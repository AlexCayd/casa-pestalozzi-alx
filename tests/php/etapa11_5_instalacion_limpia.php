<?php

declare(strict_types=1);

/** Instalacion aislada de Etapa 11.5 y ejecucion de suites criticas. */

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;

$db = ActiveRecord::getDB();
$suffix = date('YmdHis') . '_' . bin2hex(random_bytes(4));
$database = 'casa_pestalozzi_etapa115_clean_' . $suffix;
$result = [
    'ok' => false,
    'suite' => 'etapa11_5_instalacion_limpia',
    'database' => $database,
    'ddl' => false,
    'dml' => false,
    'concurrencia' => null,
    'versionado' => null,
    'critical' => null,
    'dropped' => false,
];

$runScript = static function (mysqli $connection, string $path): void {
    $lines = preg_split('/\R/', (string)file_get_contents($path)) ?: [];
    $delimiter = ';';
    $buffer = '';
    $flush = static function (string $sql) use ($connection): void {
        if (trim($sql) === '') return;
        if (!$connection->multi_query($sql)) throw new RuntimeException($connection->error . ' - script');
        do {
            if ($stored = $connection->store_result()) $stored->free();
        } while ($connection->more_results() && $connection->next_result());
        if ($connection->errno) throw new RuntimeException($connection->error . ' - script');
    };
    foreach ($lines as $line) {
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $matches) === 1) {
            $flush($buffer); $buffer = ''; $delimiter = trim($matches[1]); continue;
        }
        $buffer .= $line . "\n";
        if ($delimiter !== ';' && str_ends_with(rtrim($buffer), $delimiter)) {
            $flush(substr(rtrim($buffer), 0, -strlen($delimiter))); $buffer = '';
        }
    }
    $flush($buffer);
};

$run = static function (string $script, string $database): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script)
        . ' --db=' . escapeshellarg($database);
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
    if (!$db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) throw new RuntimeException($db->error);
    if (!$db->select_db($database)) throw new RuntimeException($db->error);
    $db->query("SET time_zone = '-06:00'");
    ActiveRecord::setDB($db);
    $runScript($db, dirname(__DIR__, 2) . '/database/ddl.sql');
    $result['ddl'] = true;
    $runScript($db, dirname(__DIR__, 2) . '/database/dml.sql');
    $result['dml'] = true;

    $result['concurrencia'] = $run('etapa11_5_concurrencia_completa.php', $database);
    $result['versionado'] = $run('etapa11_5_version_asignaciones.php', $database);
    $result['critical'] = $run('etapa6_2_fecha_horarios_capacidad.php', $database);
    $result['ok'] = $result['ddl'] && $result['dml']
        && ($result['concurrencia']['ok'] ?? false)
        && ($result['versionado']['ok'] ?? false)
        && ($result['critical']['ok'] ?? false);
} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
} finally {
    if ($db instanceof mysqli) {
        $db->query("DROP DATABASE IF EXISTS `{$database}`");
        $result['dropped'] = true;
    }
}

$result['ok'] = $result['ok'] && $result['dropped'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
