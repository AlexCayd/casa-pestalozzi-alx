<?php
declare(strict_types=1);

namespace Services\Pos;

use Model\ActiveRecord;
use Model\Usuario;
use Services\Inventory\Almacenes;
use Services\Inventory\InventarioError;
use Services\Menu\Variantes;
use Services\Security\ContextoNegocio;
use Services\Security\IntentosAcceso;
use Services\Security\PermisosUsuario;

/**
 * Caja de mostrador de Panadería (arquitectura §7). Sin mesas, comanda ni
 * cocina: el cliente trae su pan, se cobra y se va.
 *
 * Una venta crea el ticket, sus líneas ya entregadas, el consumo de vitrina y
 * el cobro en UNA transacción idempotente: no existe un ticket de caja abierto
 * a medias. El precio, el nombre y la pieza que se descuenta los resuelve el
 * servidor; el navegador sólo manda ofertas, cantidades y opciones.
 *
 * Los tickets que escribe son los que ya leen Finanzas, Análisis y el
 * Asistente de Panadería: unidad 3, estado `cerrado`, líneas `entregado`,
 * cajero en `mesero_id` y fecha de cobro en `hora_cierre`.
 */
final class CajaMostrador
{
    public const CTX = 'panaderia';
    private const MAX_LINEAS = 60;
    /** Tope del efectivo recibido: evita un cero de más tecleado por error. */
    private const MAX_RECIBIDO = 99999999; // centavos: $999,999.99
    public const MOTIVOS = [
        'cobro_equivocado' => 'Cobro equivocado',
        'cliente_desistio' => 'El cliente ya no lo quiso',
        'producto_danado' => 'Producto en mal estado',
        'otro' => 'Otro motivo',
    ];

    // ── Lecturas ────────────────────────────────────────────────

