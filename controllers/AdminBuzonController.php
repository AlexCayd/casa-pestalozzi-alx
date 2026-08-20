<?php

namespace Controllers;

use Model\Reservacion;
use Model\TicketMesa;
use MVC\Router;
use Services\AdminCsrfService;
use Services\BuzonNotificacionesService;
use Services\HorarioOperacionImpactoService;
use Services\HorarioOperacionService;
use Services\ReservacionBuzonService;
use Services\ReservacionConfig;
use Services\ReservacionPoliticaPosService;
use Services\ReservacionVigenciaService;

/** API ligera del buzón flotante administrativo. */
final class AdminBuzonController
{
    public static function resumen(Router $router): void
    {
        self::json(array_merge(['ok' => true, 'codigo' => 'BUZON_RESUMEN'], BuzonNotificacionesService::resumen()));
    }

    public static function sincronizar(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::json(['ok' => false, 'codigo' => 'METODO_NO_PERMITIDO'], 405);
            return;
        }
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }

        $resultado = ReservacionBuzonService::sincronizarPendientesTemporales();
        self::json(array_merge(
            ['ok' => true, 'codigo' => 'BUZON_SINCRONIZADO'],
            $resultado['resumen'],
            ['sincronizacion' => [
                'procesadas' => $resultado['procesadas'],
                'avisos_creados' => $resultado['avisos_creados'],
                'avisos_cerrados' => $resultado['avisos_cerrados'],
            ]]
        ));
    }

    public static function listar(Router $router): void
    {
        $notificaciones = BuzonNotificacionesService::listar(['limit' => 100]);
        $ticketsPorReservacion = [];
        foreach (TicketMesa::abiertosParaMapa() as $ticket) {
            $reservacionId = (int)($ticket['reservacion_id'] ?? 0);
            if ($reservacionId > 0) {
                $ticketsPorReservacion[$reservacionId] = $ticket;
            }
        }
        $grupos = [];
        foreach ($notificaciones as $notificacion) {
            $item = self::fuente($notificacion, $ticketsPorReservacion);
            $tipo = (string)$notificacion['tipo'];
            if ($item === null) {
                // GET es estrictamente lectura. La reconciliación de fuentes
                // huérfanas o resueltas vive en POST /sincronizar.
                continue;
            }
            $reservacionId = (int)$item['reservacion_id'];
            if (!isset($grupos[$reservacionId])) {
                $grupos[$reservacionId] = [
                    'reservacion_id' => $reservacionId,
                    'nombre' => (string)$item['nombre'],
                    'fecha' => (string)$item['fecha'],
                    'hora' => (string)$item['hora'],
                    'comensales' => (int)$item['comensales'],
                    'prioridad' => 'normal',
                    'severidad' => (int)($item['severidad'] ?? 50),
                    'leida' => true,
                    'motivos' => [],
                'notificaciones' => [],
                ];
            }
            $grupo =& $grupos[$reservacionId];
            $prioridad = (string)($notificacion['prioridad'] ?? 'normal');
            if ($prioridad === 'alta') {
                $grupo['prioridad'] = 'alta';
            }
            $grupo['severidad'] = min($grupo['severidad'], (int)($item['severidad'] ?? 50));
            if (($notificacion['leida_at'] ?? null) === null) {
                $grupo['leida'] = false;
            }
            $grupo['motivos'][] = [
                'tipo' => $tipo,
                'prioridad' => $prioridad,
                'etiqueta' => $item['etiqueta'],
                'descripcion' => $item['descripcion'] ?? null,
                'estado' => $item['estado'] ?? null,
                'severidad' => (int)($item['severidad'] ?? 50),
            ];
            $grupo['notificaciones'][] = [
                'id' => (int)$notificacion['id'],
                'tipo' => $tipo,
                'entidad_tipo' => (string)$notificacion['entidad_tipo'],
                'entidad_id' => (int)$notificacion['entidad_id'],
                'prioridad' => $prioridad,
                'leida_at' => $notificacion['leida_at'],
                'estado' => $item['estado'] ?? null,
                'etiqueta' => $item['etiqueta'],
                'descripcion' => $item['descripcion'] ?? null,
                'accion_primaria' => $item['accion_primaria'] ?? null,
                'puede_registrar_no_show' => (bool)($item['puede_registrar_no_show'] ?? false),
                'puede_asignar_mesas' => (bool)($item['puede_asignar_mesas'] ?? false),
                'ventana_operativa' => $item['ventana_operativa'] ?? null,
                'impacto_id' => $item['impacto_id'] ?? null,
                'impacto_reservacion_id' => $item['impacto_reservacion_id'] ?? null,
                'tiene_contacto' => $item['tiene_contacto'] ?? null,
                'test_link_disponible' => $item['test_link_disponible'] ?? false,
                'requiere_accion' => (bool)($item['requiere_accion'] ?? ((int)($notificacion['requiere_accion'] ?? 1) === 1)),
                'puede_mandar_aviso' => (bool)($item['puede_mandar_aviso'] ?? false),
                'mensaje_aviso' => $item['mensaje_aviso'] ?? null,
                'access_expires_at' => $item['access_expires_at'] ?? null,
                'notification_attempts' => (int)($item['notification_attempts'] ?? 0),
                'cooldown_hasta' => $item['cooldown_hasta'] ?? null,
            ];
            unset($grupo);
        }

        foreach ($grupos as &$grupo) {
            $hayAusencia = array_filter(
                $grupo['notificaciones'],
                static fn(array $notificacion): bool => $notificacion['tipo'] === ReservacionBuzonService::TIPO_AUSENCIA_PENDIENTE
            );
            if ($hayAusencia) {
                $grupo['notificaciones'] = array_values(array_filter(
                    $grupo['notificaciones'],
                    static fn(array $notificacion): bool => $notificacion['tipo'] !== ReservacionBuzonService::TIPO_SIN_ASIGNACION_PROXIMA
                ));
                $grupo['motivos'] = array_values(array_filter(
                    $grupo['motivos'],
                    static fn(array $motivo): bool => $motivo['tipo'] !== ReservacionBuzonService::TIPO_SIN_ASIGNACION_PROXIMA
                ));
            }
            usort($grupo['motivos'], static function (array $a, array $b): int {
                return [(int)($a['severidad'] ?? 50), (string)($a['etiqueta'] ?? '')]
                    <=> [(int)($b['severidad'] ?? 50), (string)($b['etiqueta'] ?? '')];
            });
        }
        unset($grupo);

        $items = array_values(array_filter($grupos, static fn(array $grupo): bool => $grupo['notificaciones'] !== []));
        usort($items, static function (array $a, array $b): int {
            return [$a['severidad'], $a['prioridad'] === 'alta' ? 0 : 1]
                <=> [$b['severidad'], $b['prioridad'] === 'alta' ? 0 : 1];
        });
        self::json([
            'ok' => true,
            'codigo' => 'BUZON_LISTA',
            'items' => $items,
            ...BuzonNotificacionesService::resumen(),
        ]);
    }

    public static function marcarLeida(Router $router): void
    {
        $datos = self::entrada();
        if (!self::csrfValido($datos)) {
            self::json(['ok' => false, 'codigo' => 'CSRF_INVALIDO'], 403);
            return;
        }
        $ok = BuzonNotificacionesService::marcarLeida((int)($datos['id'] ?? 0));
        self::json([
            'ok' => $ok,
            'codigo' => $ok ? 'BUZON_MARCADO_LEIDO' : 'BUZON_AVISO_NO_ENCONTRADO',
        ], $ok ? 200 : 404);
    }

    /** @param array<int, array<string, mixed>> $ticketsPorReservacion */
    private static function fuente(array $notificacion, array $ticketsPorReservacion = []): ?array
    {
        $tipo = (string)$notificacion['tipo'];
        if ($tipo === ReservacionBuzonService::TIPO_HORARIO_AFECTADO) {
            $fuente = HorarioOperacionImpactoService::obtenerPorItem((int)$notificacion['entidad_id']);
            if (!$fuente || in_array((string)$fuente['estado'], [
                HorarioOperacionImpactoService::ESTADO_ITEM_MANUAL,
                HorarioOperacionImpactoService::ESTADO_ITEM_CLIENTE,
            ], true)) {
                return null;
            }
            $ahora = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
            $expirada = (string)($fuente['access_expires_at'] ?? '') !== ''
                && (string)$fuente['access_expires_at'] <= $ahora;
            $tieneContacto = (bool)($fuente['tiene_contacto'] ?? false);
            $esGrupoGrande = (int)($fuente['comensales'] ?? 0) > ReservacionConfig::MAX_COMENSALES_PUBLICO;
            $requiereAccion = !$tieneContacto || $esGrupoGrande || $expirada;
            if ($esGrupoGrande) {
                $fuente['etiqueta'] = 'Requiere gestión manual';
                $fuente['descripcion'] = 'Los grupos de más de 12 personas se coordinan desde la reservación.';
                $fuente['severidad'] = 30;
            } elseif (!$tieneContacto) {
                $fuente['etiqueta'] = 'Falta un contacto';
                $fuente['descripcion'] = 'Agrega un correo o teléfono para enviar el enlace.';
                $fuente['severidad'] = 20;
            } elseif ($expirada) {
                $fuente['etiqueta'] = 'Sin respuesta';
                $fuente['descripcion'] = 'El enlace para cambiar el horario venció.';
                $fuente['severidad'] = 20;
            } else {
                $fuente['etiqueta'] = 'Esperando respuesta';
                $horaExpiracion = '';
                try {
                    $horaExpiracion = (new \DateTimeImmutable((string)$fuente['access_expires_at'], ReservacionConfig::timezone()))->format('H:i');
                } catch (\Throwable) {
                    $horaExpiracion = substr((string)$fuente['access_expires_at'], 11, 5);
                }
                $fuente['descripcion'] = 'El cliente tiene un enlace activo hasta ' . $horaExpiracion . '.';
                $fuente['severidad'] = 50;
            }
            $fuente['accion_primaria'] = 'Abrir reservación';
            $fuente['requiere_accion'] = $requiereAccion;
            $fuente['puede_mandar_aviso'] = !$esGrupoGrande
                && $tieneContacto
                && (bool)($fuente['puede_mandar_aviso'] ?? false);
            $fuente['mensaje_aviso'] = $fuente['puede_mandar_aviso']
                ? 'Enviar recordatorio'
                : ((int)($fuente['notification_attempts'] ?? 0) >= ReservacionConfig::SCHEDULE_CHANGE_NOTIFICATION_MAX_ATTEMPTS
                    ? 'Se alcanzó el límite de recordatorios.'
                    : null);
            return $fuente;
        }

        if (!in_array($tipo, [
            ReservacionBuzonService::TIPO_GRUPO_GRANDE,
            ReservacionBuzonService::TIPO_AUSENCIA_PENDIENTE,
            ReservacionBuzonService::TIPO_SIN_ASIGNACION_PROXIMA,
        ], true)) {
            return null;
        }

        $reservacion = Reservacion::findWithMesas((int)$notificacion['entidad_id']);
        if (!$reservacion) {
            return null;
        }
        $reservacionId = (int)$reservacion->id;
        $ticket = $ticketsPorReservacion[$reservacionId] ?? null;
        $estado = (string)$reservacion->estado;
        $estadoFinal = in_array($estado, ReservacionConfig::ESTADOS_FINALES, true);
        $sinContacto = !in_array((string)$reservacion->contacto_tipo, ['email', 'telefono'], true)
            || trim((string)($reservacion->contacto ?? '')) === '';
        $vigencia = ReservacionVigenciaService::clasificar($reservacion, null, $ticket);
        $politica = ReservacionPoliticaPosService::evaluar(
            $reservacion,
            null,
            $ticket,
            null,
            ['sin_mesas' => (int)$reservacion->mesas_count === 0]
        );

        $base = [
            'reservacion_id' => $reservacionId,
            'nombre' => (string)$reservacion->nombre,
            'fecha' => (string)$reservacion->fecha,
            'hora' => substr((string)$reservacion->hora, 0, 5),
            'comensales' => (int)$reservacion->comensales,
            'estado' => $estado,
            'tiene_contacto' => !$sinContacto,
            'test_link_disponible' => false,
            'requiere_accion' => true,
        ];

        if ($tipo === ReservacionBuzonService::TIPO_GRUPO_GRANDE) {
            if (!ReservacionBuzonService::grupoGrandeVisibleParaBuzon($reservacion)) {
                return null;
            }
            return array_merge($base, [
                'etiqueta' => 'Requiere gestión manual',
                'descripcion' => 'Los grupos de más de 12 personas se coordinan desde la reservación.',
                'accion_primaria' => 'Abrir reservación',
                'requiere_accion' => true,
                'severidad' => 30,
            ]);
        }

        if ($tipo === ReservacionBuzonService::TIPO_AUSENCIA_PENDIENTE) {
            if ($estado !== 'confirmada'
                || $ticket
                || !$vigencia['ausencia_pendiente']
                || !$vigencia['puede_marcar_no_show']) {
                return null;
            }
            return array_merge($base, [
                'etiqueta' => 'Tolerancia vencida',
                'descripcion' => 'El cliente no llegó dentro del tiempo de tolerancia.',
                'accion_primaria' => 'Registrar que no llegó',
                'puede_registrar_no_show' => true,
                'requiere_accion' => true,
                'severidad' => 10,
            ]);
        }

        $fueraHorario = !HorarioOperacionService::estaAbierto(
            (string)$reservacion->fecha,
            (string)$reservacion->hora
        );
        $ventana = (string)($vigencia['ventana_operativa']['estado'] ?? $politica['ventana_pos'] ?? 'futura');
        if ($estado !== 'confirmada'
            || $estadoFinal
            || $ticket
            || (int)$reservacion->mesas_count > 0
            || (int)$reservacion->comensales > ReservacionConfig::MAX_COMENSALES_PUBLICO
            || $vigencia['ausencia_pendiente']
            || $fueraHorario
            || !in_array($ventana, ['advertencia', 'bloqueo', 'tolerancia'], true)) {
            return null;
        }
        return array_merge($base, [
            'etiqueta' => 'Falta asignar mesas',
            'descripcion' => 'La reservación comienza a las ' . substr((string)$reservacion->hora, 0, 5) . ' y todavía no tiene mesas asignadas.',
            'accion_primaria' => 'Asignar mesas',
            'puede_asignar_mesas' => true,
            'requiere_accion' => true,
            'ventana_operativa' => $ventana,
            'severidad' => 40,
        ]);
    }

    private static function entrada(): array
    {
        $json = json_decode((string)file_get_contents('php://input'), true);
        return is_array($json) ? $json : $_POST;
    }

    private static function csrfValido(array $datos): bool
    {
        return AdminCsrfService::validar(isset($datos['admin_csrf']) ? (string)$datos['admin_csrf'] : null);
    }

    private static function usuarioId(): ?int
    {
        $id = filter_var($_SESSION['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return $id ? (int)$id : null;
    }

    private static function json(array $resultado, int $status = 200): void
    {
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
