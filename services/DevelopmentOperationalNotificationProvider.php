<?php

namespace Services;

/** Provider sin transporte externo para desarrollo y pruebas controladas. */
final class DevelopmentOperationalNotificationProvider implements OperationalNotificationProvider
{
    public function sendReservationsEvent(string $event, array $notifications): array
    {
        if (!in_array($event, ['reservation.schedule_change', 'reservation.reminder_next_day'], true)
            || $notifications === []
        ) {
            return ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_EVENTO_INVALIDO'];
        }
        return [
            'ok' => true,
            'accepted' => true,
            'codigo' => 'NOTIFICACION_ACEPTADA_DESARROLLO',
            'http_status' => 202,
        ];
    }
}
