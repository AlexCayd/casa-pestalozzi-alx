<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Services\Reservations\Notifications\ReservationNotificationContract as Contract;
$_ENV['APP_ENV'] = 'test';
function boundaryBuild(string $event, string $channel, array $data): array {
    return Contract::build($event, 1, 2, 1, $channel,
        $channel === 'email' ? 'fixture@example.test' : '+525500000001',
        'Fixture', '2037-01-15', '18:00', 2, $data);
}
foreach (Contract::EVENTS as $event) {
    foreach (Contract::CHANNELS as $channel) {
        $data = $event === Contract::EVENT_CONFIRMATION
            ? ['purpose' => 'reservation_confirmation', 'confirmation_code' => '123456', 'expires_at' => '2037-01-15T18:00:00-06:00']
            : ['management_url' => 'https://example.test/gestionar', 'access_expires_at' => '2037-01-15T18:00:00-06:00'];
        $payload = boundaryBuild($event, $channel, $data);
        if ($payload['data'] !== $data || $payload['contact']['type'] !== $channel) throw new RuntimeException('Contrato funcional alterado.');
        foreach (['bodyParameters', 'component.parameter', 'components', 'smtp_password'] as $field) {
            try {
                boundaryBuild($event, $channel, $data + [$field => 'fixture']);
                throw new RuntimeException('Detalle de proveedor permitido.');
            } catch (InvalidArgumentException) { /* rechazo esperado */ }
        }
    }
}
echo "Contrato: seis combinaciones funcionales conservadas; detalles de proveedor rechazados.\n";
