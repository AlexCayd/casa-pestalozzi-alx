<?php

/**
 * Controla las pantallas administrativas de reservaciones.
 * Procesa entradas HTTP y delega reglas a los servicios del modulo.
 */

namespace Controllers;

use Model\Mesa;
use Model\Reservacion;
use Model\ReservacionMesa;
use Model\TicketMesa;
use Model\Ticket;
use MVC\Router;
use Services\AsignacionMesasService;
use Services\AdminCsrfService;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionErrorCatalog;
use Services\ReservacionService;

class AdminReservacionController
{
    private const RESERVATIONS_CSS = '/build/css/admin/reservations.css?v=reservation-form-v8';
    private const RESERVATION_FORM_JS = '/build/js/admin/reservation-form.js?v=reservation-form-v8';

    public static function index(Router $router): void
    {
        $filtros = self::leerFiltros();
        $reservaciones = Reservacion::buscarAdmin($filtros);
        $metricas = Reservacion::metricasAdmin($filtros);
        $ticketsPorReservacion = [];
        foreach (TicketMesa::abiertosParaMapa() as $ticket) {
            $reservacionId = (int)($ticket['reservacion_id'] ?? 0);
            if ($reservacionId > 0) {
                $ticketsPorReservacion[$reservacionId] = $ticket;
            }
        }
        foreach ($reservaciones as $reservacion) {
            $vigencia = ReservacionService::clasificarVigencia(
                $reservacion,
                $ticketsPorReservacion[(int)($reservacion->id ?? 0)] ?? null
            );
            foreach ($vigencia as $campo => $valor) {
                $reservacion->{$campo} = $valor;
            }
            $reservacion->ticket_id_abierto = (int)($ticketsPorReservacion[(int)($reservacion->id ?? 0)]['id'] ?? 0);
        }

        $data = [
            'title' => 'Reservaciones',
            'topbarSection' => 'Reservaciones',
            'reservaciones' => $reservaciones,
            'metricas' => $metricas,
            'filtros' => $filtros,
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            'estadoLabels' => ReservacionService::estadoLabels(),
            'alertas' => self::alertasResultado($_GET['resultado'] ?? ''),
            'queryString' => http_build_query($filtros),
            'partialUrl' => AdminController::filterUrl('/admin/reservations', $filtros),
            'developmentTools' => ReservacionConfig::appEnvironment() === 'development',
        ];

        if (AdminController::isPartialRequest()) {
            AdminController::renderPartial('reservations/index', array_merge($data, ['partialOnly' => true]));
            return;
        }

        self::render('reservations/index', $data);
    }

    public static function create(Router $router): void
    {
        $reservacion = new Reservacion();
        $reservacion->fecha = HorarioReservacionService::fechaSeguraGet((string)($_GET['fecha'] ?? ReservacionConfig::fechaActual()));
        $reservacion->hora = '';
        $reservacion->comensales = 2;
        $reservacion->estado = 'confirmada';
        $reservacion->request_token = ReservacionService::generarRequestToken();

        self::renderCreate($reservacion, [], self::alertasResultado($_GET['resultado'] ?? ''), true);
    }

    public static function disponibilidad(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            self::jsonResponse([
                'ok' => false,
                'codigo' => 'METODO_NO_PERMITIDO',
                'horarios' => [],
            ], 405);
            return;
        }

        $reservacionId = filter_var(
            $_GET['reservacion_id'] ?? $_GET['reservation_id'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $respuesta = DisponibilidadReservacionService::consultarAdministrativa(
            (string)($_GET['fecha'] ?? ''),
            $_GET['personas'] ?? $_GET['comensales'] ?? null,
            $reservacionId ? (int)$reservacionId : 0,
            isset($_GET['hora']) ? (string)$_GET['hora'] : null
        );
        self::jsonResponse(
            $respuesta,
            ($respuesta['ok'] ?? false)
                ? 200
                : ReservacionErrorCatalog::httpStatus((string)($respuesta['codigo'] ?? 'ERROR_INTERNO'), 422)
        );
    }

    public static function store(Router $router): void
    {
        $expectsJson = self::expectsJsonRequest();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($expectsJson) {
                self::jsonResponse([
                    'success' => false,
                    'ok' => false,
                    'reservationId' => null,
                    'fieldErrors' => [],
                    'codigo' => 'METODO_INVALIDO',
                ], 405);
                return;
            }
            self::redirectToIndex('metodo_invalido');
        }

        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::csrfFailure($expectsJson);
        }

