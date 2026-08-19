<?php

namespace Controllers;

use MVC\Router;
use Services\AdminCsrfService;
use Services\HorarioOperacionImpactoService;
use Services\ReservacionErrorCatalog;

/** Endpoints administrativos del seguimiento de cambios de horario. */
final class AdminHorarioImpactoController
{
    public static function show(Router $router): void
    {
        $impactoId = (int)($_GET['impacto_id'] ?? $_GET['id'] ?? 0);
        $impacto = $impactoId > 0 ? HorarioOperacionImpactoService::obtener($impactoId) : null;
        self::json($impacto
            ? ['ok' => true, 'codigo' => 'OK', 'impacto' => $impacto]
            : ['ok' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA']);
    }

    public static function notify(Router $router): void
    {
        if (!self::csrfValido()) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }

        $datos = self::entrada();
        self::json(HorarioOperacionImpactoService::prepararAviso(
            (int)($datos['impacto_id'] ?? 0),
            (int)($datos['impacto_reservacion_id'] ?? 0),
            self::usuarioId()
        ));
    }

    public static function notifyAvailable(Router $router): void
    {
        if (!self::csrfValido()) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }

        $datos = self::entrada();
        self::json(HorarioOperacionImpactoService::prepararAvisosDisponibles(
            (int)($datos['impacto_id'] ?? 0),
            self::usuarioId()
        ));
    }

    public static function addContact(Router $router): void
    {
        if (!self::csrfValido()) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }

        $datos = self::entrada();
        self::json(HorarioOperacionImpactoService::agregarContacto(
            (int)($datos['impacto_id'] ?? 0),
            (int)($datos['impacto_reservacion_id'] ?? 0),
            trim((string)($datos['tipo'] ?? '')),
            trim((string)($datos['contacto'] ?? '')),
            self::usuarioId()
        ));
    }

    public static function attendManual(Router $router): void
    {
        if (!self::csrfValido()) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }

        $datos = self::entrada();
        self::json(HorarioOperacionImpactoService::atenderManual(
            (int)($datos['impacto_id'] ?? 0),
            (int)($datos['impacto_reservacion_id'] ?? 0),
            self::usuarioId(),
            trim((string)($datos['cierre_motivo'] ?? 'mantener_reservacion'))
        ));
    }

    public static function testLink(Router $router): void
    {
        if (!self::csrfValido()) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }

        $datos = self::entrada();
        self::json(HorarioOperacionImpactoService::regenerarAccesoDePrueba(
            (int)($datos['impacto_id'] ?? 0),
            (int)($datos['impacto_reservacion_id'] ?? 0),
            self::usuarioId()
        ));
    }

    private static function entrada(): array
    {
        $json = json_decode((string)file_get_contents('php://input'), true);

        return is_array($json) ? $json : $_POST;
    }

    private static function csrfValido(): bool
    {
        $datos = self::entrada();

        return AdminCsrfService::validar(isset($datos['admin_csrf']) ? (string)$datos['admin_csrf'] : null);
    }

    private static function usuarioId(): ?int
    {
        $id = filter_var($_SESSION['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $id ? (int)$id : null;
    }

    private static function json(array $resultado, ?int $status = null): void
    {
        $codigo = (string)($resultado['codigo'] ?? '');
        if ($codigo !== '' && ReservacionErrorCatalog::has($codigo)) {
            $resultado = ReservacionErrorCatalog::enriquecer($resultado, [
                'superficie' => 'administracion',
            ]);
        }
        if ($status === null) {
            $status = ($resultado['ok'] ?? false)
                ? 200
                : ReservacionErrorCatalog::httpStatus($codigo, 422);
        }

        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
