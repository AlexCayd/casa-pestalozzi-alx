<?php

namespace Services;

/** En development/testing sólo se registra la intención; nunca sale de la app. */
final class DevelopmentOperationalNotificationProvider implements OperationalNotificationProvider
{
    public function sendScheduleChange(array $payload): array
    {
        return [
            'ok' => true,
            'provider' => 'development',
            'delivered' => false,
        ];
    }
}
