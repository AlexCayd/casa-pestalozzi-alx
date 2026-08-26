<?php

namespace Services;

/** Sesión efímera limitada a una fuente y una sola reservación. */
final class ReservationManagementAccessSession
{
    private const SESSION_KEY = 'reservation_management_access';

    public static function start(): void
    {
        ReservationClientSession::start();
    }

    public static function crear(string $sourceType, int $sourceId, int $reservationId, string $expiresAt): void
    {
        self::start();
        session_regenerate_id(true);
        $timestamp = strtotime($expiresAt);
        $_SESSION[self::SESSION_KEY] = [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reservation_id' => $reservationId,
            'expires_at' => $timestamp !== false ? $timestamp : 0,
            'csrf_token' => bin2hex(random_bytes(32)),
        ];
    }

    /** @return array{source_type:string,source_id:int,reservation_id:int,expires_at:int,csrf_token:string}|null */
    public static function obtener(): ?array
    {
        self::start();
        $contexto = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($contexto)) {
            return null;
        }
        $sourceType = (string)($contexto['source_type'] ?? '');
        $sourceId = filter_var($contexto['source_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reservationId = filter_var($contexto['reservation_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expiresAt = filter_var($contexto['expires_at'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $csrf = (string)($contexto['csrf_token'] ?? '');
        if (!in_array($sourceType, ['schedule_change', 'reminder_next_day'], true)
            || !$sourceId
            || !$reservationId
            || !$expiresAt
            || $expiresAt <= ReservacionConfig::ahora()->getTimestamp()
            || strlen($csrf) < 64
        ) {
            self::limpiar();
            return null;
        }
        return [
            'source_type' => $sourceType,
            'source_id' => (int)$sourceId,
            'reservation_id' => (int)$reservationId,
            'expires_at' => (int)$expiresAt,
            'csrf_token' => $csrf,
        ];
    }

    public static function csrfToken(): string
    {
        return (string)(self::obtener()['csrf_token'] ?? '');
    }

    public static function validarCsrf(string $token): bool
    {
        $esperado = self::csrfToken();
        return $esperado !== '' && $token !== '' && hash_equals($esperado, $token);
    }

    public static function limpiar(): void
    {
        self::start();
        unset($_SESSION[self::SESSION_KEY]);
    }
}
