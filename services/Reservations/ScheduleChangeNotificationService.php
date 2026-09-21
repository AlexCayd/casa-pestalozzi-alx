<?php

namespace Services\Reservations;

use Services\Integrations\N8nClient;
use Services\Notifications\NotificationConfig;
use Services\Reservations\HorarioOperacionImpactoService;

/** Orquesta los intentos post-commit de avisos por cambio de horario. */
final class ScheduleChangeNotificationService
{
    public const AUTOMATIC_ATTEMPT = HorarioOperacionImpactoService::AUTOMATIC_ATTEMPT;
    public const MANUAL_ATTEMPT = HorarioOperacionImpactoService::MANUAL_ATTEMPT;
    public const MAX_ATTEMPTS = self::MANUAL_ATTEMPT;
    public const WEBHOOK_PATH = '/webhook/reservaciones/cambio-horario';

    /** Guarda el contacto y notifica sólo después del commit del dominio. */
    public static function addContact(int $impactId, int $itemId, string $type, string $contact, ?int $adminId): array
    {
        $result = HorarioOperacionImpactoService::agregarContacto($impactId, $itemId, $type, $contact, $adminId);
        if ($result['ok'] ?? false) {
            $result['notification_dispatch'] = self::dispatchItem($itemId, false);
        }
        return $result;
    }

    /** @return array{attempted:int,accepted:int,failed:int,prepared:int} */
    public static function dispatchAutomatic(
        int $impactId,
        ?N8nClient $client = null
    ): array {
        $result = ['attempted' => 0, 'accepted' => 0, 'failed' => 0, 'prepared' => 0];
        foreach (HorarioOperacionImpactoService::itemsPendientesNotificables($impactId) as $itemId) {
            $item = self::dispatchItem($itemId, false, $client);
            $result['attempted']++;
            if (($item['prepared'] ?? false) === true) {
                $result['prepared']++;
            }
            if (($item['accepted'] ?? false) === true) {
                $result['accepted']++;
            } elseif (($item['external_transport'] ?? false) === true) {
                $result['failed']++;
            }
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public static function resendManually(
        int $impactId,
        int $impactReservationId,
        ?N8nClient $client = null
    ): array {
        $item = HorarioOperacionImpactoService::obtenerPorItem($impactReservationId);
        if (!$item || (int)($item['impacto_id'] ?? 0) !== $impactId) {
            return ['ok' => false, 'accepted' => false, 'codigo' => 'AFECTACION_NO_ENCONTRADA'];
        }

        return self::dispatchItem($impactReservationId, true, $client);
    }

    /** @return array<string,mixed> */
    public static function dispatchItem(
        int $impactReservationId,
        bool $manual,
        ?N8nClient $client = null
    ): array {
        $preparation = HorarioOperacionImpactoService::prepararAvisoParaEntrega(
            $impactReservationId,
            $manual
        );
        if (!($preparation['ok'] ?? false) || !is_array($preparation['notification'] ?? null)) {
            return [
                'ok' => false,
                'accepted' => false,
                'prepared' => false,
                'codigo' => (string)($preparation['codigo'] ?? 'AFECTACION_NO_NOTIFICABLE'),
            ];
        }

        $notification = $preparation['notification'];
        $attempt = (int)($notification['attempt'] ?? 0);
        try {
            if (!NotificationConfig::usesExternalTransport()) {
                return [
                    'ok' => true,
                    'accepted' => false,
                    'prepared' => true,
                    'external_transport' => false,
                    'codigo' => 'NOTIFICACION_PREPARADA_DESARROLLO',
                    'source_id' => $impactReservationId,
                    'attempt' => $attempt,
                ];
            }
            $delivery = ($client ?? new N8nClient(NotificationConfig::n8nBaseUrl(), NotificationConfig::n8nSecret()))->post(self::WEBHOOK_PATH, $notification);
        } catch (\Throwable) {
            error_log('ScheduleChangeNotificationService::dispatchItem - configuración inválida redactada.');
            $delivery = [
                'ok' => false,
                'accepted' => false,
                'codigo' => 'NOTIFICACION_CONFIGURACION_INVALIDA',
            ];
        }

        if (($delivery['accepted'] ?? false) === true) {
            $persisted = HorarioOperacionImpactoService::marcarTrabajoEncolado(
                $impactReservationId,
                $attempt
            );
            return array_merge($delivery, [
                'ok' => $persisted,
                'accepted' => $persisted,
                'accepted_by' => 'n8n',
                'prepared' => true,
                'external_transport' => true,
                'source_id' => $impactReservationId,
                'attempt' => $attempt,
            ]);
        }

        HorarioOperacionImpactoService::marcarEntregaFallida($impactReservationId, $attempt);
        return array_merge($delivery, [
            'ok' => false,
            'accepted' => false,
            'prepared' => true,
            'external_transport' => true,
            'source_id' => $impactReservationId,
            'attempt' => $attempt,
        ]);
    }
}
