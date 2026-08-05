<?php

namespace Controllers;

use MVC\Router;
use Services\ReservacionConfig;
use Services\ReservacionMantenimientoService;

final class ReservacionMantenimientoController
{
    public static function index(Router $router): void
    {
        self::protegerAmbiente();
        self::render([
            'pendientes' => ReservacionMantenimientoService::vistaPreviaPendientesVencidas(),
        ]);
    }

    public static function procesarPendientes(Router $router): void
    {
        self::protegerAmbiente();
        self::protegerPost();
        $resultado = ReservacionMantenimientoService::procesarPendientesVencidas(
            (string)($_POST['confirmar'] ?? '') === '1'
        );
        self::render([
            'pendientes' => ReservacionMantenimientoService::vistaPreviaPendientesVencidas(),
            'resultadoPendientes' => $resultado,
        ]);
    }

    private static function protegerAmbiente(): void
    {
        if (ReservacionConfig::appEnvironment() === 'development') {
            return;
        }

        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No encontrado.';
        exit;
    }

    private static function protegerPost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            return;
        }

        http_response_code(405);
        header('Allow: POST');
        exit;
    }

    private static function render(array $data): void
    {
        AdminController::render('reservations/development-tools', array_merge([
            'activeModule' => 'reservations',
            'title' => 'Herramientas de desarrollo',
            'topbarSection' => 'Reservaciones',
            'styles' => ['/build/css/admin/reservations.css?v=reservation-tools-v1'],
            'scripts' => [],
            'fechaActual' => ReservacionConfig::fechaActual(),
        ], $data));
    }
}
