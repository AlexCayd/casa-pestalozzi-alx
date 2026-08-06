<?php

declare(strict_types=1);

/**
 * Orquestador reproducible de la validación final del módulo.
 * No absorbe la salida de los runners: cada fallo conserva su diagnóstico.
 */

$root = dirname(__DIR__, 2);
$dynamic = in_array('--dynamic', $argv, true);
$keepDb = in_array('--keep-db', $argv, true);
$suite = [];

$comandos = [
    ['Catálogo de errores', [PHP_BINARY, $root . '/scripts/tests/run-reservaciones-catalogo.php']],
    ['Auditoría de errores', [PHP_BINARY, $root . '/scripts/auditar-errores-reservaciones.php']],
    ['Motor temporal puro', [PHP_BINARY, $root . '/scripts/tests/run-etapa4-motor-temporal.php']],
    ['Capacidad pura', [PHP_BINARY, $root . '/scripts/tests/run-etapa5-capacidad.php']],
    ['Shell y contratos autenticados', [PHP_BINARY, $root . '/scripts/tests/run-etapa6-flujos-autenticados.php', '--static-only']],
    ['Instalación limpia', [PHP_BINARY, $root . '/scripts/tests/run-instalacion-limpia-reservaciones.php']],
    ['Concurrencia e idempotencia', [PHP_BINARY, $root . '/scripts/tests/run-etapa7-concurrencia.php']],
];

if ($dynamic) {
    $comandos[] = ['Motor temporal dinámico', [PHP_BINARY, $root . '/scripts/tests/run-etapa4-motor-temporal.php', '--dynamic']];
    $comandos[] = ['Capacidad dinámica', [PHP_BINARY, $root . '/scripts/tests/run-etapa5-capacidad.php', '--dynamic']];
    $comandos[] = ['Flujos autenticados', [PHP_BINARY, $root . '/scripts/tests/run-etapa6-flujos-autenticados.php']];
}

if ($keepDb) {
    foreach ($comandos as $indice => $comando) {
        if (in_array($comando[0], ['Instalación limpia', 'Concurrencia e idempotencia'], true)) {
            $comandos[$indice][1][] = '--keep-db';
        }
    }
}

foreach ($comandos as [$nombre, $command]) {
    $inicio = microtime(true);
    $resultado = etapa7IntegralEjecutar($command, $root);
    $duracion = microtime(true) - $inicio;
    $suite[] = [
        'nombre' => $nombre,
        'command' => implode(' ', array_map('strval', $command)),
        'resultado' => $resultado,
        'duracion' => $duracion,
    ];
}

$fallos = array_filter($suite, static fn(array $item): bool => $item['resultado']['exit'] !== 0);
echo "Suite | Comprobaciones | Aprobadas | Fallidas | Duración\n";
echo "--- | --- | ---: | ---: | ---:\n";
foreach ($suite as $item) {
    $estado = $item['resultado']['exit'] === 0 ? 'PASS' : 'FAIL';
    $salida = trim((string)$item['resultado']['output']);
    $ultimaLinea = $salida === '' ? 'sin salida' : preg_split('/\R/', $salida)[count(preg_split('/\R/', $salida)) - 1];
    echo '| ' . $item['nombre']
        . ' | ' . $estado
        . ' — ' . str_replace('|', '\\|', (string)$ultimaLinea)
        . ' | ' . ($estado === 'PASS' ? '1' : '0')
        . ' | ' . ($estado === 'FAIL' ? '1' : '0')
        . ' | ' . number_format($item['duracion'], 2, '.', '') . " s |\n";
}

if ($fallos !== []) {
    fwrite(STDERR, "\nDiagnóstico de fallos internos:\n");
    foreach ($fallos as $item) {
        fwrite(STDERR, "\n[{$item['nombre']}] {$item['command']}\n");
        fwrite(STDERR, trim((string)$item['resultado']['output']) . "\n");
    }
    exit(1);
}

echo "Aprobadas: " . count($suite) . "; Fallidas: 0; Duración total: " . number_format(array_sum(array_column($suite, 'duracion')), 2, '.', '') . " s.\n";

/** @param array<int, string> $command @return array{exit:int,output:string} */
function etapa7IntegralEjecutar(array $command, string $cwd): array
{
    $out = tempnam(sys_get_temp_dir(), 'etapa7-suite-out-');
    $err = tempnam(sys_get_temp_dir(), 'etapa7-suite-err-');
    $descriptor = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $out, 'a'],
        2 => ['file', $err, 'a'],
    ];
    $process = proc_open($command, $descriptor, $pipes, $cwd, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        @unlink($out);
        @unlink($err);
        return ['exit' => 1, 'output' => 'No fue posible iniciar el runner.'];
    }
    $exit = proc_close($process);
    $output = (string)@file_get_contents($out) . (string)@file_get_contents($err);
    @unlink($out);
    @unlink($err);
    return ['exit' => $exit, 'output' => $output];
}
