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
     * Motivos de merma. Es la única lista: alimenta el <select> del modal y la
     * validación del controlador, para que no puedan divergir.
     */
    public const MOTIVOS_MERMA = [
        'caducidad' => 'Caducidad',
        'dano' => 'Daño en almacén',
        'derrame' => 'Derrame o rotura',
        'preparacion' => 'Error de preparación',
        'faltante' => 'Faltante de inventario',
        'otro' => 'Otro',
    ];

    public static function motivoMermaValido(string $motivo): bool
    {
        return isset(self::MOTIVOS_MERMA[$motivo]);
    }

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
     * Registra una merma: descuenta el stock y deja el movimiento con su
     * motivo, quién lo registró y el costo unitario del momento.
     *
     * El costo se congela aquí porque una merma es un gasto consumado: si
     * mañana sube el precio del ingrediente, lo que se perdió ayer siguió
     * costando lo que costaba ayer.
     *
     * @return array{ok: bool, mensaje: string, valor: float}
     */
    public static function registrarMerma(
        int $ingredienteId,
        float $cantidad,
        string $motivo,
        string $nota,
        ?int $usuarioId
    ): array {
        if ($cantidad <= 0) {
            return ['ok' => false, 'mensaje' => 'La cantidad debe ser mayor a cero.', 'valor' => 0.0];
        }

        if (!self::motivoMermaValido($motivo)) {
            return ['ok' => false, 'mensaje' => 'Elige un motivo de la lista.', 'valor' => 0.0];
        }

        $ingrediente = Ingrediente::find($ingredienteId);
        if (!$ingrediente) {
            return ['ok' => false, 'mensaje' => 'El ingrediente no existe.', 'valor' => 0.0];
        }

        try {
            $db = Ingrediente::getDB();
            if (!$db) {
                return ['ok' => false, 'mensaje' => 'No hay conexión con la base de datos.', 'valor' => 0.0];
            }

            $costo = (float) $ingrediente->costo;
            $cantidadSql = self::num($cantidad);

            // Igual que en las ventas, el stock puede quedar negativo: si el
            // conteo físico dice que se tiró más de lo registrado, el dato real
            // es la merma, no el saldo que el sistema creía tener.
            $db->query("UPDATE ingredientes SET stock = stock - ({$cantidadSql}) WHERE id = {$ingredienteId}");

            // Preparada porque motivo y nota vienen del usuario.
            $stmt = $db->prepare(
                'INSERT INTO movimientos_inventario
                    (ingrediente_id, tipo, cantidad, motivo, nota, costo_unitario, usuario_id)
                 VALUES (?, \'merma\', ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return ['ok' => false, 'mensaje' => 'No se pudo registrar la merma.', 'valor' => 0.0];
            }

            $negativa = -$cantidad;
            $notaLimpia = mb_substr(trim($nota), 0, 255);
            $notaParam = $notaLimpia === '' ? null : $notaLimpia;
            $stmt->bind_param('idssdi', $ingredienteId, $negativa, $motivo, $notaParam, $costo, $usuarioId);
            $stmt->execute();
            $stmt->close();

            return [
                'ok' => true,
                'mensaje' => 'Merma registrada: ' . $cantidad . ' ' . $ingrediente->unidad . ' de ' . $ingrediente->nombre . '.',
                'valor' => $cantidad * $costo,
            ];
        } catch (\Throwable $e) {
            error_log('Inventario::registrarMerma - ' . $e->getMessage());
            return ['ok' => false, 'mensaje' => 'No se pudo registrar la merma.', 'valor' => 0.0];
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

    /**
     * Platillos y subrecetas que consumen un ingrediente.
     *
     * Es la consulta INVERSA, y hasta ahora no existía: todo el módulo recorre
     * la relación en el sentido producto → ingredientes (explotarReceta, el
     * COGS, el compositor de recetas), nunca al revés. Hace falta para poder
     * decir, antes de borrar, qué se va a llevar por delante.
     *
     * Son dos vías y por eso es un UNION:
     *  · directa   — producto_componentes con tipo 'ingrediente' apuntando al id.
     *  · indirecta — el ingrediente está en una subreceta, y esa subreceta la
     *                consume un platillo. Aquí lo interesante es el nombre de la
     *                subreceta, porque es donde el usuario tendrá que ir a
     *                editarlo.
     *
     * `ref_id` no lleva FK (es polimórfico: apunta a ingredientes.id o a
     * subrecetas.id según `tipo`), así que el filtro por tipo NO es opcional —
     * sin él se colarían subrecetas cuyo id coincida con el del ingrediente.
     *
     * @return array<int, array{platillo: string, via: string, subreceta: ?string}>
     */
    public static function recetasQueUsan(int $ingredienteId): array
    {
        $ingredienteId = max(0, $ingredienteId);
        if ($ingredienteId === 0) {
            return [];
        }

        $db = Ingrediente::getDB();
        if (!$db) {
            return [];
        }

        $sql = "
            SELECT p.nombre AS platillo, 'directa' AS via, NULL AS subreceta
              FROM producto_componentes pc
              JOIN productos p ON p.id = pc.producto_id
             WHERE pc.tipo = 'ingrediente' AND pc.ref_id = {$ingredienteId}

            UNION

            SELECT p.nombre AS platillo, 'subreceta' AS via, s.nombre AS subreceta
              FROM subreceta_ingredientes si
              JOIN subrecetas s ON s.id = si.subreceta_id
              JOIN producto_componentes pc
                ON pc.tipo = 'subreceta' AND pc.ref_id = si.subreceta_id
              JOIN productos p ON p.id = pc.producto_id
             WHERE si.ingrediente_id = {$ingredienteId}

             ORDER BY platillo ASC
        ";

        $resultado = $db->query($sql);
        if (!$resultado) {
            return [];
        }

        $filas = [];
        while ($fila = $resultado->fetch_assoc()) {
            $filas[] = [
                'platillo' => (string) $fila['platillo'],
                'via' => (string) $fila['via'],
                'subreceta' => $fila['subreceta'] !== null ? (string) $fila['subreceta'] : null,
            ];
        }
        $resultado->free();

        return $filas;
    }

    /**
     * Subrecetas que contienen el ingrediente, aunque ningún platillo las use.
     * Se pierden igual al borrar y no aparecen en recetasQueUsan(), que sólo
     * llega a las que están montadas en algún platillo.
     */
    public static function subrecetasQueUsan(int $ingredienteId): array
    {
        $ingredienteId = max(0, $ingredienteId);
        if ($ingredienteId === 0) {
            return [];
        }

        $db = Ingrediente::getDB();
        if (!$db) {
            return [];
        }

        $resultado = $db->query(
            "SELECT s.nombre
               FROM subreceta_ingredientes si
               JOIN subrecetas s ON s.id = si.subreceta_id
              WHERE si.ingrediente_id = {$ingredienteId}
              ORDER BY s.nombre ASC"
        );
        if (!$resultado) {
            return [];
        }

        $nombres = [];
        while ($fila = $resultado->fetch_assoc()) {
            $nombres[] = (string) $fila['nombre'];
        }
        $resultado->free();

        return $nombres;
    }

    /** Formatea un float para SQL con punto decimal (independiente del locale). */
    private static function num(float $valor): string
    {
        return number_format($valor, 3, '.', '');
    }
}
