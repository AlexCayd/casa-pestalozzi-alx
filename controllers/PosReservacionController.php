<?php

namespace Controllers;

use Classes\Auth;
use Services\Security\Permisos;
use Model\ReservacionReubicacion;
use MVC\Router;
use Services\Pos\PosAltaReservacionService;
use Services\Pos\PosReservacionSerializer;
use Services\Reservations\AsignacionMesasService;
use Services\Reservations\DisponibilidadReservacionService;
use Services\Reservations\ReservacionAltaPresenter;
use Services\Reservations\ReservacionErrorCatalog;
use Services\Reservations\ReservacionMapaAdministrativaService;
use Services\Reservations\ReservacionService;
use Services\Security\StaffCsrfService;

/**
 * Reservaciones operadas desde el POS.
 *
 * Lo que antes sólo se podía hacer en la pantalla de Operación —crear, elegir
 * mesas, reasignar, comentar— vive ahora junto al mapa del mesero. No hay
 * lógica de negocio aquí: cada acción delega en el MISMO servicio que usa el
 * panel, con la frontera del piso encima (CSRF de personal, contacto fuera de
 * la respuesta de un mesero y el comentario interno sólo para administración).
 *
 * Todas las rutas van por ruta exacta en Auth::APIS_POS.
 */
final class PosReservacionController
{
    /** GET /api/punto-de-venta/reservaciones/disponibilidad?fecha&personas[&hora][&reservacion_id] */
    public static function disponibilidad(Router $router): void
    {
        \Classes\Auth::liberarSesion();
        $reservacionId = filter_var(
            $_GET['reservacion_id'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $respuesta = DisponibilidadReservacionService::consultarAdministrativa(
            (string)($_GET['fecha'] ?? ''),
            $_GET['personas'] ?? $_GET['comensales'] ?? null,
            $reservacionId ? (int)$reservacionId : 0,
            isset($_GET['hora']) ? (string)$_GET['hora'] : null
        );

        self::json(
            $respuesta,
            ($respuesta['ok'] ?? false)
                ? 200
                : ReservacionErrorCatalog::httpStatus((string)($respuesta['codigo'] ?? 'ERROR_INTERNO'), 422)
        );
    }

    /**
     * POST /api/punto-de-venta/reservaciones/crear
     *
     * El alta del panel con la frontera del piso: un mesero no captura
     * contacto (el servidor lo descarta aunque llegue) y puede elegir mesas
     * (`mesa_ids[]`), que es la vía para un grupo mayor al máximo.
     */
    public static function crear(Router $router): void
    {
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            return;
        }
        $datos = PosAltaReservacionService::normalizarEntrada($datos, !Auth::puede('reservations', Permisos::EDITAR));
        $resultado = ReservacionErrorCatalog::enriquecer(
            ReservacionService::crearAdministrativa($datos, (int)($_SESSION['id'] ?? 0) ?: null),
            ['superficie' => 'pos']
        );
        $status = ($resultado['ok'] ?? false)
            ? 200
            : ReservacionErrorCatalog::httpStatus((string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO), 422);

        self::json(ReservacionAltaPresenter::json($resultado, $datos), $status);
    }

    /**
     * POST /api/punto-de-venta/reservaciones/asignar-mesas
     *
     * «Cambiar mesas» desde la ficha. Viaja con la versión y las mesas que el
     * mesero tenía en pantalla: si otra tablet la movió entretanto, el servicio
     * lo rechaza en vez de pisar el cambio ajeno.
     */
    public static function asignarMesas(Router $router): void
    {
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            return;
        }
        $id = self::reservacionId($datos);
        if ($id < 1) {
            self::json(['ok' => false, 'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS], 422);
            return;
        }
        $contextoCompleto = array_key_exists('fecha', $datos)
            && array_key_exists('hora', $datos)
            && trim((string)($datos['version_esperada'] ?? '')) !== ''
            && array_key_exists('mesa_ids_actuales', $datos);

        self::resultadoAsignacion(ReservacionMapaAdministrativaService::guardarAsignacion(
            $id,
            (array)($datos['mesa_ids'] ?? []),
            [
                'ticket_ids_aceptados' => (array)($datos['ticket_ids_aceptados'] ?? []),
                'conflicto_token' => (string)($datos['conflicto_token'] ?? ''),
                'version_esperada' => (string)($datos['version_esperada'] ?? ''),
                'usuario_id' => (int)($_SESSION['id'] ?? 0),
                'validar_contexto' => true,
                'contexto_completo' => $contextoCompleto,
                'fecha_esperada' => (string)($datos['fecha'] ?? ''),
                'hora_esperada' => (string)($datos['hora'] ?? ''),
                'mesa_ids_actuales' => (array)($datos['mesa_ids_actuales'] ?? []),
                'confirmaciones' => (array)($datos['confirmaciones'] ?? []),
            ]
        ));
    }

    /** POST /api/punto-de-venta/reservaciones/reasignar — vuelve a la asignación automática. */
    public static function reasignar(Router $router): void
    {
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            return;
        }
        $id = self::reservacionId($datos);
        if ($id < 1) {
            self::json(['ok' => false, 'codigo' => AsignacionMesasService::DATOS_INCOMPLETOS], 422);
            return;
        }

        self::resultadoAsignacion(AsignacionMesasService::asignarAutomaticamente($id));
    }

    /**
     * POST /api/punto-de-venta/reservaciones/comentario
     *
     * El comentario interno es de administración —de quien edita
     * Reservaciones—. En Operación sólo lo escondía la interfaz; aquí lo
     * cierra el servidor.
     */
    public static function comentario(Router $router): void
    {
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            return;
        }
        if (!Auth::puede('reservations', Permisos::EDITAR)) {
            self::json(['ok' => false, 'codigo' => 'PERMISO_DENEGADO'], 403);
            return;
        }
        $resultado = ReservacionService::actualizarComentario(
            self::reservacionId($datos),
            substr((string)($datos['comentario_admin'] ?? ''), 0, 5000)
        );
        $codigo = (string)($resultado['codigo'] ?? ReservacionService::ERROR_INTERNO);

        self::json(
            ['ok' => (bool)($resultado['ok'] ?? false), 'codigo' => $codigo],
            ($resultado['ok'] ?? false) ? 200 : ReservacionErrorCatalog::httpStatus($codigo, 422)
        );
    }

