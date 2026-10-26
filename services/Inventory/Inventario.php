<?php

/**
 * Servicio de inventario: descuenta ingredientes al vender un platillo y los
 * repone al cancelarlo. El enlace platillo↔producto es por NOMBRE exacto
 * (ticket_items.nombre = productos.nombre). Si el platillo no tiene producto o
 * receta, no se descuenta nada: el pedido nunca se bloquea por inventario y el
 * stock puede quedar negativo.
 *
 * Las VARIANTES (producto_variantes / variante_ajustes) no cambian ese enlace:
 * el ticket_item conserva el nombre del platillo y trae aparte los ids de las
 * opciones elegidas, que se aplican sobre la receta base al explotarla.
 */

namespace Services\Inventory;

use Model\Ingrediente;

class Inventario
{
    /** Entrada explícita al motor 3C; los consumidores comerciales se adaptan en 3D. */
    public static function porAlmacenes(\mysqli $db, int $actorId, string $authNivel): Almacenes
    {
        return new Almacenes($db, $actorId, $authNivel);
    }
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
     *
     * $varianteIds son las opciones elegidas en el POS: sus ajustes se suman a
     * la receta base antes de descontar (leche → almendra, shot extra…).
     *
     * @param int[] $varianteIds
     */
    public static function aplicarVenta(string $nombre, int $cantidad, ?int $ticketItemId = null, array $varianteIds = []): void
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
            $totales = self::explotarReceta($productoId, $db, $varianteIds);
            if (empty($totales)) {
                return; // Producto sin receta (ni ajustes de variante): nada que descontar.
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
     * Con $varianteIds, el costo de esa combinación de opciones.
     *
     * @param int[] $varianteIds
     */
    public static function costoDeProducto(int $productoId, array $varianteIds = []): float
    {
        try {
            $db = Ingrediente::getDB();
            if (!$db) {
                return 0.0;
            }
            $totales = self::explotarReceta($productoId, $db, $varianteIds);
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
     *
     * Con $varianteIds aplica encima los ajustes de esas opciones, en dos
     * pasadas:
     *   1. agregar / quitar, tal cual.
     *   2. sustituir, que es PROPORCIONAL: reemplaza la fracción
     *      cantidad ÷ (lo que la receta base lleva de ese componente) de lo
     *      que el platillo lleve en ese momento. Así «Almendra» (120 de 120 ml)
     *      cambia TODA la leche aunque «Grande» le haya sumado 60 ml más, y
     *      «Divorciados» (40 de 80 ml de salsa verde) cambia la mitad. Lo que
     *      entra es lo que sale × (nueva_cantidad ÷ cantidad): 25 g de
     *      piloncillo → 2 g de stevia. Si el componente no está en la receta
     *      base, la sustitución es literal.
     *
     * Ninguna cantidad baja de cero: «sin parmesano» en un platillo que no lo
     * lleva no debe REPONER parmesano, y una opción mal capturada en el panel no
     * puede convertir una venta en una entrada de almacén.
     *
     * services/Menu/Variantes y el editor del panel (variantes.js) aplican la
     * misma regla para la vista previa; si cambia aquí, cambia allí.
     *
     * @param int[] $varianteIds
     */
    private static function explotarReceta(int $productoId, $db, array $varianteIds = []): array
    {
        $totales = [];
        // Cantidades a nivel de COMPONENTE ('ingrediente:3', 'subreceta:1'): la
        // receta base y la vigente tras agregar/quitar. Las necesita la
        // sustitución proporcional.
        $base = [];
        $actual = [];

        $comps = $db->query(
            "SELECT tipo, ref_id, cantidad FROM producto_componentes WHERE producto_id = {$productoId}"
        );
        if ($comps) {
            while ($comp = $comps->fetch_assoc()) {
                $clave = $comp['tipo'] . ':' . (int) $comp['ref_id'];
                $cantidad = (float) $comp['cantidad'];
                $base[$clave] = ($base[$clave] ?? 0.0) + $cantidad;
                $actual[$clave] = ($actual[$clave] ?? 0.0) + $cantidad;
                self::explotarComponente($totales, (string) $comp['tipo'], (int) $comp['ref_id'], $cantidad, $db);
            }
        }

        $ids = array_values(array_filter(array_map('intval', $varianteIds), static fn (int $id): bool => $id > 0));
        if ($ids !== []) {
            // Sólo opciones del MISMO producto: un id de otro platillo que
            // llegara por error no puede mover la receta de éste.
            $lista = implode(',', $ids);
            $res = $db->query(
                "SELECT va.accion, va.tipo, va.ref_id, va.cantidad, va.nuevo_tipo, va.nuevo_ref_id, va.nueva_cantidad
                   FROM variante_ajustes va
                   JOIN producto_variantes pv ON pv.id = va.variante_id
                  WHERE va.variante_id IN ({$lista}) AND pv.producto_id = {$productoId}
                  ORDER BY va.id ASC"
            );
            $sustituciones = [];
            if ($res) {
                while ($aj = $res->fetch_assoc()) {
                    if ($aj['accion'] === 'sustituir') {
                        $sustituciones[] = $aj;
                        continue;
                    }
                    $signo = $aj['accion'] === 'quitar' ? -1.0 : 1.0;
                    $cantidad = $signo * (float) $aj['cantidad'];
                    $clave = $aj['tipo'] . ':' . (int) $aj['ref_id'];
                    $actual[$clave] = max(0.0, ($actual[$clave] ?? 0.0) + $cantidad);
                    self::explotarComponente($totales, (string) $aj['tipo'], (int) $aj['ref_id'], $cantidad, $db);
                }
            }

            foreach ($sustituciones as $aj) {
                if ($aj['nuevo_tipo'] === null || (int) $aj['nuevo_ref_id'] <= 0) {
                    continue;
                }
                $cantidad = (float) $aj['cantidad'];
                $nueva = (float) ($aj['nueva_cantidad'] ?? $cantidad);
                if ($cantidad <= 0) {
                    continue;
                }
                $clave = $aj['tipo'] . ':' . (int) $aj['ref_id'];
                $enBase = $base[$clave] ?? 0.0;
                $sale = $enBase > 0
                    ? ($actual[$clave] ?? 0.0) * min(1.0, $cantidad / $enBase)
                    : $cantidad;
                if ($sale <= 0) {
                    continue;
                }
                $entra = $sale * ($nueva / $cantidad);
                $actual[$clave] = max(0.0, ($actual[$clave] ?? 0.0) - $sale);
                $claveNueva = $aj['nuevo_tipo'] . ':' . (int) $aj['nuevo_ref_id'];
                $actual[$claveNueva] = ($actual[$claveNueva] ?? 0.0) + $entra;
                self::explotarComponente($totales, (string) $aj['tipo'], (int) $aj['ref_id'], -$sale, $db);
                self::explotarComponente($totales, (string) $aj['nuevo_tipo'], (int) $aj['nuevo_ref_id'], $entra, $db);
            }
        }

        foreach ($totales as $ingId => $cant) {
            if ($cant <= 0.0005) {
                unset($totales[$ingId]);
            }
        }

        return $totales;
    }

    /**
     * Suma (o resta, con cantidad negativa) un componente a $totales: un
     * ingrediente tal cual, una subreceta escalada por cantidad / rendimiento.
     */
    private static function explotarComponente(array &$totales, string $tipo, int $refId, float $cantidad, $db): void
    {
        if ($refId <= 0 || $cantidad == 0.0) {
            return;
        }

        if ($tipo === 'ingrediente') {
            $totales[$refId] = ($totales[$refId] ?? 0.0) + $cantidad;
            return;
        }
        if ($tipo !== 'subreceta') {
            return;
        }

        $resSub = $db->query("SELECT rendimiento FROM subrecetas WHERE id = {$refId} LIMIT 1");
        if (!$resSub || !($sub = $resSub->fetch_assoc())) {
            return;
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
            return;
        }
        while ($si = $resIng->fetch_assoc()) {
            $ingId = (int) $si['ingrediente_id'];
            $totales[$ingId] = ($totales[$ingId] ?? 0.0) + ((float) $si['cantidad'] * $factor);
        }
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
