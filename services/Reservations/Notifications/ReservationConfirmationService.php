<?php

namespace Services\Reservations\Notifications;

use DateTimeImmutable;
use Model\ActiveRecord;
use Model\VerificacionContacto;
use Services\Integrations\N8nClient;
use Services\Notifications\NotificationConfig;

/** Prepara y despacha códigos de confirmación únicamente después del commit. */
final class ReservationConfirmationService
{
    public const EVENT = ReservationNotificationContract::EVENT_CONFIRMATION;
    public const WEBHOOK_PATH = '/webhook/reservaciones/confirmacion';
    private const PRIVATE_PAYLOAD = '_notification_payload';
    private const PRIVATE_CODE = '_confirmation_code';

    /** @return array<string,mixed> */
    public static function prepare(
        int $verificationId,
        ?int $reservationId,
        string $contactType,
        string $contactValue,
        string $code,
        DateTimeImmutable $expiresAt
    ): array {
        $reservation = $reservationId !== null ? self::reservation($reservationId) : null;

        return [
            self::PRIVATE_PAYLOAD => ReservationNotificationContract::build(
                self::EVENT,
                $verificationId,
                $reservationId,
                1,
                $contactType,
                $contactValue,
                (string)($reservation['nombre'] ?? 'Cliente'),
                $reservation['fecha'] ?? null,
                $reservation['hora'] ?? null,
                isset($reservation['comensales']) ? (int)$reservation['comensales'] : null,
                [
                    'purpose' => $reservationId === null ? 'contact_access' : 'reservation_confirmation',
                    'confirmation_code' => $code,
                    'expires_at' => $expiresAt->format(DATE_ATOM),
                ]
            ),
            self::PRIVATE_CODE => $code,
        ];
    }

    /**
     * Elimina siempre los campos privados. Sólo development recibe el código;
     * los demás entornos ejecutan n8n y reciben metadatos redactados.
     *
     * @return array<string,mixed>
     */
    public static function finalize(
        array $response,
        ?N8nClient $client = null
    ): array {
        $payload = $response[self::PRIVATE_PAYLOAD] ?? null;
        $code = (string)($response[self::PRIVATE_CODE] ?? '');
        unset($response[self::PRIVATE_PAYLOAD], $response[self::PRIVATE_CODE]);

        if (!($response['ok'] ?? false) || !is_array($payload)) {
            return $response;
        }

        try {
            if (NotificationConfig::showConfirmationCode()) {
                if (preg_match('/^\d{6}$/', $code) === 1) {
                    $response['development_confirmation_code'] = $code;
                }
                $response['notification_delivery_status'] = 'pending';
                $response['external_transport'] = false;
                return self::recordResult($response, $payload, true);
            }

            $delivery = ($client ?? new N8nClient(NotificationConfig::n8nBaseUrl(), NotificationConfig::n8nSecret()))
                ->post(self::WEBHOOK_PATH, $payload, 200, 25);
            if (($delivery['channel'] ?? null) !== ($payload['contact']['type'] ?? null)) {
                $delivery['accepted'] = false;
            }
        } catch (\Throwable) {
            error_log('ReservationConfirmationService::finalize - configuración inválida redactada.');
            $delivery = [
                'ok' => false,
                'accepted' => false,
                'codigo' => 'NOTIFICACION_CONFIGURACION_INVALIDA',
            ];
        }

        $response['notification_delivery_status'] = ($delivery['accepted'] ?? false)
            ? 'accepted'
            : 'failed';
        $response['external_transport'] = true;
        $response['notification_result'] = [
            'codigo' => (string)($delivery['codigo'] ?? 'NOTIFICACION_NO_ACEPTADA'),
            'http_status' => isset($delivery['http_status']) ? (int)$delivery['http_status'] : null,
        ];
        if (!($delivery['accepted'] ?? false)) {
            $response['ok'] = false;
            $response['codigo'] = 'OTP_ENVIO_FALLIDO';
        } else {
            $response['codigo'] = 'CODIGO_CONFIRMACION_ENVIADO';
            $response['channel'] = $delivery['channel'];
            $response['contexto']['canal'] = $delivery['channel'] === 'whatsapp' ? 'WhatsApp' : 'correo electrónico';
        }

        return self::recordResult($response, $payload, ($delivery['accepted'] ?? false) === true);
    }

    private static function recordResult(array $response, array $payload, bool $accepted): array
    {
        // Tests puros de transporte no construyen un desafío persistido.
        // Todo payload creado por prepare contiene un source_id válido.
        if (!isset($payload['source_id'])) return $response;
        try {
            VerificacionContacto::finalizarEnvio((int)$payload['source_id'], $accepted);
            $state = ConfirmationResendPolicy::estado(
                $payload['contact']['type'] === 'whatsapp' ? 'telefono' : 'email',
                $payload['contact']['value'], $payload['reservation_id']
            );
            return array_replace($response, ConfirmationResendPolicy::camposPublicos($state));
        } catch (\Throwable) {
            // Si el proveedor aceptó pero la DB falló, conservar pending y bloquear
            // reenvíos: no inventar un rechazo ni un cupo adicional.
            error_log('ReservationConfirmationService::recordResult - persistencia pendiente; datos omitidos.');
            $response['can_send'] = false;
            $response['next_resend_at'] = null;
            return $response;
        }
    }

    /** @return array<string,mixed>|null */
    private static function reservation(int $reservationId): ?array
    {
        $stmt = ActiveRecord::getDB()->prepare(
            'SELECT nombre, fecha, hora, comensales FROM reservaciones WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            throw new \RuntimeException('No fue posible preparar la reservación de confirmación.');
        }
        $stmt->bind_param('i', $reservationId);
        $stmt->execute();
        $reservation = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        if (!$reservation) {
            throw new \RuntimeException('La reservación de confirmación no existe.');
        }
        $reservation['hora'] = substr((string)$reservation['hora'], 0, 5);

        return $reservation;
    }
}
