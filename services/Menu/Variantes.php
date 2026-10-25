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
use Services\Security\ContextoNegocio;
use Services\Inventory\DecimalInventario;

final class Variantes
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
    public static function deProducto(int $productoId, ?string $ctx = null): array
    {
        if (!ContextoNegocio::nuevo()) return VariantesLegado::deProducto($productoId);
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('menu', 'ver', $ctx, true);
        $db = Producto::getDB();
        $unidadId = ContextoNegocio::unidad($ctx);
        $productoId = max(0, $productoId); // ID comercial: ofertas_producto.id
        self::exigirOferta($db, $productoId, $unidadId);
        $stmt = $db->prepare('SELECT id, grupo, nombre, precio_delta, predeterminada, activo FROM producto_variantes WHERE oferta_id = ? AND unidad_id = ? ORDER BY orden_grupo, orden, id');
        $stmt->bind_param('ii', $productoId, $unidadId);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) {
            $stmt->close();
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
        $stmt->close();

        if ($ids !== []) {
            $lista = implode(',', $ids);
            $aj = $db->query(
                "SELECT va.variante_id, va.accion, va.articulo_id, va.subreceta_id, va.cantidad,
                        va.articulo_nuevo_id, va.subreceta_nueva_id, va.nueva_cantidad, pv.grupo
                   FROM variante_ajustes va
                   JOIN producto_variantes pv ON pv.id = va.variante_id AND pv.oferta_id = va.oferta_id AND pv.unidad_id = va.unidad_id
                  WHERE va.variante_id IN ({$lista}) AND va.oferta_id = {$productoId} AND va.unidad_id = {$unidadId}
                  ORDER BY va.id ASC"
            );
            if ($aj) {
                while ($row = $aj->fetch_assoc()) {
                    $vid = (int) $row['variante_id'];
                    $grupo = (string) $row['grupo'];
                    $grupos[$grupo]['opciones'][$vid]['ajustes'][] = [
                        'accion' => (string) $row['accion'],
                        'tipo' => $row['articulo_id'] !== null ? 'ingrediente' : 'subreceta',
                        'ref_id' => (int) ($row['articulo_id'] ?? $row['subreceta_id']),
                        'cantidad' => (string) $row['cantidad'],
                        'nuevo_tipo' => $row['articulo_nuevo_id'] !== null ? 'ingrediente' : ($row['subreceta_nueva_id'] !== null ? 'subreceta' : null),
                        'nuevo_ref_id' => $row['articulo_nuevo_id'] !== null ? (int) $row['articulo_nuevo_id'] : ($row['subreceta_nueva_id'] !== null ? (int) $row['subreceta_nueva_id'] : null),
                        'nueva_cantidad' => $row['nueva_cantidad'] !== null ? (string) $row['nueva_cantidad'] : null,
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
    public static function datosEditor(int $productoId, ?string $ctx = null): array
    {
        if (!ContextoNegocio::nuevo()) return VariantesLegado::datosEditor($productoId);
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('menu', 'ver', $ctx, true);
        $db = Producto::getDB();
        $productoId = max(0, $productoId);
        $unidadId = ContextoNegocio::unidad($ctx);
        self::exigirOferta($db, $productoId, $unidadId);

        $base = RecetasOferta::paraEditorVariantes($ctx, $productoId);

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
        $subStmt = $db->prepare('SELECT id, nombre, unidad, rendimiento FROM subrecetas WHERE unidad_id = ? AND activo = 1 ORDER BY nombre ASC');
        $subStmt->bind_param('i', $unidadId);
        $subStmt->execute();
        $res = $subStmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $subrecetas[(int) $row['id']] = [
                    'id' => (int) $row['id'],
                    'nombre' => (string) $row['nombre'],
                    'unidad' => (string) $row['unidad'],
                    'rendimiento' => (string) $row['rendimiento'],
                    'ingredientes' => [],
                ];
            }
            $res->free();
        }
        $subStmt->close();
        if ($subrecetas !== []) {
            $res = $db->query('SELECT subreceta_id, articulo_id, cantidad FROM subreceta_ingredientes WHERE unidad_id = ' . $unidadId . ' AND subreceta_id IN (' . implode(',', array_map('intval', array_keys($subrecetas))) . ')');
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $subrecetas[(int) $row['subreceta_id']]['ingredientes'][] = [
                        'id' => (int) $row['articulo_id'],
                        'cantidad' => (string) $row['cantidad'],
                    ];
                }
                $res->free();
            }
        }

        return [
            'producto_id' => $productoId,
            'oferta_id' => $productoId,
            'base' => $base,
            'ingredientes' => $ingredientes,
            'subrecetas' => array_values($subrecetas),
            'grupos' => self::deProducto($productoId, $ctx),
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
    public static function paraPos(?string $ctx = null): array
    {
        if (!ContextoNegocio::nuevo()) return VariantesLegado::paraPos();
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('pdv', 'operar', $ctx, true);
        $unidadId = ContextoNegocio::vendedora($ctx);
        $db = Producto::getDB();
        $stmt = $db->prepare(
            "SELECT pv.id, pv.oferta_id, pv.grupo, pv.nombre, pv.precio_delta, pv.predeterminada
               FROM producto_variantes pv
               JOIN ofertas_producto o ON o.id = pv.oferta_id AND o.unidad_id = pv.unidad_id
              WHERE pv.activo = 1 AND o.activo = 1 AND o.unidad_id = ?
              ORDER BY pv.oferta_id, pv.orden_grupo, pv.orden, pv.id"
        );
        $stmt->bind_param('i', $unidadId);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) {
            $stmt->close();
            return [];
        }

        $porProducto = [];
        while ($row = $res->fetch_assoc()) {
            $pid = (int) $row['oferta_id'];
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
        $stmt->close();

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
    public static function conteoPorProducto(?string $ctx = null): array
    {
        if (!ContextoNegocio::nuevo()) return VariantesLegado::conteoPorProducto();
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('menu', 'ver', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $db = Producto::getDB();
        $stmt = $db->prepare('SELECT oferta_id, COUNT(*) AS n FROM producto_variantes WHERE unidad_id = ? GROUP BY oferta_id');
        $stmt->bind_param('i', $unidadId);
        $stmt->execute();
        $res = $stmt->get_result();
        if (!$res) {
            $stmt->close();
            return [];
        }
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $out[(int) $row['oferta_id']] = (int) $row['n'];
        }
        $res->free();
        $stmt->close();

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
    public static function resolver(int $productoId, array $ids, ?string $ctx = null): array
    {
        if (!ContextoNegocio::nuevo()) return VariantesLegado::resolver($productoId, $ids);
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('pdv', 'operar', $ctx, true);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        $db = Producto::getDB();
        $productoId = max(0, $productoId);
        $unidadId = ContextoNegocio::vendedora($ctx);
        self::exigirOferta($db, $productoId, $unidadId);
        $stmt = $db->prepare(
            "SELECT id, grupo, nombre, precio_delta, predeterminada
               FROM producto_variantes
              WHERE oferta_id = ? AND unidad_id = ? AND activo = 1
              ORDER BY orden_grupo ASC, orden ASC, id ASC"
        );
        $stmt->bind_param('ii', $productoId, $unidadId);
        $stmt->execute();
        $res = $stmt->get_result();
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
        $stmt->close();

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
    public static function guardar(int $productoId, array $grupos, ?string $ctx = null): array
    {
        if (!ContextoNegocio::nuevo()) return VariantesLegado::guardar($productoId, $grupos);
        $ctx ??= ContextoNegocio::slug();
        ContextoNegocio::exigir('menu', 'editar', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $validacion = self::validar($grupos, $unidadId);
        if ($validacion['errores'] !== []) {
            return ['ok' => false, 'errores' => $validacion['errores'], 'grupos' => []];
        }
        $limpios = $validacion['grupos'];

        $db = Producto::getDB();
        $productoId = max(0, $productoId); // oferta_id
        try {
            $resultado = ContextoNegocio::en($ctx, static function () use ($db, $productoId, $unidadId, $limpios, $ctx): array {
                return ContextoNegocio::transaccion(static function () use ($db, $productoId, $unidadId, $limpios, $ctx): array {
                    ContextoNegocio::exigir('menu', 'editar', $ctx, true);
                    self::exigirOferta($db, $productoId, $unidadId, true);
                    $existentes = [];
                    $sel = $db->prepare('SELECT id FROM producto_variantes WHERE oferta_id = ? AND unidad_id = ? FOR UPDATE');
                    $sel->bind_param('ii', $productoId, $unidadId);
                    $sel->execute();
                    foreach ($sel->get_result()->fetch_all(MYSQLI_ASSOC) as $row) $existentes[(int)$row['id']] = true;
                    $sel->close();
                    if ($existentes !== []) {
                        $db->query("UPDATE producto_variantes SET grupo = CONCAT('~', id) WHERE oferta_id = {$productoId} AND unidad_id = {$unidadId}");
                    }
                    $upd = $db->prepare('UPDATE producto_variantes SET grupo=?, nombre=?, precio_delta=?, predeterminada=?, activo=?, orden_grupo=?, orden=? WHERE id=? AND oferta_id=? AND unidad_id=?');
                    $ins = $db->prepare('INSERT INTO producto_variantes (oferta_id, unidad_id, grupo, nombre, precio_delta, predeterminada, activo, orden_grupo, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $insAj = $db->prepare('INSERT INTO variante_ajustes (variante_id, oferta_id, unidad_id, articulo_id, subreceta_id, cantidad, accion, articulo_nuevo_id, subreceta_nueva_id, nueva_cantidad) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    if (!$upd || !$ins || !$insAj) throw new \RuntimeException($db->error);
                    $conservados = [];
                    foreach ($limpios as $ordenGrupo => $grupo) foreach ($grupo['opciones'] as $orden => $op) {
                        $id = (int)($op['id'] ?? 0); $nombreGrupo=$grupo['nombre']; $nombre=$op['nombre']; $delta=$op['precio_delta'];
                        $def=$op['predeterminada']?1:0; $activo=$op['activo']?1:0;
                        if ($id > 0 && isset($existentes[$id])) {
                            $upd->bind_param('ssdiiiiiii',$nombreGrupo,$nombre,$delta,$def,$activo,$ordenGrupo,$orden,$id,$productoId,$unidadId);
                            if (!$upd->execute()) throw new \RuntimeException($upd->error);
                        } else {
                            $ins->bind_param('iissdiiii',$productoId,$unidadId,$nombreGrupo,$nombre,$delta,$def,$activo,$ordenGrupo,$orden);
                            if (!$ins->execute()) throw new \RuntimeException($ins->error);
                            $id=(int)$db->insert_id;
                        }
                        $conservados[$id]=true;
                        $del=$db->prepare('DELETE FROM variante_ajustes WHERE variante_id=? AND oferta_id=? AND unidad_id=?');
                        $del->bind_param('iii',$id,$productoId,$unidadId); if (!$del->execute()) throw new \RuntimeException($del->error); $del->close();
                        foreach ($op['ajustes'] as $aj) {
                            $accion=$aj['accion']; $tipo=$aj['tipo']; $ref=$aj['ref_id'];
                            $articuloId=$tipo==='ingrediente'?$ref:null; $subrecetaId=$tipo==='subreceta'?$ref:null; $cant=$aj['cantidad'];
                            $nuevoTipo=$aj['nuevo_tipo']; $nuevoRef=$aj['nuevo_ref_id'];
                            $nuevoArticuloId=$nuevoTipo==='ingrediente'?$nuevoRef:null; $nuevaSubrecetaId=$nuevoTipo==='subreceta'?$nuevoRef:null; $nCant=$aj['nueva_cantidad'];
                            $insAj->bind_param('iiiiissiis',$id,$productoId,$unidadId,$articuloId,$subrecetaId,$cant,$accion,$nuevoArticuloId,$nuevaSubrecetaId,$nCant);
                            if (!$insAj->execute()) throw new \RuntimeException($insAj->error);
                        }
                    }
                    $upd->close(); $ins->close(); $insAj->close();
                    $sobrantes=array_diff(array_keys($existentes),array_keys($conservados));
                    if ($sobrantes) $db->query('DELETE FROM producto_variantes WHERE oferta_id='.$productoId.' AND unidad_id='.$unidadId.' AND id IN ('.implode(',',array_map('intval',$sobrantes)).')');
                    return ['guardado'=>true];
                });
            });
            if (empty($resultado['ok']) && $resultado['ok'] !== null) {
                return ['ok'=>false,'errores'=>[$resultado['mensaje'] ?? 'No fue posible guardar las variantes.'],'grupos'=>[]] + $resultado;
            }
            return $resultado + ['errores'=>[],'grupos'=>self::deProducto($productoId,$ctx)];
        } catch (\Throwable $e) {
            error_log('Variantes::guardar - ' . $e->getMessage());
            return ['ok'=>false,'errores'=>['No fue posible guardar las variantes. Intenta de nuevo.'],'grupos'=>[]];
        }
    }

    /**
     * @return array{errores: list<string>, grupos: list<array>}
     */
    private static function validar(array $grupos, int $unidadId): array
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
                    $limpio = self::validarAjuste(is_array($aj) ? $aj : [], $nombre, $unidadId, $errores);
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

    private static function validarAjuste(array $aj, string $opcion, int $unidadId, array &$errores): ?array
    {
        $accion = (string) ($aj['accion'] ?? '');
        $tipo = (string) ($aj['tipo'] ?? '');
        $ref = (int) ($aj['ref_id'] ?? 0);
        try {
            $cantidadInt = DecimalInventario::leer($aj['cantidad'] ?? null);
            $cantidad = DecimalInventario::cantidad($cantidadInt);
        } catch (\Throwable) {
            $cantidadInt = 0;
            $cantidad = '0.000';
        }

        if (!in_array($accion, self::ACCIONES, true) || !in_array($tipo, self::TIPOS, true) || $ref <= 0) {
            $errores[] = "Un ajuste de receta de «{$opcion}» está incompleto: elige qué hacer y con qué ingrediente.";
            return null;
        }
        if ($cantidadInt <= 0) {
            $errores[] = "Un ajuste de «{$opcion}» necesita una cantidad mayor que cero.";
            return null;
        }
        if (!self::componenteExiste($tipo, $ref, $unidadId)) {
            $errores[] = "Un ajuste de «{$opcion}» apunta a un ingrediente que ya no existe.";
            return null;
        }

        $nuevoTipo = null;
        $nuevoRef = null;
        $nuevaCantidad = null;
        if ($accion === 'sustituir') {
            $nuevoTipo = (string) ($aj['nuevo_tipo'] ?? '');
            $nuevoRef = (int) ($aj['nuevo_ref_id'] ?? 0);
            try {
                $nuevaCantidadInt = DecimalInventario::leer($aj['nueva_cantidad'] ?? null);
                $nuevaCantidad = DecimalInventario::cantidad($nuevaCantidadInt);
            } catch (\Throwable) {
                $nuevaCantidadInt = 0;
                $nuevaCantidad = '0.000';
            }
            if (!in_array($nuevoTipo, self::TIPOS, true) || $nuevoRef <= 0 || !self::componenteExiste($nuevoTipo, $nuevoRef, $unidadId)) {
                $errores[] = "En «{$opcion}» elige por qué ingrediente se sustituye.";
                return null;
            }
            if ($nuevoTipo === $tipo && $nuevoRef === $ref) {
                $errores[] = "En «{$opcion}» un ingrediente no puede sustituirse por sí mismo.";
                return null;
            }
            if ($nuevaCantidadInt <= 0) {
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

    private static function componenteExiste(string $tipo, int $ref, int $unidadId): bool
    {
        static $cache = [];
        $clave = $tipo . ':' . $unidadId . ':' . $ref;
        if (isset($cache[$clave])) {
            return $cache[$clave];
        }
        $db = Producto::getDB();
        if ($tipo === 'subreceta') {
            $stmt = $db->prepare('SELECT 1 FROM subrecetas WHERE id = ? AND unidad_id = ? LIMIT 1');
            $stmt->bind_param('ii', $ref, $unidadId);
        } else {
            $stmt = $db->prepare('SELECT 1 FROM ingredientes WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $ref);
        }
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $cache[$clave] = $ok;
    }

    private static function exigirOferta(\mysqli $db, int $ofertaId, int $unidadId, bool $bloquear=false): void
    {
        if ($ofertaId <= 0 || $unidadId <= 0) throw new \DomainException('Oferta fuera del ámbito activo.');
        $stmt = $db->prepare('SELECT id FROM ofertas_producto WHERE id = ? AND unidad_id = ? LIMIT 1'.($bloquear?' FOR UPDATE':''));
        $stmt->bind_param('ii', $ofertaId, $unidadId);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ok) throw new \DomainException('La oferta no pertenece al negocio activo.');
    }

    private static function texto($valor, int $max): string
    {
        $texto = is_scalar($valor) ? trim(preg_replace('/\s+/u', ' ', (string) $valor) ?? '') : '';

        return mb_substr($texto, 0, $max);
    }
}
