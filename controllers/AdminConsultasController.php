<?php

namespace Controllers;

use MVC\Router;
use Services\Analytics\ConsultasRapidas;

/**
 * Consultas rápidas del panel (la barra de Tickets, Análisis, Menú e
 * Inventario). Sólo lectura: GET y JSON. /admin/* ya exige rol admin por
 * prefijo en Auth::proteger(), así que no hay guardia propia que añadir.
 */
class AdminConsultasController
{
    /** GET /admin/api/consultas?q=&ambito=ventas|productos|todo&periodo= */
    public static function consultar(Router $router): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        $q = is_string($_GET['q'] ?? null) ? (string) $_GET['q'] : '';
        $ambito = is_string($_GET['ambito'] ?? null) ? (string) $_GET['ambito'] : 'todo';
        $periodo = is_string($_GET['periodo'] ?? null) && $_GET['periodo'] !== '' ? (string) $_GET['periodo'] : null;

        try {
            echo json_encode(
                ConsultasRapidas::responder($q, $ambito, $periodo) + ['periodos' => ConsultasRapidas::PERIODOS],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        } catch (\Throwable $e) {
            error_log('AdminConsultasController::consultar - ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'mensaje' => 'No fue posible responder la consulta.'], JSON_UNESCAPED_UNICODE);
        }
    }
}
