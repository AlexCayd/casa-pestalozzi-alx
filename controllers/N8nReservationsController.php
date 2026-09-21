<?php

namespace Controllers;

use MVC\Router;
use Services\Notifications\NotificationConfig;
use Services\Reservations\ReservationNotificationResultService;
use Services\Reservations\ReservationReminderService;

/** Endpoints machine-to-machine autenticados para el workflow de reservaciones. */
final class N8nReservationsController
{
    public static function prepararRecordatorios(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        if (!self::secretValido()) {
            self::json(['ok' => false, 'codigo' => 'N8N_SECRET_INVALIDO'], 403);
            return;
        }
        self::json(ReservationReminderService::preparar());
    }

    public static function notificacionResultado(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        if (!self::secretValido()) {
            self::json(['ok' => false, 'codigo' => 'N8N_SECRET_INVALIDO'], 403);
            return;
        }
        $datos = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($datos)) {
            self::json(['ok' => false, 'codigo' => 'NOTIFICACION_CALLBACK_INVALIDO'], 422);
            return;
        }
        $resultado = ReservationNotificationResultService::registrar(
            trim((string)($datos['event'] ?? '')),
            (int)($datos['source_id'] ?? 0),
            (int)($datos['attempt'] ?? 0),
            trim((string)($datos['channel'] ?? '')),
            trim((string)($datos['status'] ?? '')),
            ($datos['retryable'] ?? false) === true
        );
        $status = ($resultado['ok'] ?? false)
            ? 200
            : (($resultado['codigo'] ?? '') === 'NOTIFICACION_SOURCE_NO_ENCONTRADO' ? 404 : 422);
        self::json($resultado, $status);
    }

    public static function reclamarRecordatorio(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        if (!self::secretValido()) {
            self::json(['ok' => false, 'codigo' => 'N8N_SECRET_INVALIDO'], 403);
            return;
        }
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            self::json(['ok' => false, 'codigo' => 'NOTIFICACION_CALLBACK_INVALIDO', 'claimed' => false], 422);
            return;
        }
        $result = ReservationReminderService::reclamar((int)($data['source_id'] ?? 0),
            (int)($data['attempt'] ?? 0), (string)($data['channel'] ?? ''));
        self::json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    private static function secretValido(): bool
    {
        $esperado = NotificationConfig::n8nCallbackSecret();
        $recibido = $_SERVER['HTTP_X_N8N_CALLBACK_SECRET'] ?? '';
        return trim($esperado) !== ''
            && is_string($recibido)
            && trim($recibido) !== ''
            && hash_equals(trim($esperado), trim($recibido));
    }

    private static function json(array $resultado, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
