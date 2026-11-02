<?php

namespace Controllers;

use MVC\Router;

/**
 * Alias de la página heredada /reservaciones/cambio-horario, a la que llevan
 * los enlaces ya enviados a clientes. No mantiene una segunda implementación:
 * la página es la de gestión y llama a las APIs de /api/reservaciones/gestionar.
 */
final class ScheduleChangeAccessController
{
    public static function show(Router $router): void
    {
        ReservationManagementAccessController::show($router);
    }
}