        $resultado = ReservacionErrorCatalog::enriquecer(
            ReservacionService::crearAdministrativa(
                $_POST,
                (int)($_SESSION['id'] ?? 0) ?: null
            ),
            ['superficie' => 'administracion']
        );

        if ($resultado['ok'] ?? false) {
            $id = (int)($resultado['id'] ?? 0);
            if ($expectsJson) {
                self::jsonResponse([
                    'success' => true,
                    'ok' => true,
                    'reservationId' => $id > 0 ? $id : null,
                    'mensaje' => (string)($resultado['mensaje'] ?? ''),
                    'fieldErrors' => [],
                    'codigo' => (string)($resultado['codigo'] ?? ReservacionService::CREADA),
                    'fecha' => (string)($_POST['fecha'] ?? ''),
                    'hora' => HorarioReservacionService::normalizarHoraCorta((string)($_POST['hora'] ?? '')),
                    'asignacion' => (string)($resultado['asignacion'] ?? 'PENDIENTE'),
                    'mesaIds' => array_values(array_map('intval', (array)($resultado['mesa_ids'] ?? []))),
                    'requiresConfirmation' => (bool)($resultado['requiere_confirmacion'] ?? false),
                    'requiresManualAssignment' => (bool)($resultado['requiere_asignacion_manual'] ?? false),
                    'withoutContact' => (bool)($resultado['sin_contacto'] ?? false),
                    'requestedCapacity' => (int)($resultado['capacidad_solicitada'] ?? 0),
                    'availableCapacity' => (int)($resultado['capacidad_disponible'] ?? 0),
                    'physicalCapacityTotal' => (int)($resultado['capacidad_fisica_total'] ?? 0),
                    'physicalCapacityCommitted' => (int)($resultado['capacidad_fisica_comprometida'] ?? 0),
                    'physicalCapacityFree' => (int)($resultado['capacidad_fisica_libre'] ?? 0),
                    'unassignedDemand' => (int)($resultado['demanda_no_asignada'] ?? 0),
                    'realAvailableCapacity' => (int)($resultado['capacidad_real_disponible'] ?? 0),
                    'resultingCapacity' => (int)($resultado['capacidad_resultante'] ?? 0),
                    'excessCapacity' => (int)($resultado['exceso_capacidad'] ?? 0),
                    'warning' => $resultado['warning'] ?? null,
                    'dependsOnProjectedRelease' => (bool)($resultado['depende_liberacion_proyectada'] ?? false),
                    'projectedTableIds' => array_values(array_map(
                        'intval',
                        (array)($resultado['mesas_proyectadas'] ?? [])
                    )),
                    'idempotent' => (bool)($resultado['idempotente'] ?? false),
                    'warnings' => array_values((array)($resultado['warnings'] ?? [])),
                    'requiredConfirmations' => array_values((array)($resultado['confirmaciones_requeridas'] ?? [])),
                ]);
                return;
            }
            $codigo = self::resultadoCreacion((string)($resultado['codigo'] ?? ReservacionService::CREADA));
            $url = $id > 0 ? '/admin/reservations/show?id=' . $id : '/admin/reservations';

            header('Location: ' . self::urlConResultado($url, $codigo), true, 302);
            exit;
        }

