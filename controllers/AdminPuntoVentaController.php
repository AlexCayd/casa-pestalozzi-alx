<?php

namespace Controllers;

use MVC\Router;

/**
 * El Punto de Venta vive en /punto-de-venta (vista standalone del POS). Este
 * modulo del admin publica UNICAMENTE la tarjeta que lo lanza, por eso no tiene
 * API propia ni consulta la base: el POS habla directo con PuntoVentaController
 * via /api/*.
 *
 * Aqui vivia tambien una "Lista estructurada de mesas" con su lectura de
 * PosReservacionQueryService. Se retiro con la tabla: era una foto del piso al
 * cargar la pagina, sin refresco, y el mismo estado en vivo esta a un clic en
 * el mapa del POS.
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
            'styles' => ['/build/css/admin/punto-de-venta.css'],
            'scripts' => [],
        ]);
    }
}
