<?php

namespace Controllers;

use Model\ConfiguracionAnuncio;
use MVC\Router;
use Services\HorarioOperacionService;

class AdminConfigurationController
{
    private const MODULE_CSS = '/build/css/admin/configuration.css?v=announcement-black-v6';
    private const MODULE_JS = '/build/js/admin/configuration.js?v=announcement-types-v4';
    private const HOURS_PATH = '/admin/configuracion/horarios';
    private const ANNOUNCEMENT_PATH = '/admin/configuracion/anuncio';

    public static function index(Router $router): void
    {
        self::render('configuration/index', [
            'title' => 'Configuración',
            'topbarSection' => 'Configuración',
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
        $resultado = HorarioOperacionService::guardarHorarioSemanal($horarios, self::usuarioAutenticadoId());

        if ($resultado['ok']) {
            self::redirect(self::HOURS_PATH . '?resultado=horarios_actualizados');
        }

        self::renderHours([
            'horarios' => $resultado['horarios'] ?? HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? ['No fue posible actualizar los horarios.']],
            'horarioSemanalConErrores' => true,
        ]);
    }

    public static function guardarExcepcion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::HOURS_PATH);
        }

        $resultado = HorarioOperacionService::guardarExcepcion($_POST, self::usuarioAutenticadoId());
        if ($resultado['ok']) {
            self::redirect(self::HOURS_PATH . '?resultado=' . (!empty($resultado['editada']) ? 'excepcion_actualizada' : 'excepcion_creada'));
        }

        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? ['No fue posible guardar la excepción.']],
            'excepcionFormulario' => $resultado['datos'] ?? $_POST,
            'abrirModalExcepcion' => true,
        ]);
    }

    public static function cambiarEstadoExcepcion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::HOURS_PATH);
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $activo = (string) ($_POST['activo'] ?? '0') === '1';
        $resultado = HorarioOperacionService::cambiarEstadoExcepcion(
            $id ? (int) $id : 0,
            $activo,
            self::usuarioAutenticadoId()
        );

        if ($resultado['ok']) {
            self::redirect(self::HOURS_PATH . '?resultado=estado_actualizado');
        }

        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? ['No fue posible cambiar el estado de la excepción.']],
        ]);
    }

    public static function eliminarExcepcion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::HOURS_PATH);
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $resultado = HorarioOperacionService::eliminarExcepcion($id ? (int) $id : 0);
        if ($resultado['ok']) {
            self::redirect(self::HOURS_PATH . '?resultado=excepcion_eliminada');
        }

        self::renderHours([
            'horarios' => HorarioOperacionService::obtenerHorarioSemanal(),
            'excepciones' => HorarioOperacionService::listarExcepciones(),
            'alertas' => ['error' => $resultado['errors'] ?? ['No fue posible eliminar la excepción.']],
        ]);
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
            'alertas' => $alertas,
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ]);
    }

    public static function reports(Router $router): void
    {
        self::render('configuration/reports', [
            'title' => 'Reportes del sistema',
            'topbarSection' => 'Configuración',
            'reportes' => [],
        ]);
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
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ], $data));
    }

    private static function alertasResultado(string $resultado): array
    {
        return match ($resultado) {
            'horarios_actualizados' => ['exito' => ['Los horarios de operación se actualizaron correctamente.']],
            'excepcion_creada' => ['exito' => ['El cierre u horario especial se guardó correctamente.']],
            'excepcion_actualizada' => ['exito' => ['La excepción se actualizó correctamente.']],
            'excepcion_eliminada' => ['exito' => ['La excepción se eliminó correctamente. La fecha volverá a utilizar el horario semanal habitual.']],
            'estado_actualizado' => ['exito' => ['El estado de la excepción se actualizó correctamente.']],
            'anuncio_actualizado' => ['exito' => ['El anuncio principal se actualizó correctamente.']],
            default => [],
        };
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

    private static function defaultAnnouncement(): array
    {
        return [
            'activo' => false,
            'mensaje' => '',
            'tipo' => 'informativo',
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
