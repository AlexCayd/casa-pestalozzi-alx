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
     *   [{ id, label, img, dishes: [{ n, d, p, img }] }]
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
                    p.id, p.nombre, p.descripcion, p.img, p.precio
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
                // Sin respaldo a la de la categoría: quien la necesite la
                // resuelve (Carta::especiales()).
                'img'  => $row['img'] ? '/' . ltrim($row['img'], '/') : null,
            ];
        }

        $res->free();

        return array_values($out);
    }

    /**
     * Especiales de temporada para la vitrina de la landing
     * (views/home/_firma.php): los platillos activos de las categorías con
     * carta = 'temporada', en una lista plana y en el orden de alta.
     *   [{ n, d, p, cat, img, propia }]
     *
     * Plana porque la vitrina no agrupa: todos son «especial de la semana».
     *
     * La foto es opcional en el panel —los especiales cambian cada semana y no
     * siempre hay foto del platillo—, y aquí sí se resuelve el respaldo, al
     * revés que en publica(): la vitrina sólo sabe pintar tarjetas de
     * fotografía, así que sin foto propia el especial toma la de su categoría
     * (seed.sql siembra una para «Especiales de temporada», y se cambia en
     * Menú → Categorías). `propia` dice cuál de las dos es.
     *
     * Un especial sin foto propia NI de categoría se queda fuera: una tarjeta
     * de vitrina sin imagen es un marco vacío con el nombre en tinta clara
     * sobre papel claro. El formulario del platillo lo advierte.
     */
    public static function especiales(): array
    {
        $out = [];

        foreach (self::publica(CategoriasMenu::CARTA_TEMPORADA) as $categoria) {
            foreach ($categoria['dishes'] as $dish) {
                $img = $dish['img'] ?: $categoria['img'];
                if ($img === null) {
                    continue;
                }

                $out[] = [
                    'n'      => $dish['n'],
                    'd'      => $dish['d'],
                    'p'      => $dish['p'],
                    // La categoría del platillo: es lo que la vitrina pinta en
                    // la línea chica sobre el nombre, como hacía el arreglo fijo.
                    'cat'    => $categoria['label'],
                    'img'    => $img,
                    'propia' => $dish['img'] !== null,
                ];
            }
        }

        return $out;
    }
}
