<?php

/**
 * Coordina la herramienta operativa de reservaciones.
 * Las reglas de horarios, estados y mesas permanecen en los servicios de dominio.
 */

namespace Controllers;

use Model\Reservacion;
use MVC\Router;
use Services\AsignacionMesasService;
use Services\HorarioReservacionService;
use Services\OcupacionMesasService;
use Services\PosReservacionQueryService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionService;

class ReservacionOperacionController
{
    private const OPERATION_CSS = '/build/css/operation/reservations.css?v=reservation-operation-v21';
    private const OPERATION_JS = '/build/js/admin/reservation-operation.js?v=reservation-operation-v21';

    public static function operation(Router $router): void
    {
        $fechaFueEnviada = array_key_exists('fecha', $_GET);
        $fechaSolicitada = trim((string)($_GET['fecha'] ?? ''));
        $fechaInvalida = $fechaFueEnviada && !HorarioReservacionService::fechaValida($fechaSolicitada);
        $fecha = $fechaInvalida || !$fechaFueEnviada
            ? ReservacionConfig::fechaActual()
            : $fechaSolicitada;
        $soloLectura = HorarioReservacionService::fechaPasada($fecha);
        $horaSolicitada = self::normalizarHoraCorta((string)($_GET['hora'] ?? ''));
        $disponibilidadInicial = ReservacionService::obtenerHorariosDisponiblesParaFecha(
            $fecha,
            $soloLectura
        );
        $horariosIniciales = ($disponibilidadInicial['ok'] ?? false)
            ? (array)($disponibilidadInicial['horarios'] ?? [])
            : [];
        $resolucionHorario = HorarioReservacionService::resolverHorarioOperativo(
            $fecha,
            $horaSolicitada,
            $horariosIniciales,
            $soloLectura
        );
        $hora = (string)$resolucionHorario['hora_resuelta'];
        $operacionEditable = !$soloLectura && $horariosIniciales !== [];
        $initialOperationNotice = null;
        $reservacionIdRaw = trim((string)(
            $_GET['reservation_id'] ?? $_GET['reservacion_id'] ?? ''
        ));
        $reservacionId = preg_match('/^[1-9]\d*$/D', $reservacionIdRaw) === 1
            ? (int)$reservacionIdRaw
            : 0;
        $modoSolicitado = trim((string)($_GET['mode'] ?? ''));
        $intencionAsignacion = false;
        $returnUrl = self::returnUrlSeguro($_GET['return_url'] ?? '');
        $alertas = self::alertasResultado($_GET['resultado'] ?? '');
        if ($resolucionHorario['solicitada_vencida']) {
            $sinHorarios = (bool)$resolucionHorario['sin_horarios_futuros'];
            $initialOperationNotice = [
                'type' => 'warning',
                'title' => $sinHorarios ? 'No hay horarios disponibles' : 'Horario no disponible',
                'summary' => $sinHorarios
                    ? 'El horario solicitado ya pasó y hoy no quedan más bloques.'
                    : 'El horario solicitado ya pasó para el día actual.',
                'message' => $sinHorarios
                    ? 'No puede abrirse como operación editable. Selecciona una fecha futura para continuar.'
                    : 'No puede abrirse en modo operativo. Se cargó el siguiente horario disponible; usa la vista histórica de solo lectura cuando necesites consultar fechas anteriores.',
                'hidden' => false,
            ];
        }
        if ($fechaInvalida) {
            http_response_code(422);
            $alertas['error'][] = 'La fecha seleccionada no tiene un formato valido. Se muestra la fecha actual.';
        }
        if ($soloLectura) {
            $alertas['warning'][] = 'Operacion historica en modo de solo lectura.';
        }
        if ($modoSolicitado !== '' && $modoSolicitado !== 'assign') {
            $alertas['warning'][] = 'La intención solicitada no es válida. Se cargó el mapa normalmente.';
            $reservacionId = false;
        } elseif ($modoSolicitado === 'assign') {
            $reservacionObjetivo = $reservacionId
                ? Reservacion::findWithMesas((int)$reservacionId)
                : null;
            $horaObjetivo = $reservacionObjetivo
                ? self::normalizarHoraCorta((string)$reservacionObjetivo->hora)
                : '';
            $permiteAsignacionObjetivo = $reservacionObjetivo
                && ReservacionService::puedeEditar($reservacionObjetivo);
            $motivo = '';

            if (!$reservacionObjetivo) {
                $motivo = 'La reservación seleccionada no existe.';
            } elseif (!$permiteAsignacionObjetivo) {
                $motivo = 'La reservación seleccionada ya no permite cambiar mesas.';
            } elseif ((string)$reservacionObjetivo->fecha !== $fecha) {
                $motivo = 'La reservación seleccionada corresponde a otra fecha.';
            } elseif ($horaSolicitada !== '' && $horaObjetivo !== $horaSolicitada) {
                $motivo = 'La reservación seleccionada ya no corresponde al horario solicitado.';
            }

            if ($motivo !== '') {
                $initialOperationNotice = [
                    'type' => 'restricted',
                    'title' => !$reservacionObjetivo
                        ? 'Reservación no encontrada'
                        : (!$permiteAsignacionObjetivo
                            ? 'Reservación no editable'
                            : 'No se pudo abrir la reasignación'),
                    'summary' => $motivo,
                    'message' => !$reservacionObjetivo
                        ? 'La reservación solicitada no existe o ya no está disponible. Se cargó el mapa normalmente para que selecciones otra.'
                        : (!$permiteAsignacionObjetivo
                            ? 'La reservación pertenece a un estado final o su horario ya pasó. Continúa usando el mapa o selecciona otra reservación editable.'
                            : 'Los datos de fecha u hora ya no coinciden. Se cargó el mapa normalmente para evitar modificar otra reservación.'),
                    'hidden' => false,
                ];
                $alertas['warning'][] = $motivo . ' Se cargó el mapa normalmente.';
                $reservacionId = false;
            } else {
                $hora = $horaObjetivo;
                $intencionAsignacion = true;
            }
        }

        self::render('reservations/index', [
            'title' => $soloLectura ? 'Mapa de reservaciones historico' : 'Mapa de reservaciones',
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
            'initialOperationIntent' => $intencionAsignacion ? 'assign' : '',
            'comentarioAdminDisponible' => true,
            'fechaMinima' => ReservacionConfig::fechaActual(),
            'modoSoloLectura' => $soloLectura,
            'operacionEditable' => $operacionEditable,
            'horaSolicitadaInicial' => $horaSolicitada,
            'initialOperationNotice' => $initialOperationNotice,
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
            return;
        }

        $fecha = $fechaFueEnviada ? $fechaSolicitada : ReservacionConfig::fechaActual();
        $soloLectura = HorarioReservacionService::fechaPasada($fecha);
        $horaSolicitada = self::normalizarHoraCorta((string)($_GET['hora'] ?? ''));
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
            return;
        }

        $horarios = array_values(array_filter(array_map(static function ($horario): string {
            return HorarioReservacionService::normalizarHoraCorta((string)$horario);
        }, $disponibilidad['horarios'] ?? [])));
        $resolucionHorario = HorarioReservacionService::resolverHorarioOperativo(
            $fecha,
            $horaSolicitada,
            $horarios,
            $soloLectura
        );
        $horaResuelta = (string)($resolucionHorario['hora_resuelta'] ?: $horaSolicitada);
        if ($horaResuelta === '') {
            $horaResuelta = ReservacionConfig::ahora()->format('H:i');
        }
        $lectura = PosReservacionQueryService::paraFecha($fecha, $horaResuelta, [
            'incluir_inactivas' => true,
            'calcular_conflictos' => true,
        ]);
        if (!($lectura['ok'] ?? false)) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => $lectura['codigo'] ?? ReservacionService::ERROR_INTERNO,
                'mensaje' => $lectura['mensaje'] ?? 'No se pudo cargar la operación.',
            ], 422);
            return;
        }
        $reservacionesSerializadas = (array)$lectura['reservaciones'];
        $evaluacionOcupacion = (array)$lectura['evaluacion_ocupacion'];
        $alertasPorReservacion = [];
        foreach ((array)($evaluacionOcupacion['alertas_operativas'] ?? []) as $alerta) {
            $alertasPorReservacion[(int)($alerta['reservacion_id'] ?? 0)] = $alerta;
        }
        foreach ($reservacionesSerializadas as &$reservacionSerializada) {
            $alerta = $alertasPorReservacion[(int)($reservacionSerializada['id'] ?? 0)] ?? null;
            $reservacionSerializada['conflicto_proximo'] = $alerta !== null;
            $reservacionSerializada['alerta_operativa'] = $alerta;
        }
        unset($reservacionSerializada);
        $reservacionesOperativas = \Services\ReservacionVigenciaService::filtrarPendientesOperacion(
            $reservacionesSerializadas,
            $fecha,
            $horarios
        );
        $idsOperativos = array_fill_keys(array_map(
            static fn(array $reservacion): int => (int)($reservacion['id'] ?? 0),
            $reservacionesOperativas
        ), true);
        foreach ($reservacionesSerializadas as &$reservacionSerializada) {
            $reservacionSerializada['en_lista_operativa'] = isset(
                $idsOperativos[(int)($reservacionSerializada['id'] ?? 0)]
            );
        }
        unset($reservacionSerializada);
        $mesasSerializadas = (array)$lectura['mesas'];
        $ocupacionPorReservacion = (array)$lectura['ocupacion_por_reservacion'];
        $estadosMesas = (array)$lectura['mesas_estado'];
        $resumenCapacidad = OcupacionMesasService::resumenCapacidad(
            $mesasSerializadas,
            $evaluacionOcupacion
        );
        $capacidadHorario = [
            'capacidad_total' => (int)($resumenCapacidad['capacidad_total'] ?? 0),
            'capacidad_realmente_libre' => (int)($resumenCapacidad['capacidad_realmente_libre'] ?? 0),
            'capacidad_proyectada' => (int)($resumenCapacidad['capacidad_proyectada'] ?? 0),
            'capacidad_estimada_horario' => (int)($resumenCapacidad['capacidad_estimada_horario'] ?? 0),
        ];
        $mostrarOcupacionFisica = in_array(
            (string)($evaluacionOcupacion['contexto'] ?? ''),
            [OcupacionMesasService::CONTEXTO_ACTUAL, OcupacionMesasService::CONTEXTO_PROYECTADO],
            true
        );
        $abierto = (bool)($disponibilidad['abierto'] ?? false);
        $estadoOperacion = !$abierto
            ? 'cerrado'
            : ($horarios === [] ? 'sin_horarios' : 'disponible');
        $mensajeOperacion = match ($estadoOperacion) {
            'cerrado' => 'No hay operación programada para esta fecha.',
            'sin_horarios' => 'No existen horarios disponibles para esta fecha.',
            default => null,
        };
        if (
            $estadoOperacion === 'sin_horarios'
            && $resolucionHorario['sin_horarios_futuros']
            && $resolucionHorario['solicitada_vencida']
        ) {
            $mensajeOperacion = 'El horario solicitado ya pasó y no quedan horarios operativos disponibles para hoy. Selecciona una fecha futura.';
        }
        $editable = !$soloLectura && $estadoOperacion === 'disponible';

        self::jsonResponse([
            'ok' => true,
            'codigo' => $soloLectura ? 'FECHA_PASADA_SOLO_LECTURA' : null,
            'modo' => $soloLectura ? 'solo_lectura' : 'operacion',
            'editable' => $editable,
            'fecha' => $fecha,
            'abierto' => $abierto,
            'estado_operacion' => $estadoOperacion,
            'origen' => $disponibilidad['origen'] ?? null,
            'tipo' => $disponibilidad['tipo'] ?? null,
            'mensaje' => $mensajeOperacion,
            'horarios' => $horarios,
            'hora_solicitada' => $resolucionHorario['hora_solicitada'],
            'hora_sugerida' => $resolucionHorario['hora_resuelta'],
            'hora_ajustada' => $resolucionHorario['ajustada'],
            'hora_solicitada_vencida' => $resolucionHorario['solicitada_vencida'],
            'sin_horarios_futuros' => $resolucionHorario['sin_horarios_futuros'],
            'reservaciones' => $reservacionesSerializadas,
            'reservaciones_operativas' => $reservacionesOperativas,
            'mesas' => $mesasSerializadas,
            'mesas_estado' => $estadosMesas,
            'schema_version' => $lectura['schema_version'],
            'server_time' => $lectura['server_time'],
            'timezone' => $lectura['timezone'],
            'contexto_ocupacion' => $evaluacionOcupacion['contexto'] ?? null,
            'capacidad_horario' => $capacidadHorario,
            'alertas_operativas' => $evaluacionOcupacion['alertas_operativas'] ?? [],
            // El mapa sólo recibe contexto de ocupación; no se exponen
            // consumos, importes ni acciones internas de los tickets.
            'ocupacion_fisica' => $mostrarOcupacionFisica
                ? self::serializarOcupacionFisica(
                    (array)($evaluacionOcupacion['ocupacion_fisica'] ?? [])
                )
                : [],
            'ocupacion_por_reservacion' => $ocupacionPorReservacion,
            'config' => [
                'estado_labels' => ReservacionService::estadoLabels(),
                'estados_editables' => ReservacionService::estadosEditables(),
                'transiciones' => ReservacionService::transiciones(),
                'comentario_admin_disponible' => true,
                'temporal' => $lectura['config']['temporal'],
            ],
            'actualizado_en' => ReservacionConfig::ahora()->format(DATE_ATOM),
        ]);
    }

    public static function apiAssignTables(): void
    {
        $id = self::reservacionIdOperacionPost();
        if ($id < 1) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS,
                'mensaje' => self::mensajeAsignacionApi(AsignacionMesasService::DATOS_INCOMPLETOS),
            ], 422);
            return;
        }
        $permitirCapacidadInsuficiente = false;
        $mesaIds = $_POST['mesa_ids'] ?? [];
        $contextoCompleto = array_key_exists('fecha', $_POST)
            && array_key_exists('hora', $_POST)
            && trim((string)($_POST['version_esperada'] ?? '')) !== ''
            && (string)($_POST['mesa_ids_actuales_presentes'] ?? '') === '1';
        $resultado = AsignacionMesasService::asignarManual(
            $id,
            (array)$mesaIds,
            $permitirCapacidadInsuficiente,
            true,
            [
                'ticket_ids_aceptados' => (array)($_POST['ticket_ids_aceptados'] ?? []),
                'conflicto_token' => (string)($_POST['conflicto_token'] ?? ''),
                'version_esperada' => (string)($_POST['version_esperada'] ?? ''),
                'usuario_id' => (int)($_SESSION['id'] ?? 0),
                'validar_contexto' => true,
                'contexto_completo' => $contextoCompleto,
                'fecha_esperada' => (string)($_POST['fecha'] ?? ''),
                'hora_esperada' => (string)($_POST['hora'] ?? ''),
                'mesa_ids_actuales' => (array)($_POST['mesa_ids_actuales'] ?? []),
                'permitir_superposicion_ticket_abierto' => true,
            ]
        );

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
            ReservacionService::ejecutarAccionOperativa(
                self::reservacionIdOperacionPost(),
                $estado,
                (int)($_SESSION['id'] ?? 0),
                trim((string)($_POST['motivo'] ?? '')),
                !empty($_POST['mesero_id']) ? (int)$_POST['mesero_id'] : null
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
        $reservacionId = (int)($params['reservation_id'] ?? $params['reservacion_id'] ?? 0);
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
            $query['reservation_id'] = $reservacionId;
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

    /**
     * Contrato mínimo de ocupación física para la vista de reservaciones.
     *
     * @param array<int, array<string, mixed>> $tickets
     * @return array<int, array<string, mixed>>
     */
    private static function serializarOcupacionFisica(array $tickets): array
    {
        return array_map(static function (array $ticket): array {
            return [
                'ticket_id' => (int)($ticket['id'] ?? $ticket['ticket_id'] ?? 0),
                'reservacion_id' => $ticket['reservacion_id'] ?? null,
                'mesa_ids' => array_values(array_map('intval', $ticket['mesa_ids'] ?? [])),
                'ticket_abierto' => true,
                'walk_in' => ($ticket['reservacion_id'] ?? null) === null,
                'origen' => (string)($ticket['origen'] ?? 'walk_in'),
                'hora_apertura' => (string)($ticket['hora_apertura'] ?? ''),
                'estado_proyeccion' => $ticket['estado_proyeccion'] ?? null,
                'liberacion_proyectada' => $ticket['liberacion_proyectada'] ?? null,
            ];
        }, $tickets);
    }

    private static function reservacionIdOperacionPost(): int
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::jsonResponse([
                'ok' => false,
                'codigo' => 'METODO_INVALIDO',
                'mensaje' => 'Metodo invalido.',
            ], 405);
            return 0;
        }

        $id = filter_var(
            $_POST['reservation_id'] ?? $_POST['reservacion_id'] ?? ($_POST['id'] ?? 0),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id ? (int)$id : 0;
    }

    private static function jsonResultadoAsignacion(array $resultado, string $mensajeExito): void
    {
        $codigo = (string)($resultado['codigo'] ?? AsignacionMesasService::ERROR_INTERNO);
        $ok = (bool)($resultado['ok'] ?? false);
        $httpStatus = $ok ? 200 : match ($codigo) {
            AsignacionMesasService::ERROR_INTERNO => 500,
            AsignacionMesasService::CONFLICTO_CONCURRENTE,
            AsignacionMesasService::VERSION_DESACTUALIZADA,
            AsignacionMesasService::MESA_OCUPADA,
            AsignacionMesasService::CONFLICTO_TICKETS_ABIERTOS,
            AsignacionMesasService::RESERVACION_NO_EDITABLE => 409,
            AsignacionMesasService::SUPERPOSICION_NO_AUTORIZADA => 403,
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
            'requiere_confirmacion' => (bool)($resultado['requiere_confirmacion'] ?? false),
            'conflictos_ticket' => $resultado['conflictos_ticket'] ?? [],
            'conflicto_token' => $resultado['conflicto_token'] ?? null,
            'tickets_aceptados' => $resultado['tickets_aceptados'] ?? [],
            'version_actual' => $resultado['version_actual'] ?? null,
            'depende_liberacion_proyectada' => (bool)($resultado['depende_liberacion_proyectada'] ?? false),
            'mesas_proyectadas' => $resultado['mesas_proyectadas'] ?? [],
            'advertencia' => $resultado['advertencia'] ?? null,
        ], $httpStatus);
    }

    private static function jsonResultadoTransicion(array $resultado, string $mensajeExito): void
    {
        $codigo = (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO);
        $ok = (bool)($resultado['ok'] ?? false);
        $httpStatus = $ok ? 200 : match ($codigo) {
            ReservacionService::ERROR_INTERNO => 500,
            ReservacionService::RESERVACION_NO_EXISTE,
            PuntoVentaReservacionService::NO_EXISTE => 404,
            PuntoVentaReservacionService::CONFLICTO_CONCURRENTE => 409,
            ReservacionService::RESERVACION_PASADA,
            ReservacionService::RESERVACION_HORARIO_PASADO => 409,
            default => 422,
        };

        self::jsonResponse([
            'ok' => $ok,
            'codigo' => $codigo,
            'mensaje' => $ok ? $mensajeExito : self::mensajeTransicionApi($codigo),
            'requiere_reasignacion' => (bool)($resultado['requiere_reasignacion'] ?? false),
            'motivo' => $resultado['motivo'] ?? null,
            'mesa_ids' => $resultado['mesa_ids'] ?? [],
            'ticket_id' => $resultado['ticket_id'] ?? null,
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
            AsignacionMesasService::RESERVACION_NO_EDITABLE => 'estado_no_permite',
            AsignacionMesasService::VERSION_DESACTUALIZADA,
            AsignacionMesasService::CONFLICTO_CONCURRENTE => 'conflicto_concurrencia',
            AsignacionMesasService::DATOS_INCOMPLETOS => 'datos_incompletos',
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
            AsignacionMesasService::CONFLICTO_TICKETS_ABIERTOS => 'La selección incluye mesas con servicio activo. Revisa los tickets antes de continuar.',
            AsignacionMesasService::CONFLICTO_CONCURRENTE => 'La ocupación cambió mientras confirmabas. Revisa nuevamente las mesas y tickets.',
            AsignacionMesasService::VERSION_DESACTUALIZADA => 'La reservación cambió desde que abriste el mapa. Actualiza los datos antes de reasignar.',
            AsignacionMesasService::DATOS_INCOMPLETOS => 'Faltan la reservación, fecha, hora, versión o mesas actuales de la asignación.',
            AsignacionMesasService::RESERVACION_NO_EDITABLE => 'La reservación ya no permite modificar sus mesas.',
            AsignacionMesasService::SUPERPOSICION_NO_AUTORIZADA => 'La superposición con un ticket abierto sólo puede confirmarse desde el modo de asignación del mapa.',
            AsignacionMesasService::CAPACIDAD_INSUFICIENTE => 'La capacidad seleccionada es insuficiente.',
            AsignacionMesasService::AGRUPACION_NO_AUTORIZADA => 'La selección no corresponde a una agrupación de mesas autorizada para reservaciones.',
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
            PuntoVentaReservacionService::TOLERANCIA_VIGENTE => 'La tolerancia de 15 minutos sigue vigente.',
            PuntoVentaReservacionService::TICKET_ABIERTO => 'La reservacion ya tiene un ticket abierto.',
            PuntoVentaReservacionService::REQUIERE_REASIGNACION => 'Las mesas originales ya no estan disponibles. Reasigna mesas para registrar la llegada tardia.',
            PuntoVentaReservacionService::SIN_CAPACIDAD => 'La asignacion actual no tiene capacidad suficiente. Resuelve las mesas manualmente.',
            PuntoVentaReservacionService::DATOS_INVALIDOS => 'Completa los datos requeridos para esta accion.',
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
