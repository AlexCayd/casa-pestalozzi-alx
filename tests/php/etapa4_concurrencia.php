<?php

declare(strict_types=1);

use Model\ActiveRecord;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;

$dbName = getenv('ETAPA4_TEST_DB_NAME') ?: '';
$worker = null;
$workerId = 0;
$workerSlot = 1;
$barrier = '';
$allowActive = false;

foreach ($argv as $argumento) {
    if (str_starts_with($argumento, '--db=')) {
        $dbName = substr($argumento, 5);
    } elseif (str_starts_with($argumento, '--worker=')) {
        $worker = substr($argumento, 9);
    } elseif (str_starts_with($argumento, '--id=')) {
        $workerId = (int)substr($argumento, 5);
    } elseif (str_starts_with($argumento, '--slot=')) {
        $workerSlot = (int)substr($argumento, 7);
    } elseif (str_starts_with($argumento, '--barrier=')) {
        $barrier = substr($argumento, 10);
    } elseif ($argumento === '--allow-active') {
        $allowActive = true;
    }
}

if ($dbName === '') {
    fwrite(STDERR, "Uso: php etapa4_concurrencia.php --db=casa_pestalozzi_etapa4_test [--allow-active]\n");
    exit(2);
}
if ($dbName === 'casa-pestalozzi' && !$allowActive) {
    fwrite(STDERR, "La base activa requiere --allow-active de forma explícita.\n");
    exit(2);
}
if ($allowActive) {
    putenv('ETAPA45_ALLOW_ACTIVE=YES');
}

if (!is_string(getenv('ETAPA4_CONCURRENCY_NOW')) || getenv('ETAPA4_CONCURRENCY_NOW') === '') {
    $zona = new DateTimeZone('America/Mexico_City');
    $ahoraBase = new DateTimeImmutable('now', $zona);
    putenv('ETAPA4_CONCURRENCY_NOW=' . $ahoraBase->format('Y-m-d') . ' 18:00:00');
}
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('RESERVATION_TEST_NOW=' . (string)getenv('ETAPA4_CONCURRENCY_NOW'));
$_ENV['RESERVATION_TEST_NOW'] = (string)getenv('ETAPA4_CONCURRENCY_NOW');
$_SERVER['RESERVATION_TEST_NOW'] = (string)getenv('ETAPA4_CONCURRENCY_NOW');

putenv('ETAPA4_TEST_DB_NAME=' . $dbName);
require __DIR__ . '/bootstrap_etapa4.php';

$db = ActiveRecord::getDB();
mysqli_report(MYSQLI_REPORT_OFF);

function concurrenciaExec(mysqli $db, string $sql): void
{
    if (!$db->query($sql)) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
}

/** @return array<string, mixed> */
function concurrenciaFila(mysqli $db, string $sql): array
{
    $resultado = $db->query($sql);
    if (!$resultado) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
    $fila = $resultado->fetch_assoc() ?: [];
    $resultado->free();
    return $fila;
}

/** @return array<int, array<string, mixed>> */
function concurrenciaFilas(mysqli $db, string $sql): array
{
    $resultado = $db->query($sql);
    if (!$resultado) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
    $filas = [];
    while ($fila = $resultado->fetch_assoc()) {
        $filas[] = $fila;
    }
    $resultado->free();
    return $filas;
}

function concurrenciaEscape(mysqli $db, string $valor): string
{
    return "'" . $db->real_escape_string($valor) . "'";
}

function concurrenciaEsperarBarra(string $barrier, string $nombre): void
{
    if (!is_dir($barrier)) {
        throw new RuntimeException('La barrera de concurrencia no existe.');
    }
    file_put_contents($barrier . DIRECTORY_SEPARATOR . $nombre . '.ready', '1', LOCK_EX);
    $inicio = microtime(true);
    $objetivos = [
        $barrier . DIRECTORY_SEPARATOR . 'worker-a.ready',
        $barrier . DIRECTORY_SEPARATOR . 'worker-b.ready',
    ];
    while (count(array_filter($objetivos, 'is_file')) < 2) {
        if (microtime(true) - $inicio > 20) {
            throw new RuntimeException('Timeout esperando la barrera de concurrencia.');
        }
        usleep(10000);
    }
}

/** @return array<string, mixed> */
function concurrenciaEjecutarWorker(string $worker, int $id): array
{
    $resultado = match ($worker) {
        'start' => PuntoVentaReservacionService::comenzar($id, 0),
        'no_show' => PuntoVentaReservacionService::noShow($id, 0, false, false, 'Prueba de concurrencia Etapa 4'),
        default => throw new InvalidArgumentException('Worker desconocido: ' . $worker),
    };
    return [
        'worker' => $worker,
        'id' => $id,
        'resultado' => $resultado,
    ];
}

