<?php

/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  PANADERÍA — la landing /panaderia.                           │
 * └───────────────────────────────────────────────────────────────┘
 *
 * La página de la panadería de Calle San Borja 1026. Es la otra casa de la
 * marca y vive aparte del restaurante en cada capa: controllers/Panaderia,
 * services/Panaderia, views/panaderia, src/scss/panaderia y src/js/panaderia.
 * Lo que comparte con la portada del restaurante (menú, pie, mapa, visor,
 * cursor, medallón) está en views/components/marca/. Ver «Las dos landings»
 * en CLAUDE.md.
 */

namespace Controllers\Panaderia;

use Services\Panaderia\PanaderiaCatalogo;
use Services\Panaderia\PanaderiaConfig;
use Services\Panaderia\Temporadas;

class PanaderiaController
{
    public static function index($router)
    {
        // La línea de «hoy» y la pieza de temporada cambian con el reloj: la
        // página no se guarda en caché, igual que la portada.
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');

        $ahora = PanaderiaConfig::ahora();
        $vitrina = [];
        $enLaCarta = [];

        // Cada consulta en su try: si una falla, su banda cae a su estado
        // vacío y el resto de la página sale igual.
        try {
            $vitrina = PanaderiaCatalogo::vitrina();
        } catch (\Throwable $e) {
            error_log('PanaderiaController::index vitrina - ' . $e->getMessage());
        }

        try {
            $enLaCarta = PanaderiaCatalogo::enLaCarta();
        } catch (\Throwable $e) {
            error_log('PanaderiaController::index en la carta - ' . $e->getMessage());
        }

        $temporada = Temporadas::paraFecha($ahora);
        $semana = PanaderiaConfig::semana($ahora);
        $lineaDeHoy = PanaderiaConfig::lineaDeHoy($ahora);

        // La landing tiene su propio shell HTML, como la portada.
        include_once __DIR__ . '/../../views/panaderia/index.php';
    }
}
