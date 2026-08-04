<?php

declare(strict_types=1);

/** Runner PHP oficial: sólo ejecuta instalaciones temporales y suites aisladas. */

require dirname(__DIR__) . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__) . '/includes');
$dotenv->safeLoad();

$host = strtolower(trim((string)($_ENV['DB_HOST'] ?? getenv('DB_HOST') ?: '')));
$database = strtolower(trim((string)($_ENV['DB_NAME'] ?? getenv('DB_NAME') ?: '')));
if (!in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "El runner PHP sólo permite MySQL local; las suites crean sus propias bases temporales.\n");
    exit(2);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$scripts = [
    'etapa5_instalacion_limpia.php',
    'etapa11_5_instalacion_limpia.php',
];
$results = [];
$ok = true;
foreach ($scripts as $script) {
    $output = [];
    $exitCode = 1;
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/tests/php/' . $script);
    exec($command . ' 2>&1', $output, $exitCode);
    $decoded = json_decode(implode("\n", $output), true);
    $passed = $exitCode === 0 && is_array($decoded) && ($decoded['ok'] ?? false) === true;
    $results[] = [
        'script' => $script,
        'ok' => $passed,
        'exit_code' => $exitCode,
        'summary' => is_array($decoded)
            ? array_intersect_key($decoded, array_flip(['suite', 'passed', 'carreras', 'dropped']))
            : 'Salida no JSON',
    ];
    if (!$passed) $ok = false;
}

echo json_encode([
    'ok' => $ok,
    'runner' => 'scripts/run-tests.php',
    'database_policy' => 'local_temporal_only',
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
