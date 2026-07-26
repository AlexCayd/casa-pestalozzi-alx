<?php

/**
 * Endpoints públicos de reservaciones.
 *
 * El controlador sólo traduce HTTP; capacidad, propiedad, idempotencia,
 * transacciones y locks viven en los servicios.
 */

namespace Controllers;

use Model\Reservacion;
use MVC\Router;
use Services\ContactoAccesoService;
use Services\DisponibilidadReservacionService;
use Services\HorarioOperacionService;
use Services\HorarioReservacionService;
use Services\ReservationClientSession;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;
use Services\ReservacionService;

class ReservacionController
{
    public static function solicitarCodigo(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $entrada = self::entrada();
        $respuesta = !empty($entrada['request_token'])
            ? ReservacionPublicaService::reenviarOtpRetencion($entrada)
            : ContactoAccesoService::solicitarCodigo(
                (string)($entrada['tipo'] ?? ''),
                (string)($entrada['contacto'] ?? '')
            );
        self::json($respuesta, self::status($respuesta, 201));
    }

    /**
     * Sin request_token conserva el acceso de Etapa 1. Con request_token usa
     * la misma validación OTP y confirma atómicamente la retención enlazada.
     */
    public static function verificarContacto(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $entrada = self::entrada();
        $respuesta = !empty($entrada['request_token'])
            ? ReservacionPublicaService::confirmarRetencion($entrada)
            : ContactoAccesoService::verificarCodigo(
                (string)($entrada['tipo'] ?? ''),
                (string)($entrada['contacto'] ?? ''),
                (string)($entrada['codigo'] ?? '')
            );
        self::json($respuesta, self::status($respuesta));
    }

    public static function misReservaciones(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        $sesion = ReservationClientSession::obtener();
        if (!$sesion) {
            self::json([
                'ok' => false,
                'codigo' => ReservacionPublicaService::SESION_EXPIRADA,
                'mensaje' => 'Verifica tu contacto para consultar reservaciones.',
            ], 401);
            return;
        }

        try {
            $tipo = (string)$sesion['contact_type'];
            $contacto = (string)$sesion['contact_normalized'];
            $fecha = ReservacionConfig::fechaActual();
            $hora = ReservacionConfig::horaActual();
            $total = Reservacion::contarActivasPorContacto($tipo, $contacto, $fecha, $hora);
            $filas = Reservacion::buscarActivasPorContacto(
                $tipo,
                $contacto,
                $fecha,
                $hora,
                ReservacionConfig::MAX_ACTIVE_RESERVATIONS
            );
            $reservaciones = array_map(static function (array $fila): array {
                $estado = (string)($fila['estado'] ?? '');
                $puedeGestionarse = ReservacionPublicaService::puedeGestionarse($fila);
                return [
                    'id' => (int)($fila['id'] ?? 0),
                    'nombre' => (string)($fila['nombre'] ?? ''),
                    'fecha' => (string)($fila['fecha'] ?? ''),
                    'hora' => substr((string)($fila['hora'] ?? ''), 0, 5),
                    'comensales' => (int)($fila['comensales'] ?? 0),
                    'nota' => (string)($fila['nota'] ?? ''),
                    'estado' => $estado,
                    'estado_label' => ReservacionConfig::ESTADO_LABELS[$estado] ?? ucfirst($estado),
                    'can_modify' => $puedeGestionarse,
                    'can_cancel' => $puedeGestionarse,
                    'contact_channel' => (string)($fila['contacto_tipo'] ?? ''),
                ];
            }, $filas);

            self::json([
                'ok' => true,
                'session_verified' => true,
                'active_reservations_count' => $total,
                'max_active_reservations' => ReservacionConfig::MAX_ACTIVE_RESERVATIONS,
                'can_create_reservation' => $total < ReservacionConfig::MAX_ACTIVE_RESERVATIONS,
                'reservations' => $reservaciones,
            ]);
        } catch (\Throwable $e) {
            error_log('ReservacionController::misReservaciones - ' . $e->getMessage());
            self::json([
                'ok' => false,
                'codigo' => ReservacionPublicaService::ERROR_INTERNO,
                'mensaje' => 'No fue posible consultar las reservaciones.',
            ], 500);
        }
    }

    public static function logoutContacto(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        ReservationClientSession::cerrar();
        self::json(['ok' => true, 'codigo' => 'SESION_CERRADA', 'mensaje' => 'Sesión cerrada.']);
    }

