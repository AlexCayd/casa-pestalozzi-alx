<?php

namespace Controllers;

use Model\CategoriasMenu;
use Model\ConfiguracionAnuncio;
use Services\Menu\Carta;
use Services\Menu\CataService;
use Services\Scheduling\HorarioOperacionService;
use Services\Security\ReservationClientSession;
use Services\Reservations\ReservacionService;

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
        $catasProximas = [];
        $cartaMaridaje = [];
        $especialesTemporada = [];
        $reservationRequestToken = ReservacionService::generarRequestToken();

        // La agenda de catas se imprime en el servidor: la sección tiene que
        // leerse aunque el bundle no llegue a cargar. Si la consulta falla, el
        // parcial cae solo a su estado vacío.
        try {
            $catasProximas = CataService::agendaPublica();
        } catch (\Throwable $e) {
            error_log('HomeController::index catas - ' . $e->getMessage());
        }

        // La carta de maridaje vive en la sección de Catas, impresa en el
        // servidor como la agenda: es lo que se busca al ver las copas, y tiene
        // que leerse aunque el bundle no llegue. Si falla, el bloque no sale y
        // queda el enlace al PDF.
        try {
            $cartaMaridaje = Carta::publica(CategoriasMenu::CARTA_MARIDAJE);
        } catch (\Throwable $e) {
            error_log('HomeController::index carta de maridaje - ' . $e->getMessage());
        }

        // Los especiales de temporada, también impresos en el servidor: la
        // vitrina se lee sin JS y sin esperar a ninguna petición. Si la consulta
        // falla, el parcial cae a su estado vacío en vez de tumbar la portada.
        try {
            $especialesTemporada = Carta::especiales();
        } catch (\Throwable $e) {
            error_log('HomeController::index especiales de temporada - ' . $e->getMessage());
        }

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
