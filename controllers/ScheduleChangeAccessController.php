<?php

namespace Controllers;

use MVC\Router;
use Services\DisponibilidadReservacionService;
use Services\ReservacionErrorCatalog;
use Services\ReservacionPublicaService;
use Services\ScheduleChangeAccessService;
use Services\ScheduleChangeAccessSession;

/** Superficie pública independiente para una reservación afectada. */
final class ScheduleChangeAccessController
{
    public static function show(Router $router): void
    {
        self::headers();
        $access = trim((string)($_GET['access'] ?? ''));
        if ($access !== '') {
            if (ScheduleChangeAccessService::intercambiarToken($access)) {
                self::redirect('/reservaciones/cambio-horario', 303);
                return;
            }
            self::render(null);
            return;
        }

        self::render(ScheduleChangeAccessService::formulario());
    }

    public static function disponibilidad(Router $router): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        $datos = self::entrada();
        $contexto = self::contextoAutorizado($datos, $_SERVER['REQUEST_METHOD'] !== 'GET');
        if (!$contexto) {
            return;
        }
        $formulario = ScheduleChangeAccessService::formulario();
        if (!$formulario) {
            self::json(['ok' => false, 'codigo' => 'ACCESO_CAMBIO_HORARIO_EXPIRADO'], 410);
            return;
        }
        $resultado = DisponibilidadReservacionService::consultar(
            trim((string)($datos['fecha'] ?? '')),
            $datos['personas'] ?? $datos['comensales'] ?? null,
            (int)$contexto['reservacion_id'],
            isset($datos['hora']) ? (string)$datos['hora'] : null,
            [
                'fecha' => $formulario['fecha'],
                'hora' => $formulario['hora'],
            ]
        );
        self::json($resultado);
    }

    public static function modificar(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        $datos = self::entrada();
        $contexto = self::contextoAutorizado($datos);
        if (!$contexto) {
            return;
        }
        $resultado = ReservacionPublicaService::crearReemplazoAutorizado($datos, $contexto);
        if (($resultado['ok'] ?? false) === true) {
            ScheduleChangeAccessSession::limpiar();
        }
        self::json($resultado);
    }

    private static function contextoAutorizado(array $datos, bool $requiereCsrf = true): ?array
    {
        if ($requiereCsrf && !ScheduleChangeAccessSession::validarCsrf((string)($datos['csrf_token'] ?? ''))) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return null;
        }
        $contexto = ScheduleChangeAccessService::contextoActual();
        if (!$contexto) {
            self::json(['ok' => false, 'codigo' => 'ACCESO_CAMBIO_HORARIO_EXPIRADO'], 410);
            return null;
        }
        return $contexto;
    }

    private static function render(?array $formulario): void
    {
        include __DIR__ . '/../views/reservaciones/cambio-horario.php';
    }

    private static function entrada(): array
    {
        $json = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($json)) {
            return $json;
        }
        return $_SERVER['REQUEST_METHOD'] === 'GET' ? $_GET : $_POST;
    }

    private static function json(array $resultado, int $status = 200): void
    {
        $codigo = (string)($resultado['codigo'] ?? '');
        if (ReservacionErrorCatalog::has($codigo)) {
            $resultado = ReservacionErrorCatalog::enriquecer($resultado, ['superficie' => 'publica']);
            if (($resultado['ok'] ?? false) === false && $status === 200) {
                $status = ReservacionErrorCatalog::httpStatus($codigo, 422);
            }
        }
        http_response_code($status);
        self::headers();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function headers(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
    }

    private static function redirect(string $url, int $status): void
    {
        http_response_code($status);
        header('Location: ' . $url);
    }
}