if ($worker !== null) {
    try {
        concurrenciaEsperarBarra($barrier, $workerSlot === 1 ? 'worker-a' : 'worker-b');
        echo json_encode(concurrenciaEjecutarWorker($worker, $workerId), JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    } catch (Throwable $error) {
        echo json_encode([
            'worker' => $worker,
            'id' => $workerId,
            'error' => $error->getMessage(),
        ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(1);
    }
}

$marker = 'ETAPA4_CONC_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
$fecha = ReservacionConfig::ahora()->format('Y-m-d');
$horaNoShow = ReservacionConfig::ahora()->modify('-20 minutes')->format('H:i:s');
$horaStart = ReservacionConfig::ahora()->modify('-5 minutes')->format('H:i:s');
$horaMesaB = ReservacionConfig::ahora()->modify('-130 minutes')->format('H:i:s');
$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . strtolower($marker);
$casos = [];
$errores = [];

$registrar = static function (string $nombre, bool $ok, string $detalle = '') use (&$casos, &$errores): void {
    $casos[$nombre] = ['ok' => $ok, 'detalle' => $detalle];
    if (!$ok) {
        $errores[] = $nombre . ($detalle !== '' ? ': ' . $detalle : '');
    }
};

$insertarReservacion = static function (string $nombre, string $hora, ?string $fechaOverride = null) use ($db, $fecha): int {
    $fechaRegistro = $fechaOverride ?? $fecha;
    $stmt = $db->prepare(
        "INSERT INTO reservaciones
         (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado,
          request_token, estado_changed_at)
         VALUES (?, 'ninguno', NULL, ?, ?, 2, 'admin', 'confirmada', ?, NOW())"
    );
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    $token = $nombre . '_TOKEN';
    $stmt->bind_param('ssss', $nombre, $fechaRegistro, $hora, $token);
    if (!$stmt->execute()) {
        $mensaje = $stmt->error;
        $stmt->close();
        throw new RuntimeException($mensaje);
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
};

$asignarMesas = static function (int $reservacionId, array $mesaIds) use ($db): void {
    $stmt = $db->prepare('INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES (?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException($db->error);
    }
    foreach (array_values($mesaIds) as $indice => $mesaId) {
        $orden = $indice + 1;
        $stmt->bind_param('iii', $reservacionId, $mesaId, $orden);
        if (!$stmt->execute()) {
            $mensaje = $stmt->error;
            $stmt->close();
            throw new RuntimeException($mensaje);
        }
    }
    $stmt->close();
};

$quoteArg = static function (string $valor): string {
    return '"' . str_replace('"', '\\"', $valor) . '"';
};

$ejecutarProcesos = static function (array $workers, string $caseBarrier) use ($quoteArg, $allowActive): array {
    if (!mkdir($caseBarrier, 0700, true) && !is_dir($caseBarrier)) {
        throw new RuntimeException('No fue posible crear la barrera de concurrencia.');
    }
    $procesos = [];
    foreach ($workers as $indice => $datos) {
        $nombre = $indice === 0 ? 'worker-a' : 'worker-b';
        $argumentos = [
            $quoteArg(PHP_BINARY),
            $quoteArg(__FILE__),
            $quoteArg('--db=' . (getenv('ETAPA4_TEST_DB_NAME') ?: '')),
            $quoteArg('--worker=' . $datos['worker']),
            $quoteArg('--id=' . $datos['id']),
            $quoteArg('--slot=' . ($indice + 1)),
            $quoteArg('--barrier=' . $caseBarrier),
        ];
        if ($allowActive) {
            $argumentos[] = $quoteArg('--allow-active');
        }
        $comando = implode(' ', $argumentos);
        $pipes = [];
        $proceso = proc_open($comando, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($proceso)) {
            throw new RuntimeException('No fue posible iniciar el worker ' . $nombre . '.');
        }
        fclose($pipes[0]);
        $procesos[] = ['nombre' => $nombre, 'proceso' => $proceso, 'pipes' => $pipes];
    }

    $salidas = [];
    foreach ($procesos as $proceso) {
        $stdout = stream_get_contents($proceso['pipes'][1]);
        $stderr = stream_get_contents($proceso['pipes'][2]);
        fclose($proceso['pipes'][1]);
        fclose($proceso['pipes'][2]);
        $codigo = proc_close($proceso['proceso']);
        $lineas = array_values(array_filter(array_map('trim', explode("\n", trim($stdout)))));
        $json = $lineas !== [] ? json_decode((string)end($lineas), true) : null;
        $salidas[] = [
            'nombre' => $proceso['nombre'],
            'codigo' => $codigo,
            'resultado' => is_array($json) ? $json : null,
            'stderr' => trim($stderr),
            'stdout' => trim($stdout),
        ];
    }
    return $salidas;
};

$limpiar = static function () use ($db, $marker, $baseDir): void {
    $db->query("DELETE FROM tickets WHERE nombre LIKE '" . $db->real_escape_string($marker) . "%'");
    $db->query("DELETE FROM reservaciones WHERE nombre LIKE '" . $db->real_escape_string($marker) . "%'");
    if (is_dir($baseDir)) {
        foreach (glob($baseDir . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }
        @rmdir($baseDir);
    }
};

try {
    $mesas = concurrenciaFilas(
        $db,
        "SELECT m.id
         FROM mesas m
         WHERE m.activo = 1
           AND NOT EXISTS (
             SELECT 1 FROM ticket_mesas tm
             INNER JOIN tickets t ON t.id = tm.ticket_id
             WHERE tm.mesa_id = m.id AND t.estado = 'abierto'
           )
           AND NOT EXISTS (
             SELECT 1 FROM reservacion_mesas rm
             INNER JOIN reservaciones r ON r.id = rm.reservacion_id
             WHERE rm.mesa_id = m.id
               AND r.fecha = '{$db->real_escape_string($fecha)}'
               AND r.estado IN ('pendiente_verificacion','confirmada','en_curso')
           )
         ORDER BY m.id
         LIMIT 2"
    );
    $mesaIds = array_map(static fn(array $fila): int => (int)$fila['id'], $mesas);
    if (count($mesaIds) < 2) {
        throw new RuntimeException('No hay dos mesas libres para la prueba de concurrencia.');
    }

    $idCarrera = $insertarReservacion($marker . '_START_NO_SHOW', $horaNoShow);
    $asignarMesas($idCarrera, [$mesaIds[0]]);
    $salidasCarrera = $ejecutarProcesos([
        ['worker' => 'start', 'id' => $idCarrera],
        ['worker' => 'no_show', 'id' => $idCarrera],
    ], $baseDir . '_start_no_show');
    $filaCarrera = concurrenciaFila($db, "SELECT estado FROM reservaciones WHERE id = {$idCarrera}");
    $ticketCarrera = concurrenciaFila(
        $db,
        "SELECT COUNT(*) AS total FROM tickets WHERE reservacion_id = {$idCarrera} AND estado = 'abierto'"
    );
    $resultadosCarrera = array_values(array_filter(
        array_map(static fn(array $salida) => $salida['resultado']['resultado'] ?? null, $salidasCarrera),
        'is_array'
    ));
    $exitosCarrera = count(array_filter($resultadosCarrera, static fn(array $r): bool => ($r['ok'] ?? false) === true));
    $estadoCarrera = (string)($filaCarrera['estado'] ?? '');
    $ticketAbiertoCarrera = (int)($ticketCarrera['total'] ?? 0);
    $invarianteCarrera = ($exitosCarrera === 1)
        && (($estadoCarrera === 'no_show' && $ticketAbiertoCarrera === 0)
            || ($estadoCarrera === 'en_curso' && $ticketAbiertoCarrera === 1));
    $registrar(
        'carrera iniciar vs no_show con dos conexiones',
        $invarianteCarrera,
        json_encode([
            'workers' => $salidasCarrera,
            'estado_final' => $estadoCarrera,
            'tickets_abiertos' => $ticketAbiertoCarrera,
        ], JSON_UNESCAPED_UNICODE)
    );

    $idMesaA = $insertarReservacion($marker . '_MESA_A', $horaStart);
    $idMesaB = $insertarReservacion($marker . '_MESA_B', $horaMesaB);
    $asignarMesas($idMesaA, $mesaIds);
    $asignarMesas($idMesaB, $mesaIds);
    $salidasMesas = $ejecutarProcesos([
        ['worker' => 'start', 'id' => $idMesaA],
        ['worker' => 'start', 'id' => $idMesaB],
    ], $baseDir . '_mesas');
    $estadosMesas = concurrenciaFilas(
        $db,
        "SELECT id, estado FROM reservaciones WHERE id IN ({$idMesaA}, {$idMesaB}) ORDER BY id"
    );
    $ticketsMesas = concurrenciaFila(
        $db,
        "SELECT COUNT(DISTINCT t.id) AS tickets, COUNT(tm.id) AS ticket_mesas
         FROM tickets t
         LEFT JOIN ticket_mesas tm ON tm.ticket_id = t.id
         WHERE t.reservacion_id IN ({$idMesaA}, {$idMesaB}) AND t.estado = 'abierto'"
    );
    $resultadosMesas = array_values(array_filter(
        array_map(static fn(array $salida) => $salida['resultado']['resultado'] ?? null, $salidasMesas),
        'is_array'
    ));
    $exitosMesas = count(array_filter($resultadosMesas, static fn(array $r): bool => ($r['ok'] ?? false) === true));
    $estados = array_map(static fn(array $fila): string => (string)$fila['estado'], $estadosMesas);
    sort($estados);
    $ticketCount = (int)($ticketsMesas['tickets'] ?? 0);
    $ticketMesaCount = (int)($ticketsMesas['ticket_mesas'] ?? 0);
    $invarianteMesas = $exitosMesas === 1
        && $estados === ['confirmada', 'en_curso']
        && $ticketCount === 1
        && $ticketMesaCount === count($mesaIds);
    $registrar(
        'carrera iniciar misma multimesa',
        $invarianteMesas,
        json_encode([
            'workers' => $salidasMesas,
            'estados_finales' => $estadosMesas,
            'tickets_abiertos' => $ticketsMesas,
        ], JSON_UNESCAPED_UNICODE)
    );
} catch (Throwable $error) {
    $errores[] = 'excepcion: ' . $error->getMessage();
} finally {
    $limpiar();
}

echo json_encode([
    'ok' => $errores === [],
    'database' => $dbName,
    'casos' => $casos,
    'errores' => $errores,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($errores === [] ? 0 : 1);
