<?php

/**
 * Coordina la herramienta operativa de reservaciones.
 * Las reglas de horarios, estados y mesas permanecen en los servicios de dominio.
 */

namespace Controllers;

use Model\Mesa;
use Model\Reservacion;
use MVC\Router;
use Services\AsignacionMesasService;
use Services\HorarioReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionService;

class ReservacionOperacionController
{
    private const OPERATION_CSS = '/build/css/operation/reservations.css?v=reservation-operation-v1';
    private const OPERATION_JS = '/build/js/admin/reservation-operation.js?v=reservation-operation-v1';

    public static function operation(Router $router): void
    {
        $fechaFueEnviada = array_key_exists('fecha', $_GET);
        $fechaSolicitada = trim((string)($_GET['fecha'] ?? ''));
        $fechaInvalida = $fechaFueEnviada && !HorarioReservacionService::fechaValida($fechaSolicitada);
        $fecha = $fechaInvalida || !$fechaFueEnviada
            ? ReservacionConfig::fechaActual()
            : $fechaSolicitada;
        $soloLectura = HorarioReservacionService::fechaPasada($fecha);
        $hora = self::normalizarHoraCorta((string)($_GET['hora'] ?? ''));
        $reservacionId = filter_var($_GET['reservacion_id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        $returnUrl = self::returnUrlSeguro($_GET['return_url'] ?? '');
        $alertas = self::alertasResultado($_GET['resultado'] ?? '');
        if ($fechaInvalida) {
            http_response_code(422);
            $alertas['error'][] = 'La fecha seleccionada no tiene un formato valido. Se muestra la fecha actual.';
        }
        if ($soloLectura) {
            $alertas['warning'][] = 'Operacion historica en modo de solo lectura.';
        }

        self::render('reservations/index', [
            'title' => $soloLectura ? 'Operacion historica de reservaciones' : 'Operacion de reservaciones',
            'styles' => [self::OPERATION_CSS],
            'scripts' => [self::OPERATION_JS],
            'filtros' => [
                'fecha' => $fecha,
                'hora' => $hora,
            ],
            'estadoLabels' => ReservacionService::estadoLabels(),
            'alertas' => $alertas,
            'returnUrl' => $returnUrl,
            'initialReservacionId' => $reservacionId ? (int)$reservacionId : 0,
            'comentarioAdminDisponible' => Reservacion::tieneComentarioAdmin(),
            'fechaMinima' => ReservacionConfig::fechaActual(),
            'modoSoloLectura' => $soloLectura,
            'fechaInvalidaRecibida' => $fechaInvalida ? $fechaSolicitada : '',
        ]);
    }

    public static function operationData(): void
    {
        $fechaFueEnviada = array_key_exists('fecha', $_GET);
        $fechaSolicitada = trim((string)($_GET['fecha'] ?? ''));
        if ($fechaFueEnviada && !HorarioReservacionService::fechaValida($fechaSolicitada)) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => HorarioReservacionService::FECHA_INVALIDA,
                'tipo' => 'validacion',
                'titulo' => 'Fecha invalida',
                'mensaje' => 'La fecha seleccionada no tiene un formato valido.',
                'fecha' => $fechaSolicitada,
                'horarios' => [],
            ], 422);
        }

