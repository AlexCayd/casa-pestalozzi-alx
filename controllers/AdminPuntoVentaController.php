<?php

namespace Controllers;

use MVC\Router;

/**
 * El Punto de Venta vive en /punto-de-venta (vista standalone del POS). Este
 * modulo del admin solo publica la tarjeta que lo lanza, por eso no tiene API
 * propia: el POS habla directo con PuntoVentaController via /api/*.
 */
class AdminPuntoVentaController
{
    public static function index(Router $router): void
    {
        AdminController::render('punto-de-venta/index', [
            'activeModule' => 'pdv',
            'title' => 'Punto de Venta',
            'topbarTitle' => 'Punto de Venta',
            'topbarSection' => 'Punto de Venta',
            'styles' => [],
            'scripts' => []
        ]);
    }
}
