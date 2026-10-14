<?php

/**
 * Panel estratégico de reservaciones: lo que viene, sin mapa.
 *
 * Dos pantallas hermanas del histórico (/admin/reservaciones):
 *
 *   - Grupos grandes: el bloque exterior día por día, para decidir dónde cabe
 *     un grupo antes de prometérselo a nadie.
 *   - Casos: las reservaciones que piden que alguien haga algo, con la acción
 *     a un toque.
 *
 * La operación del día —sentar, mover, cobrar— vive en el POS; aquí sólo se
 * planea y se resuelve lo que se puede resolver sin el plano delante. Los
 * servicios hacen el trabajo; este controlador lee el periodo, pinta y, para
 * «Marcar revisada», delega en el mismo modelo que usa el POS.
 *
 * Todo vive bajo /admin/, así que Auth::proteger() lo cierra a administradores
 * por prefijo, endpoint JSON incluido.
 */

namespace Controllers;

use Model\ReservacionReubicacion;
use MVC\Router;
use Services\Notifications\BuzonNotificacionesService;
use Services\Reservations\ReservacionAgendaGruposService;
use Services\Reservations\ReservacionBuzonService;
use Services\Reservations\ReservacionCasosAccionablesService;
use Services\Reservations\ReservacionConfig;
use Services\Security\AdminCsrfService;
use Services\Shared\RangoPeriodo;

class AdminReservacionEstrategiaController
{
    private const ESTRATEGIA_CSS = '/build/css/admin/reservations-estrategia.css';
    private const ESTRATEGIA_JS = '/build/js/admin/reservation-estrategia.js';

    /** Tarjetas por página en Casos. */
    private const CASOS_POR_PAGINA = 20;

    /**
     * Cómo se presenta cada tipo de caso. Va en el orden de urgencia del
     * servicio para que las pestañas del filtro se lean igual que la lista.
     *
     * `badge` es la familia de .admin-badge: rojo sólo para lo que no admite
     * espera (una mesa apartada para alguien que no vino), ámbar para lo que
     * hay que resolver antes del servicio, azul para lo que es sólo revisar y
     * neutro para lo que es una condición, no una tarea.
     *
     * TODO(fase 6): el plan pide un ReservacionCasoPresenter compartido con el
     * buzón (extraído de AdminBuzonController::fuente()). Ese controlador tiene
     * cambios ajenos en curso; cuando se asienten, este mapa se muda allí.
     */
    public const TIPOS = [
        ReservacionCasosAccionablesService::TIPO_AUSENCIA => ['etiqueta' => 'No llegó', 'badge' => 'danger'],
        ReservacionCasosAccionablesService::TIPO_SIN_MESA => ['etiqueta' => 'Sin mesas', 'badge' => 'warning'],
        ReservacionCasosAccionablesService::TIPO_HORARIO => ['etiqueta' => 'Cambio de horario', 'badge' => 'warning'],
        ReservacionCasosAccionablesService::TIPO_REUBICADA => ['etiqueta' => 'Reubicadas', 'badge' => 'info'],
        ReservacionCasosAccionablesService::TIPO_SIN_CONTACTO => ['etiqueta' => 'Sin contacto', 'badge' => 'neutral'],
    ];

    /** GET /admin/reservaciones/grupos-grandes */
    public static function gruposGrandes(Router $router): void
    {
        // El servicio no pinta más de MAX_DIAS; el rango se recorta aquí para
        // que el selector diga lo que de verdad se ve.
        $rango = RangoPeriodo::resolverProspectivo(
            $_GET,
            ReservacionConfig::fechaActual(),
            30,
            ReservacionAgendaGruposService::MAX_DIAS
        );
        $agenda = ReservacionAgendaGruposService::rango($rango['start'], $rango['end']);

        self::render('reservations/grupos-grandes', [
            'title' => 'Grupos grandes',
            'topbarSection' => 'Reservaciones',
            'rango' => $rango,
            'agenda' => $agenda,
            'umbralGrupo' => ReservacionConfig::MIN_COMENSALES_BLOQUE_EXTERIOR,
            'returnUrl' => self::urlActual('/admin/reservaciones/grupos-grandes'),
        ]);
    }

