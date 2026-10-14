<?php

namespace Controllers;

use Model\ConfiguracionAnuncio;
use Model\ConfiguracionPos;
use MVC\Router;
use Services\Configuration\AnuncioConfig;
use Services\Security\AdminCsrfService;
use Services\Scheduling\HorarioOperacionService;
use Services\Reservations\ReservacionErrorCatalog;
use Services\Reservations\ReservacionAjustesService;
use Services\Reservations\ReservacionConfig;
use Model\ConfiguracionReservaciones;
use Services\Notifications\ReservacionNotificacionConfigService;
use Services\Configuration\ReporteSistemaService;

class AdminConfigurationController
{
    private const MODULE_CSS = '/build/css/admin/configuration.css';
    private const MODULE_JS = '/build/js/admin/configuration.js';
    private const HOURS_PATH = '/admin/configuracion/horarios';
    private const ANNOUNCEMENT_PATH = '/admin/configuracion/anuncio';
    private const POS_PATH = '/admin/configuracion/pos';
    private const POS_IMPRESION_PATH = '/admin/configuracion/pos/impresion';
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
                    'descripcion' => 'Tamaño máximo de grupo en línea, WhatsApp del restaurante y recordatorios automáticos.',
                    'ruta' => self::RESERVATIONS_PATH,
                    'icono' => '<path d="M6 3v3M18 3v3M4 8h16"/><rect x="4" y="5" width="16" height="16" rx="2"/><path d="m9 14 2 2 4-4"/>',
                ],
                [
                    'titulo' => 'POS',
                    'descripcion' => 'Asignación de mesero al abrir una mesa y el interruptor del servicio de impresión.',
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

    public static function pos(Router $router): void
    {
        $resultado = (string) ($_GET['resultado'] ?? '');
        $alertas = $resultado === 'barras_actualizadas'
            ? ['exito' => ['El tope de tickets por barra se actualizó.']]
            : self::alertasResultado($resultado);

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

        self::renderPos(self::valoresPosTrasFallo(['mesero_editable' => $editable]), $alertas);
    }

    /**
     * POST /admin/configuracion/pos/barras — tickets abiertos a la vez por
     * barra. Formulario y sentencia propios, como el de impresión: comparte
     * fila con los otros ajustes del POS y cada guardado nombra sólo su columna.
     */
    public static function guardarBarras(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::POS_PATH);
        }

        $tope = filter_var($_POST['max_tickets_barra'] ?? null, FILTER_VALIDATE_INT);
        $tope = $tope !== false ? (int) $tope : 0;
        $minimo = ConfiguracionPos::MAX_TICKETS_BARRA_MINIMO;
        $maximo = ConfiguracionPos::MAX_TICKETS_BARRA_TOPE;
        if ($tope < $minimo || $tope > $maximo) {
            self::renderPos(
                self::valoresPosTrasFallo(['max_tickets_barra' => $tope]),
                ['error' => ["El tope de tickets por barra debe estar entre {$minimo} y {$maximo}."]]
            );
            return;
        }

        $configuracion = new ConfiguracionPos([
            'max_tickets_barra' => $tope,
            'updated_by' => self::usuarioAutenticadoId(),
        ]);

        try {
            if ($configuracion->guardarMaxTicketsBarra()) {
                self::redirect(self::POS_PATH . '?resultado=barras_actualizadas');
            }
            $alertas = ['error' => ['No fue posible actualizar el tope de tickets por barra.']];
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::guardarBarras - ' . $e->getMessage());
            $alertas = ['error' => ['No fue posible actualizar el tope de tickets por barra. Revisa que la columna `max_tickets_barra` exista en `configuracion_pos`.']];
        }

        self::renderPos(self::valoresPosTrasFallo(['max_tickets_barra' => $tope]), $alertas);
    }

    /**
     * POST /admin/configuracion/pos/impresion — interruptor global del envío a
     * las impresoras térmicas.
     *
     * Acción aparte de guardarPos() y no un segundo campo del mismo formulario:
     * los dos ajustes comparten fila pero se guardan con sentencias distintas
     * —cada una nombra sólo su columna— y el diálogo que pide confirmar la
     * pausa no debe saltar en un envío que quizá sólo cambió lo del mesero.
     */
    public static function guardarImpresion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::POS_PATH);
        }

        // Mismo criterio que el de arriba: el navegador no manda la casilla
        // apagada, así que la ausencia es el "no".
        $activa = isset($_POST['impresion_activa'])
            && is_scalar($_POST['impresion_activa'])
            && (string) $_POST['impresion_activa'] === '1';

        $configuracion = new ConfiguracionPos([
            'impresion_activa' => $activa ? 1 : 0,
            'updated_by' => self::usuarioAutenticadoId(),
        ]);

        try {
            if ($configuracion->guardarImpresionActiva()) {
                self::redirect(self::POS_PATH . '?resultado=' . ($activa ? 'impresion_reanudada' : 'impresion_pausada'));
            }
            $alertas = ['error' => ['No fue posible cambiar el estado del servicio de impresión.']];
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::guardarImpresion - ' . $e->getMessage());
            $alertas = ['error' => ['No fue posible cambiar el estado del servicio de impresión. Revisa que la columna `impresion_activa` exista en `configuracion_pos`.']];
        }

        self::renderPos(self::valoresPosTrasFallo(['impresion_activa' => $activa]), $alertas);
    }

    public static function reservations(Router $router): void
    {
        $alertas = match ((string)($_GET['resultado'] ?? '')) {
            'reservaciones_actualizadas' => ['exito' => ['La configuración de reservaciones se actualizó correctamente.']],
            'limites_actualizados' => ['exito' => ['Los límites de la reserva en línea se actualizaron.']],
            default => [],
        };
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

    /**
     * POST /admin/configuracion/reservaciones/limites — máximo de comensales de
     * la landing y WhatsApp del restaurante. Formulario y sentencia propios: el
     * recordatorio vive en la misma fila y no debe pisarse (ver
     * ConfiguracionReservaciones::guardarLimites()).
     */
    public static function guardarLimitesReservaciones(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::RESERVATIONS_PATH);
        }
        $maximo = filter_var($_POST['max_comensales_publico'] ?? null, FILTER_VALIDATE_INT);
        $whatsappCrudo = is_scalar($_POST['whatsapp_reservas'] ?? null) ? (string)$_POST['whatsapp_reservas'] : '';
        $intento = [
            'max_comensales_publico' => $maximo !== false ? (int)$maximo : 0,
            'whatsapp_reservas' => ReservacionAjustesService::normalizarWhatsapp($whatsappCrudo),
        ];
        $recordatorio = ReservacionNotificacionConfigService::obtener();

        if (!AdminCsrfService::validar(isset($_POST['admin_csrf']) ? (string)$_POST['admin_csrf'] : null)) {
            self::renderReservations($recordatorio, ['error' => ['La sesión del formulario venció. Recarga la página e intenta de nuevo.']], $intento);
            return;
        }

        $modelo = new ConfiguracionReservaciones($intento + ['updated_by' => self::usuarioAutenticadoId()]);
        $errores = $modelo->validarLimites(
            ReservacionConfig::MAX_COMENSALES_PUBLICO_MINIMO,
            ReservacionConfig::MAX_COMENSALES_PUBLICO_TOPE
        );
        if ($errores !== []) {
            self::renderReservations($recordatorio, ['error' => array_values($errores['error'] ?? [])], $intento);
            return;
        }

        try {
            if (!$modelo->guardarLimites()) {
                throw new \RuntimeException('El guardado de límites no se completó.');
            }
            ReservacionAjustesService::limpiarCache();
            self::redirect(self::RESERVATIONS_PATH . '?resultado=limites_actualizados');
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::guardarLimitesReservaciones - ' . $e->getMessage());
            self::renderReservations(
                $recordatorio,
                ['error' => ['No fue posible guardar los límites. Revisa que existan las columnas `max_comensales_publico` y `whatsapp_reservas` en `configuracion_reservaciones`.']],
                $intento
            );
        }
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
        return [
            'mesero_editable' => true,
            'impresion_activa' => true,
            'max_tickets_barra' => ConfiguracionPos::MAX_TICKETS_BARRA_PREDETERMINADO,
            'updated_at' => '',
        ];
    }

    /**
     * Valores con los que repintar la vista cuando un guardado falla.
     *
     * No sirve el `valoresFormulario()` del objeto que se intentó guardar: ese
     * sólo trae el campo de SU formulario y el otro saldría con el valor por
     * omisión de la clase, no con el que hay en la base. Desde que la vista
     * pinta los dos ajustes, eso enseñaría el servicio de impresión encendido
     * después de que fallara un guardado del mesero. Se releen los dos y se
     * superpone lo que el usuario acababa de marcar.
     */
    private static function valoresPosTrasFallo(array $intento): array
    {
        try {
            $actuales = ConfiguracionPos::obtenerOCrear()->valoresFormulario();
        } catch (\Throwable $e) {
            error_log('AdminConfigurationController::valoresPosTrasFallo - ' . $e->getMessage());
            $actuales = self::defaultPos();
        }

        return array_merge($actuales, $intento);
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
            'adminCsrfToken' => AdminCsrfService::token(),
            'fechaActual' => HorarioOperacionService::fechaActual(),
        ], $data));
    }

    /**
     * `$intentoLimites` repinta lo que el admin acababa de escribir cuando el
     * guardado de límites falla; sin él, el panel vuelve a los valores vigentes.
     */
    private static function renderReservations(array $configuracion, array $alertas, array $intentoLimites = []): void
    {
        $limites = [
            'max_comensales_publico' => ReservacionConfig::maxComensalesPublico(),
            'whatsapp_reservas' => (string)(ReservacionAjustesService::whatsappReservas() ?? ''),
        ];
        if ($intentoLimites !== []) {
            $limites['max_comensales_publico'] = (int)($intentoLimites['max_comensales_publico'] ?? $limites['max_comensales_publico']);
            $limites['whatsapp_reservas'] = (string)($intentoLimites['whatsapp_reservas'] ?? '');
        }

        self::render('configuration/reservations', [
            'title' => 'Reservaciones',
            'topbarSection' => 'Configuración',
            'configuracionReservaciones' => $configuracion,
            'limitesReservaciones' => $limites + [
                'minimo' => ReservacionConfig::MAX_COMENSALES_PUBLICO_MINIMO,
                'tope' => ReservacionConfig::MAX_COMENSALES_PUBLICO_TOPE,
                'whatsapp_respaldo' => ReservacionConfig::whatsappRespaldo(),
                'reservaciones_por_tamano' => ReservacionAjustesService::reservacionesFuturasPorTamano(),
            ],
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
            'pos_actualizado' => 'POS_ACTUALIZADO',
            'impresion_reanudada' => 'IMPRESION_REANUDADA',
            'impresion_pausada' => 'IMPRESION_PAUSADA',
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
