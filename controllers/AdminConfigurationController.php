<?php

namespace Controllers;

use Model\ConfiguracionAnuncio;
use MVC\Router;
use Services\AnuncioConfig;
use Services\HorarioOperacionService;
use Services\ReservacionErrorCatalog;
use Services\ReporteSistemaService;

class AdminConfigurationController
{
    private const MODULE_CSS = '/build/css/admin/configuration.css?v=schedule-persistence-v1';
    private const MODULE_JS = '/build/js/admin/configuration.js?v=schedule-persistence-v1';
    private const HOURS_PATH = '/admin/configuracion/horarios';
    private const ANNOUNCEMENT_PATH = '/admin/configuracion/anuncio';

    public static function index(Router $router): void
    {
        self::render('configuration/index', [
            'title' => 'Configuración',
            'topbarSection' => 'Configuración',
            // 'acento' es un token de la paleta, no un hex: cada opción se
            // reconoce por color antes de leer su título.
            'configuraciones' => [
                [
                    'titulo' => 'Horarios de operación',
                    'descripcion' => 'Administra los días abiertos, horarios habituales y cierres especiales.',
                    'ruta' => self::HOURS_PATH,
                    'icono' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
                    'acento' => '--admin-c-azul',
                ],
                [
                    'titulo' => 'Anuncio principal',
                    'descripcion' => 'Configura el aviso que se mostrará en la página principal.',
                    'ruta' => '/admin/configuracion/anuncio',
                    'icono' => '<path d="M4 11v2"/><path d="M6 9v6l10 4V5L6 9Z"/><path d="M9 16l1 4h3"/>',
                    'acento' => '--admin-c-ambar',
                ],
                [
                    'titulo' => 'Reportes del sistema',
                    'descripcion' => 'Consulta y administra los problemas reportados por los usuarios.',
                    'ruta' => '/admin/configuracion/reportes',
                    'icono' => '<path d="M9 4h6l1 2h3v15H5V6h3l1-2Z"/><path d="M9 12h6"/><path d="M9 16h4"/>',
                    'acento' => '--admin-c-magenta',
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
            self::redirect(self::HOURS_PATH . '?resultado=horarios_actualizados');
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
            self::redirect(self::HOURS_PATH . '?resultado=' . (!empty($resultado['editada']) ? 'excepcion_actualizada' : 'excepcion_creada'));
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
                self::usuarioAutenticadoId()
            ),
            ['superficie' => 'administracion']
        );

        if ($resultado['ok']) {
            self::redirect(self::HOURS_PATH . '?resultado=estado_actualizado');
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
            HorarioOperacionService::eliminarExcepcion($id ? (int) $id : 0),
            ['superficie' => 'administracion']
        );
        if ($resultado['ok']) {
            self::redirect(self::HOURS_PATH . '?resultado=excepcion_eliminada');
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
        self::json(HorarioOperacionService::eliminarExcepcion($id));
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

    public static function reports(Router $router): void
    {
        $resultado = (string) ($_GET['resultado'] ?? '');
        $alertas = match ($resultado) {
            'estado' => ['exito' => ['Estado del reporte actualizado.']],
            'estado_invalido' => ['error' => ['No se pudo actualizar el reporte.']],
            default => [],
        };

        self::render('configuration/reports', [
            'title' => 'Reportes del sistema',
            'topbarSection' => 'Configuración',
            'reportes' => ReporteSistemaService::listar(),
            'alertas' => $alertas,
        ]);
    }

    /** POST /admin/configuracion/reportes/estado — desde el modal de detalle. */
    public static function reportStatus(Router $router): void
    {
        $resultado = ReporteSistemaService::cambiarEstado(
            (int) ($_POST['id'] ?? 0),
            (string) ($_POST['estado'] ?? '')
        );

        $query = ($resultado['ok'] ?? false) ? 'estado' : 'estado_invalido';
        header('Location: /admin/configuracion/reportes?resultado=' . $query, true, 302);
        exit;
    }

    /** POST /admin/api/reportes — envío del modal "Reportar un problema". */
    public static function crearReporte(Router $router): void
    {
        header('Content-Type: application/json; charset=utf-8');

        $datos = json_decode((string) file_get_contents('php://input'), true);
        $datos = is_array($datos) ? $datos : $_POST;

        $resultado = ReporteSistemaService::crear($datos, (int) ($_SESSION['id'] ?? 0));
        if (!($resultado['ok'] ?? false)) {
            http_response_code(($resultado['codigo'] ?? '') === ReporteSistemaService::DATOS_INVALIDOS ? 422 : 500);
        }

        echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
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
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ], $data));
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
