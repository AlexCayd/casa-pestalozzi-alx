<?php

namespace Controllers;

use MVC\Router;
use Services\Security\AdminCsrfService;
use Services\Notifications\BuzonNotificacionesService;
use Services\Reservations\ReservacionBuzonBandejaService;
use Services\Reservations\ReservacionBuzonService;

/**
 * API ligera del buzón flotante administrativo.
 *
 * Los tres endpoints que devuelven números —resumen, sincronizar y la lista—
 * los sacan de ReservacionBuzonBandejaService, que cuenta las TARJETAS que el
 * cajón pinta. Contar filas de `buzon_notificaciones` es lo que dejaba el
 * contador en «1» con la pestaña «Por atender» vacía.
 */
final class AdminBuzonController
{
    public static function resumen(Router $router): void
    {
        self::json(array_merge(['ok' => true, 'codigo' => 'BUZON_RESUMEN'], ReservacionBuzonBandejaService::resumen()));
    }

    public static function sincronizar(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }

        // Primero las ausencias vencidas: así el buzón no abre un aviso de
        // «no llegó» para algo que ya quedó registrado.
        \Services\Reservations\ReservacionAusenciaAutomaticaService::procesarEnLectura();
        $resultado = ReservacionBuzonService::sincronizarPendientesTemporales();
        self::json(array_merge(
            ['ok' => true, 'codigo' => 'BUZON_SINCRONIZADO'],
            $resultado['resumen'],
            ['sincronizacion' => [
                'procesadas' => $resultado['procesadas'],
                'avisos_creados' => $resultado['avisos_creados'],
                'avisos_cerrados' => $resultado['avisos_cerrados'],
            ]]
        ));
    }

    public static function listar(Router $router): void
    {
        // La lista y sus números salen de la MISMA construcción: lo que el
        // cajón pinta es exactamente lo que el contador cuenta.
        self::json(array_merge(
            ['ok' => true, 'codigo' => 'BUZON_LISTA'],
            ReservacionBuzonBandejaService::construir()
        ));
    }

    public static function marcarLeida(Router $router): void
    {
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }
        $ok = BuzonNotificacionesService::marcarLeida((int)($datos['id'] ?? 0));
        self::json([
            'ok' => $ok,
            'codigo' => $ok ? 'BUZON_MARCADO_LEIDO' : 'BUZON_AVISO_NO_ENCONTRADO',
        ], $ok ? 200 : 404);
    }

    private static function entrada(): array
    {
        $json = json_decode((string)file_get_contents('php://input'), true);
        return is_array($json) ? $json : $_POST;
    }

    private static function csrfValido(array $datos): bool
    {
        return AdminCsrfService::validar(isset($datos['admin_csrf']) ? (string)$datos['admin_csrf'] : null);
    }

    private static function usuarioId(): ?int
    {
        $id = filter_var($_SESSION['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return $id ? (int)$id : null;
    }

    private static function json(array $resultado, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
