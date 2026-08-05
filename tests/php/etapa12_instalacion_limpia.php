<?php

declare(strict_types=1);

/** Instalación limpia y cierre técnico del módulo de reservaciones. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Model\ActiveRecord;

$root = dirname(__DIR__, 2);
$dotenv = Dotenv::createImmutable($root . '/includes');
$dotenv->safeLoad();
date_default_timezone_set('America/Mexico_City');

$suffix = date('YmdHis') . '_' . bin2hex(random_bytes(4));
$database = 'casa_pestalozzi_etapa12_clean_' . $suffix;
$result = [
    'ok' => false,
    'suite' => 'etapa12_instalacion_limpia',
    'database' => $database,
    'static_contract' => null,
    'ddl' => false,
    'dml' => false,
    'publica_reemplazo_cancelacion_csrf' => null,
    'administrativa_facade' => null,
    'pos_walkin_no_show_multimesa' => null,
    'concurrencia' => null,
    'dropped' => false,
];
$server = null;
$created = false;

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

$run = static function (string $script, string $targetDatabase) use ($root): array {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/' . $script)
        . ' --db=' . escapeshellarg($targetDatabase);
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

$read = static function (string $relative) use ($root): string {
    return (string)file_get_contents($root . '/' . $relative);
};

try {
    $staticFiles = [
        'public/index.php' => $read('public/index.php'),
        'classes/Auth.php' => $read('classes/Auth.php'),
        'services/ReservacionConfig.php' => $read('services/ReservacionConfig.php'),
        'services/AsignacionMesasService.php' => $read('services/AsignacionMesasService.php'),
        'services/ReservacionPublicaService.php' => $read('services/ReservacionPublicaService.php'),
        'services/ReservacionService.php' => $read('services/ReservacionService.php'),
        'services/MesaEstadoService.php' => $read('services/MesaEstadoService.php'),
        'services/PosReservacionQueryService.php' => $read('services/PosReservacionQueryService.php'),
    ];
    $staticCases = [
        'constantes_canonicas' => str_contains($staticFiles['services/ReservacionConfig.php'], 'DURACION_RESERVACION_MINUTOS')
            && str_contains($staticFiles['services/ReservacionConfig.php'], 'VIGENCIA_HOLD_MINUTOS')
            && str_contains($staticFiles['services/ReservacionConfig.php'], 'MAX_RESERVACIONES_ACTIVAS_POR_CONTACTO'),
        'aliases_retirados_ausentes' => !preg_match('/RESERVATION_HOLD_MINUTES|MAX_ACTIVE_RESERVATIONS|MAX_PUBLIC_GUESTS|MINUTOS_ADVERTENCIA_RESERVACION_PROXIMA|TOLERANCIA_RESERVACION_MINUTOS|DURACION_SERVICIO_ESTIMADA_MINUTOS|PAREJAS_MESAS_PUBLICAS_AUTORIZADAS|TRIOS_MESAS_PUBLICAS_AUTORIZADOS|COMBINACIONES_PUBLICAS_AUTORIZADAS/', implode("\n", $staticFiles)),
        'ocupacion_sin_forzado_fisico' => !str_contains($staticFiles['services/AsignacionMesasService.php'], 'forzarOcupacionFisica'),
        'otp_modificacion_unificado' => !str_contains($staticFiles['services/ReservacionPublicaService.php'], 'reenviarOtpModificacion'),
        'rutas_legacy_retiradas' => !str_contains($staticFiles['public/index.php'], '/api/operacion/horario-efectivo')
            && !str_contains($staticFiles['public/index.php'], '/api/liberar-reservacion')
            && !str_contains($staticFiles['public/index.php'], "'/admin/reservations/operation/assign-tables'")
            && !str_contains($staticFiles['public/index.php'], "'/admin/reservations/operation/update-comment'"),
        'rutas_canonicas_presentes' => str_contains($staticFiles['public/index.php'], '/admin/api/reservations/operation/assign-tables')
            && str_contains($staticFiles['public/index.php'], '/api/punto-de-venta/reservaciones/cancelar'),
        'facade_canonica' => str_contains($staticFiles['services/ReservacionService.php'], 'return ReservacionAdministrativaService::actualizar('),
        'mapa_pos_compartido' => str_contains($staticFiles['services/PosReservacionQueryService.php'], 'PosReservacionSerializer::SCHEMA_VERSION'),
    ];
    $result['static_contract'] = [
        'ok' => !in_array(false, $staticCases, true),
        'cases' => $staticCases,
    ];
    if (!$result['static_contract']['ok']) {
        throw new RuntimeException('Falló el contrato estático canónico.');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $server = @new mysqli(
        (string)($_ENV['DB_HOST'] ?? 'localhost'),
        (string)($_ENV['DB_USER'] ?? 'root'),
        (string)($_ENV['DB_PASS'] ?? '')
    );
    if ($server->connect_errno) {
        throw new RuntimeException('No hay conexión MySQL: ' . $server->connect_error);
    }
    $server->set_charset('utf8mb4');
    if (!$server->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        throw new RuntimeException($server->error);
    }
    $created = true;
    if (!$server->select_db($database)) {
        throw new RuntimeException($server->error);
    }
    $server->query("SET time_zone = '-06:00'");

    $_ENV['DB_NAME'] = $database;
    $_SERVER['DB_NAME'] = $database;
    putenv('DB_NAME=' . $database);
    require $root . '/includes/app.php';
    $db = ActiveRecord::getDB();
    ActiveRecord::setDB($db);

    $runSqlFile($db, $root . '/database/ddl.sql');
    $result['ddl'] = true;
    $runSqlFile($db, $root . '/database/dml.sql');
    $result['dml'] = true;

    $result['publica_reemplazo_cancelacion_csrf'] = $run('etapa11_9_correcciones.php', $database);
    $result['administrativa_facade'] = $run('etapa8_administrativa.php', $database);
    $result['pos_walkin_no_show_multimesa'] = $run('etapa10_integracion_operativa.php', $database);
    $result['concurrencia'] = $run('etapa11_5_concurrencia_completa.php', $database);
    $result['ok'] = ($result['static_contract']['ok'] ?? false)
        && $result['ddl']
        && $result['dml']
        && ($result['publica_reemplazo_cancelacion_csrf']['ok'] ?? false)
        && ($result['administrativa_facade']['ok'] ?? false)
        && ($result['pos_walkin_no_show_multimesa']['ok'] ?? false)
        && ($result['concurrencia']['ok'] ?? false);
} catch (Throwable $error) {
    $result['error'] = $error->getMessage();
} finally {
    if ($server instanceof mysqli && $created) {
        $server->query("DROP DATABASE IF EXISTS `{$database}`");
        $result['dropped'] = true;
    }
}

$result['ok'] = $result['ok'] && $result['dropped'];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
