<?php

declare(strict_types=1);

/** Instala DDL/DML en una base temporal y ejecuta la regresion transversal. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexion MySQL para instalacion limpia.\\n");
    exit(2);
}

$database = 'casa_pestalozzi_etapa95_clean_' . date('YmdHis') . '_' . bin2hex(random_bytes(3));
$resultado = [
    'ok' => false,
    'database' => $database,
    'ddl' => false,
    'dml' => false,
    'suites' => [],
    'dropped' => false,
];
$created = false;
$openTicketIds = [];

$runScript = static function (mysqli $connection, string $path): void {
    $lines = preg_split('/\\R/', (string)file_get_contents($path)) ?: [];
    $delimiter = ';';
    $buffer = '';
    $flush = static function (string $sql) use ($connection): void {
        if (trim($sql) === '') return;
        if (!$connection->multi_query($sql)) throw new RuntimeException($connection->error);
        do {
            if ($result = $connection->store_result()) $result->free();
        } while ($connection->more_results() && $connection->next_result());
        if ($connection->errno) throw new RuntimeException($connection->error);
    };
    foreach ($lines as $line) {
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $matches) === 1) {
            $flush($buffer);
            $buffer = '';
            $delimiter = trim($matches[1]);
            continue;
        }
        $buffer .= $line . "\n";
        if ($delimiter !== ';' && str_ends_with(rtrim($buffer), $delimiter)) {
            $flush(substr(rtrim($buffer), 0, -strlen($delimiter)));
            $buffer = '';
        }
    }
    $flush($buffer);
};

$ejecutarSuite = static function (string $script) use ($database): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script)
        . ' --db=' . escapeshellarg($database);
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar ' . $script . '.');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $suite = json_decode(trim((string)$stdout), true);
    return is_array($suite)
        ? $suite + ['exit_code' => $exitCode, 'stderr' => trim((string)$stderr)]
        : ['ok' => $exitCode === 0, 'raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr), 'exit_code' => $exitCode];
};

try {
    if (!$db->query('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
        throw new RuntimeException($db->error);
    }
    $created = true;
    if (!$db->select_db($database)) throw new RuntimeException($db->error);
    mysqli_query($db, "SET time_zone = '-06:00'");
    ActiveRecord::setDB($db);
    $runScript($db, dirname(__DIR__, 2) . '/database/ddl.sql');
    $resultado['ddl'] = true;
    $runScript($db, dirname(__DIR__, 2) . '/database/dml.sql');
    $resultado['dml'] = true;
    $openTickets = $db->query("SELECT id FROM tickets WHERE estado = 'abierto' AND closed_at IS NULL");
    if ($openTickets) {
        while ($ticket = $openTickets->fetch_assoc()) $openTicketIds[] = (int)$ticket['id'];
        $openTickets->free();
    }
    if ($openTicketIds !== []) {
        $db->query("UPDATE tickets SET estado = 'cerrado', closed_at = '2026-11-01 12:00:00' WHERE id IN (" . implode(',', $openTicketIds) . ")");
    }

    foreach ([
        'etapa9_5_concurrencia_integrada.php',
        'etapa9_mapa_manual.php',
        'etapa9_concurrencia.php',
        'etapa7_5_concurrencia_cruzada.php',
        'pos_reservacion_contrato.php',
        'pos_reservacion_integrado.php',
    ] as $script) {
        $resultado['suites'][$script] = $ejecutarSuite($script);
    }
    $resultado['ok'] = true;
    foreach ($resultado['suites'] as $suite) {
        if (($suite['ok'] ?? false) !== true) {
            $resultado['ok'] = false;
        }
    }
} catch (Throwable $error) {
    $resultado['error'] = $error->getMessage();
} finally {
    if ($openTicketIds !== []) {
        $db->query("UPDATE tickets SET estado = 'abierto', closed_at = NULL WHERE id IN (" . implode(',', $openTicketIds) . ")");
    }
    if ($created && !$db->query('DROP DATABASE IF EXISTS ' . $database)) {
        $resultado['error_drop'] = $db->error;
    } else {
        $resultado['dropped'] = $created;
    }
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] && $resultado['dropped'] ? 0 : 1);
