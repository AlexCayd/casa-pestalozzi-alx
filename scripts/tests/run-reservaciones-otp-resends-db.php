<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !getenv('CP_NOTIFICATION_TEST_DATABASE')) exit('Usar run-notifications-isolated.php');
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Contact\ContactoAccesoService;
use Services\Integrations\N8nClient;
use Services\Reservations\ConfirmationResendPolicy;

function otpAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$_ENV['APP_ENV'] = 'test';
$_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:00:00';
$db = ActiveRecord::getDB();
$contact = 'otp.policy@example.test';
$codes = [];
$calls = 0;
$accept = true;
$client = new N8nClient('http://n8n.invalid', 'fixture', static function ($url, $secret, $json) use (&$codes, &$calls, &$accept): array {
    $payload = json_decode($json, true);
    $calls++;
    $codes[] = $payload['data']['confirmation_code'];
    // Otra conexión debe ver el OTP antes del transporte: demuestra COMMIT.
    $observer = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
    $id = (int)$payload['source_id'];
    otpAssert((int)$observer->query("SELECT COUNT(*) FROM verificaciones_contacto WHERE id = {$id}")->fetch_row()[0] === 1, 'HTTP dentro de transacción');
    $observer->close();
    if (isset($_SERVER['argv'][1]) && $_SERVER['argv'][1] === '--race') usleep(200000);
    return ['status' => $accept ? 200 : 502, 'body' => json_encode(['ok' => $accept, 'accepted' => $accept, 'channel' => 'email'])];
});

if (($argv[1] ?? '') === '--race') {
    $response = ContactoAccesoService::solicitarCodigo('email', 'otp.concurrent@example.test', $client);
    echo json_encode(['ok' => $response['ok'], 'calls' => $calls, 'codigo' => $response['codigo']]);
    exit;
}
try {
    $first = ContactoAccesoService::solicitarCodigo('email', $contact, $client);
    otpAssert($first['ok'] && $first['send_count'] === 1 && $first['remaining_resends'] === 2 && $first['retry_after_seconds'] === 60, 'primer envío/política');
    otpAssert(!str_contains(json_encode($first), $codes[0]), 'OTP expuesto en test');
    $fast = ContactoAccesoService::solicitarCodigo('email', $contact, $client);
    otpAssert(!$fast['ok'] && $fast['codigo'] === 'REENVIO_EN_COOLDOWN' && $calls === 1, 'cooldown backend');
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:01:01';
    $accept = false;
    $failed = ContactoAccesoService::solicitarCodigo('email', $contact, $client);
    otpAssert(!$failed['ok'] && $failed['send_count'] === 1 && $failed['remaining_resends'] === 2 && $failed['retry_after_seconds'] === 60, 'fallo no consume pero aplica espera');
    otpAssert(ContactoAccesoService::verificarCodigo('email', $contact, $codes[0])['ok'] === false, 'OTP anterior invalidado');
    $accept = true;
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:02:02';
    $second = ContactoAccesoService::solicitarCodigo('email', $contact, $client);
    otpAssert($second['send_count'] === 2 && $second['remaining_resends'] === 1, 'reenvío1');
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:03:03';
    $third = ContactoAccesoService::solicitarCodigo('email', $contact, $client);
    otpAssert($third['send_count'] === 3 && $third['remaining_resends'] === 0, 'reenvío2');
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:04:04';
    $_SESSION = []; // Nueva sesión/navegador no restablece el cupo.
    $blocked = ContactoAccesoService::solicitarCodigo('email', $contact, $client);
    otpAssert($blocked['codigo'] === 'LIMITE_REENVIOS_ALCANZADO' && $calls === 4, 'reenvío3 bloqueado');
    $state = ConfirmationResendPolicy::estado('email', $contact);
    otpAssert($state['send_count'] === 3 && $state['remaining_resends'] === 0, 'estado tras refresh');
    $row = $db->query("SELECT codigo_hash FROM verificaciones_contacto WHERE contacto = 'otp.policy@example.test' ORDER BY id DESC LIMIT 1")->fetch_assoc();
    otpAssert(password_verify(end($codes), $row['codigo_hash']) && $row['codigo_hash'] !== end($codes), 'sólo hash');

    $workers = [];
    for ($i = 0; $i < 2; $i++) {
        $out = tempnam(sys_get_temp_dir(), 'otp-race-');
        $proc = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=' . __DIR__ . '/notifications-test-bootstrap.php', __FILE__, '--race'],
            [0 => ['pipe','r'], 1 => ['file',$out,'w'], 2 => ['file',$out,'a']], $pipes);
        fclose($pipes[0]); $workers[] = [$proc, $out];
    }
    $accepted = 0; $transports = 0;
    foreach ($workers as [$proc, $out]) {
        $exit = proc_close($proc); $data = json_decode(file_get_contents($out), true); unlink($out);
        otpAssert($exit === 0 && is_array($data), 'request concurrente');
        $accepted += (int)$data['ok']; $transports += $data['calls'];
    }
    otpAssert($accepted === 1 && $transports === 1, 'dos requests sólo generan un transporte');
    echo "OTP:3 aceptados,fallo,cooldown,hash,refresh,nueva sesión,concurrencia y post-commit OK\n";
} finally {
    $db->query("DELETE FROM verificaciones_contacto WHERE contacto IN ('otp.policy@example.test','otp.concurrent@example.test')");
}
