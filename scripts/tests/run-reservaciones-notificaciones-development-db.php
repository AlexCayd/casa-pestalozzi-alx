<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\DisponibilidadReservacionService;
use Services\Integrations\N8nClient;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;
use Services\Reservations\ScheduleChangeNotificationService;
use Services\Reservations\HorarioOperacionImpactoService;

function developmentNotificationsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{fecha:string,hora:string} */
function developmentAvailableSlot(): array
{
    $timezone = new DateTimeZone('America/Mexico_City');
    for ($offset = 1; $offset <= 60; $offset++) {
        $date = ReservacionConfig::ahora()
            ->setTimezone($timezone)
            ->modify("+{$offset} day")
            ->format('Y-m-d');
        $availability = DisponibilidadReservacionService::consultar($date, 2);
        foreach ((array)($availability['horarios'] ?? []) as $slot) {
            if (($slot['disponible'] ?? false) === true && (string)($slot['hora'] ?? '') !== '') {
                return ['fecha' => $date, 'hora' => (string)$slot['hora']];
            }
        }
    }

    throw new RuntimeException('no se encontró un horario para la prueba development');
}

$db = ActiveRecord::getDB();
$reservationId = 0;
$impactId = 0;
$impactItemId = 0;
$oldEnvironment = $_ENV['APP_ENV'] ?? null;
$oldNow = $_ENV['RESERVATION_TEST_NOW'] ?? null;
$oldBaseUrl = $_ENV['RESERVATION_PUBLIC_BASE_URL'] ?? null;

