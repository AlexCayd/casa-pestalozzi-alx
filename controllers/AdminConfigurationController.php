<?php

namespace Controllers;

use Model\ConfiguracionAnuncio;
use MVC\Router;
use Services\Configuration\AnuncioConfig;
use Services\Security\AdminCsrfService;
use Services\Scheduling\HorarioOperacionService;
use Services\Reservations\ReservacionErrorCatalog;
use Services\Notifications\ReservacionNotificacionConfigService;

class AdminConfigurationController
{
    private const MODULE_CSS = '/build/css/admin/configuration.css';
    private const MODULE_JS = '/build/js/admin/configuration.js';
    private const HOURS_PATH = '/admin/configuracion/horarios';
    private const ANNOUNCEMENT_PATH = '/admin/configuracion/anuncio';
    private const RESERVATIONS_PATH = '/admin/configuracion/reservaciones';

    public static function index(Router $router): void
    {
        self::render('configuration/index', [
            'title' => 'Configuración',
            'topbarSection' => 'Configuración',
            // Sin 'acento': la tarjeta ya no se tiñe por opción. La forma del
            // icono diferencia; el dorado se reserva para hover y foco.
            'configuraciones' => [
                [
                    'titulo' => 'Horarios de operación',
                    'descripcion' => 'Administra los días abiertos, horarios habituales y cierres especiales.',
                    'ruta' => self::HOURS_PATH,
                    'icono' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
                ],
                [
                    'titulo' => 'Anuncio principal',
                    'descripcion' => 'Configura el aviso que se mostrará en la página principal.',
                    'ruta' => '/admin/configuracion/anuncio',
                    'icono' => '<path d="M4 11v2"/><path d="M6 9v6l10 4V5L6 9Z"/><path d="M9 16l1 4h3"/>',
                ],
                [
                    'titulo' => 'Reservaciones',
                    'descripcion' => 'Configura recordatorios automáticos y comunicaciones con clientes.',
                    'ruta' => self::RESERVATIONS_PATH,
                    'icono' => '<path d="M6 3v3M18 3v3M4 8h16"/><rect x="4" y="5" width="16" height="16" rx="2"/><path d="m9 14 2 2 4-4"/>',
                ],
            ],
        ]);
    }

