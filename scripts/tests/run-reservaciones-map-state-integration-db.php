<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !getenv('CP_NOTIFICATION_TEST_DATABASE')) {
    exit("Usar run-notifications-isolated.php\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Controllers\PuntoVentaController;
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

/** GET /api/punto-de-venta con hora: el mapa del POS proyectado. */
function readPosEndpoint(array $query): array
{
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    http_response_code(200);
    ob_start();
    PuntoVentaController::api(new \MVC\Router());
    $body = (string)ob_get_clean();
    $response = json_decode($body, true);
    assertMapStateIntegration(is_array($response), 'el endpoint del POS no devolvió JSON válido: ' . $body);
    assertMapStateIntegration(($response['ok'] ?? false) === true, 'el endpoint del POS rechazó el fixture: ' . $body);
    return $response;
}

/** La lectura del POS proyectada a una hora con un reloj fijo. */
function readPosProjection(string $date, string $hour, string $clock): array
{
    return PosReservacionQueryService::paraFecha($date, $hour, [
        'ahora' => new DateTimeImmutable($date . ' ' . $clock, ReservacionConfig::timezone()),
        'incluir_inactivas' => true,
        'superficie' => 'admin',
        'contexto_pos' => true,
    ]);
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

    // V23–V29 — call the real JSON endpoint, including non-slot map minutes.
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

    // B — el endpoint real del POS proyecta a la hora pedida. Azul de
    // inicio − 60 a inicio + 90, semiabierto; la asignación es otro hecho.
    $expectedProjection = [
        '17:30' => ['libre', 'futura', true],
        '17:45' => ['libre', 'futura', false],
        '18:00' => ['reservacion-proxima', 'advertencia', false],
        '18:15' => ['reservacion-proxima', 'advertencia', false],
        '18:30' => ['reservacion-proxima', 'bloqueo', false],
        '18:59' => ['reservacion-proxima', 'bloqueo', false],
        '19:00' => ['reservacion-proxima', 'inicio', false],
        '20:29' => ['reservacion-proxima', 'activa', false],
        '20:30' => ['libre', 'irrelevante', true],
    ];
    foreach ($expectedProjection as $queryHour => [$visual, $window, $assignable]) {
        $response = readPosEndpoint(['fecha' => $endpointDate, 'hora' => $queryHour]);
        $state = readMapState($response, $endpointTableId);
        $contexto = (array)($response['contexto'] ?? []);
        assertMapStateIntegration(
            ($contexto['modo'] ?? '') === 'proyeccion'
                && substr((string)($contexto['hora'] ?? ''), 0, 5) === $queryHour
                && $state['estado_visual_pos'] === $visual
                && $state['ventana_mapa'] === $window
                && $state['disponible_para_asignacion'] === $assignable,
            "endpoint POS {$queryHour}: modo, hora, color, ventana y asignación: "
                . json_encode([$contexto, $state['estado_visual_pos'], $state['ventana_mapa'], $state['disponible_para_asignacion']], JSON_UNESCAPED_UNICODE)
        );
        if ($queryHour === '17:45') {
            assertMapStateIntegration(
                $state['bloqueada_en_intervalo'],
                'endpoint POS 17:45: el intervalo ya choca aunque el mapa siga verde'
            );
        }
    }

    // N — enfocar la reserva (al abrir su ficha) no cambia su color.
    $selectedJson = readPosEndpoint([
        'fecha' => $endpointDate,
        'hora' => '19:00',
        'reservacion_id' => (string)$endpointReservationId,
    ]);
    $selected = readMapState($selectedJson, $endpointTableId);
    assertMapStateIntegration(
        $selected['bloqueada_en_intervalo']
            && $selected['causas_bloqueo'] === ['reservacion']
            && $selected['estado_visual_pos'] === 'reservacion-proxima'
            && $selected['subtitulo_pos'] === '19:00',
        'endpoint POS N: la reserva enfocada sigue azul y rotulada con su hora'
    );

    // C and D — interval facts stay stable for one-minute overlap and changing now.
    $matrixDate = $endpointDate;
    $overlapTableId = insertMapStateTable($db, 9802);
    $tableIds[] = $overlapTableId;
    $overlapReservationId = insertMapStateReservation($db, $matrixDate, '19:00:00', $overlapTableId);
    $reservationIds[] = $overlapReservationId;
    $caseC = readMapState(readPosProjection($matrixDate, '17:31:00', '16:00:00'), $overlapTableId);
    assertMapStateIntegration(
        $caseC['bloqueada_en_intervalo']
            && in_array('reservacion', $caseC['causas_bloqueo'], true)
            && !$caseC['disponible_para_asignacion']
            && $caseC['estado_visual_pos'] === 'libre',
        'query real C: un solapamiento futuro bloquea asignación, no el estado visual'
    );

    $clockTableId = insertMapStateTable($db, 9803);
    $tableIds[] = $clockTableId;
    $clockReservationId = insertMapStateReservation($db, $matrixDate, '20:30:00', $clockTableId);
    $reservationIds[] = $clockReservationId;
    $clockFacts = [];
    foreach (['18:00:00', '19:54:00', '20:15:00'] as $clock) {
        $state = readMapState(readPosProjection($matrixDate, '19:30:00', $clock), $clockTableId);
        $clockFacts[] = array_intersect_key($state, array_flip([
            'bloqueada_en_intervalo',
            'causas_bloqueo',
            'disponible_para_asignacion',
            'estado_visual_pos',
            'subtitulo_pos',
        ]));
        assertMapStateIntegration(
            $state['bloqueada_en_intervalo']
                && $state['causas_bloqueo'] === ['reservacion']
                && $state['estado_visual_pos'] === 'reservacion-proxima'
                && $state['subtitulo_pos'] === '20:30',
            'query real D: la proyección no depende del reloj actual: ' . json_encode($state, JSON_UNESCAPED_UNICODE)
        );
    }
    assertMapStateIntegration(
        $clockFacts[0] === $clockFacts[1] && $clockFacts[1] === $clockFacts[2],
        'query real D: la hora actual no altera hechos ni color de la proyección'
    );

    // P and Q — una reserva vencida sigue apartando la mesa hasta que se registra la ausencia.
    $absenceTableId = insertMapStateTable($db, 9804);
    $tableIds[] = $absenceTableId;
    $absenceReservationId = insertMapStateReservation($db, $matrixDate, '18:00:00', $absenceTableId);
    $reservationIds[] = $absenceReservationId;
    $caseP = readMapState(readPosProjection($matrixDate, '18:00:00', '18:20:00'), $absenceTableId);
    assertMapStateIntegration(
        $caseP['ausencia_pendiente']
            && $caseP['puede_marcar_no_show']
            && in_array('ausencia_pendiente', $caseP['modificadores'], true)
            && $caseP['bloqueada_en_intervalo']
            && $caseP['estado_visual_pos'] === 'reservacion-proxima',
        'query real P: la ausencia pendiente sigue azul y conserva su señal'
    );

    $db->query("UPDATE reservaciones SET estado = 'no_show' WHERE id = {$absenceReservationId}");
    $caseQ = readMapState(readPosProjection($matrixDate, '18:00:00', '18:20:00'), $absenceTableId);
    assertMapStateIntegration(
        !$caseQ['bloqueada_en_intervalo']
            && !$caseQ['ausencia_pendiente']
            && !$caseQ['puede_marcar_no_show']
            && $caseQ['estado_visual_pos'] === 'libre'
            && $caseQ['modificadores_visual_pos'] === [],
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
