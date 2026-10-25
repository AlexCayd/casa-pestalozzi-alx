<?php

/**
 * Variantes por platillo: grupos de opción de elección única que mueven el
 * precio y ajustan la receta (producto_variantes + variante_ajustes).
 *
 * Tres consumidores, tres formas de los mismos datos:
 *   · el panel (deProducto / guardar): todo, con ajustes y opciones apagadas;
 *   · el POS (paraPos): sólo lo activo, comprimido como el resto de CP_MENU;
 *   · el servidor del POS (resolver): valida lo que manda la tablet y decide
 *     precio y rótulo — el cliente nunca dice cuánto cuesta una opción.
 *
 * El ticket_item guarda el NOMBRE del platillo sin tocar y la variante aparte
 * (variante_ids + variante): así siguen valiendo todas las uniones por nombre
 * del COGS, las analíticas y las sugerencias.
 */

namespace Services\Menu;

use Model\Producto;

final class VariantesLegado
{
    public const MAX_GRUPOS = 6;
    public const MAX_OPCIONES = 12;
    public const SEPARADOR_ROTULO = ' · ';
    public const ACCIONES = ['agregar', 'quitar', 'sustituir'];
    public const TIPOS = ['ingrediente', 'subreceta'];

    /**
     * Arranques rápidos del editor. Sólo nombres y precios orientativos: los
     * ajustes de receta dependen de cada platillo y se capturan a mano.
     * Un grupo sin opción predeterminada es de elección obligatoria.
     */
    public const PLANTILLAS = [
        'Leche' => [
            ['nombre' => 'Entera', 'precio_delta' => 0, 'predeterminada' => true],
            ['nombre' => 'Deslactosada', 'precio_delta' => 0, 'predeterminada' => false],
            ['nombre' => 'Almendra', 'precio_delta' => 15, 'predeterminada' => false],
            ['nombre' => 'Avena', 'precio_delta' => 15, 'predeterminada' => false],
        ],
        'Tamaño' => [
            ['nombre' => 'Regular', 'precio_delta' => 0, 'predeterminada' => true],
            ['nombre' => 'Grande', 'precio_delta' => 15, 'predeterminada' => false],
        ],
        'Término' => [
            ['nombre' => 'Rojo', 'precio_delta' => 0, 'predeterminada' => false],
            ['nombre' => 'Medio', 'precio_delta' => 0, 'predeterminada' => false],
            ['nombre' => 'Tres cuartos', 'precio_delta' => 0, 'predeterminada' => false],
            ['nombre' => 'Bien cocido', 'precio_delta' => 0, 'predeterminada' => false],
        ],
        'Extra' => [
            ['nombre' => 'Sin extra', 'precio_delta' => 0, 'predeterminada' => true],
            ['nombre' => 'Porción extra', 'precio_delta' => 35, 'predeterminada' => false],
        ],
        'Sabor' => [
            ['nombre' => 'Clásico', 'precio_delta' => 0, 'predeterminada' => true],
            ['nombre' => 'Frutos rojos', 'precio_delta' => 0, 'predeterminada' => false],
        ],
    ];

