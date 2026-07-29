<?php

namespace Controllers;

use Model\ConfiguracionAnuncio;
use Services\HorarioOperacionService;
use Services\ReservationClientSession;
use Services\ReservacionService;

class HomeController
{
    public static function index($router)
    {
        ReservationClientSession::start();
        $reservationCsrfToken = ReservationClientSession::csrfToken();
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        $horariosOperacion = [];
        $proximasExcepcionesOperacion = [];
        $horariosOperacionDisponibles = false;
        $anuncioPublico = null;
        $reservationRequestToken = ReservacionService::generarRequestToken();

        try {
            $anuncioPublico = ConfiguracionAnuncio::obtenerVisible();
        } catch (\Throwable $e) {
            error_log('HomeController::index anuncio publico - ' . $e->getMessage());
        }

        try {
            $horariosOperacion = HorarioOperacionService::obtenerHorarioSemanalPublico();
            $proximasExcepcionesOperacion = HorarioOperacionService::obtenerProximasExcepciones(null);
            $horariosOperacionDisponibles = true;
        } catch (\Throwable $e) {
            error_log('HomeController::index horario publico - ' . $e->getMessage());
        }

        // La home tiene su propio shell HTML (no usa views/layout.php de auth)
        include_once __DIR__ . '/../views/home/index.php';
    }
}
