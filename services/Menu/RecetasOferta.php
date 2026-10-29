<?php

namespace Services\Menu;

use Model\Producto;
use Services\Inventory\InventarioError;
use Services\Inventory\DecimalInventario;
use Services\Security\ContextoNegocio;

/** Recetas versionadas y aisladas por oferta comercial. */
final class RecetasOferta
{
    /**
     * Guarda una nueva versión. Los componentes aceptan el DTO histórico
     * {tipo, ref_id, cantidad}; el tipo se convierte a las FK explícitas.
     * Una lista vacía desactiva la receta actual y deja la oferta sin receta.
     */
    public static function guardar(string $ctx, int $ofertaId, array $componentes): array
    {
        ContextoNegocio::exigir('productos', 'editar', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        if ($ofertaId <= 0 || $unidadId <= 0) {
            return ['ok' => false, 'error' => 'OFERTA_INVALIDA', 'mensaje' => 'La oferta no es válida para este negocio.'];
        }

        $normalizados = self::normalizar($componentes);
        if (isset($normalizados['error'])) {
            return ['ok' => false, 'error' => 'COMPONENTE_INVALIDO', 'mensaje' => $normalizados['error']];
        }
        $db = Producto::getDB();
        if (!$db) {
            return ['ok' => false, 'error' => 'DB_NO_DISPONIBLE', 'mensaje' => 'No se pudo acceder a las recetas.'];
        }

        try {
            return ContextoNegocio::en($ctx, static function () use ($db, $ctx, $unidadId, $ofertaId, $normalizados): array {
                return ContextoNegocio::transaccion(static function () use ($db, $ctx, $unidadId, $ofertaId, $normalizados): array {
                    ContextoNegocio::exigir('productos', 'editar', $ctx, true);
                    $ofertaLock = $db->prepare('SELECT id,producto_id FROM ofertas_producto WHERE id = ? AND unidad_id = ? FOR UPDATE');
                    $ofertaLock->bind_param('ii', $ofertaId, $unidadId);
                    if (!$ofertaLock->execute() || !($ofertaCasa=$ofertaLock->get_result()->fetch_assoc())) {
                        $ofertaLock->close();
                        throw new InventarioError('OFERTA_DENEGADA', 'La oferta no pertenece al negocio activo.');
                    }
                    $ofertaLock->close();
                    if(!ProductosCasa::permite((int)$ofertaCasa['producto_id'],'receta'))throw new InventarioError('CAMPO_PROTEGIDO','Receta: Definido por Global.');
                    $oficialCasa=ProductosCasa::publicada((int)$ofertaCasa['producto_id']);
                    if($oficialCasa&&$normalizados===[]&&!empty($oficialCasa['requiere_receta']))throw new InventarioError('RECETA_REQUERIDA','Este producto requiere receta.');
                    if($oficialCasa){RecetasCasa::validarAdaptacion($ofertaId,$unidadId,$normalizados);ProductosCasa::personalizacion($ofertaId,'receta',$normalizados===[]?'sin':'adaptada');}
                    foreach ($normalizados as $comp) {
                        if ($comp['subreceta_id'] !== null) {
                            self::exigirSubrecetaLocal($db, $comp['subreceta_id'], $unidadId);
                        }
                        if ($comp['articulo_id'] !== null) {
                            self::exigirArticulo($db, $comp['articulo_id']);
                        }
                    }

                    $actual = $db->prepare('SELECT id, version FROM recetas WHERE oferta_id = ? AND unidad_id = ? AND activo = 1 ORDER BY version DESC LIMIT 1 FOR UPDATE');
                    if (!$actual) {
                        throw new \RuntimeException($db->error);
                    }
                    $actual->bind_param('ii', $ofertaId, $unidadId);
                    if (!$actual->execute()) {
                        throw new \RuntimeException($actual->error);
                    }
                    $row = $actual->get_result()->fetch_assoc() ?: null;
                    $actual->close();

                    if ($normalizados === []) {
                        if ($row) {
                            $desactivar = $db->prepare('UPDATE recetas SET activo = 0 WHERE id = ? AND unidad_id = ?');
                            $id = (int) $row['id'];
                            $desactivar->bind_param('ii', $id, $unidadId);
                            if (!$desactivar->execute()) {
                                throw new \RuntimeException($desactivar->error);
                            }
                            $desactivar->close();
                        }
                        return ['ok' => true, 'activa' => false, 'receta_id' => null, 'version' => null, 'componentes' => []];
                    }

                    $maxVersion = $db->prepare('SELECT COALESCE(MAX(version), 0) AS version FROM recetas WHERE oferta_id = ? AND unidad_id = ?');
                    $maxVersion->bind_param('ii', $ofertaId, $unidadId);
                    if (!$maxVersion->execute()) throw new \RuntimeException($maxVersion->error);
                    $version = (int)($maxVersion->get_result()->fetch_assoc()['version'] ?? 0) + 1;
                    $maxVersion->close();
                    $desactivar = $db->prepare('UPDATE recetas SET activo = 0 WHERE oferta_id = ? AND unidad_id = ? AND activo = 1');
                    $desactivar->bind_param('ii', $ofertaId, $unidadId);
                    if (!$desactivar->execute()) {
                        throw new \RuntimeException($desactivar->error);
                    }
                    $desactivar->close();

                    $insertar = $db->prepare('INSERT INTO recetas (oferta_id, unidad_id, version, activo) VALUES (?, ?, ?, 1)');
                    $insertar->bind_param('iii', $ofertaId, $unidadId, $version);
                    if (!$insertar->execute()) {
                        throw new \RuntimeException($insertar->error);
                    }
                    $recetaId = (int) $db->insert_id;
                    $insertar->close();

                    $componente = $db->prepare('INSERT INTO receta_componentes (receta_id, unidad_id, articulo_id, subreceta_id, cantidad) VALUES (?, ?, ?, ?, ?)');
                    if (!$componente) {
                        throw new \RuntimeException($db->error);
                    }
                    foreach ($normalizados as $item) {
                        $articuloId = $item['articulo_id'];
                        $subrecetaId = $item['subreceta_id'];
                        $cantidad = $item['cantidad'];
                        $componente->bind_param('iiiis', $recetaId, $unidadId, $articuloId, $subrecetaId, $cantidad);
                        if (!$componente->execute()) {
                            throw new \RuntimeException($componente->error);
                        }
                    }
                    $componente->close();
                    return ['ok' => true, 'activa' => true, 'receta_id' => $recetaId, 'version' => $version, 'componentes' => self::componentes($ctx, $ofertaId)];
                });
            });
        } catch (\Throwable $e) {
            error_log('RecetasOferta::guardar - ' . $e->getMessage());
            return ['ok' => false, 'error' => 'RECETA_NO_GUARDADA', 'mensaje' => 'No fue posible guardar la receta.'];
        }
    }

    /** Devuelve la receta activa y DTOs compatibles con los consumidores existentes. */
    public static function componentes(string $ctx, int $ofertaId): array
    {
        ContextoNegocio::exigir('productos', 'ver', $ctx, true);
        return self::leerComponentes($ctx, $ofertaId);
    }

    /** Lectura de composición usada por editor de variantes, protegido por Menú. */
    public static function paraEditorVariantes(string $ctx, int $ofertaId): array
    {
        ContextoNegocio::exigir('menu', 'ver', $ctx, true);
        return self::leerComponentes($ctx, $ofertaId);
    }

    private static function leerComponentes(string $ctx, int $ofertaId): array
    {
        $unidadId = ContextoNegocio::unidad($ctx);
        if ($ofertaId <= 0 || $unidadId <= 0) {
            return [];
        }
        $db = Producto::getDB();
        self::exigirOfertaLocal($db, $ofertaId, $unidadId);
        $stmt = $db->prepare(
            "SELECT rc.articulo_id, rc.subreceta_id, rc.cantidad,
                    CASE WHEN rc.articulo_id IS NOT NULL THEN i.nombre ELSE s.nombre END AS nombre,
                    CASE WHEN rc.articulo_id IS NOT NULL THEN i.unidad ELSE s.unidad END AS unidad
               FROM recetas r JOIN receta_componentes rc ON rc.receta_id = r.id AND rc.unidad_id = r.unidad_id
               LEFT JOIN ingredientes i ON i.id = rc.articulo_id
               LEFT JOIN subrecetas s ON s.id = rc.subreceta_id AND s.unidad_id = rc.unidad_id
              WHERE r.oferta_id = ? AND r.unidad_id = ? AND r.activo = 1 ORDER BY rc.id"
        );
        if (!$stmt) {
            throw new \RuntimeException($db->error);
        }
        $stmt->bind_param('ii', $ofertaId, $unidadId);
        if (!$stmt->execute()) {
            throw new \RuntimeException($stmt->error);
        }
        $result = $stmt->get_result();
        $salida = [];
        while ($row = $result->fetch_assoc()) {
            $salida[] = [
                'tipo' => $row['articulo_id'] !== null ? 'ingrediente' : 'subreceta',
                'ref_id' => (int) ($row['articulo_id'] ?? $row['subreceta_id']),
                'cantidad' => (string) $row['cantidad'],
                'nombre' => (string) $row['nombre'],
                'unidad' => (string) $row['unidad'],
            ];
        }
        $stmt->close();
        return $salida;
    }

    private static function normalizar(array $items): array
    {
        $salida = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                return ['error' => 'Un componente de receta no es válido.'];
            }
            $tipo = (string) ($item['tipo'] ?? '');
            $ref = filter_var($item['ref_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            try {
                $cantidadEscalada = DecimalInventario::leer($item['cantidad'] ?? null);
            } catch (\Throwable) {
                return ['error' => 'La cantidad debe ser un decimal positivo con hasta tres decimales.'];
            }
            if (!in_array($tipo, ['ingrediente', 'subreceta'], true) || !$ref || $cantidadEscalada <= 0) {
                return ['error' => 'Los componentes requieren un artículo o subreceta y una cantidad positiva.'];
            }
            $salida[] = ['articulo_id' => $tipo === 'ingrediente' ? (int) $ref : null, 'subreceta_id' => $tipo === 'subreceta' ? (int) $ref : null, 'cantidad' => DecimalInventario::cantidad($cantidadEscalada)];
        }
        return $salida;
    }

    private static function exigirOfertaLocal(\mysqli $db, int $ofertaId, int $unidadId): void
    {
        $stmt = $db->prepare('SELECT id FROM ofertas_producto WHERE id = ? AND unidad_id = ? LIMIT 1');
        $stmt->bind_param('ii', $ofertaId, $unidadId);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ok) {
            throw new InventarioError('OFERTA_DENEGADA', 'La oferta no pertenece al negocio activo.');
        }
    }

    private static function exigirSubrecetaLocal(\mysqli $db, int $id, int $unidadId): void
    {
        $stmt = $db->prepare('SELECT id FROM subrecetas WHERE id = ? AND unidad_id = ? LIMIT 1');
        $stmt->bind_param('ii', $id, $unidadId);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ok) {
            throw new InventarioError('SUBRECETA_DENEGADA', 'La subreceta no pertenece al negocio activo.');
        }
    }

    private static function exigirArticulo(\mysqli $db, int $id): void
    {
        $stmt = $db->prepare('SELECT id FROM ingredientes WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $ok = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$ok) {
            throw new InventarioError('ARTICULO_INVALIDO', 'El artículo no existe.');
        }
    }
}
