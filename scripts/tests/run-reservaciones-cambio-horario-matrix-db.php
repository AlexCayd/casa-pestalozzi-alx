<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Integrations\N8nClient;
use Services\Reservations\Notifications\ScheduleChangeNotificationService;
use Services\Reservations\ScheduleChanges\HorarioOperacionImpactoService;

function scheduleMatrixAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insertScheduleMatrixReservation(
    mysqli $db,
    string $name,
    string $contactType,
    ?string $contact,
    int $guests
): int {
    $token = bin2hex(random_bytes(32));
    $date = '2037-02-15';
    $time = '13:00:00';
    $note = 'Fixture matriz cambio horario';
    $origin = 'landing';
    $status = 'confirmada';
    $changedAt = '2037-02-14 12:00:00';
    $stmt = $db->prepare(
        'INSERT INTO reservaciones
          (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen,
           estado, request_token, estado_changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sssssisssss',
        $name,
        $contactType,
        $contact,
        $date,
        $time,
        $guests,
        $note,
        $origin,
        $status,
        $token,
        $changedAt
    );
    scheduleMatrixAssert($stmt->execute(), 'no se pudo insertar fixture de matriz');
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

$db = ActiveRecord::getDB();
$reservationIds = [];
$impactId = 0;
$oldEnvironment = $_ENV['APP_ENV'] ?? null;
$oldBaseUrl = $_ENV['RESERVATION_PUBLIC_BASE_URL'] ?? null;

try {
    $_ENV['APP_ENV'] = 'test';
    $_ENV['RESERVATION_PUBLIC_BASE_URL'] = 'http://localhost';

    $fixtures = [
        'small_email' => ['email', 'matrix.email@example.test', 2],
        'small_whatsapp' => ['telefono', '+525500000021', 2],
        'small_no_contact' => ['ninguno', null, 2],
        'large_contact' => ['email', 'matrix.large@example.test', 13],
        'large_no_contact' => ['ninguno', null, 13],
    ];
    foreach ($fixtures as $key => [$contactType, $contact, $guests]) {
        $reservationIds[$key] = insertScheduleMatrixReservation(
            $db,
            'FIXTURE matriz ' . $key,
            $contactType,
            $contact,
            $guests
        );
    }

    $before = [
        'semanal' => [0 => ['abierto' => true, 'hora_apertura' => '08:00:00', 'hora_cierre' => '22:00:00']],
        'excepciones' => [],
    ];
    $after = [
        'semanal' => [0 => ['abierto' => false, 'hora_apertura' => null, 'hora_cierre' => null]],
        'excepciones' => [],
    ];
    $evaluation = [
        'antes' => $before,
        'despues' => $after,
        'conflictos' => array_map(
            static fn(int $id): array => ['id' => $id, 'reservacion_id' => $id],
            array_values($reservationIds)
        ),
    ];
    scheduleMatrixAssert($db->begin_transaction(), 'no se pudo iniciar la persistencia del impacto');
    $impactId = (int)(HorarioOperacionImpactoService::persistir($evaluation, 'fixture_matrix', null, null) ?? 0);
    scheduleMatrixAssert($impactId > 0, 'no se creó el impacto de matriz');
    scheduleMatrixAssert($db->commit(), 'no se confirmó el impacto de matriz');

    $captured = [];
    $client = new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static function (string $url, string $secret, string $json) use (&$captured): array {
            $payload = json_decode($json, true);
            if (is_array($payload)) {
                $captured[] = $payload;
            }
            return ['status' => 202, 'body' => '{"ok":true,"accepted":true}'];
        }
    );
    $dispatch = ScheduleChangeNotificationService::dispatchAutomatic($impactId, $client);
    scheduleMatrixAssert($dispatch === ['attempted' => 2, 'accepted' => 2, 'failed' => 0, 'prepared' => 2], 'el dispatch automático no seleccionó exactamente los dos casos elegibles');
    $channels = array_map(static fn(array $payload): string => (string)($payload['contact']['type'] ?? ''), $captured);
    sort($channels);
    scheduleMatrixAssert($channels === ['email', 'whatsapp'], 'el impacto no cubrió Email y WhatsApp');

    $rowsResult = $db->query(
        "SELECT ir.id, ir.reservacion_id, ir.estado, ir.notification_attempts,
                ir.notification_delivery_status, ir.access_token_hash,
                bn.prioridad, bn.requiere_accion
         FROM horario_impacto_reservaciones ir
         LEFT JOIN buzon_notificaciones bn
           ON bn.entidad_tipo = 'horario_impacto_reservacion' AND bn.entidad_id = ir.id
         WHERE ir.impacto_id = {$impactId}"
    );
    scheduleMatrixAssert($rowsResult !== false, 'no se pudo leer la matriz persistida');
    $byReservation = [];
    while ($row = $rowsResult->fetch_assoc()) {
        $byReservation[(int)$row['reservacion_id']] = $row;
    }
    $rowsResult->free();

    foreach (['small_email', 'small_whatsapp'] as $key) {
        $row = $byReservation[$reservationIds[$key]] ?? [];
        scheduleMatrixAssert(($row['estado'] ?? '') === 'notificacion_preparada', "{$key} no preparó autoservicio");
        scheduleMatrixAssert((int)($row['notification_attempts'] ?? 0) === 1, "{$key} no quedó en attempt 1");
        scheduleMatrixAssert(($row['notification_delivery_status'] ?? '') === 'pending', "{$key} debe esperar el resultado proveedor, no convertir 202 en envío");
        scheduleMatrixAssert((string)($row['access_token_hash'] ?? '') !== '', "{$key} no persistió hash de acceso");
        scheduleMatrixAssert((int)($row['requiere_accion'] ?? 1) === 0, "{$key} no pasó a En espera");
    }

    $smallNoContact = $byReservation[$reservationIds['small_no_contact']] ?? [];
    scheduleMatrixAssert(($smallNoContact['estado'] ?? '') === 'sin_contacto' && (int)($smallNoContact['notification_attempts'] ?? -1) === 0, 'caso <=12 sin contacto fue despachado');
    scheduleMatrixAssert((int)($smallNoContact['requiere_accion'] ?? 0) === 1, 'caso <=12 sin contacto no quedó accionable');

    foreach (['large_contact', 'large_no_contact'] as $key) {
        $row = $byReservation[$reservationIds[$key]] ?? [];
        scheduleMatrixAssert((int)($row['notification_attempts'] ?? -1) === 0, "{$key} inició autoservicio");
        scheduleMatrixAssert((string)($row['access_token_hash'] ?? '') === '', "{$key} creó acceso automático");
        scheduleMatrixAssert(($row['prioridad'] ?? '') === 'alta' && (int)($row['requiere_accion'] ?? 0) === 1, "{$key} no quedó en gestión administrativa");
    }

    echo json_encode([
        'ok' => true,
        'matrix' => [
            '<=12_email' => 'pending',
            '<=12_whatsapp' => 'pending',
            '<=12_sin_contacto' => 'accionable',
            '>12_con_contacto' => 'administrativo',
            '>12_sin_contacto' => 'administrativo',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($impactId > 0) {
        $itemIdsResult = $db->query('SELECT id FROM horario_impacto_reservaciones WHERE impacto_id = ' . $impactId);
        $itemIds = [];
        if ($itemIdsResult) {
            while ($row = $itemIdsResult->fetch_assoc()) {
                $itemIds[] = (int)$row['id'];
            }
            $itemIdsResult->free();
        }
        if ($itemIds !== []) {
            $db->query("DELETE FROM buzon_notificaciones WHERE entidad_tipo = 'horario_impacto_reservacion' AND entidad_id IN (" . implode(',', $itemIds) . ')');
        }
        $db->query('DELETE FROM horario_impacto_reservaciones WHERE impacto_id = ' . $impactId);
        $db->query('DELETE FROM horario_impactos WHERE id = ' . $impactId);
    }
    if ($reservationIds !== []) {
        $db->query('DELETE FROM reservaciones WHERE id IN (' . implode(',', array_map('intval', array_values($reservationIds))) . ')');
    }
    if ($oldEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $oldEnvironment;
    if ($oldBaseUrl === null) unset($_ENV['RESERVATION_PUBLIC_BASE_URL']); else $_ENV['RESERVATION_PUBLIC_BASE_URL'] = $oldBaseUrl;
}
