<?php

namespace Services;

/** Genera y presenta tokens de gestión sin persistir su valor plano. */
final class ReservationAccessTokenService
{
    /** @return array{token:string,hash:string} */
    public static function generar(): array
    {
        $token = bin2hex(random_bytes(32));
        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function formatoValido(string $token): bool
    {
        return preg_match('/^[a-f0-9]{64}$/i', trim($token)) === 1;
    }

    public static function url(string $token): string
    {
        if (!self::formatoValido($token)) {
            throw new \InvalidArgumentException('El token de gestión no es válido.');
        }
        $base = ReservacionConfig::reservationPublicBaseUrl();
        if ($base === '') {
            throw new \RuntimeException('RESERVATION_PUBLIC_BASE_URL no está configurada.');
        }
        return $base . '/reservaciones/gestionar?access=' . rawurlencode($token);
    }
}
