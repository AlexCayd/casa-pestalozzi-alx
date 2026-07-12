<?php

/**
 * Controla las pantallas administrativas de reservaciones.
 * Procesa entradas HTTP y delega reglas a los servicios del modulo.
 */

namespace Controllers;

use Model\Mesa;
use Model\Reservacion;
use Model\ReservacionMesa;
use MVC\Router;
use Services\AsignacionMesasService;
use Services\HorarioReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionService;

class AdminReservacionController
{
    private const RESERVATIONS_CSS = '/build/css/admin/reservations.css';
    private const MAP_CSS = '/build/css/admin/map.css';
    private const OPERATION_JS = '/build/js/admin/reservation-operation.js';

    private const ESTADO_LABELS = [
        'pendiente' => 'Pendiente',
        'confirmada' => 'Confirmada',
        'completada' => 'Completada',
        'cancelada' => 'Cancelada',
        'no_show' => 'No show',
    ];

    private const ESTADOS_ACTIVOS = ['pendiente', 'confirmada'];

    public static function index(Router $router): void
    {
        $filtros = self::leerFiltros();
        $reservaciones = Reservacion::buscarAdmin($filtros);
        $metricas = Reservacion::metricasAdmin($filtros);

        self::render('reservations/index', [
            'title' => 'Reservaciones',
            'topbarSection' => 'Reservaciones',
            'reservaciones' => $reservaciones,
            'metricas' => $metricas,
            'filtros' => $filtros,
            'filtrosActivos' => self::hayFiltrosActivos($filtros),
            'estadoLabels' => self::ESTADO_LABELS,
            'alertas' => self::alertasResultado($_GET['resultado'] ?? ''),
            'queryString' => http_build_query($filtros),
        ]);
    }

