<?php

declare(strict_types=1);

/** Cobertura multiproceso exacta de las carreras A-L de Etapa 11.5. */

$database = '';
foreach ($argv ?? [] as $argument) {
    if (str_starts_with((string)$argument, '--db=')) $database = substr((string)$argument, 5);
}
if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1
    || in_array($database, ['casa-pestalozzi', 'casa_pestalozzi'], true)) {
    fwrite(STDERR, "Uso: php etapa11_5_concurrencia_completa.php --db=BASE_DE_PRUEBAS\n");
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
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
ini_set('session.save_path', dirname(__DIR__));

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\PuntoVentaReservacionService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No hay conexion MySQL para la suite A-L.\n");
    exit(2);
}
ActiveRecord::setDB($db);

$marker = 'ETAPA115_' . strtoupper(bin2hex(random_bytes(5)));
$contactPrefix = strtolower($marker) . '-';
$workerPath = __DIR__ . '/etapa11_5_concurrencia_worker.php';
$barriers = [];
$reservationIds = [];
$ticketIds = [];
$cases = [];
$failed = [];

$query = static function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) throw new RuntimeException($db->error . ' - ' . $sql);
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
$rowByToken = static function (string $token) use ($query, $escape): ?array {
    $result = $query("SELECT * FROM reservaciones WHERE request_token = '" . $escape($token) . "' LIMIT 1");
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};
$idsForReservation = static function (int $id): array {
    $ids = ReservacionMesa::obtenerIdsPorReservacion($id);
    sort($ids, SORT_NUMERIC);
    return array_values(array_map('intval', $ids));
};
$ticketForReservation = static function (int $id) use ($query): ?array {
    $result = $query('SELECT * FROM tickets WHERE reservacion_id = ' . $id . ' ORDER BY id DESC LIMIT 1');
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
    string $date,
    string $hour,
    string $state = 'confirmada',
    string $origin = 'admin',
    string $contactType = 'ninguno',
    ?string $contact = null,
    ?int $replacementId = null,
    ?string $token = null
) use ($db, $escape, $query, $marker): int {
    $name = $marker . '_' . $suffix;
    $token ??= $marker . '_' . $suffix . '_' . strtolower(bin2hex(random_bytes(4)));
    $contactSql = $contact === null ? 'NULL' : "'" . $escape($contact) . "'";
    $replacementSql = $replacementId === null ? 'NULL' : (string)$replacementId;
    $sql = "INSERT INTO reservaciones
        (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
         comentario_admin, origen, request_token, hold_expires_at, estado,
         reemplaza_reservacion_id, estado_changed_at)
        VALUES ('" . $escape($name) . "', '" . $escape($contactType) . "', {$contactSql},
         '" . $escape($date) . "', '" . $escape($hour) . "', 2,
         'Fixture controlado Etapa 11.5', '', '" . $escape($origin) . "',
         '" . $escape($token) . "', NULL, '" . $escape($state) . "',
         {$replacementSql}, '2026-11-01 12:00:00')";
    $query($sql);
    return (int)$db->insert_id;
};
$assign = static function (int $id, array $mesaIds) use ($query): void {
    $query('DELETE FROM reservacion_mesas WHERE reservacion_id = ' . $id);
    foreach (array_values(array_map('intval', $mesaIds)) as $order => $mesaId) {
        if ($mesaId > 0) $query("INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES ({$id}, {$mesaId}, " . ($order + 1) . ')');
    }
};
$record = static function (string $case, string $race, bool $ok, array $state, array $invariants = []) use (&$cases, &$failed): void {
    $cases[$case] = [
        'caso' => $case,
        'carrera' => $race,
        'suite' => 'etapa11_5_concurrencia_completa.php',
        'resultado' => $ok ? 'PASS' : 'FAIL',
        'estado_final' => $state,
        'invariantes' => $invariants,
    ];
    if (!$ok) $failed[] = $case;
};
$workerArg = static function (string $key, mixed $value): string {
    if (is_array($value)) $value = json_encode(array_values($value), JSON_UNESCAPED_SLASHES);
    return '--' . $key . '=' . escapeshellarg((string)$value);
};
$runRace = static function (string $name, array $specs) use (&$barriers, $database, $workerPath, $workerArg): array {
    $barrier = __DIR__ . '/.etapa115_' . strtolower($name) . '_' . bin2hex(random_bytes(4)) . '.start';
    $barriers[] = $barrier;
    $processes = [];
    foreach ($specs as $spec) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath)
            . ' ' . $workerArg('db', $database)
            . ' ' . $workerArg('scenario', $name)
            . ' ' . $workerArg('barrier', $barrier)
            . ' ' . $workerArg('now', '2026-11-01 12:00:00');
        foreach ($spec as $key => $value) $command .= ' ' . $workerArg((string)$key, $value);
        $pipes = [];
        $resource = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
        $processes[] = ['resource' => $resource, 'pipes' => $pipes];
    }
    if (!touch($barrier)) throw new RuntimeException('No se pudo abrir la barrera de ' . $name);
    $deadline = microtime(true) + 30;
    do {
        $running = false;
        foreach ($processes as $process) {
            if (is_resource($process['resource'] ?? null) && proc_get_status($process['resource'])['running']) $running = true;
        }
        if ($running && microtime(true) < $deadline) usleep(10000);
    } while ($running && microtime(true) < $deadline);
    $outputs = [];
    foreach ($processes as $process) {
        if (is_resource($process['resource'] ?? null) && proc_get_status($process['resource'])['running']) proc_terminate($process['resource']);
        if (!isset($process['pipes'][1])) {
            $outputs[] = ['ok_proceso' => false, 'error' => 'WORKER_NO_INICIADO'];
            continue;
        }
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][0]); fclose($process['pipes'][1]); fclose($process['pipes'][2]);
        $exit = is_resource($process['resource'] ?? null) ? proc_close($process['resource']) : 1;
        $payload = json_decode(trim((string)$stdout), true);
        $outputs[] = is_array($payload)
            ? $payload + ['exit_code' => $exit, 'stderr' => trim((string)$stderr)]
            : ['ok_proceso' => false, 'raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr), 'exit_code' => $exit];
    }
    @unlink($barrier);
    return $outputs;
};
$resultFor = static function (array $workers, string $kind): ?array {
    foreach ($workers as $worker) if (($worker['kind'] ?? '') === $kind) return (array)($worker['resultado'] ?? []);
    return null;
};
$workerOk = static fn(?array $result): bool => ($result['ok'] ?? false) === true;

