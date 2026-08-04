<?php

declare(strict_types=1);

/** Carreras multiproceso de las transiciones operativas de Etapa 10. */

$database = '';
foreach ($argv ?? [] as $argumento) {
    if (str_starts_with((string)$argumento, '--db=')) {
        $database = substr((string)$argumento, 5);
    }
}
if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1
    || in_array($database, ['casa-pestalozzi', 'casa_pestalozzi'], true)) {
    fwrite(STDERR, "Uso: php etapa10_concurrencia.php --db=BASE_DE_PRUEBAS\n");
    exit(2);
}

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('APP_ENV=testing');
putenv('DB_NAME=' . $database);
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\PuntoVentaReservacionService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No hay conexión MySQL para concurrencia Etapa 10.\n");
    exit(2);
}
ActiveRecord::setDB($db);
$marker = 'ETAPA10_RACE_' . strtoupper(bin2hex(random_bytes(5)));
$failed = [];
$cases = [];

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
    if ($result instanceof mysqli_result) $result->free();
    return $value;
};
$insert = static function (string $suffix, string $hora = '12:00:00') use ($query, $escape, $db, $marker): int {
    $name = $marker . '_' . $suffix;
    $query("INSERT INTO reservaciones
        (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen,
         request_token, hold_expires_at, estado, estado_changed_at)
        VALUES ('{$escape($name)}', 'ninguno', NULL, '2026-11-01', '{$hora}', 2,
         'Etapa 10 carrera', 'admin', '{$escape($marker . '_' . $suffix . '_' . bin2hex(random_bytes(3)))}',
         NULL, 'confirmada', '2026-11-01 12:00:00')");
    return (int)$db->insert_id;
};
$assign = static function (int $id, array $mesaIds): void {
    ReservacionMesa::reemplazarAsignacion($id, $mesaIds);
};
$worker = __DIR__ . '/etapa10_concurrencia_worker.php';
$barriers = [];

$runRace = static function (string $name, array $operations) use ($database, $worker, &$barriers): array {
    $barrier = __DIR__ . '/.etapa10_' . strtolower($name) . '_' . bin2hex(random_bytes(4)) . '.start';
    $barriers[] = $barrier;
    $processes = [];
    $php = escapeshellarg(PHP_BINARY);
    foreach ($operations as $operation) {
        $args = [
            '--db=' . escapeshellarg($database),
            '--op=' . escapeshellarg((string)$operation['op']),
            '--barrier=' . escapeshellarg($barrier),
        ];
        if (!empty($operation['id'])) $args[] = '--id=' . (int)$operation['id'];
        if (!empty($operation['ticket-id'])) $args[] = '--ticket-id=' . (int)$operation['ticket-id'];
        $pipes = [];
        $process = proc_open($php . ' ' . escapeshellarg($worker) . ' ' . implode(' ', $args), [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, dirname(__DIR__, 2));
        if (!is_resource($process)) {
            $processes[] = ['resource' => null, 'pipes' => [], 'error' => 'WORKER_NO_INICIADO'];
            continue;
        }
        $processes[] = ['resource' => $process, 'pipes' => $pipes];
    }
    if (!touch($barrier)) {
        throw new RuntimeException('No se pudo crear la barrera de carrera.');
    }
    $deadline = microtime(true) + 25;
    foreach ($processes as $process) {
        if (!is_resource($process['resource'] ?? null)) continue;
        stream_set_blocking($process['pipes'][1], false);
        stream_set_blocking($process['pipes'][2], false);
    }
    $outputs = [];
    foreach ($processes as $process) {
        if (!is_resource($process['resource'] ?? null)) {
            $outputs[] = ['ok_proceso' => false, 'error' => $process['error'] ?? 'WORKER'];
            continue;
        }
        while (proc_get_status($process['resource'])['running'] && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (proc_get_status($process['resource'])['running']) {
            proc_terminate($process['resource']);
        }
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][0]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exit = proc_close($process['resource']);
        $payload = json_decode(trim((string)$stdout), true);
        $outputs[] = is_array($payload)
            ? $payload + ['exit_code' => $exit, 'stderr' => trim((string)$stderr)]
            : ['ok_proceso' => false, 'raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr), 'exit_code' => $exit];
    }
    @unlink($barrier);
    return $outputs;
};
$resultFor = static function (array $workers, string $operation): ?array {
    foreach ($workers as $worker) {
        if (($worker['op'] ?? '') === $operation) return (array)($worker['resultado'] ?? []);
    }
    return null;
};
$record = static function (string $name, bool $ok, string $detail = '') use (&$cases, &$failed): void {
    $cases[$name] = ['ok' => $ok, 'detail' => $detail];
    if (!$ok) $failed[] = $name . ($detail !== '' ? ': ' . $detail : '');
};

$reservationIds = [];
$ticketIds = [];
try {
    $tables = [];
    $tableResult = $query("SELECT id, numero FROM mesas
        WHERE activo = 1 AND reservable = 1 AND tipo = 'mesa' ORDER BY numero, id");
    while ($value = $tableResult->fetch_assoc()) $tables[(int)$value['numero']] = (int)$value['id'];
    $tableResult->free();
    $selected = array_values(array_intersect_key($tables, array_flip([2, 3, 4, 7])));
    if (count($selected) < 4) throw new RuntimeException('No hay mesas de carrera disponibles.');
    [$mesaA, $mesaB, $mesaC, $mesaD] = array_map('intval', $selected);

    // A. cancelación contra inicio: exactamente una transición operacional gana.
    $cancelStart = $insert('CANCEL_START'); $reservationIds[] = $cancelStart; $assign($cancelStart, [$mesaA]);
    $raceA = $runRace('cancel_start', [
        ['op' => 'cancel', 'id' => $cancelStart], ['op' => 'start', 'id' => $cancelStart],
    ]);
    $rowA = $row("SELECT r.estado, COUNT(t.id) AS open_tickets FROM reservaciones r
        LEFT JOIN tickets t ON t.reservacion_id = r.id AND t.estado = 'abierto' AND t.closed_at IS NULL
        WHERE r.id = {$cancelStart} GROUP BY r.id, r.estado");
    $validA = !($rowA['estado'] === 'cancelada' && (int)$rowA['open_tickets'] > 0)
        && in_array((string)$rowA['estado'], ['cancelada', 'en_curso'], true);
    $record('cancel_vs_start', $validA, json_encode(['workers' => $raceA, 'final' => $rowA], JSON_UNESCAPED_UNICODE));

    // B. no-show contra inicio: no-show no puede coexistir con ticket abierto.
    $noShowStart = $insert('NOSHOW_START', '11:00:00'); $reservationIds[] = $noShowStart; $assign($noShowStart, [$mesaB]);
    $raceB = $runRace('noshow_start', [
        ['op' => 'no-show', 'id' => $noShowStart], ['op' => 'start', 'id' => $noShowStart],
    ]);
    $rowB = $row("SELECT r.estado, COUNT(t.id) AS open_tickets FROM reservaciones r
        LEFT JOIN tickets t ON t.reservacion_id = r.id AND t.estado = 'abierto' AND t.closed_at IS NULL
        WHERE r.id = {$noShowStart} GROUP BY r.id, r.estado");
    $validB = !($rowB['estado'] === 'no_show' && (int)$rowB['open_tickets'] > 0)
        && in_array((string)$rowB['estado'], ['no_show', 'en_curso'], true);
    $record('no_show_vs_start', $validB, json_encode(['workers' => $raceB, 'final' => $rowB], JSON_UNESCAPED_UNICODE));

    // C. dos inicios: un solo ticket físico y un solo estado en_curso.
    $doubleStart = $insert('DOUBLE_START'); $reservationIds[] = $doubleStart; $assign($doubleStart, [$mesaC]);
    $raceC = $runRace('double_start', [
        ['op' => 'start', 'id' => $doubleStart], ['op' => 'start', 'id' => $doubleStart],
    ]);
    $rowC = $row("SELECT r.estado, COUNT(t.id) AS tickets, SUM(t.estado = 'abierto' AND t.closed_at IS NULL) AS open_tickets
        FROM reservaciones r LEFT JOIN tickets t ON t.reservacion_id = r.id WHERE r.id = {$doubleStart} GROUP BY r.id, r.estado");
    $record('double_start_one_ticket', ($rowC['estado'] ?? '') === 'en_curso'
        && (int)($rowC['tickets'] ?? 0) === 1 && (int)($rowC['open_tickets'] ?? 0) === 1, json_encode(['workers' => $raceC, 'final' => $rowC], JSON_UNESCAPED_UNICODE));
    $ticketC = (int)($row("SELECT id FROM tickets WHERE reservacion_id = {$doubleStart} ORDER BY id DESC LIMIT 1")['id'] ?? 0);
    if ($ticketC) $ticketIds[] = $ticketC;

    // D. dos cierres: uno materializa el cierre y el otro sólo es idempotente.
    $raceD = $runRace('double_close', [
        ['op' => 'close', 'ticket-id' => $ticketC], ['op' => 'close', 'ticket-id' => $ticketC],
    ]);
    $rowD = $row("SELECT r.estado, t.estado AS ticket_state, COUNT(tm.id) AS pivot_history
        FROM reservaciones r INNER JOIN tickets t ON t.reservacion_id = r.id
        LEFT JOIN ticket_mesas tm ON tm.ticket_id = t.id
        WHERE r.id = {$doubleStart} GROUP BY r.id, r.estado, t.id, t.estado");
    $record('double_close_idempotent', ($rowD['estado'] ?? '') === 'completada'
        && ($rowD['ticket_state'] ?? '') === 'cerrado' && (int)($rowD['pivot_history'] ?? 0) === 1, json_encode(['workers' => $raceD, 'final' => $rowD], JSON_UNESCAPED_UNICODE));

    // E. cierre contra reasignación después de iniciar: el cierre gana y el mapa rechaza.
    $closeReassign = $insert('CLOSE_REASSIGN'); $reservationIds[] = $closeReassign; $assign($closeReassign, [$mesaD]);
    $started = PuntoVentaReservacionService::comenzar($closeReassign, 1, null);
    $ticketE = (int)($started['ticket_id'] ?? 0); if ($ticketE) $ticketIds[] = $ticketE;
    $raceE = $runRace('close_reassign', [
        ['op' => 'close', 'ticket-id' => $ticketE], ['op' => 'reassign', 'id' => $closeReassign],
    ]);
    $rowE = $row("SELECT r.estado, t.estado AS ticket_state FROM reservaciones r
        INNER JOIN tickets t ON t.reservacion_id = r.id WHERE r.id = {$closeReassign} LIMIT 1");
    $reassignResult = $resultFor($raceE, 'reassign');
    $record('close_vs_reassign', ($rowE['estado'] ?? '') === 'completada'
        && ($rowE['ticket_state'] ?? '') === 'cerrado'
        && !($reassignResult['ok'] ?? false), json_encode(['workers' => $raceE, 'final' => $rowE], JSON_UNESCAPED_UNICODE));

    // Invariante global final de la carrera.
    $invalidOpen = (int)($row("SELECT COUNT(*) AS total FROM tickets t
        LEFT JOIN reservaciones r ON r.id = t.reservacion_id
        WHERE t.estado = 'abierto' AND t.closed_at IS NULL
          AND t.reservacion_id IS NOT NULL AND (r.id IS NULL OR r.estado <> 'en_curso')")['total'] ?? 0);
    $record('reconciliation_after_races', $invalidOpen === 0, 'tickets vinculados fuera de en_curso=' . $invalidOpen);
} catch (Throwable $error) {
    $failed[] = 'EXCEPCION: ' . $error->getMessage();
} finally {
    foreach ($barriers as $barrier) @unlink($barrier);
    if ($ticketIds !== []) {
        $list = implode(',', array_values(array_unique(array_map('intval', $ticketIds))));
        $db->query("DELETE FROM ticket_pagos WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM ticket_items WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM ticket_mesas WHERE ticket_id IN ({$list})");
        $db->query("DELETE FROM tickets WHERE id IN ({$list})");
    }
    $like = $db->real_escape_string($marker . '%');
    $result = $db->query("SELECT id FROM reservaciones WHERE nombre LIKE '{$like}' ORDER BY id DESC");
    $ids = [];
    if ($result) {
        while ($value = $result->fetch_assoc()) $ids[] = (int)$value['id'];
        $result->free();
    }
    if ($ids !== []) {
        $list = implode(',', $ids);
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$list})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$list})");
    }
}

$result = [
    'ok' => $failed === [],
    'suite' => 'etapa10_concurrencia',
    'database' => $database,
    'failed' => $failed,
    'cases' => $cases,
    'workers' => 2,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
