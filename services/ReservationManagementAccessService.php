<?php

namespace Services;

use Model\ActiveRecord;

/** Resuelve y revalida accesos de afectaciones y recordatorios. */
final class ReservationManagementAccessService
{
    public const SOURCE_SCHEDULE_CHANGE = 'schedule_change';
    public const SOURCE_REMINDER_NEXT_DAY = 'reminder_next_day';

    /** @return array<string,mixed>|null */
    public static function validarToken(string $token): ?array
    {
        $token = trim($token);
        if (!ReservationAccessTokenService::formatoValido($token)) {
            return null;
        }
        $hash = ReservationAccessTokenService::hash($token);
        $fila = self::filaSchedulePorHash($hash);
        if ($fila !== null) {
            return self::contextoValido($fila, self::SOURCE_SCHEDULE_CHANGE);
        }
        $fila = self::filaReminderPorHash($hash);
        return $fila !== null ? self::contextoValido($fila, self::SOURCE_REMINDER_NEXT_DAY) : null;
    }

    public static function intercambiarToken(string $token): bool
    {
        $contexto = self::validarToken($token);
        if (!$contexto) {
            return false;
        }
        ReservationManagementAccessSession::crear(
            (string)$contexto['source_type'],
            (int)$contexto['source_id'],
            (int)$contexto['reservation_id'],
            (string)$contexto['access_expires_at']
        );
        return true;
    }

    /** @return array<string,mixed>|null */
    public static function contextoActual(): ?array
    {
        $sesion = ReservationManagementAccessSession::obtener();
        if (!$sesion) {
            return null;
        }
        $fila = $sesion['source_type'] === self::SOURCE_SCHEDULE_CHANGE
            ? self::filaSchedulePorIds((int)$sesion['source_id'], (int)$sesion['reservation_id'])
            : self::filaReminderPorIds((int)$sesion['source_id'], (int)$sesion['reservation_id']);
        $contexto = $fila ? self::contextoValido($fila, (string)$sesion['source_type']) : null;
        if (!$contexto) {
            ReservationManagementAccessSession::limpiar();
            return null;
        }
        return array_merge($contexto, ['csrf_token' => (string)$sesion['csrf_token']]);
    }

    /** Sólo devuelve identidad operativa; nunca contacto. */
    public static function formulario(): ?array
    {
        $contexto = self::contextoActual();
        if (!$contexto) {
            return null;
        }
        return [
            'source_type' => (string)$contexto['source_type'],
            'nombre' => (string)$contexto['nombre'],
            'fecha' => (string)$contexto['fecha'],
            'hora' => substr((string)$contexto['hora'], 0, 5),
            'comensales' => (int)$contexto['comensales'],
            'nota' => (string)($contexto['nota'] ?? ''),
            'can_modify' => (bool)$contexto['can_modify'],
            'can_cancel' => (bool)$contexto['can_cancel'],
            'csrf_token' => (string)$contexto['csrf_token'],
            'fecha_actual' => ReservacionConfig::fechaActual(),
        ];
    }

    /** Debe invocarse dentro de la transacción de la acción canónica. */
    public static function accesoValidoEnTransaccion(
        \mysqli $db,
        array $contexto,
        string $accion
    ): bool {
        $sourceType = (string)($contexto['source_type'] ?? '');
        $sourceId = (int)($contexto['source_id'] ?? 0);
        $reservationId = (int)($contexto['reservation_id'] ?? 0);
        if ($sourceId < 1 || $reservationId < 1 || !in_array($accion, ['modify', 'cancel'], true)) {
            return false;
        }
        if ($sourceType === self::SOURCE_SCHEDULE_CHANGE) {
            $stmt = $db->prepare(
                "SELECT r.estado, r.fecha, r.hora, r.comensales
                 FROM horario_impacto_reservaciones ir
                 JOIN horario_impactos i ON i.id = ir.impacto_id
                 JOIN reservaciones r ON r.id = ir.reservacion_id
                 WHERE ir.id = ? AND ir.reservacion_id = ?
                   AND ir.estado = 'notificacion_preparada' AND i.estado = 'pendiente'
                   AND ir.access_invalidated_at IS NULL AND ir.access_expires_at > NOW()
                 LIMIT 1 FOR UPDATE"
            );
        } elseif ($sourceType === self::SOURCE_REMINDER_NEXT_DAY) {
            $stmt = $db->prepare(
                "SELECT r.estado, r.fecha, r.hora, r.comensales
                 FROM reservacion_recordatorios rr
                 JOIN reservaciones r ON r.id = rr.reservacion_id
                 WHERE rr.id = ? AND rr.reservacion_id = ? AND rr.tipo = 'dia_anterior'
                   AND rr.access_invalidated_at IS NULL AND rr.access_expires_at > NOW()
                 LIMIT 1 FOR UPDATE"
            );
        } else {
            return false;
        }
        $stmt->bind_param('ii', $sourceId, $reservationId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila || (string)$fila['estado'] !== 'confirmada') {
            return false;
        }
        if ($accion === 'modify') {
            return ($sourceType !== self::SOURCE_REMINDER_NEXT_DAY
                    || (int)$fila['comensales'] <= ReservacionConfig::MAX_COMENSALES_PUBLICO)
                && ReservacionPublicaService::puedeModificarPublicamente($fila);
        }
        return ReservacionPublicaService::puedeCancelarPublicamente($fila);
    }

