<?php

declare(strict_types=1);

/** Carrera multiproceso de dos asignaciones manuales sobre la misma mesa. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

$options = getopt('', ['db:']);
$database = (string)($options['db'] ?? '');
if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1) {
    fwrite(STDERR, "Uso: php etapa9_concurrencia.php --db=BASE_DE_PRUEBAS\n");
    exit(2);
}
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\ReservacionAdministrativaService;
use Services\ReservacionService;

$db = ActiveRecord::getDB();
if (!$db->select_db($database)) {
    fwrite(STDERR, $db->error . "\n");
    exit(2);
}
ActiveRecord::setDB($db);
$prefix = 'ETAPA9_RACE_' . bin2hex(random_bytes(4));
$reservacionIds = [];
$processes = [];
$outputs = [];
$asignadas = 0;
$barrier = dirname(__DIR__) . '/.etapa9_' . strtolower($prefix) . '.start';

$crear = static function (string $sufijo) use ($prefix): int {
    $resultado = ReservacionService::crearAdministrativa([
        'nombre' => $prefix . '_' . $sufijo,
        'contacto_tipo' => 'email',
        'contacto' => strtolower($prefix . '_' . $sufijo) . '@example.test',
        'fecha' => '2026-11-03',
        'hora' => '13:00',
        'comensales' => 2,
        'nota' => 'fixture de concurrencia Etapa 9',
        'comentario_admin' => '',
        'request_token' => $prefix . '_' . $sufijo . '_TOKEN',
        'asignar_automaticamente' => '0',
        'confirmaciones' => [ReservacionAdministrativaService::SIN_ASIGNACION],
    ]);
    $id = (int)($resultado['id'] ?? 0);
    if ($id < 1) throw new RuntimeException('No se pudo crear fixture: ' . json_encode($resultado));
    return $id;
};

try {
    $reservacionIds = [$crear('A'), $crear('B')];
    $worker = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/etapa9_concurrencia_worker.php');
    foreach ($reservacionIds as $id) {
        $command = $worker
            . ' --db=' . escapeshellarg($database)
            . ' --reservation-id=' . $id
            . ' --table-id=2'
            . ' --barrier=' . escapeshellarg($barrier);
        $pipes = [];
        $processes[] = [
            'resource' => proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2)),
            'pipes' => $pipes,
        ];
    }
    if (!touch($barrier)) throw new RuntimeException('No se pudo abrir la barrera.');
    foreach ($processes as $process) {
        if (!is_resource($process['resource'])) {
            $outputs[] = ['ok' => false, 'codigo' => 'WORKER_NO_INICIADO'];
            continue;
        }
        fclose($process['pipes'][0]);
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exit = proc_close($process['resource']);
        $payload = json_decode(trim((string)$stdout), true);
        $outputs[] = is_array($payload)
            ? $payload + ['exit_code' => $exit, 'stderr' => trim((string)$stderr)]
            : ['ok' => false, 'raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr), 'exit_code' => $exit];
    }
    foreach ($reservacionIds as $id) $asignadas += count(ReservacionMesa::obtenerIdsPorReservacion($id));
} catch (Throwable $error) {
    $outputs[] = ['ok' => false, 'error' => $error->getMessage()];
} finally {
    foreach ($processes as $process) {
        if (is_resource($process['resource'])) proc_terminate($process['resource']);
    }
    if (is_file($barrier)) @unlink($barrier);
    if ($reservacionIds !== []) {
        $ids = implode(',', array_map('intval', $reservacionIds));
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$ids})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$ids})");
    }
}

$ganadoras = array_values(array_filter($outputs, static fn(array $row): bool => ($row['ok'] ?? false) === true));
$perdedoras = array_values(array_filter($outputs, static fn(array $row): bool => ($row['codigo'] ?? '') === 'MESA_OCUPADA'));
$ok = count($outputs) === 2 && count($ganadoras) === 1 && count($perdedoras) === 1;
echo json_encode([
    'ok' => $ok,
    'prefix' => $prefix,
    'workers' => $outputs,
    'asignaciones_durante_verificacion' => $asignadas,
    'esperado' => 'una ganadora y una MESA_OCUPADA; no hay doble asignacion',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($ok ? 0 : 1);