    /**
     * POST /api/punto-de-venta/reservaciones/reubicaciones/revisar
     *
     * Quita la insignia «Reubicada de… a…». El rastro queda en la bitácora con
     * quién lo revisó; marcarla otra vez no hace nada.
     */
    public static function revisarReubicacion(Router $router): void
    {
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            return;
        }
        $reubicacionId = (int)($datos['reubicacion_id'] ?? 0);
        if ($reubicacionId < 1) {
            self::json(['ok' => false, 'codigo' => 'DATOS_INVALIDOS'], 422);
            return;
        }
        try {
            $usuarioId = (int)($_SESSION['id'] ?? 0) ?: null;
            $marcada = ReservacionReubicacion::marcarRevisada($reubicacionId, $usuarioId);
            // Revisada en el POS, el aviso del buzón ya no tiene nada que decir.
            \Services\Notifications\BuzonNotificacionesService::cerrarEntidad(
                \Services\Reservations\ReservacionBuzonService::ENTIDAD_REUBICACION,
                $reubicacionId,
                $usuarioId,
                'revisada'
            );
        } catch (\Throwable $error) {
            error_log('PosReservacionController::revisarReubicacion - ' . $error->getMessage());
            self::json(['ok' => false, 'codigo' => ReservacionService::ERROR_INTERNO], 500);
            return;
        }

        self::json(['ok' => true, 'codigo' => 'OK', 'idempotente' => !$marcada]);
    }

    /** @param array<string, mixed> $resultado */
    private static function resultadoAsignacion(array $resultado): void
    {
        $codigo = (string)($resultado['codigo'] ?? AsignacionMesasService::ERROR_INTERNO);
        $ok = (bool)($resultado['ok'] ?? false);
        $decisiones = ReservacionErrorCatalog::decisionesResultado($resultado);

        self::json([
            'ok' => $ok,
            'codigo' => $codigo,
            'mesa_ids' => $resultado['mesa_ids'] ?? [],
            'requiere_confirmacion' => (bool)($resultado['requiere_confirmacion'] ?? false),
            'conflictos_ticket' => $resultado['conflictos_ticket'] ?? [],
            'conflicto_token' => $resultado['conflicto_token'] ?? null,
            'tickets_aceptados' => $resultado['tickets_aceptados'] ?? [],
            'version_actual' => $resultado['version_actual'] ?? null,
            'depende_liberacion_proyectada' => (bool)($resultado['depende_liberacion_proyectada'] ?? false),
            'mesas_proyectadas' => $resultado['mesas_proyectadas'] ?? [],
            'reubicaciones' => $resultado['reubicaciones'] ?? [],
            'advertencia' => $resultado['advertencia'] ?? null,
            'advertencias' => $resultado['advertencias'] ?? [],
            'confirmaciones_requeridas' => $decisiones,
        ], $ok ? 200 : ReservacionErrorCatalog::httpStatus($codigo, 422));
    }

    /** @return array<string, mixed> */
    private static function entrada(): array
    {
        $datos = json_decode((string)file_get_contents('php://input'), true);

        return is_array($datos) ? $datos : $_POST;
    }

    /** @param array<string, mixed> $datos */
    private static function reservacionId(array $datos): int
    {
        $id = filter_var(
            $datos['reservacion_id'] ?? $datos['reservation_id'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        return $id ? (int)$id : 0;
    }

    /** @param array<string, mixed> $datos */
    private static function csrfValido(array $datos): bool
    {
        if (StaffCsrfService::validarRequest($datos)) {
            return true;
        }
        self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 419);

        return false;
    }

    /** @param array<string, mixed> $datos */
    private static function json(array $datos, int $status = 200): void
    {
        if (array_key_exists('codigo', $datos) && $datos['codigo'] !== null) {
            $datos = ReservacionErrorCatalog::enriquecer($datos, ['superficie' => 'pos']);
        }
        // El contacto nunca cruza hacia quien no ve Reservaciones, tampoco
        // anidado.
        if (!Auth::puede('reservations')) {
            $datos = PosReservacionSerializer::sanitizarParaWaiter($datos);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