    public static function operation(Router $router): void
    {
        $fecha = self::fechaOperacion();
        $reservacionId = filter_var($_GET['reservacion_id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        $returnUrl = self::returnUrlSeguro($_GET['return_url'] ?? '');

        self::render('reservations/operation', [
            'activeModule' => 'reservations_operation',
            'title' => 'Operacion de reservaciones',
            'topbarSection' => 'Reservaciones',
            'styles' => [self::MAP_CSS, self::RESERVATIONS_CSS],
            'scripts' => [self::OPERATION_JS],
            'filtros' => [
                'fecha' => $fecha,
            ],
            'estadoLabels' => self::ESTADO_LABELS,
            'alertas' => self::alertasResultado($_GET['resultado'] ?? ''),
            'returnUrl' => $returnUrl,
            'initialReservacionId' => $reservacionId ? (int)$reservacionId : 0,
            'comentarioAdminDisponible' => Reservacion::tieneComentarioAdmin(),
        ]);
    }

    public static function operationData(): void
    {
        $fecha = self::fechaOperacion();
        $horariosObjetos = self::horariosParaFecha($fecha);
        $horarios = array_values(array_filter(array_map(static function ($horario): string {
            return HorarioReservacionService::normalizarHoraCorta((string)($horario->hora ?? ''));
        }, $horariosObjetos)));
        $reservaciones = Reservacion::buscarPorDiaOperacionAdmin($fecha);
        $reservacionesSerializadas = self::serializarReservacionesOperacion($reservaciones);
        $ocupacionPorReservacion = AsignacionMesasService::obtenerOcupacionPorReservacionDelDia($fecha, $reservaciones);

        self::jsonResponse([
            'ok' => true,
            'fecha' => $fecha,
            'horarios' => $horarios,
            'hora_sugerida' => HorarioReservacionService::horaPorDefecto($horariosObjetos, $fecha),
            'reservaciones' => $reservacionesSerializadas,
            'mesas' => self::serializarMesasOperacion(self::mesasMapaOperacion()),
            'ocupacion_por_reservacion' => $ocupacionPorReservacion,
            'config' => [
                'estado_labels' => self::ESTADO_LABELS,
                'estados_editables' => self::ESTADOS_ACTIVOS,
                'comentario_admin_disponible' => Reservacion::tieneComentarioAdmin(),
            ],
        ]);
    }

    public static function create(Router $router): void
    {
        $reservacion = new Reservacion();
        $reservacion->fecha = HorarioReservacionService::fechaSeguraGet((string)($_GET['fecha'] ?? ReservacionConfig::fechaActual()));
        $reservacion->hora = '';
        $reservacion->comensales = 2;
        $reservacion->estado = 'pendiente';

        self::renderCreate($reservacion, [], self::alertasResultado($_GET['resultado'] ?? ''), true);
    }

    public static function store(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirectToIndex('metodo_invalido');
        }

        $resultado = ReservacionService::crearAdministrativa($_POST);

        if ($resultado['ok'] ?? false) {
            $id = (int)($resultado['id'] ?? 0);
            $codigo = self::resultadoCreacion((string)($resultado['codigo'] ?? ReservacionService::CREADA));
            $url = $id > 0 ? '/admin/reservations/show?id=' . $id : '/admin/reservations';

            header('Location: ' . self::urlConResultado($url, $codigo), true, 302);
            exit;
        }

        http_response_code(($resultado['codigo'] ?? '') === ReservacionService::ERROR_INTERNO ? 500 : 422);
        self::renderCreate(
            self::reservacionDesdePost($_POST),
            $resultado['errors'] ?? [],
            self::alertasResultado(self::resultadoCreacion((string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO))),
            (string)($_POST['asignar_automaticamente'] ?? '0') === '1'
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

        self::render('reservations/show', [
            'title' => 'Detalle de reservación',
            'topbarSection' => 'Reservaciones',
            'reservacion' => $reservacion,
            'mesasAsignadas' => $mesasAsignadas,
            'capacidadTotal' => $capacidadTotal,
            'capacidadRestaurante' => $capacidadRestaurante,
            'estadoLabels' => self::ESTADO_LABELS,
            'alertas' => self::alertasResultado($_GET['resultado'] ?? ''),
            'errores' => [],
            'editable' => ReservacionService::puedeEditar($reservacion),
            'motivoNoEditable' => ReservacionService::codigoNoEditable($reservacion),
            'fechaActual' => ReservacionConfig::fechaActual(),
            'diasActivos' => HorarioReservacionService::diasConHorariosActivos(),
            'maxComensalesAdmin' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'comentarioAdminDisponible' => Reservacion::tieneComentarioAdmin(),
            'returnUrl' => self::returnUrlActual(),
            'backUrl' => self::backUrlDesdeQuery(),
            'scripts' => [self::OPERATION_JS],
        ]);
    }

    public static function update(Router $router): void
    {
        $resultado = ReservacionService::actualizarDatos(self::reservacionIdDesdePost(), $_POST);

        if ($resultado['ok'] ?? false) {
            self::redirectBack(self::resultadoActualizacion($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO));
        }

        $id = self::reservacionIdDesdePost();
        $reservacion = $id > 0 ? Reservacion::findWithMesas($id) : null;

        if (!$reservacion) {
            self::redirectBack('no_existe');
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
            'estadoLabels' => self::ESTADO_LABELS,
            'alertas' => self::alertasResultado(self::resultadoActualizacion((string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO))),
            'errores' => $resultado['errors'] ?? [],
            'editable' => ReservacionService::puedeEditar($reservacion),
            'motivoNoEditable' => ReservacionService::codigoNoEditable($reservacion),
            'fechaActual' => ReservacionConfig::fechaActual(),
            'diasActivos' => HorarioReservacionService::diasConHorariosActivos(),
            'maxComensalesAdmin' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'comentarioAdminDisponible' => Reservacion::tieneComentarioAdmin(),
            'returnUrl' => (string)($_POST['return_to'] ?? self::returnUrlActual()),
            'backUrl' => self::backUrlDesdePost(),
            'scripts' => [self::OPERATION_JS],
        ]);
    }

    public static function status(): void
    {
        $estado = (string)($_POST['estado'] ?? '');
        $resultado = ReservacionService::cambiarEstado(self::reservacionIdDesdePost(), $estado);
        self::redirectBack(self::resultadoTransicion($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO));
    }

    public static function reasignarAutomaticamente(): void
    {
        $resultado = AsignacionMesasService::asignarAutomaticamente(self::reservacionIdDesdePost());
        self::redirectBack(self::resultadoAsignacion($resultado['codigo'] ?? AsignacionMesasService::ERROR_INTERNO, true));
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
            ReservacionService::cambiarEstado(self::reservacionIdOperacionPost(), $estado),
            self::mensajeExitoEstado($estado)
        );
    }

    private static function renderCreate($reservacion, array $errores = [], array $alertas = [], bool $asignarAutomaticamente = true): void
    {
        self::render('reservations/create', [
            'title' => 'Nueva reservacion',
            'topbarSection' => 'Reservaciones',
            'reservacion' => $reservacion,
            'errores' => $errores,
            'alertas' => $alertas,
            'editable' => true,
            'fechaActual' => ReservacionConfig::fechaActual(),
            'diasActivos' => HorarioReservacionService::diasConHorariosActivos(),
            'maxComensalesAdmin' => ReservacionConfig::MAX_COMENSALES_ADMIN,
            'comentarioAdminDisponible' => Reservacion::tieneComentarioAdmin(),
            'asignarAutomaticamente' => $asignarAutomaticamente,
            'returnUrl' => '/admin/reservations',
            'backUrl' => '/admin/reservations',
            'scripts' => [self::OPERATION_JS],
        ]);
    }

