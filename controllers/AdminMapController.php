<?php

namespace Controllers;

use MVC\Router;

class AdminMapController
{
    private const MAP_CSS = '/build/css/admin/map.css';
    private const MAP_JS = '/build/js/admin/map.js';
    private const QR_JS = 'https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js';

    public static function index(Router $router): void
    {
        AdminController::render('map/index', [
            'activeModule' => 'map',
            'title' => 'Mapa / Mesas',
            'topbarTitle' => 'Mapa operativo',
            'topbarSection' => 'Mapa operativo',
            'compactTopbar' => true,
            'styles' => [
                self::MAP_CSS
            ],
            'scripts' => [
                self::QR_JS,
                self::MAP_JS
            ]
        ]);
    }

    public static function map(Router $router): void
    {
        MapaController::api($router);
    }

    public static function openTicket(Router $router): void
    {
        MapaController::abrirTicket($router);
    }

    public static function releaseReservation(Router $router): void
    {
        MapaController::liberarReservacion($router);
    }

    public static function closeTicket(Router $router): void
    {
        MapaController::cerrarTicket($router);
    }

    public static function sendOrder(Router $router): void
    {
        MapaController::enviarComanda($router);
    }

    public static function ticketItems(Router $router): void
    {
        MapaController::ticketItems($router);
    }

    public static function deliverItem(Router $router): void
    {
        MapaController::entregarItem($router);
    }

    public static function updateTicket(Router $router): void
    {
        MapaController::actualizarTicket($router);
    }

}
