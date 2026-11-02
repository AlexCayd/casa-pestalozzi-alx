<?php

/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  PANADERÍA — sólo la landing /panaderia lee esto.             │
 * └───────────────────────────────────────────────────────────────┘
 *
 * Lo que la landing de la panadería lee del catálogo. Con el esquema
 * multinegocio la Panadería es un negocio con su propio menú (se administra en
 * /admin/panaderia/menu), y la vitrina es su carta de comida. Las dos lecturas
 * pasan por aquí para que la capa de la panadería tenga un solo punto de
 * contacto con la base.
 */

namespace Services\Panaderia;

use Model\CategoriasMenu;
use Services\Menu\Carta;
use Services\Menu\CartaMultinegocio;
use Services\Security\ContextoNegocio;

class PanaderiaCatalogo
{
    /**
     * La vitrina: las categorías del menú de la Panadería con sus piezas
     * activas, en la forma de Carta::publica():
     *   [{ id, label, img, dishes: [{ n, d, p, img }] }]
     * Una categoría sin piezas no se enseña: sería una pestaña vacía. El
     * esquema legado no tiene menú de la Panadería, así que ahí sale vacía y
     * la vitrina cae a su línea con WhatsApp.
     */
    public static function vitrina(): array
    {
        try {
            if (!ContextoNegocio::nuevo()) {
                return [];
            }
            $carta = CartaMultinegocio::publicaDe('panaderia', CategoriasMenu::CARTA_COMIDA);
        } catch (\Throwable $e) {
            error_log('PanaderiaCatalogo::vitrina - ' . $e->getMessage());
            return [];
        }

        return array_values(array_filter(
            $carta,
            static fn (array $categoria): bool => !empty($categoria['dishes'])
        ));
    }

    /**
     * Los platillos del restaurante de PanaderiaConfig::EN_LA_CARTA que siguen
     * activos, en el orden de esa lista: [['nombre' => …, 'pan' => …], …].
     * Casa por nombre contra la carta pública del Restaurante (todas sus
     * cartas), que sirve igual en el esquema legado y en el multinegocio: en
     * éste `productos` ya no dice si algo se vende, lo dice su oferta.
     */
    public static function enLaCarta(): array
    {
        if (PanaderiaConfig::EN_LA_CARTA === []) {
            return [];
        }

        $activos = [];
        try {
            foreach (Carta::publica(null) as $categoria) {
                foreach ($categoria['dishes'] as $platillo) {
                    $activos[(string) $platillo['n']] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('PanaderiaCatalogo::enLaCarta - ' . $e->getMessage());
            return [];
        }

        $out = [];
        foreach (PanaderiaConfig::EN_LA_CARTA as $nombre => $pan) {
            if (isset($activos[$nombre])) {
                $out[] = ['nombre' => $nombre, 'pan' => $pan];
            }
        }

        return $out;
    }
}
