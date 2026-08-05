<?php

namespace Services;

use Classes\Auth;

/** CSRF comun para todas las escrituras del personal autenticado por cookie. */
final class StaffCsrfService
{
    private const SESSION_KEY = '_staff_csrf_token';

    public static function token(): string
    {
        Auth::start();
        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return (string)$_SESSION[self::SESSION_KEY];
    }

    public static function validar(?string $token): bool
    {
        Auth::start();
        $esperado = (string)($_SESSION[self::SESSION_KEY] ?? '');
        $recibido = trim((string)$token);

        return $esperado !== '' && $recibido !== '' && hash_equals($esperado, $recibido);
    }

    public static function validarRequest(array $datos = []): bool
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($datos['csrf_token'] ?? null);
        return self::validar(is_string($token) ? $token : null);
    }
}
