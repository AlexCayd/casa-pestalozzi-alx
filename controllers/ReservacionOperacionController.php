<?php

/**
 * Coordina la herramienta operativa de reservaciones.
 * Las reglas de horarios, estados y mesas permanecen en los servicios de dominio.
 */

namespace Controllers;

use Classes\Auth;
use Model\Reservacion;
use MVC\Router;
use Services\AsignacionMesasService;
use Services\AdminCsrfService;
use Services\HorarioReservacionService;
use Services\OcupacionMesasService;
use Services\PosReservacionQueryService;
use Services\PosReservacionSerializer;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionErrorCatalog;
use Services\ReservacionMapaAdministrativaService;
use Services\ReservacionService;

class ReservacionOperacionController
{
    private const OPERATION_CSS = '/build/css/operation/reservations.css?v=reservation-operation-v29';
    private const OPERATION_JS = '/build/js/admin/reservation-operation.js?v=reservation-operation-v30';

    public static function operation(Router $router): void
    {
        $esAdmin = Auth::esAdmin();
        $esMesero = Auth::esMesero();
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
        $resolucionHorario = HorarioReservacionService::resolverHorarioMapa(
            $fecha,
            $horaSolicitada,
            $horariosIniciales
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
            $initialOperationNotice = self::avisoCatalogo(
                $sinHorarios ? 'HORARIO_SIN_CONFIGURACION' : 'HORARIO_PASADO'
            );
        }
        if ($fechaInvalida) {
            http_response_code(422);
            $alertas['error'][] = ReservacionErrorCatalog::presentar('FECHA_INVALIDA')['mensaje'];
        }
        if ($soloLectura) {
            $alertas['warning'][] = ReservacionErrorCatalog::presentar('FECHA_PASADA_SOLO_LECTURA')['mensaje'];
        }
        if ($modoSolicitado !== '' && $modoSolicitado !== 'assign') {
            $alertas['warning'][] = ReservacionErrorCatalog::presentar('DATOS_INVALIDOS')['mensaje'];
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
            $motivoCodigo = null;

            if (!$reservacionObjetivo) {
                $motivoCodigo = 'RESERVACION_NO_ENCONTRADA';
            } elseif (!$permiteAsignacionObjetivo) {
                $motivoCodigo = 'RESERVACION_NO_EDITABLE';
            } elseif ((string)$reservacionObjetivo->fecha !== $fecha) {
                $motivoCodigo = 'FECHA_RESPUESTA_MISMATCH';
            } elseif ($horaSolicitada !== '' && $horaObjetivo !== $horaSolicitada) {
                $motivoCodigo = 'HORARIO_INVALIDO';
            }

            if ($motivoCodigo !== null) {
                $initialOperationNotice = self::avisoCatalogo($motivoCodigo, 'restricted');
                $alertas['warning'][] = ReservacionErrorCatalog::presentar($motivoCodigo)['mensaje'];
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
            'puedeCrearAdministrativa' => $esAdmin,
            'puedeCrearDesdeMapa' => $esAdmin || $esMesero,
            'puedeCapturarContacto' => $esAdmin,
            'createReservationAction' => $esAdmin
                ? '/admin/reservaciones/crear'
                : '/admin/api/reservaciones/operacion/crear',
            'availabilityEndpoint' => $esAdmin
                ? '/admin/api/reservaciones/disponibilidad'
                : '/admin/api/reservaciones/operacion/disponibilidad',
            'operationalHeaderBack' => $esAdmin,
            'horaSolicitadaInicial' => $horaSolicitada,
            'initialOperationNotice' => $initialOperationNotice,
            'fechaInvalidaRecibida' => $fechaInvalida ? $fechaSolicitada : '',
            'adminCsrfToken' => AdminCsrfService::token(),
        ]);
    }

    /**
     * Alta compartida desde el mapa operativo.
     *
     * El servicio administrativo conserva la validacion de horario,
     * capacidad, asignacion, advertencias e idempotencia. El waiter solo
     * cambia la frontera de contacto: el servidor ignora cualquier valor
     * manipulado y confirma internamente el alta sin contacto.
     */
    public static function createFromOperation(Router $router): void
    {
        $_POST = self::normalizarDatosAltaOperativa($_POST, Auth::esMesero());

        AdminReservacionController::store($router);
    }

