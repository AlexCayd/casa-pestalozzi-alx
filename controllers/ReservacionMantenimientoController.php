<?php

namespace Controllers;

use Classes\Auth;
use MVC\Router;
use Services\AdminCsrfService;
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
        if (!Auth::esAdmin()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'No autorizado.';
            exit;
        }
        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            http_response_code(419);
            self::render([
                'pendientes' => ReservacionMantenimientoService::vistaPreviaPendientesVencidas(),
                'resultadoPendientes' => [
                    'ok' => false,
                    'codigo' => 'CSRF_INVALIDO',
                    'procesadas' => 0,
                    'omitidas' => 0,
                    'fallidas' => 0,
                ],
            ]);
            return;
        }
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
            'adminCsrfToken' => AdminCsrfService::token(),
        ], $data));
    }
}
