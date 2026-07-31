<?php

namespace Controllers;

use Services\Carta;
use Services\MenuPdf;

/**
 * Carta pública: el JSON que consume la landing y el PDF descargable.
 * La fuente es `productos` (ver Services\Carta).
 */
class MenuController {

    /** GET /menu — JSON que pinta la sección de menú de la landing. */
    public static function index($router) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(Carta::publica(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** GET /menu/pdf — carta en PDF para el comensal. */
    public static function pdf($router) {
        MenuPdf::stream();
    }
}
