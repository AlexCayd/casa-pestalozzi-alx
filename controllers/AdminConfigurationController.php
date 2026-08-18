<?php

namespace Controllers;

use Model\ConfiguracionAnuncio;
use Model\ConfiguracionPos;
use MVC\Router;
use Services\AnuncioConfig;
use Services\AdminCsrfService;
use Services\HorarioOperacionImpactoService;
use Services\HorarioOperacionService;
use Services\ReservacionErrorCatalog;
use Services\ReporteSistemaService;

class AdminConfigurationController
{
    private const MODULE_CSS = '/build/css/admin/configuration.css?v=pos-settings-v1';
    private const MODULE_JS = '/build/js/admin/configuration.js?v=pos-settings-v1';
    private const HOURS_PATH = '/admin/configuracion/horarios';
    private const ANNOUNCEMENT_PATH = '/admin/configuracion/anuncio';
    private const POS_PATH = '/admin/configuracion/pos';

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
                    'titulo' => 'POS',
                    'descripcion' => 'Define cómo se comporta el punto de venta al abrir una mesa.',
                    'ruta' => self::POS_PATH,
                    'icono' => '<rect x="4" y="3" width="16" height="13" rx="2"/><path d="M8 20h8"/><path d="M12 16v4"/><path d="M8 8h8"/>',
                ],
                [
                    'titulo' => 'Reportes del sistema',
                    'descripcion' => 'Consulta y administra los problemas reportados por los usuarios.',
                    'ruta' => '/admin/configuracion/reportes',
                    'icono' => '<path d="M9 4h6l1 2h3v15H5V6h3l1-2Z"/><path d="M9 12h6"/><path d="M9 16h4"/>',
                ],
            ],
        ]);
    }

    public static function hours(Router $router): void
    {
        $impacto = null;
        try {
            $solicitado = filter_var($_GET['impacto_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $impactoId = $solicitado ? (int)$solicitado : HorarioOperacionImpactoService::primerPendienteId();
            if (!$impactoId || !HorarioOperacionImpactoService::esPendiente((int)$impactoId)) {
                $impacto = null;
            } else {
                // La vista sólo necesita el identificador para que el cliente
                // cargue el seguimiento una sola vez por API.
                $impacto = ['id' => (int)$impactoId];
            }
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::hours seguimiento - ' . $e->getMessage());
        }
        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => self::alertasResultado((string) ($_GET['resultado'] ?? '')),
            'impactoSeguimiento' => $impacto,
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

    public static function pos(Router $router): void
    {
        $alertas = self::alertasResultado((string) ($_GET['resultado'] ?? ''));

        try {
            $configuracion = ConfiguracionPos::obtenerOCrear()->valoresFormulario();
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::pos - ' . $e->getMessage());
            $configuracion = self::defaultPos();
            $alertas['error'][] = 'No fue posible cargar la configuración actual del POS.';
        }

        self::renderPos($configuracion, $alertas);
    }

    public static function guardarPos(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::POS_PATH);
        }

        // Checkbox: el navegador no envía el campo cuando está apagado, así que
        // la ausencia es el "no" y no hay estado intermedio que validar.
        $editable = isset($_POST['mesero_editable'])
            && is_scalar($_POST['mesero_editable'])
            && (string) $_POST['mesero_editable'] === '1';

        $configuracion = new ConfiguracionPos([
            'mesero_editable' => $editable ? 1 : 0,
            'updated_by' => self::usuarioAutenticadoId(),
        ]);

        try {
            if ($configuracion->guardarConfiguracion()) {
                self::redirect(self::POS_PATH . '?resultado=pos_actualizado');
            }
            $alertas = ['error' => ['No fue posible actualizar la configuración del POS.']];
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::guardarPos - ' . $e->getMessage());
            $alertas = ['error' => ['No fue posible actualizar la configuración del POS. Intenta de nuevo.']];
        }

        self::renderPos($configuracion->valoresFormulario(), $alertas);
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

    private static function renderPos(array $configuracion, array $alertas): void
    {
        self::render('configuration/pos', [
            'title' => 'POS',
            'topbarSection' => 'Configuración',
            'configuracionPos' => $configuracion,
            'alertas' => $alertas,
        ]);
    }

    private static function defaultPos(): array
    {
        // Ante un fallo de lectura se muestra el comportamiento histórico, que
        // es el que sigue aplicando el POS mientras no pueda leer el ajuste.
        return ['mesero_editable' => true, 'updated_at' => ''];
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
            'impactoSeguimiento' => null,
            'adminCsrfToken' => AdminCsrfService::token(),
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ], $data));
    }

    private static function urlResultadoHorario(string $resultado, array $respuesta): string
    {
        $query = ['resultado' => $resultado];
        if (!empty($respuesta['impacto_id'])) {
            $query['impacto_id'] = (int)$respuesta['impacto_id'];
        }

        return self::HOURS_PATH . '?' . http_build_query($query);
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
            'pos_actualizado' => 'POS_ACTUALIZADO',
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
