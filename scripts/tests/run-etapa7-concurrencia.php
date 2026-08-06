<?php

declare(strict_types=1);

/**
 * Pruebas de cierre para locks, versiones e idempotencia del módulo.
 * Incluye una carrera real de dos procesos contra request_token único.
 */

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$root = dirname(__DIR__, 2);
if (in_array('--worker', $argv, true)) {
    etapa7cWorker($root);
    exit;
}

$keepDb = in_array('--keep-db', $argv, true);
$fallos = [];
$baseTemporal = null;
$servidor = null;
$db = null;
$afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    if (!$condicion) {
        $fallos[] = $mensaje;
    }
};

try {
    $env = etapa7cLeerEnv($root . '/includes/.env');
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $usuario = $env['DB_USER'] ?? 'root';
    $password = $env['DB_PASS'] ?? '';
    $baseActiva = strtolower(trim((string)($env['DB_NAME'] ?? '')));
    $baseTemporal = 'casa_pestalozzi_tmp_concurrency_' . gmdate('Ymd_His') . '_' . random_int(100, 999);
    $afirmar((bool)preg_match('/^casa_pestalozzi_tmp_concurrency_[a-z0-9_]+$/i', $baseTemporal), 'CONC: prefijo de base inválido.');
    $afirmar(strtolower($baseTemporal) !== $baseActiva, 'CONC: la base temporal coincide con la activa.');

    $servidor = new mysqli($host, $usuario, $password);
    $servidor->set_charset('utf8mb4');
    $servidor->query("CREATE DATABASE `{$baseTemporal}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db = new mysqli($host, $usuario, $password, $baseTemporal);
    $db->set_charset('utf8mb4');
    $db->query("SET time_zone = '-06:00'");
    etapa7cCargarSql($db, $root . '/database/ddl.sql');
    etapa7cFixtures($db);

    require_once $root . '/vendor/autoload.php';
    \Model\ActiveRecord::setDB($db);

    $contratos = [
        $root . '/services/ReservacionPublicaService.php',
        $root . '/services/ReservacionAdministrativaService.php',
        $root . '/services/AsignacionMesasService.php',
        $root . '/services/PuntoVentaReservacionService.php',
    ];
    foreach ($contratos as $archivo) {
        $contenido = (string)file_get_contents($archivo);
        $nombre = basename($archivo);
        $afirmar(str_contains($contenido, 'begin_transaction'), "CONC: {$nombre} no declara transacción.");
        $afirmar(str_contains($contenido, 'rollback'), "CONC: {$nombre} no declara rollback.");
    }
    $afirmar(str_contains((string)file_get_contents($root . '/services/AsignacionMesasService.php'), 'version_esperada'), 'CONC: reasignación sin control de versión.');
    $afirmar(str_contains((string)file_get_contents($root . '/services/AsignacionMesasService.php'), 'FOR UPDATE'), 'CONC: reasignación sin bloqueo de filas.');

    $fecha = (new DateTimeImmutable('now', new DateTimeZone('America/Mexico_City')))->modify('+30 days')->format('Y-m-d');
    $hora = '15:00:00';
    $token = 'etapa7-concurrencia-' . bin2hex(random_bytes(8));
    $workerArgs = [
        '--host', strtolower($host) === 'localhost' ? '127.0.0.1' : $host,
        '--user', $usuario,
        '--pass', $password,
        '--db', $baseTemporal,
        '--token', $token,
        '--fecha', $fecha,
        '--hora', $hora,
    ];
    $workers = [
        etapa7cIniciarWorker($root, $workerArgs),
        etapa7cIniciarWorker($root, $workerArgs),
    ];
    $resultados = [];
    foreach ($workers as $worker) {
        $resultados[] = etapa7cCerrarWorker($worker);
    }
    $creados = 0;
    $idempotentes = 0;
    foreach ($resultados as $resultado) {
        $payload = json_decode($resultado['output'], true);
        $afirmar($resultado['exit'] === 0 && is_array($payload), 'CONC-A: un trabajador no devolvió JSON válido: ' . trim($resultado['output']));
        if (is_array($payload) && ($payload['ok'] ?? false)) {
            if (($payload['idempotente'] ?? false) === true) {
                $idempotentes++;
            } else {
                $creados++;
            }
        }
    }
    $fila = $db->query("SELECT COUNT(*) FROM reservaciones WHERE request_token = '" . $db->real_escape_string($token) . "'")->fetch_row();
    $afirmar($creados === 1, 'CONC-A: no hubo exactamente un alta ganadora.');
    $afirmar($idempotentes === 1, 'CONC-A: el reintento concurrente no fue idempotente.');
    $afirmar((int)($fila[0] ?? 0) === 1, 'CONC-A: request_token produjo más de una reservación.');

    $conflicto = \Services\ReservacionAdministrativaService::crear([
        'nombre' => 'Cambio de token',
        'contacto_tipo' => 'email',
        'contacto' => 'concurrencia@example.test',
        'fecha' => $fecha,
        'hora' => $hora,
        'comensales' => 3,
        'nota' => '',
        'comentario_admin' => '',
        'request_token' => $token,
        'asignar_automaticamente' => '0',
        'confirmaciones' => ['SIN_ASIGNACION'],
    ], 1);
    $afirmar(($conflicto['codigo'] ?? '') === 'REQUEST_TOKEN_CONFLICTO', 'CONC-B: el mismo token con datos distintos no fue rechazado.');

    $waiter = $db->query("SELECT id FROM usuarios WHERE rol = 'waiter' AND activo = 1 ORDER BY id LIMIT 1")->fetch_row();
    $waiterId = (int)($waiter[0] ?? 0);
    $afirmar($waiterId > 0, 'CONC-C: no existe personal waiter para iniciar servicio.');
    if ($waiterId > 0) {
        $zona = new DateTimeZone('America/Mexico_City');
        $ahora = new DateTimeImmutable('now', $zona);
        $horaInicio = $ahora->modify('-5 minutes')->format('H:i:s');
        $stmt = $db->prepare("INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, request_token, estado) VALUES ('Carrera ticket', 'email', 'ticket@example.test', ?, ?, 2, 'admin', ?, 'confirmada')");
        $ticketToken = 'etapa7-ticket-' . bin2hex(random_bytes(8));
        $hoy = $ahora->format('Y-m-d');
        $stmt->bind_param('sss', $hoy, $horaInicio, $ticketToken);
        $stmt->execute();
        $reservacionTicket = $db->insert_id;
        $stmt->close();
        $stmt = $db->prepare('INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES (?, 1, 1)');
        $stmt->bind_param('i', $reservacionTicket);
        $stmt->execute();
        $stmt->close();

        $inicio1 = \Services\PuntoVentaReservacionService::comenzar($reservacionTicket, $waiterId, $waiterId);
        $inicio2 = \Services\PuntoVentaReservacionService::comenzar($reservacionTicket, $waiterId, $waiterId);
        $tickets = $db->query("SELECT COUNT(*) FROM tickets WHERE reservacion_id = {$reservacionTicket}")->fetch_row();
        $afirmar(($inicio1['ok'] ?? false) === true, 'CONC-C: el primer inicio de servicio falló.');
        $afirmar(($inicio2['ok'] ?? false) === true && ($inicio2['idempotente'] ?? false) === true, 'CONC-C: el segundo inicio no fue idempotente.');
        $afirmar((int)($tickets[0] ?? 0) === 1, 'CONC-C: se creó más de un ticket para la reservación.');
        $estado = $db->query("SELECT estado FROM reservaciones WHERE id = {$reservacionTicket}")->fetch_row();
        $afirmar(($estado[0] ?? '') === 'en_curso', 'CONC-C: la transición a en_curso quedó inconsistente.');
    }

    $zona = new DateTimeZone('America/Mexico_City');
    $ahora = new DateTimeImmutable('now', $zona);
    $horaAusencia = $ahora->modify('-30 minutes')->format('H:i:s');
    $stmt = $db->prepare("INSERT INTO reservaciones (nombre, contacto_tipo, fecha, hora, comensales, origen, request_token, estado) VALUES ('Carrera no show', 'ninguno', ?, ?, 2, 'admin', ?, 'confirmada')");
    $noShowToken = 'etapa7-no-show-' . bin2hex(random_bytes(8));
    $hoy = $ahora->format('Y-m-d');
    $stmt->bind_param('sss', $hoy, $horaAusencia, $noShowToken);
    $stmt->execute();
    $reservacionNoShow = $db->insert_id;
    $stmt->close();
    $noShow1 = \Services\PuntoVentaReservacionService::noShow($reservacionNoShow, 1, false, false, 'prueba de concurrencia');
    $noShow2 = \Services\PuntoVentaReservacionService::noShow($reservacionNoShow, 1, false, false, 'reintento de concurrencia');
    $afirmar(($noShow1['ok'] ?? false) === true, 'CONC-D: el primer no-show falló.');
    $afirmar(($noShow2['ok'] ?? false) === true && ($noShow2['idempotente'] ?? false) === true, 'CONC-D: el segundo no-show no fue idempotente.');
    $estadoNoShow = $db->query("SELECT estado FROM reservaciones WHERE id = {$reservacionNoShow}")->fetch_row();
    $afirmar(($estadoNoShow[0] ?? '') === 'no_show', 'CONC-D: el estado final de no-show es incorrecto.');

} catch (Throwable $e) {
    $fallos[] = 'CONC: ' . $e->getMessage();
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
    if ($servidor instanceof mysqli && is_string($baseTemporal) && !$keepDb && preg_match('/^casa_pestalozzi_tmp_concurrency_[a-z0-9_]+$/i', $baseTemporal)) {
        try {
            $servidor->query("DROP DATABASE IF EXISTS `{$baseTemporal}`");
        } catch (Throwable $e) {
            $fallos[] = 'CONC: no se pudo eliminar la base temporal: ' . $e->getMessage();
        }
    }
    if ($servidor instanceof mysqli) {
        $servidor->close();
    }
}

if ($fallos !== []) {
    fwrite(STDERR, "FAIL: concurrencia de reservaciones\n");
    foreach (array_unique($fallos) as $fallo) {
        fwrite(STDERR, '- ' . $fallo . "\n");
    }
    exit(1);
}
echo "PASS: concurrencia; alta=1+1 idempotente, token conflictivo, ticket idempotente y no-show idempotente.\n";
if ($keepDb) {
    echo "INFO: base temporal conservada: {$baseTemporal}\n";
}

function etapa7cWorker(string $root): void
{
    try {
        require_once $root . '/vendor/autoload.php';
        $args = $GLOBALS['argv'];
        $arg = static function (string $name, string $fallback = '') use ($args): string {
            $index = array_search($name, $args, true);
            return $index === false ? $fallback : (string)($args[$index + 1] ?? $fallback);
        };
        $host = $arg('--host', '127.0.0.1');
        $user = $arg('--user', 'root');
        $pass = $arg('--pass');
        $name = $arg('--db');
        $db = new mysqli($host, $user, $pass, $name);
        $db->set_charset('utf8mb4');
        $db->query("SET time_zone = '-06:00'");
        \Model\ActiveRecord::setDB($db);
        usleep(random_int(100000, 250000));
        $resultado = \Services\ReservacionAdministrativaService::crear([
            'nombre' => 'Carrera administrativa',
            'contacto_tipo' => 'email',
            'contacto' => 'concurrencia@example.test',
            'fecha' => $arg('--fecha'),
            'hora' => $arg('--hora'),
            'comensales' => 2,
            'nota' => '',
            'comentario_admin' => '',
            'request_token' => $arg('--token'),
            'asignar_automaticamente' => '0',
            'confirmaciones' => ['SIN_ASIGNACION'],
        ], 1);
        echo json_encode($resultado, JSON_UNESCAPED_UNICODE) . "\n";
        $db->close();
        exit(($resultado['ok'] ?? false) ? 0 : 1);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n";
        exit(1);
    }
}

/** @return array{process:resource,out:string,err:string} */
function etapa7cIniciarWorker(string $root, array $args): array
{
    $out = tempnam(sys_get_temp_dir(), 'etapa7-conc-out-');
    $err = tempnam(sys_get_temp_dir(), 'etapa7-conc-err-');
    $descriptor = [
        0 => ['file', 'NUL', 'r'],
        1 => ['file', $out, 'a'],
        2 => ['file', $err, 'a'],
    ];
    $process = proc_open(
        array_merge([PHP_BINARY, __FILE__, '--worker'], $args),
        $descriptor,
        $pipes,
        $root,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar trabajador concurrente.');
    }
    return ['process' => $process, 'out' => $out, 'err' => $err];
}

/** @param array{process:resource,out:string,err:string} $worker @return array{exit:int,output:string} */
function etapa7cCerrarWorker(array $worker): array
{
    $exit = proc_close($worker['process']);
    $output = trim((string)@file_get_contents($worker['out']));
    $error = trim((string)@file_get_contents($worker['err']));
    @unlink($worker['out']);
    @unlink($worker['err']);
    return ['exit' => $exit, 'output' => $output !== '' ? $output : $error];
}

/** @return array<string, string> */
function etapa7cLeerEnv(string $path): array
{
    $resultado = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
        $linea = trim($linea);
        if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
            continue;
        }
        [$clave, $valor] = explode('=', $linea, 2);
        $resultado[trim($clave)] = trim($valor, " \t\"'");
    }
    return $resultado;
}