        $status = ReservacionErrorCatalog::httpStatus(
            (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO),
            422
        );
        http_response_code($status);
        if ($expectsJson) {
            self::jsonResponse([
                'success' => false,
                'ok' => false,
                'reservationId' => null,
                'mensaje' => (string)($resultado['mensaje'] ?? ''),
                'fieldErrors' => is_array($resultado['errors'] ?? null) ? $resultado['errors'] : [],
                'codigo' => (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO),
                'requiresContactConfirmation' => (bool)($resultado['requiere_confirmacion_sin_contacto'] ?? false),
                'requiresCapacityConfirmation' => (bool)($resultado['requiere_confirmacion_capacidad'] ?? false),
                'warnings' => array_values((array)($resultado['warnings'] ?? [])),
                'requiredConfirmations' => array_values((array)($resultado['confirmaciones_requeridas'] ?? [])),
                'requestedCapacity' => (int)($resultado['capacidad_solicitada'] ?? 0),
                'availableCapacity' => (int)($resultado['capacidad_disponible'] ?? 0),
                'physicalCapacityTotal' => (int)($resultado['capacidad_fisica_total'] ?? 0),
                'physicalCapacityCommitted' => (int)($resultado['capacidad_fisica_comprometida'] ?? 0),
                'physicalCapacityFree' => (int)($resultado['capacidad_fisica_libre'] ?? 0),
                'unassignedDemand' => (int)($resultado['demanda_no_asignada'] ?? 0),
                'realAvailableCapacity' => (int)($resultado['capacidad_real_disponible'] ?? 0),
                'resultingCapacity' => (int)($resultado['capacidad_resultante'] ?? 0),
                'excessCapacity' => (int)($resultado['exceso_capacidad'] ?? 0),
                'nextValidTime' => $resultado['siguiente_horario_valido'] ?? null,
            ], $status);
            return;
        }
        self::renderCreate(
            self::reservacionDesdePost($_POST),
            $resultado['errors'] ?? [],
            self::alertasResultado(self::resultadoCreacion((string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO))),
            (string)($_POST['asignar_automaticamente'] ?? '0') === '1',
            ($resultado['requiere_confirmacion_capacidad'] ?? false) ? $resultado : []
        );
    }

    public static function show(Router $router): void
    {
        $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if (!$id) {
            self::redirectToIndex('no_existe');
        }

        $reservacion = Reservacion::findWithMesas((int)$id);

        if (!$reservacion) {
            self::redirectToIndex('no_existe');
        }

        $mesasAsignadas = ReservacionMesa::obtenerPorReservacion((int)$reservacion->id);
        $capacidadTotal = (int)($reservacion->capacidad_total ?? 0);
        $capacidadRestaurante = Mesa::capacidadReservableTotal();
        $ticketAbierto = null;
        foreach (TicketMesa::abiertosParaMapa() as $ticket) {
            if ((int)($ticket['reservacion_id'] ?? 0) === (int)$reservacion->id) {
                $ticketAbierto = $ticket;
                break;
            }
        }
        $ticketFisico = Ticket::buscarPorReservacion((int)$reservacion->id);
        $vigencia = ReservacionService::clasificarVigencia($reservacion, $ticketAbierto);

        self::render('reservations/show', [
            'title' => 'Detalle de reservación',
            'topbarSection' => 'Reservaciones',
            'reservacion' => $reservacion,
            'mesasAsignadas' => $mesasAsignadas,
            'capacidadTotal' => $capacidadTotal,
            'capacidadRestaurante' => $capacidadRestaurante,
            'estadoLabels' => ReservacionService::estadoLabels(),
            'alertas' => self::alertasResultado($_GET['resultado'] ?? ''),
            'errores' => [],
            'editable' => ReservacionService::puedeEditar($reservacion),
            'vigencia' => $vigencia,
            'ticketAbierto' => $ticketAbierto,
            'ticketFisico' => $ticketFisico,
            'adminCsrfToken' => AdminCsrfService::token(),
            'motivoNoEditable' => ReservacionService::codigoNoEditable($reservacion),
            'fechaActual' => ReservacionConfig::fechaActual(),
            'diasActivos' => range(0, 6),
            'maxComensalesAdmin' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'comentarioAdminDisponible' => true,
            'formTransport' => 'json',
            'returnUrl' => self::returnUrlActual(),
            'backUrl' => self::backUrlDesdeQuery(),
            'scripts' => [self::RESERVATION_FORM_JS],
        ]);
    }

    public static function update(Router $router): void
    {
        $expectsJson = self::expectsJsonRequest();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::csrfFailure($expectsJson, 405);
        }
        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::csrfFailure($expectsJson);
        }
        $resultado = ReservacionErrorCatalog::enriquecer(
            ReservacionService::actualizarDatos(
                self::reservacionIdDesdePost(),
                $_POST,
                (int)($_SESSION['id'] ?? 0) ?: null
            ),
            ['superficie' => 'administracion']
        );

        if ($resultado['ok'] ?? false) {
            if ($expectsJson) {
                $returnTo = (string)($_POST['return_to'] ?? '');
                if (!str_starts_with($returnTo, '/admin/reservations')) {
                    $returnTo = '/admin/reservations/show?id=' . self::reservacionIdDesdePost();
                }
                $resultadoUrl = self::urlConResultado(
                    $returnTo,
                    self::resultadoActualizacion(
                        (string)($resultado['codigo'] ?? ReservacionService::ACTUALIZADA)
                    )
                );
                self::jsonResponse([
                    'ok' => true,
                    'success' => true,
                    'codigo' => (string)$resultado['codigo'],
                    'requiere_asignacion' => (bool)($resultado['requiere_asignacion'] ?? false),
                    'depende_liberacion_proyectada' => (bool)($resultado['depende_liberacion_proyectada'] ?? false),
                    'advertencia' => $resultado['advertencia'] ?? null,
                    'redirect' => $resultadoUrl,
                ]);
                return;
            }
            self::redirectBack(self::resultadoActualizacion($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO));
        }

        $id = self::reservacionIdDesdePost();
        $reservacion = $id > 0 ? Reservacion::findWithMesas($id) : null;

        if (!$reservacion) {
            if ($expectsJson) {
                self::jsonResponse([
                    'ok' => false,
                    'success' => false,
                    'codigo' => ReservacionService::RESERVACION_NO_EXISTE,
                    'fieldErrors' => [],
                ], 404);
                return;
            }
            self::redirectBack('no_existe');
        }

        if ($expectsJson) {
            $codigo = (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO);
            $status = ReservacionErrorCatalog::httpStatus($codigo, 422);
            self::jsonResponse([
                'ok' => false,
                'success' => false,
                'codigo' => $codigo,
                'fieldErrors' => is_array($resultado['errors'] ?? null)
                    ? $resultado['errors']
                    : [],
                'fieldCodes' => is_array($resultado['field_codes'] ?? null)
                    ? $resultado['field_codes']
                    : [],
                'warnings' => array_values((array)($resultado['warnings'] ?? [])),
                'requiredConfirmations' => array_values((array)($resultado['confirmaciones_requeridas'] ?? [])),
            ], $status);
            return;
        }

        if (($resultado['codigo'] ?? '') === ReservacionService::DATOS_INVALIDOS
            || ($resultado['codigo'] ?? '') === ReservacionService::HORARIO_INVALIDO) {
            $reservacion = self::reservacionDesdePost($_POST, $reservacion);
        }

        $mesasAsignadas = ReservacionMesa::obtenerPorReservacion((int)$reservacion->id);
        $capacidadTotal = (int)($reservacion->capacidad_total ?? 0);
        $capacidadRestaurante = Mesa::capacidadReservableTotal();

        http_response_code(($resultado['codigo'] ?? '') === ReservacionService::ERROR_INTERNO ? 500 : 422);
        self::render('reservations/show', [
            'title' => 'Detalle de reservacion',
            'topbarSection' => 'Reservaciones',
            'reservacion' => $reservacion,
            'mesasAsignadas' => $mesasAsignadas,
            'capacidadTotal' => $capacidadTotal,
            'capacidadRestaurante' => $capacidadRestaurante,
            'estadoLabels' => ReservacionService::estadoLabels(),
            'alertas' => self::alertasResultado(self::resultadoActualizacion((string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO))),
            'errores' => $resultado['errors'] ?? [],
            'editable' => ReservacionService::puedeEditar($reservacion),
            'vigencia' => ReservacionService::clasificarVigencia($reservacion),
            'ticketAbierto' => null,
            'ticketFisico' => null,
            'adminCsrfToken' => AdminCsrfService::token(),
            'motivoNoEditable' => ReservacionService::codigoNoEditable($reservacion),
            'fechaActual' => ReservacionConfig::fechaActual(),
            'diasActivos' => range(0, 6),
            'maxComensalesAdmin' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'comentarioAdminDisponible' => true,
            'formTransport' => 'json',
            'returnUrl' => (string)($_POST['return_to'] ?? self::returnUrlActual()),
            'backUrl' => self::backUrlDesdePost(),
            'scripts' => [self::RESERVATION_FORM_JS],
        ]);
    }

    public static function status(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirectBack('metodo_invalido');
        }
        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirectBack('csrf_invalido');
        }
        $estado = (string)($_POST['estado'] ?? '');
        $resultado = ReservacionService::ejecutarAccionOperativa(
            self::reservacionIdDesdePost(),
            $estado,
            (int)($_SESSION['id'] ?? 0),
            trim((string)($_POST['motivo'] ?? ''))
        );
        if ($resultado['ok'] ?? false) {
            self::redirectBack(match ($estado) {
                'confirmada' => 'confirmada',
                'cancelada' => 'cancelada',
                'no_show' => 'no_show',
                default => 'actualizada',
            });
        }
        self::redirectBack(self::resultadoTransicion($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO));
    }

    public static function reasignarAutomaticamente(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirectBack('metodo_invalido');
        }
        if (!AdminCsrfService::validar($_POST['admin_csrf'] ?? null)) {
            self::redirectBack('csrf_invalido');
        }
        $resultado = AsignacionMesasService::asignarAutomaticamente(self::reservacionIdDesdePost());
        self::redirectBack(self::resultadoAsignacion($resultado['codigo'] ?? AsignacionMesasService::ERROR_INTERNO, true));
    }

    private static function renderCreate(
        $reservacion,
        array $errores = [],
        array $alertas = [],
        bool $asignarAutomaticamente = true,
        array $capacidadWarning = []
    ): void
    {
        if (empty($reservacion->request_token)) {
            $reservacion->request_token = ReservacionService::generarRequestToken();
        }

        self::render('reservations/create', [
            'title' => 'Nueva reservacion',
            'topbarSection' => 'Reservaciones',
            'reservacion' => $reservacion,
            'errores' => $errores,
            'alertas' => $alertas,
            'editable' => true,
            'fechaActual' => ReservacionConfig::fechaActual(),
            'diasActivos' => range(0, 6),
            'maxComensalesAdmin' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'comentarioAdminDisponible' => true,
            'asignarAutomaticamente' => $asignarAutomaticamente,
            'capacidadWarning' => $capacidadWarning,
            'formTransport' => 'json',
            'adminCsrfToken' => AdminCsrfService::token(),
            'returnUrl' => '/admin/reservations',
            'backUrl' => '/admin/reservations',
            'scripts' => [self::RESERVATION_FORM_JS],
        ]);
    }

    private static function reservacionDesdePost(array $post, $base = null)
    {
        $reservacion = $base ?: new Reservacion();
        $reservacion->id = (int)($post['id'] ?? ($reservacion->id ?? 0));
        $reservacion->nombre = (string)($post['nombre'] ?? '');
        $reservacion->contacto_tipo = (string)($post['contacto_tipo'] ?? 'ninguno');
        $reservacion->contacto = (string)($post['contacto'] ?? '');
        $reservacion->fecha = (string)($post['fecha'] ?? '');
        $reservacion->hora = HorarioReservacionService::normalizarHoraSql((string)($post['hora'] ?? ''));
        $reservacion->comensales = (int)($post['comensales'] ?? 0);
        $reservacion->nota = (string)($post['nota'] ?? ($reservacion->nota ?? ''));
        $reservacion->comentario_admin = (string)($post['comentario_admin'] ?? ($reservacion->comentario_admin ?? ''));
        $reservacion->request_token = (string)($post['request_token'] ?? ($reservacion->request_token ?? ''));
        $reservacion->confirmar_sin_contacto = (string)($post['confirmar_sin_contacto'] ?? '0');
        $reservacion->permitir_capacidad_insuficiente = (string)($post['permitir_capacidad_insuficiente'] ?? '0');
        $reservacion->estado = (string)($reservacion->estado ?? 'confirmada');

        return $reservacion;
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'reservations',
            'styles' => [self::RESERVATIONS_CSS],
            'scripts' => [],
        ], $data));
    }

    private static function expectsJsonRequest(): bool
    {
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
        $requestedFormat = strtolower((string)($_POST['response_format'] ?? ''));

        return str_contains($accept, 'application/json')
            || $requestedFormat === 'json';
    }

    private static function jsonResponse(array $payload, int $status = 200): void
    {
        if (array_key_exists('codigo', $payload) && $payload['codigo'] !== null) {
            $payload = ReservacionErrorCatalog::enriquecer($payload, ['superficie' => 'administracion']);
        }
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function csrfFailure(bool $json, int $status = 419): void
    {
        if ($json) {
            self::jsonResponse([
                'ok' => false,
                'success' => false,
                'codigo' => 'CSRF_INVALIDO',
                'fieldErrors' => [],
            ], $status);
            return;
        }

        self::redirectToIndex('csrf_invalido');
    }

    private static function leerFiltros(): array
    {
        $hoy = ReservacionConfig::fechaActual();
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 100);
        $fechaInicio = (string)($_GET['fecha_inicio'] ?? '');
        $fechaFin = (string)($_GET['fecha_fin'] ?? '');
        $estado = (string)($_GET['estado'] ?? '');
        $asignacion = (string)($_GET['asignacion'] ?? '');
        $origen = (string)($_GET['origen'] ?? '');

        if ($fechaInicio === '' && $fechaFin === '') {
            $fechaInicio = $hoy;
            $fechaFin = $hoy;
        } elseif ($fechaInicio !== '' && $fechaFin === '') {
            $fechaFin = $fechaInicio;
        } elseif ($fechaInicio === '' && $fechaFin !== '') {
            $fechaInicio = $fechaFin;
        }

        if (!self::fechaValida($fechaInicio)) {
            $fechaInicio = $hoy;
        }

        if (!self::fechaValida($fechaFin)) {
            $fechaFin = $fechaInicio;
        }

        if ($fechaInicio > $fechaFin) {
            [$fechaInicio, $fechaFin] = [$fechaFin, $fechaInicio];
        }

        if (!array_key_exists($estado, ReservacionService::estadoLabels())) {
            $estado = '';
        }

        if (!in_array($asignacion, ['', 'con_mesa', 'sin_mesa'], true)) {
            $asignacion = '';
        }

        if (!in_array($origen, ['', 'landing', 'admin'], true)) {
            $origen = '';
        }

        return [
            'q' => $q,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'estado' => $estado,
            'asignacion' => $asignacion,
            'origen' => $origen,
        ];
    }

    private static function hayFiltrosActivos(array $filtros): bool
    {
        $hoy = ReservacionConfig::fechaActual();

        return (string)($filtros['q'] ?? '') !== ''
            || (string)($filtros['estado'] ?? '') !== ''
            || (string)($filtros['asignacion'] ?? '') !== ''
            || (string)($filtros['origen'] ?? '') !== ''
            || (string)($filtros['fecha_inicio'] ?? '') !== $hoy
            || (string)($filtros['fecha_fin'] ?? '') !== $hoy;
    }

    private static function fechaValida(string $fecha): bool
    {
        return HorarioReservacionService::fechaValida($fecha);
    }

    private static function resultadoActualizacion(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::ACTUALIZADA => 'actualizada',
            ReservacionService::ACTUALIZADA_REQUIERE_ASIGNACION => 'actualizada_requiere_asignacion',
            ReservacionService::COMENTARIO_ACTUALIZADO => 'comentario_guardado',
            ReservacionService::DATOS_INVALIDOS => 'datos_invalidos',
            ReservacionService::HORARIO_INVALIDO => 'horario_invalido',
            ReservacionService::SIN_DISPONIBILIDAD => 'sin_disponibilidad',
            ReservacionService::COMENTARIO_NO_DISPONIBLE => 'comentario_migracion_pendiente',
            ReservacionService::RESERVACION_NO_EXISTE => 'no_existe',
            ReservacionService::RESERVACION_PASADA => 'reservacion_pasada',
            ReservacionService::RESERVACION_HORARIO_PASADO => 'reservacion_horario_pasado',
            ReservacionService::ESTADO_NO_EDITABLE => 'estado_no_editable',
            default => 'error_interno',
        };
    }

    private static function resultadoCreacion(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::CREADA => 'creada',
            ReservacionService::CREADA_SIN_MESAS => 'creada_sin_mesas',
            ReservacionService::REQUIERE_CONFIRMACION_SIN_CONTACTO => 'confirmar_sin_contacto',
            ReservacionService::REQUIERE_CONFIRMACION_CAPACIDAD => 'confirmar_capacidad',
            ReservacionService::DATOS_INVALIDOS => 'datos_invalidos',
            ReservacionService::HORARIO_INVALIDO => 'horario_invalido',
            default => 'error_interno',
        };
    }

    private static function reservacionIdDesdePost(): int
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirectBack('metodo_invalido');
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        return $id ? (int)$id : 0;
    }

    private static function resultadoTransicion(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::CONFIRMADA => 'confirmada',
            ReservacionService::COMPLETADA => 'completada',
            ReservacionService::CANCELADA => 'cancelada',
            ReservacionService::NO_SHOW => 'no_show',
            ReservacionService::CONFIRMAR_SIN_MESA => 'confirmar_sin_mesa',
            ReservacionService::RESERVACION_NO_EXISTE => 'no_existe',
            ReservacionService::ESTADO_INVALIDO => 'estado_invalido',
            \Services\PuntoVentaReservacionService::TOLERANCIA_VIGENTE => 'tolerancia_vigente',
            \Services\PuntoVentaReservacionService::TICKET_ABIERTO => 'ticket_abierto',
            \Services\PuntoVentaReservacionService::REQUIERE_REASIGNACION => 'requiere_reasignacion',
            \Services\PuntoVentaReservacionService::SIN_CAPACIDAD => 'capacidad_insuficiente',
            \Services\PuntoVentaReservacionService::DATOS_INVALIDOS => 'datos_invalidos',
            default => 'error_interno',
        };
    }

    private static function resultadoAsignacion(string $codigo, bool $automatica = false): string
    {
        return match ($codigo) {
            AsignacionMesasService::ASIGNACION_GUARDADA => $automatica ? 'reasignada' : 'asignacion_guardada',
            AsignacionMesasService::SIN_CAPACIDAD => 'reasignar_sin_capacidad',
            AsignacionMesasService::ASIGNACION_VACIA => 'asignacion_vacia',
            AsignacionMesasService::MESAS_INVALIDAS => 'mesas_invalidas',
            AsignacionMesasService::MESA_OCUPADA => 'mesa_ocupada',
            AsignacionMesasService::CAPACIDAD_INSUFICIENTE => 'capacidad_insuficiente',
            AsignacionMesasService::CONFLICTO_TICKETS_ABIERTOS => 'ticket_mesa_abierto',
            AsignacionMesasService::CONFLICTO_CONCURRENTE => 'conflicto_concurrente',
            AsignacionMesasService::VERSION_DESACTUALIZADA => 'version_desactualizada',
            AsignacionMesasService::DATOS_INCOMPLETOS => 'asignacion_datos_incompletos',
            AsignacionMesasService::SUPERPOSICION_NO_AUTORIZADA => 'superposicion_no_autorizada',
            AsignacionMesasService::ESTADO_INVALIDO,
            AsignacionMesasService::RESERVACION_NO_EDITABLE => 'estado_no_permite',
            AsignacionMesasService::RESERVACION_NO_EXISTE => 'no_existe',
            default => 'error_interno',
        };
    }

    private static function alertasResultado(string $resultado): array
    {
        $codigos = [
            'creada' => 'RESERVACION_CREADA',
            'creada_sin_mesas' => 'RESERVACION_CREADA_SIN_MESA',
            'confirmar_sin_contacto' => 'REQUIERE_CONFIRMACION_SIN_CONTACTO',
            'confirmar_capacidad' => 'REQUIERE_CONFIRMACION_CAPACIDAD',
            'actualizada' => 'ACTUALIZADA',
            'actualizada_requiere_asignacion' => 'ACTUALIZADA_REQUIERE_ASIGNACION',
            'confirmada' => 'CONFIRMADA',
            'llegada' => 'CONFIRMADA',
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
            'ticket_mesa_abierto' => 'CONFLICTO_TICKET_ABIERTO',
            'conflicto_concurrente' => 'CONFLICTO_CONCURRENTE',
            'version_desactualizada' => 'VERSION_DESACTUALIZADA',
            'asignacion_datos_incompletos' => 'DATOS_INCOMPLETOS',
            'superposicion_no_autorizada' => 'SUPERPOSICION_NO_AUTORIZADA',
            'estado_no_permite' => 'ESTADO_NO_EDITABLE',
            'estado_invalido' => 'ESTADO_INVALIDO',
            'tolerancia_vigente' => 'TOLERANCIA_VIGENTE',
            'ticket_abierto' => 'TICKET_ABIERTO',
            'requiere_reasignacion' => 'REQUIERE_REASIGNACION',
            'datos_invalidos' => 'DATOS_INVALIDOS',
            'horario_invalido' => 'HORARIO_INVALIDO',
            'sin_disponibilidad' => 'SIN_DISPONIBILIDAD',
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
        $campo = $definicion['tipo'] === ReservacionErrorCatalog::TIPO_INFORMACION
            ? 'exito'
            : (in_array($definicion['tipo'], [
                ReservacionErrorCatalog::TIPO_ADVERTENCIA,
                ReservacionErrorCatalog::TIPO_DECISION,
            ], true) ? 'warning' : 'error');
        return [$campo => [$presentacion['mensaje']]];
    }

    private static function redirectBack(string $resultado): void
    {
        $url = (string)($_POST['return_to'] ?? '/admin/reservations');

        if (!str_starts_with($url, '/admin/reservations')) {
            $url = '/admin/reservations';
        }

        $url = self::urlConResultado($url, $resultado);

        header('Location: ' . $url, true, 302);
        exit;
    }

    private static function redirectToIndex(string $resultado): void
    {
        header('Location: ' . self::urlConResultado('/admin/reservations', $resultado), true, 302);
        exit;
    }

    private static function returnUrlActual(): string
    {
        $id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        $url = '/admin/reservations/show';

        if ($id) {
            $url .= '?id=' . (int)$id;
        }

        $back = (string)($_GET['return_url'] ?? '');

        if ($back !== '' && str_starts_with($back, '/admin/reservations')) {
            $url .= (str_contains($url, '?') ? '&' : '?') . 'return_url=' . rawurlencode($back);
        }

        return $url;
    }

    private static function backUrlDesdeQuery(): string
    {
        $back = (string)($_GET['return_url'] ?? '');

        if ($back !== '' && str_starts_with($back, '/admin/reservations')) {
            return $back;
        }

        return '/admin/reservations';
    }

    private static function backUrlDesdePost(): string
    {
        $returnTo = (string)($_POST['return_to'] ?? '');
        $partes = parse_url($returnTo);
        $query = [];

        if (!empty($partes['query'])) {
            parse_str($partes['query'], $query);
        }

        $back = (string)($query['return_url'] ?? '');

        if ($back !== '' && str_starts_with($back, '/admin/reservations')) {
            return $back;
        }

        if ($returnTo !== '' && str_starts_with($returnTo, '/admin/reservations')) {
            return $returnTo;
        }

        return '/admin/reservations';
    }

    private static function urlConResultado(string $url, string $resultado): string
    {
        $partes = parse_url($url);
        $path = $partes['path'] ?? '/admin/reservations';
        $query = [];

        if (!empty($partes['query'])) {
            parse_str($partes['query'], $query);
        }

        $query['resultado'] = $resultado;
        return $path . '?' . http_build_query($query);
    }
}