    /** Ruta legacy conservada para selectores administrativos existentes. */
    public static function horarios(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO', 'horarios' => []], 405);
            return;
        }
        $respuesta = ReservacionService::obtenerHorariosDisponiblesParaFecha((string)($_GET['fecha'] ?? ''));
        self::json($respuesta, ($respuesta['ok'] ?? false) ? 200 : 422);
    }

    /** Disponibilidad orientativa; no expone mesas ni capacidad interna. */
    public static function disponibilidad(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        try {
            $respuesta = DisponibilidadReservacionService::consultar(
                (string)($_GET['fecha'] ?? ''),
                $_GET['personas'] ?? null
            );
            self::json($respuesta, self::status($respuesta));
        } catch (\Throwable $error) {
            error_log('Error al consultar disponibilidad de reservaciones: ' . $error->getMessage());
            self::json([
                'ok' => false,
                'codigo' => 'ERROR_DISPONIBILIDAD',
                'mensaje' => 'No fue posible consultar la disponibilidad en este momento.',
            ], 500);
        }
    }

    /** Resolución pública uniforme del horario operativo y sus slots. */
    public static function horarioEfectivo(Router $router): void
    {
        $fecha = trim((string)($_GET['fecha'] ?? ''));
        if (!HorarioReservacionService::fechaValida($fecha)) {
            self::json([
                'ok' => false,
                'codigo' => HorarioReservacionService::FECHA_INVALIDA,
                'fecha' => $fecha,
            ], 422);
            return;
        }

        $efectivo = HorarioOperacionService::obtenerHorarioEfectivo($fecha);
        $slots = ($efectivo['abierto'] ?? false)
            ? HorarioReservacionService::generarIntervalos(
                (string)$efectivo['hora_apertura'],
                (string)$efectivo['hora_cierre']
            )
            : [];
        self::json([
            'ok' => true,
            'fecha' => $fecha,
            'abierto' => (bool)($efectivo['abierto'] ?? false),
            'origen' => $efectivo['origen'] ?? 'semanal',
            'hora_apertura' => !empty($efectivo['hora_apertura'])
                ? substr((string)$efectivo['hora_apertura'], 0, 5)
                : null,
            'hora_cierre' => !empty($efectivo['hora_cierre'])
                ? substr((string)$efectivo['hora_cierre'], 0, 5)
                : null,
            'slots_reservables' => array_map(
                static fn(string $hora): string => substr($hora, 0, 5),
                $slots
            ),
            'excepcion_aplicada' => ($efectivo['origen'] ?? '') === 'excepcion'
                ? [
                    'tipo' => $efectivo['tipo'] ?? null,
                    'motivo' => $efectivo['motivo'] ?? null,
                ]
                : null,
            'zona_horaria' => ReservacionConfig::TIMEZONE,
        ]);
    }

    public static function retencion(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $respuesta = ReservacionPublicaService::crearRetencion(self::entrada());
        self::json($respuesta, self::status($respuesta, 201));
    }

    public static function crearVerificada(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $sesion = ReservationClientSession::obtener();
        if (!$sesion) {
            self::json([
                'ok' => false,
                'codigo' => ReservacionPublicaService::CONTACTO_NO_VERIFICADO,
                'mensaje' => 'Verifica tu contacto para reservar directamente.',
            ], 401);
            return;
        }
        $respuesta = ReservacionPublicaService::crearConfirmada(self::entrada(), $sesion);
        self::json($respuesta, self::status($respuesta, 201));
    }

    public static function modificarPublica(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $sesion = ReservationClientSession::obtener();
        if (!$sesion) {
            self::json([
                'ok' => false,
                'codigo' => ReservacionPublicaService::SESION_EXPIRADA,
                'mensaje' => 'Verifica nuevamente tu contacto.',
            ], 401);
            return;
        }
        $respuesta = ReservacionPublicaService::modificar(self::entrada(), $sesion);
        self::json($respuesta, self::status($respuesta));
    }

    public static function cancelarPublica(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $sesion = ReservationClientSession::obtener();
        if (!$sesion) {
            self::json([
                'ok' => false,
                'codigo' => ReservacionPublicaService::SESION_EXPIRADA,
                'mensaje' => 'Verifica nuevamente tu contacto.',
            ], 401);
            return;
        }
        $entrada = self::entrada();
        $respuesta = ReservacionPublicaService::cancelar(
            (int)($entrada['reservacion_id'] ?? $entrada['id'] ?? 0),
            $sesion
        );
        self::json($respuesta, self::status($respuesta));
    }

    /**
     * Compatibilidad de URL: el POST histórico ya no crea una reservación sin
     * verificar. Una sesión válida usa el nuevo flujo confirmado.
     */
    public static function crear(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $sesion = ReservationClientSession::obtener();
        if (!$sesion) {
            self::json([
                'ok' => false,
                'codigo' => ReservacionPublicaService::CONTACTO_NO_VERIFICADO,
                'mensaje' => 'Crea una retención y verifica tu contacto para continuar.',
            ], 401);
            return;
        }
        $respuesta = ReservacionPublicaService::crearConfirmada(self::entrada(), $sesion);
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

    private static function esPost(): bool
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return true;
        }
        self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
        return false;
    }

    /** @param array<string, mixed> $respuesta */
    private static function json(array $respuesta, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($respuesta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function status(array $respuesta, int $exito = 200): int
    {
        if ($respuesta['ok'] ?? false) {
            return $exito;
        }
        return match ((string)($respuesta['codigo'] ?? '')) {
            ReservacionPublicaService::CONTACTO_NO_VERIFICADO,
            ReservacionPublicaService::SESION_EXPIRADA => 401,
            ReservacionPublicaService::RESERVACION_NO_PERTENECE_AL_CONTACTO,
            ReservacionPublicaService::MODIFICACION_NO_PERMITIDA,
            ReservacionPublicaService::CANCELACION_NO_PERMITIDA => 403,
            ReservacionPublicaService::RESERVACION_NO_ENCONTRADA => 404,
            ReservacionPublicaService::RETENCION_EXPIRADA => 410,
            ReservacionPublicaService::SIN_DISPONIBILIDAD,
            ReservacionPublicaService::LIMITE_RESERVACIONES_ALCANZADO,
            ReservacionPublicaService::REQUEST_TOKEN_CONFLICTO => 409,
            ContactoAccesoService::REENVIO_NO_DISPONIBLE,
            ContactoAccesoService::DEMASIADOS_INTENTOS => 429,
            ReservacionPublicaService::ERROR_INTERNO,
            ContactoAccesoService::ERROR_INTERNO => 500,
            default => 422,
        };
    }
}
