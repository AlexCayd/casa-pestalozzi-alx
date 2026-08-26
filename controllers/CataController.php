<?php

/**
 * Endpoints públicos de catas.
 *
 * El controlador sólo traduce HTTP: la agenda, el cupo y el freno de reenvíos
 * viven en Services\CataService.
 */

namespace Controllers;

use MVC\Router;
use Services\CataService;
use Services\ReservationClientSession;

class CataController
{
    /** Agenda de próximas catas. La landing ya la trae impresa; esto es para refrescarla. */
    public static function proximas(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }

        $catas = array_map(static function (array $fila): array {
            return [
                'id' => (int)$fila['id'],
                'titulo' => (string)$fila['titulo'],
                'descripcion' => (string)($fila['descripcion'] ?? ''),
                'fecha' => (string)$fila['fecha'],
                'hora' => substr((string)$fila['hora'], 0, 5),
                'duracion_min' => (int)$fila['duracion_min'],
                'precio' => (float)$fila['precio'],
                'estado' => (string)$fila['estado'],
                'lugares_disponibles' => (int)$fila['lugares_disponibles'],
                'abierta' => (bool)$fila['abierta'],
            ];
        }, CataService::agendaPublica());

        self::json(['ok' => true, 'catas' => $catas]);
    }

    public static function inscribir(Router $router): void
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

        // Trampa para bots: el campo va oculto por CSS y una persona no lo llena.
        // Se responde como si todo hubiera ido bien para no dar pistas.
        if (trim((string)($entrada['sitio_web'] ?? '')) !== '') {
            self::json(['ok' => true, 'mensaje' => 'Listo, tu lugar quedó apartado.']);
            return;
        }

        $respuesta = CataService::inscribir($entrada);
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
            CataService::NO_EXISTE => 404,
            CataService::CERRADA, CataService::SIN_CUPO => 409,
            CataService::DEMASIADOS_ENVIOS => 429,
            CataService::ERROR_GUARDADO => 500,
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
