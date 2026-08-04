<?php

/**
 * Sesión temporal e independiente para clientes de reservaciones.
 *
 * Comparte la cookie PHP con el sitio para poder coexistir con una sesión de
 * personal, pero usa exclusivamente el namespace reservation_client.
 */

namespace Services;

class ReservationClientSession
{
    private const SESSION_KEY = 'reservation_client';
    private const CSRF_KEY = 'reservation_client_csrf';

    /**
     * Inicia la sesión con una cookie endurecida cuando todavía es posible
     * configurarla. Secure se activa automáticamente bajo HTTPS.
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
            $environment = ReservacionConfig::appEnvironment();
            if (in_array($environment, ['development', 'testing'], true)) {
                $sessionPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . '.sessions';
                if (is_dir($sessionPath)) {
                    ini_set('session.save_path', $sessionPath);
                }
            }
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

    public static function csrfToken(): string
    {
        self::start();
        $token = $_SESSION[self::CSRF_KEY] ?? null;
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

        return is_string($guardado)
            && $guardado !== ''
            && $token !== ''
            && hash_equals($guardado, $token);
    }

    /**
     * Regenera el ID para impedir fijación y conserva cualquier sesión
     * administrativa ya existente sin copiar privilegios al cliente.
     */
    public static function crear(string $tipo, string $contacto): void
    {
        self::start();
        session_regenerate_id(true);
        $ahora = time();

        $_SESSION[self::SESSION_KEY] = [
            'contacto_tipo' => $tipo,
            'contacto' => $contacto,
            'verified_at' => $ahora,
            'expires_at' => $ahora + (ReservacionConfig::CLIENT_SESSION_IDLE_MINUTES * 60),
        ];
    }

    /**
     * Devuelve la identidad verificada sin extender su vencimiento.
     * Una nueva verificación es la única forma de crear otra sesión.
     *
     * @return array{contacto_tipo: string, contacto: string, verified_at: int, expires_at: int}|null
     */
    public static function obtener(bool $renovar = false): ?array
    {
        self::start();
        $sesion = $_SESSION[self::SESSION_KEY] ?? null;

        if (!is_array($sesion) || (int)($sesion['expires_at'] ?? 0) <= time()) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        if (
            !in_array((string)($sesion['contacto_tipo'] ?? ''), ContactoService::TIPOS, true)
            || trim((string)($sesion['contacto'] ?? '')) === ''
        ) {
            unset($_SESSION[self::SESSION_KEY]);
            return null;
        }

        return $sesion;
    }

    /**
     * Cierra solo el contexto público; no borra login, rol ni datos del staff.
     */
    public static function cerrar(): void
    {
        self::start();
        unset($_SESSION[self::SESSION_KEY], $_SESSION[self::CSRF_KEY]);
    }
}