    /** Todo lo que la pantalla necesita para abrir: permisos, carta y vitrina. */
    public static function estado(): array
    {
        return self::ejecutar(static function (): array {
            $perfil = ContextoNegocio::exigirUno(['pdv' => 'operar', 'vitrina' => 'operar'], self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            $db = self::db();
            $permisos = self::permisos($perfil);
            return [
                'ok' => true,
                'permisos' => $permisos,
                'catalogo' => $permisos['vender'] ? self::catalogo($db, $unidad) : ['categorias' => [], 'productos' => []],
                'vitrina' => self::vitrinaSaldos($db, $unidad),
                'motivos' => self::MOTIVOS,
                'impresora' => self::impresoraActiva($db, $unidad),
            ];
        });
    }

    /** Saldos de vitrina para refrescar las teclas sin recargar la carta. */
    public static function vitrina(): array
    {
        return self::ejecutar(static function (): array {
            ContextoNegocio::exigirUno(['pdv' => 'operar', 'vitrina' => 'operar'], self::CTX);
            return ['ok' => true, 'vitrina' => self::vitrinaSaldos(self::db(), ContextoNegocio::vendedora(self::CTX))];
        });
    }

    /** Tickets cobrados hoy, del más reciente al más antiguo, con sus líneas. */
    public static function ticketsDeHoy(int $limite = 40): array
    {
        return self::ejecutar(static function () use ($limite): array {
            ContextoNegocio::exigir('pdv', 'operar', self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            $db = self::db();
            $limite = max(1, min(80, $limite));
            $filas = self::sql($db,
                "SELECT t.id,t.estado,t.metodo_pago,t.hora_cierre,u.nombre AS cajero,
                        a.motivo,a.nota,a.created_at AS anulado_at,au.nombre AS autorizador,
                        COALESCE(a.total,(SELECT SUM(ti.precio*ti.cantidad) FROM ticket_items ti WHERE ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id AND ti.estado<>'cancelado'),0) AS total
                   FROM tickets t
                   LEFT JOIN usuarios u ON u.id=t.mesero_id
                   LEFT JOIN ticket_anulaciones a ON a.ticket_id=t.id AND a.unidad_id=t.unidad_id
                   LEFT JOIN usuarios au ON au.id=a.autorizador_id
                  WHERE t.unidad_id=? AND t.estado IN ('cerrado','cancelado')
                    AND t.hora_cierre>=CURDATE() AND t.hora_cierre<CURDATE()+INTERVAL 1 DAY
                  ORDER BY t.hora_cierre DESC,t.id DESC LIMIT {$limite}",
                [$unidad]
            )->get_result()->fetch_all(MYSQLI_ASSOC);
            $ids = array_map(static fn(array $f): int => (int)$f['id'], $filas);
            $lineas = self::lineasDe($db, $unidad, $ids);
            $tickets = [];
            foreach ($filas as $f) {
                $id = (int)$f['id'];
                $tickets[] = [
                    'id' => $id,
                    'estado' => $f['estado'] === 'cancelado' ? 'anulado' : 'cobrado',
                    'metodo' => (string)$f['metodo_pago'],
                    'hora' => substr((string)$f['hora_cierre'], 11, 5),
                    'total' => self::dinero(self::centavos((string)$f['total'])),
                    'cajero' => (string)($f['cajero'] ?? ''),
                    'lineas' => $lineas[$id] ?? [],
                    'anulacion' => $f['estado'] === 'cancelado' ? [
                        'motivo' => self::MOTIVOS[(string)$f['motivo']] ?? 'Anulado',
                        'nota' => $f['nota'],
                        'autorizador' => (string)($f['autorizador'] ?? ''),
                        'hora' => substr((string)$f['anulado_at'], 11, 5),
                    ] : null,
                ];
            }
            return ['ok' => true, 'tickets' => $tickets];
        });
    }

    /**
     * Corte de la caja: mismo criterio que el del Restaurante y que Finanzas
     * (tickets cerrados por fecha de cobro), más las anulaciones de la ventana.
     */
    public static function corte(?string $fecha, ?string $turnoPedido): array
    {
        return self::ejecutar(static function () use ($fecha, $turnoPedido): array {
            ContextoNegocio::exigir('pdv', 'operar', self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            $db = self::db();
            $fila = self::sql($db, 'SELECT turnos_caja FROM configuracion_pos WHERE unidad_id=? AND id=1', [$unidad])->get_result()->fetch_row();
            $lista = json_decode((string)($fila[0] ?? ''), true);
            $turnos = CorteCaja::normalizarTurnos(is_array($lista) ? $lista : [])['turnos'];
            $ahora = new \DateTimeImmutable();
            $vigente = CorteCaja::turnoVigente($turnos, $ahora);
            $valida = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$fecha);
            $fecha = ($valida && $valida->format('Y-m-d') === $fecha) ? $fecha : $vigente['fecha'];
            if ($turnoPedido === null) $indice = $vigente['indice'];
            else $indice = ctype_digit($turnoPedido) && isset($turnos[(int)$turnoPedido]) ? (int)$turnoPedido : null;
            $ventana = CorteCaja::ventana($fecha, $indice !== null ? $turnos[$indice] : null);
            $momento = $ahora->format('Y-m-d H:i:s');

            $piezas = self::sql($db,
                "SELECT COALESCE(SUM(ti.cantidad),0) FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id
                  WHERE t.unidad_id=? AND t.estado='cerrado' AND ti.estado<>'cancelado'
                    AND COALESCE(t.hora_cierre,t.hora_apertura)>=? AND COALESCE(t.hora_cierre,t.hora_apertura)<?",
                [$unidad, $ventana['desde'], $ventana['hasta']]
            )->get_result()->fetch_row();
            $anuladas = self::sql($db,
                "SELECT COUNT(*) n,COALESCE(SUM(a.total),0) total FROM ticket_anulaciones a JOIN tickets t ON t.id=a.ticket_id AND t.unidad_id=a.unidad_id
                  WHERE a.unidad_id=? AND COALESCE(t.hora_cierre,t.hora_apertura)>=? AND COALESCE(t.hora_cierre,t.hora_apertura)<?",
                [$unidad, $ventana['desde'], $ventana['hasta']]
            )->get_result()->fetch_assoc();

            return array_merge([
                'ok' => true,
                'fecha' => $fecha,
                'turnos' => $turnos,
                'turno' => $indice,
                'en_curso' => $momento >= $ventana['desde'] && $momento < $ventana['hasta'],
                'ventana' => $ventana,
                'piezas' => (int)($piezas[0] ?? 0),
                'anulaciones' => ['tickets' => (int)$anuladas['n'], 'total' => self::dinero(self::centavos((string)$anuladas['total']))],
            ], CorteCaja::resumen($ventana['desde'], $ventana['hasta'], $unidad));
        });
    }

    /** Ticket propio de hoy con lo necesario para imprimir su comprobante. */
    public static function comprobante(int $ticketId): array
    {
        return self::ejecutar(static function () use ($ticketId): array {
            ContextoNegocio::exigir('pdv', 'operar', self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            $db = self::db();
            if ($ticketId < 1) throw new InventarioError('TICKET_NO_VALIDO', 'El ticket no es válido.');
            $t = self::sql($db,
                "SELECT t.id,t.estado,t.metodo_pago,t.hora_cierre,u.nombre cajero FROM tickets t LEFT JOIN usuarios u ON u.id=t.mesero_id
                  WHERE t.id=? AND t.unidad_id=? AND t.estado='cerrado' AND t.hora_cierre>=CURDATE()-INTERVAL 1 DAY",
                [$ticketId, $unidad]
            )->get_result()->fetch_assoc();
            if (!$t) throw new InventarioError('TICKET_NO_VALIDO', 'Solo se imprimen tickets cobrados de hoy en esta caja.');
            $pago = self::sql($db, "SELECT resultado FROM pos_operaciones WHERE unidad_id=? AND ticket_id=? AND tipo='venta_caja' LIMIT 1", [$unidad, $ticketId])->get_result()->fetch_row();
            $venta = json_decode((string)($pago[0] ?? ''), true);
            $lineas = self::lineasDe($db, $unidad, [$ticketId])[$ticketId] ?? [];
            $total = 0;
            foreach ($lineas as $l) $total += self::centavos($l['importe']);
            return ['ok' => true, 'ticket' => [
                'id' => (int)$t['id'], 'fecha' => (string)$t['hora_cierre'], 'cajero' => (string)($t['cajero'] ?? ''),
                'metodo' => (string)$t['metodo_pago'], 'total' => self::dinero($total),
                'recibido' => $venta['ticket']['recibido'] ?? self::dinero($total),
                'cambio' => $venta['ticket']['cambio'] ?? '0.00',
                'lineas' => $lineas,
            ]];
        });
    }

    // ── Venta ───────────────────────────────────────────────────

    /**
     * @param list<array{oferta_id:int,cantidad:int,variantes?:list<int>}> $items
     * @param array{metodo:string,recibido?:string} $pago
     */
    public static function vender(array $items, array $pago, string $clave): array
    {
        return self::ejecutar(static function () use ($items, $pago, $clave): array {
            ContextoNegocio::exigir('pdv', 'operar', self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            $actor = ContextoNegocio::actorId();
            if ($actor < 1 || !self::claveValida($clave)) throw new InventarioError('DATOS_INVALIDOS', 'La venta no trae una clave válida.');
            $lineas = self::normalizarItems($items);
            $metodo = (string)($pago['metodo'] ?? '');
            if (!in_array($metodo, ['efectivo', 'tarjeta'], true)) throw new InventarioError('METODO_INVALIDO', 'Elige efectivo o tarjeta.');
            $recibido = null;
            if ($metodo === 'efectivo') {
                $recibido = self::leerDinero($pago['recibido'] ?? null);
                if ($recibido === null || $recibido > self::MAX_RECIBIDO) throw new InventarioError('RECIBIDO_INVALIDO', 'Escribe cuánto entregó el cliente.');
            }
            $hash = hash('sha256', json_encode([$actor, $unidad, 'venta_caja', $lineas, $metodo, $recibido], JSON_THROW_ON_ERROR));
            $db = self::db();

            return ContextoNegocio::transaccion(static function () use ($db, $unidad, $actor, $clave, $hash, $lineas, $metodo, $recibido): array {
                ContextoNegocio::exigir('pdv', 'operar', self::CTX, true);
                $previa = self::operacionPrevia($db, $unidad, $actor, 'venta_caja', $clave, $hash, null);
                if ($previa !== null) return array_merge($previa, ['reintento' => true]);

                // Resolver todo antes de escribir: una oferta retirada, una opción
                // obsoleta o un precio inválido rechaza la venta completa.
                $resueltas = [];
                $total = 0;
                foreach ($lineas as $l) {
                    $oferta = self::oferta($db, $l['oferta_id'], $unidad);
                    if (!$oferta) throw new InventarioError('OFERTA_NO_DISPONIBLE', 'Uno de los productos ya no está a la venta. Actualiza la caja.');
                    $variantes = Variantes::resolver((int)$oferta['oferta_id'], $l['variantes'], self::CTX);
                    if (!($variantes['ok'] ?? false)) throw new InventarioError('VARIANTE_INVALIDA', (string)($variantes['error'] ?? 'Revisa las opciones del producto.'));
                    $precio = self::centavos((string)$oferta['precio']) + (int)round(((float)$variantes['delta']) * 100);
                    if ($precio <= 0) throw new InventarioError('PRECIO_INVALIDO', 'El precio calculado de un producto no es válido.');
                    $total += $precio * $l['cantidad'];
                    $resueltas[] = $l + ['oferta' => $oferta, 'precio' => $precio, 'variante_ids' => $variantes['ids'], 'variante' => $variantes['etiqueta']];
                }
                if ($total <= 0) throw new InventarioError('TOTAL_INVALIDO', 'La venta no tiene importe.');
                if ($metodo === 'efectivo' && $recibido < $total) throw new InventarioError('RECIBIDO_INSUFICIENTE', 'El efectivo recibido no cubre el total.');
                $recibidoFinal = $metodo === 'efectivo' ? $recibido : $total;

                self::sql($db, "INSERT INTO tickets(unidad_id,comensales,nombre,hora_apertura,estado,mesero_id) VALUES (?,1,NULL,NOW(),'abierto',?)", [$unidad, $actor]);
                $ticketId = (int)$db->insert_id;
                self::registrarOperacion($db, $unidad, $actor, $ticketId, 'venta_caja', $clave, $hash);

                $almacenes = new Almacenes($db, $actor, ContextoNegocio::nivelAuth());
                $piezas = [];
                $faltantes = [];
                $recibo = [];
                foreach ($resueltas as $l) {
                    $o = $l['oferta'];
                    // De vitrina no se snapshotea receta: la venta descuenta la pieza.
                    $receta = $o['vitrina'] ? null : ($o['receta_id'] !== null ? (int)$o['receta_id'] : null);
                    self::sql($db,
                        "INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,receta_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,comensal,cantidad,nota,variante_ids,variante,estado)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL,?,NULL,?,?,'enviado')",
                        [$unidad, $ticketId, (int)$o['oferta_id'], (int)$o['producto_id'], $receta, (string)$o['nombre'], self::dinero($l['precio']),
                         (string)$o['categoria'], (int)$o['categoria_id'], (int)$o['area_id'], (string)$o['area_nombre'], $l['cantidad'],
                         $l['variante_ids'] ? implode(',', $l['variante_ids']) : null, $l['variante']]
                    );
                    $itemId = (int)$db->insert_id;
                    $consumo = $almacenes->consumirVenta(self::CTX, $itemId);
                    if (($consumo['ok'] ?? true) === false) {
                        throw new InventarioError((string)($consumo['codigo'] ?? 'CONSUMO_FALLIDO'), (string)($consumo['mensaje'] ?? 'No se pudo descontar la vitrina.'));
                    }
                    if (($consumo['costo_unitario'] ?? null) !== null) {
                        self::sql($db, 'UPDATE ticket_items SET costo_unitario=? WHERE id=? AND unidad_id=?', [(string)$consumo['costo_unitario'], $itemId, $unidad]);
                    }
                    foreach ($consumo['movimientos'] ?? [] as $m) $piezas[(int)$m['almacen_id'] . ':' . (int)$m['articulo_id']] = true;
                    // §16: sin piezas suficientes la venta sigue; el motor deja la
                    // pieza en cero y registra lo que faltó como faltante por conciliar.
                    foreach ($consumo['faltantes'] ?? [] as $f) {
                        $k = (int)$f['almacen_id'] . ':' . (int)$f['articulo_id'];
                        $piezas[$k] = true;
                        $faltantes[$k] = bcadd($faltantes[$k] ?? '0', (string)$f['cantidad'], 3);
                    }
                    $recibo[] = ['nombre' => (string)$o['nombre'], 'variante' => $l['variante'], 'cantidad' => $l['cantidad'],
                        'precio' => self::dinero($l['precio']), 'importe' => self::dinero($l['precio'] * $l['cantidad'])];
                }
                // Mostrador: lo vendido ya está en manos del cliente.
                self::sql($db, "UPDATE ticket_items SET estado='entregado' WHERE ticket_id=? AND unidad_id=? AND estado='enviado'", [$ticketId, $unidad]);
                self::sql($db, "UPDATE tickets SET estado='cerrado',closed_at=NOW(),hora_cierre=NOW(),metodo_pago=?,propina=0 WHERE id=? AND unidad_id=? AND estado='abierto'", [$metodo, $ticketId, $unidad]);
                $hora = (string)self::sql($db, 'SELECT hora_cierre FROM tickets WHERE id=? AND unidad_id=?', [$ticketId, $unidad])->get_result()->fetch_row()[0];

                $vitrina = self::vitrinaSaldos($db, $unidad, array_keys($piezas));
                $avisos = [];
                foreach ($vitrina as $p) {
                    if (isset($faltantes[$p['clave']])) $avisos[] = ['codigo' => 'VITRINA_FALTANTE', 'pieza' => $p['nombre'], 'faltante' => $faltantes[$p['clave']]];
                }
                $resultado = [
                    'ok' => true,
                    'ticket' => [
                        'id' => $ticketId, 'fecha' => $hora, 'hora' => substr($hora, 11, 5), 'metodo' => $metodo,
                        'total' => self::dinero($total), 'recibido' => self::dinero($recibidoFinal), 'cambio' => self::dinero($recibidoFinal - $total),
                        'lineas' => $recibo,
                    ],
                    'vitrina' => $vitrina,
                    'avisos' => $avisos,
                    'reintento' => false,
                ];
                self::guardarResultado($db, $unidad, $actor, 'venta_caja', $clave, $resultado);
                return $resultado;
            });
        });
    }

    // ── Horneada ────────────────────────────────────────────────

    /** @param list<array{almacen_id:int,articulo_id:int,cantidad:string|int}> $lineas */
    public static function hornear(array $lineas, string $clave): array
    {
        return self::ejecutar(static function () use ($lineas, $clave): array {
            ContextoNegocio::exigir('vitrina', 'operar', self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            if (!self::claveValida($clave)) throw new InventarioError('DATOS_INVALIDOS', 'La horneada no trae una clave válida.');
            $db = self::db();
            $almacenes = new Almacenes($db, ContextoNegocio::actorId(), ContextoNegocio::nivelAuth());
            $r = $almacenes->registrarHorneada(self::CTX, $lineas, $clave);
            if (($r['ok'] ?? false) !== true) return $r;
            $piezas = [];
            foreach ($r['movimientos'] ?? [] as $m) $piezas[] = (int)$m['almacen_id'] . ':' . (int)$m['articulo_id'];
            return ['ok' => true, 'confirmado' => true, 'reintento' => (bool)($r['reintento'] ?? false), 'vitrina' => self::vitrinaSaldos($db, $unidad, $piezas)];
        });
    }

    // ── Anulación ───────────────────────────────────────────────

    /**
     * Anula una venta cobrada HOY en esta caja. Sin permiso `anular`, la
     * autoriza en el momento el NIP de alguien que lo tenga. El ticket pasa a
     * `cancelado` (deja de contar en ventas y corte), sus líneas también y sus
     * piezas vuelven a la vitrina compensando los movimientos originales.
     */
    public static function anular(int $ticketId, string $motivo, ?string $nota, string $clave, ?string $nipAutoriza): array
    {
        return self::ejecutar(static function () use ($ticketId, $motivo, $nota, $clave, $nipAutoriza): array {
            $perfil = ContextoNegocio::exigir('pdv', 'operar', self::CTX);
            $unidad = ContextoNegocio::vendedora(self::CTX);
            $actor = ContextoNegocio::actorId();
            if ($ticketId < 1 || !self::claveValida($clave)) throw new InventarioError('DATOS_INVALIDOS', 'La anulación no es válida.');
            if (!isset(self::MOTIVOS[$motivo])) throw new InventarioError('MOTIVO_INVALIDO', 'Elige el motivo de la anulación.');
            $nota = $nota !== null ? trim($nota) : null;
            if ($nota === '') $nota = null;
            if ($nota !== null && (mb_strlen($nota) > 160 || str_contains($nota, "\0"))) throw new InventarioError('NOTA_INVALIDA', 'La nota admite hasta 160 caracteres.');
            if ($motivo === 'otro' && $nota === null) throw new InventarioError('NOTA_REQUERIDA', 'Cuenta en una línea por qué se anula.');
            $autorizador = PermisosUsuario::permitido($perfil, self::CTX, 'anular', 'operar') ? $actor : self::autorizadorPorNip($nipAutoriza);
            $hash = hash('sha256', json_encode([$actor, $unidad, 'anulacion', $ticketId, $motivo, $nota, $autorizador], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $db = self::db();

            return ContextoNegocio::transaccion(static function () use ($db, $unidad, $actor, $autorizador, $ticketId, $motivo, $nota, $clave, $hash): array {
                ContextoNegocio::exigir('pdv', 'operar', self::CTX, true);
                $ticket = self::sql($db, 'SELECT id,estado,metodo_pago,hora_cierre FROM tickets WHERE id=? AND unidad_id=? FOR UPDATE', [$ticketId, $unidad])->get_result()->fetch_assoc();
                if (!$ticket) throw new InventarioError('TICKET_NO_VALIDO', 'El ticket no pertenece a esta caja.');
                $previa = self::operacionPrevia($db, $unidad, $actor, 'anulacion', $clave, $hash, $ticketId);
                if ($previa !== null) return array_merge($previa, ['reintento' => true]);
                $hoy = (new \DateTimeImmutable('today'))->format('Y-m-d');
                if ($ticket['estado'] !== 'cerrado' || !in_array($ticket['metodo_pago'], ['efectivo', 'tarjeta'], true)
                    || substr((string)$ticket['hora_cierre'], 0, 10) !== $hoy) {
                    throw new InventarioError('TICKET_NO_ANULABLE', 'Solo se anulan cobros de hoy que sigan vigentes.');
                }
                self::registrarOperacion($db, $unidad, $actor, $ticketId, 'anulacion', $clave, $hash);
                $items = self::sql($db, "SELECT id,precio,cantidad FROM ticket_items WHERE ticket_id=? AND unidad_id=? AND estado<>'cancelado' ORDER BY id FOR UPDATE", [$ticketId, $unidad])->get_result()->fetch_all(MYSQLI_ASSOC);
                $total = 0;
                foreach ($items as $i) $total += self::centavos((string)$i['precio']) * (int)$i['cantidad'];
                self::sql($db, "UPDATE tickets SET estado='cancelado' WHERE id=? AND unidad_id=? AND estado='cerrado'", [$ticketId, $unidad]);
                self::sql($db, 'INSERT INTO ticket_anulaciones(ticket_id,unidad_id,solicitante_id,autorizador_id,motivo,nota,total,metodo_pago) VALUES (?,?,?,?,?,?,?,?)',
                    [$ticketId, $unidad, $actor, $autorizador, $motivo, $nota, self::dinero($total), (string)$ticket['metodo_pago']]);
                $almacenes = new Almacenes($db, $actor, ContextoNegocio::nivelAuth());
                $piezas = [];
                foreach ($items as $i) {
                    self::sql($db, "UPDATE ticket_items SET estado='cancelado' WHERE id=? AND unidad_id=?", [(int)$i['id'], $unidad]);
                    $r = $almacenes->revertirVenta(self::CTX, (int)$i['id']);
                    if (($r['ok'] ?? true) === false) throw new InventarioError((string)($r['codigo'] ?? 'REVERSA_FALLIDA'), (string)($r['mensaje'] ?? 'No se pudieron devolver las piezas a la vitrina.'));
                    foreach ($r['movimientos'] ?? [] as $m) $piezas[(int)$m['almacen_id'] . ':' . (int)$m['articulo_id']] = true;
                }
                $resultado = ['ok' => true, 'ticket_id' => $ticketId, 'total' => self::dinero($total), 'metodo' => (string)$ticket['metodo_pago'],
                    'vitrina' => self::vitrinaSaldos($db, $unidad, array_keys($piezas)), 'reintento' => false];
                self::guardarResultado($db, $unidad, $actor, 'anulacion', $clave, $resultado);
                return $resultado;
            });
        });
    }

    /** NIP de quien sí puede anular; limitado igual que el acceso por NIP. */
    private static function autorizadorPorNip(?string $nip): int
    {
        if ($nip === null || $nip === '') throw new InventarioError('AUTORIZACION_REQUERIDA', 'Pide a quien pueda anular que escriba su NIP.');
        $_SESSION['_caja_nip_anulacion'] ??= ['inicio' => time(), 'fallos' => 0];
        $estado = &$_SESSION['_caja_nip_anulacion'];
        if (time() - (int)$estado['inicio'] >= 60) $estado = ['inicio' => time(), 'fallos' => 0];
        if ((int)$estado['fallos'] >= 5 || !IntentosAcceso::admitir('nip', (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'))) {
            throw new InventarioError('DEMASIADOS_INTENTOS', 'Demasiados intentos. Espera un minuto.');
        }
        $usuario = preg_match('/^\d{4}$/D', $nip) ? Usuario::porNip($nip) : null;
        $ok = false;
        if ($usuario) {
            $perfil = PermisosUsuario::cargar((int)$usuario->id, self::db());
            $ok = PermisosUsuario::decidir($perfil, self::CTX, 'anular', 'operar', 'nip')['ok'];
        }
        if (!$ok) {
            $estado['fallos'] = (int)$estado['fallos'] + 1;
            throw new InventarioError('AUTORIZACION_INVALIDA', 'Ese NIP no puede autorizar anulaciones.');
        }
        $estado = ['inicio' => time(), 'fallos' => 0];
        return (int)$usuario->id;
    }

    // ── Apoyos ──────────────────────────────────────────────────

    private static function permisos(array $perfil): array
    {
        return [
            'vender' => PermisosUsuario::permitido($perfil, self::CTX, 'pdv', 'operar'),
            'hornear' => PermisosUsuario::permitido($perfil, self::CTX, 'vitrina', 'operar'),
            'anular' => PermisosUsuario::permitido($perfil, self::CTX, 'anular', 'operar'),
        ];
    }

    /** Carta de la caja: ofertas activas, opciones y la pieza que descuenta cada una. */
    private static function catalogo(\mysqli $db, int $unidad): array
    {
        $filas = self::sql($db,
            "SELECT o.id oferta_id,o.precio,c.id categoria_id,c.nombre categoria,p.nombre,p.descripcion,p.img,
                    vp.almacen_id,vp.articulo_id,vp.cantidad
               FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id
               JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
               LEFT JOIN vitrina_piezas vp ON vp.oferta_id=o.id AND vp.unidad_id=o.unidad_id
              WHERE o.unidad_id=? AND o.activo=1 AND c.activo=1
              ORDER BY c.orden,c.nombre,o.orden,p.nombre",
            [$unidad]
        )->get_result()->fetch_all(MYSQLI_ASSOC);
        $piezasVariante = [];
        foreach (self::sql($db, 'SELECT variante_id,almacen_id,articulo_id,cantidad FROM vitrina_piezas_variante WHERE unidad_id=?', [$unidad])->get_result()->fetch_all(MYSQLI_ASSOC) as $v) {
            $piezasVariante[(int)$v['variante_id']] = ['pz' => (int)$v['almacen_id'] . ':' . (int)$v['articulo_id'], 'q' => (string)$v['cantidad']];
        }
        $variantes = Variantes::paraPos(self::CTX);
        $categorias = [];
        $productos = [];
        foreach ($filas as $f) {
            $cat = (int)$f['categoria_id'];
            $categorias[$cat] ??= ['id' => $cat, 'nombre' => (string)$f['categoria']];
            $grupos = [];
            foreach ($variantes[(int)$f['oferta_id']] ?? [] as $g) {
                $grupos[] = ['n' => (string)$g['n'], 'req' => (bool)$g['req'], 'o' => array_map(static fn(array $o): array => [
                    'id' => (int)$o['id'], 'n' => (string)$o['n'], 'd' => number_format((float)$o['d'], 2, '.', ''), 'def' => (bool)$o['def'],
                    'pz' => $piezasVariante[(int)$o['id']]['pz'] ?? null, 'q' => $piezasVariante[(int)$o['id']]['q'] ?? null,
                ], $g['o'])];
            }
            $productos[] = [
                'id' => (int)$f['oferta_id'],
                'n' => (string)$f['nombre'],
                'desc' => (string)($f['descripcion'] ?? ''),
                'img' => $f['img'] !== null && $f['img'] !== '' ? '/' . ltrim((string)$f['img'], '/') : null,
                'p' => (string)$f['precio'],
                'c' => $cat,
                'pz' => $f['articulo_id'] !== null ? (int)$f['almacen_id'] . ':' . (int)$f['articulo_id'] : null,
                'q' => $f['cantidad'] !== null ? (string)$f['cantidad'] : null,
                'g' => $grupos,
            ];
        }
        return ['categorias' => array_values($categorias), 'productos' => $productos];
    }

    /** @param list<string>|null $claves «almacen:articulo»; null = toda la vitrina */
    private static function vitrinaSaldos(\mysqli $db, int $unidad, ?array $claves = null): array
    {
        if ($claves === []) return [];
        $filas = self::sql($db,
            "SELECT aa.almacen_id,aa.articulo_id,aa.saldo,aa.minimo,i.nombre
               FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id
              WHERE aa.unidad_id=? AND EXISTS (
                    SELECT 1 FROM vitrina_piezas vp WHERE vp.unidad_id=aa.unidad_id AND vp.almacen_id=aa.almacen_id AND vp.articulo_id=aa.articulo_id
                    UNION ALL SELECT 1 FROM vitrina_piezas_variante vv WHERE vv.unidad_id=aa.unidad_id AND vv.almacen_id=aa.almacen_id AND vv.articulo_id=aa.articulo_id)
              ORDER BY i.nombre,aa.almacen_id",
            [$unidad]
        )->get_result()->fetch_all(MYSQLI_ASSOC);
        $out = [];
        foreach ($filas as $f) {
            $clave = (int)$f['almacen_id'] . ':' . (int)$f['articulo_id'];
            if ($claves !== null && !in_array($clave, $claves, true)) continue;
            $out[] = ['clave' => $clave, 'almacen_id' => (int)$f['almacen_id'], 'articulo_id' => (int)$f['articulo_id'],
                'nombre' => (string)$f['nombre'], 'saldo' => (string)$f['saldo'], 'minimo' => (string)$f['minimo']];
        }
        return $out;
    }

    /** @param list<int> $ids */
    private static function lineasDe(\mysqli $db, int $unidad, array $ids): array
    {
        if ($ids === []) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        $filas = self::sql($db, "SELECT ticket_id,nombre,variante,cantidad,precio,estado FROM ticket_items WHERE unidad_id=? AND ticket_id IN ({$in}) ORDER BY ticket_id,id", array_merge([$unidad], $ids))->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($filas as $f) {
            $precio = self::centavos((string)$f['precio']);
            $out[(int)$f['ticket_id']][] = ['nombre' => (string)$f['nombre'], 'variante' => $f['variante'], 'cantidad' => (int)$f['cantidad'],
                'precio' => self::dinero($precio), 'importe' => self::dinero($precio * (int)$f['cantidad'])];
        }
        return $out;
    }

    private static function oferta(\mysqli $db, int $id, int $unidad): ?array
    {
        $fila = self::sql($db,
            'SELECT o.id oferta_id,o.producto_id,o.precio,c.id categoria_id,c.nombre categoria,ap.id area_id,ap.nombre area_nombre,p.nombre,
                    (SELECT r.id FROM recetas r WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1 ORDER BY r.version DESC LIMIT 1) receta_id,
                    EXISTS(SELECT 1 FROM vitrina_piezas vp WHERE vp.oferta_id=o.id AND vp.unidad_id=o.unidad_id) vitrina
               FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id
               JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
               JOIN areas_produccion ap ON ap.id=o.area_id AND ap.unidad_id=o.unidad_id
              WHERE o.id=? AND o.unidad_id=? AND o.activo=1 AND c.activo=1 LIMIT 1 FOR SHARE',
            [$id, $unidad]
        )->get_result()->fetch_assoc();
        if (!$fila) return null;
        $fila['vitrina'] = (int)$fila['vitrina'] === 1;
        return $fila;
    }

    private static function impresoraActiva(\mysqli $db, int $unidad): bool
    {
        $fila = self::sql($db,
            "SELECT (SELECT impresion_activa FROM configuracion_pos WHERE unidad_id=? AND id=1) activa,
                    EXISTS(SELECT 1 FROM impresoras WHERE unidad_id=? AND rol='cuenta' AND activo=1) existe",
            [$unidad, $unidad]
        )->get_result()->fetch_assoc();
        return (int)($fila['activa'] ?? 0) === 1 && (int)($fila['existe'] ?? 0) === 1;
    }

    private static function normalizarItems(array $items): array
    {
        if (!array_is_list($items) || $items === [] || count($items) > self::MAX_LINEAS) {
            throw new InventarioError('DATOS_INVALIDOS', 'La venta debe tener de 1 a ' . self::MAX_LINEAS . ' líneas.');
        }
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new InventarioError('ITEM_INVALIDO', 'Una línea de la venta no es válida.');
            $oferta = filter_var($item['oferta_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 255]]);
            if ($oferta === false || $cantidad === false) throw new InventarioError('ITEM_INVALIDO', 'El producto o la cantidad de una línea no es válida.');
            $variantes = $item['variantes'] ?? [];
            if (!is_array($variantes) || !array_is_list($variantes) || count($variantes) > 6) throw new InventarioError('VARIANTES_INVALIDAS', 'Las opciones del producto no son válidas.');
            foreach ($variantes as $v) if (filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new InventarioError('VARIANTES_INVALIDAS', 'Las opciones del producto no son válidas.');
            $ids = array_values(array_unique(array_map('intval', $variantes)));
            sort($ids, SORT_NUMERIC);
            $out[] = ['oferta_id' => (int)$oferta, 'cantidad' => (int)$cantidad, 'variantes' => $ids];
        }
        return $out;
    }

    /** Respuesta guardada de un reintento; conflicto si la clave trae otra cosa. */
    private static function operacionPrevia(\mysqli $db, int $unidad, int $actor, string $tipo, string $clave, string $hash, ?int $ticketId): ?array
    {
        $fila = self::sql($db, 'SELECT ticket_id,hash_solicitud,resultado FROM pos_operaciones WHERE unidad_id=? AND actor_id=? AND tipo=? AND clave=? FOR UPDATE', [$unidad, $actor, $tipo, $clave])->get_result()->fetch_assoc();
        if (!$fila) return null;
        if (!hash_equals((string)$fila['hash_solicitud'], $hash) || $fila['resultado'] === null || ($ticketId !== null && (int)$fila['ticket_id'] !== $ticketId)) {
            throw new InventarioError('CONFLICTO_IDEMPOTENCIA', 'Esa operación ya se registró con otro contenido.');
        }
        $r = json_decode((string)$fila['resultado'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($r)) throw new InventarioError('CONFLICTO_IDEMPOTENCIA', 'No se pudo recuperar la operación original.');
        return $r;
    }

    private static function registrarOperacion(\mysqli $db, int $unidad, int $actor, int $ticketId, string $tipo, string $clave, string $hash): void
    {
        try {
            self::sql($db, 'INSERT INTO pos_operaciones(unidad_id,actor_id,ticket_id,tipo,clave,hash_solicitud) VALUES (?,?,?,?,?,?)', [$unidad, $actor, $ticketId, $tipo, $clave, $hash]);
        } catch (\mysqli_sql_exception $e) {
            // Otra petición con la misma clave ganó la carrera: el cliente reintenta y lee su resultado.
            if ($e->getCode() === 1062) throw new InventarioError('OPERACION_EN_CURSO', 'Esta operación ya se está registrando. Intenta de nuevo.');
            throw $e;
        }
    }

    private static function guardarResultado(\mysqli $db, int $unidad, int $actor, string $tipo, string $clave, array $resultado): void
    {
        self::sql($db, 'UPDATE pos_operaciones SET resultado=? WHERE unidad_id=? AND actor_id=? AND tipo=? AND clave=?',
            [json_encode($resultado, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $unidad, $actor, $tipo, $clave]);
    }

    /** Ejecuta en el ámbito de la caja y traduce errores a la respuesta pública. */
    private static function ejecutar(callable $accion): array
    {
        if (!ContextoNegocio::nuevo()) return ['ok' => false, 'confirmado' => false, 'codigo' => 'ESQUEMA_NO_DISPONIBLE', 'mensaje' => 'La caja requiere el esquema multinegocio.'];
        try {
            $r = ContextoNegocio::en(self::CTX, $accion);
            if (($r['ok'] ?? false) === true && !array_key_exists('confirmado', $r)) $r['confirmado'] = true;
            return $r;
        } catch (\Throwable $e) {
            if (!$e instanceof InventarioError) error_log('CajaMostrador - ' . $e->getMessage());
            return [
                'ok' => false, 'confirmado' => false,
                'codigo' => $e instanceof InventarioError ? $e->codigo : 'OPERACION_CAJA_FALLIDA',
                'mensaje' => $e instanceof InventarioError ? $e->getMessage() : 'No se pudo completar la operación de caja.',
            ];
        }
    }

    private static function db(): \mysqli
    {
        return ContextoNegocio::db();
    }

    private static function sql(\mysqli $db, string $sql, array $args = []): \mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new \RuntimeException($db->error);
        if ($args !== []) {
            $tipos = '';
            foreach ($args as $a) $tipos .= is_int($a) ? 'i' : 's';
            $stmt->bind_param($tipos, ...$args);
        }
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new \RuntimeException($error); }
        return $stmt;
    }

    private static function claveValida(string $clave): bool
    {
        return strlen($clave) >= 16 && strlen($clave) <= 100 && preg_match('/^[A-Za-z0-9._:-]+$/D', $clave) === 1;
    }

    /** Importe exacto en centavos desde DECIMAL o texto «123.45». */
    private static function centavos(string $monto): int
    {
        if (!preg_match('/^(-?)(\d{1,9})(?:\.(\d{1,4}))?$/D', trim($monto), $m)) throw new InventarioError('IMPORTE_INVALIDO', 'Un importe no es válido.');
        $dec = str_pad(substr(($m[3] ?? '') . '00', 0, 2), 2, '0');
        $v = (int)$m[2] * 100 + (int)$dec;
        return $m[1] === '-' ? -$v : $v;
    }

    private static function leerDinero(mixed $monto): ?int
    {
        if (is_int($monto)) $monto = (string)$monto;
        if (!is_string($monto) || !preg_match('/^\d{1,7}(?:\.\d{1,2})?$/D', trim($monto))) return null;
        return self::centavos(trim($monto));
    }

    private static function dinero(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);
        return $signo . intdiv($centavos, 100) . '.' . str_pad((string)($centavos % 100), 2, '0', STR_PAD_LEFT);
    }
}
