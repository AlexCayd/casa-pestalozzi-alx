<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);

require_once __DIR__ . '/notifications-test-bootstrap.php';
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Contact\ContactoProtegidoService;
use Services\Reservations\DisponibilidadReservacionService;
use Services\Reservations\ReservacionConfig;

function retentionHttpAssert(bool $condition, string $phase): void
{
    if (!$condition) throw new RuntimeException('HTTP retención aislada falló en fase ' . $phase . '.');
}

/** @return array{status:int,body:string} */
function retentionHttpRequest(string $url, string $method, ?array $payload, array &$cookies): array
{
    $encodedPayload = $payload === null ? '' : (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
    $headers = ['Accept: application/json, text/html;q=0.9'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($encodedPayload);
    }
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) $pairs[] = $name . '=' . $value;
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'content' => $encodedPayload,
        'ignore_errors' => true,
        'timeout' => 15,
        'follow_location' => 0,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) $status = (int)$match[1];
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $header, $match)) $cookies[$match[1]] = $match[2];
    }
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
}

$database = (string)getenv('CP_NOTIFICATION_TEST_DATABASE');
retentionHttpAssert(preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database) === 1, 'bootstrap');
$db = ActiveRecord::getDB();
retentionHttpAssert((string)$db->query('SELECT DATABASE()')->fetch_row()[0] === $database, 'database-namespace');

$sessionDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cp-retention-http-session-' . bin2hex(random_bytes(8));
$logPath = tempnam(sys_get_temp_dir(), 'cp-retention-http-log-');
$server = null;
$pipes = [];
$reservationId = 0;
$verificationId = 0;
$phase = 'setup';
$failed = false;
$ownedSessionDir = false;
$portSocket = @stream_socket_server('tcp://127.0.0.1:0', $socketErrno, $socketError);
try {
    retentionHttpAssert(is_resource($portSocket), 'port');
    $portAddress = stream_socket_get_name($portSocket, false);
    retentionHttpAssert(is_string($portAddress) && preg_match('/:(\d+)$/', $portAddress, $portMatch) === 1, 'port');
    $port = (int)$portMatch[1];
    fclose($portSocket);
    $portSocket = null;
    retentionHttpAssert(mkdir($sessionDir, 0700), 'session-dir');
    $ownedSessionDir = true;

    $enc = rtrim((string)getenv('CP_TEST_CONTACT_ENCRYPTION_KEY'), '=');
    $lookup = rtrim((string)getenv('CP_TEST_CONTACT_LOOKUP_KEY'), '=');
    $base = 'http://127.0.0.1:' . $port;
    $serverEnv = array_merge(getenv(), $_ENV);
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS'] as $name) $serverEnv[$name] = (string)($_ENV[$name] ?? getenv($name) ?: '');
    $serverEnv['CP_NOTIFICATION_TEST_DATABASE'] = $database;
    $serverEnv['CP_TEST_CONTACT_ENCRYPTION_KEY'] = $enc;
    $serverEnv['CP_TEST_CONTACT_LOOKUP_KEY'] = $lookup;
    $serverEnv['CP_NOTIFICATION_TEST_BASE_URL'] = $base;
    $serverEnv['SESSION_SAVE_PATH'] = $sessionDir;
    $serverEnv['APP_ENV'] = 'development';
    unset($serverEnv['RESERVATION_TEST_NOW']);
    $root = dirname(__DIR__, 2);
    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', __DIR__ . '/notifications-http-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'a'], 2 => ['file', $logPath, 'a']],
        $pipes,
        $root,
        $serverEnv
    );
    retentionHttpAssert(is_resource($server), 'server-start');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 60; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $probeErrno, $probeError, 0.1);
        if (is_resource($probe)) { fclose($probe); break; }
        usleep(50000);
    }

    $phase = 'landing-csrf';
    $cookies = [];
    $landing = retentionHttpRequest($base . '/', 'GET', null, $cookies);
    retentionHttpAssert($landing['status'] === 200
        && preg_match('/data-reservation-csrf="([^"]+)"/', $landing['body'], $csrfMatch) === 1
        && $cookies !== [], $phase);
    $csrf = html_entity_decode($csrfMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $phase = 'availability';
    $slot = null;
    $_ENV['APP_ENV'] = 'development';
    putenv('APP_ENV=development');
    unset($_ENV['RESERVATION_TEST_NOW']);
    putenv('RESERVATION_TEST_NOW');
    for ($offset = 1; $offset <= 60 && $slot === null; $offset++) {
        $date = ReservacionConfig::ahora()->setTimezone(new DateTimeZone('America/Mexico_City'))
            ->modify('+' . $offset . ' days')->format('Y-m-d');
        $availability = DisponibilidadReservacionService::consultar($date, 2);
        foreach ((array)($availability['horarios'] ?? []) as $candidate) {
            if (($candidate['disponible'] ?? false) === true && (string)($candidate['hora'] ?? '') !== '') {
                $slot = ['fecha' => $date, 'hora' => (string)$candidate['hora']];
                break;
            }
        }
    }
    retentionHttpAssert(is_array($slot), $phase);

    $phase = 'retention-post';
    $contact = 'http.retention.' . bin2hex(random_bytes(5)) . '@example.test';
    $token = bin2hex(random_bytes(32));
    $retention = retentionHttpRequest($base . '/api/reservaciones/retencion', 'POST', [
        'csrf_token' => $csrf,
        'nombre' => 'Fixture HTTP de retención',
        'tipo_contacto' => 'email',
        'contacto' => $contact,
        'fecha' => $slot['fecha'],
        'hora' => $slot['hora'],
        'personas' => 2,
        'notas' => 'Prueba HTTP aislada',
        'request_token' => $token,
    ], $cookies);
    $retentionJson = json_decode($retention['body'], true);
    retentionHttpAssert($retention['status'] === 201 && is_array($retentionJson)
        && ($retentionJson['ok'] ?? false) === true
        && ($retentionJson['codigo'] ?? '') === 'RETENCION_CREADA'
        && preg_match('/^\d{6}$/', (string)($retentionJson['development_confirmation_code'] ?? '')) === 1
        && ($retentionJson['external_transport'] ?? true) === false, $phase);
    $otpCode = (string)$retentionJson['development_confirmation_code'];

    $phase = 'persisted-hold';
    $stmt = $db->prepare('SELECT id, estado, contacto, contacto_hash, hold_expires_at FROM reservaciones WHERE request_token = ?');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    retentionHttpAssert(is_array($row) && $row['estado'] === 'pendiente_verificacion'
        && str_starts_with((string)$row['contacto'], 'cp1:')
        && hash_equals(ContactoProtegidoService::identificador('email', $contact), (string)$row['contacto_hash'])
        && is_string($row['hold_expires_at']) && $row['hold_expires_at'] !== '', $phase);
    $reservationId = (int)$row['id'];
    $reservationCount = (int)$db->query('SELECT COUNT(*) FROM reservacion_mesas WHERE reservacion_id = ' . $reservationId)->fetch_row()[0];
    retentionHttpAssert($reservationCount > 0, $phase);
    $stmt = $db->prepare('SELECT id, contacto, contacto_hash, codigo_hash FROM verificaciones_contacto WHERE reservacion_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $reservationId);
    $stmt->execute();
    $otpRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    retentionHttpAssert(is_array($otpRow) && str_starts_with((string)$otpRow['contacto'], 'cp1:')
        && hash_equals(ContactoProtegidoService::identificador('email', $contact), (string)$otpRow['contacto_hash'])
        && password_verify($otpCode, (string)$otpRow['codigo_hash']), $phase);
    $verificationId = (int)$otpRow['id'];

    $phase = 'otp-post';
    $verification = retentionHttpRequest($base . '/api/reservaciones/contacto/verificar', 'POST', [
        'csrf_token' => $csrf,
        'tipo' => 'email',
        'contacto' => $contact,
        'codigo' => $otpCode,
        'request_token' => $token,
    ], $cookies);
    $verificationJson = json_decode($verification['body'], true);
    retentionHttpAssert($verification['status'] === 200 && is_array($verificationJson)
        && ($verificationJson['ok'] ?? false) === true, $phase);
    $state = $db->query('SELECT estado FROM reservaciones WHERE id = ' . $reservationId)->fetch_row()[0];
    retentionHttpAssert($state === 'confirmada', 'confirmed-state');
    echo "Retención HTTP aislada: CSRF, hold cifrado, OTP y confirmación PASS.\n";
} catch (Throwable) {
    fwrite(STDERR, 'Suite HTTP de retención detenida en fase ' . $phase . "; sin cuerpos ni datos sensibles.\n");
    $failed = true;
} finally {
    if (is_resource($portSocket ?? null)) fclose($portSocket);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if ($reservationId > 0) {
        $db->query('DELETE FROM buzon_notificaciones WHERE entidad_tipo = \'reservacion\' AND entidad_id = ' . $reservationId);
        $db->query('DELETE FROM reservacion_recordatorios WHERE reservacion_id = ' . $reservationId);
        $db->query('DELETE FROM verificaciones_contacto WHERE reservacion_id = ' . $reservationId);
        $db->query('DELETE FROM reservacion_mesas WHERE reservacion_id = ' . $reservationId);
        $db->query('DELETE FROM reservaciones WHERE id = ' . $reservationId);
    }
    if (is_file($logPath)) unlink($logPath);
    if ($ownedSessionDir && is_dir($sessionDir)) {
        foreach (glob($sessionDir . DIRECTORY_SEPARATOR . '*') ?: [] as $sessionFile) if (is_file($sessionFile)) unlink($sessionFile);
        rmdir($sessionDir);
    }
}
exit(isset($failed) && $failed ? 1 : 0);