try {
    $_ENV['APP_ENV'] = 'development';
    $_ENV['RESERVATION_PUBLIC_BASE_URL'] = 'http://localhost';

    $slot = developmentAvailableSlot();
    $requestToken = bin2hex(random_bytes(32));
    $contact = 'development.fixture@example.test';
    $created = ReservacionPublicaService::crearRetencion([
        'nombre' => 'FIXTURE notificaciones development',
        'tipo_contacto' => 'email',
        'contacto' => $contact,
        'fecha' => $slot['fecha'],
        'hora' => $slot['hora'],
        'personas' => 2,
        'notas' => 'Fixture local sin transporte externo',
        'request_token' => $requestToken,
    ]);
    developmentNotificationsAssert(($created['ok'] ?? false) === true, 'development no creó la retención');
    $code = (string)($created['development_confirmation_code'] ?? '');
    developmentNotificationsAssert(preg_match('/^\d{6}$/', $code) === 1, 'development no expuso el campo explícito del código');
    developmentNotificationsAssert(($created['external_transport'] ?? true) === false, 'development intentó usar transporte externo');
    developmentNotificationsAssert(!array_key_exists('_notification_payload', $created), 'la respuesta expuso el payload privado');

    $rowResult = $db->query(
        "SELECT id, estado FROM reservaciones WHERE request_token = '" . $db->real_escape_string($requestToken) . "'"
    );
    $row = $rowResult ? ($rowResult->fetch_assoc() ?: []) : [];
    if ($rowResult) {
        $rowResult->free();
    }
    $reservationId = (int)($row['id'] ?? 0);
    developmentNotificationsAssert($reservationId > 0 && ($row['estado'] ?? '') === 'pendiente_verificacion', 'la retención no persistió correctamente');

    $confirmed = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $contact,
        'codigo' => $code,
        'request_token' => $requestToken,
    ]);
    developmentNotificationsAssert(($confirmed['ok'] ?? false) === true, 'el código development no confirmó la reservación');
    $confirmedState = $db->query('SELECT estado FROM reservaciones WHERE id = ' . $reservationId);
    $confirmedRow = $confirmedState ? ($confirmedState->fetch_assoc() ?: []) : [];
    if ($confirmedState) {
        $confirmedState->free();
    }
    developmentNotificationsAssert(($confirmedRow['estado'] ?? '') === 'confirmada', 'el dominio no quedó confirmado');

    $dedup = hash('sha256', 'development-impact-' . bin2hex(random_bytes(8)));
    $stmt = $db->prepare("INSERT INTO horario_impactos (tipo_origen, estado, dedup_key) VALUES ('fixture', 'pendiente', ?)");
    $stmt->bind_param('s', $dedup);
    developmentNotificationsAssert($stmt->execute(), 'no se pudo crear el impacto development');
    $impactId = (int)$db->insert_id;
    $stmt->close();
    developmentNotificationsAssert($db->query(
        "INSERT INTO horario_impacto_reservaciones (impacto_id, reservacion_id, estado)
         VALUES ({$impactId}, {$reservationId}, 'pendiente_notificacion')"
    ) !== false, 'no se pudo crear el seguimiento development');
    $impactItemId = (int)$db->insert_id;

    $externalCalls = 0;
    $guardClient = new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static function () use (&$externalCalls): array {
            $externalCalls++;
            throw new RuntimeException('n8n no debe ejecutarse en development');
        }
    );
    $prepared = ScheduleChangeNotificationService::dispatchItem($impactItemId, false, $guardClient);
    developmentNotificationsAssert(($prepared['prepared'] ?? false) === true, 'development no preparó el acceso de cambio de horario');
    developmentNotificationsAssert(($prepared['external_transport'] ?? true) === false && $externalCalls === 0, 'development llamó n8n');
    $debugItem = HorarioOperacionImpactoService::obtenerPorItem($impactItemId);
    developmentNotificationsAssert(($debugItem['test_link_disponible'] ?? false) === true, 'development no habilitó el enlace de gestión');
    $beforeDebugAttempts = (int)($debugItem['notification_attempts'] ?? -1);
    $beforeDebugStatus = (string)($debugItem['notification_delivery_status'] ?? '');
    $debugLink = HorarioOperacionImpactoService::regenerarAccesoDePrueba($impactId, $impactItemId, null);
    developmentNotificationsAssert(($debugLink['ok'] ?? false) === true && str_starts_with((string)($debugLink['test_access_url'] ?? ''), 'http://localhost'), 'development no generó el enlace de prueba');
    $afterDebugItem = HorarioOperacionImpactoService::obtenerPorItem($impactItemId);
    developmentNotificationsAssert((int)($afterDebugItem['notification_attempts'] ?? -1) === $beforeDebugAttempts, 'el enlace de prueba incrementó attempt');
    developmentNotificationsAssert((string)($afterDebugItem['notification_delivery_status'] ?? '') === $beforeDebugStatus, 'el enlace de prueba cambió delivery_status');

    echo json_encode([
        'ok' => true,
        'confirmation' => ['code_visible' => true, 'confirmed' => true],
        'schedule_change' => ['debug_link' => true, 'external_calls' => 0, 'attempt_unchanged' => true],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($impactItemId > 0) {
        $db->query("DELETE FROM buzon_notificaciones WHERE entidad_tipo = 'horario_impacto_reservacion' AND entidad_id = {$impactItemId}");
        $db->query('DELETE FROM horario_impacto_reservaciones WHERE id = ' . $impactItemId);
    }
    if ($impactId > 0) {
        $db->query('DELETE FROM horario_impactos WHERE id = ' . $impactId);
    }
    if ($reservationId > 0) {
        $db->query('DELETE FROM reservacion_recordatorios WHERE reservacion_id = ' . $reservationId);
        $db->query('DELETE FROM reservaciones WHERE id = ' . $reservationId);
    }
    if ($oldEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $oldEnvironment;
    if ($oldNow === null) unset($_ENV['RESERVATION_TEST_NOW']); else $_ENV['RESERVATION_TEST_NOW'] = $oldNow;
    if ($oldBaseUrl === null) unset($_ENV['RESERVATION_PUBLIC_BASE_URL']); else $_ENV['RESERVATION_PUBLIC_BASE_URL'] = $oldBaseUrl;
}