    /** GET /admin/reservaciones/casos */
    public static function casos(Router $router): void
    {
        // Una ausencia vencida ya no es un caso: se registra sola antes de listar.
        \Services\Reservations\ReservacionAusenciaAutomaticaService::procesarEnLectura();
        $rango = RangoPeriodo::resolverProspectivo($_GET, ReservacionConfig::fechaActual(), 30, 366);
        $tipo = (string)($_GET['tipo'] ?? '');
        if (!array_key_exists($tipo, self::TIPOS)) {
            $tipo = '';
        }
        $pagina = max(1, (int)($_GET['pagina'] ?? 1));

        $resultado = ReservacionCasosAccionablesService::listar(
            $rango['start'],
            $rango['end'],
            $tipo !== '' ? $tipo : null,
            $pagina,
            self::CASOS_POR_PAGINA
        );

        // El periodo sólo viaja en la URL si el usuario lo eligió: así un
        // enlace a «Casos» sin query sigue abriendo sobre «hoy en adelante»
        // aunque se pegue días después.
        $base = [];
        if (!$rango['porDefecto']) {
            $base['desde'] = $rango['start'];
            $base['hasta'] = $rango['end'];
        }
        $urlCasos = static function (array $cambios) use ($base, $tipo): string {
            $query = array_filter(
                array_merge($base, ['tipo' => $tipo], $cambios),
                static fn($valor): bool => $valor !== '' && $valor !== null && $valor !== 1
            );

            return '/admin/reservaciones/casos' . ($query ? '?' . http_build_query($query) : '');
        };

        $data = [
            'title' => 'Casos de reservaciones',
            'topbarSection' => 'Reservaciones',
            'rango' => $rango,
            'resultado' => $resultado,
            'tipoActivo' => $tipo,
            'tipos' => self::TIPOS,
            'urlCasos' => $urlCasos,
            'returnUrl' => self::urlActual('/admin/reservaciones/casos'),
            'adminCsrfToken' => AdminCsrfService::token(),
            'scripts' => [self::ESTRATEGIA_JS],
        ];

        // La lista se repinta sola tras una acción (estrategia.js pide la
        // misma URL con X-Requested-With): así el aviso de «hecho» sobrevive,
        // que con una recarga completa se perdería.
        if (AdminController::isPartialRequest()) {
            AdminController::renderPartial('reservations/casos', array_merge($data, ['partialOnly' => true]));
            return;
        }

        self::render('reservations/casos', $data);
    }

    /**
     * POST /admin/api/reservaciones/reubicaciones/revisar
     *
     * «Marcar revisada» desde Casos. Es la misma operación que el POS
     * (PosReservacionController::revisarReubicacion) con la frontera del
     * panel: CSRF de administración en vez del de personal. El rastro queda en
     * la bitácora con quién la revisó y marcarla otra vez no hace nada.
     */
    public static function revisarReubicacion(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_INVALIDO', 'mensaje' => 'Método no permitido.'], 405);
            return;
        }
        $datos = self::entrada();
        $token = $datos['admin_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (!AdminCsrfService::validar(is_string($token) ? $token : null)) {
            self::json([
                'ok' => false,
                'codigo' => 'CSRF_INVALIDO',
                'mensaje' => 'La sesión del formulario venció. Recarga la página e inténtalo de nuevo.',
            ], 419);
            return;
        }
        $reubicacionId = filter_var($datos['reubicacion_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$reubicacionId) {
            self::json(['ok' => false, 'codigo' => 'DATOS_INVALIDOS', 'mensaje' => 'Falta la reubicación que se quiere marcar.'], 422);
            return;
        }

        try {
            $usuarioId = (int)($_SESSION['id'] ?? 0) ?: null;
            $marcada = ReservacionReubicacion::marcarRevisada((int)$reubicacionId, $usuarioId);
            // Revisada aquí, el aviso del buzón ya no tiene nada que decir: el
            // mismo cierre que hace el POS, para que los dos lados coincidan.
            BuzonNotificacionesService::cerrarEntidad(
                ReservacionBuzonService::ENTIDAD_REUBICACION,
                (int)$reubicacionId,
                $usuarioId,
                'revisada'
            );
        } catch (\Throwable $error) {
            error_log('AdminReservacionEstrategiaController::revisarReubicacion - ' . $error->getMessage());
            self::json(['ok' => false, 'codigo' => 'ERROR_INTERNO', 'mensaje' => 'No fue posible marcar la reubicación como revisada.'], 500);
            return;
        }

        self::json(['ok' => true, 'codigo' => 'OK', 'idempotente' => !$marcada]);
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'reservations',
            'styles' => [self::ESTRATEGIA_CSS],
            'scripts' => [],
        ], $data));
    }

    /**
     * URL de esta pantalla con su query, para el `return_url` del detalle: al
     * volver de una reservación se regresa al mismo periodo y filtro, no al
     * histórico. El detalle sólo acepta destinos bajo /admin/reservaciones.
     */
    private static function urlActual(string $ruta): string
    {
        $query = array_intersect_key($_GET, array_flip(['desde', 'hasta', 'tipo', 'pagina']));
        $query = array_filter(array_map(static fn($v): string => is_scalar($v) ? (string)$v : '', $query), static fn(string $v): bool => $v !== '');

        return $ruta . ($query ? '?' . http_build_query($query) : '');
    }

    /** @return array<string, mixed> */
    private static function entrada(): array
    {
        $datos = json_decode((string)file_get_contents('php://input'), true);

        return is_array($datos) ? $datos : $_POST;
    }

    private static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
