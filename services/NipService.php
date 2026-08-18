<?php

namespace Services;

/**
 * Fuente única para las credenciales de cuatro dígitos del personal de piso.
 *
 * El valor plano sólo vive en la llamada que lo genera. La base de datos recibe
 * el hash para verificarlo y un HMAC determinista para localizarlo y aplicar
 * la restricción UNIQUE sin exponer un digest precalculable.
 */
final class NipService
{
    public const LONGITUD = 4;
    public const MAX_INTENTOS = 50;

    /** @var callable|null */
    private static $generador = null;

    public static function generar(): string
    {
        $valor = self::$generador !== null
            ? call_user_func(self::$generador)
            : str_pad((string) random_int(0, 9999), self::LONGITUD, '0', STR_PAD_LEFT);

        $nip = (string) $valor;
        if (!preg_match('/^\d{4}$/', $nip)) {
            throw new \RuntimeException('El generador de NIP devolvió un formato inválido.');
        }

        return $nip;
    }

    /** Permite controlar el candidato en pruebas deterministas. */
    public static function usarGenerador(?callable $generador): void
    {
        self::$generador = $generador;
    }

    public static function validar(string $nip): bool
    {
        return preg_match('/^\d{4}$/', $nip) === 1;
    }

    public static function lookup(string $nip): string
    {
        if (!self::validar($nip)) {
            throw new \InvalidArgumentException('El NIP debe tener exactamente cuatro dígitos.');
        }

        $secreto = self::secreto();

        return hash_hmac('sha256', $nip, $secreto);
    }

    /** @return array{hash: string, lookup: string} */
    public static function credencial(string $nip): array
    {
        return [
            'hash' => password_hash($nip, PASSWORD_DEFAULT),
            'lookup' => self::lookup($nip),
        ];
    }

    /**
     * Preselección determinista para reducir colisiones frecuentes. La BD
     * sigue siendo la autoridad final: el llamador debe persistir y reintentar
     * si la restricción UNIQUE rechaza una carrera concurrente.
     *
     * @return array{nip: string, hash: string, lookup: string}
     */
    public static function generarCredencialDisponible(callable $ocupado): array
    {
        for ($intento = 1; $intento <= self::MAX_INTENTOS; $intento++) {
            $nip = self::generar();
            $credencial = self::credencial($nip);
            if (!call_user_func($ocupado, $credencial['lookup'])) {
                return [
                    'nip' => $nip,
                    'hash' => $credencial['hash'],
                    'lookup' => $credencial['lookup'],
                ];
            }
        }

        throw new \RuntimeException('No fue posible generar un NIP disponible.');
    }

    public static function secretoConfigurado(): bool
    {
        return self::secreto(false) !== null;
    }

    /**
     * Un error de clave duplicada sólo se considera colisión de NIP cuando el
     * nombre de la restricción lo confirma; un username duplicado no debe
     * gastar los 50 reintentos ni ocultar el error correcto.
     */
    public static function esColision(\Throwable $error): bool
    {
        $mensaje = strtolower($error->getMessage());

        return ((int) $error->getCode() === 1062 || str_contains($mensaje, 'duplicate'))
            && str_contains($mensaje, 'nip_lookup');
    }

    /** @return string|null */
    private static function secreto(bool $obligatorio = true): ?string
    {
        $secreto = $_ENV['NIP_LOOKUP_SECRET'] ?? '';
        if (!is_string($secreto) || trim($secreto) === '') {
            $secreto = getenv('NIP_LOOKUP_SECRET');
        }
        $secreto = is_string($secreto) ? trim($secreto) : '';

        if ($secreto === '') {
            if ($obligatorio) {
                throw new \RuntimeException('NIP_LOOKUP_SECRET no está configurado.');
            }

            return null;
        }

        return $secreto;
    }
}
