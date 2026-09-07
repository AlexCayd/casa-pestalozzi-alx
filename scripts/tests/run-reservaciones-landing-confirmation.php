<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Controllers\ReservacionController;
use Model\ActiveRecord;
use MVC\Router;
use Services\ContactoAccesoService;
use Services\ContactNotificationProvider;
use Services\ReservationConfirmationService;
use Services\ReservationClientSession;

function landingConfirmationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insertLandingConfirmationReservation(mysqli $db, string $name, string $contact, string $status): int
{
    $requestToken = bin2hex(random_bytes(32));
    $date = '2037-01-16';
    $time = '13:00:00';
    $guests = 2;
    $note = 'Fixture de regresión landing';
    $origin = 'landing';
    $holdExpiresAt = $status === 'pendiente_verificacion' ? '2037-01-16 12:45:00' : null;
    $changedAt = '2037-01-15 12:00:00';
    $stmt = $db->prepare(
        'INSERT INTO reservaciones
          (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen,
           estado, hold_expires_at, request_token, estado_changed_at)
         VALUES (?, "email", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    landingConfirmationAssert($stmt !== false, 'no se pudo preparar el fixture landing');
    $stmt->bind_param(
        'ssssissssss',
        $name,
        $contact,
        $date,
        $time,
        $guests,
        $note,
        $origin,
        $status,
        $holdExpiresAt,
        $requestToken,
        $changedAt
    );
    landingConfirmationAssert($stmt->execute(), 'no se pudo insertar el fixture landing');
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

$db = ActiveRecord::getDB();
$reservationIds = [];
$oldEnvironment = $_ENV['APP_ENV'] ?? null;
$oldProvider = $_ENV['RESERVATION_NOTIFICATION_PROVIDER'] ?? null;
$oldBaseUrl = $_ENV['RESERVATION_PUBLIC_BASE_URL'] ?? null;
$oldRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$oldContentType = $_SERVER['CONTENT_TYPE'] ?? null;
$oldPost = $_POST;

try {
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['RESERVATION_NOTIFICATION_PROVIDER'] = 'development';
    $_ENV['RESERVATION_PUBLIC_BASE_URL'] = 'http://localhost';

    $contact = 'landing.fixture@example.test';
    $pendingId = insertLandingConfirmationReservation(
        $db,
        'FIXTURE landing OTP',
        $contact,
        'pendiente_verificacion'
    );
    $reservationIds[] = $pendingId;

    $otpProvider = new class implements ContactNotificationProvider {
        public string $code = '';

        public function sendOtp(string $tipo, string $contacto, string $codigo): array
        {
            $this->code = $codigo;
            return ['ok' => true, 'provider' => 'test', 'delivered' => false];
        }
    };
    $db->begin_transaction();
    $otp = ContactoAccesoService::emitirCodigoEnTransaccion('email', $contact, $pendingId, $otpProvider);
    landingConfirmationAssert(($otp['ok'] ?? false) === true, 'no se pudo emitir OTP de fixture');
    landingConfirmationAssert(preg_match('/^\d{6}$/', $otpProvider->code) === 1, 'el fixture no capturó un OTP válido');
    landingConfirmationAssert($db->commit(), 'no se pudo confirmar OTP de fixture');

    $csrf = ReservationClientSession::csrfToken();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_POST = [
        'tipo' => 'email',
        'contacto' => $contact,
        'codigo' => $otpProvider->code,
        'request_token' => (string)$db->query(
            'SELECT request_token FROM reservaciones WHERE id = ' . $pendingId
        )->fetch_assoc()['request_token'],
        'csrf_token' => $csrf,
    ];

    ob_start();
    ReservacionController::verificarContacto(new Router());
    $responseBody = (string)ob_get_clean();
    $response = json_decode($responseBody, true);
    landingConfirmationAssert(is_array($response), 'el endpoint landing no devolvió JSON');
    landingConfirmationAssert(($response['ok'] ?? false) === true, 'el OTP landing no confirmó la reservación');
    landingConfirmationAssert(($response['codigo'] ?? '') === 'RESERVACION_CONFIRMADA', 'el endpoint devolvió un éxito que no confirma reservación');
    $returnedId = (int)($response['reservation']['id'] ?? 0);
    landingConfirmationAssert($returnedId === $pendingId, 'el endpoint no devolvió el id confirmado');

    $domain = $db->query(
        'SELECT id, estado, contacto_tipo, contacto FROM reservaciones WHERE id = ' . $pendingId
    )->fetch_assoc() ?: [];
    landingConfirmationAssert(($domain['estado'] ?? '') === 'confirmada', 'el dominio no quedó confirmado');
    landingConfirmationAssert(($domain['contacto_tipo'] ?? '') === 'email' && ($domain['contacto'] ?? '') === $contact, 'el contacto confirmado no coincide');

    $row = $db->query(
        "SELECT id, reservacion_id, tipo, dedup_key, notification_delivery_status
         FROM reservacion_recordatorios
         WHERE dedup_key = 'confirmacion|{$pendingId}'"
    )->fetch_assoc() ?: [];
    landingConfirmationAssert((int)($row['reservacion_id'] ?? 0) === $pendingId, 'landing no creó la fila de confirmación');
    landingConfirmationAssert(($row['tipo'] ?? '') === ReservationConfirmationService::TYPE, 'landing creó un tipo de comunicación incorrecto');
    landingConfirmationAssert(($row['notification_delivery_status'] ?? '') === 'accepted', 'provider development no aceptó la confirmación');

    ob_start();
    ReservacionController::verificarContacto(new Router());
    $repeatBody = (string)ob_get_clean();
    $repeat = json_decode($repeatBody, true);
    landingConfirmationAssert(($repeat['ok'] ?? false) === true, 'la confirmación OTP repetida dejó de ser idempotente');
    $count = (int)$db->query(
        "SELECT COUNT(*) AS n FROM reservacion_recordatorios WHERE dedup_key = 'confirmacion|{$pendingId}'"
    )->fetch_assoc()['n'];
    landingConfirmationAssert($count === 1, 'el retry landing creó más de una confirmación');

    $directId = insertLandingConfirmationReservation(
        $db,
        'FIXTURE preparar directo',
        'preparar.fixture@example.test',
        'confirmada'
    );
    $reservationIds[] = $directId;
    $prepared = ReservationConfirmationService::preparar($directId);
    landingConfirmationAssert(is_array($prepared), 'preparar directo no devolvió notificación');
    $directRow = $db->query(
        "SELECT reservacion_id, tipo, dedup_key, notification_delivery_status
         FROM reservacion_recordatorios WHERE dedup_key = 'confirmacion|{$directId}'"
    )->fetch_assoc() ?: [];
    landingConfirmationAssert((int)($directRow['reservacion_id'] ?? 0) === $directId, 'preparar directo no persistió reservación');
    landingConfirmationAssert(($directRow['tipo'] ?? '') === 'confirmacion' && ($directRow['dedup_key'] ?? '') === 'confirmacion|' . $directId, 'preparar directo no persistió tipo/dedup');
    landingConfirmationAssert(($directRow['notification_delivery_status'] ?? '') === 'pending', 'preparar directo no inició pending');

    echo json_encode([
        'ok' => true,
        'landing_otp' => ['reservation_id' => $pendingId, 'status' => 'confirmada', 'communication' => 'accepted'],
        'direct_preparation' => ['reservation_id' => $directId, 'communication' => 'pending'],
        'dedup' => 'confirmacion|reservation_id',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    ReservationClientSession::cerrar();
    if ($reservationIds !== []) {
        $ids = implode(',', array_map('intval', $reservationIds));
        $db->query("DELETE FROM reservacion_recordatorios WHERE reservacion_id IN ({$ids})");
        foreach (array_reverse($reservationIds) as $reservationId) {
            $db->query('DELETE FROM reservaciones WHERE id = ' . (int)$reservationId);
        }
    }
    foreach (['APP_ENV' => $oldEnvironment, 'RESERVATION_NOTIFICATION_PROVIDER' => $oldProvider, 'RESERVATION_PUBLIC_BASE_URL' => $oldBaseUrl] as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $value;
        }
    }
    if ($oldRequestMethod === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $oldRequestMethod;
    }
    if ($oldContentType === null) {
        unset($_SERVER['CONTENT_TYPE']);
    } else {
        $_SERVER['CONTENT_TYPE'] = $oldContentType;
    }
    $_POST = $oldPost;
}
