<?php

namespace Controllers;

use MVC\Router;

/** Alias de rutas heredadas; no mantiene una segunda implementación. */
final class ScheduleChangeAccessController
{
    public static function show(Router $router): void
    {
        ReservationManagementAccessController::show($router);
    }

    public static function disponibilidad(Router $router): void
    {
        ReservationManagementAccessController::disponibilidad($router);
    }

    public static function modificar(Router $router): void
    {
        ReservationManagementAccessController::modificar($router);
    }

    public static function cancelar(Router $router): void
    {
        ReservationManagementAccessController::cancelar($router);
    }
}
