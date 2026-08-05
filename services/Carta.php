<?php

namespace Services;

use Model\Producto;

/**
 * Lectura de la carta desde la fuente única (`productos`).
 *
 * Un solo lugar arma el agrupado por categoría que consumen la landing
 * (GET /menu), el PDF y el punto de venta. Antes la carta pública hacía N+1
 * consultas (una por categoría) y el PDF ordenaba al revés porque usaba
 * ActiveRecord::all(), que es ORDER BY id DESC.
 *
 * No se usa consultarSQL: ActiveRecord::crearObjeto() descarta cualquier
 * columna que no sea propiedad declarada del modelo, así que `c.nombre AS
 * cat_nombre` se perdería. Con mysqli directo se controla la forma.
 */
class Carta
{
    /**
     * Carta pública agrupada por categoría.
     *
     * Forma idéntica a la que ya consumían src/js/modules/menu.js y
     * views/home/_menu.php, para que no tengan que cambiar:
     *   [{ id, label, img, dishes: [{ n, d, p }] }]
     */
    public static function publica(): array
    {
        $out = [];
        $db = Producto::getDB();
        $res = $db->query(
            "SELECT c.id AS cat_id, c.nombre AS cat_nombre, c.img AS cat_img,
                    p.id, p.nombre, p.descripcion, p.precio
               FROM productos p
               JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND c.activo = 1
              ORDER BY c.id ASC, p.id ASC"
        );

        if (!$res) {
            return [];
        }

        while ($row = $res->fetch_assoc()) {
            $cid = (int) $row['cat_id'];

            if (!isset($out[$cid])) {
                $out[$cid] = [
                    'id'     => $cid,
                    'label'  => $row['cat_nombre'],
                    'img'    => $row['cat_img'] ? '/' . ltrim($row['cat_img'], '/') : null,
                    'dishes' => [],
                ];
            }

            $out[$cid]['dishes'][] = [
                'n'    => $row['nombre'],
                'd'    => (string) $row['descripcion'],
                // (float) para que json_encode emita 240 y no "240.00":
                // menu.js concatena '$' + d.p directamente.
                'p'    => (float) $row['precio'],
            ];
        }

        $res->free();

        return array_values($out);
    }

    /**
     * Carta para el punto de venta.
     *
     * Se omiten descripción e imagen (el POS no las pinta) y el área viaja como
     * slug, que es lo que addToComanda resuelve contra window.CP_AREAS:
     *   [{ id, label, dishes: [{ n, p, area }] }]
     */
    public static function paraPos(): array
    {
        $out = [];
        $db = Producto::getDB();
        $res = $db->query(
            "SELECT c.id AS cat_id, c.nombre AS cat_nombre,
                    p.nombre, p.precio, ap.slug AS area_slug
               FROM productos p
               JOIN categorias c        ON c.id  = p.categoria_id
               JOIN areas_produccion ap ON ap.id = p.area_id
              WHERE p.activo = 1 AND c.activo = 1
              ORDER BY c.id ASC, p.id ASC"
        );

        if (!$res) {
            return [];
        }

        while ($row = $res->fetch_assoc()) {
            $cid = (int) $row['cat_id'];

            if (!isset($out[$cid])) {
                $out[$cid] = ['id' => $cid, 'label' => $row['cat_nombre'], 'dishes' => []];
            }

            $out[$cid]['dishes'][] = [
                'n'    => $row['nombre'],
                'p'    => (float) $row['precio'],
                'area' => $row['area_slug'],
            ];
        }

        $res->free();

        return array_values($out);
    }

    /**
     * Áreas de producción como slug => {id, label, color}.
     * Reemplaza al literal window.CP_AREAS de src/js/data/menu-data.js.
     */
    public static function areasPos(): array
    {
        $out = [];
        $db = Producto::getDB();
        $res = $db->query("SELECT id, nombre, slug, color FROM areas_produccion ORDER BY id ASC");

        if (!$res) {
            return [];
        }

        while ($row = $res->fetch_assoc()) {
            $out[$row['slug']] = [
                'id'    => (int) $row['id'],
                'label' => $row['nombre'],
                'color' => $row['color'],
            ];
        }

        $res->free();

        return $out;
    }
}