    public static function hours(Router $router): void
    {
        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => self::alertasResultado((string) ($_GET['resultado'] ?? '')),
        ]);
    }

    public static function guardarHorarios(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::HOURS_PATH);
        }

        $horarios = isset($_POST['horarios']) && is_array($_POST['horarios'])
            ? $_POST['horarios']
            : [];
        $resultado = ReservacionErrorCatalog::enriquecer(
            HorarioOperacionService::guardarHorarioSemanal(
                $horarios,
                self::usuarioAutenticadoId(),
                (string)($_POST['confirmar_conflictos'] ?? '0') === '1'
            ),
            ['superficie' => 'administracion']
        );

        if ($resultado['ok']) {
            self::redirect(self::urlResultadoHorario('horarios_actualizados', $resultado));
        }

        self::renderHours([
            'horarios' => $resultado['horarios'] ?? HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? [$resultado['mensaje'] ?? '']],
            'horarioSemanalConErrores' => true,
            'conflictosHorarios' => $resultado['conflictos'] ?? [],
        ]);
    }

    public static function guardarExcepcion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::HOURS_PATH);
        }

        $resultado = ReservacionErrorCatalog::enriquecer(
            HorarioOperacionService::guardarExcepcion(
                $_POST,
                self::usuarioAutenticadoId(),
                (string)($_POST['confirmar_conflictos'] ?? '0') === '1'
            ),
            ['superficie' => 'administracion']
        );
        if ($resultado['ok']) {
            self::redirect(self::urlResultadoHorario(
                !empty($resultado['editada']) ? 'excepcion_actualizada' : 'excepcion_creada',
                $resultado
            ));
        }

        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? [$resultado['mensaje'] ?? '']],
            'excepcionFormulario' => $resultado['datos'] ?? $_POST,
            'abrirModalExcepcion' => true,
            'conflictosExcepcion' => $resultado['conflictos'] ?? [],
        ]);
    }

    public static function cambiarEstadoExcepcion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::HOURS_PATH);
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $activo = (string) ($_POST['activo'] ?? '0') === '1';
        $resultado = ReservacionErrorCatalog::enriquecer(
            HorarioOperacionService::cambiarEstadoExcepcion(
                $id ? (int) $id : 0,
                $activo,
                self::usuarioAutenticadoId(),
                (string)($_POST['confirmar_conflictos'] ?? '0') === '1'
            ),
            ['superficie' => 'administracion']
        );

        if ($resultado['ok']) {
            self::redirect(self::urlResultadoHorario('estado_actualizado', $resultado));
        }

        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? [$resultado['mensaje'] ?? '']],
        ]);
    }

    public static function eliminarExcepcion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::HOURS_PATH);
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $resultado = ReservacionErrorCatalog::enriquecer(
            HorarioOperacionService::eliminarExcepcion(
                $id ? (int) $id : 0,
                (string)($_POST['confirmar_conflictos'] ?? '0') === '1',
                self::usuarioAutenticadoId()
            ),
            ['superficie' => 'administracion']
        );
        if ($resultado['ok']) {
            self::redirect(self::urlResultadoHorario('excepcion_eliminada', $resultado));
        }

        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? [$resultado['mensaje'] ?? '']],
        ]);
    }

    /** POST /api/configuracion/horarios/semanales */
    public static function apiGuardarHorarios(Router $router): void
    {
        try {
            $datos = self::entradaApi();
            $resultado = HorarioOperacionService::guardarHorarioSemanal(
                is_array($datos['horarios'] ?? null) ? $datos['horarios'] : [],
                self::usuarioAutenticadoId(),
                !empty($datos['confirmar_conflictos'])
            );
            self::json($resultado);
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::apiGuardarHorarios - ' . $e->getMessage());
            self::json([
                'ok' => false,
                'codigo' => 'ERROR_ACTUALIZACION_HORARIOS',
            ]);
        }
    }

    /** GET /api/configuracion/horarios/semanales */
    public static function apiObtenerHorarios(Router $router): void
    {
        try {
            self::json([
                'ok' => true,
                'codigo' => 'HORARIOS_OBTENIDOS',
                'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            ]);
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::apiObtenerHorarios - ' . $e->getMessage());
            self::json([
                'ok' => false,
                'codigo' => 'ERROR_CONSULTA_HORARIOS',
            ]);
        }
    }

    /** POST /api/configuracion/horarios/especiales */
    public static function apiGuardarEspecial(Router $router): void
    {
        $datos = self::entradaApi();
        $datos['tipo'] = 'horario_especial';
        self::json(HorarioOperacionService::guardarExcepcion(
            $datos,
            self::usuarioAutenticadoId(),
            !empty($datos['confirmar_conflictos'])
        ));
    }

    /** POST /api/configuracion/horarios/excepciones */
    public static function apiGuardarExcepcion(Router $router): void
    {
        $datos = self::entradaApi();
        self::json(HorarioOperacionService::guardarExcepcion(
            $datos,
            self::usuarioAutenticadoId(),
            !empty($datos['confirmar_conflictos'])
        ));
    }

    /** DELETE /api/configuracion/horarios/excepciones */
    public static function apiEliminarExcepcion(Router $router): void
    {
        $datos = self::entradaApi();
        $id = (int)($datos['id'] ?? $_GET['id'] ?? 0);
        self::json(HorarioOperacionService::eliminarExcepcion(
            $id,
            !empty($datos['confirmar_conflictos']),
            self::usuarioAutenticadoId()
        ));
    }

    /** POST /api/configuracion/horarios/excepciones/estado */
    public static function apiCambiarEstadoExcepcion(Router $router): void
    {
        $datos = self::entradaApi();
        self::json(HorarioOperacionService::cambiarEstadoExcepcion(
            (int)($datos['id'] ?? 0),
            !empty($datos['activo']),
            self::usuarioAutenticadoId(),
            !empty($datos['confirmar_conflictos'])
        ));
    }

    public static function announcement(Router $router): void
    {
        $alertas = self::alertasResultado((string) ($_GET['resultado'] ?? ''));

        try {
            $anuncio = ConfiguracionAnuncio::obtenerOCrear()->valoresFormulario();
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::announcement - ' . $e->getMessage());
            $anuncio = self::defaultAnnouncement();
            $alertas['error'][] = 'No fue posible cargar la configuración actual del anuncio.';
        }

        self::render('configuration/announcement', [
            'title' => 'Anuncio principal',
            'topbarSection' => 'Configuración',
            'anuncio' => $anuncio,
            'tiposAnuncio' => AnuncioConfig::TIPOS,
            'alertas' => $alertas,
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ]);
    }

    public static function guardarAnuncio(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::ANNOUNCEMENT_PATH);
        }

        $datos = self::datosAnuncioDesdePost($_POST);
        $anuncio = new ConfiguracionAnuncio(array_merge($datos, [
            'updated_by' => self::usuarioAutenticadoId(),
        ]));
        $alertas = $anuncio->validar();

        if (empty($alertas)) {
            try {
                if ($anuncio->guardarConfiguracion()) {
                    self::redirect(self::ANNOUNCEMENT_PATH . '?resultado=anuncio_actualizado');
                }
                $alertas['error'][] = 'No fue posible actualizar el anuncio.';
            } catch (\Throwable $e) {
                error_log('AdminConfigurationController::guardarAnuncio - ' . $e->getMessage());
                $alertas['error'][] = 'No fue posible actualizar el anuncio. Intenta de nuevo.';
            }
        }

        self::render('configuration/announcement', [
            'title' => 'Anuncio principal',
            'topbarSection' => 'Configuración',
            'anuncio' => $datos,
            'tiposAnuncio' => AnuncioConfig::TIPOS,
            'erroresCampos' => $anuncio->erroresCampos(),
            'alertas' => $alertas,
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ]);
    }

    public static function reservations(Router $router): void
    {
        $alertas = (string)($_GET['resultado'] ?? '') === 'reservaciones_actualizadas'
            ? ['exito' => ['La configuración de reservaciones se actualizó correctamente.']]
            : [];
        try {
            $configuracion = ReservacionNotificacionConfigService::obtenerOCrear();
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::reservations - configuración no disponible.');
            $configuracion = ReservacionNotificacionConfigService::obtener();
            $alertas['error'][] = 'No fue posible cargar la configuración de reservaciones.';
        }
        self::renderReservations($configuracion, $alertas);
    }

    public static function guardarReservaciones(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::RESERVATIONS_PATH);
        }
        $entrada = [
            'recordatorio_dia_anterior_activo' => isset($_POST['recordatorio_dia_anterior_activo'])
                && is_scalar($_POST['recordatorio_dia_anterior_activo'])
                && (string)$_POST['recordatorio_dia_anterior_activo'] === '1',
            'hora_recordatorio' => is_scalar($_POST['hora_recordatorio'] ?? null)
                ? trim((string)$_POST['hora_recordatorio'])
                : '',
        ];
        if (!AdminCsrfService::validar(isset($_POST['admin_csrf']) ? (string)$_POST['admin_csrf'] : null)) {
            self::renderReservations($entrada, ['error' => ['La sesión del formulario venció. Recarga la página e intenta de nuevo.']]);
            return;
        }
        try {
            $resultado = ReservacionNotificacionConfigService::guardar($entrada, self::usuarioAutenticadoId());
            if (($resultado['ok'] ?? false) === true) {
                self::redirect(self::RESERVATIONS_PATH . '?resultado=reservaciones_actualizadas');
            }
            self::renderReservations(
                (array)($resultado['configuracion'] ?? $entrada),
                ['error' => (array)($resultado['errors'] ?? ['La configuración no es válida.'])]
            );
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::guardarReservaciones - guardado no disponible.');
            self::renderReservations($entrada, ['error' => ['No fue posible actualizar la configuración. Intenta de nuevo.']]);
        }
    }

    private static function renderHours(array $data): void
    {
        self::render('configuration/hours', array_merge([
            'title' => 'Horarios de operación',
            'topbarSection' => 'Configuración',
            'alertas' => [],
            'excepcionFormulario' => [],
            'abrirModalExcepcion' => false,
            'horarioSemanalConErrores' => false,
            'conflictosHorarios' => [],
            'conflictosExcepcion' => [],
            'adminCsrfToken' => AdminCsrfService::token(),
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ], $data));
    }

    private static function renderReservations(array $configuracion, array $alertas): void
    {
        self::render('configuration/reservations', [
            'title' => 'Reservaciones',
            'topbarSection' => 'Configuración',
            'configuracionReservaciones' => $configuracion,
            'adminCsrfToken' => AdminCsrfService::token(),
            'alertas' => $alertas,
        ]);
    }

    private static function urlResultadoHorario(string $resultado, array $respuesta): string
    {
        return self::HOURS_PATH . '?' . http_build_query(['resultado' => $resultado]);
    }

    private static function alertasResultado(string $resultado): array
    {
        $codigos = [
            'horarios_actualizados' => 'HORARIOS_ACTUALIZADOS',
            'excepcion_creada' => 'EXCEPCION_CREADA',
            'excepcion_actualizada' => 'EXCEPCION_ACTUALIZADA',
            'excepcion_eliminada' => 'EXCEPCION_ELIMINADA',
            'estado_actualizado' => 'EXCEPCION_ESTADO_ACTUALIZADO',
            'anuncio_actualizado' => 'ANUNCIO_ACTUALIZADO',
        ];
        $codigo = $codigos[$resultado] ?? null;
        if ($codigo === null || !ReservacionErrorCatalog::has($codigo)) {
            return [];
        }
        return ['exito' => [ReservacionErrorCatalog::presentar($codigo)['mensaje']]];
    }

    private static function usuarioAutenticadoId(): ?int
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }

        $usuarioId = filter_var(
            $_SESSION['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $usuarioId ? (int) $usuarioId : null;
    }

    private static function entradaApi(): array
    {
        $datos = json_decode((string)file_get_contents('php://input'), true);
        return is_array($datos) ? $datos : $_POST;
    }

    private static function json(array $resultado): void
    {
        $codigo = (string)($resultado['codigo'] ?? '');
        if ($codigo !== '' && ReservacionErrorCatalog::has($codigo)) {
            $resultado = ReservacionErrorCatalog::enriquecer($resultado, ['superficie' => 'administracion']);
        }
        $status = ($resultado['ok'] ?? false)
            ? 200
            : ReservacionErrorCatalog::httpStatus($codigo, 422);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function defaultAnnouncement(): array
    {
        return [
            'activo' => false,
            'mensaje' => '',
            'tipo' => AnuncioConfig::TIPO_PREDETERMINADO,
            'fecha_inicio' => '',
            'fecha_fin' => '',
            'texto_enlace' => '',
            'url_enlace' => '',
        ];
    }

    private static function datosAnuncioDesdePost(array $post): array
    {
        return [
            'activo' => isset($post['activo']) && is_scalar($post['activo']) && (string) $post['activo'] === '1',
            'mensaje' => self::campoTexto($post['mensaje'] ?? ''),
            'tipo' => self::campoTexto($post['tipo'] ?? ''),
            'fecha_inicio' => self::campoTexto($post['fecha_inicio'] ?? ''),
            'fecha_fin' => self::campoTexto($post['fecha_fin'] ?? ''),
            'texto_enlace' => self::campoTexto($post['texto_enlace'] ?? ''),
            'url_enlace' => self::campoTexto($post['url_enlace'] ?? ''),
        ];
    }

    private static function campoTexto($valor): string
    {
        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private static function render(string $view, array $data): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'configuration',
            'styles' => [
                '/build/css/admin/menu.css',
                self::MODULE_CSS,
            ],
            'scripts' => [self::MODULE_JS],
        ], $data));
    }
}
