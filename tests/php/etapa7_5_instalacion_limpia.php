<?php

declare(strict_types=1);

/**
 * Provisiona una base temporal desde DDL/DML y ejecuta la suite cruzada de
 * Etapa 7.5 dentro de esa misma base. La base se elimina siempre al terminar.
 */

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 11:20:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 11:20:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 11:20:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexión MySQL para crear la base limpia.\n");
    exit(2);
}

$suffix = date('YmdHis') . '_' . bin2hex(random_bytes(4));
$database = 'casa_pestalozzi_etapa75_clean_' . $suffix;
if (preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1) {
    throw new RuntimeException('Nombre de base temporal inválido.');
}

/** Ejecuta scripts SQL con bloques DELIMITER usados por el DDL. */
$runScript = static function (mysqli $connection, string $path): void {
    $lines = preg_split('/\R/', (string)file_get_contents($path)) ?: [];
    $delimiter = ';';
    $buffer = '';
    $flush = static function (string $sql) use ($connection): void {
        if (trim($sql) === '') {
            return;
        }
        if (!$connection->multi_query($sql)) {
            throw new RuntimeException($connection->error . ' — script');
        }
        do {
            if ($resultado = $connection->store_result()) {
                $resultado->free();
            }
        } while ($connection->more_results() && $connection->next_result());
        if ($connection->errno) {
            throw new RuntimeException($connection->error . ' — script');
        }
    };

    foreach ($lines as $linea) {
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $linea, $matches) === 1) {
            $flush($buffer);
            $buffer = '';
            $delimiter = trim($matches[1]);
            continue;
        }
        $buffer .= $linea . "\n";
        if ($delimiter !== ';' && str_ends_with(rtrim($buffer), $delimiter)) {
            $statement = substr(rtrim($buffer), 0, -strlen($delimiter));
            $flush($statement);
            $buffer = '';
        }
    }
    $flush($buffer);
};

$resultado = [
    'ok' => false,
    'database' => $database,
    'ddl' => false,
    'dml' => false,
    'suite' => null,
    'dropped' => false,
];

try {
    if (!$db->query('CREATE DATABASE ' . $database . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci')) {
        throw new RuntimeException($db->error);
    }
    if (!$db->select_db($database)) {
        throw new RuntimeException($db->error);
    }
    mysqli_query($db, "SET time_zone = '-06:00'");
    ActiveRecord::setDB($db);

    $runScript($db, dirname(__DIR__, 2) . '/database/ddl.sql');
    $resultado['ddl'] = true;
    $runScript($db, dirname(__DIR__, 2) . '/database/dml.sql');
    $resultado['dml'] = true;

    $command = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/etapa7_5_concurrencia_cruzada.php')
        . ' --db=' . escapeshellarg($database);
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open($command, $descriptor, $pipes, dirname(__DIR__, 2));
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar la suite cruzada sobre la base limpia.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $suite = json_decode(trim((string)$stdout), true);
    $resultado['suite'] = is_array($suite)
        ? $suite
        : ['raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr), 'exit_code' => $exitCode];
    $resultado['ok'] = $exitCode === 0 && ($suite['ok'] ?? false) === true;
} finally {
    if (!$db->query('DROP DATABASE IF EXISTS ' . $database)) {
        throw new RuntimeException('No se pudo eliminar la base temporal: ' . $db->error);
    }
    $resultado['dropped'] = true;
}

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($resultado['ok'] && $resultado['dropped'] ? 0 : 1);
