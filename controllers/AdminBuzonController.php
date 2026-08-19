<?php

namespace Controllers;

use Model\Reservacion;
use MVC\Router;
use Services\AdminCsrfService;
use Services\BuzonNotificacionesService;
use Services\HorarioOperacionImpactoService;
use Services\ReservacionBuzonService;

/** API ligera del buzón flotante administrativo. */
final class AdminBuzonController
{
    public static function resumen(Router $router): void
    {
        self::json(array_merge(['ok' => true, 'codigo' => 'BUZON_RESUMEN'], BuzonNotificacionesService::resumen()));
    }

    public static function listar(Router $router): void
    {
        $notificaciones = BuzonNotificacionesService::listar(['limit' => 100]);
        $grupos = [];
        foreach ($notificaciones as $notificacion) {
            $item = self::fuente($notificacion);
            if ($item === null) {
                BuzonNotificacionesService::cerrar(
                    (int)$notificacion['id'],
                    self::usuarioId(),
                    'fuente_inexistente_o_resuelta'
                );
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
            if (($notificacion['leida_at'] ?? null) === null) {
                $grupo['leida'] = false;
            }
            $motivo = [
                'tipo' => (string)$notificacion['tipo'],
                'prioridad' => $prioridad,
                'etiqueta' => $item['etiqueta'],
                'estado' => $item['estado'] ?? null,
            ];
            $grupo['motivos'][] = $motivo;
            $grupo['notificaciones'][] = [
                'id' => (int)$notificacion['id'],
                'tipo' => (string)$notificacion['tipo'],
                'entidad_tipo' => (string)$notificacion['entidad_tipo'],
                'entidad_id' => (int)$notificacion['entidad_id'],
                'prioridad' => $prioridad,
                'leida_at' => $notificacion['leida_at'],
                'estado' => $item['estado'] ?? null,
                'impacto_id' => $item['impacto_id'] ?? null,
                'impacto_reservacion_id' => $item['impacto_reservacion_id'] ?? null,
                'tiene_contacto' => $item['tiene_contacto'] ?? null,
                'test_link_disponible' => $item['test_link_disponible'] ?? false,
            ];
            unset($grupo);
        }

        $items = array_values($grupos);
        usort($items, static function (array $a, array $b): int {
            return (($a['prioridad'] === 'alta' ? 0 : 1) <=> ($b['prioridad'] === 'alta' ? 0 : 1));
        });
        self::json([
            'ok' => true,
            'codigo' => 'BUZON_LISTA',
            'items' => $items,
            'cantidad' => count($notificaciones),
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

    /** @return array<string, mixed>|null */
    private static function fuente(array $notificacion): ?array
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
            $fuente['etiqueta'] = 'Afectada por cambio de horario';
            return $fuente;
        }

        if ($tipo === ReservacionBuzonService::TIPO_GRUPO_GRANDE) {
            $reservacion = Reservacion::findWithMesas((int)$notificacion['entidad_id']);
            if (!$reservacion) {
                return null;
            }
            $sinContacto = !in_array((string)$reservacion->contacto_tipo, ['email', 'telefono'], true)
                || trim((string)($reservacion->contacto ?? '')) === '';
            $requiereAccion = (int)$reservacion->comensales > 12
                && !in_array((string)$reservacion->estado, \Services\ReservacionConfig::ESTADOS_FINALES, true)
                && ($sinContacto || (int)$reservacion->mesas_count === 0);
            if (!$requiereAccion) {
                return null;
            }
            return [
                'reservacion_id' => (int)$reservacion->id,
                'nombre' => (string)$reservacion->nombre,
                'fecha' => (string)$reservacion->fecha,
                'hora' => substr((string)$reservacion->hora, 0, 5),
                'comensales' => (int)$reservacion->comensales,
                'estado' => (string)$reservacion->estado,
                'etiqueta' => 'Grupo grande requiere coordinación',
                'tiene_contacto' => !$sinContacto,
                'test_link_disponible' => false,
            ];
        }
        return null;
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
