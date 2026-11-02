<?php

/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  PANADERÍA — sólo la landing /panaderia lee esto.             │
 * └───────────────────────────────────────────────────────────────┘
 *
 * Lo que la landing de la panadería lee del catálogo compartido (`productos`
 * y `categorias`, que administra Menú). Las dos consultas pasan por aquí para
 * que la capa de la panadería tenga un solo punto de contacto con la base.
 */

namespace Services\Panaderia;

use Model\CategoriasMenu;
use Model\Producto;
use Services\Menu\Carta;

class PanaderiaCatalogo
{
    /**
     * La vitrina: las categorías de la carta «Panadería» con sus piezas
     * activas, en la forma de Carta::publica():
     *   [{ id, label, img, dishes: [{ n, d, p, img }] }]
     * Una categoría sin piezas no se enseña: sería una pestaña vacía.
     */
    public static function vitrina(): array
    {
        return array_values(array_filter(
            Carta::publica(CategoriasMenu::CARTA_PANADERIA),
            static fn (array $categoria): bool => !empty($categoria['dishes'])
        ));
    }

    /**
     * Los platillos del restaurante de PanaderiaConfig::EN_LA_CARTA que siguen
     * activos, en el orden de esa lista: [['nombre' => …, 'pan' => …], …].
     * Casa por nombre, que es UNIQUE en `productos`.
     */
    public static function enLaCarta(): array
    {
        $nombres = array_keys(PanaderiaConfig::EN_LA_CARTA);
        if ($nombres === []) {
            return [];
        }

        $lista = implode(',', array_map(
            static fn (string $nombre): string => "'" . Producto::escaparString($nombre) . "'",
            $nombres
        ));
        $res = Producto::getDB()->query(
            "SELECT p.nombre
               FROM productos p
               JOIN categorias c ON c.id = p.categoria_id
              WHERE p.activo = 1 AND c.activo = 1 AND p.nombre IN ({$lista})"
        );
        if (!$res) {
            return [];
        }

        $activos = [];
        while ($fila = $res->fetch_assoc()) {
            $activos[$fila['nombre']] = true;
        }
        $res->free();

        $out = [];
        foreach (PanaderiaConfig::EN_LA_CARTA as $nombre => $pan) {
            if (isset($activos[$nombre])) {
                $out[] = ['nombre' => $nombre, 'pan' => $pan];
            }
        }

        return $out;
    }
}
