<?php

/**
 * Expone constantes y datos de contacto del modulo de reservaciones.
 * Mantiene valores compartidos fuera de controladores y modelos.
 */

namespace Services;

use DateTimeZone;

class ReservacionConfig
{
    /** Valores compartidos por backend y expuestos al frontend solo cuando aplica. */
    public const OTP_EXPIRATION_MINUTES = 5;
    public const OTP_MAX_ATTEMPTS = 5;
    public const OTP_RESEND_SECONDS = 60;
    public const CLIENT_SESSION_MINUTES = 30;
    public const RESERVATION_HOLD_MINUTES = 5;
    public const MAX_ACTIVE_RESERVATIONS = 5;
    public const MAX_PUBLIC_GUESTS = 12;
    public const MAX_PUBLIC_TABLES = 3;
    public const NOMBRE_MAX_CARACTERES = 100;
    public const EMAIL_MAX_CARACTERES = 150;
    public const MAX_COMENSALES_PUBLICO = self::MAX_PUBLIC_GUESTS;
    public const MAX_COMENSALES_ADMIN = 44;
    public const NOTA_MAX_CARACTERES = 500;
    public const COMENTARIO_ADMIN_MAX_CARACTERES = 5000;
    public const MINUTOS_PREVIOS_BLOQUEO = 30;
    public const INTERVALO_RESERVACION_MINUTOS = 30;
    public const TIMEZONE = 'America/Mexico_City';
    public const TOLERANCIA_RESERVACION_MINUTOS = 15;
    public const DURACION_SERVICIO_ESTIMADA_MINUTOS = 120;
    public const MARGEN_PREPARACION_MESA_MINUTOS = 15;
    public const ESTADO_LABELS = [
        'pendiente' => 'Pendiente',
        'pendiente_verificacion' => 'Esperando verificación',
        'confirmada' => 'Confirmada',
        'llego' => 'Cliente llegó',
        'en_curso' => 'En curso',
        'expirada' => 'Expirada',
        'completada' => 'Completada',
        'cancelada' => 'Cancelada',
        'no_show' => 'No show',
    ];
    public const ESTADOS_EDITABLES = ['pendiente', 'pendiente_verificacion', 'confirmada', 'llego'];
    public const ESTADOS_FINALES = ['expirada', 'completada', 'cancelada', 'no_show'];
    /**
     * `pendiente_verificacion` se añade mediante una condición temporal en las
     * consultas: sólo ocupa mientras verification_expires_at sea futura.
     */
    public const ESTADOS_OCUPAN_MESA = ['pendiente', 'confirmada', 'llego', 'en_curso'];
    public const ESTADOS_CUENTAN_LIMITE = ['confirmada'];
    public const ORDEN_ESTADOS = [
        'pendiente_verificacion',
        'pendiente',
        'confirmada',
        'llego',
        'en_curso',
        'completada',
        'no_show',
        'cancelada',
        'expirada',
    ];
    public const TRANSICIONES = [
        'pendiente' => ['confirmada', 'completada', 'cancelada', 'no_show'],
        'pendiente_verificacion' => ['confirmada', 'expirada'],
        'confirmada' => ['llego', 'en_curso', 'cancelada', 'no_show'],
        'llego' => ['en_curso', 'cancelada', 'no_show'],
        'en_curso' => ['completada'],
        'expirada' => [],
        'completada' => [],
        'cancelada' => [],
        'no_show' => [],
    ];

    // Genera los horarios de reservaciones hasta estos minutos antes
    public const MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION = 60;
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
        return new DateTimeZone(self::env('APP_TIMEZONE', self::TIMEZONE));
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

    public static function estadosPermitidos(): array
    {
        return array_keys(self::ESTADO_LABELS);
    }

    /**
     * La vista previa requiere dos controles del servidor: entorno no
     * productivo y una bandera explícita. Ningún dato del navegador la activa.
     */
    public static function otpPreviewEnabled(): bool
    {
        $entorno = strtolower(self::env('APP_ENV', 'production'));

        return in_array($entorno, ['development', 'testing'], true)
            && self::envBool('CONTACT_OTP_PREVIEW', false);
    }

    public static function otpSendEnabled(): bool
    {
        return self::envBool('CONTACT_OTP_SEND_ENABLED', false);
    }

    public static function appEnvironment(): string
    {
        return strtolower(self::env('APP_ENV', 'production'));
    }

    private static function env(string $key, string $default): string
    {
        $value = $_ENV[$key] ?? getenv($key);

        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        return trim($value);
    }

    private static function envBool(string $key, bool $default): bool
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        $normalizado = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $normalizado ?? $default;
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
