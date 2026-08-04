<?php

declare(strict_types=1);

/**
 * Integración operativa completa de Etapa 10.
 *
 * La suite exige una base explícita de pruebas y sólo crea fixtures con un
 * prefijo propio. No modifica el esquema ni deja datos al terminar.
 */

$database = '';
foreach ($argv ?? [] as $argumento) {
    if (str_starts_with((string)$argumento, '--db=')) {
        $database = substr((string)$argumento, 5);
    }
}
if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1
    || in_array($database, ['casa-pestalozzi', 'casa_pestalozzi'], true)) {
    fwrite(STDERR, "Uso: php etapa10_integracion_operativa.php --db=BASE_DE_PRUEBAS\n");
    exit(2);
}

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('APP_ENV=testing');
putenv('DB_NAME=' . $database);
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
ini_set('session.save_path', dirname(__DIR__));

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\AsignacionMesasService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionConfig;
use Services\ReservacionMapaAdministrativaService;
use Services\ReservacionPublicaService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No hay conexión MySQL para Etapa 10.\n");
    exit(2);
}
ActiveRecord::setDB($db);
$db->set_charset('utf8mb4');

$marker = 'ETAPA10_' . strtoupper(bin2hex(random_bytes(5)));
$passed = 0;
$failed = [];
$cases = [];
$fixtureReservationIds = [];

$assert = static function (string $case, bool $condition, string $detail = '') use (&$passed, &$failed, &$cases): void {
    $cases[$case] = ['ok' => $condition, 'detail' => $detail];
    if ($condition) {
        $passed++;
    } else {
        $failed[] = $case . ($detail !== '' ? ': ' . $detail : '');
    }
};

$query = static function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
    return $result;
};
$escape = static fn(string $value): string => $db->real_escape_string($value);
$row = static function (string $sql) use ($query): ?array {
    $result = $query($sql);
    $value = $result instanceof mysqli_result ? ($result->fetch_assoc() ?: null) : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    return $value;
};
$idsFrom = static function (string $sql) use ($query): array {
    $result = $query($sql);
    $ids = [];
    while ($value = $result->fetch_assoc()) {
        $ids[] = (int)($value['id'] ?? $value['mesa_id'] ?? 0);
    }
    $result->free();
    sort($ids, SORT_NUMERIC);
    return array_values(array_filter($ids));
};

$insertReservation = static function (
    string $suffix,
    string $fecha,
    string $hora,
    string $estado = 'confirmada',
    ?string $hold = null,
    ?int $reemplaza = null,
    string $origen = 'admin'
) use ($query, $escape, $db, $marker, &$fixtureReservationIds): int {
    $name = $marker . '_' . $suffix;
    $token = $marker . '_' . $suffix . '_' . bin2hex(random_bytes(4));
    $holdSql = $hold === null ? 'NULL' : "'" . $escape($hold) . "'";
    $replaceSql = $reemplaza === null ? 'NULL' : (string)$reemplaza;
    $query("INSERT INTO reservaciones
        (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
         comentario_admin, origen, request_token, hold_expires_at, estado,
         reemplaza_reservacion_id, estado_changed_at)
        VALUES ('{$escape($name)}', 'ninguno', NULL, '{$escape($fecha)}', '{$escape($hora)}',
         2, 'Fixture Etapa 10', '', '{$escape($origen)}', '{$escape($token)}',
         {$holdSql}, '{$escape($estado)}', {$replaceSql}, '2026-11-01 12:00:00')");
    $id = (int)$db->insert_id;
    $fixtureReservationIds[] = $id;
    return $id;
};

$assign = static function (int $reservationId, array $mesaIds) use ($db): void {
    ReservacionMesa::reemplazarAsignacion($reservationId, array_values(array_map('intval', $mesaIds)));
    if (!ReservacionMesa::tieneMesasAsignadas($reservationId)) {
        throw new RuntimeException('Fixture sin asignación de mesas.');
    }
};

$cleanup = static function () use ($db, $query, $escape, $marker): void {
    $like = $escape($marker . '%');
    $ticketIds = [];
    $result = $query("SELECT id FROM tickets WHERE nombre LIKE '{$like}'");
    while ($value = $result->fetch_assoc()) {
        $ticketIds[] = (int)$value['id'];
    }
    $result->free();
    if ($ticketIds !== []) {
        $list = implode(',', $ticketIds);
        $db->query("DELETE FROM ticket_pagos WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM ticket_items WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM ticket_mesas WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM tickets WHERE id IN ({$list})");
    }

    $reservationIds = [];
    $result = $query("SELECT id FROM reservaciones WHERE nombre LIKE '{$like}' ORDER BY id DESC");
    while ($value = $result->fetch_assoc()) {
        $reservationIds[] = (int)$value['id'];
    }
    $result->free();
    if ($reservationIds !== []) {
        $list = implode(',', $reservationIds);
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$list})");
        $db->query("UPDATE reservaciones SET reemplaza_reservacion_id = NULL WHERE id IN ({$list})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$list})");
    }
};