try {
    // El DML de la aplicacion deja algunos tickets POS abiertos como datos de
    // demostracion. Esta suite trabaja sobre una base temporal y debe reservar
    // sus ocho mesas de fixture sin heredar ocupacion fisica ajena.
    $query("UPDATE tickets
        SET estado = 'cerrado',
            closed_at = COALESCE(closed_at, '2026-11-01 11:59:00'),
            hora_cierre = COALESCE(hora_cierre, '2026-11-01 11:59:00')
        WHERE estado = 'abierto' AND closed_at IS NULL");

    $tableResult = $query("SELECT m.id
        FROM mesas m
        WHERE m.activo = 1
          AND m.reservable = 1
          AND m.tipo = 'mesa'
          AND NOT EXISTS (
              SELECT 1
              FROM ticket_mesas tm
              INNER JOIN tickets t ON t.id = tm.ticket_id
              WHERE tm.mesa_id = m.id
                AND t.estado = 'abierto'
                AND t.closed_at IS NULL
          )
        ORDER BY m.numero
        LIMIT 8");
    $tables = [];
    while ($row = $tableResult->fetch_assoc()) $tables[] = (int)$row['id'];
    $tableResult->free();
    if (count($tables) < 8) throw new RuntimeException('Se requieren ocho mesas de fixture.');
    [$t1, $t2, $t3, $t4, $t5, $t6, $t7, $t8] = array_slice($tables, 0, 8);

    // A: confirmacion publica OTP contra asignacion administrativa.
    $aContact = $contactPrefix . 'a@example.test';
    $aToken = $marker . '_A_HOLD';
    $aHold = ReservacionPublicaService::crearRetencion([
        'nombre' => $marker . '_A_PUBLIC', 'tipo_contacto' => 'email', 'contacto' => $aContact,
        'fecha' => '2026-11-02', 'hora' => '14:00', 'personas' => 2, 'notas' => 'A', 'request_token' => $aToken,
    ]);
    if (!$workerOk($aHold)) throw new RuntimeException('No se pudo crear A: ' . json_encode($aHold));
    $aRow = $rowByToken($aToken); $aId = (int)$aRow['id']; $reservationIds[] = $aId;
    $aOtp = (string)($aHold['preview_code'] ?? ''); $aMesa = $idsForReservation($aId)[0] ?? $t1;
    $aAdmin = $insertReservation('A_ADMIN', '2026-11-02', '14:00', 'confirmada', 'admin'); $reservationIds[] = $aAdmin;
    $aWorkers = $runRace('A_confirmacion_vs_asignacion', [
        ['kind' => 'public_confirm', 'token' => $aToken, 'contact' => $aContact, 'otp' => $aOtp],
        ['kind' => 'admin_assign', 'reservation-id' => $aAdmin, 'target-ids' => [$aMesa]],
    ]);
    $aFinal = $rowById($aId); $aAdminFinal = $rowById($aAdmin); $aAdminIds = $idsForReservation($aAdmin);
    $record('A', 'confirmacion publica OTP vs asignacion administrativa', ($aFinal['estado'] ?? '') === 'confirmada'
        && $aAdminIds === [] && !($workerOk($resultFor($aWorkers, 'admin_assign'))),
        ['publica' => $aFinal, 'administrativa' => $aAdminFinal, 'mesas_publicas' => $idsForReservation($aId), 'mesas_admin' => $aAdminIds, 'workers' => $aWorkers],
        ['sin_doble_asignacion' => $aAdminIds === [], 'hold_confirmado' => ($aFinal['estado'] ?? '') === 'confirmada']);

    // B: cancelacion administrativa contra inicio POS.
    $b = $insertReservation('B', '2026-11-01', '12:30', 'confirmada', 'admin'); $reservationIds[] = $b; $assign($b, [$t1]);
    $bWorkers = $runRace('B_cancelacion_vs_inicio', [
        ['kind' => 'admin_cancel', 'reservation-id' => $b], ['kind' => 'start', 'reservation-id' => $b],
    ]);
    $bFinal = $rowById($b); $bTicket = $ticketForReservation($b); if ($bTicket) $ticketIds[] = (int)$bTicket['id'];
    $bValid = (($bFinal['estado'] ?? '') === 'cancelada' && $bTicket === null)
        || (($bFinal['estado'] ?? '') === 'en_curso' && $bTicket !== null && ($bTicket['estado'] ?? '') === 'abierto');
    $record('B', 'cancelacion administrativa vs inicio POS', $bValid, ['reservacion' => $bFinal, 'ticket' => $bTicket, 'workers' => $bWorkers], ['sin_cancelada_con_ticket' => !(($bFinal['estado'] ?? '') === 'cancelada' && $bTicket !== null)]);

    // C: no-show contra inicio POS.
    $c = $insertReservation('C', '2026-11-01', '11:00', 'confirmada', 'admin'); $reservationIds[] = $c; $assign($c, [$t2]);
    $cWorkers = $runRace('C_no_show_vs_inicio', [
        ['kind' => 'no_show', 'reservation-id' => $c], ['kind' => 'start', 'reservation-id' => $c],
    ]);
    $cFinal = $rowById($c); $cTicket = $ticketForReservation($c); if ($cTicket) $ticketIds[] = (int)$cTicket['id'];
    $cValid = (($cFinal['estado'] ?? '') === 'no_show' && $cTicket === null)
        || (($cFinal['estado'] ?? '') === 'en_curso' && $cTicket !== null && ($cTicket['estado'] ?? '') === 'abierto');
    $record('C', 'no show vs inicio POS', $cValid, ['reservacion' => $cFinal, 'ticket' => $cTicket, 'workers' => $cWorkers], ['no_show_sin_ticket' => !(($cFinal['estado'] ?? '') === 'no_show' && $cTicket !== null)]);

    // D: cierre contra reasignacion.
    $d = $insertReservation('D', '2026-11-01', '12:00', 'confirmada', 'admin'); $reservationIds[] = $d; $assign($d, [$t3]);
    $dStarted = PuntoVentaReservacionService::comenzar($d, 1, null); $dTicket = (int)($dStarted['ticket_id'] ?? 0); if ($dTicket) $ticketIds[] = $dTicket;
    $dWorkers = $runRace('D_cierre_vs_reasignacion', [
        ['kind' => 'close', 'ticket-id' => $dTicket], ['kind' => 'admin_reassign', 'reservation-id' => $d, 'target-ids' => [$t4]],
    ]);
    $dFinal = $rowById($d); $dTicketFinal = $ticketForReservation($d); $dMap = $resultFor($dWorkers, 'admin_reassign');
    $record('D', 'cierre de ticket vs reasignacion', ($dFinal['estado'] ?? '') === 'completada' && ($dTicketFinal['estado'] ?? '') === 'cerrado' && !$workerOk($dMap), ['reservacion' => $dFinal, 'ticket' => $dTicketFinal, 'workers' => $dWorkers], ['no_reasignacion_despues_de_inicio' => !$workerOk($dMap)]);

    // E: cierre contra segunda apertura.
    $e = $insertReservation('E', '2026-11-01', '12:00', 'confirmada', 'admin'); $reservationIds[] = $e; $assign($e, [$t4]);
    $eStarted = PuntoVentaReservacionService::comenzar($e, 1, null); $eTicket = (int)($eStarted['ticket_id'] ?? 0); if ($eTicket) $ticketIds[] = $eTicket;
    $eWorkers = $runRace('E_cierre_vs_segunda_apertura', [
        ['kind' => 'close', 'ticket-id' => $eTicket], ['kind' => 'start_again', 'reservation-id' => $e],
    ]);
    $eFinal = $rowById($e); $eTicketFinal = $ticketForReservation($e); $eCount = (int)($query('SELECT COUNT(*) AS total FROM tickets WHERE reservacion_id = ' . $e)->fetch_assoc()['total'] ?? 0);
    $record('E', 'cierre de ticket vs segunda apertura', ($eFinal['estado'] ?? '') === 'completada' && ($eTicketFinal['estado'] ?? '') === 'cerrado' && $eCount === 1, ['reservacion' => $eFinal, 'ticket' => $eTicketFinal, 'tickets' => $eCount, 'workers' => $eWorkers], ['un_ticket' => $eCount === 1, 'sin_ticket_abierto' => ($eTicketFinal['estado'] ?? '') !== 'abierto']);

    // F: reemplazo publico contra inicio POS.
    $fContact = $contactPrefix . 'f@example.test';
    $f = $insertReservation('F_ORIGINAL', '2026-11-01', '12:30', 'confirmada', 'landing', 'email', $fContact); $reservationIds[] = $f; $assign($f, [$t6]);
    $fToken = $marker . '_F_REPLACEMENT';
    $fReplacement = ReservacionPublicaService::crearReemplazo(['reservacion_id' => $f, 'fecha' => '2026-11-03', 'hora' => '14:00', 'personas' => 2, 'notas' => 'F', 'request_token' => $fToken], ['contacto_tipo' => 'email', 'contacto' => $fContact]);
    if (!$workerOk($fReplacement)) throw new RuntimeException('No se pudo crear F: ' . json_encode($fReplacement));
    $fNew = $rowByToken($fToken); $fId = (int)$fNew['id']; $reservationIds[] = $fId; $fOtp = (string)($fReplacement['preview_code'] ?? '');
    $fWorkers = $runRace('F_reemplazo_vs_inicio', [
        ['kind' => 'public_confirm_replacement', 'token' => $fToken, 'contact' => $fContact, 'otp' => $fOtp], ['kind' => 'start', 'reservation-id' => $f],
    ]);
    $fFinal = $rowById($f); $fNewFinal = $rowById($fId); $fTicket = $ticketForReservation($f); if ($fTicket) $ticketIds[] = (int)$fTicket['id'];
    $fValid = (($fFinal['estado'] ?? '') === 'reemplazada' && ($fNewFinal['estado'] ?? '') === 'confirmada' && $fTicket === null)
        || (($fFinal['estado'] ?? '') === 'en_curso' && ($fNewFinal['estado'] ?? '') === 'expirada' && $fTicket !== null);
    $record('F', 'reemplazo publico vs inicio POS', $fValid, ['original' => $fFinal, 'reemplazo' => $fNewFinal, 'ticket' => $fTicket, 'workers' => $fWorkers], ['original_y_reemplazo_operativos' => !(($fFinal['estado'] ?? '') === 'confirmada' && ($fNewFinal['estado'] ?? '') === 'confirmada')]);

    // G: expiracion del hold contra confirmacion OTP.
    $gContact = $contactPrefix . 'g@example.test'; $gToken = $marker . '_G_HOLD';
    $gHold = ReservacionPublicaService::crearRetencion(['nombre' => $marker . '_G', 'tipo_contacto' => 'email', 'contacto' => $gContact, 'fecha' => '2026-11-04', 'hora' => '14:00', 'personas' => 2, 'notas' => 'G', 'request_token' => $gToken]);
    if (!$workerOk($gHold)) throw new RuntimeException('No se pudo crear G: ' . json_encode($gHold));
    $g = $rowByToken($gToken); $gId = (int)$g['id']; $reservationIds[] = $gId; $query("UPDATE reservaciones SET hold_expires_at = '2026-11-01 12:00:00' WHERE id = {$gId}"); $gOtp = (string)($gHold['preview_code'] ?? '');
    $gWorkers = $runRace('G_expiracion_vs_confirmacion', [
        ['kind' => 'expire'], ['kind' => 'public_confirm', 'token' => $gToken, 'contact' => $gContact, 'otp' => $gOtp],
    ]);
    $gFinal = $rowById($gId);
    $record('G', 'expiracion de hold vs confirmacion OTP', ($gFinal['estado'] ?? '') === 'expirada', ['reservacion' => $gFinal, 'workers' => $gWorkers], ['otp_expirado_no_confirma' => ($gFinal['estado'] ?? '') !== 'confirmada']);

    // H: reasignacion contra creacion de hold publico sobre la ultima mesa libre.
    $h = $insertReservation('H_ORIGINAL', '2026-11-09', '14:00', 'confirmada', 'admin'); $reservationIds[] = $h; $assign($h, [$t1]);
    foreach ([$t3, $t4, $t5, $t6, $t7, $t8] as $index => $blockedMesa) {
        $block = $insertReservation('H_BLOCK_' . $index, '2026-11-09', '14:00', 'confirmada', 'admin'); $reservationIds[] = $block; $assign($block, [$blockedMesa]);
    }
    $hContact = $contactPrefix . 'h@example.test'; $hToken = $marker . '_H_HOLD';
    $hWorkers = $runRace('H_reasignacion_vs_hold_publico', [
        ['kind' => 'admin_reassign', 'reservation-id' => $h, 'target-ids' => [$t2]],
        ['kind' => 'public_hold', 'name' => $marker . '_H_PUBLIC', 'contact' => $hContact, 'date' => '2026-11-09', 'hour' => '14:00', 'token' => $hToken],
    ]);
    $hFinal = $rowById($h); $hPublic = $rowByToken($hToken); if ($hPublic) $reservationIds[] = (int)$hPublic['id']; $hMap = $resultFor($hWorkers, 'admin_reassign');
    $hPublicIds = $hPublic ? $idsForReservation((int)$hPublic['id']) : [];
    $hValid = ($workerOk($hMap) && (!$workerOk($resultFor($hWorkers, 'public_hold')) || ($hPublic !== null && $hPublicIds !== [$t2])) && $idsForReservation($h) === [$t2] && ($hPublic === null || $hPublicIds !== [$t2]))
        || (!$workerOk($hMap) && $workerOk($resultFor($hWorkers, 'public_hold')) && $hPublic !== null && $hPublicIds === [$t2] && $idsForReservation($h) === [$t1]);
    $record('H', 'reasignacion administrativa vs creacion de hold publico', $hValid, ['original' => $hFinal, 'original_mesas' => $idsForReservation($h), 'hold_publico' => $hPublic, 'hold_mesas' => $hPublicIds, 'workers' => $hWorkers], ['sin_conflicto_mesa' => !($hPublic !== null && $hPublicIds === [$t2] && $idsForReservation($h) === [$t2])]);

    // I: cancelacion administrativa contra confirmacion de reemplazo.
    $iContact = $contactPrefix . 'i@example.test'; $i = $insertReservation('I_ORIGINAL', '2026-11-10', '14:00', 'confirmada', 'landing', 'email', $iContact); $reservationIds[] = $i; $assign($i, [$t1]);
    $iToken = $marker . '_I_REPLACEMENT';
    $iReplacement = ReservacionPublicaService::crearReemplazo(['reservacion_id' => $i, 'fecha' => '2026-11-11', 'hora' => '14:00', 'personas' => 2, 'notas' => 'I', 'request_token' => $iToken], ['contacto_tipo' => 'email', 'contacto' => $iContact]);
    if (!$workerOk($iReplacement)) throw new RuntimeException('No se pudo crear I: ' . json_encode($iReplacement));
    $iNew = $rowByToken($iToken); $iId = (int)$iNew['id']; $reservationIds[] = $iId; $iOtp = (string)($iReplacement['preview_code'] ?? '');
    $iWorkers = $runRace('I_cancelacion_vs_reemplazo', [
        ['kind' => 'admin_cancel', 'reservation-id' => $i], ['kind' => 'public_confirm_replacement', 'token' => $iToken, 'contact' => $iContact, 'otp' => $iOtp],
    ]);
    $iFinal = $rowById($i); $iNewFinal = $rowById($iId); $iCancel = $resultFor($iWorkers, 'admin_cancel'); $iConfirm = $resultFor($iWorkers, 'public_confirm_replacement');
    $iValid = ($workerOk($iCancel) && ($iFinal['estado'] ?? '') === 'cancelada' && ($iNewFinal['estado'] ?? '') === 'expirada' && !$workerOk($iConfirm))
        || ($workerOk($iConfirm) && ($iFinal['estado'] ?? '') === 'reemplazada' && ($iNewFinal['estado'] ?? '') === 'confirmada' && !$workerOk($iCancel));
    $record('I', 'cancelacion administrativa vs reemplazo publico', $iValid, ['original' => $iFinal, 'reemplazo' => $iNewFinal, 'workers' => $iWorkers], ['original_cancelada_y_reemplazo_confirmado' => !(($iFinal['estado'] ?? '') === 'cancelada' && ($iNewFinal['estado'] ?? '') === 'confirmada')]);

    // J: no-show contra cancelacion.
    $j = $insertReservation('J', '2026-11-01', '11:00', 'confirmada', 'admin'); $reservationIds[] = $j; $assign($j, [$t7]);
    $jWorkers = $runRace('J_no_show_vs_cancelacion', [
        ['kind' => 'no_show', 'reservation-id' => $j], ['kind' => 'admin_cancel', 'reservation-id' => $j],
    ]);
    $jFinal = $rowById($j); $jSuccesses = count(array_filter($jWorkers, static fn(array $w): bool => ($w['resultado']['ok'] ?? false) === true));
    $record('J', 'no show vs cancelacion', in_array($jFinal['estado'] ?? '', ['no_show', 'cancelada'], true) && $jSuccesses === 1, ['reservacion' => $jFinal, 'workers' => $jWorkers, 'transiciones_exitosas' => $jSuccesses], ['una_transicion_terminal' => $jSuccesses === 1]);

    // K: cierre contra no-show obsoleto.
    $k = $insertReservation('K', '2026-11-01', '12:00', 'confirmada', 'admin'); $reservationIds[] = $k; $assign($k, [$t8]);
    $kStarted = PuntoVentaReservacionService::comenzar($k, 1, null); $kTicket = (int)($kStarted['ticket_id'] ?? 0); if ($kTicket) $ticketIds[] = $kTicket;
    $kWorkers = $runRace('K_cierre_vs_no_show', [
        ['kind' => 'close', 'ticket-id' => $kTicket], ['kind' => 'no_show', 'reservation-id' => $k],
    ]);
    $kFinal = $rowById($k); $kTicketFinal = $ticketForReservation($k); $kNoShow = $resultFor($kWorkers, 'no_show');
    $record('K', 'cierre de ticket vs no show', ($kFinal['estado'] ?? '') === 'completada' && ($kTicketFinal['estado'] ?? '') === 'cerrado' && !$workerOk($kNoShow), ['reservacion' => $kFinal, 'ticket' => $kTicketFinal, 'workers' => $kWorkers], ['no_show_rechazado_en_curso' => !$workerOk($kNoShow)]);

    // L: dos cierres simultaneos.
    // C conserva deliberadamente un ticket abierto para probar la carrera C;
    // L usa la mesa que K acaba de liberar para no heredar esa ocupacion.
    $l = $insertReservation('L', '2026-11-01', '12:00', 'confirmada', 'admin'); $reservationIds[] = $l; $assign($l, [$t8]);
    $lStarted = PuntoVentaReservacionService::comenzar($l, 1, null); $lTicket = (int)($lStarted['ticket_id'] ?? 0); if ($lTicket) $ticketIds[] = $lTicket;
    $lWorkers = $runRace('L_doble_cierre', [
        ['kind' => 'close', 'ticket-id' => $lTicket], ['kind' => 'close', 'ticket-id' => $lTicket],
    ]);
    $lFinal = $rowById($l); $lTicketFinal = $ticketForReservation($l); $lMaterial = count(array_filter($lWorkers, static fn(array $w): bool => ($w['resultado']['ok'] ?? false) === true && !($w['resultado']['idempotente'] ?? false)));
    $lIdempotent = count(array_filter($lWorkers, static fn(array $w): bool => ($w['resultado']['ok'] ?? false) === true && ($w['resultado']['idempotente'] ?? false) === true));
    $record('L', 'cierre simultaneo contra cierre simultaneo', ($lFinal['estado'] ?? '') === 'completada' && ($lTicketFinal['estado'] ?? '') === 'cerrado' && $lMaterial === 1 && $lIdempotent === 1, ['reservacion' => $lFinal, 'ticket' => $lTicketFinal, 'workers' => $lWorkers], ['un_cierre_material' => $lMaterial === 1, 'segundo_cierre_idempotente' => $lIdempotent === 1]);
} catch (Throwable $error) {
    $failed[] = 'EXCEPCION: ' . $error->getMessage();
}

try {
    $ticketIds = array_values(array_unique(array_filter(array_map('intval', $ticketIds))));
    if ($ticketIds !== []) {
        $list = implode(',', $ticketIds);
        $db->query("DELETE FROM ticket_pagos WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM ticket_items WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM ticket_mesas WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM tickets WHERE id IN ({$list})");
    }
    $like = $escape($marker . '%');
    $rows = $db->query("SELECT id FROM reservaciones WHERE nombre LIKE '{$like}' OR request_token LIKE '{$like}'");
    $ids = [];
    if ($rows) { while ($row = $rows->fetch_assoc()) $ids[] = (int)$row['id']; $rows->free(); }
    if ($ids !== []) {
        $list = implode(',', array_values(array_unique($ids)));
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservaciones WHERE reemplaza_reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$list})");
    }
} finally {
    foreach ($barriers as $barrier) if (is_file($barrier)) @unlink($barrier);
}

$ok = $failed === [] && count($cases) === 12 && count(array_filter($cases, static fn(array $case): bool => $case['resultado'] === 'PASS')) === 12;
echo json_encode([
    'ok' => $ok,
    'suite' => 'etapa11_5_concurrencia_completa',
    'database' => $database,
    'carreras' => count(array_filter($cases, static fn(array $case): bool => $case['resultado'] === 'PASS')) . '/12',
    'failed' => $failed,
    'cases' => $cases,
    'workers' => 2,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
