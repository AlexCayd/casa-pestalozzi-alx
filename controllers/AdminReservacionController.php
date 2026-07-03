<?php

namespace Controllers;

use Model\Reservacion;
use MVC\Router;

class AdminReservacionController
{
    private const RESERVATIONS_CSS = '/build/css/admin/reservations.css';

    private const ESTADO_LABELS = [
        'pendiente' => 'Pendiente',
        'confirmada' => 'Confirmada',
        'completada' => 'Completada',
        'cancelada' => 'Cancelada',
        'no_show' => 'No show',
    ];

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

        Reservacion::cambiarEstado((int)$reservacion->id, 'completada');
        self::redirectBack('completada');
    }

    public static function noShow(): void
    {
        $reservacion = self::reservacionDesdePost();

        if (!$reservacion) {
            self::redirectBack('no_existe');
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

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'reservations',
            'styles' => [self::RESERVATIONS_CSS],
            'scripts' => [],
        ], $data));
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
            'reasignar_sin_capacidad' => ['error' => ['No hay mesas suficientes disponibles para reasignar automáticamente.']],
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
