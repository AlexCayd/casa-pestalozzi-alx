<?php

namespace Services;

/** Orquesta preparación post-commit y aceptación del transporte externo. */
final class ReservationNotificationDispatcher
{
    public const EVENT_SCHEDULE_CHANGE = 'reservation.schedule_change';

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
