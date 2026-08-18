<?php

namespace Services;

use Model\ActiveRecord;

/** Valida tokens y revalida el contexto temporal en cada request. */
final class ScheduleChangeAccessService
{
    /** @return array<string, mixed>|null */
    public static function validarToken(string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT ir.id AS impacto_reservacion_id, ir.impacto_id, ir.reservacion_id,
                    ir.estado, ir.access_expires_at, ir.access_invalidated_at,
                    i.estado AS impacto_estado,
                    r.estado AS reservacion_estado, r.fecha, r.hora,
                    r.comensales
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.access_token_hash = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $hash);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila || !self::filaValida($fila)) {
            return null;
        }

        return [
            'impacto_reservacion_id' => (int)$fila['impacto_reservacion_id'],
            'reservacion_id' => (int)$fila['reservacion_id'],
            'expires_at' => (string)$fila['access_expires_at'],
        ];
    }

    public static function intercambiarToken(string $token): bool
    {
        $fila = self::validarToken($token);
        if (!$fila) {
            return false;
        }
        ScheduleChangeAccessSession::crear(
            (int)$fila['impacto_reservacion_id'],
            (int)$fila['reservacion_id'],
            (string)$fila['expires_at']
        );
        return true;
    }

    /** Revalida sesión, ids, estado del seguimiento, expiración y edición pública. */
    public static function contextoActual(): ?array
    {
        $contexto = ScheduleChangeAccessSession::obtener();
        if (!$contexto) {
            return null;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT ir.id AS impacto_reservacion_id, ir.reservacion_id, ir.estado,
                    ir.access_expires_at, ir.access_invalidated_at,
                    i.estado AS impacto_estado,
                    r.id, r.estado AS reservacion_estado, r.fecha, r.hora,
                    r.comensales
             FROM horario_impacto_reservaciones ir
             JOIN horario_impactos i ON i.id = ir.impacto_id
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? AND ir.reservacion_id = ?
             LIMIT 1"
        );
        if (!$stmt) {
            ScheduleChangeAccessSession::limpiar();
            return null;
        }
        $impactoReservacionId = (int)$contexto['impacto_reservacion_id'];
        $reservacionId = (int)$contexto['reservacion_id'];
        $stmt->bind_param('ii', $impactoReservacionId, $reservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila || !self::filaValida($fila)) {
            ScheduleChangeAccessSession::limpiar();
            return null;
        }
        return $contexto;
    }

    /** Sólo campos de identidad y edición; nunca contacto. */
    public static function formulario(): ?array
    {
        $contexto = self::contextoActual();
        if (!$contexto) {
            return null;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            "SELECT r.nombre, r.fecha, r.hora, r.comensales, r.nota
             FROM horario_impacto_reservaciones ir
             JOIN reservaciones r ON r.id = ir.reservacion_id
             WHERE ir.id = ? AND ir.reservacion_id = ?
             LIMIT 1"
        );
        $impactoReservacionId = (int)$contexto['impacto_reservacion_id'];
        $reservacionId = (int)$contexto['reservacion_id'];
        $stmt->bind_param('ii', $impactoReservacionId, $reservacionId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$fila) {
            return null;
        }
        return [
            'nombre' => (string)$fila['nombre'],
            'fecha' => (string)$fila['fecha'],
            'hora' => substr((string)$fila['hora'], 0, 5),
            'comensales' => (int)$fila['comensales'],
            'nota' => (string)($fila['nota'] ?? ''),
            'csrf_token' => ScheduleChangeAccessSession::csrfToken(),
            'fecha_actual' => ReservacionConfig::fechaActual(),
        ];
    }

    public static function invalidar(int $impactoReservacionId): void
    {
        if ($impactoReservacionId < 1) {
            return;
        }
        $stmt = ActiveRecord::getDB()->prepare(
            'UPDATE horario_impacto_reservaciones SET access_invalidated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('i', $impactoReservacionId);
        $stmt->execute();
        $stmt->close();
    }

    private static function filaValida(array $fila): bool
    {
        if (isset($fila['impacto_estado']) && (string)$fila['impacto_estado'] !== HorarioOperacionImpactoService::ESTADO_IMPACTO_PENDIENTE) {
            return false;
        }
        if ((string)($fila['estado'] ?? '') !== HorarioOperacionImpactoService::ESTADO_ITEM_PREPARADO) {
            return false;
        }
        if (($fila['access_invalidated_at'] ?? null) !== null) {
            return false;
        }
        $expira = strtotime((string)($fila['access_expires_at'] ?? ''));
        if ($expira === false || $expira <= time()) {
            return false;
        }
        return ReservacionPublicaService::puedeModificarPublicamente([
            'estado' => (string)($fila['reservacion_estado'] ?? ''),
            'fecha' => (string)($fila['fecha'] ?? ''),
            'hora' => (string)($fila['hora'] ?? ''),
        ]);
    }
}
