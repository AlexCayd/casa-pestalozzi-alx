<?php

namespace Controllers;

use Model\HorarioReservacion;
use Model\Mesa;
use Model\Reservacion;
use MVC\Router;

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
        $horarios = self::horariosParaFecha($fecha);
        $horaCorta = self::horaOperacion($horarios, $fecha);
        $horaSql = self::horaSql($horaCorta);
        $estado = self::estadoOperacion();
        $reservacionId = filter_var($_GET['reservacion_id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);
        $returnUrl = self::returnUrlSeguro($_GET['return_url'] ?? '');

        $reservaciones = Reservacion::buscarPorHorarioAdmin($fecha, $horaSql, $estado);
        $reservacionSeleccionada = self::seleccionarReservacionOperacion($reservaciones, (int)$reservacionId);
        $selectedId = $reservacionSeleccionada ? (int)$reservacionSeleccionada->id : 0;
        $mesasAsignadas = $selectedId > 0 ? Reservacion::obtenerMesasAsignadas($selectedId) : [];
        $capacidadAsignada = $selectedId > 0 ? Reservacion::capacidadAsignada($selectedId) : 0;
        $mesas = self::mesasMapaOperacion();
        $ocupacion = Reservacion::ocupacionMesasParaHorario($fecha, $horaSql);

        $currentUrl = self::operationUrl([
            'fecha' => $fecha,
            'hora' => $horaCorta,
            'estado' => $estado,
            'reservacion_id' => $selectedId,
            'return_url' => $returnUrl,
        ]);

        self::render('reservations/operation', [
            'activeModule' => 'reservations_operation',
            'title' => 'Operacion de reservaciones',
            'topbarSection' => 'Reservaciones',
            'styles' => [self::MAP_CSS, self::RESERVATIONS_CSS],
            'scripts' => [self::OPERATION_JS],
            'filtros' => [
                'fecha' => $fecha,
                'hora' => $horaCorta,
                'estado' => $estado,
            ],
            'horarios' => $horarios,
            'reservaciones' => $reservaciones,
            'reservacionSeleccionada' => $reservacionSeleccionada,
            'mesasAsignadas' => $mesasAsignadas,
            'capacidadAsignada' => $capacidadAsignada,
            'mesas' => $mesas,
            'ocupacion' => $ocupacion,
            'estadoLabels' => self::ESTADO_LABELS,
            'alertas' => self::alertasResultado($_GET['resultado'] ?? ''),
            'returnUrl' => $returnUrl,
            'currentUrl' => $currentUrl,
            'comentarioAdminDisponible' => Reservacion::tieneComentarioAdmin(),
        ]);
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

        $mesasAsignadas = Reservacion::obtenerMesasAsignadas((int)$reservacion->id);
        $capacidadTotal = Reservacion::capacidadAsignada((int)$reservacion->id);

        self::render('reservations/show', [
            'title' => 'Detalle de reservación',
            'topbarSection' => 'Reservaciones',
            'reservacion' => $reservacion,
            'mesasAsignadas' => $mesasAsignadas,
            'capacidadTotal' => $capacidadTotal,
            'estadoLabels' => self::ESTADO_LABELS,
            'alertas' => self::alertasResultado($_GET['resultado'] ?? ''),
            'returnUrl' => self::returnUrlActual(),
            'backUrl' => self::backUrlDesdeQuery(),
        ]);
    }

    public static function confirmar(): void
    {
        $reservacion = self::reservacionDesdePost();

        if (!$reservacion) {
            self::redirectBack('no_existe');
        }

        if ((string)$reservacion->estado !== 'pendiente') {
            self::redirectBack('estado_invalido');
        }

        if (!Reservacion::tieneMesasAsignadas((int)$reservacion->id)) {
            self::redirectBack('confirmar_sin_mesa');
        }

        Reservacion::cambiarEstado((int)$reservacion->id, 'confirmada');
        self::redirectBack('confirmada');
    }

    public static function cancelar(): void
    {
        $reservacion = self::reservacionDesdePost();

        if (!$reservacion) {
            self::redirectBack('no_existe');
        }

        if (!self::estadoActivo((string)$reservacion->estado)) {
            self::redirectBack('estado_invalido');
        }

        Reservacion::cambiarEstado((int)$reservacion->id, 'cancelada');
        Reservacion::limpiarMesasAsignadas((int)$reservacion->id);
        self::redirectBack('cancelada');
    }

    public static function completar(): void
    {
        $reservacion = self::reservacionDesdePost();

        if (!$reservacion) {
            self::redirectBack('no_existe');
        }

        if (!self::estadoActivo((string)$reservacion->estado)) {
            self::redirectBack('estado_invalido');
        }

        Reservacion::cambiarEstado((int)$reservacion->id, 'completada');
        self::redirectBack('completada');
    }

    public static function noShow(): void
    {
        $reservacion = self::reservacionDesdePost();

        if (!$reservacion) {
            self::redirectBack('no_existe');
        }

        if (!self::estadoActivo((string)$reservacion->estado)) {
            self::redirectBack('estado_invalido');
        }

        Reservacion::cambiarEstado((int)$reservacion->id, 'no_show');
        Reservacion::limpiarMesasAsignadas((int)$reservacion->id);
        self::redirectBack('no_show');
    }

    public static function reasignarAutomaticamente(): void
    {
        $reservacion = self::reservacionDesdePost();

        if (!$reservacion) {
            self::redirectBack('no_existe');
        }

        if (!self::estadoActivo((string)$reservacion->estado)) {
            self::redirectBack('estado_invalido');
        }

        $mesasDisponibles = Reservacion::obtenerMesasDisponibles(
            $reservacion->fecha,
            $reservacion->hora,
            (int)$reservacion->id
        );
        $mesasSeleccionadas = Reservacion::seleccionarMesasParaComensales(
            $mesasDisponibles,
            (int)$reservacion->comensales
        );

        if (empty($mesasSeleccionadas)) {
            self::redirectBack('reasignar_sin_capacidad');
        }

        $mesaIds = array_map(function($mesa) {
            return (int)$mesa->id;
        }, $mesasSeleccionadas);

        Reservacion::asignarMesas((int)$reservacion->id, $mesaIds);
        self::redirectBack('reasignada');
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

        if (!self::estadoActivo((string)$reservacion->estado)) {
            self::redirectOperacionDesdePost('estado_no_permite', $reservacion);
        }

        $mesaIds = $_POST['mesa_ids'] ?? [];
        $mesaIds = array_values(array_unique(array_filter(array_map('intval', (array)$mesaIds))));

        if (empty($mesaIds)) {
            self::redirectOperacionDesdePost('asignacion_vacia', $reservacion);
        }

        $mesas = self::mesasPorIdsReservablesActivas($mesaIds);

        if (count($mesas) !== count($mesaIds)) {
            self::redirectOperacionDesdePost('mesas_invalidas', $reservacion);
        }

        $ocupacion = Reservacion::ocupacionMesasParaHorario(
            $reservacion->fecha,
            $reservacion->hora,
            (int)$reservacion->id
        );

        foreach ($mesaIds as $mesaId) {
            if (!empty($ocupacion[(int)$mesaId])) {
                self::redirectOperacionDesdePost('mesa_ocupada', $reservacion);
            }
        }

        $capacidad = array_reduce($mesas, function($total, $mesa) {
            return $total + (int)$mesa->capacidad;
        }, 0);

        if ($capacidad < (int)$reservacion->comensales) {
            self::redirectOperacionDesdePost('capacidad_insuficiente', $reservacion);
        }

        Reservacion::asignarMesas((int)$reservacion->id, $mesaIds);
        self::redirectOperacionDesdePost('asignacion_guardada', $reservacion);
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

        if (!Reservacion::tieneComentarioAdmin()) {
            self::redirectOperacionDesdePost('comentario_migracion_pendiente', $reservacion);
        }

        $comentario = substr((string)($_POST['comentario_admin'] ?? ''), 0, 5000);
        Reservacion::actualizarComentarioAdmin((int)$reservacion->id, $comentario);

        self::redirectOperacionDesdePost('comentario_guardado', $reservacion);
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
        $fecha = (string)($_GET['fecha'] ?? '');

        if (!self::fechaValida($fecha)) {
            return date('Y-m-d');
        }

        return $fecha;
    }

    private static function horaOperacion(array $horarios, string $fecha): string
    {
        $hora = self::normalizarHoraCorta((string)($_GET['hora'] ?? ''));
        $horasDisponibles = array_map(function($horario) {
            return self::normalizarHoraCorta((string)$horario->hora);
        }, $horarios);

        if ($hora !== '' && in_array($hora, $horasDisponibles, true)) {
            return $hora;
        }

        return self::horaPorDefecto($horasDisponibles, $fecha);
    }

    private static function horaPorDefecto(array $horasDisponibles, string $fecha): string
    {
        $horasDisponibles = array_values(array_filter($horasDisponibles));

        if (empty($horasDisponibles)) {
            return '09:00';
        }

        if ($fecha === date('Y-m-d')) {
            $horaActual = date('H:i');

            foreach ($horasDisponibles as $hora) {
                if ($hora >= $horaActual) {
                    return $hora;
                }
            }
        }

        return $horasDisponibles[0];
    }

    private static function horariosParaFecha(string $fecha): array
    {
        $timestamp = strtotime($fecha);
        $diaSemana = $timestamp ? (int)date('w', $timestamp) : (int)date('w');

        return HorarioReservacion::consultarSQL(
            "SELECT h.id, h.dia_id, h.hora
             FROM horarios_reservacion h
             INNER JOIN dias_reservacion d ON d.id = h.dia_id
             WHERE d.dia_semana = {$diaSemana}
               AND d.activo = 1
             ORDER BY h.hora ASC"
        );
    }

    private static function estadoOperacion(): string
    {
        $estado = (string)($_GET['estado'] ?? '');

        return array_key_exists($estado, self::ESTADO_LABELS) ? $estado : '';
    }

    private static function horaSql(string $hora): string
    {
        $hora = self::normalizarHoraCorta($hora);

        return $hora !== '' ? $hora . ':00' : '09:00:00';
    }

    private static function normalizarHoraCorta(string $hora): string
    {
        $hora = trim($hora);

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::[0-5]\d)?$/', $hora, $matches) !== 1) {
            return '';
        }

        return $matches[1] . ':' . $matches[2];
    }

    private static function seleccionarReservacionOperacion(array $reservaciones, int $reservacionId)
    {
        foreach ($reservaciones as $reservacion) {
            if ((int)$reservacion->id === $reservacionId) {
                return $reservacion;
            }
        }

        return $reservaciones[0] ?? null;
    }

    private static function mesasMapaOperacion(): array
    {
        return Mesa::consultarSQL(
            "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             ORDER BY numero ASC"
        );
    }

    private static function mesasPorIdsReservablesActivas(array $mesaIds): array
    {
        $mesaIds = array_values(array_unique(array_filter(array_map('intval', $mesaIds))));

        if (empty($mesaIds)) {
            return [];
        }

        return Mesa::consultarSQL(
            "SELECT id, numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable
             FROM mesas
             WHERE id IN (" . implode(',', $mesaIds) . ")
               AND activo = 1
               AND reservable = 1
             ORDER BY FIELD(id, " . implode(',', $mesaIds) . ")"
        );
    }

    private static function estadoActivo(string $estado): bool
    {
        return in_array($estado, self::ESTADOS_ACTIVOS, true);
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
            $fecha = date('Y-m-d');
        }

        if ($hora === '') {
            $hora = '09:00';
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
        $hoy = date('Y-m-d');
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
        $hoy = date('Y-m-d');

        return (string)($filtros['q'] ?? '') !== ''
            || (string)($filtros['estado'] ?? '') !== ''
            || (string)($filtros['asignacion'] ?? '') !== ''
            || (string)($filtros['fecha_inicio'] ?? '') !== $hoy
            || (string)($filtros['fecha_fin'] ?? '') !== $hoy;
    }

    private static function fechaValida(string $fecha): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) === 1;
    }

    private static function reservacionDesdePost()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirectBack('metodo_invalido');
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if (!$id) {
            return null;
        }

        return Reservacion::find((int)$id);
    }

    private static function alertasResultado(string $resultado): array
    {
        return match ($resultado) {
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
            'comentario_migracion_pendiente' => ['warning' => ['Para editar comentarios internos aplica la migracion: ALTER TABLE reservaciones ADD COLUMN comentario_admin TEXT NULL AFTER nota;']],
            'confirmar_sin_mesa' => ['error' => ['Asigna una mesa antes de confirmar la reservación.']],
            'no_existe' => ['error' => ['La reservación no existe.']],
            'metodo_invalido' => ['error' => ['La acción solicitada no es válida.']],
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