    private static function reservacionDesdePost(array $post, $base = null)
    {
        $reservacion = $base ?: new Reservacion();
        $reservacion->id = (int)($post['id'] ?? ($reservacion->id ?? 0));
        $reservacion->nombre = (string)($post['nombre'] ?? '');
        $reservacion->email = (string)($post['email'] ?? '');
        $reservacion->fecha = (string)($post['fecha'] ?? '');
        $reservacion->hora = HorarioReservacionService::normalizarHoraSql((string)($post['hora'] ?? ''));
        $reservacion->comensales = (int)($post['comensales'] ?? 0);
        $reservacion->nota = (string)($post['nota'] ?? ($reservacion->nota ?? ''));
        $reservacion->comentario_admin = (string)($post['comentario_admin'] ?? ($reservacion->comentario_admin ?? ''));
        $reservacion->estado = (string)($reservacion->estado ?? 'pendiente');

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

    private static function fechaOperacion(): string
    {
        return HorarioReservacionService::fechaSeguraGet((string)($_GET['fecha'] ?? ''));
    }

    private static function horariosParaFecha(string $fecha): array
    {
        return HorarioReservacionService::horariosParaFecha($fecha);
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

        if (self::fechaValida($fecha)) {
            $query['fecha'] = $fecha;
        }

        if ($hora !== '') {
            $query['hora'] = $hora;
        }

        if (array_key_exists($estado, self::ESTADO_LABELS)) {
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

    private static function leerFiltros(): array
    {
        $hoy = ReservacionConfig::fechaActual();
        $q = substr(trim((string)($_GET['q'] ?? '')), 0, 100);
        $fechaInicio = (string)($_GET['fecha_inicio'] ?? '');
        $fechaFin = (string)($_GET['fecha_fin'] ?? '');
        $estado = (string)($_GET['estado'] ?? '');
        $asignacion = (string)($_GET['asignacion'] ?? '');

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

        if (!array_key_exists($estado, self::ESTADO_LABELS)) {
            $estado = '';
        }

        if (!in_array($asignacion, ['', 'con_mesa', 'sin_mesa'], true)) {
            $asignacion = '';
        }

        return [
            'q' => $q,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'estado' => $estado,
            'asignacion' => $asignacion,
        ];
    }

    private static function hayFiltrosActivos(array $filtros): bool
    {
        $hoy = ReservacionConfig::fechaActual();

        return (string)($filtros['q'] ?? '') !== ''
            || (string)($filtros['estado'] ?? '') !== ''
            || (string)($filtros['asignacion'] ?? '') !== ''
            || (string)($filtros['fecha_inicio'] ?? '') !== $hoy
            || (string)($filtros['fecha_fin'] ?? '') !== $hoy;
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
                'email' => (string)($reservacion->email ?? ''),
                'fecha' => (string)($reservacion->fecha ?? ''),
                'hora' => substr((string)($reservacion->hora ?? ''), 0, 5),
                'comensales' => (int)($reservacion->comensales ?? 0),
                'estado' => (string)($reservacion->estado ?? 'pendiente'),
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
            AsignacionMesasService::MESA_OCUPADA => 409,
            AsignacionMesasService::RESERVACION_NO_EXISTE => 404,
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
        $httpStatus = $ok ? 200 : ($codigo === ReservacionService::RESERVACION_NO_EXISTE ? 404 : 422);

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

    private static function resultadoCreacion(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::CREADA => 'creada',
            ReservacionService::CREADA_SIN_MESAS => 'creada_sin_mesas',
            ReservacionService::DATOS_INVALIDOS => 'datos_invalidos',
            ReservacionService::HORARIO_INVALIDO => 'horario_invalido',
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
            AsignacionMesasService::RESERVACION_NO_EXISTE => 'La reservacion no existe.',
            default => 'No fue posible guardar los cambios. Intentalo nuevamente.',
        };
    }

    private static function mensajeTransicionApi(string $codigo): string
    {
        return match ($codigo) {
            ReservacionService::CONFIRMAR_SIN_MESA => 'Asigna una mesa antes de confirmar.',
            ReservacionService::ESTADO_INVALIDO => 'La accion no es valida para el estado actual.',
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
            AsignacionMesasService::ESTADO_INVALIDO => 'estado_no_permite',
            AsignacionMesasService::RESERVACION_NO_EXISTE => 'no_existe',
            default => 'error_interno',
        };
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
