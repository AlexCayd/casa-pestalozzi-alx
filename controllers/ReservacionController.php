<?php

/**
 * Atiende endpoints publicos de reservaciones y delega reglas al servicio.
 */

namespace Controllers;

use MVC\Router;
use Services\ReservacionService;

class ReservacionController
{
    public static function horarios(Router $router): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            echo json_encode([
                'ok' => false,
                'mensaje' => 'Método no permitido.',
                'horarios' => [],
            ], JSON_UNESCAPED_UNICODE);
            return;
        }

        // La ausencia de fecha conserva el comportamiento previo: respuesta de validación 422.
        $fecha = (string)($_GET['fecha'] ?? '');
        $respuesta = ReservacionService::obtenerHorariosDisponiblesParaFecha($fecha);

        if (!($respuesta['ok'] ?? false)) {
            http_response_code(($respuesta['codigo'] ?? '') === ReservacionService::ERROR_INTERNO ? 500 : 422);
        }

        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    }

    public static function crear(Router $router): void
    {
        header('Content-Type: application/json; charset=utf-8');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'msg' => 'Metodo no permitido', 'errors' => []]);
            return;
        }

        $respuesta = ReservacionService::crearPublica($_POST);

        if (!($respuesta['ok'] ?? false)) {
            http_response_code(($respuesta['codigo'] ?? '') === ReservacionService::ERROR_INTERNO ? 500 : 422);
        }

        if (array_key_exists('warning', $respuesta) && $respuesta['warning'] === null) {
            unset($respuesta['warning']);
        }

        echo json_encode($respuesta);
    }
}
