<?php

namespace Services\Reservations;

use Services\ContactoService;
use Services\Notifications\NotificationConfig;

/** Construye el contrato canónico y normalizado que PHP entrega a n8n. */
final class ReservationNotificationContract
{
    public const SCHEMA_VERSION = 1;
    public const EVENT_CONFIRMATION = 'reservation.confirmation';
    public const EVENT_REMINDER = 'reservation.reminder';
    public const EVENT_SCHEDULE_CHANGE = 'reservation.schedule_change';
    public const CHANNEL_EMAIL = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const EVENTS = [
        self::EVENT_CONFIRMATION,
        self::EVENT_REMINDER,
        self::EVENT_SCHEDULE_CHANGE,
    ];

    public const CHANNELS = [
        self::CHANNEL_EMAIL,
        self::CHANNEL_WHATSAPP,
    ];

    /** @return array<string,mixed> */
    public static function build(
        string $event,
        int $sourceId,
        ?int $reservationId,
        int $attempt,
        string $contactType,
        string $contactValue,
        string $recipientName,
        ?string $date,
        ?string $time,
        ?int $guests,
        array $data = []
    ): array {
        if (!in_array($event, self::EVENTS, true) || $sourceId < 1 || $attempt < 1) {
            throw new \InvalidArgumentException('Evento, fuente o intento de notificación inválido.');
        }
        // El dominio entrega intención y datos, nunca parámetros internos de Meta/SMTP.
        $allowedData = $event === self::EVENT_CONFIRMATION
            ? ['purpose', 'confirmation_code', 'expires_at']
            : ['management_url', 'access_expires_at'];
        if (array_diff(array_keys($data), $allowedData) !== []) {
            throw new \InvalidArgumentException('Datos ajenos al contrato funcional de notificación.');
        }
        foreach ($data as $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException('Formato de datos funcionales inválido.');
            }
        }
        if ($reservationId !== null && $reservationId < 1) {
            throw new \InvalidArgumentException('Reservación de notificación inválida.');
        }
        if ($event === self::EVENT_SCHEDULE_CHANGE) {
            if (!in_array($attempt, [1, 2], true)) {
                throw new \InvalidArgumentException('Intento de cambio de horario inválido.');
            }
        } elseif ($event === self::EVENT_REMINDER) {
            if ($attempt > ReservationReminderService::MAX_ATTEMPTS) {
                throw new \InvalidArgumentException('Intento de recordatorio inválido.');
            }
        } elseif ($attempt !== 1) {
            throw new \InvalidArgumentException('El evento sólo admite el primer intento.');
        }
        if ($reservationId === null
            && ($event !== self::EVENT_CONFIRMATION || ($data['purpose'] ?? '') !== 'contact_access')
        ) {
            throw new \InvalidArgumentException('Sólo el acceso de contacto admite reservación nula.');
        }

        $legacyType = self::legacyContactType($contactType);
        $normalized = ContactoService::normalizar($legacyType, $contactValue);
        $channel = $legacyType === ContactoService::TIPO_EMAIL
            ? self::CHANNEL_EMAIL
            : self::CHANNEL_WHATSAPP;
        $recipientName = trim($recipientName);
        if ($recipientName === '') {
            $recipientName = 'Cliente';
        }

        $reservation = null;
        if ($reservationId !== null) {
            $date = trim((string)$date);
            $time = substr(trim((string)$time), 0, 5);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
                || preg_match('/^\d{2}:\d{2}$/', $time) !== 1
                || (int)$guests < 1
            ) {
                throw new \InvalidArgumentException('Datos de reservación inválidos para notificación.');
            }
            $reservation = [
                'date' => $date,
                'time' => $time,
                'guests' => (int)$guests,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event' => $event,
            'source_id' => $sourceId,
            'reservation_id' => $reservationId,
            'attempt' => $attempt,
            'transport' => [
                'whatsapp_mode' => NotificationConfig::whatsappMode(),
            ],
            'contact' => [
                'type' => $channel,
                'value' => $normalized,
            ],
            'recipient' => [
                'name' => $recipientName,
            ],
            'reservation' => $reservation,
            'data' => $data,
        ];
    }

    public static function channelForLegacyType(string $contactType): string
    {
        return self::legacyContactType($contactType) === ContactoService::TIPO_EMAIL
            ? self::CHANNEL_EMAIL
            : self::CHANNEL_WHATSAPP;
    }

    private static function legacyContactType(string $contactType): string
    {
        $contactType = strtolower(trim($contactType));
        return match ($contactType) {
            ContactoService::TIPO_EMAIL => ContactoService::TIPO_EMAIL,
            ContactoService::TIPO_TELEFONO, self::CHANNEL_WHATSAPP => ContactoService::TIPO_TELEFONO,
            default => throw new \InvalidArgumentException('Canal de notificación inválido.'),
        };
    }
}
