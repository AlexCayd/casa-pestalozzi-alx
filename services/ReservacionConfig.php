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
    public const CLIENT_SESSION_IDLE_MINUTES = 15;
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
    public const MINUTOS_ADVERTENCIA_RESERVACION_PROXIMA = 60;
    public const MINUTOS_PREVIOS_BLOQUEO = 30;
    public const INTERVALO_RESERVACION_MINUTOS = 30;
    public const TIMEZONE = 'America/Mexico_City';
    public const TOLERANCIA_RESERVACION_MINUTOS = 15;
    public const DURACION_SERVICIO_ESTIMADA_MINUTOS = 90;
    public const MARGEN_PREPARACION_MESA_MINUTOS = 15;
    public const MARGEN_MINIMO_SEGURIDAD_MINUTOS = 30;
    public const REFRESCO_ESTADOS_SEGUNDOS = 60;
    public const ESTADO_RETENCION_PENDIENTE = 'pendiente_verificacion';
    public const ESTADO_LABELS = [
        'pendiente_verificacion' => 'Esperando verificación',
        'confirmada' => 'Confirmada',
        'llego' => 'Cliente llegó',
        'en_curso' => 'En curso',
        'expirada' => 'Expirada',
        'completada' => 'Completada',
        'cancelada' => 'Cancelada',
        'no_show' => 'No show',
    ];
    public const ESTADOS_EDITABLES = ['pendiente_verificacion', 'confirmada', 'llego'];
    public const ESTADOS_FINALES = ['expirada', 'completada', 'cancelada', 'no_show'];
    /**
     * `pendiente_verificacion` se añade mediante una condición temporal en las
     * consultas: sólo ocupa mientras hold_expires_at sea futura.
     */
    public const ESTADOS_OCUPAN_MESA = ['confirmada', 'llego', 'en_curso'];
    public const ESTADOS_LISTA_OPERATIVA = ['confirmada', 'llego', 'en_curso'];
    public const ESTADOS_CUENTAN_LIMITE = ['confirmada'];
    public const ORDEN_ESTADOS = [
        'pendiente_verificacion',
        'confirmada',
        'llego',
        'en_curso',
        'completada',
        'no_show',
        'cancelada',
        'expirada',
    ];
    public const TRANSICIONES = [
        'pendiente_verificacion' => ['confirmada', 'expirada'],
        'confirmada' => ['llego', 'en_curso', 'cancelada', 'no_show'],
        'llego' => ['en_curso', 'cancelada'],
        'en_curso' => ['completada'],
        'expirada' => [],
        'completada' => [],
        'cancelada' => [],
        'no_show' => [],
    ];

    // Genera los horarios de reservaciones hasta estos minutos antes
    public const MINUTOS_ANTES_CIERRE_ULTIMA_RESERVACION = 60;
    public const DURACION_RESERVACION_MINUTOS = 90;
    /**
     * Agrupaciones físicas autorizadas por ID de mesa. No se infieren por
     * capacidad ni por número para evitar unir mesas que no son contiguas.
     */
    public const PAREJAS_MESAS_PUBLICAS_AUTORIZADAS = [
        [2, 4],
        [5, 11],
        [10, 11],
        [8, 9],
    ];
    public const TRIOS_MESAS_PUBLICAS_AUTORIZADOS = [
        [2, 4, 5],
        [8, 9, 10],
    ];
    /** Compatibilidad para consumidores que sólo necesitan listar grupos. */
    public const COMBINACIONES_PUBLICAS_AUTORIZADAS = [
        [2, 4],
        [5, 11],
        [10, 11],
        [8, 9],
        [2, 4, 5],
        [8, 9, 10],
    ];

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::env('APP_TIMEZONE', self::TIMEZONE));
    }

    public static function fechaActual(): string
    {
        return self::ahora()->format('Y-m-d');
    }

    public static function horaActual(): string
    {
        return self::ahora()->format('H:i:s');
    }

    /**
     * Reloj único del módulo. La fecha fija sólo se acepta en testing para que
     * las suites futuras sean reproducibles sin abrir una vía de configuración
     * temporal en desarrollo o producción.
     */
    public static function ahora(): \DateTimeImmutable
    {
        if (self::appEnvironment() === 'testing') {
            $valor = self::env('RESERVATION_TEST_NOW', '');
            if ($valor !== '') {
                $fecha = \DateTimeImmutable::createFromFormat(
                    '!Y-m-d H:i:s',
                    $valor,
                    self::timezone()
                );
                $errores = \DateTimeImmutable::getLastErrors();
                if (
                    $fecha instanceof \DateTimeImmutable
                    && ($errores === false
                        || (($errores['warning_count'] ?? 0) === 0
                            && ($errores['error_count'] ?? 0) === 0))
                    && $fecha->format('Y-m-d H:i:s') === $valor
                ) {
                    return $fecha;
                }
            }
        }

        return new \DateTimeImmutable('now', self::timezone());
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
     * Expone el mismo contrato temporal a los dos mapas. Los consumidores no
     * deben volver a declarar estas ventanas en JavaScript.
     */
    public static function configuracionOperacion(): array
    {
        return [
            'zona_horaria' => self::timezone()->getName(),
            'advertencia_reservacion_minutos' => self::MINUTOS_ADVERTENCIA_RESERVACION_PROXIMA,
            'bloqueo_previo_minutos' => self::MINUTOS_PREVIOS_BLOQUEO,
            'duracion_reservacion_minutos' => self::DURACION_RESERVACION_MINUTOS,
            'tolerancia_llegada_minutos' => self::TOLERANCIA_RESERVACION_MINUTOS,
            'intervalo_reservacion_minutos' => self::INTERVALO_RESERVACION_MINUTOS,
            'duracion_servicio_estimada_minutos' => self::DURACION_SERVICIO_ESTIMADA_MINUTOS,
            'margen_preparacion_mesa_minutos' => self::MARGEN_PREPARACION_MESA_MINUTOS,
            'margen_minimo_seguridad_minutos' => self::MARGEN_MINIMO_SEGURIDAD_MINUTOS,
            'refresco_estados_segundos' => self::REFRESCO_ESTADOS_SEGUNDOS,
            'estados_finales' => self::ESTADOS_FINALES,
            'estados_ocupan_mesa' => self::ESTADOS_OCUPAN_MESA,
            'estado_retencion_pendiente' => self::ESTADO_RETENCION_PENDIENTE,
        ];
    }

    /**
     * Convierte únicamente estados declarados por el dominio a una lista SQL.
     * Evita repetir literales en consultas con alcances distintos.
     */
    public static function estadosSql(array $estados): string
    {
        $permitidos = self::estadosPermitidos();
        $normalizados = array_values(array_unique(array_filter(
            $estados,
            static fn($estado): bool => is_string($estado)
                && in_array($estado, $permitidos, true)
        )));
        if ($normalizados === []) {
            throw new \InvalidArgumentException('La lista SQL de estados no puede quedar vacía.');
        }

        return implode(', ', array_map(
            static fn(string $estado): string => "'" . $estado . "'",
            $normalizados
        ));
    }

    /**
     * Centraliza la condición SQL de una reservación que todavía influye en
     * disponibilidad. El alias se restringe para que no pueda inyectar SQL.
     */
    public static function condicionSqlOcupacionActiva(string $alias = 'r'): string
    {
        return ReservacionVigenciaService::condicionSqlInfluyeDisponibilidad($alias);
    }

    /**
     * Evalúa el caso pendiente fuera de SQL para serializadores y pruebas.
     * Los estados finales nunca recuperan influencia por tener una fecha hold.
     */
    public static function reservacionInfluyeDisponibilidad(
        string $estado,
        ?string $holdExpiresAt = null,
        ?\DateTimeImmutable $ahora = null,
        ?string $fecha = null,
        ?string $hora = null,
        ?string $arrivedAt = null,
        bool $ticketAbierto = false
    ): bool {
        return (bool)ReservacionVigenciaService::clasificar([
            'estado' => $estado,
            'fecha' => $fecha,
            'hora' => $hora,
            'hold_expires_at' => $holdExpiresAt,
            'arrived_at' => $arrivedAt,
            'ticket_abierto' => $ticketAbierto,
        ], $ahora)['influye_disponibilidad'];
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
