<?php
declare(strict_types=1);

namespace Services\Pos;

use Model\ActiveRecord;
use Services\Inventory\Almacenes;
use Services\Inventory\InventarioError;
use Services\Menu\Variantes;
use Services\Security\ContextoNegocio;

/** Operaciones comerciales POS. La ruta conserva el contrato HTTP; este servicio
 * valida la oferta/unidad y confirma conjuntamente ticket, líneas y consumo. */
final class Comercial
{
    private const MAX_ITEMS = 100;

    public static function enviarComanda(int $ticketId, array $items, string $clave, string $ctx): array
    {
        if (!ContextoNegocio::nuevo()) {
            return ['ok' => false, 'confirmado' => false, 'codigo' => 'ESQUEMA_NO_DISPONIBLE'];
        }
        try {
            return ContextoNegocio::en($ctx, static function () use ($ticketId, $items, $clave, $ctx): array {
                ContextoNegocio::exigir('pdv', 'operar', $ctx);
                self::soloRestaurante($ctx);
                $unidad = ContextoNegocio::vendedora($ctx);
                $actor = ContextoNegocio::actorId();
                if ($ticketId < 1 || $actor < 1 || !self::claveValida($clave)
                    || !array_is_list($items) || $items === [] || count($items) > self::MAX_ITEMS) {
                    throw new InventarioError('DATOS_INVALIDOS', 'La comanda no contiene una solicitud válida.');
                }
                $normalizados = self::normalizarItems($items);
                $hash = hash('sha256', json_encode(
                    [$actor, $unidad, 'comanda', $ticketId, $normalizados],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
                $db = ActiveRecord::getDB();
                $resultado = ContextoNegocio::transaccion(static function () use (
                    $db, $ticketId, $ctx, $unidad, $actor, $clave, $hash, $normalizados
                ): array {
                    ContextoNegocio::exigir('pdv', 'operar', $ctx, true);
                    self::soloRestaurante($ctx);
                    $ticket = self::ticketBloqueado($db, $ticketId, $unidad);
                    $reintento = self::buscarOperacion($db, $unidad, $actor, 'comanda', $clave, $hash, $ticketId);
                    if ($reintento !== null) {
                        $reintento['reintento'] = true;
                        $reintento['imprimir'] = false;
                        return $reintento;
                    }
                    if ($ticket['estado'] !== 'abierto' || $ticket['closed_at'] !== null) {
                        throw new InventarioError('TICKET_NO_VALIDO', 'El ticket no está abierto en este negocio.');
                    }
                    // Resolver todas las líneas primero. Una oferta ajena, variante
                    // obsoleta o dato inválido cancela el lote completo.
                    $resueltas = [];
                    foreach ($normalizados as $linea) {
                        if ($linea['comensal'] !== null && $linea['comensal'] > (int)$ticket['comensales']) {
                            throw new InventarioError('COMENSAL_INVALIDO', 'El comensal no pertenece a este ticket.');
                        }
                        $oferta = self::oferta($db, (int)$linea['oferta_id'], $unidad, true);
                        if (!$oferta) {
                            throw new InventarioError('OFERTA_NO_DISPONIBLE', 'Uno de los productos ya no está disponible.');
                        }
                        $variantes = Variantes::resolver((int)$oferta['oferta_id'], $linea['variantes'], $ctx);
                        if (!($variantes['ok'] ?? false)) {
                            throw new InventarioError('VARIANTE_INVALIDA', (string)($variantes['error'] ?? 'Revisa las opciones del producto.'));
                        }
                        $precio = round((float)$oferta['precio'] + (float)$variantes['delta'], 2);
                        if ($precio <= 0) {
                            throw new InventarioError('PRECIO_INVALIDO', 'El precio calculado de una oferta no es válido.');
                        }
                        $resueltas[] = $linea + [
                            'producto_id' => (int)$oferta['producto_id'],
                            'nombre' => (string)$oferta['nombre'],
                            'precio' => $precio,
                            'categoria' => (string)$oferta['categoria'],
                            'categoria_id' => (int)$oferta['categoria_id'],
                            'area_id' => (int)$oferta['area_id'],
                            'area_nombre' => (string)$oferta['area_nombre'],
                            'receta_id' => $oferta['receta_id'] !== null ? (int)$oferta['receta_id'] : null,
                            'variante_ids' => $variantes['ids'],
                            'variante' => $variantes['etiqueta'],
                        ];
                    }

                    $itemIds = [];
                    $itemsComanda = [];
                    $almacenes = new Almacenes($db, $actor, ContextoNegocio::nivelAuth());
                    foreach ($resueltas as $linea) {
                        $itemId = self::insertarItem($db, $ticketId, $unidad, $linea);
                        $consumo = $almacenes->consumirVenta($ctx, $itemId);
                        if (($consumo['ok'] ?? true) === false) {
                            throw new InventarioError((string)($consumo['codigo'] ?? 'CONSUMO_FALLIDO'), (string)($consumo['mensaje'] ?? 'No se pudo registrar el consumo de inventario.'));
                        }
                        if (array_key_exists('costo_unitario', $consumo) && $consumo['costo_unitario'] !== null) {
                            self::actualizarCosto($db, $itemId, $unidad, (string)$consumo['costo_unitario']);
                        }
                        $itemIds[] = $itemId;
                        $itemsComanda[] = [
                            'nombre' => $linea['nombre'],
                            'variante' => $linea['variante'],
                            'cantidad' => $linea['cantidad'],
                            'comensal' => $linea['comensal'],
                            'nota' => $linea['nota'],
                            'precio' => $linea['precio'],
                            'area_id' => $linea['area_id'],
                        ];
                    }
                    $payload = [
                        'ok' => true,
                        'ticket_id' => $ticketId,
                        'count' => count($itemIds),
                        'item_ids' => $itemIds,
                        'items_comanda' => $itemsComanda,
                        'reintento' => false,
                        'imprimir' => true,
                    ];
                    self::guardarResultadoOperacion($db, $unidad, $actor, 'comanda', $clave, $payload);
                    return $payload;
                });
                if (($resultado['ok'] ?? false) === true && !($resultado['confirmado'] ?? false)) {
                    return ['ok' => false, 'confirmado' => false, 'pendiente' => true, 'codigo' => 'COMANDA_PENDIENTE'];
                }
                return $resultado;
            });
        } catch (\Throwable $e) {
            return self::error($e);
        }
    }

    public static function cancelarItem(int $itemId, string $ctx): array
    {
        return self::mutarItem($itemId, $ctx, static function (\mysqli $db, array $item, int $unidad, string $ctx): array {
            if ($item['estado'] === 'cancelado') return ['ok' => true, 'codigo' => 'ITEM_CANCELADO', 'idempotente' => true];
            if (in_array($item['estado'], ['entregado'], true) || !in_array($item['estado'], ['enviado', 'en_preparacion', 'listo'], true)) {
                throw new InventarioError('ITEM_NO_CANCELABLE', 'Este producto ya no se puede cancelar.');
            }
            self::sql($db, "UPDATE ticket_items SET estado='cancelado' WHERE id=? AND unidad_id=? AND estado IN ('enviado','en_preparacion','listo')", [$item['id'], $unidad]);
            $almacenes = new Almacenes($db, ContextoNegocio::actorId(), ContextoNegocio::nivelAuth());
            $r = $almacenes->revertirVenta($ctx, (int)$item['id']);
            if (($r['ok'] ?? true) === false) throw new InventarioError((string)($r['codigo'] ?? 'REVERSA_FALLIDA'), (string)($r['mensaje'] ?? 'No se pudo reponer el inventario original.'));
            return ['ok' => true, 'codigo' => 'ITEM_CANCELADO', 'idempotente' => false];
        });
    }

    public static function entregarItem(int $itemId, string $ctx): array
    {
        return self::mutarItem($itemId, $ctx, static function (\mysqli $db, array $item, int $unidad): array {
            if (!in_array($item['estado'], ['enviado', 'en_preparacion', 'listo'], true)) {
                throw new InventarioError('ITEM_NO_ENTREGABLE', 'El producto ya no está pendiente de entrega.');
            }
            self::sql($db, "UPDATE ticket_items SET estado='entregado' WHERE id=? AND unidad_id=? AND estado IN ('enviado','en_preparacion','listo')", [$item['id'], $unidad]);
            return ['ok' => true, 'codigo' => 'ITEM_ENTREGADO'];
        });
    }

    public static function actualizarTicket(int $ticketId, ?string $nombre, string $ctx): array
    {
        try {
            return ContextoNegocio::en($ctx, static function () use ($ticketId, $nombre, $ctx): array {
                ContextoNegocio::exigir('pdv', 'operar', $ctx);
                self::soloRestaurante($ctx);
                $unidad = ContextoNegocio::vendedora($ctx); $db = ActiveRecord::getDB();
                $nombre = $nombre !== null ? trim($nombre) : null;
                if ($ticketId < 1 || ($nombre !== null && (mb_strlen($nombre) > 120 || str_contains($nombre, "\0")))) throw new InventarioError('DATOS_INVALIDOS', 'El nombre del ticket no es válido.');
                $r = ContextoNegocio::transaccion(static function () use ($db, $ticketId, $unidad, $ctx, $nombre): array {
                    ContextoNegocio::exigir('pdv', 'operar', $ctx, true);
                    self::soloRestaurante($ctx);
                    self::ticketAbiertoBloqueado($db, $ticketId, $unidad);
                    self::sql($db, 'UPDATE tickets SET nombre=? WHERE id=? AND unidad_id=? AND estado=\'abierto\' AND closed_at IS NULL', [$nombre, $ticketId, $unidad]);
                    return ['ok' => true, 'codigo' => 'TICKET_ACTUALIZADO', 'id' => $ticketId];
                });
                return $r['pendiente'] ?? false ? ['ok' => false, 'confirmado' => false, 'codigo' => 'OPERACION_PENDIENTE'] : $r;
            });
        } catch (\Throwable $e) { return self::error($e); }
    }

    /** Lectura usada por HTTP: id, precio y estados son del snapshot vendido. */
    public static function ticketItems(int $ticketId, string $ctx): array
    {
        try {
            return ContextoNegocio::en($ctx, static function () use ($ticketId, $ctx): array {
                ContextoNegocio::exigir('pdv', 'operar', $ctx);
                self::soloRestaurante($ctx);
                $unidad = ContextoNegocio::vendedora($ctx); $db = ActiveRecord::getDB();
                if ($ticketId < 1) throw new InventarioError('TICKET_NO_VALIDO', 'El ticket no es válido.');
                $stmt = self::sql($db,
                    'SELECT ti.id,ti.nombre,ti.precio,ti.categoria,ti.area_id,COALESCE(ti.area_nombre,ap.nombre) area_nombre,ap.slug area_slug,ap.color area_color,ti.comensal,ti.cantidad,ti.nota,ti.variante,ti.estado,ti.created_at
                       FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id
                       LEFT JOIN areas_produccion ap ON ap.id=ti.area_id AND ap.unidad_id=ti.unidad_id
                      WHERE ti.ticket_id=? AND ti.unidad_id=? ORDER BY ti.area_id,ti.created_at,ti.id',
                    [$ticketId, $unidad]
                );
                $items = [];
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $items[] = [
                        'id' => (int)$r['id'], 'nombre' => (string)$r['nombre'], 'precio' => (float)$r['precio'],
                        'categoria' => (string)$r['categoria'], 'area_id' => (int)$r['area_id'],
                        'area_nombre' => (string)$r['area_nombre'], 'area_slug' => (string)$r['area_slug'],
                        'area_color' => (string)$r['area_color'], 'comensal' => $r['comensal'] !== null ? (int)$r['comensal'] : null,
                        'cantidad' => (int)$r['cantidad'], 'nota' => $r['nota'], 'variante' => $r['variante'],
                        'estado' => (string)$r['estado'], 'created_at' => $r['created_at'],
                    ];
                }
                return ['ok' => true, 'items' => $items];
            });
        } catch (\Throwable $e) { return self::error($e); }
    }

    /** Total canónico para que el controller de pagos no lea tickets ajenos. */
    public static function totalTicket(int $ticketId, string $ctx): array
    {
        try {
            return ContextoNegocio::en($ctx, static function () use ($ticketId, $ctx): array {
                ContextoNegocio::exigir('pdv', 'operar', $ctx);
                self::soloRestaurante($ctx);
                $unidad = ContextoNegocio::vendedora($ctx); $db = ActiveRecord::getDB();
                $row = self::sql($db,
                    "SELECT t.estado,COUNT(CASE WHEN ti.estado NOT IN ('entregado','cancelado') THEN 1 END) pendientes,COALESCE(SUM(CASE WHEN ti.estado<>'cancelado' THEN ti.precio*ti.cantidad ELSE 0 END),0) total FROM tickets t LEFT JOIN ticket_items ti ON ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id WHERE t.id=? AND t.unidad_id=? GROUP BY t.id,t.estado",
                    [$ticketId, $unidad]
                )->get_result()->fetch_assoc();
                if (!$row) throw new InventarioError('TICKET_NO_VALIDO', 'El ticket no existe en este negocio.');
                return ['ok' => true, 'estado' => (string)$row['estado'], 'pendientes' => (int)$row['pendientes'], 'total' => round((float)$row['total'], 2)];
            });
        } catch (\Throwable $e) { return self::error($e); }
    }

    private static function mutarItem(int $itemId, string $ctx, callable $accion): array
    {
        try {
            return ContextoNegocio::en($ctx, static function () use ($itemId, $ctx, $accion): array {
                ContextoNegocio::exigir('pdv', 'operar', $ctx);
                self::soloRestaurante($ctx);
                $unidad = ContextoNegocio::vendedora($ctx); $db = ActiveRecord::getDB();
                if ($itemId < 1) throw new InventarioError('ITEM_ID_REQUERIDO', 'El producto del ticket no es válido.');
                $r = ContextoNegocio::transaccion(static function () use ($db, $itemId, $ctx, $unidad, $accion): array {
                    ContextoNegocio::exigir('pdv', 'operar', $ctx, true);
                    self::soloRestaurante($ctx);
                    $stmt = self::sql($db,
                        'SELECT ti.id,ti.ticket_id,ti.estado FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id WHERE ti.id=? AND ti.unidad_id=? AND t.estado=\'abierto\' AND t.closed_at IS NULL FOR UPDATE',
                        [$itemId, $unidad]
                    );
                    $item = $stmt->get_result()->fetch_assoc();
                    if (!$item) throw new InventarioError('ITEM_NO_DISPONIBLE', 'El producto no pertenece a un ticket abierto de este negocio.');
                    return $accion($db, $item, $unidad, $ctx);
                });
                if (($r['ok'] ?? false) === true && !($r['confirmado'] ?? false)) return ['ok' => false, 'confirmado' => false, 'pendiente' => true, 'codigo' => 'OPERACION_PENDIENTE'];
                return $r;
            });
        } catch (\Throwable $e) { return self::error($e); }
    }

    private static function normalizarItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) throw new InventarioError('ITEM_INVALIDO', 'Una línea de la comanda no es válida.');
            $oferta = filter_var($item['oferta_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $cantidad = filter_var($item['cantidad'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 255]]);
            $comensal = $item['comensal'] ?? null;
            if ($comensal !== null) {
                $comensal = filter_var($comensal, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 255]]);
                if ($comensal === false) throw new InventarioError('COMENSAL_INVALIDO', 'El comensal indicado no es válido.');
            }
            $variantes = $item['variantes'] ?? [];
            if (!is_array($variantes) || !array_is_list($variantes)) throw new InventarioError('VARIANTES_INVALIDAS', 'Las opciones del producto no son válidas.');
            foreach ($variantes as $id) if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new InventarioError('VARIANTES_INVALIDAS', 'Las opciones del producto no son válidas.');
            $nota = $item['nota'] ?? null;
            if ($nota !== null && (!is_string($nota) || mb_strlen($nota) > 280 || str_contains($nota, "\0"))) throw new InventarioError('NOTA_INVALIDA', 'La nota de cocina no es válida.');
            if ($oferta === false || $cantidad === false) throw new InventarioError('ITEM_INVALIDO', 'La oferta o cantidad de la comanda no es válida.');
            $ids = array_values(array_unique(array_map('intval', $variantes))); sort($ids, SORT_NUMERIC);
            $out[] = ['oferta_id' => (int)$oferta, 'cantidad' => (int)$cantidad, 'comensal' => $comensal === null ? null : (int)$comensal, 'nota' => $nota !== null && trim($nota) !== '' ? trim($nota) : null, 'variantes' => $ids];
        }
        return $out;
    }

    private static function oferta(\mysqli $db, int $id, int $unidad, bool $bloquear): ?array
    {
        $lock = $bloquear ? ' FOR UPDATE' : '';
        $stmt = self::sql($db,
            'SELECT o.id oferta_id,o.producto_id,o.precio,c.id categoria_id,c.nombre categoria,ap.id area_id,ap.nombre area_nombre,p.nombre,p.img,r.id receta_id
               FROM ofertas_producto o JOIN productos_oferta p ON p.oferta_id=o.id
               JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
               JOIN areas_produccion ap ON ap.id=o.area_id AND ap.unidad_id=o.unidad_id
               LEFT JOIN recetas r ON r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1
              WHERE o.id=? AND o.unidad_id=? AND o.activo=1 AND c.activo=1 ORDER BY r.version DESC LIMIT 1' . $lock,
            [$id, $unidad]
        );
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private static function insertarItem(\mysqli $db, int $ticketId, int $unidad, array $i): int
    {
        $stmt = self::sql($db,
            "INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,receta_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,comensal,cantidad,nota,variante_ids,variante,estado)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'enviado')",
            [$unidad,$ticketId,$i['oferta_id'],$i['producto_id'],$i['receta_id'],$i['nombre'],number_format($i['precio'],2,'.',''),$i['categoria'],$i['categoria_id'],$i['area_id'],$i['area_nombre'],$i['comensal'],$i['cantidad'],$i['nota'],$i['variante_ids'] ? implode(',',$i['variante_ids']) : null,$i['variante']]
        );
        return (int)$db->insert_id;
    }

    private static function actualizarCosto(\mysqli $db, int $itemId, int $unidad, string $costo): void
    {
        if (!preg_match('/^\d{1,6}(?:\.\d{1,4})?$/D', $costo)) throw new InventarioError('COSTO_INVALIDO', 'El costo calculado no es válido.');
        self::sql($db, 'UPDATE ticket_items SET costo_unitario=? WHERE id=? AND unidad_id=?', [$costo,$itemId,$unidad]);
    }

    private static function ticketBloqueado(\mysqli $db, int $ticketId, int $unidad): array
    {
        $r = self::sql($db, 'SELECT id,comensales,estado,closed_at FROM tickets WHERE id=? AND unidad_id=? FOR UPDATE', [$ticketId,$unidad])->get_result()->fetch_assoc();
        if (!$r) throw new InventarioError('TICKET_NO_VALIDO', 'El ticket no está abierto en este negocio.');
        return $r;
    }

    private static function buscarOperacion(\mysqli $db, int $unidad, int $actor, string $tipo, string $clave, string $hash, int $ticket): ?array
    {
        try {
            self::sql($db, 'INSERT INTO pos_operaciones(unidad_id,actor_id,ticket_id,tipo,clave,hash_solicitud) VALUES(?,?,?,?,?,?)', [$unidad,$actor,$ticket,$tipo,$clave,$hash]);
            return null;
        } catch (\mysqli_sql_exception $e) {
            if ($e->getCode() !== 1062) throw $e;
        }
        $row = self::sql($db, 'SELECT ticket_id,hash_solicitud,resultado FROM pos_operaciones WHERE unidad_id=? AND actor_id=? AND tipo=? AND clave=? FOR UPDATE', [$unidad,$actor,$tipo,$clave])->get_result()->fetch_assoc();
        if (!$row || (int)$row['ticket_id'] !== $ticket || !hash_equals((string)$row['hash_solicitud'], $hash) || $row['resultado'] === null) {
            throw new InventarioError('CONFLICTO_IDEMPOTENCIA', 'La clave de envío ya se usó con otro contenido.');
        }
        $result = json_decode((string)$row['resultado'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new InventarioError('CONFLICTO_IDEMPOTENCIA', 'No se pudo recuperar el envío original.');
        return $result;
    }

    private static function guardarResultadoOperacion(\mysqli $db, int $unidad, int $actor, string $tipo, string $clave, array $resultado): void
    {
        self::sql($db, 'UPDATE pos_operaciones SET resultado=? WHERE unidad_id=? AND actor_id=? AND tipo=? AND clave=?', [json_encode($resultado, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),$unidad,$actor,$tipo,$clave]);
    }

    private static function sql(\mysqli $db, string $sql, array $args = []): \mysqli_stmt
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) throw new \RuntimeException($db->error);
        if ($args !== []) {
            $types = '';
            foreach ($args as $arg) $types .= is_int($arg) ? 'i' : (is_float($arg) ? 'd' : 's');
            $stmt->bind_param($types, ...$args);
        }
        if (!$stmt->execute()) { $error = $stmt->error; $stmt->close(); throw new \RuntimeException($error); }
        return $stmt;
    }

    private static function claveValida(string $clave): bool
    {
        return strlen($clave) >= 16 && strlen($clave) <= 120 && preg_match('/^[A-Za-z0-9._:-]+$/D', $clave) === 1;
    }

    private static function error(\Throwable $e): array
    {
        return [
            'ok' => false, 'confirmado' => false,
            'codigo' => $e instanceof InventarioError ? $e->codigo : 'OPERACION_POS_FALLIDA',
            'mensaje' => $e instanceof InventarioError ? $e->getMessage() : 'No se pudo completar la operación del POS.',
        ];
    }

    private static function soloRestaurante(string $ctx): void
    {
        if ($ctx !== 'restaurante') {
            throw new InventarioError('AMBITO_NO_HABILITADO', 'El POS comercial solo está habilitado para Restaurante.');
        }
    }
}
