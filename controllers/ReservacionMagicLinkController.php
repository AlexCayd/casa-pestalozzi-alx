<?php

namespace Controllers;

use MVC\Router;
use Services\ReservationClientSession;
use Services\ReservacionMagicLinkService;

/** Puente público que mantiene el token fuera de la navegación posterior. */
final class ReservacionMagicLinkController
{
    public static function show(Router $router): void
    {
        ReservationClientSession::start();
        self::headers();
        $publicId = trim((string)($_GET['id'] ?? ''));
        include __DIR__ . '/../views/reservaciones/acceso-cambio-horario.php';
    }

    public static function consume(Router $router): void
    {
        self::headers();
        $datos = self::entrada();
        if (!ReservationClientSession::validarCsrf((string)($datos['csrf_token'] ?? ''))) {
            self::redirectInvalid();
        }

        $resultado = ReservacionMagicLinkService::consumir(
            trim((string)($datos['public_id'] ?? '')),
            trim((string)($datos['token'] ?? ''))
        );
        if (!($resultado['ok'] ?? false)) {
            self::redirectInvalid();
        }

        header('Location: /reservaciones#reserva', true, 303);
        exit;
    }

    private static function entrada(): array
    {
        $datos = json_decode((string)file_get_contents('php://input'), true);

        return is_array($datos) ? $datos : $_POST;
    }

    private static function headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
    }

    private static function redirectInvalid(): void
    {
        header('Location: /reservaciones?cambio_horario=invalido#reserva', true, 303);
        exit;
    }
}
