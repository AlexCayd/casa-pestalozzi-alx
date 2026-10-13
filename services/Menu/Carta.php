<?php

namespace Services\Menu;

use Model\CategoriasMenu;
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
     *
     * $carta acota a una de las dos piezas impresas (`categorias.carta`) y
     * por defecto trae la de comida, que es lo que ya pintaban la landing y
     * el PDF antes de que existiera la de maridaje: quien no pide nada sigue
     * recibiendo lo mismo. Pasar null trae las dos — nadie lo hace hoy, pero
     * es lo que evita que un consumidor futuro invente su propio SQL.
     */
    public static function publica(?string $carta = CategoriasMenu::CARTA_COMIDA): array
    {
        $out = [];
        $db = Producto::getDB();
        $filtroCarta = '';

        if ($carta !== null) {
            // Un valor fuera del ENUM no se ignora: devolvería la carta entera
            // en una ruta que promete una sola pieza.
            $filtroCarta = " AND c.carta = '"
                . Producto::escaparString(CategoriasMenu::normalizarCarta($carta)) . "'";
        }

        $res = $db->query(
            "SELECT c.id AS cat_id, c.nombre AS cat_nombre, c.img AS cat_img,
                    p.id, p.nombre, p.descripcion, p.precio
               FROM productos p
               JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND c.activo = 1" . $filtroCarta . "
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
}
