<?php

declare(strict_types=1);

/** Instalación temporal de Etapa 11.9 y suites históricas reconciliadas. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;

$db = ActiveRecord::getDB();
$suffix = date('YmdHis') . '_' . bin2hex(random_bytes(4));
$database = 'casa_pestalozzi_etapa119_clean_' . $suffix;
$result = [
    'ok' => false,
    'suite' => 'etapa11_9_instalacion_limpia',
    'database' => $database,
    'ddl' => false,
    'dml' => false,
    'correcciones' => null,
    'etapa9_5_reconciliada' => null,
    'etapa11_5' => null,
    'etapa11_7_2' => null,
    'dropped' => false,
];

$runSqlFile = static function (mysqli $connection, string $path): void {
    $lines = preg_split('/\R/', (string)file_get_contents($path)) ?: [];
    $delimiter = ';';
    $buffer = '';
    $flush = static function (string $sql) use ($connection): void {
        if (trim($sql) === '') {
            return;
        }
        if (!$connection->multi_query($sql)) {
            throw new RuntimeException($connection->error . ' - script');
        }
        do {
            if ($stored = $connection->store_result()) {
                $stored->free();
            }
        } while ($connection->more_results() && $connection->next_result());
        if ($connection->errno) {
            throw new RuntimeException($connection->error . ' - script');
        }
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

$runScript = static function (string $script, ?string $database = null): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script);
    if ($database !== null) {
        $command .= ' --db=' . escapeshellarg($database);
    }
    $output = [];
    $exitCode = 1;
    exec($command . ' 2>&1', $output, $exitCode);
    $decoded = json_decode(implode("\n", $output), true);
    return [
        'ok' => $exitCode === 0 && is_array($decoded) && ($decoded['ok'] ?? false) === true,
        'exit_code' => $exitCode,
        'output' => is_array($decoded) ? $decoded : implode("\n", $output),
    ];
};

try {
    if (!$db->query(
        "CREATE DATABASE " . $database . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    )) {
        throw new RuntimeException($db->error);
    }
    if (!$db->select_db($database)) {
        throw new RuntimeException($db->error);
    }
    $db->query("SET time_zone = '-06:00'");
    ActiveRecord::setDB($db);
    $runSqlFile($db, dirname(__DIR__, 2) . '/database/ddl.sql');
    $result['ddl'] = true;
    $runSqlFile($db, dirname(__DIR__, 2) . '/database/dml.sql');
    $result['dml'] = true;

    $result['correcciones'] = $runScript('etapa11_9_correcciones.php', $database);
    // Etapa 9.5 ahora espera snapshots listos antes de abrir la barrera.
    $result['etapa9_5_reconciliada'] = $runScript('etapa9_5_instalacion_limpia.php');
    $result['etapa11_5'] = $runScript('etapa11_5_instalacion_limpia.php');
    $result['etapa11_7_2'] = $runScript('etapa11_7_2_instalacion_limpia.php');
    $result['ok'] = $result['ddl'] && $result['dml']
        && ($result['correcciones']['ok'] ?? false)
        && ($result['etapa9_5_reconciliada']['ok'] ?? false)
        && ($result['etapa11_5']['ok'] ?? false)
        && ($result['etapa11_7_2']['ok'] ?? false);
} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
} finally {
    if ($db instanceof mysqli) {
        $db->query("DROP DATABASE IF EXISTS " . $database);
        $result['dropped'] = true;
    }
}

$result['ok'] = $result['ok'] && $result['dropped'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