    /** Invalida la fuente exacta después de la acción, dentro del mismo commit. */
    public static function finalizarEnTransaccion(\mysqli $db, array $contexto): bool
    {
        $sourceType = (string)($contexto['source_type'] ?? '');
        $sourceId = (int)($contexto['source_id'] ?? 0);
        if ($sourceType === self::SOURCE_SCHEDULE_CHANGE) {
            return HorarioOperacionImpactoService::resolverAccesoTemporalEnTransaccion($db, $sourceId);
        }
        if ($sourceType !== self::SOURCE_REMINDER_NEXT_DAY || $sourceId < 1) {
            return false;
        }
        $stmt = $db->prepare(
            'UPDATE reservacion_recordatorios
             SET access_invalidated_at = COALESCE(access_invalidated_at, NOW())
             WHERE id = ? AND access_invalidated_at IS NULL'
        );
        $stmt->bind_param('i', $sourceId);
        $stmt->execute();
        $actualizada = $stmt->affected_rows === 1;
        $stmt->close();
        return $actualizada;
    }

    /** @return array<string,mixed>|null */
    private static function contextoValido(array $fila, string $sourceType): ?array
    {
        if (($fila['access_invalidated_at'] ?? null) !== null
            || (string)($fila['reservacion_estado'] ?? '') !== 'confirmada'
        ) {
            return null;
        }
        $expira = strtotime((string)($fila['access_expires_at'] ?? ''));
        if ($expira === false || $expira <= ReservacionConfig::ahora()->getTimestamp()) {
            return null;
        }
        if ($sourceType === self::SOURCE_SCHEDULE_CHANGE
            && ((string)($fila['source_estado'] ?? '') !== HorarioOperacionImpactoService::ESTADO_ITEM_PREPARADO
                || (string)($fila['impacto_estado'] ?? '') !== HorarioOperacionImpactoService::ESTADO_IMPACTO_PENDIENTE)
        ) {
            return null;
        }
        if ($sourceType === self::SOURCE_REMINDER_NEXT_DAY && (string)($fila['source_tipo'] ?? '') !== 'dia_anterior') {
            return null;
        }
        $base = [
            'estado' => (string)$fila['reservacion_estado'],
            'fecha' => (string)$fila['fecha'],
            'hora' => (string)$fila['hora'],
        ];
        $canModify = ReservacionPublicaService::puedeModificarPublicamente($base)
            && ($sourceType !== self::SOURCE_REMINDER_NEXT_DAY
                || (int)$fila['comensales'] <= ReservacionConfig::MAX_COMENSALES_PUBLICO);
        $canCancel = ReservacionPublicaService::puedeCancelarPublicamente($base);
        if (!$canModify && !$canCancel) {
            return null;
        }
        return [
            'source_type' => $sourceType,
            'source_id' => (int)$fila['source_id'],
            'reservation_id' => (int)$fila['reservation_id'],
            'access_expires_at' => (string)$fila['access_expires_at'],
            'nombre' => (string)$fila['nombre'],
            'fecha' => (string)$fila['fecha'],
            'hora' => (string)$fila['hora'],
            'comensales' => (int)$fila['comensales'],
            'nota' => (string)($fila['nota'] ?? ''),
            'can_modify' => $canModify,
            'can_cancel' => $canCancel,
        ];
    }

    private static function filaSchedulePorHash(string $hash): ?array
    {
        return self::filaSchedule('ir.access_token_hash = ?', 's', [$hash]);
    }

    private static function filaSchedulePorIds(int $sourceId, int $reservationId): ?array
    {
        return self::filaSchedule('ir.id = ? AND ir.reservacion_id = ?', 'ii', [$sourceId, $reservationId]);
    }

    private static function filaSchedule(string $where, string $types, array $params): ?array
    {
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT ir.id AS source_id, ir.reservacion_id AS reservation_id, ir.estado AS source_estado,
                    ir.access_expires_at, ir.access_invalidated_at, i.estado AS impacto_estado,
                    r.estado AS reservacion_estado, r.nombre, r.fecha, r.hora, r.comensales, r.nota
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE {$where} LIMIT 1"
        );
        self::bind($stmt, $types, $params);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function filaReminderPorHash(string $hash): ?array
    {
        return self::filaReminder('rr.access_token_hash = ?', 's', [$hash]);
    }

    private static function filaReminderPorIds(int $sourceId, int $reservationId): ?array
    {
        return self::filaReminder('rr.id = ? AND rr.reservacion_id = ?', 'ii', [$sourceId, $reservationId]);
    }

    private static function filaReminder(string $where, string $types, array $params): ?array
    {
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT rr.id AS source_id, rr.reservacion_id AS reservation_id, rr.tipo AS source_tipo,
                    rr.access_expires_at, rr.access_invalidated_at,
                    r.estado AS reservacion_estado, r.nombre, r.fecha, r.hora, r.comensales, r.nota
             FROM reservacion_recordatorios rr
             JOIN reservaciones r ON r.id = rr.reservacion_id
             WHERE {$where} LIMIT 1"
        );
        self::bind($stmt, $types, $params);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $fila;
    }

    private static function bind(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if ($types === 's') {
            $value = (string)($params[0] ?? '');
            $stmt->bind_param('s', $value);
            return;
        }
        $first = (int)($params[0] ?? 0);
        $second = (int)($params[1] ?? 0);
        $stmt->bind_param('ii', $first, $second);
    }
}