    /**
     * Grupos de un producto para el panel, con opciones apagadas y ajustes.
     *
     * @return list<array{nombre: string, opciones: list<array>}>
     */
    public static function deProducto(int $productoId): array
    {
        $db = Producto::getDB();
        $productoId = max(0, $productoId);
        $res = $db->query(
            "SELECT id, grupo, nombre, precio_delta, predeterminada, activo
               FROM producto_variantes
              WHERE producto_id = {$productoId}
              ORDER BY orden_grupo ASC, orden ASC, id ASC"
        );
        if (!$res) {
            return [];
        }

        $grupos = [];
        $ids = [];
        while ($row = $res->fetch_assoc()) {
            $grupo = (string) $row['grupo'];
            if (!isset($grupos[$grupo])) {
                $grupos[$grupo] = ['nombre' => $grupo, 'opciones' => []];
            }
            $id = (int) $row['id'];
            $ids[] = $id;
            $grupos[$grupo]['opciones'][$id] = [
                'id' => $id,
                'nombre' => (string) $row['nombre'],
                'precio_delta' => (float) $row['precio_delta'],
                'predeterminada' => (int) $row['predeterminada'] === 1,
                'activo' => (int) $row['activo'] === 1,
                'ajustes' => [],
            ];
        }
        $res->free();

        if ($ids !== []) {
            $lista = implode(',', $ids);
            $aj = $db->query(
                "SELECT va.variante_id, va.accion, va.tipo, va.ref_id, va.cantidad,
                        va.nuevo_tipo, va.nuevo_ref_id, va.nueva_cantidad, pv.grupo
                   FROM variante_ajustes va
                   JOIN producto_variantes pv ON pv.id = va.variante_id
                  WHERE va.variante_id IN ({$lista})
                  ORDER BY va.id ASC"
            );
            if ($aj) {
                while ($row = $aj->fetch_assoc()) {
                    $vid = (int) $row['variante_id'];
                    $grupo = (string) $row['grupo'];
                    $grupos[$grupo]['opciones'][$vid]['ajustes'][] = [
                        'accion' => (string) $row['accion'],
                        'tipo' => (string) $row['tipo'],
                        'ref_id' => (int) $row['ref_id'],
                        'cantidad' => (float) $row['cantidad'],
                        'nuevo_tipo' => $row['nuevo_tipo'] !== null ? (string) $row['nuevo_tipo'] : null,
                        'nuevo_ref_id' => $row['nuevo_ref_id'] !== null ? (int) $row['nuevo_ref_id'] : null,
                        'nueva_cantidad' => $row['nueva_cantidad'] !== null ? (float) $row['nueva_cantidad'] : null,
                    ];
                }
                $aj->free();
            }
        }

        return array_values(array_map(static function (array $g): array {
            $g['opciones'] = array_values($g['opciones']);
            return $g;
        }, $grupos));
    }

    /**
     * Todo lo que necesita el editor del panel para trabajar sin ir al
     * servidor en cada tecla: la receta base del platillo, el catálogo de
     * ingredientes y subrecetas con su costo (para la vista previa de receta
     * resultante y margen) y los grupos guardados.
     *
     * El editor repite en JS la explosión de Inventario::explotarReceta(),
     * sustitución proporcional incluida; por eso viajan las composiciones de
     * las subrecetas y no sólo sus nombres.
     */
    public static function datosEditor(int $productoId): array
    {
        $db = Producto::getDB();
        $productoId = max(0, $productoId);

        $base = [];
        $res = $db->query(
            "SELECT tipo, ref_id, cantidad FROM producto_componentes
              WHERE producto_id = {$productoId} ORDER BY id ASC"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $base[] = ['tipo' => (string) $row['tipo'], 'ref_id' => (int) $row['ref_id'], 'cantidad' => (float) $row['cantidad']];
            }
            $res->free();
        }

