<?php

namespace Services;

final class OperationalNotificationProviderFactory
{
    public static function crear(): OperationalNotificationProvider
    {
        $valor = $_ENV['RESERVATION_NOTIFICATION_PROVIDER']
            ?? getenv('RESERVATION_NOTIFICATION_PROVIDER')
            ?: 'development';
        return match (strtolower(trim((string)$valor))) {
            'development' => new DevelopmentOperationalNotificationProvider(),
            'n8n' => new N8nOperationalNotificationProvider(),
            default => throw new \RuntimeException('RESERVATION_NOTIFICATION_PROVIDER no es válido.'),
        };
    }
}
