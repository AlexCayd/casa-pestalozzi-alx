<?php

namespace Services;

use Classes\Auth;

/** CSRF de las escrituras del modulo administrativo de reservaciones. */
final class AdminCsrfService
{
    private const SESSION_KEY = '_admin_reservations_csrf';

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
}