        $ingredientes = [];
        $res = $db->query('SELECT id, nombre, unidad, costo, activo FROM ingredientes ORDER BY nombre ASC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $ingredientes[] = [
                    'id' => (int) $row['id'],
                    'nombre' => (string) $row['nombre'],
                    'unidad' => (string) $row['unidad'],
                    'costo' => (float) $row['costo'],
                    'activo' => (int) $row['activo'] === 1,
                ];
            }
            $res->free();
        }

        $subrecetas = [];
        $res = $db->query('SELECT id, nombre, unidad, rendimiento FROM subrecetas WHERE activo = 1 ORDER BY nombre ASC');
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $subrecetas[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'nombre' => (string) $row['nombre'],
                    'unidad' => (string) $row['unidad'],
                    'rendimiento' => (float) $row['rendimiento'],
                    'ingredientes' => [],
                ];
            }
            $res->free();
        }
        if ($subrecetas !== []) {
            $res = $db->query(
                'SELECT subreceta_id, ingrediente_id, cantidad FROM subreceta_ingredientes WHERE subreceta_id IN ('
                . implode(',', array_keys($subrecetas)) . ')'
            );
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $subrecetas[(int) $row['subreceta_id']]['ingredientes'][] = [
                        'id' => (int) $row['ingrediente_id'],
                        'cantidad' => (float) $row['cantidad'],
                    ];
                }
                $res->free();
            }
        }

        return [
            'producto_id' => $productoId,
            'base' => $base,
            'ingredientes' => $ingredientes,
            'subrecetas' => array_values($subrecetas),
            'grupos' => self::deProducto($productoId),
            'plantillas' => self::PLANTILLAS,
            'limites' => ['grupos' => self::MAX_GRUPOS, 'opciones' => self::MAX_OPCIONES],
        ];
    }

    /**
     * Variantes activas de todo el catálogo, indexadas por producto, en la
     * forma comprimida de CP_MENU: [{n, req, o: [{id, n, d, def}]}].
     *
     * `req` sale de la regla de la casa: un grupo sin predeterminada obliga a
     * elegir. Un grupo con menos de dos opciones activas no se envía —con una
     * sola no hay nada que elegir y el POS abriría un selector inútil.
     *
     * @return array<int, list<array>>
     */
    public static function paraPos(): array
    {
        $db = Producto::getDB();
        $res = $db->query(
            "SELECT pv.id, pv.producto_id, pv.grupo, pv.nombre, pv.precio_delta, pv.predeterminada
               FROM producto_variantes pv
               JOIN productos p ON p.id = pv.producto_id
              WHERE pv.activo = 1 AND p.activo = 1
              ORDER BY pv.producto_id ASC, pv.orden_grupo ASC, pv.orden ASC, pv.id ASC"
        );
        if (!$res) {
            return [];
        }

        $porProducto = [];
        while ($row = $res->fetch_assoc()) {
            $pid = (int) $row['producto_id'];
            $grupo = (string) $row['grupo'];
            $porProducto[$pid][$grupo]['n'] = $grupo;
            $porProducto[$pid][$grupo]['o'][] = [
                'id' => (int) $row['id'],
                'n' => (string) $row['nombre'],
                'd' => (float) $row['precio_delta'],
                'def' => (int) $row['predeterminada'] === 1,
            ];
        }
        $res->free();

        $salida = [];
        foreach ($porProducto as $pid => $grupos) {
            foreach ($grupos as $grupo) {
                if (count($grupo['o']) < 2) {
                    continue;
                }
                $tieneDefecto = false;
                foreach ($grupo['o'] as $op) {
                    $tieneDefecto = $tieneDefecto || $op['def'];
                }
                $grupo['req'] = !$tieneDefecto;
                $salida[$pid][] = $grupo;
            }
        }

        return $salida;
    }

    /**
     * Opciones por producto (activas o no), para el contador del listado del
     * panel.
     *
     * @return array<int, int>
     */
    public static function conteoPorProducto(): array
    {
        $db = Producto::getDB();
        $res = $db->query('SELECT producto_id, COUNT(*) AS n FROM producto_variantes GROUP BY producto_id');
        if (!$res) {
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(int) $row['producto_id']] = (int) $row['n'];
        }
        $res->free();

        return $out;
    }

    /**
     * Valida la selección que manda el POS para un producto y decide cuánto
     * suma y cómo se rotula.
     *
     * - Una opción por grupo; un id que no sea de este producto o esté
     *   apagado es un error, no se ignora: la tablet tiene un catálogo viejo.
     * - Un grupo sin elección toma su predeterminada; si no la tiene, falta.
     * - El rótulo omite las predeterminadas: «Cappuccino» a secas ES la
     *   receta base, y la comanda sólo debe gritar lo que cambia.
     * - `ids` incluye las predeterminadas: el desglose de ventas por opción
     *   («7 con leche entera, 3 con almendra») las necesita.
     *
     * @param int[] $ids
     * @return array{ok: bool, ids: list<int>, etiqueta: ?string, delta: float, error: ?string}
     */
    public static function resolver(int $productoId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        $db = Producto::getDB();
        $productoId = max(0, $productoId);
        $res = $db->query(
            "SELECT id, grupo, nombre, precio_delta, predeterminada
               FROM producto_variantes
              WHERE producto_id = {$productoId} AND activo = 1
              ORDER BY orden_grupo ASC, orden ASC, id ASC"
        );
        $grupos = [];
        $porId = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $op = [
                    'id' => (int) $row['id'],
                    'grupo' => (string) $row['grupo'],
                    'nombre' => (string) $row['nombre'],
                    'delta' => (float) $row['precio_delta'],
                    'def' => (int) $row['predeterminada'] === 1,
                ];
                $grupos[$op['grupo']][] = $op;
                $porId[$op['id']] = $op;
            }
            $res->free();
        }

        $fallo = static fn (string $error): array => ['ok' => false, 'ids' => [], 'etiqueta' => null, 'delta' => 0.0, 'error' => $error];

        foreach ($ids as $id) {
            if (!isset($porId[$id])) {
                return $fallo('Una de las opciones elegidas ya no está disponible. Actualiza la pantalla y vuelve a elegir.');
            }
        }

        $elegidas = [];
        foreach ($grupos as $nombreGrupo => $opciones) {
            if (count($opciones) < 2) {
                // Igual que en paraPos(): un grupo de una opción no se ofrece.
                continue;
            }
            $enGrupo = array_values(array_filter($opciones, static fn (array $op): bool => in_array($op['id'], $ids, true)));
            if (count($enGrupo) > 1) {
                return $fallo("Elige una sola opción de «{$nombreGrupo}».");
            }
            if ($enGrupo === []) {
                $defecto = array_values(array_filter($opciones, static fn (array $op): bool => $op['def']));
                if ($defecto === []) {
                    return $fallo("Falta elegir «{$nombreGrupo}».");
                }
                $enGrupo = [$defecto[0]];
            }
            $elegidas[] = $enGrupo[0];
        }

        $rotulo = [];
        $delta = 0.0;
        $finales = [];
        foreach ($elegidas as $op) {
            $delta += $op['delta'];
            $finales[] = $op['id'];
            if (!$op['def']) {
                $rotulo[] = $op['nombre'];
            }
        }
        sort($finales);

        return [
            'ok' => true,
            'ids' => $finales,
            'etiqueta' => $rotulo !== [] ? mb_substr(implode(self::SEPARADOR_ROTULO, $rotulo), 0, 160) : null,
            'delta' => round($delta, 2),
            'error' => null,
        ];
    }

    /**
     * Reemplaza los grupos de un producto por los que manda el panel.
     *
     * Los ids que llegan y ya son de este producto se ACTUALIZAN en vez de
     * borrarse y volver a crearse: una tablet que cargó el catálogo antes del
     * guardado lleva esos ids en su carrito, y con ids nuevos el siguiente
     * envío de comanda fallaría a mitad del servicio.
     *
     * @return array{ok: bool, errores: list<string>, grupos: list<array>}
     */
    public static function guardar(int $productoId, array $grupos): array
    {
        $validacion = self::validar($grupos);
        if ($validacion['errores'] !== []) {
            return ['ok' => false, 'errores' => $validacion['errores'], 'grupos' => []];
        }
        $limpios = $validacion['grupos'];

        $db = Producto::getDB();
        $productoId = max(0, $productoId);

        $existentes = [];
        $res = $db->query("SELECT id FROM producto_variantes WHERE producto_id = {$productoId}");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $existentes[(int) $row['id']] = true;
            }
            $res->free();
        }

        $db->begin_transaction();
        try {
            // Nombres temporales para que renombrar «A→B» y «B→A» en el mismo
            // guardado no tropiece con uq_variante a mitad del recorrido.
            if ($existentes !== []) {
                $db->query(
                    "UPDATE producto_variantes SET grupo = CONCAT('~', id) WHERE producto_id = {$productoId}"
                );
            }

            $conservados = [];
            $upd = $db->prepare(
                'UPDATE producto_variantes
                    SET grupo = ?, nombre = ?, precio_delta = ?, predeterminada = ?, activo = ?, orden_grupo = ?, orden = ?
                  WHERE id = ? AND producto_id = ?'
            );
            $ins = $db->prepare(
                'INSERT INTO producto_variantes (producto_id, grupo, nombre, precio_delta, predeterminada, activo, orden_grupo, orden)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insAj = $db->prepare(
                'INSERT INTO variante_ajustes (variante_id, accion, tipo, ref_id, cantidad, nuevo_tipo, nuevo_ref_id, nueva_cantidad)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$upd || !$ins || !$insAj) {
                throw new \RuntimeException('No fue posible preparar el guardado de variantes.');
            }

            foreach ($limpios as $ordenGrupo => $grupo) {
                foreach ($grupo['opciones'] as $orden => $op) {
                    $id = (int) ($op['id'] ?? 0);
                    $nombreGrupo = $grupo['nombre'];
                    $nombre = $op['nombre'];
                    $delta = $op['precio_delta'];
                    $def = $op['predeterminada'] ? 1 : 0;
                    $activo = $op['activo'] ? 1 : 0;
                    if ($id > 0 && isset($existentes[$id])) {
                        $upd->bind_param('ssdiiiiii', $nombreGrupo, $nombre, $delta, $def, $activo, $ordenGrupo, $orden, $id, $productoId);
                        if (!$upd->execute()) {
                            throw new \RuntimeException($upd->error);
                        }
                    } else {
                        $ins->bind_param('issdiiii', $productoId, $nombreGrupo, $nombre, $delta, $def, $activo, $ordenGrupo, $orden);
                        if (!$ins->execute()) {
                            throw new \RuntimeException($ins->error);
                        }
                        $id = (int) $db->insert_id;
                    }
                    $conservados[$id] = true;

                    $db->query("DELETE FROM variante_ajustes WHERE variante_id = {$id}");
                    foreach ($op['ajustes'] as $aj) {
                        $accion = $aj['accion'];
                        $tipo = $aj['tipo'];
                        $ref = $aj['ref_id'];
                        $cant = $aj['cantidad'];
                        $nTipo = $aj['nuevo_tipo'];
                        $nRef = $aj['nuevo_ref_id'];
                        $nCant = $aj['nueva_cantidad'];
                        $insAj->bind_param('issidsid', $id, $accion, $tipo, $ref, $cant, $nTipo, $nRef, $nCant);
                        if (!$insAj->execute()) {
                            throw new \RuntimeException($insAj->error);
                        }
                    }
                }
            }

            $sobrantes = array_diff(array_keys($existentes), array_keys($conservados));
            if ($sobrantes !== []) {
                $db->query(
                    'DELETE FROM producto_variantes WHERE producto_id = ' . $productoId
                    . ' AND id IN (' . implode(',', array_map('intval', $sobrantes)) . ')'
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollback();
            error_log('Variantes::guardar - ' . $e->getMessage());
            return ['ok' => false, 'errores' => ['No fue posible guardar las variantes. Intenta de nuevo.'], 'grupos' => []];
        }

        return ['ok' => true, 'errores' => [], 'grupos' => self::deProducto($productoId)];
    }

    /**
     * @return array{errores: list<string>, grupos: list<array>}
     */
    private static function validar(array $grupos): array
    {
        $errores = [];
        $limpios = [];
        $vistosGrupo = [];

        if (count($grupos) > self::MAX_GRUPOS) {
            $errores[] = 'Un platillo admite hasta ' . self::MAX_GRUPOS . ' grupos de opciones.';
        }

        foreach (array_values($grupos) as $g => $grupo) {
            if (!is_array($grupo)) {
                continue;
            }
            $nombreGrupo = self::texto($grupo['nombre'] ?? '', 40);
            $etiquetaGrupo = $nombreGrupo !== '' ? "«{$nombreGrupo}»" : 'el grupo ' . ($g + 1);
            if ($nombreGrupo === '') {
                $errores[] = 'Cada grupo necesita un nombre (por ejemplo «Leche» o «Término»).';
            }
            $claveGrupo = mb_strtolower($nombreGrupo);
            if ($nombreGrupo !== '' && isset($vistosGrupo[$claveGrupo])) {
                $errores[] = "Hay dos grupos llamados {$etiquetaGrupo}.";
            }
            $vistosGrupo[$claveGrupo] = true;

            $opciones = is_array($grupo['opciones'] ?? null) ? array_values($grupo['opciones']) : [];
            if (count($opciones) < 2) {
                $errores[] = "En {$etiquetaGrupo} agrega al menos dos opciones: con una sola no hay nada que elegir.";
            }
            if (count($opciones) > self::MAX_OPCIONES) {
                $errores[] = "{$etiquetaGrupo} admite hasta " . self::MAX_OPCIONES . ' opciones.';
            }

            $vistosOpcion = [];
            $predeterminadas = 0;
            $limpiasOpciones = [];
            foreach ($opciones as $op) {
                if (!is_array($op)) {
                    continue;
                }
                $nombre = self::texto($op['nombre'] ?? '', 60);
                if ($nombre === '') {
                    $errores[] = "Hay una opción sin nombre en {$etiquetaGrupo}.";
                    continue;
                }
                $clave = mb_strtolower($nombre);
                if (isset($vistosOpcion[$clave])) {
                    $errores[] = "«{$nombre}» aparece dos veces en {$etiquetaGrupo}.";
                }
                $vistosOpcion[$clave] = true;

                $delta = is_numeric($op['precio_delta'] ?? 0) ? round((float) $op['precio_delta'], 2) : null;
                if ($delta === null || $delta < -9999 || $delta > 9999) {
                    $errores[] = "El cambio de precio de «{$nombre}» no es válido.";
                    $delta = 0.0;
                }
                $def = !empty($op['predeterminada']);
                $activo = !array_key_exists('activo', $op) || !empty($op['activo']);
                if ($def) {
                    $predeterminadas++;
                    if (!$activo) {
                        $errores[] = "«{$nombre}» es la opción predeterminada de {$etiquetaGrupo} y no puede estar apagada.";
                    }
                }

                $ajustes = [];
                foreach (is_array($op['ajustes'] ?? null) ? $op['ajustes'] : [] as $aj) {
                    $limpio = self::validarAjuste(is_array($aj) ? $aj : [], $nombre, $errores);
                    if ($limpio !== null) {
                        $ajustes[] = $limpio;
                    }
                }

                $limpiasOpciones[] = [
                    'id' => (int) ($op['id'] ?? 0),
                    'nombre' => $nombre,
                    'precio_delta' => $delta,
                    'predeterminada' => $def,
                    'activo' => $activo,
                    'ajustes' => $ajustes,
                ];
            }
            if ($predeterminadas > 1) {
                $errores[] = "{$etiquetaGrupo} tiene más de una opción predeterminada; deja sólo una (o ninguna, si el mesero siempre debe elegir).";
            }

            $limpios[] = ['nombre' => $nombreGrupo, 'opciones' => $limpiasOpciones];
        }

        return ['errores' => array_values(array_unique($errores)), 'grupos' => $limpios];
    }

    private static function validarAjuste(array $aj, string $opcion, array &$errores): ?array
    {
        $accion = (string) ($aj['accion'] ?? '');
        $tipo = (string) ($aj['tipo'] ?? '');
        $ref = (int) ($aj['ref_id'] ?? 0);
        $cantidad = is_numeric($aj['cantidad'] ?? null) ? round((float) $aj['cantidad'], 3) : 0.0;

        if (!in_array($accion, self::ACCIONES, true) || !in_array($tipo, self::TIPOS, true) || $ref <= 0) {
            $errores[] = "Un ajuste de receta de «{$opcion}» está incompleto: elige qué hacer y con qué ingrediente.";
            return null;
        }
        if ($cantidad <= 0 || $cantidad > 99999) {
            $errores[] = "Un ajuste de «{$opcion}» necesita una cantidad mayor que cero.";
            return null;
        }
        if (!self::componenteExiste($tipo, $ref)) {
            $errores[] = "Un ajuste de «{$opcion}» apunta a un ingrediente que ya no existe.";
            return null;
        }

        $nuevoTipo = null;
        $nuevoRef = null;
        $nuevaCantidad = null;
        if ($accion === 'sustituir') {
            $nuevoTipo = (string) ($aj['nuevo_tipo'] ?? '');
            $nuevoRef = (int) ($aj['nuevo_ref_id'] ?? 0);
            $nuevaCantidad = is_numeric($aj['nueva_cantidad'] ?? null) ? round((float) $aj['nueva_cantidad'], 3) : $cantidad;
            if (!in_array($nuevoTipo, self::TIPOS, true) || $nuevoRef <= 0 || !self::componenteExiste($nuevoTipo, $nuevoRef)) {
                $errores[] = "En «{$opcion}» elige por qué ingrediente se sustituye.";
                return null;
            }
            if ($nuevoTipo === $tipo && $nuevoRef === $ref) {
                $errores[] = "En «{$opcion}» un ingrediente no puede sustituirse por sí mismo.";
                return null;
            }
            if ($nuevaCantidad <= 0 || $nuevaCantidad > 99999) {
                $errores[] = "En «{$opcion}» la cantidad del sustituto debe ser mayor que cero.";
                return null;
            }
        }

        return [
            'accion' => $accion,
            'tipo' => $tipo,
            'ref_id' => $ref,
            'cantidad' => $cantidad,
            'nuevo_tipo' => $nuevoTipo,
            'nuevo_ref_id' => $nuevoRef,
            'nueva_cantidad' => $nuevaCantidad,
        ];
    }

    private static function componenteExiste(string $tipo, int $ref): bool
    {
        static $cache = [];
        $clave = $tipo . ':' . $ref;
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }
        $tabla = $tipo === 'subreceta' ? 'subrecetas' : 'ingredientes';
        $res = Producto::getDB()->query("SELECT 1 FROM {$tabla} WHERE id = " . max(0, $ref) . ' LIMIT 1');

        return $cache[$clave] = (bool) ($res && $res->fetch_row());
    }

    private static function texto($valor, int $max): string
    {
        $texto = is_scalar($valor) ? trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '') : '';

        return mb_substr($texto, 0, $max);
    }
}
