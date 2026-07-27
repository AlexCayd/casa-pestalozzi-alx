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

    /**
     * Inicia la sesión con una cookie endurecida cuando todavía es posible
     * configurarla. Secure se activa automáticamente bajo HTTPS.
     */
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
     * Devuelve la identidad verificada y renueva su vencimiento por actividad.
     *
     * @return array{contacto_tipo: string, contacto: string, verified_at: int, expires_at: int}|null
     */
    public static function obtener(bool $renovar = true): ?array
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

        if ($renovar) {
            $_SESSION[self::SESSION_KEY]['expires_at'] =
                time() + (ReservacionConfig::CLIENT_SESSION_IDLE_MINUTES * 60);
            $sesion = $_SESSION[self::SESSION_KEY];
        }

        return $sesion;
    }

    /**
     * Cierra solo el contexto público; no borra login, rol ni datos del staff.
     */
    public static function cerrar(): void
    {
        self::start();
        unset($_SESSION[self::SESSION_KEY]);
    }
}