$findFreeDate = static function () use ($query, $escape): string {
    for ($offset = 3; $offset <= 85; $offset++) {
        $date = (new DateTimeImmutable('2026-11-01', ReservacionConfig::timezone()))
            ->modify('+' . $offset . ' days')->format('Y-m-d');
        $safe = $escape($date);
        $value = $query("SELECT
            (SELECT COUNT(*) FROM reservaciones WHERE fecha = '{$safe}') AS reservations,
            (SELECT COUNT(*) FROM excepciones_operacion WHERE fecha = '{$safe}') AS exceptions")->fetch_assoc();
        if ((int)($value['reservations'] ?? 0) === 0 && (int)($value['exceptions'] ?? 0) === 0) {
            return $date;
        }
    }
    throw new RuntimeException('No se encontró fecha libre para la prueba pública.');
};

try {
    $mesas = [];
    $result = $query("SELECT id, numero FROM mesas
        WHERE activo = 1 AND reservable = 1 AND tipo = 'mesa'
        ORDER BY numero, id");
    while ($value = $result->fetch_assoc()) {
        $mesas[(int)$value['numero']] = (int)$value['id'];
    }
    $result->free();
    $mesaIds = array_values(array_intersect_key($mesas, array_flip([2, 3, 4, 7])));
    if (count($mesaIds) < 4) {
        throw new RuntimeException('La instalación no tiene cuatro mesas de fixture.');
    }
    $mesaA = (int)$mesaIds[0];
    $mesaB = (int)$mesaIds[1];
    $mesaC = (int)$mesaIds[2];
    $mesaD = (int)$mesaIds[3];

    // Publicación y confirmación OTP contra el mismo núcleo de persistencia.
    $publicDate = $findFreeDate();
    $publicToken = $marker . '_PUBLIC_' . bin2hex(random_bytes(5));
    $public = ReservacionPublicaService::crearRetencion([
        'nombre' => $marker . ' Publica',
        'tipo_contacto' => 'email',
        'contacto' => strtolower($marker) . '@example.test',
        'fecha' => $publicDate,
        'hora' => '15:00',
        'personas' => 2,
        'notas' => 'Etapa 10',
        'request_token' => $publicToken,
    ]);
    $publicRow = $row("SELECT * FROM reservaciones WHERE request_token = '" . $escape($publicToken) . "'");
    if ($publicRow) {
        $fixtureReservationIds[] = (int)$publicRow['id'];
    }
    $confirmed = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => strtolower($marker) . '@example.test',
        'codigo' => (string)($public['preview_code'] ?? ''),
        'request_token' => $publicToken,
    ]);
    $publicAfter = $row('SELECT estado FROM reservaciones WHERE id = ' . (int)($publicRow['id'] ?? 0));
    $assert('public_create_confirm', ($public['ok'] ?? false) && ($confirmed['ok'] ?? false)
        && ($publicAfter['estado'] ?? '') === 'confirmada', json_encode(['create' => $public, 'confirm' => $confirmed], JSON_UNESCAPED_UNICODE));

    // Alta administrativa sin mesas y asignación posterior desde el mapa.
    $adminDate = $findFreeDate();
    $adminToken = $marker . '_ADMIN_' . bin2hex(random_bytes(5));
    $admin = ReservacionAdministrativaService::crear([
        'nombre' => $marker . ' Admin',
        'contacto_tipo' => 'email',
        'contacto' => strtolower($marker) . '-admin@example.test',
        'fecha' => $adminDate,
        'hora' => '15:00',
        'comensales' => 2,
        'nota' => '',
        'comentario_admin' => 'Etapa 10',
        'request_token' => $adminToken,
        'asignar_automaticamente' => '0',
        'confirmaciones' => [ReservacionAdministrativaService::SIN_ASIGNACION],
    ], 1);
    $adminId = (int)($admin['id'] ?? 0);
    if ($adminId > 0) {
        $fixtureReservationIds[] = $adminId;
        $adminRow = $row('SELECT id, fecha, hora, updated_at, created_at FROM reservaciones WHERE id = ' . $adminId);
        $version = hash('sha256', (string)($adminRow['updated_at'] ?: $adminRow['created_at']) . '|');
        $map = ReservacionMapaAdministrativaService::guardarAsignacion($adminId, [$mesaD], [
            'fecha_esperada' => (string)$adminRow['fecha'],
            'hora_esperada' => (string)$adminRow['hora'],
            'mesa_ids_actuales' => [],
            'version_esperada' => $version,
            'validar_contexto' => true,
            'contexto_completo' => true,
        ]);
    } else {
        $map = ['ok' => false, 'codigo' => 'ADMIN_CREATE_FAILED'];
    }
    $assert('admin_create_map_assign', ($admin['ok'] ?? false) && ($map['ok'] ?? false)
        && ReservacionMesa::obtenerIdsPorReservacion($adminId) === [$mesaD], json_encode(['admin' => $admin, 'map' => $map], JSON_UNESCAPED_UNICODE));

    // Ciclo POS: una mesa y multimesa, incluyendo idempotencia, cierre e historial.
    $startId = $insertReservation('START', '2026-11-01', '12:00:00');
    $assign($startId, [$mesaA]);
    $multiId = $insertReservation('MULTI', '2026-11-01', '12:00:00');
    $assign($multiId, [$mesaB, $mesaC]);
    $start = PuntoVentaReservacionService::comenzar($startId, 1, null);
    $multiStart = PuntoVentaReservacionService::comenzar($multiId, 1, null);
    $startRepeat = PuntoVentaReservacionService::comenzar($startId, 1, null);
    $startTicket = (int)($start['ticket_id'] ?? 0);
    $multiTicket = (int)($multiStart['ticket_id'] ?? 0);
    $startTicketCount = (int)($row("SELECT COUNT(*) AS total FROM ticket_mesas WHERE ticket_id = {$startTicket}")['total'] ?? 0);
    $multiTicketCount = (int)($row("SELECT COUNT(*) AS total FROM ticket_mesas WHERE ticket_id = {$multiTicket}")['total'] ?? 0);
    $assert('pos_start_single_multi', ($start['ok'] ?? false) && ($multiStart['ok'] ?? false)
        && $startTicketCount === 1 && $multiTicketCount === 2
        && ($startRepeat['ok'] ?? false) && ($startRepeat['idempotente'] ?? false), json_encode(['start' => $start, 'multi' => $multiStart, 'repeat' => $startRepeat], JSON_UNESCAPED_UNICODE));

    $closeStart = PuntoVentaReservacionService::cerrarTicket($startTicket, 'efectivo', 0.0, [], 1);
    $closeMulti = PuntoVentaReservacionService::cerrarTicket($multiTicket, 'tarjeta', 0.0, [], 1);
    $closeRepeat = PuntoVentaReservacionService::cerrarTicket($startTicket, 'efectivo', 0.0, [], 1);
    $states = $row("SELECT
        (SELECT estado FROM reservaciones WHERE id = {$startId}) AS one_state,
        (SELECT estado FROM reservaciones WHERE id = {$multiId}) AS multi_state,
        (SELECT COUNT(*) FROM tickets WHERE id IN ({$startTicket}, {$multiTicket}) AND estado = 'cerrado') AS closed");
    $assert('pos_close_complete_idempotent', ($closeStart['ok'] ?? false) && ($closeMulti['ok'] ?? false)
        && ($closeRepeat['ok'] ?? false) && ($closeRepeat['idempotente'] ?? false)
        && ($states['one_state'] ?? '') === 'completada' && ($states['multi_state'] ?? '') === 'completada'
        && (int)($states['closed'] ?? 0) === 2, json_encode(['one' => $closeStart, 'multi' => $closeMulti, 'repeat' => $closeRepeat], JSON_UNESCAPED_UNICODE));
    $history = $row("SELECT COUNT(*) AS total FROM ticket_mesas WHERE ticket_id = {$multiTicket}");
    $assert('ticket_mesas_history', (int)($history['total'] ?? 0) === 2);

    // No-show sólo tras la tolerancia y sin borrar la relación histórica.
    $noShowId = $insertReservation('NO_SHOW', '2026-11-01', '11:00:00');
    $assign($noShowId, [$mesaD]);
    $noShowBefore = (int)($row("SELECT COUNT(*) AS total FROM reservacion_mesas WHERE reservacion_id = {$noShowId}")['total'] ?? 0);
    $noShow = PuntoVentaReservacionService::noShow($noShowId, 1, false, false, 'Cliente no se presentó');
    $noShowAfter = $row("SELECT estado, (SELECT COUNT(*) FROM reservacion_mesas WHERE reservacion_id = {$noShowId}) AS mesas FROM reservaciones WHERE id = {$noShowId}");
    $occupancy = ReservacionMesa::obtenerOcupacionDelDia('2026-11-01');
    $stillOccupies = array_filter($occupancy, static fn(array $item): bool => (int)$item['reservacion_id'] === $noShowId);
    $assert('no_show_releases_capacity_preserves_history', ($noShow['ok'] ?? false)
        && ($noShowAfter['estado'] ?? '') === 'no_show'
        && (int)($noShowAfter['mesas'] ?? 0) === $noShowBefore && $stillOccupies === [], json_encode($noShow, JSON_UNESCAPED_UNICODE));

    // Cancelación administrativa expira un reemplazo pendiente e invalida su OTP.
    $originalId = $insertReservation('ORIGINAL', '2026-11-01', '13:00:00');
    $assign($originalId, [$mesaA]);
    $replacementId = $insertReservation('REPLACEMENT', '2026-11-01', '14:00:00', 'pendiente_verificacion', '2026-11-01 12:15:00', $originalId, 'landing');
    $query("INSERT INTO verificaciones_contacto
        (reservacion_id, contacto_tipo, contacto, codigo_hash, expires_at)
        VALUES ({$replacementId}, 'email', '" . $escape(strtolower($marker) . '-replacement@example.test') . "',
        '" . $escape(password_hash('123456', PASSWORD_DEFAULT)) . "', '2026-11-01 12:05:00')");
    $cancel = ReservacionAdministrativaService::cancelar($originalId, 1, 'Prueba Etapa 10');
    $replacementAfter = $row("SELECT estado, hold_expires_at,
        (SELECT COUNT(*) FROM verificaciones_contacto WHERE reservacion_id = {$replacementId} AND invalidated_at IS NOT NULL) AS invalidated
        FROM reservaciones WHERE id = {$replacementId}");
    $assert('cancel_expires_pending_replacement', ($cancel['ok'] ?? false)
        && ($replacementAfter['estado'] ?? '') === 'expirada'
        && $replacementAfter['hold_expires_at'] === null
        && (int)($replacementAfter['invalidated'] ?? 0) === 1, json_encode(['cancel' => $cancel, 'replacement' => $replacementAfter], JSON_UNESCAPED_UNICODE));

    // Exactitud del borde hold_expires_at = ahora.
    $holdId = $insertReservation('HOLD_EDGE', '2026-11-02', '15:00:00', 'pendiente_verificacion', '2026-11-01 12:00:00', null, 'landing');
    $expired = ReservacionPublicaService::expirarRetenciones();
    $holdAfter = $row("SELECT estado FROM reservaciones WHERE id = {$holdId}");
    $assert('hold_exact_boundary_expires', ($expired['ok'] ?? false) && ($holdAfter['estado'] ?? '') === 'expirada', json_encode($expired, JSON_UNESCAPED_UNICODE));

    // Reasignación previa permitida; después de iniciar servicio queda bloqueada.
    $reassignId = $insertReservation('REASSIGN', '2026-11-01', '13:00:00');
    $assign($reassignId, [$mesaB]);
    $reassignRow = $row("SELECT id, fecha, hora, updated_at, created_at FROM reservaciones WHERE id = {$reassignId}");
    $reassignVersion = hash('sha256', (string)($reassignRow['updated_at'] ?: $reassignRow['created_at']) . '|' . $mesaB);
    $reassign = ReservacionMapaAdministrativaService::guardarAsignacion($reassignId, [$mesaC], [
        'fecha_esperada' => (string)$reassignRow['fecha'],
        'hora_esperada' => (string)$reassignRow['hora'],
        'mesa_ids_actuales' => [$mesaB],
        'version_esperada' => $reassignVersion,
        'validar_contexto' => true,
        'contexto_completo' => true,
    ]);
    $assert('reassignment_before_service', ($reassign['ok'] ?? false) && ReservacionMesa::obtenerIdsPorReservacion($reassignId) === [$mesaC], json_encode($reassign, JSON_UNESCAPED_UNICODE));
    $lockedId = $insertReservation('LOCKED', '2026-11-01', '12:00:00');
    $assign($lockedId, [$mesaB]);
    $lockedStart = PuntoVentaReservacionService::comenzar($lockedId, 1, null);
    $lockedRow = $row("SELECT id, fecha, hora, updated_at, created_at FROM reservaciones WHERE id = {$lockedId}");
    $lockedVersion = hash('sha256', (string)($lockedRow['updated_at'] ?: $lockedRow['created_at']) . '|' . $mesaB);
    $reassignAfterStart = ReservacionMapaAdministrativaService::guardarAsignacion($lockedId, [$mesaC], [
        'fecha_esperada' => (string)$lockedRow['fecha'],
        'hora_esperada' => (string)$lockedRow['hora'],
        'mesa_ids_actuales' => [$mesaB],
        'version_esperada' => $lockedVersion,
        'validar_contexto' => true,
        'contexto_completo' => true,
    ]);
    $assert('reassignment_after_start_rejected', ($lockedStart['ok'] ?? false)
        && !($reassignAfterStart['ok'] ?? false)
        && in_array((string)($reassignAfterStart['codigo'] ?? ''), [AsignacionMesasService::RESERVACION_NO_EDITABLE, AsignacionMesasService::ESTADO_INVALIDO], true), json_encode($reassignAfterStart, JSON_UNESCAPED_UNICODE));
    if (($lockedStart['ticket_id'] ?? 0) > 0) {
        PuntoVentaReservacionService::cerrarTicket((int)$lockedStart['ticket_id'], 'efectivo', 0.0, [], 1);
    }

    // Reconciliación de invariantes de lectura, sin reparar datos.
    $orphanOpen = (int)($row("SELECT COUNT(*) AS total FROM tickets t
        LEFT JOIN reservaciones r ON r.id = t.reservacion_id
        WHERE t.estado = 'abierto' AND t.closed_at IS NULL AND t.reservacion_id IS NOT NULL
          AND (r.id IS NULL OR r.estado <> 'en_curso')")['total'] ?? 0);
    $openWithoutMesa = (int)($row("SELECT COUNT(*) AS total FROM tickets t
        LEFT JOIN ticket_mesas tm ON tm.ticket_id = t.id
        WHERE t.estado = 'abierto' AND t.closed_at IS NULL AND tm.ticket_id IS NULL")['total'] ?? 0);
    $cancelledOpen = (int)($row("SELECT COUNT(*) AS total FROM reservaciones r
        INNER JOIN tickets t ON t.reservacion_id = r.id
        WHERE r.estado IN ('cancelada', 'no_show', 'expirada', 'reemplazada')
          AND t.estado = 'abierto' AND t.closed_at IS NULL")['total'] ?? 0);
    $assert('reconciliation_read_only', $orphanOpen === 0 && $openWithoutMesa === 0 && $cancelledOpen === 0, json_encode([
        'open_linked_invalid_state' => $orphanOpen,
        'open_without_ticket_mesas' => $openWithoutMesa,
        'terminal_with_open_ticket' => $cancelledOpen,
    ], JSON_UNESCAPED_UNICODE));
} catch (Throwable $error) {
    $failed[] = 'EXCEPCION: ' . $error->getMessage();
} finally {
    try {
        $cleanup();
    } catch (Throwable $cleanupError) {
        $failed[] = 'LIMPIEZA: ' . $cleanupError->getMessage();
    }
}

$result = [
    'ok' => $failed === [],
    'suite' => 'etapa10_integracion_operativa',
    'database' => $database,
    'passed' => $passed,
    'failed' => $failed,
    'cases' => $cases,
    'contract' => 'pos-reservacion.v1',
    'states' => ['pendiente_verificacion', 'confirmada', 'en_curso', 'completada', 'cancelada', 'no_show', 'expirada', 'reemplazada'],
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
