<?php
declare(strict_types=1);
namespace Services\Inventory;

/**
 * Faltantes de venta y su conciliación de origen (docs/arquitectura.md §16).
 *
 * Una venta nunca se bloquea por saldo: el almacén de consumo descuenta lo que
 * tiene y el resto nace aquí como faltante pendiente. Después, quien administra
 * el negocio dueño del almacén declara de qué almacén(es) salió. No hay saldo
 * negativo, ni compra directa, ni diferencia de inventario: sólo traslados
 * trazables (transferencias de regularización) y el consumo ligado al ticket.
 */
final class Faltantes
{
    public const AVISO = 'inventario.faltante';
    private const MAX_ASIGNACIONES = 20;

    private AlcanceInventario $alcance;

    public function __construct(private \mysqli $db, int $actorId, string $nivelAuth)
    {
        $this->alcance = new AlcanceInventario($db, $actorId, $nivelAuth);
    }

    // ── Venta (dentro de la transacción del motor) ──────────────────────

    /** Registra la parte no descontada de una línea de venta. Idempotente por item+almacén+artículo. */
    public static function registrarVenta(\mysqli $db, int $unidad, int $almacen, int $articulo, int $ticketItem, int $operacion, string $cantidad): array
    {
        $db->execute_query('INSERT INTO faltantes_inventario(unidad_id,almacen_id,articulo_id,ticket_item_id,operacion_id,origen,cantidad) VALUES (?,?,?,?,?,\'venta\',?)',
            [$unidad, $almacen, $articulo, $ticketItem, $operacion, $cantidad]);
        $id = (int)$db->insert_id;
        self::avisar($db, $unidad, $almacen, $articulo, $id);
        return ['faltante_id'=>$id, 'almacen_id'=>$almacen, 'articulo_id'=>$articulo, 'cantidad'=>$cantidad];
    }

    /** Aviso de prioridad alta por almacén + artículo + día, en la unidad dueña del almacén. */
    public static function avisar(\mysqli $db, int $unidad, int $almacen, int $articulo, int $faltanteId): void
    {
        $dedup = self::AVISO.':'.$almacen.':'.$articulo.':'.date('Y-m-d');
        $db->execute_query("INSERT INTO buzon_notificaciones(unidad_id,tipo,modulo,entidad_tipo,entidad_id,prioridad,requiere_accion,dedup_key) VALUES (?,?,'inventario','faltante_inventario',?,'alta',1,?)
            ON DUPLICATE KEY UPDATE cerrada_at=NULL,cerrada_por=NULL,cierre_motivo=NULL,leida_at=NULL,prioridad='alta',updated_at=NOW()",
            [$unidad, self::AVISO, $faltanteId, $dedup]);
    }

