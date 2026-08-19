<?php

namespace Services;

use Model\ActiveRecord;

/** Reglas de reservaciones que alimentan el buzón reutilizable. */
final class ReservacionBuzonService
{
    public const TIPO_HORARIO_AFECTADO = 'reservacion_horario_afectado';
    public const TIPO_GRUPO_GRANDE = 'reservacion_grupo_grande';
    public const ENTIDAD_IMPACTO_RESERVACION = 'horario_impacto_reservacion';
    public const ENTIDAD_RESERVACION = 'reservacion';

    public static function dedupHorario(int $impactoReservacionId): string
    {
        return 'reservacion_horario_afectado:' . $impactoReservacionId;
    }

    public static function crearSeguimientoHorarioEnTransaccion(
        \mysqli $db,
        int $impactoReservacionId,
        string $prioridad,
        ?string $visibleFrom
    ): int {
        return BuzonNotificacionesService::crearEnTransaccion($db, [
            'tipo' => self::TIPO_HORARIO_AFECTADO,
            'modulo' => 'reservaciones',
            'entidad_tipo' => self::ENTIDAD_IMPACTO_RESERVACION,
            'entidad_id' => $impactoReservacionId,
            'prioridad' => $prioridad,
            'visible_from' => $visibleFrom,
            'dedup_key' => self::dedupHorario($impactoReservacionId),
        ]);
    }

    /**
     * Crea el seguimiento de grupo sólo si existe una acción operativa real:
     * falta de mesas/asignación o falta de contacto. Un grupo coordinado no
     * llena el buzón por el número de personas.
     */
    public static function sincronizarGrupoGrande(int $reservacionId, ?int $usuarioId = null): bool
    {
        if ($reservacionId < 1) {
            return false;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT r.id, r.estado, r.comensales, r.contacto_tipo, r.contacto,
                    COUNT(rm.id) AS mesas_count
             FROM reservaciones r
             LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id
             WHERE r.id = ?
             GROUP BY r.id, r.estado, r.comensales, r.contacto_tipo, r.contacto
             LIMIT 1"
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $reservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila) {
            BuzonNotificacionesService::cerrarEntidad(
                self::ENTIDAD_RESERVACION,
                $reservacionId,
                $usuarioId,
                'fuente_inexistente'
            );
            return false;
        }

        $estadoFinal = in_array((string)$fila['estado'], ReservacionConfig::ESTADOS_FINALES, true);
        $sinContacto = !in_array((string)$fila['contacto_tipo'], ['email', 'telefono'], true)
            || trim((string)($fila['contacto'] ?? '')) === '';
        $requiereAccion = (int)$fila['comensales'] > ReservacionConfig::MAX_COMENSALES_PUBLICO
            && !$estadoFinal
            && ($sinContacto || (int)$fila['mesas_count'] === 0);

        if (!$requiereAccion) {
            BuzonNotificacionesService::cerrarEntidad(
                self::ENTIDAD_RESERVACION,
                $reservacionId,
                $usuarioId,
                $estadoFinal ? 'reservacion_finalizada' : 'coordinacion_completada'
            );
            return false;
        }

        BuzonNotificacionesService::crear([
            'tipo' => self::TIPO_GRUPO_GRANDE,
            'modulo' => 'reservaciones',
            'entidad_tipo' => self::ENTIDAD_RESERVACION,
            'entidad_id' => $reservacionId,
            'prioridad' => BuzonNotificacionesService::PRIORIDAD_ALTA,
            'visible_from' => null,
            'dedup_key' => 'reservacion_grupo_grande:' . $reservacionId,
        ]);
        return true;
    }

    public static function cerrarGrupoGrande(int $reservacionId, ?int $usuarioId, string $motivo): int
    {
        return BuzonNotificacionesService::cerrarEntidad(
            self::ENTIDAD_RESERVACION,
            $reservacionId,
            $usuarioId,
            $motivo
        );
    }
}
