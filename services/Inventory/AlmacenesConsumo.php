<?php

namespace Services\Inventory;

use Model\ActiveRecord;
use Services\Security\ContextoNegocio;

/**
 * Almacén de consumo de cada artículo de una oferta (oferta_consumos).
 *
 * La venta no busca otro almacén si falta la fila: ConsumoOferta rechaza la
 * comanda. Por eso el panel exige elegir a mano el origen de todo artículo que
 * la receta activa o las variantes de la oferta pueden descontar.
 */
final class AlmacenesConsumo
{
    // Receta activa, sus subrecetas explotadas y lo que las variantes agregan o
    // meten por sustitución; quitar solo resta artículos que ya están en la base.
    private const REQUERIDOS = "
        SELECT rc.articulo_id AS id FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id
         WHERE r.oferta_id=? AND r.unidad_id=? AND r.activo=1 AND rc.articulo_id IS NOT NULL
        UNION SELECT si.articulo_id FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id
         JOIN subreceta_ingredientes si ON si.subreceta_id=rc.subreceta_id AND si.unidad_id=rc.unidad_id
         WHERE r.oferta_id=? AND r.unidad_id=? AND r.activo=1
        UNION SELECT IF(va.accion='agregar',va.articulo_id,va.articulo_nuevo_id) FROM variante_ajustes va
         WHERE va.oferta_id=? AND va.unidad_id=? AND va.accion<>'quitar' AND IF(va.accion='agregar',va.articulo_id,va.articulo_nuevo_id) IS NOT NULL
        UNION SELECT si.articulo_id FROM variante_ajustes va JOIN subreceta_ingredientes si
         ON si.unidad_id=va.unidad_id AND si.subreceta_id=IF(va.accion='agregar',va.subreceta_id,va.subreceta_nueva_id)
         WHERE va.oferta_id=? AND va.unidad_id=? AND va.accion<>'quitar'";

    /** Filas del formulario: artículo, almacén asignado vigente y almacenes donde puede salir. */
    public static function paraFormulario(string $ctx, int $ofertaId): array
    {
        ContextoNegocio::exigir('productos', 'ver', $ctx);
        return self::filas(ActiveRecord::getDB(), $ofertaId, ContextoNegocio::unidad($ctx));
    }

    /** Artículos que una venta no podría descontar hoy por falta de almacén. */
    public static function pendientes(string $ctx, int $ofertaId): int
    {
        return count(array_filter(self::paraFormulario($ctx, $ofertaId), static fn(array $f): bool => $f['almacen_id'] === null));
    }

    /**
     * Reemplaza las asignaciones de la oferta. Cada artículo requerido necesita
     * un almacén activo del negocio donde esté dado de alta.
     *
     * @param array<int|string,mixed> $asignacion articulo_id => almacen_id
     */
    public static function guardar(string $ctx, int $ofertaId, array $asignacion): array
    {
        ContextoNegocio::exigir('productos', 'editar', $ctx, true);
        $unidadId = ContextoNegocio::unidad($ctx);
        $db = ActiveRecord::getDB();
        return ContextoNegocio::en($ctx, static fn(): array => ContextoNegocio::transaccion(static function () use ($db, $ctx, $unidadId, $ofertaId, $asignacion): array {
            ContextoNegocio::exigir('productos', 'editar', $ctx, true);
            $oferta = $db->prepare('SELECT id FROM ofertas_producto WHERE id=? AND unidad_id=? FOR UPDATE');
            $oferta->execute([$ofertaId, $unidadId]);
            if (!$oferta->get_result()->fetch_row()) throw new InventarioError('OFERTA_DENEGADA', 'La oferta no pertenece al negocio activo.');
            $filas = self::filas($db, $ofertaId, $unidadId, true);
            $elegidos = [];
            foreach ($filas as $f) {
                if ($f['opciones'] === []) throw new InventarioError('ARTICULO_SIN_ALMACEN', "«{$f['nombre']}» no está dado de alta en ningún almacén activo de este negocio. Asócialo en Almacenes.");
                $almacen = filter_var($asignacion[$f['articulo_id']] ?? null, FILTER_VALIDATE_INT);
                if (!isset($f['opciones'][$almacen])) throw new InventarioError('ALMACEN_CONSUMO_REQUERIDO', "Elige de qué almacén sale «{$f['nombre']}».");
                $elegidos[$f['articulo_id']] = $almacen;
            }
            // Reemplazo completo: también retira artículos que la receta o las variantes ya no usan.
            $db->prepare('DELETE FROM oferta_consumos WHERE oferta_id=? AND unidad_id=?')->execute([$ofertaId, $unidadId]);
            $ins = $db->prepare('INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES (?,?,?,?)');
            foreach ($elegidos as $articulo => $almacen) $ins->execute([$ofertaId, $articulo, $unidadId, $almacen]);
            return ['asignados' => count($elegidos)];
        }));
    }

    private static function filas(\mysqli $db, int $ofertaId, int $unidadId, bool $bloquear = false): array
    {
        $req = $db->prepare(self::REQUERIDOS);
        $req->execute(array_merge(...array_fill(0, 4, [$ofertaId, $unidadId])));
        $ids = array_map('intval', array_column($req->get_result()->fetch_all(MYSQLI_ASSOC), 'id'));
        if ($ids === []) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $lock = $bloquear ? ' FOR SHARE' : '';
        $stmt = $db->prepare("SELECT aa.articulo_id,a.id,a.nombre FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id
            WHERE aa.unidad_id=? AND aa.activo=1 AND a.activo=1 AND aa.articulo_id IN ({$in}) ORDER BY a.nombre{$lock}");
        $stmt->execute(array_merge([$unidadId], $ids));
        $opciones = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $opciones[(int)$r['articulo_id']][(int)$r['id']] = $r['nombre'];
        $stmt = $db->prepare("SELECT i.id,i.nombre,i.unidad,oc.almacen_id FROM ingredientes i
            LEFT JOIN oferta_consumos oc ON oc.articulo_id=i.id AND oc.oferta_id=? AND oc.unidad_id=? WHERE i.id IN ({$in}) ORDER BY i.nombre");
        $stmt->execute(array_merge([$ofertaId, $unidadId], $ids));
        $filas = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $id = (int)$r['id'];
            $actual = $r['almacen_id'] === null ? null : (int)$r['almacen_id'];
            // Una asignación a un almacén o asociación ya inactivos cuenta como pendiente.
            $filas[] = ['articulo_id' => $id, 'nombre' => $r['nombre'], 'unidad' => $r['unidad'], 'opciones' => $opciones[$id] ?? [],
                'almacen_id' => isset($opciones[$id][$actual]) ? $actual : null];
        }
        return $filas;
    }
}
