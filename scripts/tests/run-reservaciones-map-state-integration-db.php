<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !getenv('CP_NOTIFICATION_TEST_DATABASE')) {
    exit("Usar run-notifications-isolated.php\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Controllers\ReservacionOperacionController;
use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\Pos\PosReservacionQueryService;
use Services\Reservations\ReservacionConfig;

function assertMapStateIntegration(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insertMapStateTable(mysqli $db, int $number): int
{
    $name = 'Fixture mapa ' . $number;
    $stmt = $db->prepare(
        "INSERT INTO mesas (numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable)
         VALUES (?, ?, 'mesa', 4, 15, 20, 1, 1)"
    );
    $stmt->bind_param('is', $number, $name);
    assertMapStateIntegration($stmt->execute(), 'no se pudo crear la mesa fixture');
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

function insertMapStateReservation(
    mysqli $db,
    string $date,
    string $time,
    int $tableId,
    string $status = 'confirmada',
    ?string $holdExpiresAt = null
): int {
    $name = 'Fixture mapa de estado';
    $contactType = 'ninguno';
    $contact = null;
    $guests = 2;
    $note = '';
    $origin = 'admin';
    $token = 'map-state-' . bin2hex(random_bytes(8));
    $changedAt = $date . ' 12:00:00';
    $stmt = $db->prepare(
        'INSERT INTO reservaciones
          (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen,
           estado, request_token, hold_expires_at, estado_changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'sssssissssss',
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
        $holdExpiresAt,
        $changedAt
    );
    assertMapStateIntegration($stmt->execute(), 'no se pudo crear la reservación fixture');
    $id = (int)$db->insert_id;
    $stmt->close();
    ReservacionMesa::reemplazarAsignacion($id, [$tableId]);
    return $id;
}

function readMapState(array $response, int $tableId): array
{
    foreach ((array)($response['mesas_estado'] ?? []) as $table) {
        if ((int)($table['id'] ?? 0) === $tableId) {
            return $table;
        }
    }
    throw new RuntimeException('la respuesta mesas_estado no contiene la mesa fixture ' . $tableId);
}

function readOperationEndpoint(array $query): array
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    http_response_code(200);
    ob_start();
    ReservacionOperacionController::operationData();
    $body = (string)ob_get_clean();
    $response = json_decode($body, true);
    assertMapStateIntegration(is_array($response), 'operationData no devolvió JSON válido: ' . $body);
    assertMapStateIntegration(($response['ok'] ?? false) === true, 'operationData rechazó el fixture: ' . $body);
    return $response;
}

$db = ActiveRecord::getDB();
$tableIds = [];
$reservationIds = [];
$ticketIds = [];
$originalSession = $_SESSION ?? [];
$originalGet = $_GET;
$originalRequestMethod = $_SERVER['REQUEST_METHOD'] ?? null;

try {
    $realNow = ReservacionConfig::ahora();
    $endpointDate = $realNow->modify('+1 day')->format('Y-m-d');
    $weekday = (int)(new DateTimeImmutable($endpointDate, ReservacionConfig::timezone()))->format('w');
    $db->query(
        "INSERT INTO horarios_operacion (dia_semana, abierto, hora_apertura, hora_cierre)
         VALUES ({$weekday}, 1, '10:00:00', '23:00:00')
         ON DUPLICATE KEY UPDATE abierto = 1, hora_apertura = '10:00:00', hora_cierre = '23:00:00'"
    );

    // B and N — call the real JSON endpoint from its controller.
    $endpointTableId = insertMapStateTable($db, 9801);
    $tableIds[] = $endpointTableId;
    $endpointReservationId = insertMapStateReservation(
        $db,
        $endpointDate,
        '19:00:00',
        $endpointTableId
    );
    $reservationIds[] = $endpointReservationId;
    $_SESSION = ['login' => true, 'id' => 1, 'rol' => 'admin'];

    $adjacentJson = json_decode(json_encode(readOperationEndpoint([
        'fecha' => $endpointDate,
        'hora' => '17:30',
    ]), JSON_UNESCAPED_UNICODE), true);
    $adjacent = readMapState($adjacentJson, $endpointTableId);
    assertMapStateIntegration(
        !$adjacent['bloqueada_en_intervalo']
            && $adjacent['causas_bloqueo'] === []
            && $adjacent['disponible_para_asignacion']
            && $adjacent['estado_visual_mapa'] === 'libre'
            && $adjacent['reservacion_cercana_mapa']
            && in_array('reservacion_advertencia', $adjacent['modificadores_visual_mapa'], true)
            && $adjacent['label_visual_mapa'] === 'Disponible con reservación cercana',
        'endpoint JSON B: consulta 17:30 y reserva 19:00 debe ser libre con aviso'
    );

    $selectedJson = readOperationEndpoint([
        'fecha' => $endpointDate,
        'hora' => '19:00',
        'reservation_id' => (string)$endpointReservationId,
    ]);
    $selected = readMapState($selectedJson, $endpointTableId);
    assertMapStateIntegration(
        $selected['bloqueada_en_intervalo']
            && $selected['causas_bloqueo'] === ['reservacion']
            && $selected['disponible_para_asignacion']
            && $selected['estado_visual_mapa'] === 'reservacion-proxima'
            && $selected['label_visual_mapa'] === 'No disponible por reservación'
            && $selected['aria_label_mapa'] === 'Fixture mapa 9801, no disponible por reservación.'
            && $selected['titulo_mapa'] === $selected['aria_label_mapa'],
        'endpoint JSON N: la reserva propia se conserva azul y asignable'
    );

    // C and D — interval facts stay stable for one-minute overlap and changing now.
    $matrixDate = '2037-02-15';
    $overlapTableId = insertMapStateTable($db, 9802);
    $tableIds[] = $overlapTableId;
    $overlapReservationId = insertMapStateReservation($db, $matrixDate, '19:00:00', $overlapTableId);
    $reservationIds[] = $overlapReservationId;
    $overlap = PosReservacionQueryService::paraFecha($matrixDate, '17:31:00', [
        'ahora' => new DateTimeImmutable($matrixDate . ' 16:00:00', ReservacionConfig::timezone()),
        'incluir_inactivas' => true,
        'superficie' => 'admin',
    ]);
    $caseC = readMapState($overlap, $overlapTableId);
    assertMapStateIntegration(
        $caseC['bloqueada_en_intervalo']
            && in_array('reservacion', $caseC['causas_bloqueo'], true)
            && !$caseC['disponible_para_asignacion']
            && $caseC['estado_visual_mapa'] === 'reservacion-proxima'
            && !in_array('reservacion_advertencia', $caseC['modificadores_visual_mapa'], true)
            && $caseC['label_visual_mapa'] === 'No disponible por reservación',
        'query real C: el minuto de solapamiento se conserva hasta mesas_estado'
    );

    $clockTableId = insertMapStateTable($db, 9803);
    $tableIds[] = $clockTableId;
    $clockReservationId = insertMapStateReservation($db, $matrixDate, '20:30:00', $clockTableId);
    $reservationIds[] = $clockReservationId;
    $clockFacts = [];
    foreach (['18:00:00', '19:54:00', '20:15:00'] as $clock) {
        $response = PosReservacionQueryService::paraFecha($matrixDate, '19:30:00', [
            'ahora' => new DateTimeImmutable($matrixDate . ' ' . $clock, ReservacionConfig::timezone()),
            'incluir_inactivas' => true,
            'superficie' => 'admin',
        ]);
        $state = readMapState($response, $clockTableId);
        $clockFacts[] = array_intersect_key($state, array_flip([
            'bloqueada_en_intervalo',
            'causas_bloqueo',
            'disponible_para_asignacion',
            'estado_visual_mapa',
            'modificadores_visual_mapa',
            'label_visual_mapa',
        ]));
        assertMapStateIntegration(
            $state['bloqueada_en_intervalo']
                && $state['causas_bloqueo'] === ['reservacion']
                && $state['estado_visual_mapa'] === 'reservacion-proxima'
                && $state['label_visual_mapa'] === 'No disponible por reservación',
            'query real D: cada reloj conserva el bloqueo por solapamiento'
        );
    }
    assertMapStateIntegration(
        $clockFacts[0] === $clockFacts[1] && $clockFacts[1] === $clockFacts[2],
        'query real D: la hora actual no altera hechos ni etiqueta del intervalo'
    );

    // P and Q — admin map keeps an overdue confirmed reservation until no_show persists.
    $absenceTableId = insertMapStateTable($db, 9804);
    $tableIds[] = $absenceTableId;
    $absenceReservationId = insertMapStateReservation($db, $matrixDate, '18:00:00', $absenceTableId);
    $reservationIds[] = $absenceReservationId;
    $absencePending = PosReservacionQueryService::paraFecha($matrixDate, '18:00:00', [
        'ahora' => new DateTimeImmutable($matrixDate . ' 18:20:00', ReservacionConfig::timezone()),
        'incluir_inactivas' => true,
        'superficie' => 'admin',
    ]);
    $caseP = readMapState($absencePending, $absenceTableId);
    assertMapStateIntegration(
        $caseP['ausencia_pendiente']
            && $caseP['puede_marcar_no_show']
            && in_array('ausencia_pendiente', $caseP['modificadores_visual_mapa'], true)
            && $caseP['bloqueada_en_intervalo']
            && $caseP['estado_visual_mapa'] === 'reservacion-proxima',
        'query real P: no-show pendiente acompaña el bloqueo sin crear disponibilidad'
    );

    $db->query("UPDATE reservaciones SET estado = 'no_show' WHERE id = {$absenceReservationId}");
    $noShow = PosReservacionQueryService::paraFecha($matrixDate, '18:00:00', [
        'ahora' => new DateTimeImmutable($matrixDate . ' 18:20:00', ReservacionConfig::timezone()),
        'incluir_inactivas' => true,
        'superficie' => 'admin',
    ]);
    $caseQ = readMapState($noShow, $absenceTableId);
    assertMapStateIntegration(
        !$caseQ['bloqueada_en_intervalo']
            && !$caseQ['ausencia_pendiente']
            && !$caseQ['puede_marcar_no_show']
            && $caseQ['estado_visual_mapa'] === 'libre'
            && $caseQ['modificadores_visual_mapa'] === [],
        'query real Q: no-show confirmado sale de ocupación y modificadores'
    );

    echo json_encode([
        'ok' => true,
        'endpoint' => ['B' => 'PASS', 'N' => 'PASS'],
        'query' => ['C' => 'PASS', 'D' => 'PASS', 'P' => 'PASS', 'Q' => 'PASS'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    foreach ($ticketIds as $id) {
        $db->query('DELETE FROM tickets WHERE id = ' . (int)$id);
    }
    foreach ($reservationIds as $id) {
        $db->query('DELETE FROM reservaciones WHERE id = ' . (int)$id);
    }
    foreach ($tableIds as $id) {
        $db->query('DELETE FROM mesas WHERE id = ' . (int)$id);
    }
    $_SESSION = $originalSession;
    $_GET = $originalGet;
    if ($originalRequestMethod === null) {
        unset($_SERVER['REQUEST_METHOD']);
    } else {
        $_SERVER['REQUEST_METHOD'] = $originalRequestMethod;
    }
}
