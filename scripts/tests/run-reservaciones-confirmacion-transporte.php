<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Integrations\N8nClient;
use Services\Reservations\ReservationConfirmationService;

$_ENV['APP_ENV'] = 'test';
foreach (['email', 'whatsapp'] as $channel) {
    foreach (['accepted', 'failed', 'timeout', '4xx', '5xx', 'invalid_json', 'early_202', 'wrong_channel'] as $case) {
        $client = new N8nClient('http://n8n.invalid', 'fixture-secret', static function () use ($case, $channel): array {
            if ($case === 'timeout') throw new RuntimeException('fixture timeout');
            return [
                'status' => match ($case) { '4xx' => 403, '5xx', 'failed' => 502, 'early_202' => 202, default => 200 },
                'body' => $case === 'invalid_json' ? 'invalid' : json_encode([
                    'ok' => $case !== 'failed', 'accepted' => $case !== 'failed',
                    'channel' => $case === 'wrong_channel' ? 'other' : $channel,
                ]),
            ];
        });
        $result = ReservationConfirmationService::finalize([
            'ok' => true, 'request_token' => 'fixture-retention',
            '_confirmation_code' => '123456',
            '_notification_payload' => ['contact' => ['type' => $channel]],
        ], $client);
        if (($result['ok'] ?? false) !== ($case === 'accepted')
            || ($result['request_token'] ?? '') !== 'fixture-retention'
            || str_contains(json_encode($result), '123456')
            || isset($result['_notification_payload'])
        ) throw new RuntimeException("Confirmación {$channel} {$case}");
    }
}
echo "Confirmación síncrona: Email/WhatsApp, fallos, timeout, HTTP/JSON y secreto confinados OK\n";
