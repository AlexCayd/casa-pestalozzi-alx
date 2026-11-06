<?php

declare(strict_types=1);

/** Ejecuta los contratos de análisis, Asistente y finanzas en una instalación temporal. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
date_default_timezone_set((string) ($_ENV['APP_TIMEZONE'] ?? 'America/Mexico_City'));

$config = [];
foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DEV_SUPERADMIN_PASSWORD'] as $key) {
    $config[$key] = (string) ($_ENV[$key] ?? getenv($key) ?: '');
}
$config['DB_PORT'] = (int) ($_ENV['DB_PORT'] ?? 3306);
if (!in_array((string) ($_ENV['APP_ENV'] ?? ''), ['development', 'test'], true)
    || strlen($config['DEV_SUPERADMIN_PASSWORD']) < 12
    || $config['DB_HOST'] === '' || $config['DB_USER'] === '') {
    fwrite(STDERR, "Configura un entorno local de desarrollo/pruebas para el harness aislado.\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$server = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASS'], '', $config['DB_PORT']);
$server->set_charset('utf8mb4');
$base = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
$ownerToken = '';
$failure = null;
$limpieza = null;

$_ENV['APP_ENV'] = 'test';
$_ENV['DB_NAME'] = $base;
$_ENV['NIP_LOOKUP_SECRET'] = bin2hex(random_bytes(32));
foreach ([
    'APP_ENV' => 'test',
    'DB_NAME' => $base,
    'DB_HOST' => $config['DB_HOST'],
    'DB_USER' => $config['DB_USER'],
    'DB_PASS' => $config['DB_PASS'],
    'DB_PORT' => (string) $config['DB_PORT'],
    'NIP_LOOKUP_SECRET' => $_ENV['NIP_LOOKUP_SECRET'],
    'DEV_SUPERADMIN_PASSWORD' => $config['DEV_SUPERADMIN_PASSWORD'],
] as $key => $value) {
    putenv($key . '=' . $value);
}

try {
    Services\Development\Instalacion::ejecutar(
        $server,
        $base,
        'test',
        $config['DEV_SUPERADMIN_PASSWORD'],
        true,
        static function (string $nombre, string $token) use (&$ownerToken): void {
            $ownerToken = $token;
        }
    );

    $versiones = (int) $server->query(
        "SELECT COUNT(*) FROM `{$base}`.esquema_versiones WHERE version IN "
        . "('multinegocio-3a-v1','multinegocio-3b-v1','multinegocio-3c-v1')"
    )->fetch_row()[0];
    if ($versiones !== 3) {
        throw new RuntimeException('La base temporal no quedó completa para iniciar las suites.');
    }

    foreach ([
        'scripts/tests/run-analisis-ambito-db.php' => 'Análisis de Panadería y Global:',
        'scripts/tests/run-consultas-ambito-db.php' => 'Consultas de Panadería y Global:',
        'scripts/tests/run-finanzas-ambito-db.php' => 'Finanzas de Panadería y Global:',
    ] as $script => $expected) {
        // variables_order omite E en algunas instalaciones PHP de XAMPP. La
        // aplicación lee DB_* desde $_ENV, así que copiamos el entorno heredado
        // antes de cargar includes/app.php en el proceso hijo.
        $bootstrap = '$keys = ["APP_ENV", "DB_NAME", "DB_HOST", "DB_USER", "DB_PASS", "DB_PORT", '
            . '"NIP_LOOKUP_SECRET", "DEV_SUPERADMIN_PASSWORD"]; '
            . 'foreach ($keys as $key) { $value = getenv($key); if ($value !== false) $_ENV[$key] = $value; } '
            . 'require $argv[1];';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $bootstrap, $root . '/' . $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null
        );
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar ' . $script . '.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($stdout !== '') {
            echo $stdout;
        }
        if ($exitCode !== 0 || !str_contains($stdout, $expected) || str_contains($stdout, 'SKIP:')
            || str_contains($stdout, 'La instalación no está disponible')) {
            throw new RuntimeException(
                $script . ' terminó sin su contrato esperado (código ' . $exitCode . '): '
                . trim((string) $stdout . ' ' . (string) $stderr)
            );
        }
        echo $script . ': PASS' . PHP_EOL;
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    if ($ownerToken !== '') {
        $propietario = null;
        try {
            $propietario = $server->execute_query(
                'SELECT propietario_token FROM `' . $base . '`.instalacion_desarrollo WHERE id=1'
            )->fetch_row()[0] ?? null;
        } catch (Throwable) {
            try {
                $propietario = $server->execute_query(
                    'SELECT token FROM `' . $base . '`._cp_instalacion_owner LIMIT 1'
                )->fetch_row()[0] ?? null;
            } catch (Throwable) {
                $propietario = null;
            }
        }
        if (is_string($propietario) && hash_equals($ownerToken, $propietario)) {
            try {
                $server->query('DROP DATABASE `' . $base . '`');
                echo 'Base temporal verificada y eliminada.' . PHP_EOL;
            } catch (Throwable $error) {
                $limpieza = $error;
            }
        } else {
            $limpieza = new RuntimeException('No se verificó la propiedad de la base temporal; no se eliminó.');
        }
    }
    $server->close();
}

if ($failure !== null) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
}
if ($limpieza !== null) {
    fwrite(STDERR, 'Limpieza: ' . $limpieza->getMessage() . PHP_EOL);
}
exit($failure === null && $limpieza === null ? 0 : 1);
