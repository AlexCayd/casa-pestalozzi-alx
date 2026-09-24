<?php

namespace Services\Reservations;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\VerificacionContacto;
use Services\Contact\ContactoService;

/**
 * Fuente única del límite: tres aceptaciones por ciclo, incluidas simulaciones
 * development. Acceso: 15 min fijos o consumo; reserva: todo el hold.
 * created_at limita solicitudes; accepted_at acredita envíos aceptados.
 * Un pending incierto bloquea hasta terminar el ciclo: nunca se asume fallo
 * por timeout local ni se arriesga un cuarto envío aceptado tras una caída.
 */
final class ConfirmationResendPolicy
{
    public const MAX_ACCEPTED_SENDS = 3;
    public const COOLDOWN_SECONDS = ReservacionConfig::OTP_RESEND_SECONDS;
    public const ACCESS_CYCLE_MINUTES = 15;
    public const PUBLIC_FIELDS = [
        'send_count', 'last_sent_at', 'next_resend_at', 'remaining_resends',
        'retry_after_seconds', 'expires_at', 'can_send', 'notification_delivery_status',
    ];

    /** API de lectura para POST contacto/estado: no crea ciclo, OTP, sesión ni locks. */
    public static function estado(string $tipo, string $contacto, ?int $reservacionId = null): array
    {
        try {
            $tipo = trim($tipo);
            $contacto = ContactoService::normalizar($tipo, $contacto);
        } catch (\InvalidArgumentException) {
            return ['ok' => false, 'codigo' => 'DATOS_INVALIDOS'];
        }
        try {
            $state = self::evaluar($tipo, $contacto, $reservacionId);
            if (isset($state['error'])) {
                return ['ok' => false, 'codigo' => $state['error']];
            }
            return ['ok' => true, 'codigo' => 'OTP_ESTADO'] + self::camposPublicos($state);
        } catch (\Throwable) {
            error_log('ConfirmationResendPolicy::estado - consulta OTP fallida; datos omitidos.');
            return ['ok' => false, 'codigo' => 'ERROR_INTERNO'];
        }
    }

    public static function camposPublicos(array $state): array
    {
        return array_intersect_key($state, array_flip(self::PUBLIC_FIELDS));
    }

    /**
     * Interno: para decidir una emisión, llamador mantiene ContactoOperacionLock
     * desde ANTES de begin_transaction hasta commit/rollback. No adquiere otro.
     * Las lecturas del endpoint no necesitan FOR UPDATE.
     */
    public static function evaluar(string $tipo, string $contacto, ?int $reservacionId): array
    {
        $now = ReservacionConfig::ahora();
        $cycleEnd = null;
        if ($reservacionId !== null) {
            if ($reservacionId < 1) {
                return ['error' => 'DATOS_INVALIDOS'];
            }
            $stmt = ActiveRecord::getDB()->prepare(
                'SELECT contacto_tipo, contacto, estado, hold_expires_at FROM reservaciones WHERE id = ?'
            );
            $stmt->bind_param('i', $reservacionId);
            $stmt->execute();
            $reservation = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$reservation || !hash_equals((string)$reservation['contacto_tipo'], $tipo)
                || !hash_equals((string)$reservation['contacto'], $contacto)) {
                return ['error' => 'RESERVACION_NO_EXISTE'];
            }
            if ($reservation['estado'] !== 'pendiente_verificacion' || !$reservation['hold_expires_at']) {
                return ['error' => 'RETENCION_EXPIRADA'];
            }
            $cycleEnd = self::date($reservation['hold_expires_at']);
            if ($cycleEnd <= $now) {
                return ['error' => 'RETENCION_EXPIRADA'];
            }
        }

        $rows = VerificacionContacto::historialEnvios($tipo, $contacto, $reservacionId);
        $latest = $rows[0] ?? null;
        if ($reservacionId === null && $latest) {
            $cycleEnd = !empty($latest['cycle_expires_at'])
                ? self::date($latest['cycle_expires_at'])
                : self::date($latest['created_at'])->modify('+' . self::ACCESS_CYCLE_MINUTES . ' minutes');
            if ($latest['used_at'] !== null || $cycleEnd <= $now) {
                $latest = null;
                $rows = [];
                $cycleEnd = null;
            }
        }
        $cycleEnd ??= $now->modify('+' . self::ACCESS_CYCLE_MINUTES . ' minutes');
        // Reserva cuenta TODAS sus filas, incluso ante un cycle_id histórico distinto.
        $cycleRows = $reservacionId !== null ? $rows : array_values(array_filter(
            $rows, static fn(array $row): bool => $row['cycle_id'] === ($latest['cycle_id'] ?? null)
        ));
        $count = count(array_filter($cycleRows, static fn(array $row): bool => $row['accepted_at'] !== null));
        $pending = count(array_filter($cycleRows, static fn(array $row): bool => $row['delivery_status'] === 'pending')) > 0;
        $legacy = count(array_filter($cycleRows, static fn(array $row): bool => $row['delivery_status'] === 'legacy')) > 0;
        $lastAttempt = $latest ? self::date($latest['created_at']) : null;
        $next = $lastAttempt ? $lastAttempt->modify('+' . self::COOLDOWN_SECONDS . ' seconds') : null;
        // Cooldown también cruza el consumo del ciclo anterior.
        if (!$lastAttempt && $reservacionId === null) {
            $previous = VerificacionContacto::historialEnvios($tipo, $contacto, null)[0] ?? null;
            if ($previous) {
                $lastAttempt = self::date($previous['created_at']);
                $next = $lastAttempt->modify('+' . self::COOLDOWN_SECONDS . ' seconds');
            }
        }
        $retry = $next ? max(0, $next->getTimestamp() - $now->getTimestamp()) : 0;
        $lastAccepted = null;
        foreach ($cycleRows as $row) {
            if ($row['accepted_at'] !== null) { $lastAccepted = self::date($row['accepted_at']); break; }
        }
        $reason = $count >= self::MAX_ACCEPTED_SENDS ? 'LIMITE_REENVIOS_ALCANZADO'
            : ($retry > 0 ? 'REENVIO_EN_COOLDOWN' : (($pending || $legacy) ? 'REENVIO_NO_DISPONIBLE' : null));
        return [
            'cycle_id' => $latest['cycle_id'] ?? null,
            'cycle_expires_at' => $cycleEnd->format('Y-m-d H:i:s'),
            'blocked_code' => $reason,
            'send_count' => $count,
            'last_sent_at' => $lastAccepted?->format(DATE_ATOM),
            'next_resend_at' => $count >= self::MAX_ACCEPTED_SENDS || $pending || $legacy
                || ($next && $next >= $cycleEnd) ? null : $next?->format(DATE_ATOM),
            'remaining_resends' => max(0, self::MAX_ACCEPTED_SENDS - $count),
            'retry_after_seconds' => $retry,
            'expires_at' => $latest ? self::date($latest['expires_at'])->format(DATE_ATOM) : null,
            'can_send' => $reason === null,
            'notification_delivery_status' => $latest['delivery_status'] ?? null,
        ];
    }

    private static function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, ReservacionConfig::timezone());
    }
}
