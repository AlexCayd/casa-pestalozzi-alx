<?php

namespace Services;

final class N8nOperationalNotificationProvider implements OperationalNotificationProvider
{
    private N8nNotificationClient $client;

    public function __construct(?N8nNotificationClient $client = null)
    {
        $this->client = $client ?? new N8nNotificationClient();
    }

    public function sendReservationsEvent(string $event, array $notifications): array
    {
        if (!in_array($event, ['reservation.schedule_change', 'reservation.reminder_next_day'], true)
            || $notifications === []
        ) {
            return ['ok' => false, 'accepted' => false, 'codigo' => 'NOTIFICACION_EVENTO_INVALIDO'];
        }
        return $this->client->send([
            'schema_version' => 1,
            'event' => $event,
            'notifications' => array_values($notifications),
        ]);
    }
}
