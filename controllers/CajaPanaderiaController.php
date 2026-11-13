<?php
declare(strict_types=1);

namespace Controllers;

use Classes\Auth;
use Classes\TicketPrinter;
use MVC\Router;
use Services\Pos\CajaMostrador;
use Services\Security\StaffCsrfService;

/**
 * Caja de mostrador de Panadería (/panaderia/caja). Coordina HTTP, CSRF y la
 * forma de las respuestas; las reglas viven en Services\Pos\CajaMostrador.
 * La guardia central ya resolvió sesión, ámbito y módulo antes de llegar aquí.
 */
final class CajaPanaderiaController
{
    public static function index(Router $router): void
    {
        $estado = CajaMostrador::estado();
        if (empty($estado['ok'])) {
            header('Location: ' . Auth::destinoPorRol());
            exit;
        }
        $estadoJson = json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        include __DIR__ . '/../views/caja/index.php';
    }

    /** GET: estado completo, para recargar tras un cambio de carta. */
    public static function estado(Router $router): void
    {
        Auth::liberarSesion();
        self::json(CajaMostrador::estado());
    }

    /** GET: sólo saldos de vitrina, para refrescar las teclas. */
    public static function vitrina(Router $router): void
    {
        Auth::liberarSesion();
        self::json(CajaMostrador::vitrina());
    }

    public static function vender(Router $router): void
    {
        $d = self::entrada();
        if (!self::csrf($d)) return;
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $pago = is_array($d['pago'] ?? null) ? $d['pago'] : [];
        self::json(CajaMostrador::vender($items, $pago, is_string($d['clave'] ?? null) ? $d['clave'] : ''));
    }

    public static function horneada(Router $router): void
    {
        $d = self::entrada();
        if (!self::csrf($d)) return;
        $lineas = is_array($d['lineas'] ?? null) ? $d['lineas'] : [];
        self::json(CajaMostrador::hornear($lineas, is_string($d['clave'] ?? null) ? $d['clave'] : ''));
    }

    public static function tickets(Router $router): void
    {
        Auth::liberarSesion();
        self::json(CajaMostrador::ticketsDeHoy());
    }

    public static function anular(Router $router): void
    {
        $d = self::entrada();
        if (!self::csrf($d)) return;
        // Escribe el contador de intentos de NIP en sesión: no liberar antes.
        self::json(CajaMostrador::anular(
            (int)($d['ticket_id'] ?? 0),
            is_string($d['motivo'] ?? null) ? $d['motivo'] : '',
            is_string($d['nota'] ?? null) ? $d['nota'] : null,
            is_string($d['clave'] ?? null) ? $d['clave'] : '',
            is_string($d['nip'] ?? null) ? $d['nip'] : null
        ));
    }

    public static function corte(Router $router): void
    {
        Auth::liberarSesion();
        $fecha = is_string($_GET['fecha'] ?? null) ? $_GET['fecha'] : null;
        $turno = is_string($_GET['turno'] ?? null) ? $_GET['turno'] : null;
        self::json(CajaMostrador::corte($fecha, $turno));
    }

    /** POST: imprime el comprobante. Un fallo de papel no toca la venta. */
    public static function imprimir(Router $router): void
    {
        $d = self::entrada();
        if (!self::csrf($d)) return;
        $comprobante = CajaMostrador::comprobante((int)($d['ticket_id'] ?? 0));
        if (empty($comprobante['ok'])) { self::json($comprobante); return; }
        $impreso = TicketPrinter::imprimirTicketCaja($comprobante['ticket']);
        self::json($impreso['ok']
            ? ['ok' => true, 'confirmado' => true, 'impreso' => true]
            : ['ok' => false, 'codigo' => $impreso['codigo'], 'mensaje' => $impreso['mensaje']]);
    }

    private static function entrada(): array
    {
        $d = json_decode((string)file_get_contents('php://input'), true);
        return is_array($d) ? $d : $_POST;
    }

    private static function csrf(array $d): bool
    {
        if (StaffCsrfService::validarRequest($d)) return true;
        http_response_code(419);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'codigo' => 'CSRF_INVALIDO', 'mensaje' => 'La sesión de la caja venció. Recarga la pantalla.'], JSON_UNESCAPED_UNICODE);
        return false;
    }

    private static function json(array $r): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (empty($r['ok'])) {
            $codigo = (string)($r['codigo'] ?? 'OPERACION_CAJA_FALLIDA');
            $status = match (true) {
                in_array($codigo, ['PERMISO_DENEGADO', 'AMBITO_DENEGADO', 'NIVEL_AUTH_REQUERIDO', 'NO_AUTORIZADO'], true) => 403,
                in_array($codigo, ['CONFLICTO_IDEMPOTENCIA', 'OPERACION_EN_CURSO', 'CONCURRENCIA_CONFLICTO', 'TICKET_NO_ANULABLE'], true) => 409,
                $codigo === 'DEMASIADOS_INTENTOS' => 429,
                in_array($codigo, ['OPERACION_CAJA_FALLIDA', 'ERROR_INVENTARIO', 'IMPRESION_FALLIDA', 'SIN_IMPRESORA'], true) => 503,
                default => 422,
            };
            http_response_code($status);
            $r['ok'] = false;
            $r['mensaje'] ??= 'No se pudo completar la operación de caja.';
        }
        echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
