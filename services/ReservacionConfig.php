<?php

/**
 * Expone constantes y datos de contacto del modulo de reservaciones.
 * Mantiene valores compartidos fuera de controladores y modelos.
 */

namespace Services;

use DateTimeZone;

class ReservacionConfig
{
    public const NOMBRE_MAX_CARACTERES = 100;
    public const EMAIL_MAX_CARACTERES = 150;
    public const MAX_COMENSALES_PUBLICO = 12;
    public const MAX_COMENSALES_ADMIN = 44;
    public const NOTA_MAX_CARACTERES = 500;
    public const COMENTARIO_ADMIN_MAX_CARACTERES = 5000;
    public const MINUTOS_PREVIOS_BLOQUEO = 30;
    public const DURACION_RESERVACION_MINUTOS = 90;
    public const COMBINACIONES_PUBLICAS_AUTORIZADAS = [
        [2, 4],
        [5, 11],
        [10, 11],
        [8, 9],
        [2, 4, 5],
        [5, 10, 11],
        [8, 9, 10],
    ];

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::env('APP_TIMEZONE', 'America/Mexico_City'));
    }

    public static function fechaActual(): string
    {
        return (new \DateTimeImmutable('today', self::timezone()))->format('Y-m-d');
    }

    public static function horaActual(): string
    {
        return (new \DateTimeImmutable('now', self::timezone()))->format('H:i:s');
    }

    public static function telefonoVisible(): string
    {
        return self::env('RESERVAS_TELEFONO_VISIBLE', '56 1481 8297');
    }

    public static function telefonoTel(): string
    {
        return self::normalizarTel(self::env('RESERVAS_TELEFONO_TEL', '+525614818297'));
    }

    public static function whatsappNumero(): string
    {
        return self::normalizarWhatsapp(self::env('RESERVAS_WHATSAPP', self::telefonoTel()));
    }

    public static function whatsappUrl(): string
    {
        return 'https://wa.me/' . self::whatsappNumero();
    }

    public static function contactoPublico(): array
    {
        return [
            'telefono_visible' => self::telefonoVisible(),
            'telefono_tel' => self::telefonoTel(),
            'whatsapp' => self::whatsappNumero(),
            'whatsapp_url' => self::whatsappUrl(),
            'max_comensales' => self::MAX_COMENSALES_PUBLICO,
        ];
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private static function normalizarTel(string $telefono): string
    {
        $telefono = trim($telefono);
        $prefijo = str_starts_with($telefono, '+') ? '+' : '';
        $digitos = preg_replace('/\D+/', '', $telefono) ?? '';

        return $prefijo . $digitos;
    }

    private static function normalizarWhatsapp(string $numero): string
    {
        return preg_replace('/\D+/', '', $numero) ?? '';
    }
}
