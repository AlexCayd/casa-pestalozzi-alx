<?php

declare(strict_types=1);

/** Pruebas del snapshot monotono de asignaciones, sin cambios de esquema. */

$database = '';
foreach ($argv ?? [] as $argument) if (str_starts_with((string)$argument, '--db=')) $database = substr((string)$argument, 5);
if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    fwrite(STDERR, "Uso: php etapa11_5_version_asignaciones.php --db=BASE_DE_PRUEBAS\n");
    exit(2);
}
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('APP_ENV=testing');
putenv('DB_NAME=' . $database);
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\PuntoVentaReservacionService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionMapaAdministrativaService;
use Services\ReservacionPublicaService;
use Services\AsignacionMesasService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) exit(2);
ActiveRecord::setDB($db);
$marker = 'ETAPA115_VERSION_' . strtoupper(bin2hex(random_bytes(4)));
$ids = [];
$ticketIds = [];
$passed = 0;
$failed = [];
$assert = static function (bool $ok, string $message) use (&$passed, &$failed): void { if ($ok) $passed++; else $failed[] = $message; };
$query = static function (string $sql) use ($db): mysqli_result|bool { $r = $db->query($sql); if ($r === false) throw new RuntimeException($db->error); return $r; };
$escape = static fn(string $value): string => $db->real_escape_string($value);
$insert = static function (string $suffix, string $date, string $hour, string $origin = 'admin', string $contactType = 'ninguno', ?string $contact = null) use ($query, $escape, $db, $marker): int {
    $token = $marker . '_' . $suffix . '_' . bin2hex(random_bytes(3));
    $contactSql = $contact === null ? 'NULL' : "'" . $escape($contact) . "'";
    $query("INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token, estado_changed_at)
        VALUES ('" . $escape($marker . '_' . $suffix) . "', '" . $escape($contactType) . "', {$contactSql}, '{$date}', '{$hour}', 2, 'version fixture', '{$origin}', 'confirmada', '{$token}', '2026-11-01 12:00:00')");
    return (int)$db->insert_id;
};
$assign = static function (int $id, array $mesaIds) use ($query): void { $query('DELETE FROM reservacion_mesas WHERE reservacion_id = ' . $id); foreach (array_values($mesaIds) as $order => $mesaId) $query("INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES ({$id}, " . (int)$mesaId . ', ' . ($order + 1) . ')'); };
$context = static function (int $id) use ($query): array {
    $row = $query('SELECT fecha, hora, created_at, updated_at FROM reservaciones WHERE id = ' . $id)->fetch_assoc();
    $mesaIds = ReservacionMesa::obtenerIdsPorReservacion($id); sort($mesaIds, SORT_NUMERIC);
    return ['validar_contexto' => true, 'contexto_completo' => true, 'version_esperada' => hash('sha256', (string)($row['updated_at'] ?: $row['created_at']) . '|' . implode(',', $mesaIds)), 'fecha_esperada' => $row['fecha'], 'hora_esperada' => $row['hora'], 'mesa_ids_actuales' => $mesaIds];
};
$versionTimestamp = static function (int $id) use ($query): string { return (string)($query('SELECT COALESCE(updated_at, created_at) AS version_at FROM reservaciones WHERE id = ' . $id)->fetch_assoc()['version_at'] ?? ''); };

try {
    // El DML trae tickets operativos abiertos de demostracion; se cierran
    // para que la prueba de versionado no dependa de ocupacion heredada.
    $query("UPDATE tickets SET estado = 'cerrado', closed_at = '2026-11-01 12:00:00' WHERE estado = 'abierto' AND closed_at IS NULL");
    $tableResult = $query("SELECT id FROM mesas WHERE activo = 1 AND reservable = 1 AND tipo = 'mesa' ORDER BY numero LIMIT 6");
    $tables = []; while ($row = $tableResult->fetch_assoc()) $tables[] = (int)$row['id']; $tableResult->free();
    if (count($tables) < 4) throw new RuntimeException('Faltan mesas de versionado.');
    [$t1, $t2, $t3, $t4] = array_slice($tables, 0, 4);

    $initial = $insert('INITIAL', '2026-11-12', '14:00');
    $beforeInitial = $versionTimestamp($initial);
    $initialResult = AsignacionMesasService::asignarManual($initial, [$t1]);
    $afterInitial = $versionTimestamp($initial);
    $assert(($initialResult['ok'] ?? false) && ReservacionMesa::obtenerIdsPorReservacion($initial) === [$t1], 'asignacion inicial guarda mesas');
    $assert($afterInitial !== '' && $afterInitial >= $beforeInitial, 'asignacion inicial avanza version');

    $sameContext = $context($initial);
    $same = ReservacionMapaAdministrativaService::guardarAsignacion($initial, [$t1], $sameContext);
    $afterSame = $versionTimestamp($initial);
    $assert(($same['ok'] ?? false) && ReservacionMesa::obtenerIdsPorReservacion($initial) === [$t1], 'reasignacion identica conserva consistencia');
    $assert($afterSame >= $afterInitial, 'reasignacion identica no retrocede version');

    $reassignContext = $context($initial);
    $reassign = ReservacionMapaAdministrativaService::guardarAsignacion($initial, [$t2], $reassignContext);
    $afterReassign = $versionTimestamp($initial);
    $assert(($reassign['ok'] ?? false) && ReservacionMesa::obtenerIdsPorReservacion($initial) === [$t2], 'reasignacion cambia pivot');
    $assert($afterReassign > $afterSame, 'reasignacion avanza version monotonicamente');

    $staleA = $context($initial); $staleB = $staleA;
    $first = ReservacionMapaAdministrativaService::guardarAsignacion($initial, [$t3], $staleA);
    $second = ReservacionMapaAdministrativaService::guardarAsignacion($initial, [$t4], $staleB);
    $assert(($first['ok'] ?? false) && ($second['codigo'] ?? '') === AsignacionMesasService::VERSION_DESACTUALIZADA, 'dos snapshots iguales tienen un solo ganador');

    $releaseContext = $context($initial); $beforeRelease = $versionTimestamp($initial);
    $release = ReservacionMapaAdministrativaService::liberarAsignacion($initial, $releaseContext + ['confirmaciones' => [AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL]]);
    $afterRelease = $versionTimestamp($initial);
    $assert(($release['ok'] ?? false) && ReservacionMesa::obtenerIdsPorReservacion($initial) === [], 'liberacion elimina pivot ' . json_encode(['resultado' => $release, 'contexto' => $releaseContext], JSON_UNESCAPED_UNICODE));
    $assert($afterRelease > $beforeRelease, 'liberacion avanza version: ' . $beforeRelease . ' -> ' . $afterRelease . ' ' . json_encode($release, JSON_UNESCAPED_UNICODE));

    $startReservation = $insert('START_INVALIDATES', '2026-11-01', '12:30'); $assign($startReservation, [$t1]); $startContext = $context($startReservation);
    $started = PuntoVentaReservacionService::comenzar($startReservation, 1, null); $startTicket = (int)($started['ticket_id'] ?? 0); if ($startTicket) $ticketIds[] = $startTicket;
    $startEdit = ReservacionMapaAdministrativaService::guardarAsignacion($startReservation, [$t2], $startContext);
    $assert(($started['ok'] ?? false) && !($startEdit['ok'] ?? false), 'inicio POS invalida edicion de asignacion: ' . json_encode(['start' => $started, 'edit' => $startEdit], JSON_UNESCAPED_UNICODE));

    $cancelReservation = $insert('CANCEL_INVALIDATES', '2026-11-13', '14:00'); $assign($cancelReservation, [$t2]); $cancelContext = $context($cancelReservation);
    $cancel = ReservacionAdministrativaService::cancelar($cancelReservation, 1, 'Version fixture');
    $cancelEdit = ReservacionMapaAdministrativaService::guardarAsignacion($cancelReservation, [$t3], $cancelContext);
    $assert(($cancel['ok'] ?? false) && !($cancelEdit['ok'] ?? false), 'cancelacion invalida edicion de asignacion');

    $noShowReservation = $insert('NOSHOW_INVALIDATES', '2026-11-01', '11:00'); $assign($noShowReservation, [$t3]); $noShowContext = $context($noShowReservation);
    $noShow = PuntoVentaReservacionService::noShow($noShowReservation, 1, false, false, 'Version fixture');
    $noShowEdit = ReservacionMapaAdministrativaService::guardarAsignacion($noShowReservation, [$t4], $noShowContext);
    $assert(($noShow['ok'] ?? false) && !($noShowEdit['ok'] ?? false), 'no show invalida edicion de asignacion');

    $contact = strtolower($marker) . '@example.test';
    $original = $insert('REPLACED_ORIGINAL', '2026-11-14', '14:00', 'landing', 'email', $contact); $assign($original, [$t4]); $replacementToken = $marker . '_REPLACEMENT';
    $replacement = ReservacionPublicaService::crearReemplazo(['reservacion_id' => $original, 'fecha' => '2026-11-15', 'hora' => '14:00', 'personas' => 2, 'notas' => 'version', 'request_token' => $replacementToken], ['contacto_tipo' => 'email', 'contacto' => $contact]);
    $replacementRow = $query("SELECT id FROM reservaciones WHERE request_token = '" . $escape($replacementToken) . "'")->fetch_assoc();
    $replacementId = (int)($replacementRow['id'] ?? 0); if ($replacementId) $ids[] = $replacementId;
    $replacementConfirm = ReservacionPublicaService::confirmarReemplazo(['request_token' => $replacementToken, 'codigo' => (string)($replacement['preview_code'] ?? '')], ['contacto_tipo' => 'email', 'contacto' => $contact]);
    $replacementEdit = ReservacionMapaAdministrativaService::guardarAsignacion($original, [$t1], $context($original));
    $assert(($replacement['ok'] ?? false) && ($replacementConfirm['ok'] ?? false) && !($replacementEdit['ok'] ?? false), 'reemplazo invalida edicion de original');

    $assert($afterReassign >= $afterInitial && $afterRelease > $beforeRelease, 'version nunca retrocede');
} catch (Throwable $error) {
    $failed[] = 'EXCEPCION: ' . $error->getMessage();
}

try {
    $like = $escape($marker . '%');
    $tickets = $db->query("SELECT id FROM tickets WHERE nombre LIKE '{$like}'");
    $ticketList = []; if ($tickets) { while ($row = $tickets->fetch_assoc()) $ticketList[] = (int)$row['id']; $tickets->free(); }
    if ($ticketList !== []) { $list = implode(',', $ticketList); $db->query("DELETE FROM ticket_pagos WHERE ticket_id IN ({$list})"); $db->query("DELETE FROM ticket_items WHERE ticket_id IN ({$list})"); $db->query("DELETE FROM ticket_mesas WHERE ticket_id IN ({$list})"); $db->query("DELETE FROM tickets WHERE id IN ({$list})"); }
    $rows = $db->query("SELECT id FROM reservaciones WHERE nombre LIKE '{$like}' OR request_token LIKE '{$like}'");
    $reservationList = []; if ($rows) { while ($row = $rows->fetch_assoc()) $reservationList[] = (int)$row['id']; $rows->free(); }
    if ($reservationList !== []) {
        $list = implode(',', array_unique($reservationList));
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservaciones WHERE reemplaza_reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$list})");
    }
} catch (Throwable $error) { $failed[] = 'LIMPIEZA: ' . $error->getMessage(); }

$ok = $failed === [];
echo json_encode(['ok' => $ok, 'suite' => 'etapa11_5_version_asignaciones', 'passed' => $passed, 'failed' => $failed, 'cases' => $passed + count($failed)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
