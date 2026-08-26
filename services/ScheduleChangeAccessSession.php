<?php

namespace Services;

/** Alias de compatibilidad; la sesión real es la de gestión compartida. */
final class ScheduleChangeAccessSession
{
    public static function start(): void
    {
        ReservationManagementAccessSession::start();
    }

    public static function crear(int $impactoReservacionId, int $reservacionId, string $expiresAt): void
    {
        ReservationManagementAccessSession::crear(
            ReservationManagementAccessService::SOURCE_SCHEDULE_CHANGE,
            $impactoReservacionId,
            $reservacionId,
            $expiresAt
        );
    }

    public static function obtener(): ?array
    {
        $contexto = ReservationManagementAccessSession::obtener();
        if (!$contexto || $contexto['source_type'] !== ReservationManagementAccessService::SOURCE_SCHEDULE_CHANGE) {
            return null;
        }
        return [
            'impacto_reservacion_id' => (int)$contexto['source_id'],
            'reservacion_id' => (int)$contexto['reservation_id'],
            'expires_at' => (int)$contexto['expires_at'],
        ];
    }

    public static function csrfToken(): string
    {
        return ReservationManagementAccessSession::csrfToken();
    }

    public static function validarCsrf(string $token): bool
    {
        return ReservationManagementAccessSession::validarCsrf($token);
    }

    public static function limpiar(): void
    {
        ReservationManagementAccessSession::limpiar();
    }
}