    /** @return array<string, mixed> */
    private static function normalizarDatosAltaOperativa(array $post, bool $esMesero): array
    {
        if (!$esMesero) {
            return $post;
        }

        $post['contacto_tipo'] = 'ninguno';
        $post['contacto'] = null;
        $post['confirmar_sin_contacto'] = '1';

        $confirmaciones = $post['confirmaciones'] ?? [];
        if (is_string($confirmaciones)) {
            $confirmaciones = preg_split('/[,|\s]+/', $confirmaciones, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($confirmaciones)) {
            $confirmaciones = [];
        }
        $confirmaciones[] = 'SIN_CONTACTO';
        $post['confirmaciones'] = array_values(array_unique(array_map('strval', $confirmaciones)));

        return $post;
    }

    /** Disponibilidad de horarios para el formulario alojado en el mapa. */
    public static function availability(): void
    {
        AdminReservacionController::disponibilidad();
    }

    public static function operationData(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $fechaFueEnviada = array_key_exists('fecha', $_GET);
        $fechaSolicitada = trim((string)($_GET['fecha'] ?? ''));
        if ($fechaFueEnviada && !HorarioReservacionService::fechaValida($fechaSolicitada)) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => HorarioReservacionService::FECHA_INVALIDA,
                'fecha' => $fechaSolicitada,
                'horarios' => [],
            ], 422);
            return;
        }

        $fecha = $fechaFueEnviada ? $fechaSolicitada : ReservacionConfig::fechaActual();
        $soloLectura = HorarioReservacionService::fechaPasada($fecha);
        $reservacionExcluidaRaw = trim((string)(
            $_GET['reservation_id'] ?? $_GET['reservacion_id'] ?? ''
        ));
        $reservacionExcluida = preg_match('/^[1-9]\d*$/D', $reservacionExcluidaRaw) === 1
            ? (int)$reservacionExcluidaRaw
            : 0;
        $horaSolicitada = self::normalizarHoraCorta((string)($_GET['hora'] ?? ''));
        $disponibilidad = ReservacionService::obtenerHorariosDisponiblesParaFecha($fecha, $soloLectura);
        if (!($disponibilidad['ok'] ?? false)) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => $disponibilidad['codigo'] ?? ReservacionService::ERROR_INTERNO,
                'fecha' => $fecha,
                'horarios' => [],
            ], ReservacionErrorCatalog::httpStatus(
                (string)($disponibilidad['codigo'] ?? ReservacionService::ERROR_INTERNO),
                422
            ));
            return;
        }

        $horarios = array_values(array_filter(array_map(static function ($horario): string {
            return HorarioReservacionService::normalizarHoraCorta((string)$horario);
        }, $disponibilidad['horarios'] ?? [])));
        $resolucionHorario = HorarioReservacionService::resolverHorarioMapa(
            $fecha,
            $horaSolicitada,
            $horarios
        );
        $horaResuelta = (string)($resolucionHorario['hora_resuelta'] ?: $horaSolicitada);
        if ($horaResuelta === '') {
            $horaResuelta = ReservacionConfig::ahora()->format('H:i');
        }
        $lectura = PosReservacionQueryService::paraFecha($fecha, $horaResuelta, [
            'incluir_inactivas' => true,
            'calcular_conflictos' => true,
            'excluir_reservacion_id' => $reservacionExcluida,
            'reservacion_en_edicion_id' => $reservacionExcluida,
            'incluir_contexto_administrativo' => true,
            'superficie' => Auth::esAdmin() ? 'admin' : 'waiter',
        ]);
        if (!($lectura['ok'] ?? false)) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => $lectura['codigo'] ?? ReservacionService::ERROR_INTERNO,
            ], 422);
            return;
        }
        $reservacionesSerializadas = array_values(array_filter(
            (array)$lectura['reservaciones'],
            static fn(array $reservacion): bool => (string)($reservacion['estado'] ?? '') === 'confirmada'
        ));
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
        $proyeccionAdministrativa = ReservacionMapaAdministrativaService::proyectar(
            $reservacionesSerializadas,
            $reservacionesOperativas
        );
        $reservacionesSerializadas = $proyeccionAdministrativa['reservaciones'];
        $reservacionesAdministrativas = $proyeccionAdministrativa['reservaciones_admin'];
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
        \Services\CapacidadReservacionesService::registrarEvaluacion(
            $resumenCapacidad + [
                'fecha' => $fecha,
                'hora' => (string)($evaluacionOcupacion['hora'] ?? ''),
            ],
            'mapa',
            0,
            null,
            'consulta_mapa'
        );
        $capacidadHorario = [
            'capacidad_total' => (int)($resumenCapacidad['capacidad_total'] ?? 0),
            'capacidad_realmente_libre' => (int)($resumenCapacidad['capacidad_realmente_libre'] ?? 0),
            'capacidad_proyectada' => (int)($resumenCapacidad['capacidad_proyectada'] ?? 0),
            'capacidad_estimada_horario' => (int)($resumenCapacidad['capacidad_estimada_horario'] ?? 0),
            'capacidad_fisica_total' => (int)($resumenCapacidad['capacidad_fisica_total'] ?? 0),
            'capacidad_fisica_comprometida' => (int)($resumenCapacidad['capacidad_fisica_comprometida'] ?? 0),
            'capacidad_fisica_libre' => (int)($resumenCapacidad['capacidad_fisica_libre'] ?? 0),
            'demanda_no_asignada' => (int)($resumenCapacidad['demanda_no_asignada'] ?? 0),
            'capacidad_real_disponible' => (int)($resumenCapacidad['capacidad_real_disponible'] ?? 0),
            'exceso_capacidad' => (int)($resumenCapacidad['exceso_capacidad'] ?? 0),
            'mesas_total' => (int)($resumenCapacidad['mesas_total'] ?? 0),
            'mesas_bloqueadas' => (int)($resumenCapacidad['mesas_bloqueadas'] ?? 0),
            'mesas_libres' => (int)($resumenCapacidad['mesas_libres'] ?? 0),
            'mesa_ids_bloqueadas' => array_values(array_map(
                'intval',
                (array)($resumenCapacidad['mesa_ids_bloqueadas'] ?? [])
            )),
            'mesa_ids_libres' => array_values(array_map(
                'intval',
                (array)($resumenCapacidad['mesa_ids_libres'] ?? [])
            )),
            'depende_liberacion_proyectada' => (bool)($resumenCapacidad['depende_liberacion_proyectada'] ?? false),
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
        $codigoOperacion = $resolucionHorario['solicitada_vencida']
            ? ($resolucionHorario['sin_horarios_futuros'] ? 'HORARIO_SIN_CONFIGURACION' : 'HORARIO_PASADO')
            : ($soloLectura
                ? 'FECHA_PASADA_SOLO_LECTURA'
                : ($estadoOperacion === 'cerrado'
                    ? 'DIA_INACTIVO'
                    : ($estadoOperacion === 'sin_horarios' ? 'HORARIO_SIN_CONFIGURACION' : null)));
        $editable = !$soloLectura && $estadoOperacion === 'disponible';
        $horariosMapa = array_values(array_filter(array_map(
            static fn($horario): string => HorarioReservacionService::normalizarHoraCorta((string)$horario),
            HorarioReservacionService::horariosConfiguradosParaMapa($fecha)
        )));
        $esAdmin = Auth::esAdmin();
        $estadosEditables = $esAdmin ? ReservacionService::estadosEditables() : ['confirmada'];
        $transiciones = $esAdmin
            ? ReservacionService::transiciones()
            : ['confirmada' => ['en_curso', 'cancelada', 'no_show']];

        self::jsonResponse([
            'ok' => true,
            'codigo' => $codigoOperacion,
            'modo' => $soloLectura ? 'solo_lectura' : 'operacion',
            'editable' => $editable,
            'fecha' => $fecha,
            'hora' => (string)($evaluacionOcupacion['hora'] ?? $resolucionHorario['hora_resuelta'] ?? ''),
            'abierto' => $abierto,
            'estado_operacion' => $estadoOperacion,
            'origen' => $disponibilidad['origen'] ?? null,
            'tipo' => $disponibilidad['tipo'] ?? null,
            'horarios' => $horarios,
            'horarios_reservables' => $disponibilidad['horarios_reservables'] ?? $horarios,
            'horarios_mapa' => $horariosMapa,
            'hora_solicitada' => $resolucionHorario['hora_solicitada'],
            'hora_sugerida' => $resolucionHorario['hora_resuelta'],
            'hora_ajustada' => $resolucionHorario['ajustada'],
            'hora_solicitada_vencida' => $resolucionHorario['solicitada_vencida'],
            'sin_horarios_futuros' => $resolucionHorario['sin_horarios_futuros'],
            'reservaciones' => $reservacionesSerializadas,
            'reservaciones_operativas' => $reservacionesOperativas,
            'reservaciones_admin' => $reservacionesAdministrativas,
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
                'estados_editables' => $estadosEditables,
                'transiciones' => $transiciones,
                'comentario_admin_disponible' => true,
                'temporal' => $lectura['config']['temporal'],
            ],
            'actualizado_en' => ReservacionConfig::ahora()->format(DATE_ATOM),
        ]);
    }

    public static function apiAssignTables(): void
    {
        if (!self::csrfValido()) {
            self::csrfFailure();
        }
        $id = self::reservacionIdOperacionPost();
        if ($id < 1) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS,
            ], 422);
            return;
        }
        $mesaIds = $_POST['mesa_ids'] ?? [];
        $contextoCompleto = array_key_exists('fecha', $_POST)
            && array_key_exists('hora', $_POST)
            && trim((string)($_POST['version_esperada'] ?? '')) !== ''
            && (string)($_POST['mesa_ids_actuales_presentes'] ?? '') === '1';
        $resultado = ReservacionMapaAdministrativaService::guardarAsignacion(
            $id,
            (array)$mesaIds,
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
                'confirmaciones' => (array)($_POST['confirmaciones'] ?? []),
            ]
        );

        self::jsonResultadoAsignacion($resultado);
    }

    public static function apiClearTables(): void
    {
        if (!self::csrfValido()) {
            self::csrfFailure();
        }
        $id = self::reservacionIdOperacionPost();
        if ($id < 1) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS,
            ], 422);
            return;
        }

        $contextoCompleto = array_key_exists('fecha', $_POST)
            && array_key_exists('hora', $_POST)
            && trim((string)($_POST['version_esperada'] ?? '')) !== ''
            && (string)($_POST['mesa_ids_actuales_presentes'] ?? '') === '1';
        $resultado = ReservacionMapaAdministrativaService::liberarAsignacion($id, [
            'version_esperada' => (string)($_POST['version_esperada'] ?? ''),
            'permitir_liberacion_operativa' => Auth::esMesero(),
            'validar_contexto' => true,
            'contexto_completo' => $contextoCompleto,
            'fecha_esperada' => (string)($_POST['fecha'] ?? ''),
            'hora_esperada' => (string)($_POST['hora'] ?? ''),
            'mesa_ids_actuales' => (array)($_POST['mesa_ids_actuales'] ?? []),
            'confirmaciones' => (array)($_POST['confirmaciones'] ?? []),
        ]);

        self::jsonResultadoAsignacion($resultado);
    }

    public static function apiReasignarAutomaticamente(): void
    {
        if (!self::csrfValido()) {
            self::csrfFailure();
        }
        $resultado = AsignacionMesasService::asignarAutomaticamente(self::reservacionIdOperacionPost());

        self::jsonResultadoAsignacion($resultado);
    }

    public static function apiUpdateComment(): void
    {
        if (!self::csrfValido()) {
            self::csrfFailure();
        }
        $id = self::reservacionIdOperacionPost();

        $comentario = substr((string)($_POST['comentario_admin'] ?? ''), 0, 5000);
        $resultado = ReservacionService::actualizarComentario($id, $comentario);
        $codigo = (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO);

        self::jsonResponse([
            'ok' => (bool)($resultado['ok'] ?? false),
            'codigo' => $codigo,
        ], ($resultado['ok'] ?? false) ? 200 : ReservacionErrorCatalog::httpStatus($codigo, 422));
    }

    public static function apiStatus(): void
    {
        if (!self::csrfValido()) {
            self::csrfFailure();
        }
        $estado = (string)($_POST['estado'] ?? '');

        // El endpoint conserva el contrato compartido, pero un waiter sólo
        // puede iniciar servicio, cancelar o registrar no-show desde esta
        // superficie operativa. Confirmación y demás transiciones quedan en
        // los flujos administrativos correspondientes.
        if (Auth::esMesero() && !in_array($estado, ['en_curso', 'cancelada', 'no_show'], true)) {
            self::jsonResponse([
                'ok' => false,
                'codigo' => ReservacionService::ESTADO_INVALIDO,
            ], 422);
            return;
        }

        self::jsonResultadoTransicion(
            ReservacionService::ejecutarAccionOperativa(
                self::reservacionIdOperacionPost(),
                $estado,
                (int)($_SESSION['id'] ?? 0),
                trim((string)($_POST['motivo'] ?? '')),
                !empty($_POST['mesero_id']) ? (int)$_POST['mesero_id'] : null
            )
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

    /** @return array<string, mixed> */
    private static function avisoCatalogo(string $codigo, string $tipo = 'warning'): array
    {
        $presentacion = ReservacionErrorCatalog::presentar($codigo);
        return [
            'codigo' => $codigo,
            'type' => $tipo,
            'title' => $presentacion['titulo'],
            'summary' => $presentacion['mensaje'],
            'mensaje' => $presentacion['consecuencia'],
            'hidden' => false,
        ];
    }

    private static function normalizarHoraCorta(string $hora): string
    {
        return HorarioReservacionService::normalizarHoraCorta($hora);
    }

    private static function returnUrlSeguro($url, string $fallback = ''): string
    {
        $url = (string)$url;

        if ($url !== '' && str_starts_with($url, '/admin/reservaciones')) {
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

        return '/admin/reservaciones/operacion' . (!empty($query) ? '?' . http_build_query($query) : '');
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

    private static function jsonResultadoAsignacion(array $resultado): void
    {
        $codigo = (string)($resultado['codigo'] ?? AsignacionMesasService::ERROR_INTERNO);
        $ok = (bool)($resultado['ok'] ?? false);
        $httpStatus = $ok ? 200 : ReservacionErrorCatalog::httpStatus($codigo, 422);
        $decisiones = ReservacionErrorCatalog::decisionesResultado($resultado);

        self::jsonResponse([
            'ok' => $ok,
            'codigo' => $codigo,
            'mesa_ids' => $resultado['mesa_ids'] ?? [],
            'requiere_confirmacion' => (bool)($resultado['requiere_confirmacion'] ?? false),
            'conflictos_ticket' => $resultado['conflictos_ticket'] ?? [],
            'conflicto_token' => $resultado['conflicto_token'] ?? null,
            'tickets_aceptados' => $resultado['tickets_aceptados'] ?? [],
            'version_actual' => $resultado['version_actual'] ?? null,
            'depende_liberacion_proyectada' => (bool)($resultado['depende_liberacion_proyectada'] ?? false),
            'mesas_proyectadas' => $resultado['mesas_proyectadas'] ?? [],
            'advertencia' => $resultado['advertencia'] ?? null,
            'advertencias' => $resultado['advertencias'] ?? [],
            'confirmaciones_requeridas' => $decisiones,
            'requiredConfirmations' => $decisiones,
            'mesas_liberadas' => $resultado['mesas_liberadas'] ?? [],
        ], $httpStatus);
    }

    private static function jsonResultadoTransicion(array $resultado): void
    {
        $codigo = (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO);
        $ok = (bool)($resultado['ok'] ?? false);
        $httpStatus = $ok ? 200 : ReservacionErrorCatalog::httpStatus($codigo, 422);

        self::jsonResponse([
            'ok' => $ok,
            'codigo' => $codigo,
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
            AsignacionMesasService::CONFLICTO_TICKET_ABIERTO,
            AsignacionMesasService::CONFLICTO_TICKETS_ABIERTOS,
            AsignacionMesasService::DEPENDE_LIBERACION_PROYECTADA => 'conflicto_tickets',
            AsignacionMesasService::SIN_CONTACTO => 'asignacion_guardada',
            AsignacionMesasService::ESTADO_INVALIDO => 'estado_no_permite',
            AsignacionMesasService::RESERVACION_NO_EDITABLE => 'estado_no_permite',
            AsignacionMesasService::VERSION_DESACTUALIZADA,
            AsignacionMesasService::CONFLICTO_CONCURRENTE => 'conflicto_concurrencia',
            AsignacionMesasService::DATOS_INCOMPLETOS => 'datos_incompletos',
            AsignacionMesasService::RESERVACION_NO_EXISTE => 'no_existe',
            default => 'error_interno',
        };
    }

    private static function jsonResponse(array $data, int $status = 200): void
    {
        if (array_key_exists('codigo', $data) && $data['codigo'] !== null) {
            $data = ReservacionErrorCatalog::enriquecer($data, ['superficie' => 'mapa']);
        }
        if (Auth::esMesero()) {
            $data = PosReservacionSerializer::sanitizarParaWaiter($data);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    private static function csrfValido(): bool
    {
        return AdminCsrfService::validar(
            $_POST['admin_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
        );
    }

    private static function csrfFailure(): void
    {
        self::jsonResponse([
            'ok' => false,
            'codigo' => 'CSRF_INVALIDO',
        ], 419);
    }

    private static function alertasResultado(string $resultado): array
    {
        $codigos = [
            'creada' => 'RESERVACION_CREADA',
            'creada_sin_mesas' => 'RESERVACION_CREADA_SIN_MESA',
            'actualizada' => 'ACTUALIZADA',
            'actualizada_requiere_asignacion' => 'ACTUALIZADA_REQUIERE_ASIGNACION',
            'confirmada' => 'CONFIRMADA',
            'cancelada' => 'CANCELADA',
            'completada' => 'COMPLETADA',
            'no_show' => 'NO_SHOW',
            'reasignada' => 'ASIGNACION_GUARDADA',
            'asignacion_guardada' => 'ASIGNACION_GUARDADA',
            'comentario_guardado' => 'COMENTARIO_ACTUALIZADO',
            'reasignar_sin_capacidad' => 'CAPACIDAD_INSUFICIENTE',
            'asignacion_vacia' => 'ASIGNACION_VACIA',
            'mesas_invalidas' => 'MESAS_INVALIDAS',
            'mesa_ocupada' => 'MESA_OCUPADA',
            'capacidad_insuficiente' => 'CAPACIDAD_INSUFICIENTE',
            'conflicto_tickets' => 'CONFLICTO_TICKETS_ABIERTOS',
            'conflicto_concurrencia' => 'CONFLICTO_CONCURRENTE',
            'datos_incompletos' => 'DATOS_INCOMPLETOS',
            'estado_no_permite' => 'ESTADO_INVALIDO',
            'estado_invalido' => 'ESTADO_INVALIDO',
            'datos_invalidos' => 'DATOS_INVALIDOS',
            'horario_invalido' => 'HORARIO_INVALIDO',
            'estado_no_editable' => 'ESTADO_NO_EDITABLE',
            'reservacion_pasada' => 'RESERVACION_PASADA',
            'reservacion_horario_pasado' => 'RESERVACION_HORARIO_PASADO',
            'comentario_migracion_pendiente' => 'COMENTARIO_NO_DISPONIBLE',
            'confirmar_sin_mesa' => 'CONFIRMAR_SIN_MESA',
            'no_existe' => 'RESERVACION_NO_ENCONTRADA',
            'metodo_invalido' => 'METODO_INVALIDO',
            'error_interno' => 'ERROR_INTERNO',
        ];
        $codigo = $codigos[$resultado] ?? null;
        if ($codigo === null || !ReservacionErrorCatalog::has($codigo)) {
            return [];
        }
        $definicion = ReservacionErrorCatalog::definition($codigo);
        $presentacion = ReservacionErrorCatalog::presentar($codigo);
        $campo = in_array($definicion['tipo'], [
            ReservacionErrorCatalog::TIPO_INFORMACION,
        ], true) ? 'exito' : (in_array($definicion['tipo'], [
            ReservacionErrorCatalog::TIPO_ADVERTENCIA,
            ReservacionErrorCatalog::TIPO_DECISION,
        ], true) ? 'warning' : 'error');
        return [$campo => [$presentacion['mensaje']]];
    }
}
