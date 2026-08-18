<?php

namespace Services;

/** Contexto de sesión limitado a una sola reservación afectada. */
final class ScheduleChangeAccessSession
{
    private const CONTEXT_KEY = 'schedule_change_access';
    private const CSRF_KEY = 'schedule_change_access_csrf';

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function crear(int $impactoReservacionId, int $reservacionId, string $expiresAt): void
    {
        self::start();
        session_regenerate_id(true);
        $timestamp = strtotime($expiresAt);
        $_SESSION[self::CONTEXT_KEY] = [
            'impacto_reservacion_id' => $impactoReservacionId,
            'reservacion_id' => $reservacionId,
            'expires_at' => $timestamp !== false ? $timestamp : 0,
        ];
        unset($_SESSION[self::CSRF_KEY]);
    }

    /** @return array{impacto_reservacion_id:int,reservacion_id:int,expires_at:int}|null */
    public static function obtener(): ?array
    {
        self::start();
        $contexto = $_SESSION[self::CONTEXT_KEY] ?? null;
        if (!is_array($contexto)) {
            return null;
        }

        $impactoReservacionId = filter_var($contexto['impacto_reservacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reservacionId = filter_var($contexto['reservacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $expiresAt = filter_var($contexto['expires_at'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$impactoReservacionId || !$reservacionId || !$expiresAt || $expiresAt <= time()) {
            self::limpiar();
            return null;
        }

        return [
            'impacto_reservacion_id' => (int)$impactoReservacionId,
            'reservacion_id' => (int)$reservacionId,
            'expires_at' => (int)$expiresAt,
        ];
    }

    public static function csrfToken(): string
    {
        self::start();
        $token = $_SESSION[self::CSRF_KEY] ?? '';
        if (!is_string($token) || strlen($token) < 64) {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }
        return $token;
    }

    public static function validarCsrf(string $token): bool
    {
        self::start();
        $guardado = $_SESSION[self::CSRF_KEY] ?? '';
        return is_string($guardado) && $guardado !== '' && $token !== '' && hash_equals($guardado, $token);
    }

    public static function limpiar(): void
    {
        self::start();
        unset($_SESSION[self::CONTEXT_KEY], $_SESSION[self::CSRF_KEY]);
    }
}
