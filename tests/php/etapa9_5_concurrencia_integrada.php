<?php

declare(strict_types=1);

/** Matriz multiproceso de Etapa 9.5. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

$database = '';
foreach ($argv ?? [] as $argumento) {
    if (str_starts_with((string)$argumento, '--db=')) {
        $database = substr((string)$argumento, 5);
    }
}
if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1) {
    fwrite(STDERR, "Uso: php etapa9_5_concurrencia_integrada.php --db=BASE_DE_PRUEBAS\n");
    exit(2);
}
if (in_array($database, ['casa-pestalozzi', 'casa_pestalozzi'], true)) {
    fwrite(STDERR, "La suite no permite apuntar a la base activa.\n");
    exit(2);
}

$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);
ini_set('session.save_path', dirname(__DIR__));
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\ReservacionPublicaService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No hay conexion MySQL para la suite de Etapa 9.5.\n");
    exit(2);
}
ActiveRecord::setDB($db);

$runId = strtoupper(bin2hex(random_bytes(5)));
$fixturePrefix = 'ETAPA95_' . $runId;
$contactPrefix = strtolower($fixturePrefix) . '-';
$workerPath = __DIR__ . '/etapa9_5_concurrencia_worker.php';
$barriers = [];
$passed = 0;
$failed = [];
$escenarios = [];
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
    } else {
        $failed[] = $message;
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
$rowById = static function (int $id) use ($query): ?array {
    if ($id < 1) return null;
    $result = $query('SELECT * FROM reservaciones WHERE id = ' . $id . ' LIMIT 1');
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};
$rowByName = static function (string $name) use ($query, $escape): ?array {
    $name = $escape($name);
    $result = $query("SELECT * FROM reservaciones WHERE nombre = '{$name}' ORDER BY id DESC LIMIT 1");
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};
$mesaIds = static function (int $reservacionId): array {
    $ids = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
    sort($ids, SORT_NUMERIC);
    return array_values(array_map('intval', $ids));
};
$ticketByReservation = static function (int $reservacionId) use ($query): ?array {
    $result = $query('SELECT * FROM tickets WHERE reservacion_id = ' . $reservacionId . ' ORDER BY id DESC LIMIT 1');
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};
$ticketMesaIds = static function (int $ticketId) use ($query): array {
    $result = $query('SELECT mesa_id FROM ticket_mesas WHERE ticket_id = ' . $ticketId . ' ORDER BY orden, mesa_id');
    $ids = [];
    while ($row = $result->fetch_assoc()) $ids[] = (int)$row['mesa_id'];
    $result->free();
    sort($ids, SORT_NUMERIC);
    return $ids;
};
$insertReservation = static function (
    string $suffix,
    string $fecha,
    string $hora,
    string $estado = 'confirmada',
    string $origen = 'admin',
    string $contactoTipo = 'ninguno',
    ?string $contacto = null,
    ?int $reemplazaId = null
) use ($db, $escape, $fixturePrefix): int {
    $nombre = $fixturePrefix . '_' . $suffix;
    $token = $fixturePrefix . '_' . $suffix . '_' . strtolower(bin2hex(random_bytes(4)));
    $contactoSql = $contacto === null ? 'NULL' : "'" . $escape($contacto) . "'";
    $reemplazaSql = $reemplazaId === null ? 'NULL' : (string)$reemplazaId;
    $sql = "INSERT INTO reservaciones
        (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
         comentario_admin, origen, request_token, hold_expires_at, estado,
         reemplaza_reservacion_id, estado_changed_at)
        VALUES ('" . $escape($nombre) . "', '" . $escape($contactoTipo) . "', {$contactoSql},
         '" . $escape($fecha) . "', '" . $escape($hora) . "', 2,
         'Fixture controlado Etapa 9.5', '', '" . $escape($origen) . "',
         '" . $escape($token) . "', NULL, '" . $escape($estado) . "',
         {$reemplazaSql}, '2026-11-01 12:00:00')";
    if (!$db->query($sql)) throw new RuntimeException('No se pudo crear fixture ' . $nombre . ': ' . $db->error);
    return (int)$db->insert_id;
};
$assign = static function (int $reservacionId, array $ids) use ($db): void {
    $db->query('DELETE FROM reservacion_mesas WHERE reservacion_id = ' . $reservacionId);
    foreach (array_values(array_map('intval', $ids)) as $orden => $mesaId) {
        if ($mesaId < 1) continue;
        if (!$db->query('INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES (' . $reservacionId . ', ' . $mesaId . ', ' . ($orden + 1) . ')')) {
            throw new RuntimeException('No se pudo asignar fixture: ' . $db->error);
        }
    }
};

$tablesResult = $query("SELECT id FROM mesas WHERE activo = 1 AND reservable = 1 AND tipo = 'mesa' ORDER BY numero LIMIT 8");
$tables = [];
while ($row = $tablesResult->fetch_assoc()) $tables[] = (int)$row['id'];
$tablesResult->free();
if (count($tables) < 4) {
    fwrite(STDERR, "La instalacion no tiene cuatro mesas reservables para las carreras.\n");
    exit(2);
}
[$t1, $t2, $t3, $t4] = array_slice($tables, 0, 4);
$openTicketIds = [];
$openTickets = $query("SELECT id FROM tickets WHERE estado = 'abierto' AND closed_at IS NULL");
while ($row = $openTickets->fetch_assoc()) $openTicketIds[] = (int)$row['id'];
$openTickets->free();
if ($openTicketIds !== []) {
    $query("UPDATE tickets SET estado = 'cerrado', closed_at = '2026-11-01 12:00:00' WHERE id IN (" . implode(',', $openTicketIds) . ")");
}
$restoreOpenTickets = static function () use ($db, $openTicketIds): void {
    if ($openTicketIds !== []) {
        $db->query("UPDATE tickets SET estado = 'abierto', closed_at = NULL WHERE id IN (" . implode(',', $openTicketIds) . ")");
    }
};
$workerArg = static function (string $key, mixed $value): string {
    if (is_array($value)) $value = json_encode(array_values($value), JSON_UNESCAPED_SLASHES);
    return '--' . $key . '=' . escapeshellarg((string)$value);
};
$runRace = static function (string $name, array $specs) use (&$barriers, $database, $workerPath, $workerArg): array {
    $barrier = __DIR__ . '/.etapa9_5_' . strtolower($name) . '_' . strtolower(bin2hex(random_bytes(4))) . '.start';
    $barriers[] = $barrier;
    $processes = [];
    $outputs = [];
    $base = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath)
        . ' ' . $workerArg('db', $database)
        . ' ' . $workerArg('scenario', $name)
        . ' ' . $workerArg('barrier', $barrier)
        . ' ' . $workerArg('now', '2026-11-01 12:00:00');
    foreach ($specs as $spec) {
        $command = $base;
        foreach ($spec as $key => $value) $command .= ' ' . $workerArg((string)$key, $value);
        $pipes = [];
        $resource = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        $processes[] = ['resource' => $resource, 'pipes' => $pipes];
    }
    if (!touch($barrier)) throw new RuntimeException('No se pudo abrir la barrera de ' . $name . '.');
    $deadline = microtime(true) + 25;
    do {
        $running = false;
        foreach ($processes as $process) {
            if (is_resource($process['resource']) && proc_get_status($process['resource'])['running']) $running = true;
        }
        if ($running && microtime(true) < $deadline) usleep(10000);
    } while ($running && microtime(true) < $deadline);
    foreach ($processes as $process) {
        if (is_resource($process['resource']) && proc_get_status($process['resource'])['running']) proc_terminate($process['resource']);
        if (!isset($process['pipes'][1])) {
            $outputs[] = ['ok_proceso' => false, 'error' => 'WORKER_NO_INICIADO'];
            continue;
        }
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][0]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exit = is_resource($process['resource']) ? proc_close($process['resource']) : 1;
        $payload = json_decode(trim((string)$stdout), true);
        $outputs[] = is_array($payload)
            ? $payload + ['exit_code' => $exit, 'stderr' => trim((string)$stderr)]
            : ['ok_proceso' => false, 'raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr), 'exit_code' => $exit];
    }
    @unlink($barrier);
    return $outputs;
};
$resultado = static function (array $workers, string $kind): ?array {
    foreach ($workers as $worker) if (($worker['kind'] ?? '') === $kind) return is_array($worker['resultado'] ?? null) ? $worker['resultado'] : null;
    return null;
};
$okResultado = static fn(?array $resultado): bool => ($resultado['ok'] ?? false) === true;

try {
    // A: reasignacion contra inicio POS.
    $a = $insertReservation('A_ORIGINAL', '2026-11-01', '12:10'); $assign($a, [$t1]);
    $aWorkers = $runRace('A_reasignacion_vs_pos', [
        ['kind' => 'map_assign', 'reservation-id' => $a, 'target-ids' => [$t2]],
        ['kind' => 'pos_start', 'reservation-id' => $a],
    ]);
    $aMap = $resultado($aWorkers, 'map_assign'); $aPos = $resultado($aWorkers, 'pos_start'); $aRow = $rowById($a); $aTicket = $ticketByReservation($a); $aIds = $mesaIds($a);
    $aValid = ($okResultado($aMap) && $okResultado($aPos))
        ? (($aRow['estado'] ?? '') === 'en_curso' && $aIds === [$t2] && $aTicket !== null && $ticketMesaIds((int)$aTicket['id']) === [$t2])
        : (($okResultado($aPos) && !$okResultado($aMap))
            ? (($aRow['estado'] ?? '') === 'en_curso' && $aIds === [$t1] && $aTicket !== null && $ticketMesaIds((int)$aTicket['id']) === [$t1])
            : ($okResultado($aMap) && !$okResultado($aPos) && ($aRow['estado'] ?? '') === 'confirmada' && $aIds === [$t2] && $aTicket === null));
    $assert($aValid, 'A: combinacion invalida: ' . json_encode($aWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['A_reasignacion_vs_pos'] = ['workers' => $aWorkers, 'estado_final' => $aRow, 'mesa_ids' => $aIds, 'ok' => $aValid];

    // B: liberar contra inicio POS.
    $b = $insertReservation('B_ORIGINAL', '2026-11-01', '12:20'); $assign($b, [$t3]);
    $bWorkers = $runRace('B_liberacion_vs_pos', [
        ['kind' => 'release', 'reservation-id' => $b], ['kind' => 'pos_start', 'reservation-id' => $b],
    ]);
    $bRelease = $resultado($bWorkers, 'release'); $bPos = $resultado($bWorkers, 'pos_start'); $bRow = $rowById($b); $bTicket = $ticketByReservation($b); $bIds = $mesaIds($b);
    $bValid = ($okResultado($bRelease) && !$okResultado($bPos))
        ? (($bRow['estado'] ?? '') === 'confirmada' && $bIds === [] && $bTicket === null)
        : (!$okResultado($bRelease) && $okResultado($bPos) && ($bRow['estado'] ?? '') === 'en_curso' && $bIds === [$t3] && $bTicket !== null && $ticketMesaIds((int)$bTicket['id']) === [$t3]);
    $assert($bValid, 'B: combinacion invalida: ' . json_encode($bWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['B_liberacion_vs_pos'] = ['workers' => $bWorkers, 'estado_final' => $bRow, 'mesa_ids' => $bIds, 'ok' => $bValid];

    // C: asignar manualmente contra hold publico.
    $c = $insertReservation('C_ORIGINAL', '2026-11-02', '13:00'); $cContact = $contactPrefix . 'c@example.test'; $cToken = $fixturePrefix . '_C_PUBLIC_TOKEN';
    $cWorkers = $runRace('C_mapa_vs_hold_publico', [
        ['kind' => 'map_assign', 'reservation-id' => $c, 'target-ids' => [$t1]],
        ['kind' => 'public_hold', 'name' => $fixturePrefix . '_C_PUBLIC', 'contact' => $cContact, 'date' => '2026-11-02', 'hour' => '13:00', 'token' => $cToken],
    ]);
    $cMap = $resultado($cWorkers, 'map_assign'); $cHold = $resultado($cWorkers, 'public_hold'); $cPublic = $rowByName($fixturePrefix . '_C_PUBLIC'); $cRow = $rowById($c);
    $cPublicIds = $cPublic !== null ? $mesaIds((int)$cPublic['id']) : [];
    $cValid = ($okResultado($cMap) && $okResultado($cHold))
        ? ($cPublic !== null && $mesaIds($c) === [$t1] && array_intersect($mesaIds($c), $cPublicIds) === [])
        : (($okResultado($cMap) && !$okResultado($cHold))
            ? ($mesaIds($c) === [$t1] && $cPublic === null)
            : (!$okResultado($cMap) && $okResultado($cHold) && $mesaIds($c) === [] && $cPublic !== null && $cPublicIds === [$t1]));
    $assert($cValid, 'C: duplicacion de mesa: ' . json_encode($cWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['C_mapa_vs_hold_publico'] = ['workers' => $cWorkers, 'estado_final' => $cRow, 'publica' => $cPublic, 'ok' => $cValid];

    // D: asignar manualmente contra alta administrativa automatica.
    $d = $insertReservation('D_ORIGINAL', '2026-11-03', '14:00'); $dName = $fixturePrefix . '_D_NEW'; $dToken = $fixturePrefix . '_D_ADMIN_TOKEN';
    $dWorkers = $runRace('D_mapa_vs_alta_admin', [
        ['kind' => 'map_assign', 'reservation-id' => $d, 'target-ids' => [$t1]],
        ['kind' => 'admin_create', 'name' => $dName, 'date' => '2026-11-03', 'hour' => '14:00', 'token' => $dToken],
    ]);
    $dMap = $resultado($dWorkers, 'map_assign'); $dCreate = $resultado($dWorkers, 'admin_create'); $dNew = $rowByName($dName);
    $dNewIds = $dNew !== null ? $mesaIds((int)$dNew['id']) : [];
    $dValid = ($okResultado($dMap) && $okResultado($dCreate))
        ? ($dNew !== null && $mesaIds($d) === [$t1] && !in_array($t1, $dNewIds, true))
        : (($okResultado($dMap) && !$okResultado($dCreate))
            ? ($mesaIds($d) === [$t1] && $dNew === null)
            : (!$okResultado($dMap) && $okResultado($dCreate) && $dNew !== null && $mesaIds($d) === []));
    $assert($dValid, 'D: duplicacion de mesa: ' . json_encode($dWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['D_mapa_vs_alta_admin'] = ['workers' => $dWorkers, 'nueva' => $dNew, 'ok' => $dValid];

    // E: reasignar contra cancelar.
    $e = $insertReservation('E_ORIGINAL', '2026-11-04', '14:00'); $assign($e, [$t1]);
    $eWorkers = $runRace('E_reasignacion_vs_cancelacion', [
        ['kind' => 'map_assign', 'reservation-id' => $e, 'target-ids' => [$t2]], ['kind' => 'cancel', 'reservation-id' => $e],
    ]);
    $eMap = $resultado($eWorkers, 'map_assign'); $eCancel = $resultado($eWorkers, 'cancel'); $eRow = $rowById($e); $eIds = $mesaIds($e);
    $eValid = $okResultado($eCancel) && ($eRow['estado'] ?? '') === 'cancelada' && in_array($eIds, [[$t1], [$t2]], true) && (!$okResultado($eMap) || $eIds === [$t2]);
    $assert($eValid, 'E: cancelacion/reasignacion invalida: ' . json_encode($eWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['E_reasignacion_vs_cancelacion'] = ['workers' => $eWorkers, 'estado_final' => $eRow, 'mesa_ids' => $eIds, 'ok' => $eValid];

    // F: dos escrituras con la misma version.
    $f = $insertReservation('F_ORIGINAL', '2026-11-05', '14:00'); $assign($f, [$t1]);
    $fWorkers = $runRace('F_dos_asignaciones', [
        ['kind' => 'map_assign', 'reservation-id' => $f, 'target-ids' => [$t3]], ['kind' => 'map_assign', 'reservation-id' => $f, 'target-ids' => [$t4]],
    ]);
    $fSuccesses = array_values(array_filter($fWorkers, static fn(array $worker): bool => ($worker['resultado']['ok'] ?? false) === true)); $fIds = $mesaIds($f);
    $fLoserVersion = count(array_filter($fWorkers, static fn(array $worker): bool => ($worker['resultado']['codigo'] ?? '') === 'VERSION_DESACTUALIZADA')) === 1;
    $fValid = count($fSuccesses) === 1 && $fLoserVersion && in_array($fIds, [[$t3], [$t4]], true);
    $assert($fValid, 'F: no se rechazo la segunda version: ' . json_encode($fWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['F_dos_asignaciones'] = ['workers' => $fWorkers, 'mesa_ids' => $fIds, 'ok' => $fValid];

    // G: liberar contra cancelar.
    $g = $insertReservation('G_ORIGINAL', '2026-11-06', '14:00'); $assign($g, [$t1]);
    $gWorkers = $runRace('G_liberacion_vs_cancelacion', [
        ['kind' => 'release', 'reservation-id' => $g], ['kind' => 'cancel', 'reservation-id' => $g],
    ]);
    $gRelease = $resultado($gWorkers, 'release'); $gCancel = $resultado($gWorkers, 'cancel'); $gRow = $rowById($g); $gIds = $mesaIds($g);
    $gValid = $okResultado($gCancel) && ($gRow['estado'] ?? '') === 'cancelada' && in_array($gIds, [[$t1], []], true) && (!$okResultado($gRelease) || $gIds === []);
    $assert($gValid, 'G: liberar/cancelar invalido: ' . json_encode($gWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['G_liberacion_vs_cancelacion'] = ['workers' => $gWorkers, 'estado_final' => $gRow, 'mesa_ids' => $gIds, 'ok' => $gValid];

    // H: reasignar contra confirmacion de reemplazo publico.
    $hContact = $contactPrefix . 'h@example.test'; $h = $insertReservation('H_ORIGINAL', '2026-11-07', '14:00', 'confirmada', 'landing', 'email', $hContact); $assign($h, [$t1]);
    $hToken = $fixturePrefix . '_H_REPLACEMENT_TOKEN';
    $hReplacement = ReservacionPublicaService::crearReemplazo([
        'reservacion_id' => $h, 'fecha' => '2026-11-08', 'hora' => '14:00', 'personas' => 2,
        'notas' => 'Reemplazo controlado Etapa 9.5', 'request_token' => $hToken,
    ], ['contacto_tipo' => 'email', 'contacto' => $hContact]);
    $hOtp = (string)($hReplacement['preview_code'] ?? ''); $hReplacementId = (int)($hReplacement['replacement']['id'] ?? $hReplacement['id'] ?? $hReplacement['reservacion_id'] ?? 0);
    if ($hOtp === '' || $hReplacementId < 1) throw new RuntimeException('No se pudo preparar H: ' . json_encode($hReplacement, JSON_UNESCAPED_UNICODE));
    $hWorkers = $runRace('H_reasignacion_vs_reemplazo', [
        ['kind' => 'map_assign', 'reservation-id' => $h, 'target-ids' => [$t3]],
        ['kind' => 'confirm_replacement', 'contact' => $hContact, 'token' => $hToken, 'otp' => $hOtp],
    ]);
    $hMap = $resultado($hWorkers, 'map_assign'); $hConfirm = $resultado($hWorkers, 'confirm_replacement'); $hRow = $rowById($h); $hNewRow = $rowById($hReplacementId);
    $hValid = $okResultado($hConfirm)
        ? (($hRow['estado'] ?? '') === 'reemplazada' && ($hNewRow['estado'] ?? '') === 'confirmada')
        : ($okResultado($hMap) && ($hRow['estado'] ?? '') === 'confirmada' && ($hNewRow['estado'] ?? '') === 'pendiente_verificacion' && $mesaIds($h) === [$t3]);
    $assert($hValid, 'H: estados cruzados en reemplazo: ' . json_encode($hWorkers, JSON_UNESCAPED_UNICODE));
    $escenarios['H_reasignacion_vs_reemplazo'] = ['workers' => $hWorkers, 'original' => $hRow, 'reemplazo' => $hNewRow, 'mesa_ids_originales' => $mesaIds($h), 'ok' => $hValid];
} catch (Throwable $error) {
    $failed[] = 'Excepcion de la suite: ' . $error->getMessage();
}

try {
    $nameLike = $escape($fixturePrefix . '%'); $contactLike = $escape($contactPrefix . '%'); $ticketIds = [];
    $tickets = $db->query("SELECT id FROM tickets WHERE nombre LIKE '{$nameLike}'");
    if ($tickets) { while ($row = $tickets->fetch_assoc()) $ticketIds[] = (int)$row['id']; $tickets->free(); }
    if ($ticketIds !== []) { $ids = implode(',', $ticketIds); $db->query("DELETE FROM ticket_mesas WHERE ticket_id IN ({$ids})"); $db->query("DELETE FROM tickets WHERE id IN ({$ids})"); }
    $db->query("DELETE FROM verificaciones_contacto WHERE contacto LIKE '{$contactLike}'");
    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN (SELECT id FROM reservaciones WHERE nombre LIKE '{$nameLike}')");
    $db->query("DELETE FROM reservaciones WHERE nombre LIKE '{$nameLike}' AND reemplaza_reservacion_id IS NOT NULL");
    $db->query("DELETE FROM reservaciones WHERE nombre LIKE '{$nameLike}'");
} finally {
    $restoreOpenTickets();
    foreach ($barriers as $barrier) if (is_file($barrier)) @unlink($barrier);
}

$ok = $failed === [] && count($escenarios) === 8;
echo json_encode([
    'ok' => $ok,
    'fixture_prefix' => $fixturePrefix,
    'pasadas' => $passed,
    'fallidas' => $failed,
    'escenarios' => $escenarios,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
