<?php

/**
 * Servicio de inventario: descuenta existencias al vender un platillo y las
 * repone al cancelarlo. El enlace platillo↔producto es por NOMBRE exacto
 * (ticket_items.nombre = productos.nombre). Si el platillo no tiene producto o
 * receta, no se descuenta nada: el pedido nunca se bloquea por inventario y el
 * stock puede quedar negativo.
 *
 * Qué existencia mueve la venta depende de la subreceta (subrecetas.por_lote):
 * una POR LOTE (una salsa) se produjo antes —producirSubreceta() ya sacó sus
 * ingredientes del almacén—, así que la venta descuenta la SUBRECETA; una AL
 * MOMENTO (un shot de espresso) se desarma hasta sus ingredientes, como un
 * ingrediente suelto de la receta.
 *
 * El COSTO de un platillo no hace esa distinción: siempre es lo que cuestan sus
 * ingredientes hoy, desarmando todas las subrecetas (costoDeProducto).
 *
 * Las VARIANTES (producto_variantes / variante_ajustes) no cambian ese enlace:
 * el ticket_item conserva el nombre del platillo y trae aparte los ids de las
 * opciones elegidas, que se aplican sobre la receta base al explotarla.
 */

namespace Services\Inventory;

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
     * Tope de una cantidad capturada a mano (producción, ajuste, merma): lo que
     * cabe en DECIMAL(12,3) con margen. No evita un cero de más —eso sólo lo
     * ve quien captura—, evita que la base rechace el número a medio guardar.
     */
    public const MAX_CANTIDAD = 999999.0;

    /**
     * Descuenta el inventario por la venta de $cantidad unidades del platillo
     * $nombre. Registra un movimiento por cada existencia afectada: cada
     * ingrediente, y cada subreceta por lote (ver la cabecera).
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
            $totales = self::explotarReceta($productoId, $db, $varianteIds, true);
            if (empty($totales)) {
                return; // Producto sin receta (ni ajustes de variante): nada que descontar.
            }

            $tidSql = $ticketItemId ? (string) (int) $ticketItemId : 'NULL';

            foreach ($totales as $clave => $porUnidad) {
                $delta = $porUnidad * $cantidad; // cantidad total a descontar
                if ($delta == 0.0) {
                    continue;
                }
                // Negativo = descuento. Se permite que el stock quede negativo.
                self::moverExistencia($db, (string) $clave, -$delta, 'venta', $tidSql);
            }
        } catch (\Throwable $e) {
            error_log('Inventario::aplicarVenta - ' . $e->getMessage());
        }
    }

    /**
     * Mueve una existencia —'ingrediente:3' o 'subreceta:1', las claves de
     * explotarReceta()— y deja su movimiento en la bitácora con la columna que
     * le toca: cada fila mueve una sola de las dos.
     */
    private static function moverExistencia($db, string $clave, float $cantidad, string $tipo, string $tidSql = 'NULL'): void
    {
        [$tipoRef, $id] = self::partirClave($clave);
        if ($id <= 0 || $cantidad == 0.0) {
            return;
        }
        $cantSql = self::num($cantidad);
        [$tabla, $columna] = $tipoRef === 'subreceta'
            ? ['subrecetas', 'subreceta_id']
            : ['ingredientes', 'ingrediente_id'];

        $db->query("UPDATE {$tabla} SET stock = stock + ({$cantSql}) WHERE id = {$id}");
        $db->query(
            "INSERT INTO movimientos_inventario ({$columna}, tipo, cantidad, ticket_item_id)
             VALUES ({$id}, '{$tipo}', {$cantSql}, {$tidSql})"
        );
    }

    /** @return array{0: string, 1: int} ['ingrediente'|'subreceta', id] */
    private static function partirClave(string $clave): array
    {
        $partes = explode(':', $clave, 2);
        $tipo = $partes[0] === 'subreceta' ? 'subreceta' : 'ingrediente';

        return [$tipo, (int) ($partes[1] ?? 0)];
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

            // La subreceta vuelve como subreceta: lo que se repone es lo que se
            // descontó, no la receta de hoy (que pudo cambiar de clase).
            $res = $db->query(
                "SELECT ingrediente_id, subreceta_id, cantidad FROM movimientos_inventario
                 WHERE ticket_item_id = {$tid} AND tipo = 'venta'"
            );
            if (!$res) {
                return;
            }

            $filas = $res->fetch_all(MYSQLI_ASSOC);
            foreach ($filas as $row) {
                $repone = -1 * (float) $row['cantidad']; // el movimiento de venta es negativo
                $clave = $row['subreceta_id'] !== null
                    ? 'subreceta:' . (int) $row['subreceta_id']
                    : 'ingrediente:' . (int) $row['ingrediente_id'];
                self::moverExistencia($db, $clave, $repone, 'cancelacion', (string) $tid);
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
            // Sin $paraExistencias: TODAS las subrecetas se desarman, también
            // las por lote. El costo de un plato es lo que cuestan hoy sus
            // ingredientes, no cómo salieron del almacén.
            $totales = self::explotarReceta($productoId, $db, $varianteIds);
            if (empty($totales)) {
                return 0.0;
            }
            $porIngrediente = [];
            foreach ($totales as $clave => $cantidad) {
                [, $ingId] = self::partirClave((string) $clave);
                $porIngrediente[$ingId] = $cantidad;
            }
            $ids = implode(',', array_keys($porIngrediente));
            $costos = [];
            $res = $db->query("SELECT id, costo FROM ingredientes WHERE id IN ({$ids})");
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $costos[(int) $r['id']] = (float) $r['costo'];
                }
            }
            $total = 0.0;
            foreach ($porIngrediente as $ingId => $cantidad) {
                $total += $cantidad * ($costos[(int) $ingId] ?? 0.0);
            }
            return $total;
        } catch (\Throwable $e) {
            error_log('Inventario::costoDeProducto - ' . $e->getMessage());
            return 0.0;
        }
    }

    /**
     * Explota la receta de un producto: devuelve [clave => cantidad por unidad
     * de producto], con claves 'ingrediente:N' o 'subreceta:N'.
     *
     * Para el COSTO ($paraExistencias = false) todas las subrecetas se
     * desarman hasta ingredientes, escaladas por (cantidad usada ÷
     * rendimiento), y sólo salen claves de ingrediente. Para DESCONTAR
     * ($paraExistencias = true) se desarman sólo las subrecetas al momento: las
     * por lote se quedan como 'subreceta:N', porque sus ingredientes ya
     * salieron del almacén al producirlas.
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
     * @return array<string, float>
     */
    private static function explotarReceta(int $productoId, $db, array $varianteIds = [], bool $paraExistencias = false): array
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
                self::explotarComponente($totales, (string) $comp['tipo'], (int) $comp['ref_id'], $cantidad, $db, $paraExistencias);
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
                    self::explotarComponente($totales, (string) $aj['tipo'], (int) $aj['ref_id'], $cantidad, $db, $paraExistencias);
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
                self::explotarComponente($totales, (string) $aj['tipo'], (int) $aj['ref_id'], -$sale, $db, $paraExistencias);
                self::explotarComponente($totales, (string) $aj['nuevo_tipo'], (int) $aj['nuevo_ref_id'], $entra, $db, $paraExistencias);
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
     * ingrediente tal cual; una subreceta escalada por cantidad / rendimiento,
     * salvo que se esté descontando y sea por lote, que entra entera como
     * subreceta.
     */
    private static function explotarComponente(array &$totales, string $tipo, int $refId, float $cantidad, $db, bool $paraExistencias = false): void
    {
        if ($refId <= 0 || $cantidad == 0.0) {
            return;
        }

        if ($tipo === 'ingrediente') {
            $clave = 'ingrediente:' . $refId;
            $totales[$clave] = ($totales[$clave] ?? 0.0) + $cantidad;
            return;
        }
        if ($tipo !== 'subreceta') {
            return;
        }

        $resSub = $db->query("SELECT rendimiento, por_lote FROM subrecetas WHERE id = {$refId} LIMIT 1");
        if (!$resSub || !($sub = $resSub->fetch_assoc())) {
            return;
        }
        if ($paraExistencias && (int) $sub['por_lote'] === 1) {
            $clave = 'subreceta:' . $refId;
            $totales[$clave] = ($totales[$clave] ?? 0.0) + $cantidad;
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
            $clave = 'ingrediente:' . (int) $si['ingrediente_id'];
            $totales[$clave] = ($totales[$clave] ?? 0.0) + ((float) $si['cantidad'] * $factor);
        }
    }

    // ── Subrecetas por lote ──────────────────────────────────────

    /**
     * Ingredientes de una subreceta con lo que lleva UN lote (su rendimiento),
     * la existencia y el costo de hoy. Un ingrediente repetido en la
     * composición se suma en una sola fila.
     *
     * @return array<int, array{id: int, nombre: string, unidad: string, cantidad: float, stock: float, costo: float}>
     */
    public static function composicionDeSubreceta(int $subrecetaId): array
    {
        $db = Ingrediente::getDB();
        if (!$db || $subrecetaId <= 0) {
            return [];
        }

        $res = $db->query(
            "SELECT i.id, i.nombre, i.unidad, i.stock, i.costo, SUM(si.cantidad) AS cantidad
               FROM subreceta_ingredientes si
               JOIN ingredientes i ON i.id = si.ingrediente_id
              WHERE si.subreceta_id = {$subrecetaId}
              GROUP BY i.id, i.nombre, i.unidad, i.stock, i.costo
              ORDER BY i.nombre ASC"
        );
        if (!$res) {
            return [];
        }

        $filas = [];
        while ($row = $res->fetch_assoc()) {
            $filas[] = [
                'id' => (int) $row['id'],
                'nombre' => (string) $row['nombre'],
                'unidad' => (string) $row['unidad'],
                'cantidad' => (float) $row['cantidad'],
                'stock' => (float) $row['stock'],
                'costo' => (float) $row['costo'],
            ];
        }
        $res->free();

        return $filas;
    }

    /**
     * Lo que cuesta hoy una unidad de la subreceta (un ml, un g): sus
     * ingredientes al costo actual entre el rendimiento. Es el costo TEÓRICO;
     * el de un lote ya producido es el que se congeló al producirlo.
     */
    public static function costoUnitarioSubreceta(int $subrecetaId): float
    {
        $db = Ingrediente::getDB();
        if (!$db || $subrecetaId <= 0) {
            return 0.0;
        }
        $res = $db->query("SELECT rendimiento FROM subrecetas WHERE id = {$subrecetaId} LIMIT 1");
        $rendimiento = $res && ($row = $res->fetch_assoc()) ? (float) $row['rendimiento'] : 0.0;
        if ($rendimiento <= 0) {
            return 0.0;
        }

        $total = 0.0;
        foreach (self::composicionDeSubreceta($subrecetaId) as $ing) {
            $total += $ing['cantidad'] * $ing['costo'];
        }

        return $total / $rendimiento;
    }

    /**
     * Registra un lote producido de una subreceta POR LOTE: descuenta sus
     * ingredientes AHORA —escalados por lo producido ÷ rendimiento—, suma lo
     * producido a la existencia de la subreceta y deja el lote en
     * producciones_subreceta con el costo del día.
     *
     * Igual que en la venta, un ingrediente puede quedar en negativo: la
     * cocina ya cocinó, y lo que dice el almacén es que faltaba registrar una
     * entrada. El resultado nombra los que quedaron así, para avisar.
     *
     * Todo o nada en una transacción. Si quien llama ya apagó el autocommit
     * (una prueba que lo revierte todo al final), la transacción es suya.
     *
     * @return array{ok: bool, mensaje: string, produccion_id: int, negativos: string[]}
     */
    public static function producirSubreceta(int $subrecetaId, float $cantidad, string $nota, ?int $usuarioId): array
    {
        $falla = static fn (string $mensaje): array => ['ok' => false, 'mensaje' => $mensaje, 'produccion_id' => 0, 'negativos' => []];

        if (!is_finite($cantidad) || $cantidad <= 0) {
            return $falla('La cantidad producida debe ser mayor a cero.');
        }
        if ($cantidad > self::MAX_CANTIDAD) {
            return $falla('La cantidad producida es demasiado grande. Revisa que no sobre un cero.');
        }

        $db = Ingrediente::getDB();
        if (!$db) {
            return $falla('No hay conexión con la base de datos.');
        }

        $sub = self::subreceta($db, $subrecetaId);
        if ($sub === null) {
            return $falla('La subreceta no existe.');
        }
        $nombre = (string) $sub['nombre'];
        if ((int) $sub['por_lote'] !== 1) {
            return $falla('«' . $nombre . '» se prepara al momento: la venta descuenta sus ingredientes y no se produce por adelantado.');
        }
        if ((int) $sub['activo'] !== 1) {
            return $falla('«' . $nombre . '» está inactiva. Actívala para producirla.');
        }

        $composicion = self::composicionDeSubreceta($subrecetaId);
        if ($composicion === []) {
            return $falla('«' . $nombre . '» no tiene ingredientes. Define su composición antes de producirla.');
        }

        $rendimiento = (float) $sub['rendimiento'] > 0 ? (float) $sub['rendimiento'] : 1.0;
        $factor = $cantidad / $rendimiento;
        $usuarioSql = $usuarioId ? (string) (int) $usuarioId : 'NULL';
        $notaLimpia = mb_substr(trim($nota), 0, 255);
        $notaParam = $notaLimpia === '' ? null : $notaLimpia;

        $propia = self::autocommitEncendido($db);
        if ($propia) {
            $db->begin_transaction();
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO producciones_subreceta (subreceta_id, cantidad, costo_total, nota, usuario_id)
                 VALUES (?, ?, 0, ?, ?)'
            );
            if (!$stmt) {
                throw new \RuntimeException('No se pudo preparar el lote.');
            }
            $stmt->bind_param('idsi', $subrecetaId, $cantidad, $notaParam, $usuarioId);
            $stmt->execute();
            $produccionId = (int) $stmt->insert_id;
            $stmt->close();

            $costoTotal = 0.0;
            $negativos = [];
            foreach ($composicion as $ing) {
                $sale = round($ing['cantidad'] * $factor, 3);
                if ($sale <= 0) {
                    continue;
                }
                $costoTotal += $sale * $ing['costo'];
                $saleSql = self::num($sale);
                $costoSql = number_format($ing['costo'], 4, '.', '');
                $db->query("UPDATE ingredientes SET stock = stock - ({$saleSql}) WHERE id = {$ing['id']}");
                $db->query(
                    "INSERT INTO movimientos_inventario (ingrediente_id, tipo, cantidad, costo_unitario, usuario_id, produccion_id)
                     VALUES ({$ing['id']}, 'produccion', -{$saleSql}, {$costoSql}, {$usuarioSql}, {$produccionId})"
                );
                if ($ing['stock'] - $sale < 0) {
                    $negativos[] = $ing['nombre'];
                }
            }

            $cantidadSql = self::num($cantidad);
            $costoUnitarioSql = number_format($costoTotal / $cantidad, 4, '.', '');
            $db->query("UPDATE subrecetas SET stock = stock + ({$cantidadSql}) WHERE id = {$subrecetaId}");
            $db->query(
                "INSERT INTO movimientos_inventario (subreceta_id, tipo, cantidad, costo_unitario, usuario_id, produccion_id)
                 VALUES ({$subrecetaId}, 'produccion', {$cantidadSql}, {$costoUnitarioSql}, {$usuarioSql}, {$produccionId})"
            );
            $costoTotalSql = number_format($costoTotal, 4, '.', '');
            $db->query("UPDATE producciones_subreceta SET costo_total = {$costoTotalSql} WHERE id = {$produccionId}");

            if ($propia) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($propia) {
                $db->rollback();
            }
            error_log('Inventario::producirSubreceta - ' . $e->getMessage());
            return $falla('No se pudo registrar la producción.');
        }

        $mensaje = 'Producción registrada: ' . self::cantidadLegible($cantidad) . ' ' . $sub['unidad'] . ' de ' . $nombre . '.';
        if ($negativos !== []) {
            $mensaje .= ' Quedaron en negativo: ' . self::enumerar($negativos) . '. Falta registrar su entrada en Inventario.';
        }

        return ['ok' => true, 'mensaje' => $mensaje, 'produccion_id' => $produccionId, 'negativos' => $negativos];
    }

    /**
     * Corrige la existencia de una subreceta por lote tras contarla: fija el
     * valor y deja la diferencia como `ajuste`, como el ajuste de un
     * ingrediente.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public static function ajustarSubreceta(int $subrecetaId, float $nuevoStock, ?int $usuarioId): array
    {
        if (!is_finite($nuevoStock) || abs($nuevoStock) > self::MAX_CANTIDAD) {
            return ['ok' => false, 'mensaje' => 'La existencia contada no es un número válido.'];
        }

        $db = Ingrediente::getDB();
        $sub = $db ? self::subreceta($db, $subrecetaId) : null;
        if ($sub === null) {
            return ['ok' => false, 'mensaje' => 'La subreceta no existe.'];
        }
        if ((int) $sub['por_lote'] !== 1) {
            return ['ok' => false, 'mensaje' => '«' . $sub['nombre'] . '» se prepara al momento: no lleva existencia.'];
        }

        $delta = round($nuevoStock - (float) $sub['stock'], 3);
        if (abs($delta) < 0.0005) {
            return ['ok' => true, 'mensaje' => 'Sin cambios: ya había ' . self::cantidadLegible($nuevoStock) . ' ' . $sub['unidad'] . ' de ' . $sub['nombre'] . '.'];
        }

        try {
            $nuevoSql = self::num($nuevoStock);
            $deltaSql = self::num($delta);
            $usuarioSql = $usuarioId ? (string) (int) $usuarioId : 'NULL';
            $db->query("UPDATE subrecetas SET stock = {$nuevoSql} WHERE id = {$subrecetaId}");
            $db->query(
                "INSERT INTO movimientos_inventario (subreceta_id, tipo, cantidad, usuario_id)
                 VALUES ({$subrecetaId}, 'ajuste', {$deltaSql}, {$usuarioSql})"
            );
        } catch (\Throwable $e) {
            error_log('Inventario::ajustarSubreceta - ' . $e->getMessage());
            return ['ok' => false, 'mensaje' => 'No se pudo corregir la existencia.'];
        }

        return ['ok' => true, 'mensaje' => 'Existencia de ' . $sub['nombre'] . ': ' . self::cantidadLegible($nuevoStock) . ' ' . $sub['unidad'] . '.'];
    }

    /**
     * Merma de una subreceta por lote (la salsa que se echó a perder). Se
     * valoriza con el costo del ÚLTIMO lote producido, que es lo que había en
     * la cámara; sin lotes, con el costo teórico de hoy.
     *
     * @return array{ok: bool, mensaje: string, valor: float}
     */
    public static function registrarMermaSubreceta(int $subrecetaId, float $cantidad, string $motivo, string $nota, ?int $usuarioId): array
    {
        if (!is_finite($cantidad) || $cantidad <= 0 || $cantidad > self::MAX_CANTIDAD) {
            return ['ok' => false, 'mensaje' => 'La cantidad mermada debe ser un número mayor a cero.', 'valor' => 0.0];
        }
        if (!self::motivoMermaValido($motivo)) {
            return ['ok' => false, 'mensaje' => 'Elige un motivo de la lista.', 'valor' => 0.0];
        }

        $db = Ingrediente::getDB();
        $sub = $db ? self::subreceta($db, $subrecetaId) : null;
        if ($sub === null) {
            return ['ok' => false, 'mensaje' => 'La subreceta no existe.', 'valor' => 0.0];
        }
        if ((int) $sub['por_lote'] !== 1) {
            return ['ok' => false, 'mensaje' => '«' . $sub['nombre'] . '» se prepara al momento: su merma se registra por ingrediente en Inventario.', 'valor' => 0.0];
        }

        $res = $db->query(
            "SELECT costo_total / cantidad FROM producciones_subreceta
              WHERE subreceta_id = {$subrecetaId} AND cantidad > 0
              ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $ultimo = $res ? $res->fetch_row() : null;
        $costo = $ultimo !== null && $ultimo !== false ? (float) $ultimo[0] : self::costoUnitarioSubreceta($subrecetaId);

        try {
            $cantidadSql = self::num($cantidad);
            $db->query("UPDATE subrecetas SET stock = stock - ({$cantidadSql}) WHERE id = {$subrecetaId}");

            // Preparada porque motivo y nota vienen del usuario.
            $stmt = $db->prepare(
                'INSERT INTO movimientos_inventario
                    (subreceta_id, tipo, cantidad, motivo, nota, costo_unitario, usuario_id)
                 VALUES (?, \'merma\', ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return ['ok' => false, 'mensaje' => 'No se pudo registrar la merma.', 'valor' => 0.0];
            }
            $negativa = -$cantidad;
            $notaLimpia = mb_substr(trim($nota), 0, 255);
            $notaParam = $notaLimpia === '' ? null : $notaLimpia;
            $stmt->bind_param('idssdi', $subrecetaId, $negativa, $motivo, $notaParam, $costo, $usuarioId);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $e) {
            error_log('Inventario::registrarMermaSubreceta - ' . $e->getMessage());
            return ['ok' => false, 'mensaje' => 'No se pudo registrar la merma.', 'valor' => 0.0];
        }

        return [
            'ok' => true,
            'mensaje' => 'Merma registrada: ' . self::cantidadLegible($cantidad) . ' ' . $sub['unidad'] . ' de ' . $sub['nombre'] . '.',
            'valor' => $cantidad * $costo,
        ];
    }

    /**
     * Últimos lotes producidos de una subreceta, del más reciente al más
     * antiguo. LEFT JOIN a usuarios por la FK ON DELETE SET NULL: dar de baja
     * a quien produjo no borra el lote.
     *
     * @return array<int, array{fecha: string, cantidad: float, costo: float, nota: string, usuario: string}>
     */
    public static function lotesDeSubreceta(int $subrecetaId, int $limite = 8): array
    {
        $db = Ingrediente::getDB();
        if (!$db || $subrecetaId <= 0) {
            return [];
        }
        $limite = max(1, min(50, $limite));

        $res = $db->query(
            "SELECT ps.created_at, ps.cantidad, ps.costo_total, ps.nota, u.nombre AS usuario
               FROM producciones_subreceta ps
               LEFT JOIN usuarios u ON u.id = ps.usuario_id
              WHERE ps.subreceta_id = {$subrecetaId}
              ORDER BY ps.created_at DESC, ps.id DESC
              LIMIT {$limite}"
        );
        if (!$res) {
            return [];
        }

        $lotes = [];
        while ($row = $res->fetch_assoc()) {
            $lotes[] = [
                'fecha' => (string) $row['created_at'],
                'cantidad' => (float) $row['cantidad'],
                'costo' => (float) $row['costo_total'],
                'nota' => (string) ($row['nota'] ?? ''),
                'usuario' => (string) ($row['usuario'] ?? ''),
            ];
        }
        $res->free();

        return $lotes;
    }

    /** @return array<string, mixed>|null */
    private static function subreceta($db, int $subrecetaId): ?array
    {
        if ($subrecetaId <= 0) {
            return null;
        }
        $res = $db->query(
            "SELECT id, nombre, unidad, rendimiento, por_lote, stock, activo FROM subrecetas WHERE id = {$subrecetaId} LIMIT 1"
        );
        $row = $res ? $res->fetch_assoc() : null;

        return $row ?: null;
    }

    /**
     * true si cada sentencia se confirma sola. Con el autocommit apagado quien
     * llama ya gobierna la transacción, y abrir otra la confirmaría.
     */
    private static function autocommitEncendido($db): bool
    {
        $res = $db->query('SELECT @@autocommit');
        $row = $res ? $res->fetch_row() : null;

        return !$row || (int) $row[0] === 1;
    }

    /** 1000 → «1000»; 2.5 → «2.5»: sin ceros de relleno en los mensajes. */
    private static function cantidadLegible(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 3, '.', ''), '0'), '.');
    }

    /** ['a', 'b', 'c'] → «a, b y c». */
    private static function enumerar(array $nombres): string
    {
        if (count($nombres) <= 1) {
            return (string) ($nombres[0] ?? '');
        }
        $ultimo = array_pop($nombres);

        return implode(', ', $nombres) . ' y ' . $ultimo;
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