function etapa7cCargarSql(mysqli $db, string $path): void
{
    $contenido = file_get_contents($path);
    if (!is_string($contenido)) {
        throw new RuntimeException("No fue posible leer {$path}.");
    }
    $delimiter = ';';
    $buffer = '';
    foreach (preg_split('/\R/', $contenido) ?: [] as $linea) {
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $linea, $matches)) {
            $delimiter = trim($matches[1]);
            continue;
        }
        $buffer .= $linea . "\n";
        $recortado = rtrim($buffer);
        if (!str_ends_with($recortado, $delimiter)) {
            continue;
        }
        $sentencia = trim(substr($recortado, 0, -strlen($delimiter)));
        $buffer = '';
        $sentencia = preg_replace('/\A\s*(?:(?:--[^\r\n]*|#[^\r\n]*|\/\*.*?\*\/)\s*)+/s', '', $sentencia) ?? $sentencia;
        if (trim($sentencia) === '') {
            continue;
        }
        $db->query($sentencia);
    }
}

function etapa7cFixtures(mysqli $db): void
{
    $db->query("INSERT INTO horarios_operacion (dia_semana, abierto, hora_apertura, hora_cierre) VALUES (0,1,'08:00','22:00'),(1,1,'08:00','22:00'),(2,1,'08:00','22:00'),(3,1,'08:00','22:00'),(4,1,'08:00','22:00'),(5,1,'08:00','22:00'),(6,1,'08:00','22:00')");
    $db->query("INSERT INTO mesas (numero, nombre, tipo, capacidad, pos_x, pos_y, reservable) VALUES (1,'Mesa 1','mesa',4,50,50,1),(2,'Mesa 2','mesa',4,60,50,1),(3,'Mesa 3','mesa',4,70,50,1)");
    $hash = password_hash('Etapa7Concurrente1!', PASSWORD_DEFAULT);
    $nip = password_hash('1234', PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO usuarios (username, nombre, password_hash, nip_hash, rol, activo) VALUES ('etapa7_admin','Etapa 7 Admin',?,?, 'admin',1),('etapa7_waiter','Etapa 7 Waiter',?,?, 'waiter',1)");
    $stmt->bind_param('ssss', $hash, $nip, $hash, $nip);
    $stmt->execute();
    $stmt->close();
}
