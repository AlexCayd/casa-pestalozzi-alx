<?php

declare(strict_types=1);

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\HorarioReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;

$worker = in_array('--worker', $argv ?? [], true);
$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexión MySQL para la prueba de concurrencia.\n");
    exit(2);
}

if ($worker) {
    $entrada = [
        'nombre' => 'Etapa 6 Concurrencia',
        'tipo_contacto' => 'email',
        'contacto' => (string)getenv('ETAPA6_CONCURRENCY_CONTACT'),
        'fecha' => (string)getenv('ETAPA6_CONCURRENCY_DATE'),
        'hora' => (string)getenv('ETAPA6_CONCURRENCY_HOUR'),
        'personas' => 2,
        'notas' => '',
        'request_token' => (string)getenv('ETAPA6_CONCURRENCY_TOKEN'),
    ];
    $resultado = ReservacionPublicaService::crearRetencion($entrada);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($resultado['ok'] ?? false) ? 0 : 1);
}

$query = static function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
    return $result;
};
$escape = static fn(string $value): string => $db->real_escape_string($value);
$date = '';
$hour = '';
$contact = 'etapa6-concurrency-' . bin2hex(random_bytes(6)) . '@example.test';
$tokens = [
    'ETAPA6_CONC_A_' . bin2hex(random_bytes(8)),
    'ETAPA6_CONC_B_' . bin2hex(random_bytes(8)),
];
$processes = [];

try {
    for ($offset = 5; $offset <= 80; $offset++) {
        $candidate = (new DateTimeImmutable('2026-11-01', ReservacionConfig::timezone()))
            ->modify('+' . $offset . ' days')->format('Y-m-d');
        $sqlDate = $escape($candidate);
        $result = $query("SELECT
            (SELECT COUNT(*) FROM reservaciones WHERE fecha = '{$sqlDate}') AS reservations,
            (SELECT COUNT(*) FROM excepciones_operacion WHERE fecha = '{$sqlDate}') AS exceptions");
        $row = $result->fetch_assoc();
        $result->free();
        if ((int)$row['reservations'] !== 0 || (int)$row['exceptions'] !== 0) {
            continue;
        }
        $calendar = HorarioReservacionService::resolverFecha($candidate, ReservacionConfig::ahora());
        if (($calendar['reservable'] ?? false) && !empty($calendar['horarios_candidatos'])) {
            $date = $candidate;
            $hour = substr((string)$calendar['horarios_candidatos'][2], 0, 5);
            break;
        }
    }
    if ($date === '' || $hour === '') {
        throw new RuntimeException('No se encontró un turno libre para concurrencia.');
    }

    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $command = '"' . PHP_BINARY . '" "' . __FILE__ . '" --worker';
    foreach ($tokens as $index => $token) {
        putenv('ETAPA6_CONCURRENCY_CONTACT=' . $contact);
        putenv('ETAPA6_CONCURRENCY_DATE=' . $date);
        putenv('ETAPA6_CONCURRENCY_HOUR=' . $hour);
        putenv('ETAPA6_CONCURRENCY_TOKEN=' . $token);
        $pipes = [];
        $processes[$index] = [
            'handle' => proc_open($command, $descriptor, $pipes, dirname(__DIR__, 2)),
            'pipes' => $pipes,
            'token' => $token,
        ];
        if (!is_resource($processes[$index]['handle'])) {
            throw new RuntimeException('No fue posible iniciar el proceso concurrente ' . $index . '.');
        }
        fclose($pipes[0]);
    }

    $results = [];
    foreach ($processes as $index => $process) {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exitCode = proc_close($process['handle']);
        $decoded = json_decode(trim($stdout), true);
        $results[] = [
            'index' => $index,
            'exit_code' => $exitCode,
            'resultado' => is_array($decoded) ? $decoded : ['raw' => trim($stdout), 'stderr' => trim($stderr)],
        ];
    }

    $successes = count(array_filter($results, static fn(array $item): bool => ($item['resultado']['codigo'] ?? '') === ReservacionPublicaService::RETENCION_CREADA));
    $duplicates = count(array_filter($results, static fn(array $item): bool => ($item['resultado']['codigo'] ?? '') === ReservacionPublicaService::RESERVACION_DUPLICADA));

    $ids = [];
    foreach ($tokens as $token) {
        $sqlToken = $escape($token);
        $found = $query("SELECT id FROM reservaciones WHERE request_token = '{$sqlToken}'");
        while ($row = $found->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $found->free();
    }
    if ($ids !== []) {
        $idList = implode(',', array_unique($ids));
        $query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$idList})");
        $query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$idList})");
        $query("DELETE FROM reservaciones WHERE id IN ({$idList})");
    }

    $ok = $successes === 1 && $duplicates === 1;
    echo json_encode([
        'ok' => $ok,
        'processes' => 2,
        'successes' => $successes,
        'duplicates' => $duplicates,
        'date' => $date,
        'hour' => $hour,
        'results' => $results,
        'cleanup' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    foreach ($processes as $process) {
        if (isset($process['pipes'][1]) && is_resource($process['pipes'][1])) {
            fclose($process['pipes'][1]);
        }
        if (isset($process['pipes'][2]) && is_resource($process['pipes'][2])) {
            fclose($process['pipes'][2]);
        }
        if (isset($process['handle']) && is_resource($process['handle'])) {
            proc_terminate($process['handle']);
            proc_close($process['handle']);
        }
    }
    fwrite(STDERR, "ETAPA6_CONCURRENCIA_FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