    /**
     * Una entrada de un artículo puede cubrir faltantes que nadie pudo conciliar:
     * se reabre el aviso de cada almacén con faltantes pendientes de ese artículo.
     *
     * @param list<int> $articulos
     */
    public static function reactivarAvisos(\mysqli $db, array $articulos): void
    {
        foreach (array_unique($articulos) as $articulo) {
            $rows = $db->execute_query("SELECT unidad_id,almacen_id,MIN(id) id FROM faltantes_inventario WHERE articulo_id=? AND estado='pendiente' GROUP BY unidad_id,almacen_id", [(int)$articulo])->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $r) self::avisar($db, (int)$r['unidad_id'], (int)$r['almacen_id'], (int)$articulo, (int)$r['id']);
        }
    }

    /**
     * Cancelación de un item (transacción del POS): lo pendiente se anula, las
     * partes en confirmación cancelan su transferencia y se anulan. Lo ya
     * descontado o conciliado lo compensa revertirVenta() como cualquier venta.
     */
    public static function anularPorCancelacion(\mysqli $db, int $unidad, int $ticketItem, int $actor): void
    {
        $faltantes = $db->execute_query("SELECT * FROM faltantes_inventario WHERE ticket_item_id=? AND unidad_id=? AND estado IN('pendiente','en_confirmacion') ORDER BY id FOR UPDATE", [$ticketItem, $unidad])->fetch_all(MYSQLI_ASSOC);
        foreach ($faltantes as $f) {
            $asignaciones = $db->execute_query("SELECT * FROM faltante_asignaciones WHERE faltante_id=? AND estado='en_confirmacion' ORDER BY id FOR UPDATE", [$f['id']])->fetch_all(MYSQLI_ASSOC);
            foreach ($asignaciones as $as) {
                $t = $db->execute_query('SELECT * FROM transferencias WHERE id=? FOR UPDATE', [$as['transferencia_id']])->fetch_assoc();
                if ($t && $t['estado'] === 'solicitada') {
                    $db->execute_query("UPDATE transferencias SET estado='cancelada',version=version+1 WHERE id=?", [$t['id']]);
                    $db->execute_query("INSERT INTO transferencia_historial(transferencia_id,estado_anterior,estado_nuevo,actor_id,motivo) VALUES (?,?,'cancelada',?,?)",
                        [$t['id'], $t['estado'], $actor, 'cancelar: el item vendido se canceló']);
                }
                $db->execute_query("UPDATE faltante_asignaciones SET estado='anulada',motivo='El item vendido se canceló',resuelto_at=NOW() WHERE id=?", [$as['id']]);
            }
            $db->execute_query("UPDATE faltantes_inventario SET estado='anulado',cantidad_en_confirmacion=0,version=version+1 WHERE id=?", [$f['id']]);
            self::cerrarAvisoSiResuelto($db, (int)$f['unidad_id'], (int)$f['almacen_id'], (int)$f['articulo_id'], 'faltante_anulado');
        }
    }

    // ── Consulta ─────────────────────────────────────────────────────────

    /** Faltantes por conciliar del negocio (para el aviso «N faltantes por conciliar»). */
    public function contar(string $ctx): int
    {
        $r = TransaccionInventario::ejecutar($this->db, function () use ($ctx): array {
            $u = $this->alcance->autorizar($ctx, 'ver')['unidad_id'];
            return ['n'=>(int)$this->uno("SELECT COUNT(*) n FROM faltantes_inventario WHERE unidad_id=? AND estado='pendiente'", [$u])['n']];
        }, 'REPEATABLE READ');
        return (int)($r['n'] ?? 0);
    }

    /**
     * Bandeja de conciliación. Para cada faltante abierto del negocio: artículo,
     * almacén, cantidades, ticket de origen y orígenes posibles. El saldo sólo se
     * muestra en almacenes propios; un almacén de otro propietario aparece por
     * identidad y su dueño confirma (nunca se expone su saldo).
     */
    public function bandeja(string $ctx): array
    {
        return TransaccionInventario::ejecutar($this->db, function () use ($ctx): array {
            $u = $this->alcance->autorizar($ctx, 'ver', null, true)['unidad_id'];
            $filas = $this->sql("SELECT f.*,i.nombre articulo_nombre,i.unidad,i.contenido_pieza,a.nombre almacen_nombre,ti.ticket_id,ti.nombre item_nombre,ti.created_at item_fecha
                FROM faltantes_inventario f JOIN ingredientes i ON i.id=f.articulo_id JOIN almacenes a ON a.id=f.almacen_id AND a.unidad_id=f.unidad_id
                LEFT JOIN ticket_items ti ON ti.id=f.ticket_item_id AND ti.unidad_id=f.unidad_id
                WHERE f.unidad_id=? AND f.estado IN('pendiente','en_confirmacion') ORDER BY f.estado='pendiente' DESC,f.creado_at,f.id LIMIT 200", [$u])->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($filas as &$f) {
                $restante = self::restante($f);
                $f['restante'] = DecimalInventario::cantidad($restante);
                $f['asignaciones'] = $this->sql('SELECT s.id,s.almacen_origen_id,a.nombre almacen_nombre,us.slug contexto,s.cantidad,s.estado,s.motivo,s.transferencia_id,s.creado_at,s.resuelto_at FROM faltante_asignaciones s JOIN almacenes a ON a.id=s.almacen_origen_id JOIN unidades us ON us.id=a.unidad_id WHERE s.faltante_id=? ORDER BY s.id', [$f['id']])->get_result()->fetch_all(MYSQLI_ASSOC);
                $origenes = $this->sql("SELECT a.id almacen_id,a.nombre,a.unidad_id,us.slug contexto,aa.saldo FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id JOIN unidades us ON us.id=a.unidad_id
                    WHERE aa.articulo_id=? AND aa.activo=1 AND a.activo=1 AND us.slug IN('global','restaurante','panaderia') ORDER BY a.unidad_id<>?,a.unidad_id,a.nombre,a.id", [$f['articulo_id'], $u])->get_result()->fetch_all(MYSQLI_ASSOC);
                $cubrePropio = 0;
                foreach ($origenes as &$o) {
                    $propio = (int)$o['unidad_id'] === $u;
                    $o['propio'] = $propio;
                    $o['requiere_confirmacion'] = !$propio;
                    if ($propio) { $cubrePropio = DecimalInventario::sumar($cubrePropio, max(0, DecimalInventario::leer($o['saldo'], 3, true))); }
                    else unset($o['saldo']);
                    unset($o['unidad_id']);
                }
                unset($o);
                $f['origenes'] = $origenes;
                $f['disponible_propio'] = DecimalInventario::cantidad($cubrePropio);
                // «Ningún almacén cubre este faltante» con lo que el negocio puede ver.
                $f['cubierto_por_propios'] = $restante > 0 && $cubrePropio >= $restante;
            }
            unset($f);
            return ['codigo'=>'FALTANTES_CONSULTADOS', 'faltantes'=>$filas];
        }, 'REPEATABLE READ');
    }

    // ── Conciliación ─────────────────────────────────────────────────────

    /**
     * Reparte la cantidad pendiente entre uno o varios almacenes de origen.
     * datos: faltante_id, version, clave, asignaciones[{almacen_id,cantidad}].
     */
    public function conciliar(string $ctx, array $datos): array
    {
        return TransaccionInventario::ejecutar($this->db, function () use ($ctx, $datos): array {
            if (array_diff(array_keys($datos), ['faltante_id','version','clave','asignaciones'])) throw new InventarioError('DATOS_INVALIDOS', 'Campos de conciliación no permitidos.');
            $u = $this->alcance->autorizar($ctx, 'editar', null, true)['unidad_id'];
            $clave = self::clave($datos['clave'] ?? null);
            $id = self::id($datos['faltante_id'] ?? null);
            $version = self::version($datos['version'] ?? null);
            $asignaciones = self::asignaciones($datos['asignaciones'] ?? null);
            $hash = hash('sha256', json_encode([$this->alcance->actorId(), $ctx, 'conciliar', $id, $version, $asignaciones], JSON_THROW_ON_ERROR));
            $previo = $this->comando($u, $clave, $hash);
            if ($previo !== null) return array_merge($previo, ['reintento'=>true]);

            // Un ID ajeno se rechaza igual que uno inexistente.
            $f = $this->uno('SELECT * FROM faltantes_inventario WHERE id=? AND unidad_id=? FOR UPDATE', [$id, $u]);
            if (!$f) throw new InventarioError('RECURSO_NO_ENCONTRADO', 'No se encontró el faltante autorizado.');
            $this->alcance->almacen($ctx, (int)$f['almacen_id'], 'editar', true, false);
            if ((string)$f['version'] !== (string)$version) throw new InventarioError('VERSION_CONFLICTO', 'El faltante cambió mientras lo conciliabas; recarga la bandeja.');
            if ($f['estado'] !== 'pendiente') throw new InventarioError('ESTADO_INVALIDO', 'El faltante ya no está pendiente de origen.');
            $restante = self::restante($f);
            $suma = 0;
            foreach ($asignaciones as $q) $suma = DecimalInventario::sumar($suma, $q);
            if ($suma !== $restante) throw new InventarioError('SUMA_INVALIDA', 'El reparto debe sumar exactamente '.DecimalInventario::cantidad($restante).' '.$this->unidadArticulo((int)$f['articulo_id']).'.');

            // Bloqueos ordenados: almacenes y asociaciones por id.
            $ids = array_keys($asignaciones);
            sort($ids, SORT_NUMERIC);
            $almacenes = [];
            foreach ($ids as $w) {
                $a = $this->uno("SELECT a.id,a.unidad_id,a.nombre,a.activo FROM almacenes a JOIN unidades us ON us.id=a.unidad_id WHERE a.id=? AND us.slug IN('global','restaurante','panaderia') FOR UPDATE", [$w]);
                if (!$a) throw new InventarioError('RECURSO_NO_ENCONTRADO', 'No se encontró un almacén de origen.');
                if (!(int)$a['activo']) throw new InventarioError('ALMACEN_INACTIVO', 'El almacén «'.$a['nombre'].'» está inactivo.');
                $this->asociacionActiva($w, (int)$f['articulo_id'], $a['nombre']);
                $almacenes[$w] = $a;
            }
            $aplicadas = [];
            $enConfirmacion = [];
            foreach ($ids as $w) {
                $q = $asignaciones[$w];
                if ((int)$almacenes[$w]['unidad_id'] === $u) {
                    $aplicadas[] = $this->aplicar($f, $almacenes[$w], $q, null);
                    $f = $this->uno('SELECT * FROM faltantes_inventario WHERE id=? FOR UPDATE', [$id]);
                } else {
                    $enConfirmacion[] = $this->solicitar($f, $almacenes[$w], $q);
                }
            }
            $f = $this->uno('SELECT * FROM faltantes_inventario WHERE id=?', [$id]);
            self::cerrarAvisoSiResuelto($this->db, $u, (int)$f['almacen_id'], (int)$f['articulo_id'], 'faltante_conciliado');
            $r = ['codigo'=>'FALTANTE_CONCILIADO', 'faltante_id'=>$id, 'estado'=>$f['estado'], 'version'=>(int)$f['version'], 'aplicadas'=>$aplicadas, 'en_confirmacion'=>$enConfirmacion, 'reintento'=>false];
            $this->recordar($u, $clave, $hash, $r);
            return $r;
        });
    }

    /**
     * Confirmación del dueño del origen (llamada desde Transferencias dentro de su
     * transacción, con la transferencia y su versión ya validadas y bloqueadas).
     * Ejecuta envío, recepción y consumo juntos.
     */
    public function confirmarRegularizacion(array $t): void
    {
        $as = $this->uno("SELECT * FROM faltante_asignaciones WHERE transferencia_id=? FOR UPDATE", [$t['id']]);
        if (!$as || $as['estado'] !== 'en_confirmacion') throw new InventarioError('ESTADO_INVALIDO', 'La regularización ya no espera confirmación.');
        $f = $this->uno('SELECT * FROM faltantes_inventario WHERE id=? FOR UPDATE', [$as['faltante_id']]);
        if (!$f || $f['estado'] === 'anulado') throw new InventarioError('ESTADO_INVALIDO', 'El faltante de origen se anuló.');
        $origen = $this->uno('SELECT id,unidad_id,nombre,activo FROM almacenes WHERE id=? FOR UPDATE', [$as['almacen_origen_id']]);
        $this->asociacionActiva((int)$origen['id'], (int)$f['articulo_id'], $origen['nombre']);
        $q = DecimalInventario::leer($as['cantidad']);
        // La cantidad en confirmación pasa a conciliada dentro de aplicar().
        $this->db->execute_query('UPDATE faltantes_inventario SET cantidad_en_confirmacion=cantidad_en_confirmacion-? WHERE id=?', [$as['cantidad'], $f['id']]);
        $f['cantidad_en_confirmacion'] = DecimalInventario::cantidad(DecimalInventario::leer($f['cantidad_en_confirmacion']) - $q);
        $this->aplicar($f, $origen, $q, $t, (int)$as['id']);
        $f = $this->uno('SELECT * FROM faltantes_inventario WHERE id=?', [$f['id']]);
        self::cerrarAvisoSiResuelto($this->db, (int)$f['unidad_id'], (int)$f['almacen_id'], (int)$f['articulo_id'], 'faltante_conciliado');
    }

    /** Rechazo del dueño del origen: esa parte vuelve a pendiente con el motivo. */
    public function rechazarRegularizacion(array $t, string $motivo): void
    {
        $as = $this->uno("SELECT * FROM faltante_asignaciones WHERE transferencia_id=? FOR UPDATE", [$t['id']]);
        if (!$as || $as['estado'] !== 'en_confirmacion') throw new InventarioError('ESTADO_INVALIDO', 'La regularización ya no espera confirmación.');
        $f = $this->uno('SELECT * FROM faltantes_inventario WHERE id=? FOR UPDATE', [$as['faltante_id']]);
        $this->db->execute_query("UPDATE faltante_asignaciones SET estado='rechazada',motivo=?,resuelto_at=NOW() WHERE id=?", [mb_substr($motivo, 0, 255), $as['id']]);
        $this->db->execute_query('UPDATE faltantes_inventario SET cantidad_en_confirmacion=cantidad_en_confirmacion-? WHERE id=?', [$as['cantidad'], $f['id']]);
        $this->actualizarEstado((int)$f['id']);
        if ($f['estado'] !== 'anulado') self::avisar($this->db, (int)$f['unidad_id'], (int)$f['almacen_id'], (int)$f['articulo_id'], (int)$f['id']);
    }

    // ── Internos ─────────────────────────────────────────────────────────

    /**
     * Mueve q del origen al almacén de consumo y lo consume ligado al ticket.
     * Mismo almacén: sólo el consumo. Otro almacén: transferencia de
     * regularización enviada y recibida (nueva o la confirmada $t).
     */
    private function aplicar(array $f, array $origen, int $q, ?array $t, ?int $asignacionId = null): array
    {
        $articulo = (int)$f['articulo_id'];
        $destino = (int)$f['almacen_id'];
        $uDestino = (int)$f['unidad_id'];
        $uOrigen = (int)$origen['unidad_id'];
        $qSql = DecimalInventario::cantidad($q);
        $actor = $this->alcance->actorId();
        // Asociaciones bloqueadas en orden de almacén para evitar interbloqueos.
        $orden = array_unique([(int)$origen['id'], $destino]);
        sort($orden, SORT_NUMERIC);
        $aa = [];
        foreach ($orden as $w) $aa[$w] = $this->uno('SELECT aa.*,i.costo FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.almacen_id=? AND aa.articulo_id=? FOR UPDATE', [$w, $articulo]);
        $saldoOrigen = DecimalInventario::leer($aa[(int)$origen['id']]['saldo'], 3, true);
        if ($saldoOrigen < $q) throw new InventarioError('SALDO_INSUFICIENTE', 'El almacén «'.$origen['nombre'].'» no tiene saldo suficiente; reparte entre más almacenes o espera una entrada.');
        $costo = $aa[(int)$origen['id']]['costo'];
        $base = 'faltante-'.$f['id'].'-'.bin2hex(random_bytes(6));
        $lineaId = null;
        $transferenciaId = null;
        if ((int)$origen['id'] !== $destino) {
            if ($t === null) {
                $this->db->execute_query("INSERT INTO transferencias(tipo,almacen_origen_id,origen_unidad_id,almacen_destino_id,destino_unidad_id,solicitante_id,estado) VALUES ('regularizacion',?,?,?,?,?,'solicitada')", [$origen['id'], $uOrigen, $destino, $uDestino, $actor]);
                $transferenciaId = (int)$this->db->insert_id;
                $this->db->execute_query('INSERT INTO transferencia_lineas(transferencia_id,articulo_id,cantidad_solicitada) VALUES (?,?,?)', [$transferenciaId, $articulo, $qSql]);
                $this->historial($transferenciaId, null, 'solicitada', 'solicitar: regularización del faltante #'.$f['id']);
                $estadoPrevio = 'solicitada';
            } else { $transferenciaId = (int)$t['id']; $estadoPrevio = $t['estado']; }
            $linea = $this->uno('SELECT * FROM transferencia_lineas WHERE transferencia_id=? AND articulo_id=? FOR UPDATE', [$transferenciaId, $articulo]);
            $lineaId = (int)$linea['id'];
            $opSalida = $this->operacion($uOrigen, 'transferencia_salida', $base.'-salida', $transferenciaId);
            $this->mover($opSalida, $uOrigen, (int)$origen['id'], $articulo, 'transferencia_salida', -$q, $costo, 'Regularización de faltante', null, $lineaId, null);
            $opEntrada = $this->operacion($uDestino, 'transferencia_entrada', $base.'-entrada', $transferenciaId);
            $this->mover($opEntrada, $uDestino, $destino, $articulo, 'transferencia_entrada', $q, $costo, 'Regularización de faltante', null, $lineaId, null);
            $this->db->execute_query('UPDATE transferencia_lineas SET cantidad_enviada=?,cantidad_recibida=?,costo_unitario=? WHERE id=?', [$qSql, $qSql, $costo, $lineaId]);
            $this->db->execute_query("UPDATE transferencias SET estado='recibida',version=version+?,emisor_id=?,receptor_id=?,enviada_at=NOW(),recibida_at=NOW() WHERE id=?", [$t === null ? 2 : 1, $actor, $actor, $transferenciaId]);
            if ($t === null) $this->historial($transferenciaId, 'solicitada', 'enviada', 'enviar: regularización en el mismo negocio');
            $this->historial($transferenciaId, $t === null ? 'enviada' : $estadoPrevio, 'recibida', 'recibir: consumo conciliado del faltante #'.$f['id']);
        }
        // El consumo conciliado guarda el costo del origen; el ticket conserva el suyo.
        $opVenta = $this->operacion($uDestino, 'venta', $base.'-venta', $transferenciaId);
        $this->mover($opVenta, $uDestino, $destino, $articulo, 'venta', -$q, $costo, 'venta_conciliada', $f['ticket_item_id'] === null ? null : (int)$f['ticket_item_id'], null, (int)$f['id']);
        if ($asignacionId === null) {
            $this->db->execute_query("INSERT INTO faltante_asignaciones(faltante_id,almacen_origen_id,cantidad,transferencia_id,estado,actor_id,resuelto_at) VALUES (?,?,?,?,'aplicada',?,NOW())", [$f['id'], $origen['id'], $qSql, $transferenciaId, $actor]);
            $asignacionId = (int)$this->db->insert_id;
        } else {
            $this->db->execute_query("UPDATE faltante_asignaciones SET estado='aplicada',resuelto_at=NOW() WHERE id=?", [$asignacionId]);
        }
        $this->db->execute_query('UPDATE faltantes_inventario SET cantidad_conciliada=cantidad_conciliada+? WHERE id=?', [$qSql, $f['id']]);
        $this->actualizarEstado((int)$f['id']);
        return ['asignacion_id'=>$asignacionId, 'almacen_id'=>(int)$origen['id'], 'cantidad'=>$qSql, 'transferencia_id'=>$transferenciaId];
    }

    /** Origen de otro propietario: transferencia de regularización solicitada. */
    private function solicitar(array $f, array $origen, int $q): array
    {
        $actor = $this->alcance->actorId();
        $qSql = DecimalInventario::cantidad($q);
        $this->db->execute_query("INSERT INTO transferencias(tipo,almacen_origen_id,origen_unidad_id,almacen_destino_id,destino_unidad_id,solicitante_id,estado) VALUES ('regularizacion',?,?,?,?,?,'solicitada')",
            [$origen['id'], $origen['unidad_id'], $f['almacen_id'], $f['unidad_id'], $actor]);
        $tid = (int)$this->db->insert_id;
        $this->db->execute_query('INSERT INTO transferencia_lineas(transferencia_id,articulo_id,cantidad_solicitada) VALUES (?,?,?)', [$tid, $f['articulo_id'], $qSql]);
        $this->historial($tid, null, 'solicitada', 'solicitar: regularización del faltante #'.$f['id'].'; la confirma el dueño del origen');
        $this->db->execute_query("INSERT INTO faltante_asignaciones(faltante_id,almacen_origen_id,cantidad,transferencia_id,estado,actor_id) VALUES (?,?,?,?,'en_confirmacion',?)", [$f['id'], $origen['id'], $qSql, $tid, $actor]);
        $aid = (int)$this->db->insert_id;
        $this->db->execute_query('UPDATE faltantes_inventario SET cantidad_en_confirmacion=cantidad_en_confirmacion+? WHERE id=?', [$qSql, $f['id']]);
        $this->actualizarEstado((int)$f['id']);
        return ['asignacion_id'=>$aid, 'almacen_id'=>(int)$origen['id'], 'cantidad'=>$qSql, 'transferencia_id'=>$tid];
    }

    private function actualizarEstado(int $id): void
    {
        $f = $this->uno('SELECT * FROM faltantes_inventario WHERE id=? FOR UPDATE', [$id]);
        if ($f['estado'] === 'anulado') { $this->db->execute_query('UPDATE faltantes_inventario SET version=version+1 WHERE id=?', [$id]); return; }
        $total = DecimalInventario::leer($f['cantidad']);
        $c = DecimalInventario::leer($f['cantidad_conciliada']);
        $e = DecimalInventario::leer($f['cantidad_en_confirmacion']);
        $estado = $c === $total ? 'conciliado' : ($e > 0 && $c + $e === $total ? 'en_confirmacion' : 'pendiente');
        $this->db->execute_query('UPDATE faltantes_inventario SET estado=?,version=version+1 WHERE id=?', [$estado, $id]);
    }

    /** Cierra los avisos del almacén + artículo cuando ya no quedan faltantes pendientes. */
    private static function cerrarAvisoSiResuelto(\mysqli $db, int $unidad, int $almacen, int $articulo, string $motivo): void
    {
        $pendiente = $db->execute_query("SELECT 1 FROM faltantes_inventario WHERE unidad_id=? AND almacen_id=? AND articulo_id=? AND estado='pendiente' LIMIT 1", [$unidad, $almacen, $articulo])->fetch_row();
        if ($pendiente) return;
        $prefijo = str_replace(['!','%','_'], ['!!','!%','!_'], self::AVISO.':'.$almacen.':'.$articulo.':').'%';
        $db->execute_query("UPDATE buzon_notificaciones SET cerrada_at=NOW(),cierre_motivo=?,updated_at=NOW() WHERE unidad_id=? AND tipo=? AND cerrada_at IS NULL AND dedup_key LIKE ? ESCAPE '!'", [$motivo, $unidad, self::AVISO, $prefijo]);
    }

    private function operacion(int $unidad, string $tipo, string $clave, ?int $transferencia): int
    {
        $this->db->execute_query("INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia,resultado) VALUES (?,?,'usuario',?,'faltantes',?,?,?,?)",
            [$unidad, $tipo, $this->alcance->actorId(), $clave, hash('sha256', $clave), $clave, json_encode(['codigo'=>'FALTANTE_CONCILIADO','transferencia_id'=>$transferencia], JSON_THROW_ON_ERROR)]);
        return (int)$this->db->insert_id;
    }

    private function mover(int $op, int $unidad, int $almacen, int $articulo, string $tipo, int $delta, ?string $costo, string $motivo, ?int $ticketItem, ?int $linea, ?int $faltante): void
    {
        $a = $this->uno('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=? FOR UPDATE', [$almacen, $articulo]);
        $nuevo = DecimalInventario::sumar(DecimalInventario::leer($a['saldo'], 3, true), $delta);
        if ($nuevo < 0) throw new InventarioError('SALDO_INSUFICIENTE', 'El movimiento dejaría un saldo negativo.');
        $this->db->execute_query('UPDATE almacen_articulos SET saldo=?,version=version+1 WHERE almacen_id=? AND articulo_id=?', [DecimalInventario::cantidad($nuevo), $almacen, $articulo]);
        $this->db->execute_query('INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,motivo,costo_unitario,ticket_item_id,transferencia_linea_id,faltante_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$op, $unidad, $almacen, $articulo, $tipo, DecimalInventario::cantidad($delta), $motivo, $costo, $ticketItem, $linea, $faltante]);
    }

    private function historial(int $t, ?string $anterior, string $nuevo, string $motivo): void
    {
        $this->db->execute_query('INSERT INTO transferencia_historial(transferencia_id,estado_anterior,estado_nuevo,actor_id,motivo) VALUES (?,?,?,?,?)', [$t, $anterior, $nuevo, $this->alcance->actorId(), mb_substr($motivo, 0, 255)]);
    }

    private function asociacionActiva(int $almacen, int $articulo, string $nombre): void
    {
        $r = $this->uno('SELECT aa.activo,i.activo articulo_activo FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id WHERE aa.almacen_id=? AND aa.articulo_id=? FOR UPDATE', [$almacen, $articulo]);
        if (!$r) throw new InventarioError('ASOCIACION_NO_EXISTE', 'El artículo no está asociado a «'.$nombre.'».');
        if (!(int)$r['activo'] || !(int)$r['articulo_activo']) throw new InventarioError('ASOCIACION_INACTIVA', 'El artículo o su asociación en «'.$nombre.'» están inactivos.');
    }

    private function unidadArticulo(int $id): string { return (string)($this->uno('SELECT unidad FROM ingredientes WHERE id=?', [$id])['unidad'] ?? ''); }

    private static function restante(array $f): int
    {
        return DecimalInventario::leer($f['cantidad']) - DecimalInventario::leer($f['cantidad_conciliada']) - DecimalInventario::leer($f['cantidad_en_confirmacion']);
    }

    /** @return array<int,int> almacén => cantidad escalada */
    private static function asignaciones(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v) || $v === [] || count($v) > self::MAX_ASIGNACIONES) throw new InventarioError('LINEAS_INVALIDAS', 'Indica de 1 a '.self::MAX_ASIGNACIONES.' almacenes de origen.');
        $r = [];
        foreach ($v as $l) {
            if (!is_array($l) || array_diff(array_keys($l), ['almacen_id','cantidad'])) throw new InventarioError('LINEAS_INVALIDAS', 'Una asignación no es válida.');
            $w = self::id($l['almacen_id'] ?? null);
            $q = DecimalInventario::leer($l['cantidad'] ?? null);
            if ($q <= 0 || isset($r[$w])) throw new InventarioError('LINEAS_INVALIDAS', 'Cada almacén aparece una vez con cantidad positiva.');
            $r[$w] = $q;
        }
        ksort($r, SORT_NUMERIC);
        return $r;
    }

    private function comando(int $u, string $clave, string $hash): ?array
    {
        $r = $this->uno('SELECT huella,resultado FROM articulo_comandos WHERE unidad_id=? AND clave=? FOR UPDATE', [$u, $clave]);
        if (!$r) return null;
        if (!hash_equals($r['huella'], $hash)) throw new InventarioError('CONFLICTO_IDEMPOTENCIA', 'La clave ya pertenece a otra solicitud.');
        return json_decode($r['resultado'], true, 512, JSON_THROW_ON_ERROR);
    }

    private function recordar(int $u, string $clave, string $hash, array $r): void
    {
        $this->db->execute_query('INSERT INTO articulo_comandos(unidad_id,clave,huella,resultado) VALUES (?,?,?,?)', [$u, $clave, $hash, json_encode($r, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
    }

    private static function clave(mixed $v): string
    {
        if (!is_string($v) || !preg_match('/^[a-zA-Z0-9_-]{16,80}$/D', $v)) throw new InventarioError('CLAVE_INVALIDA', 'Recarga la bandeja para obtener una solicitud válida.');
        return $v;
    }
    private static function id(mixed $v): int { if ((!is_int($v) && !is_string($v)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string)$v)) throw new InventarioError('ID_INVALIDO', 'El identificador no es válido.'); return (int)$v; }
    private static function version(mixed $v): int { if ((!is_int($v) && !is_string($v)) || !preg_match('/^(0|[1-9][0-9]{0,17})$/D', (string)$v)) throw new InventarioError('VERSION_INVALIDA', 'Versión de faltante inválida.'); return (int)$v; }
    private function sql(string $sql, array $p = []): \mysqli_stmt { $s = $this->db->prepare($sql); $s->execute($p); return $s; }
    private function uno(string $sql, array $p = []): ?array { return $this->sql($sql, $p)->get_result()->fetch_assoc() ?: null; }
}
