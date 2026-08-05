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
        if (!self::validarCsrfPublico($entrada)) {
            return;
        }
        if (!empty($entrada['request_token']) && ($entrada['operacion'] ?? '') === 'modificacion') {
            $sesion = ReservationClientSession::obtener();
            $respuesta = $sesion
                ? ReservacionPublicaService::reenviarOtpModificacion($entrada, $sesion)
                : [
                    'ok' => false,
                    'codigo' => ReservacionPublicaService::SESION_EXPIRADA,
                    'mensaje' => 'Verifica nuevamente tu contacto.',
                ];
        } elseif (!empty($entrada['request_token'])) {
            $respuesta = ReservacionPublicaService::reenviarOtpRetencion($entrada);
        } else {
            $respuesta = ContactoAccesoService::solicitarCodigo(
                (string)($entrada['tipo'] ?? ''),
                (string)($entrada['contacto'] ?? '')
            );
        }
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
        if (!self::validarCsrfPublico($entrada)) {
            return;
        }
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
            $tipo = (string)$sesion['contacto_tipo'];
            $contacto = (string)$sesion['contacto'];
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
            $pendientes = Reservacion::buscarReemplazosPendientesPorContacto(
                $tipo,
                $contacto,
                $fecha,
                $hora
            );
            $pendientesPorOriginal = [];
            foreach ($pendientes as $pendiente) {
                $pendientesPorOriginal[(int)$pendiente['reemplaza_reservacion_id']] = [
                    'fecha' => (string)$pendiente['fecha'],
                    'hora' => substr((string)$pendiente['hora'], 0, 5),
                    'comensales' => (int)$pendiente['comensales'],
                    'nota' => (string)($pendiente['nota'] ?? ''),
                    'hold_expires_at' => (string)$pendiente['hold_expires_at'],
                    'label' => 'Cambio pendiente de confirmación',
                ];
            }
            $reservaciones = array_map(static function (array $fila): array {
                $estado = (string)($fila['estado'] ?? '');
                $puedeModificar = ReservacionPublicaService::puedeModificarPublicamente($fila);
                $puedeCancelar = ReservacionPublicaService::puedeCancelarPublicamente($fila);
                return [
                    'id' => (int)($fila['id'] ?? 0),
                    'nombre' => (string)($fila['nombre'] ?? ''),
                    'fecha' => (string)($fila['fecha'] ?? ''),
                    'hora' => substr((string)($fila['hora'] ?? ''), 0, 5),
                    'comensales' => (int)($fila['comensales'] ?? 0),
                    'nota' => (string)($fila['nota'] ?? ''),
                    'estado_label' => ReservacionConfig::ESTADO_LABELS[$estado] ?? ucfirst($estado),
                    'can_modify' => $puedeModificar,
                    'can_cancel' => $puedeCancelar,
                ];
            }, $filas);
            foreach ($reservaciones as &$reservacion) {
                if (isset($pendientesPorOriginal[$reservacion['id']])) {
                    $reservacion['pending_modification'] = $pendientesPorOriginal[$reservacion['id']];
                }
            }
            unset($reservacion);

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
        $entrada = self::entrada();
        if (!ReservationClientSession::validarCsrf((string)($entrada['csrf_token'] ?? ''))) {
            self::json([
                'ok' => false,
                'codigo' => 'CSRF_INVALIDO',
                'mensaje' => 'No fue posible validar la salida. Recarga la página e inténtalo nuevamente.',
            ], 403);
            return;
        }
        ReservationClientSession::cerrar();
        self::json([
            'ok' => true,
            'codigo' => 'GESTION_SALIDA',
            'mensaje' => 'Saliste de la gestión de reservaciones.',
        ]);
    }

    /** Lista de slots calculados desde el horario efectivo. */
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
            $excluirReservacionId = 0;
            $horarioOriginalPreservable = null;
            $reservacionId = filter_var(
                $_GET['reservacion_id'] ?? $_GET['reservation_id'] ?? 0,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]]
            );
            if ($reservacionId) {
                $sesion = ReservationClientSession::obtener();
                if (
                    $sesion
                    && ReservacionPublicaService::reservacionPerteneceASesion(
                        (int)$reservacionId,
                        $sesion
                    )
                ) {
                    $excluirReservacionId = (int)$reservacionId;
                    $filaOriginal = Reservacion::findWithMesas((int)$reservacionId);
                    if ($filaOriginal) {
                        $filaOriginalArray = get_object_vars($filaOriginal);
                        if (ReservacionPublicaService::puedeModificarPublicamente($filaOriginalArray)) {
                            $horarioOriginalPreservable = [
                                'fecha' => (string)$filaOriginal->fecha,
                                'hora' => (string)$filaOriginal->hora,
                            ];
                        }
                    }
                }
            }
            $respuesta = DisponibilidadReservacionService::consultar(
                (string)($_GET['fecha'] ?? ''),
                $_GET['personas'] ?? null,
                $excluirReservacionId,
                isset($_GET['hora']) ? (string)$_GET['hora'] : null,
                $horarioOriginalPreservable
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
        $entrada = self::entrada();
        if (!self::validarCsrfPublico($entrada)) {
            return;
        }
        $respuesta = ReservacionPublicaService::crearRetencion($entrada);
        self::json($respuesta, self::status($respuesta, 201));
    }

    public static function crearVerificada(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $entrada = self::entrada();
        if (!self::validarCsrfPublico($entrada)) {
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
        $respuesta = ReservacionPublicaService::contactoCoincideConSesion($entrada, $sesion)
            ? ReservacionPublicaService::crearConfirmada($entrada, $sesion)
            : ReservacionPublicaService::crearRetencion($entrada);
        self::json($respuesta, self::status($respuesta, 201));
    }

    public static function modificarPublica(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $entrada = self::entrada();
        if (!self::validarCsrfPublico($entrada)) {
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
        $respuesta = ReservacionPublicaService::crearReemplazo($entrada, $sesion);
        self::json($respuesta, self::status($respuesta));
    }

    public static function confirmarModificacion(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $entrada = self::entrada();
        if (!self::validarCsrfPublico($entrada)) {
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
        $respuesta = ReservacionPublicaService::confirmarReemplazo($entrada, $sesion);
        self::json($respuesta, self::status($respuesta));
    }

    public static function cancelarPublica(Router $router): void
    {
        if (!self::esPost()) {
            return;
        }
        $entrada = self::entrada();
        if (!self::validarCsrfPublico($entrada)) {
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
        $respuesta = ReservacionPublicaService::cancelar(
            (int)($entrada['reservacion_id'] ?? $entrada['id'] ?? 0),
            $sesion
        );
        self::json($respuesta, self::status($respuesta));
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

    /** La creación pública usa el token de sesión; la gestión antigua conserva su flujo propio. */
    private static function validarCsrfPublico(array $entrada): bool
    {
        if (ReservationClientSession::validarCsrf((string)($entrada['csrf_token'] ?? ''))) {
            return true;
        }
        self::json([
            'ok' => false,
            'codigo' => 'CSRF_INVALIDO',
            'mensaje' => 'La sesión de reservación venció. Recarga la página e inténtalo nuevamente.',
        ], 403);
        return false;
    }

    /** @param array<string, mixed> $respuesta */
    private static function json(array $respuesta, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
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
            ReservacionPublicaService::CONTACTO_NO_COINCIDE,
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
