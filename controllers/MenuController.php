<?php

namespace Controllers;

use Model\CategoriasMenu;
use Services\Menu\Carta;
use Services\Menu\MenuPdf;

/**
 * Carta pública: el JSON que consume la landing y los dos PDF descargables.
 * La fuente es `productos` (ver Services\Menu\Carta).
 */
class MenuController {

    /** GET /menu — JSON que pinta la sección de menú de la landing. */
    public static function index($router) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(Carta::publica(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** GET /menu/pdf — carta de comida en PDF para el comensal. */
    public static function pdf($router) {
        MenuPdf::stream(CategoriasMenu::CARTA_COMIDA);
    }

    /**
     * GET /maridaje/pdf — carta de maridaje (barra) en PDF.
     *
     * Ruta propia y no un ?carta= sobre la anterior: es el enlace que la
     * landing pone en un botón, y un parámetro invita a escribir a mano uno
     * que no existe. Con dos rutas, lo que no está declarado es 404.
     */
    public static function pdfMaridaje($router) {
        MenuPdf::stream(CategoriasMenu::CARTA_MARIDAJE);
    }
}
