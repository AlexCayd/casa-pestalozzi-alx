<?php

declare(strict_types=1);

/** Dos altas administrativas simultaneas sobre la misma fecha operativa. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;

$db = ActiveRecord::getDB();
$options = getopt('', ['db:']);
if (!empty($options['db']) && preg_match('/^[A-Za-z0-9_]+$/', (string)$options['db']) === 1) {
    $db->select_db((string)$options['db']);
    ActiveRecord::setDB($db);
}
$prefix = 'ETAPA8_RACE_' . bin2hex(random_bytes(4));
$nameA = $prefix . '_A';
$nameB = $prefix . '_B';
$contact = strtolower($prefix) . '@example.test';
$commandBase = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/etapa8_concurrencia_worker.php')
    . (!empty($options['db']) ? ' --db=' . escapeshellarg((string)$options['db']) : '');
$jobs = [
    $commandBase . ' --name=' . escapeshellarg($nameA) . ' --token=' . escapeshellarg($prefix . '_A_TOKEN') . ' --contact=' . escapeshellarg($contact),
    $commandBase . ' --name=' . escapeshellarg($nameB) . ' --token=' . escapeshellarg($prefix . '_B_TOKEN') . ' --contact=' . escapeshellarg($contact),
];
$processes = [];
foreach ($jobs as $job) {
    $pipes = [];
    $processes[] = [
        'resource' => proc_open($job, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2)),
        'pipes' => $pipes,
    ];
}
$outputs = [];
foreach ($processes as $process) {
    if (!is_resource($process['resource'])) {
        $outputs[] = ['ok' => false, 'error' => 'No se pudo iniciar worker.'];
        continue;
    }
    fclose($process['pipes'][0]);
    $stdout = stream_get_contents($process['pipes'][1]);
    $stderr = stream_get_contents($process['pipes'][2]);
    fclose($process['pipes'][1]); fclose($process['pipes'][2]);
    $exit = proc_close($process['resource']);
    $payload = json_decode(trim((string)$stdout), true);
    $outputs[] = is_array($payload) ? $payload + ['exit_code' => $exit, 'stderr' => trim((string)$stderr)] : ['ok' => false, 'raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr), 'exit_code' => $exit];
}
$result = $db->query("SELECT r.id, COUNT(rm.id) AS mesas FROM reservaciones r LEFT JOIN reservacion_mesas rm ON rm.reservacion_id = r.id WHERE r.nombre LIKE '" . $db->real_escape_string($prefix) . "%' GROUP BY r.id");
$rows = [];
if ($result) {
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $result->free();
}
$assigned = [];
foreach ($rows as $row) {
    $tables = $db->query('SELECT mesa_id FROM reservacion_mesas WHERE reservacion_id = ' . (int)$row['id']);
    while ($tables && ($table = $tables->fetch_assoc())) $assigned[] = (int)$table['mesa_id'];
}
$duplicates = count($assigned) !== count(array_unique($assigned));
$cleanup = $db->query("SELECT id FROM reservaciones WHERE nombre LIKE '" . $db->real_escape_string($prefix) . "%'");
$ids = [];
if ($cleanup) { while ($row = $cleanup->fetch_assoc()) $ids[] = (int)$row['id']; }
if ($ids !== []) {
    $idList = implode(',', $ids);
    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$idList})");
    $db->query("DELETE FROM reservaciones WHERE id IN ({$idList})");
}
$ok = count($outputs) === 2 && count(array_filter($outputs, static fn(array $row): bool => ($row['ok'] ?? false) === true)) === 2 && !$duplicates;
echo json_encode(['ok' => $ok, 'workers' => $outputs, 'reservations' => $rows, 'duplicate_table_ids' => $duplicates], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