        $fecha = $fechaFueEnviada ? $fechaSolicitada : ReservacionConfig::fechaActual();
        $soloLectura = HorarioReservacionService::fechaPasada($fecha);
        $disponibilidad = ReservacionService::obtenerHorariosDisponiblesParaFecha($fecha, $soloLectura);
        if (!($disponibilidad['ok'] ?? false)) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => $disponibilidad['codigo'] ?? ReservacionService::ERROR_INTERNO,
                'tipo' => ($disponibilidad['codigo'] ?? '') === HorarioReservacionService::ERROR_INTERNO
                    ? 'tecnico'
                    : 'validacion',
                'fecha' => $fecha,
                'mensaje' => $disponibilidad['mensaje'] ?? 'No fue posible consultar los horarios.',
                'horarios' => [],
            ], ($disponibilidad['codigo'] ?? '') === ReservacionService::ERROR_INTERNO ? 500 : 422);
        }

        $horarios = array_values(array_filter(array_map(static function ($horario): string {
            return HorarioReservacionService::normalizarHoraCorta((string)$horario);
        }, $disponibilidad['horarios'] ?? [])));
        $reservaciones = Reservacion::buscarPorDiaOperacionAdmin($fecha);
        $reservacionesSerializadas = self::serializarReservacionesOperacion($reservaciones);
        $ocupacionPorReservacion = AsignacionMesasService::obtenerOcupacionPorReservacionDelDia($fecha, $reservaciones);
        $abierto = (bool)($disponibilidad['abierto'] ?? false);
        $estadoOperacion = !$abierto
            ? 'cerrado'
            : ($horarios === [] ? 'sin_horarios' : 'disponible');
        $mensajeOperacion = match ($estadoOperacion) {
            'cerrado' => 'No hay operación programada para esta fecha.',
            'sin_horarios' => 'No existen horarios disponibles para esta fecha.',
            default => null,
        };

        self::jsonResponse([
            'ok' => true,
            'codigo' => $soloLectura ? 'FECHA_PASADA_SOLO_LECTURA' : null,
            'modo' => $soloLectura ? 'solo_lectura' : 'operacion',
            'editable' => !$soloLectura,
            'fecha' => $fecha,
            'abierto' => $abierto,
            'estado_operacion' => $estadoOperacion,
            'origen' => $disponibilidad['origen'] ?? null,
            'tipo' => $disponibilidad['tipo'] ?? null,
            'mensaje' => $mensajeOperacion,
            'horarios' => $horarios,
            'hora_sugerida' => HorarioReservacionService::horaPorDefecto($horarios, $fecha),
            'reservaciones' => $reservacionesSerializadas,
            'mesas' => self::serializarMesasOperacion(self::mesasMapaOperacion()),
            'ocupacion_por_reservacion' => $ocupacionPorReservacion,
            'config' => [
                'estado_labels' => ReservacionService::estadoLabels(),
                'estados_editables' => ReservacionService::estadosEditables(),
                'transiciones' => ReservacionService::transiciones(),
                'comentario_admin_disponible' => Reservacion::tieneComentarioAdmin(),
            ],
        ]);
    }

    public static function apiAssignTables(): void
    {
        $id = self::reservacionIdOperacionPost();
        $permitirCapacidadInsuficiente = (string)($_POST['permitir_capacidad_insuficiente'] ?? '') === '1';
        $mesaIds = $_POST['mesa_ids'] ?? [];
        $resultado = AsignacionMesasService::asignarManual($id, (array)$mesaIds, $permitirCapacidadInsuficiente);

        self::jsonResultadoAsignacion($resultado, 'Asignacion guardada.');
    }

    public static function apiReasignarAutomaticamente(): void
    {
        $resultado = AsignacionMesasService::asignarAutomaticamente(self::reservacionIdOperacionPost());

        self::jsonResultadoAsignacion($resultado, 'Asignacion guardada.');
    }

    public static function apiUpdateComment(): void
    {
        $id = self::reservacionIdOperacionPost();

        $comentario = substr((string)($_POST['comentario_admin'] ?? ''), 0, 5000);
        $resultado = ReservacionService::actualizarComentario($id, $comentario);
        $codigo = (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO);

        self::jsonResponse([
            'ok' => (bool)($resultado['ok'] ?? false),
            'codigo' => $codigo,
            'mensaje' => ($resultado['ok'] ?? false) ? 'Comentario guardado.' : self::mensajeActualizacionApi($codigo),
        ], ($resultado['ok'] ?? false) ? 200 : self::httpStatusActualizacion($codigo));
    }

    public static function apiStatus(): void
    {
        $estado = (string)($_POST['estado'] ?? '');

        self::jsonResultadoTransicion(
            ReservacionService::cambiarEstado(
                self::reservacionIdOperacionPost(),
                $estado,
                (int)($_SESSION['id'] ?? 0) ?: null
            ),
            self::mensajeExitoEstado($estado)
        );
    }

    public static function assignTables(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirectOperacionDesdePost('metodo_invalido');
        }

        $id = filter_var($_POST['reservacion_id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if (!$id) {
            self::redirectOperacionDesdePost('no_existe');
        }

        $reservacion = Reservacion::find((int)$id);
        if (!$reservacion) {
            self::redirectOperacionDesdePost('no_existe');
        }

        $mesaIds = $_POST['mesa_ids'] ?? [];
        $resultado = AsignacionMesasService::asignarManual((int)$reservacion->id, (array)$mesaIds);
        self::redirectOperacionDesdePost(
            self::resultadoAsignacion($resultado['codigo'] ?? AsignacionMesasService::ERROR_INTERNO),
            $reservacion
        );
    }

    public static function updateComment(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirectOperacionDesdePost('metodo_invalido');
        }

        $id = filter_var($_POST['reservacion_id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if (!$id) {
            self::redirectOperacionDesdePost('no_existe');
        }

        $reservacion = Reservacion::find((int)$id);
        if (!$reservacion) {
            self::redirectOperacionDesdePost('no_existe');
        }

        $comentario = substr((string)($_POST['comentario_admin'] ?? ''), 0, 5000);
        $resultado = ReservacionService::actualizarComentario((int)$reservacion->id, $comentario);

        self::redirectOperacionDesdePost(
            $resultado['ok'] ? 'comentario_guardado' : self::resultadoActualizacion($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO),
            $reservacion
        );
    }

    private static function fechaOperacion(): string
    {
        return HorarioReservacionService::fechaSeguraGet((string)($_GET['fecha'] ?? ''));
    }

    private static function render(string $view, array $data = []): void
    {
        $styles = [];
        $scripts = [];

        foreach ($data as $key => $value) {
            $$key = $value;
        }

        ob_start();
        include __DIR__ . "/../views/operation/{$view}.php";
        $content = ob_get_clean();

        include __DIR__ . '/../views/operation/layout.php';
    }

    private static function normalizarHoraCorta(string $hora): string
    {
        return HorarioReservacionService::normalizarHoraCorta($hora);
    }

    private static function mesasMapaOperacion(): array
    {
        return Mesa::buscarTodasParaMapa();
    }

    private static function returnUrlSeguro($url, string $fallback = ''): string
    {
        $url = (string)$url;

        if ($url !== '' && str_starts_with($url, '/admin/reservations')) {
            return $url;
        }

        return $fallback;
    }

    private static function operationUrl(array $params = []): string
    {
        $query = [];
        $fecha = (string)($params['fecha'] ?? '');
        $hora = self::normalizarHoraCorta((string)($params['hora'] ?? ''));
        $estado = (string)($params['estado'] ?? '');
        $reservacionId = (int)($params['reservacion_id'] ?? 0);
        $returnUrl = self::returnUrlSeguro($params['return_url'] ?? '');
        $resultado = (string)($params['resultado'] ?? '');
        $validacion = (string)($params['validacion'] ?? '');

        if (self::fechaValida($fecha)) {
            $query['fecha'] = $fecha;
        }

        if ($hora !== '') {
            $query['hora'] = $hora;
        }

        if (array_key_exists($estado, ReservacionService::estadoLabels())) {
            $query['estado'] = $estado;
        }

        if ($reservacionId > 0) {
            $query['reservacion_id'] = $reservacionId;
        }

        if ($returnUrl !== '') {
            $query['return_url'] = $returnUrl;
        }

        if ($resultado !== '') {
            $query['resultado'] = $resultado;
        }

        if ($validacion === 'fecha_pasada') {
            $query['validacion'] = $validacion;
        }

        return '/admin/reservations/operation' . (!empty($query) ? '?' . http_build_query($query) : '');
    }

    private static function redirectOperacionDesdePost(string $resultado, $reservacion = null): void
    {
        $fecha = (string)($_POST['fecha'] ?? '');
        $hora = self::normalizarHoraCorta((string)($_POST['hora'] ?? ''));
        $reservacionId = 0;

        if ($reservacion) {
            $fecha = (string)$reservacion->fecha;
            $hora = self::normalizarHoraCorta((string)$reservacion->hora);
            $reservacionId = (int)$reservacion->id;
        } else {
            $reservacionId = (int)($_POST['reservacion_id'] ?? 0);
        }

        if (!self::fechaValida($fecha)) {
            $fecha = ReservacionConfig::fechaActual();
        }

        header('Location: ' . self::operationUrl([
            'fecha' => $fecha,
            'hora' => $hora,
            'reservacion_id' => $reservacionId,
            'return_url' => self::returnUrlSeguro($_POST['return_url'] ?? ''),
            'resultado' => $resultado,
        ]), true, 302);
        exit;
    }

    private static function fechaValida(string $fecha): bool
    {
        return HorarioReservacionService::fechaValida($fecha);
    }

    private static function serializarReservacionesOperacion(array $reservaciones): array
    {
        return array_map(static function ($reservacion): array {
            $mesaIds = array_values(array_filter(array_map('intval', explode(',', (string)($reservacion->mesa_ids ?? '')))));
            $mesasNombres = array_values(array_filter(array_map('trim', explode(',', (string)($reservacion->mesas_asignadas ?? '')))));

            return [
                'id' => (int)($reservacion->id ?? 0),
                'nombre' => (string)($reservacion->nombre ?? ''),
                'contacto' => (string)($reservacion->contacto ?? ''),
                'fecha' => (string)($reservacion->fecha ?? ''),
                'hora' => substr((string)($reservacion->hora ?? ''), 0, 5),
                'comensales' => (int)($reservacion->comensales ?? 0),
                'estado' => (string)($reservacion->estado ?? 'confirmada'),
                'editable' => ReservacionService::puedeEditar($reservacion),
                'motivo_no_editable' => ReservacionService::codigoNoEditable($reservacion),
                'mesa_ids' => $mesaIds,
                'mesas_asignadas' => $mesasNombres,
                'mesas_count' => (int)($reservacion->mesas_count ?? 0),
                'capacidad_asignada' => (int)($reservacion->capacidad_total ?? 0),
                'nota' => (string)($reservacion->nota ?? ''),
                'comentario_admin' => (string)($reservacion->comentario_admin ?? ''),
            ];
        }, $reservaciones);
    }

    private static function serializarMesasOperacion(array $mesas): array
    {
        return array_map(static function ($mesa): array {
            return [
                'id' => (int)($mesa->id ?? 0),
                'numero' => (int)($mesa->numero ?? 0),
                'nombre' => (string)($mesa->nombre ?? ''),
                'tipo' => (string)($mesa->tipo ?? 'mesa'),
                'capacidad' => (int)($mesa->capacidad ?? 0),
                'pos_x' => (float)($mesa->pos_x ?? 50),
                'pos_y' => (float)($mesa->pos_y ?? 50),
                'activo' => (int)($mesa->activo ?? 0),
                'reservable' => (int)($mesa->reservable ?? 0),
            ];
        }, $mesas);
    }

    private static function reservacionIdOperacionPost(): int
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::jsonResponse([
                'ok' => false,
                'codigo' => 'METODO_INVALIDO',
                'mensaje' => 'Metodo invalido.',
            ], 405);
        }

        $id = filter_var($_POST['reservacion_id'] ?? ($_POST['id'] ?? 0), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        return $id ? (int)$id : 0;
    }

    private static function jsonResultadoAsignacion(array $resultado, string $mensajeExito): void
    {
        $codigo = (string)($resultado['codigo'] ?? AsignacionMesasService::ERROR_INTERNO);
        $ok = (bool)($resultado['ok'] ?? false);
        $httpStatus = $ok ? 200 : match ($codigo) {
            AsignacionMesasService::ERROR_INTERNO => 500,
            AsignacionMesasService::MESA_OCUPADA => 409,
            AsignacionMesasService::RESERVACION_NO_EXISTE => 404,
            ReservacionService::RESERVACION_PASADA,
            ReservacionService::RESERVACION_HORARIO_PASADO => 409,
            default => 422,
        };

        self::jsonResponse([
            'ok' => $ok,
            'codigo' => $codigo,
            'mensaje' => $ok ? $mensajeExito : self::mensajeAsignacionApi($codigo),
            'mesa_ids' => $resultado['mesa_ids'] ?? [],
        ], $httpStatus);
    }

    private static function jsonResultadoTransicion(array $resultado, string $mensajeExito): void
    {
        $codigo = (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO);
        $ok = (bool)($resultado['ok'] ?? false);
        $httpStatus = $ok ? 200 : match ($codigo) {
            ReservacionService::ERROR_INTERNO => 500,
            ReservacionService::RESERVACION_NO_EXISTE => 404,
            ReservacionService::RESERVACION_PASADA,
            ReservacionService::RESERVACION_HORARIO_PASADO => 409,
            default => 422,
        };

        self::jsonResponse([
            'ok' => $ok,
            'codigo' => $codigo,
            'mensaje' => $ok ? $mensajeExito : self::mensajeTransicionApi($codigo),
        ], $httpStatus);
    }

    private static function resultadoActualizacion(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::ACTUALIZADA => 'actualizada',
            ReservacionService::ACTUALIZADA_REQUIERE_ASIGNACION => 'actualizada_requiere_asignacion',
            ReservacionService::COMENTARIO_ACTUALIZADO => 'comentario_guardado',
            ReservacionService::DATOS_INVALIDOS => 'datos_invalidos',
            ReservacionService::HORARIO_INVALIDO => 'horario_invalido',
            ReservacionService::COMENTARIO_NO_DISPONIBLE => 'comentario_migracion_pendiente',
            ReservacionService::RESERVACION_NO_EXISTE => 'no_existe',
            ReservacionService::RESERVACION_PASADA => 'reservacion_pasada',
            ReservacionService::RESERVACION_HORARIO_PASADO => 'reservacion_horario_pasado',
            ReservacionService::ESTADO_NO_EDITABLE => 'estado_no_editable',
            default => 'error_interno',
        };
    }

    private static function resultadoAsignacion(string $codigo): string
    {
        return match ($codigo) {
            AsignacionMesasService::ASIGNACION_GUARDADA => 'asignacion_guardada',
            AsignacionMesasService::SIN_CAPACIDAD => 'reasignar_sin_capacidad',
            AsignacionMesasService::ASIGNACION_VACIA => 'asignacion_vacia',
            AsignacionMesasService::MESAS_INVALIDAS => 'mesas_invalidas',
            AsignacionMesasService::MESA_OCUPADA => 'mesa_ocupada',
            AsignacionMesasService::CAPACIDAD_INSUFICIENTE => 'capacidad_insuficiente',
            AsignacionMesasService::ESTADO_INVALIDO => 'estado_no_permite',
            AsignacionMesasService::RESERVACION_NO_EXISTE => 'no_existe',
            default => 'error_interno',
        };
    }

    private static function mensajeAsignacionApi(string $codigo): string
    {
        return match ($codigo) {
            AsignacionMesasService::ASIGNACION_VACIA => 'Selecciona al menos una mesa.',
            AsignacionMesasService::MESAS_INVALIDAS => 'Una o mas mesas no estan disponibles para reserva.',
            AsignacionMesasService::MESA_OCUPADA => 'La mesa acaba de ser asignada a otra reservacion. Los datos fueron actualizados.',
            AsignacionMesasService::CAPACIDAD_INSUFICIENTE => 'La capacidad seleccionada es insuficiente.',
            AsignacionMesasService::ESTADO_INVALIDO => 'Este estado no permite modificar mesas.',
            ReservacionService::RESERVACION_PASADA,
            ReservacionService::RESERVACION_HORARIO_PASADO => 'La operacion historica es de solo lectura.',
            AsignacionMesasService::RESERVACION_NO_EXISTE => 'La reservacion no existe.',
            default => 'No fue posible guardar los cambios. Intentalo nuevamente.',
        };
    }

    private static function mensajeTransicionApi(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::CONFIRMAR_SIN_MESA => 'Asigna una mesa antes de confirmar.',
            ReservacionService::ESTADO_INVALIDO => 'La accion no es valida para el estado actual.',
            ReservacionService::RESERVACION_PASADA,
            ReservacionService::RESERVACION_HORARIO_PASADO => 'La operacion historica es de solo lectura.',
            ReservacionService::RESERVACION_NO_EXISTE => 'La reservacion no existe.',
            default => 'No fue posible guardar los cambios. Intentalo nuevamente.',
        };
    }

    private static function mensajeActualizacionApi(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::COMENTARIO_NO_DISPONIBLE => 'Los comentarios internos no estan disponibles.',
            ReservacionService::DATOS_INVALIDOS => 'Revisa los datos de la reservacion.',
            ReservacionService::HORARIO_INVALIDO => 'La fecha u hora seleccionada no esta disponible.',
            ReservacionService::RESERVACION_NO_EXISTE => 'La reservacion no existe.',
            ReservacionService::RESERVACION_PASADA => 'No se pueden modificar reservaciones de fechas anteriores.',
            ReservacionService::RESERVACION_HORARIO_PASADO => 'No se pueden modificar reservaciones cuyo horario ya paso.',
            ReservacionService::ESTADO_NO_EDITABLE => 'La reservacion no puede modificarse en su estado actual.',
            default => 'No fue posible guardar los cambios. Intentalo nuevamente.',
        };
    }

    private static function httpStatusActualizacion(string $codigo): int
    {
        return match ($codigo) {
            ReservacionService::ERROR_INTERNO => 500,
            ReservacionService::RESERVACION_NO_EXISTE => 404,
            ReservacionService::COMENTARIO_NO_DISPONIBLE => 409,
            ReservacionService::ESTADO_NO_EDITABLE,
            ReservacionService::RESERVACION_PASADA,
            ReservacionService::RESERVACION_HORARIO_PASADO => 409,
            default => 422,
        };
    }

    private static function mensajeExitoEstado(string $estado): string
    {
        return match ($estado) {
            'confirmada' => 'Reservacion confirmada.',
            'completada' => 'Reservacion completada.',
            'cancelada' => 'Reservacion cancelada.',
            'no_show' => 'Reservacion marcada como no show.',
            default => 'Estado actualizado.',
        };
    }

    private static function jsonResponse(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function alertasResultado(string $resultado): array
    {
        return match ($resultado) {
            'creada' => ['exito' => ['Reservacion creada correctamente.']],
            'creada_sin_mesas' => ['warning' => ['Reservacion creada correctamente, pero no fue posible asignar mesas automaticamente.']],
            'actualizada' => ['exito' => ['Reservacion actualizada correctamente.']],
            'actualizada_requiere_asignacion' => ['warning' => ['La reservacion fue actualizada, pero sus mesas anteriores ya no son validas. Debe realizarse una nueva asignacion.']],
            'confirmada' => ['exito' => ['Reservación confirmada correctamente.']],
            'cancelada' => ['exito' => ['Reservación cancelada correctamente.']],
            'completada' => ['exito' => ['Reservación marcada como completada.']],
            'no_show' => ['exito' => ['Reservación marcada como no show.']],
            'reasignada' => ['exito' => ['Mesas reasignadas correctamente.']],
            'asignacion_guardada' => ['exito' => ['Asignacion de mesas guardada correctamente.']],
            'comentario_guardado' => ['exito' => ['Comentario interno guardado correctamente.']],
            'reasignar_sin_capacidad' => ['error' => ['No hay mesas suficientes disponibles para reasignar automáticamente.']],
            'asignacion_vacia' => ['error' => ['Selecciona al menos una mesa para guardar la asignacion.']],
            'mesas_invalidas' => ['error' => ['Una o mas mesas no existen, no estan activas o no son reservables.']],
            'mesa_ocupada' => ['error' => ['Una de las mesas seleccionadas ya esta ocupada por otra reservacion activa en esa ventana horaria.']],
            'capacidad_insuficiente' => ['error' => ['La capacidad seleccionada no cubre los comensales de la reservacion.']],
            'estado_no_permite' => ['error' => ['El estado de la reservacion no permite modificar mesas.']],
            'estado_invalido' => ['error' => ['La accion no es valida para el estado actual de la reservacion.']],
            'datos_invalidos' => ['error' => ['Revisa los datos de la reservacion.']],
            'horario_invalido' => ['error' => ['La fecha u hora seleccionada no esta disponible.']],
            'estado_no_editable' => ['error' => ['La reservacion no puede modificarse en su estado actual.']],
            'reservacion_pasada' => ['error' => ['No se pueden modificar reservaciones de fechas anteriores.']],
            'reservacion_horario_pasado' => ['error' => ['No se pueden modificar reservaciones cuyo horario ya paso.']],
            'comentario_migracion_pendiente' => ['warning' => ['Los comentarios internos no estan disponibles en esta instalacion.']],
            'confirmar_sin_mesa' => ['error' => ['Asigna una mesa antes de confirmar la reservación.']],
            'no_existe' => ['error' => ['La reservación no existe.']],
            'metodo_invalido' => ['error' => ['La acción solicitada no es válida.']],
            'error_interno' => ['error' => ['No se pudo completar la accion. Intenta de nuevo.']],
            default => [],
        };
    }
}
