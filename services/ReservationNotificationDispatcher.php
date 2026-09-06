<?php

namespace Services;

/** Orquesta preparación post-commit y aceptación del transporte externo. */
final class ReservationNotificationDispatcher
{
    public const EVENT_SCHEDULE_CHANGE = 'reservation.schedule_change';

    /** El llamador ya terminó su operación de dominio y liberó sus locks. */
    public static function dispatchConfirmation(
        int $reservacionId,
        ?OperationalNotificationProvider $provider = null
    ): array {
        try {
            $notification = ReservationConfirmationService::preparar($reservacionId);
            if ($notification === null) {
                return ['ok' => true, 'accepted' => false, 'attempted' => false];
            }
            $sourceId = (int)$notification['source_id'];
            try {
                $provider = $provider ?? OperationalNotificationProviderFactory::crear();
                $envio = $provider->sendReservationsEvent(ReservationConfirmationService::EVENT, [$notification]);
            } catch (\Throwable $e) {
                $envio = ['ok' => false, 'accepted' => false];
            }
            if (($envio['accepted'] ?? false) === true) {
                // Un callback rápido puede haber finalizado antes de este UPDATE.
                $stmt = \Model\ActiveRecord::getDB()->prepare(
                    "UPDATE reservacion_recordatorios
                     SET notification_delivery_status = 'accepted', notification_delivery_updated_at = NOW()
                     WHERE id = ? AND tipo = 'confirmacion' AND notification_delivery_status = 'pending'"
                );
                $stmt->bind_param('i', $sourceId);
                $stmt->execute();
                $stmt->close();
            } else {
                ReservationNotificationResultService::registrar(
                    ReservationConfirmationService::EVENT, $sourceId, 1, 'failed'
                );
            }
            return array_merge($envio, ['attempted' => true, 'source_id' => $sourceId, 'attempt' => 1]);
        } catch (\Throwable $e) {
            // La comunicación nunca convierte un commit de dominio exitoso en error.
            error_log('ReservationNotificationDispatcher::dispatchConfirmation - fallo redactado.');
            return ['ok' => false, 'accepted' => false];
        }
    }

    public static function dispatchScheduleChange(
        int $impactoId,
        ?OperationalNotificationProvider $provider = null
    ): array {
        $resultado = ['attempted' => 0, 'accepted' => 0, 'failed' => 0];
        foreach (HorarioOperacionImpactoService::itemsPendientesNotificables($impactoId) as $itemId) {
            $item = self::dispatchScheduleChangeItem($itemId, $provider);
            $resultado['attempted']++;
            if (($item['accepted'] ?? false) === true) {
                $resultado['accepted']++;
            } else {
                $resultado['failed']++;
            }
        }
        return $resultado;
    }

    public static function dispatchScheduleChangeItem(
        int $impactoReservacionId,
        ?OperationalNotificationProvider $provider = null
    ): array {
        $preparacion = HorarioOperacionImpactoService::prepararAvisoParaEntrega($impactoReservacionId);
        if (!($preparacion['ok'] ?? false) || !is_array($preparacion['notification'] ?? null)) {
            return [
                'ok' => false,
                'accepted' => false,
                'codigo' => (string)($preparacion['codigo'] ?? 'AFECTACION_NO_NOTIFICABLE'),
            ];
        }
        $notification = $preparacion['notification'];
        $attempt = (int)($notification['attempt'] ?? 0);
        try {
            $provider = $provider ?? OperationalNotificationProviderFactory::crear();
            $envio = $provider->sendReservationsEvent(self::EVENT_SCHEDULE_CHANGE, [$notification]);
        } catch (\Throwable $e) {
            error_log('ReservationNotificationDispatcher::dispatchScheduleChangeItem - provider inválido.');
            $envio = ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_CONFIGURACION_INVALIDA'];
        }
        if (($envio['accepted'] ?? false) === true) {
            $persistida = HorarioOperacionImpactoService::marcarEntregaAceptada($impactoReservacionId, $attempt);
            return array_merge($envio, [
                'ok' => $persistida,
                'accepted' => $persistida,
                'source_id' => $impactoReservacionId,
                'attempt' => $attempt,
            ]);
        }
        HorarioOperacionImpactoService::marcarEntregaFallida($impactoReservacionId, $attempt);
        return array_merge($envio, [
            'ok' => false,
            'accepted' => false,
            'source_id' => $impactoReservacionId,
            'attempt' => $attempt,
        ]);
    }
}
