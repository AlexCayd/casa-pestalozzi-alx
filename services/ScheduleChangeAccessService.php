<?php

namespace Services;

/** Alias compatible hacia la autorización temporal generalizada. */
final class ScheduleChangeAccessService
{
    public static function validarToken(string $token): ?array
    {
        $contexto = ReservationManagementAccessService::validarToken($token);
        if (!$contexto || $contexto['source_type'] !== ReservationManagementAccessService::SOURCE_SCHEDULE_CHANGE) {
            return null;
        }
        return [
            'impacto_reservacion_id' => (int)$contexto['source_id'],
            'reservacion_id' => (int)$contexto['reservation_id'],
            'expires_at' => (string)$contexto['access_expires_at'],
        ];
    }

    public static function intercambiarToken(string $token): bool
    {
        $contexto = self::validarToken($token);
        if (!$contexto) {
            return false;
        }
        ScheduleChangeAccessSession::crear(
            (int)$contexto['impacto_reservacion_id'],
            (int)$contexto['reservacion_id'],
            (string)$contexto['expires_at']
        );
        return true;
    }

    public static function contextoActual(): ?array
    {
        $contexto = ReservationManagementAccessService::contextoActual();
        if (!$contexto || $contexto['source_type'] !== ReservationManagementAccessService::SOURCE_SCHEDULE_CHANGE) {
            return null;
        }
        return [
            'impacto_reservacion_id' => (int)$contexto['source_id'],
            'reservacion_id' => (int)$contexto['reservation_id'],
            'expires_at' => strtotime((string)$contexto['access_expires_at']) ?: 0,
        ];
    }

    public static function formulario(): ?array
    {
        return ReservationManagementAccessService::formulario();
    }

    public static function invalidar(int $impactoReservacionId): void
    {
        if ($impactoReservacionId < 1) {
            return;
        }
        $stmt = \Model\ActiveRecord::getDB()->prepare(
            'UPDATE horario_impacto_reservaciones SET access_invalidated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('i', $impactoReservacionId);
        $stmt->execute();
        $stmt->close();
    }
}
