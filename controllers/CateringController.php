<?php

/**
 * Endpoint público de cotizaciones de catering.
 *
 * El controlador sólo traduce HTTP; la validación y el freno de reenvíos viven
 * en Services\CateringService.
 */

namespace Controllers;

use MVC\Router;
use Services\CateringService;
use Services\ReservationClientSession;

class CateringController
{
    public static function cotizar(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }

        $entrada = self::entrada();

        if (!ReservationClientSession::validarCsrf((string)($entrada['csrf_token'] ?? ''))) {
            self::json([
                'ok' => false,
                'codigo' => 'CSRF_INVALIDO',
                'mensaje' => 'Tu sesión expiró. Recarga la página e inténtalo otra vez.',
            ], 403);
            return;
        }

        // Misma trampa que en catas: campo oculto que sólo un bot rellena.
        if (trim((string)($entrada['sitio_web'] ?? '')) !== '') {
            self::json(['ok' => true, 'mensaje' => 'Recibimos tu solicitud.']);
            return;
        }

        $respuesta = CateringService::registrarSolicitud($entrada);
        self::json($respuesta, self::status($respuesta, 201));
    }

    /** @return array<string, mixed> */
    private static function entrada(): array
    {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $data = json_decode((string)file_get_contents('php://input'), true);
            return is_array($data) ? $data : [];
        }

        return $_POST;
    }

    /** @param array<string, mixed> $respuesta */
    private static function status(array $respuesta, int $exito = 200): int
    {
        if ($respuesta['ok'] ?? false) {
            return $exito;
        }

        return match ((string)($respuesta['codigo'] ?? '')) {
            CateringService::NO_EXISTE => 404,
            CateringService::DEMASIADOS_ENVIOS => 429,
            CateringService::ERROR_GUARDADO => 500,
            default => 422,
        };
    }

    /** @param array<string, mixed> $respuesta */
    private static function json(array $respuesta, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
