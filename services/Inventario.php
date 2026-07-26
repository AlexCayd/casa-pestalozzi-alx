<?php

/**
 * Servicio de inventario: descuenta ingredientes al vender un platillo y los
 * repone al cancelarlo. El enlace platillo↔producto es por NOMBRE exacto
 * (ticket_items.nombre = productos.nombre). Si el platillo no tiene producto o
 * receta, no se descuenta nada: el pedido nunca se bloquea por inventario y el
 * stock puede quedar negativo.
 */

namespace Services;

use Model\Ingrediente;

class Inventario
{
    /**
     * Descuenta el inventario por la venta de $cantidad unidades del platillo
     * $nombre. Registra un movimiento por cada ingrediente afectado.
     */
    public static function aplicarVenta(string $nombre, int $cantidad, ?int $ticketItemId = null): void
    {
        if ($cantidad <= 0) {
            return;
        }

        try {
            $db = Ingrediente::getDB();
            if (!$db) {
                return;
            }

            $nombreEsc = $db->real_escape_string(trim($nombre));
            $resProd = $db->query("SELECT id FROM productos WHERE nombre = '{$nombreEsc}' LIMIT 1");
            if (!$resProd || !($prod = $resProd->fetch_assoc())) {
                return; // Platillo sin producto en el catálogo: no se descuenta.
            }

            $productoId = (int) $prod['id'];
            $totales = self::explotarReceta($productoId, $db);
            if (empty($totales)) {
                return; // Producto sin receta: nada que descontar.
            }

            $tidSql = $ticketItemId ? (int) $ticketItemId : 'NULL';

            foreach ($totales as $ingredienteId => $porUnidad) {
                $delta = $porUnidad * $cantidad; // cantidad total a descontar
                if ($delta == 0.0) {
                    continue;
                }
                $ingredienteId = (int) $ingredienteId;
                $deltaSql = self::num($delta);
                $movSql = self::num(-$delta); // negativo = descuento

                // Se permite que el stock quede negativo (sin topar en 0).
                $db->query("UPDATE ingredientes SET stock = stock - ({$deltaSql}) WHERE id = {$ingredienteId}");
                $db->query(
                    "INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, ticket_item_id)
                     VALUES ({$ingredienteId}, 'venta', {$movSql}, {$tidSql})"
                );
            }
        } catch (\Throwable $e) {
            error_log('Inventario::aplicarVenta - ' . $e->getMessage());
        }
    }

    /**
     * Repone el inventario descontado por un ticket_item (al cancelarlo). Usa
     * los movimientos de 'venta' previos para saber cuánto reponer.
     */
    public static function revertir(?int $ticketItemId): void
    {
        if (!$ticketItemId) {
            return;
        }

        try {
            $db = Ingrediente::getDB();
            if (!$db) {
                return;
            }

            $tid = (int) $ticketItemId;
            // Evita doble reposición si ya se canceló antes.
            $yaRevertido = $db->query(
                "SELECT 1 FROM movimientos_inventario WHERE ticket_item_id = {$tid} AND tipo = 'cancelacion' LIMIT 1"
            );
            if ($yaRevertido && $yaRevertido->fetch_row()) {
                return;
            }

            $res = $db->query(
                "SELECT ingrediente_id, cantidad FROM movimientos_inventario
                 WHERE ticket_item_id = {$tid} AND tipo = 'venta'"
            );
            if (!$res) {
                return;
            }

            while ($row = $res->fetch_assoc()) {
                $ingredienteId = (int) $row['ingrediente_id'];
                $repone = -1 * (float) $row['cantidad']; // el movimiento de venta es negativo
                if ($repone == 0.0) {
                    continue;
                }
                $reponeSql = self::num($repone);
                $db->query("UPDATE ingredientes SET stock = stock + ({$reponeSql}) WHERE id = {$ingredienteId}");
                $db->query(
                    "INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, ticket_item_id)
                     VALUES ({$ingredienteId}, 'cancelacion', {$reponeSql}, {$tid})"
                );
            }
        } catch (\Throwable $e) {
            error_log('Inventario::revertir - ' . $e->getMessage());
        }
    }

    /**
     * Costo de insumos de una unidad del producto: explota su receta y suma
     * (cantidad × costo unitario) de cada ingrediente. 0 si no tiene receta.
     */
    public static function costoDeProducto(int $productoId): float
    {
        try {
            $db = Ingrediente::getDB();
            if (!$db) {
                return 0.0;
            }
            $totales = self::explotarReceta($productoId, $db);
            if (empty($totales)) {
                return 0.0;
            }
            $ids = implode(',', array_map('intval', array_keys($totales)));
            $costos = [];
            $res = $db->query("SELECT id, costo FROM ingredientes WHERE id IN ({$ids})");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $costos[(int) $r['id']] = (float) $r['costo'];
                }
            }
            $total = 0.0;
            foreach ($totales as $ingId => $cantidad) {
                $total += $cantidad * ($costos[(int) $ingId] ?? 0.0);
            }
            return $total;
        } catch (\Throwable $e) {
            error_log('Inventario::costoDeProducto - ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Explota la receta de un producto hasta ingredientes: devuelve
     * [ingrediente_id => cantidad_por_unidad_de_producto]. Las subrecetas se
     * escalan por (cantidad_usada / rendimiento).
     */
    private static function explotarReceta(int $productoId, $db): array
    {
        $totales = [];

        $comps = $db->query(
            "SELECT tipo, ref_id, cantidad FROM producto_componentes WHERE producto_id = {$productoId}"
        );
        if (!$comps) {
            return $totales;
        }

        while ($comp = $comps->fetch_assoc()) {
            $cantidad = (float) $comp['cantidad'];
            $refId = (int) $comp['ref_id'];

            if ($comp['tipo'] === 'ingrediente') {
                $totales[$refId] = ($totales[$refId] ?? 0.0) + $cantidad;
                continue;
            }

            // Subreceta: escalar sus ingredientes por cantidad / rendimiento.
            $resSub = $db->query("SELECT rendimiento FROM subrecetas WHERE id = {$refId} LIMIT 1");
            if (!$resSub || !($sub = $resSub->fetch_assoc())) {
                continue;
            }
            $rendimiento = (float) $sub['rendimiento'];
            if ($rendimiento <= 0) {
                $rendimiento = 1.0;
            }
            $factor = $cantidad / $rendimiento;

            $resIng = $db->query(
                "SELECT ingrediente_id, cantidad FROM subreceta_ingredientes WHERE subreceta_id = {$refId}"
            );
            if (!$resIng) {
                continue;
            }
            while ($si = $resIng->fetch_assoc()) {
                $ingId = (int) $si['ingrediente_id'];
                $totales[$ingId] = ($totales[$ingId] ?? 0.0) + ((float) $si['cantidad'] * $factor);
            }
        }

        return $totales;
    }

    /** Formatea un float para SQL con punto decimal (independiente del locale). */
    private static function num(float $valor): string
    {
        return number_format($valor, 3, '.', '');
    }
}
