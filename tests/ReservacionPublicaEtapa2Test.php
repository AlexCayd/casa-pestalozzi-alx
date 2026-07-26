<?php

/**
 * Integración reproducible de creación, retención, gestión y concurrencia.
 *
 * Sólo muta la base desechable casa_pestalozzi_etapa2_test y confirma el nombre
 * conectado antes de ejecutar cualquier caso.
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;
use Model\Reservacion;
use Services\AsignacionMesasService;
use Services\ContactoAccesoService;
use Services\DisponibilidadReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');
ini_set('session.save_path', __DIR__ . '/.sessions');

$databaseName = 'casa_pestalozzi_etapa2_test';
$keepDatabase = in_array('--keep-database', $argv ?? [], true);
$db = mysqli_connect(
    (string)($_ENV['DB_HOST'] ?? 'localhost'),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? '')
);
if (!$db) {
    throw new RuntimeException('No fue posible conectar con MySQL para la prueba.');
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$tests = 0;
$failures = [];

function e2Assert(string $name, bool $condition): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $name;
    }
}

function e2Same(string $name, $actual, $expected): void
{
    e2Assert($name . ': esperado ' . var_export($expected, true) . ', recibido ' . var_export($actual, true), $actual === $expected);
}

function e2SqlFile(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('No fue posible leer ' . $path);
    }
    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function e2Payload(string $token, string $contacto, string $fecha, string $hora, int $personas = 2): array
{
    return [
        'nombre' => 'Cliente Etapa 2',
        'tipo_contacto' => 'email',
        'contacto' => $contacto,
        'fecha' => $fecha,
        'hora' => $hora,
        'personas' => $personas,
        'notas' => 'Prueba automática',
        'request_token' => $token,
    ];
}

function e2Count(mysqli $db, string $sql): int
{
    return (int)($db->query($sql)->fetch_assoc()['total'] ?? 0);
}

/**
 * Ejecuta dos procesos PHP con conexiones mysqli independientes y una barrera
 * común, de modo que ambas solicitudes compitan realmente.
 *
 * @return array<int, array<string, mixed>>
 */
function e2Race(string $databaseName, array $payloads): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'casa_pestalozzi_e2_' . bin2hex(random_bytes(5));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('No fue posible crear el directorio temporal de concurrencia.');
    }
    $go = $dir . DIRECTORY_SEPARATOR . 'go';
    $processes = [];
    $paths = [];
    foreach (array_values($payloads) as $index => $payload) {
        $ready = $dir . DIRECTORY_SEPARATOR . "ready{$index}";
        $result = $dir . DIRECTORY_SEPARATOR . "result{$index}.json";
        $command = [
            PHP_BINARY,
            __DIR__ . '/ReservacionEtapa2ConcurrencyWorker.php',
            $databaseName,
            base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $ready,
            $go,
            $result,
        ];
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('No fue posible iniciar un worker de concurrencia.');
        }
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
        $paths[] = [$ready, $result];
    }

    $deadline = microtime(true) + 15;
    while (microtime(true) < $deadline) {
        if (is_file($paths[0][0]) && is_file($paths[1][0])) {
            break;
        }
        usleep(10000);
    }
    file_put_contents($go, 'go');

    $responses = [];
    foreach ($processes as $index => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            throw new RuntimeException("Worker {$index} falló ({$status}): {$stdout} {$stderr}");
        }
        $encoded = file_get_contents($paths[$index][1]);
        $decoded = is_string($encoded) ? json_decode($encoded, true) : null;
        if (!is_array($decoded)) {
            throw new RuntimeException("Worker {$index} no produjo JSON.");
        }
        $responses[] = $decoded;
    }

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($dir);
    return $responses;
}

try {
    if (!$keepDatabase) {
        $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    }
    $db->query("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($databaseName);
    $db->query("SET time_zone = '-06:00'");
    $connected = (string)$db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'];
    if ($connected !== $databaseName) {
        throw new RuntimeException('Diagnóstico falló: conexión mutable fuera de la base de prueba.');
    }
    e2SqlFile($db, __DIR__ . '/../database/ddl.sql');
    e2SqlFile($db, __DIR__ . '/../database/dml.sql');
    ActiveRecord::setDB($db);
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['CONTACT_OTP_PREVIEW'] = 'true';
    putenv('APP_ENV=testing');
    putenv('CONTACT_OTP_PREVIEW=true');

    $fechaBase = '2098-08-01';
    $disponibilidad1 = DisponibilidadReservacionService::consultar($fechaBase, 1);
    $disponibilidad12 = DisponibilidadReservacionService::consultar($fechaBase, 12);
    $disponibilidad13 = DisponibilidadReservacionService::consultar($fechaBase, 13);
    e2Assert('disponibilidad para una persona', (bool)($disponibilidad1['ok'] ?? false));
    e2Assert('disponibilidad para doce personas', (bool)($disponibilidad12['ok'] ?? false));
    e2Same('rechazo de trece personas', $disponibilidad13['codigo'] ?? '', DisponibilidadReservacionService::DATOS_INVALIDOS);
    e2Assert('slots no exponen mesas', !str_contains(json_encode($disponibilidad12), 'mesa_id'));

    $mesas = array_map(static function (int $numero): object {
        return (object)['id' => $numero, 'numero' => $numero, 'capacidad' => 4];
    }, range(1, 11));
    e2Same('selección de una mesa', count(AsignacionMesasService::seleccionarMesasPublicas($mesas, 4)), 1);
    e2Same('selección de dos mesas', count(AsignacionMesasService::seleccionarMesasPublicas($mesas, 5)), 2);
    e2Same('selección de tres mesas', count(AsignacionMesasService::seleccionarMesasPublicas($mesas, 12)), 3);
    $mesasQueRequeririanCuatro = array_map(static function (int $numero): object {
        return (object)['id' => $numero, 'numero' => $numero, 'capacidad' => 3];
    }, range(1, 11));
    e2Same(
        'caso que requeriría cuatro mesas no se asigna',
        count(AsignacionMesasService::seleccionarMesasPublicas($mesasQueRequeririanCuatro, 12)),
        0
    );

    $payloadHold = e2Payload('e2-test-hold-00000001', 'hold.flow@example.test', $fechaBase, '18:00', 2);
    $hold = ReservacionPublicaService::crearRetencion($payloadHold);
    e2Same('retención válida', $hold['codigo'] ?? '', ReservacionPublicaService::RETENCION_CREADA);
    $otp = (string)($hold['preview_code'] ?? '');
    e2Assert('preview testing de seis dígitos', preg_match('/^\d{6}$/', $otp) === 1);
    $holdRow = $db->query(
        "SELECT * FROM reservaciones WHERE request_token = 'e2-test-hold-00000001'"
    )->fetch_assoc();
    e2Same('estado pendiente_verificacion', $holdRow['estado'] ?? '', 'pendiente_verificacion');
    e2Same('retención asigna una mesa', e2Count($db, 'SELECT COUNT(*) total FROM reservacion_mesas WHERE reservacion_id = ' . (int)$holdRow['id']), 1);
    $otpRow = $db->query(
        'SELECT * FROM verificaciones_contacto WHERE reservacion_id = ' . (int)$holdRow['id'] . ' ORDER BY id DESC LIMIT 1'
    )->fetch_assoc();
    e2Assert('OTP ligado a retención', (int)($otpRow['reservacion_id'] ?? 0) === (int)$holdRow['id']);
    e2Assert('OTP sólo como hash', password_verify($otp, (string)$otpRow['codigo_hash']) && $otp !== $otpRow['codigo_hash']);

    $retry = ReservacionPublicaService::crearRetencion($payloadHold);
    e2Assert('reintento idempotente', (bool)($retry['ok'] ?? false) && ($retry['idempotente'] ?? false) === true);
    $conflictPayload = $payloadHold;
    $conflictPayload['personas'] = 3;
    $conflict = ReservacionPublicaService::crearRetencion($conflictPayload);
    e2Same('conflicto fingerprint', $conflict['codigo'] ?? '', ReservacionPublicaService::REQUEST_TOKEN_CONFLICTO);
    e2Same('doble clic crea una fila', e2Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE request_token = 'e2-test-hold-00000001'"), 1);

    // Los triggers existen sólo en la base desechable y fuerzan fallos en dos
    // puntos distintos para comprobar que la transacción no deja datos parciales.
    $db->query(
        "CREATE TRIGGER e2_fail_assignment
         BEFORE INSERT ON reservacion_mesas
         FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'e2 forced assignment failure'"
    );
    $rollbackAfterReservation = ReservacionPublicaService::crearRetencion(
        e2Payload('e2-rollback-reservation-01', 'rollback.reservation@example.test', '2098-08-19', '18:00', 2)
    );
    $db->query('DROP TRIGGER e2_fail_assignment');
    e2Same('fallo después de insertar reservación devuelve error', $rollbackAfterReservation['codigo'] ?? '', ReservacionPublicaService::ERROR_INTERNO);
    e2Same(
        'rollback después de insertar reservación',
        e2Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE request_token = 'e2-rollback-reservation-01'"),
        0
    );

    $assignmentsBeforeRollback = e2Count($db, 'SELECT COUNT(*) total FROM reservacion_mesas');
    $otpsBeforeRollback = e2Count($db, 'SELECT COUNT(*) total FROM verificaciones_contacto');
    $db->query(
        "CREATE TRIGGER e2_fail_otp
         BEFORE INSERT ON verificaciones_contacto
         FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'e2 forced otp failure'"
    );
    $rollbackAfterAssignment = ReservacionPublicaService::crearRetencion(
        e2Payload('e2-rollback-assignment-01', 'rollback.assignment@example.test', '2098-08-20', '18:00', 2)
    );
    $db->query('DROP TRIGGER e2_fail_otp');
    e2Same('fallo después de asignar mesas devuelve error', $rollbackAfterAssignment['codigo'] ?? '', ReservacionPublicaService::ERROR_INTERNO);
    e2Same(
        'rollback elimina reservación previa a OTP',
        e2Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE request_token = 'e2-rollback-assignment-01'"),
        0
    );
    e2Same('rollback conserva conteo de asignaciones', e2Count($db, 'SELECT COUNT(*) total FROM reservacion_mesas'), $assignmentsBeforeRollback);
    e2Same('rollback no deja OTP utilizable', e2Count($db, 'SELECT COUNT(*) total FROM verificaciones_contacto'), $otpsBeforeRollback);

    $wrong = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email', 'contacto' => 'hold.flow@example.test',
        'codigo' => $otp === '000000' ? '111111' : '000000',
        'request_token' => 'e2-test-hold-00000001',
    ]);
    e2Same('OTP incorrecto', $wrong['codigo'] ?? '', ContactoAccesoService::CODIGO_INVALIDO);
    $confirmed = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email', 'contacto' => 'hold.flow@example.test',
        'codigo' => $otp, 'request_token' => 'e2-test-hold-00000001',
    ]);
    e2Same('confirmación correcta', $confirmed['codigo'] ?? '', ReservacionPublicaService::RESERVACION_CONFIRMADA);
    $doubleConfirm = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email', 'contacto' => 'hold.flow@example.test',
        'codigo' => $otp, 'request_token' => 'e2-test-hold-00000001',
    ]);
    e2Assert('doble confirmación idempotente', (bool)($doubleConfirm['ok'] ?? false) && ($doubleConfirm['idempotente'] ?? false) === true);

    $expiredOtpPayload = e2Payload(
        'e2-expired-otp-hold-0001',
        'expired.otp@example.test',
        '2098-08-13',
        '18:00',
        2
    );
    $expiredOtpHold = ReservacionPublicaService::crearRetencion($expiredOtpPayload);
    $expiredOtpCode = (string)($expiredOtpHold['preview_code'] ?? '');
    $expiredOtpId = (int)$db->query(
        "SELECT id FROM reservaciones WHERE request_token = 'e2-expired-otp-hold-0001'"
    )->fetch_assoc()['id'];
    $db->query(
        "UPDATE verificaciones_contacto
         SET expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
         WHERE reservacion_id = {$expiredOtpId}"
    );
    $expiredOtpResult = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => 'expired.otp@example.test',
        'codigo' => $expiredOtpCode,
        'request_token' => 'e2-expired-otp-hold-0001',
    ]);
    e2Same('OTP vencido', $expiredOtpResult['codigo'] ?? '', ContactoAccesoService::CODIGO_EXPIRADO);

    $expiredHoldPayload = e2Payload(
        'e2-expired-hold-valid-otp',
        'expired.hold@example.test',
        '2098-08-14',
        '18:00',
        2
    );
    $expiredHoldWithOtp = ReservacionPublicaService::crearRetencion($expiredHoldPayload);
    $validOtpForExpiredHold = (string)($expiredHoldWithOtp['preview_code'] ?? '');
    $expiredHoldId = (int)$db->query(
        "SELECT id FROM reservaciones WHERE request_token = 'e2-expired-hold-valid-otp'"
    )->fetch_assoc()['id'];
    $db->query(
        "UPDATE reservaciones
         SET verification_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
         WHERE id = {$expiredHoldId}"
    );
    $expiredHoldResult = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => 'expired.hold@example.test',
        'codigo' => $validOtpForExpiredHold,
        'request_token' => 'e2-expired-hold-valid-otp',
    ]);
    e2Same('retención vencida con OTP vigente', $expiredHoldResult['codigo'] ?? '', ReservacionPublicaService::RETENCION_EXPIRADA);
    e2Same(
        'confirmar una retención vencida la materializa',
        (string)$db->query("SELECT estado FROM reservaciones WHERE id = {$expiredHoldId}")->fetch_assoc()['estado'],
        'expirada'
    );

    $db->query('UPDATE mesas SET reservable = IF(id = 1, 1, 0)');
    $capacityHold = ReservacionPublicaService::crearRetencion(
        e2Payload('e2-capacity-hold-live-01', 'capacity.hold.a@example.test', '2098-08-15', '18:00', 2)
    );
    $capacityHoldId = (int)$db->query(
        "SELECT id FROM reservaciones WHERE request_token = 'e2-capacity-hold-live-01'"
    )->fetch_assoc()['id'];
    $blockedByHold = ReservacionPublicaService::crearRetencion(
        e2Payload('e2-capacity-hold-blocked', 'capacity.hold.b@example.test', '2098-08-15', '18:00', 2)
    );
    e2Assert('retención vigente consume capacidad', ($capacityHold['ok'] ?? false) === true
        && ($blockedByHold['codigo'] ?? '') === ReservacionPublicaService::SIN_DISPONIBILIDAD);
    $db->query(
        "UPDATE reservaciones
         SET verification_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND)
         WHERE id = {$capacityHoldId}"
    );
    $availableAfterTimestamp = ReservacionPublicaService::crearRetencion(
        e2Payload('e2-capacity-after-expired', 'capacity.hold.c@example.test', '2098-08-15', '18:00', 2)
    );
    e2Same('retención vencida deja de consumir sin materializar', $availableAfterTimestamp['codigo'] ?? '', ReservacionPublicaService::RETENCION_CREADA);
    $db->query('UPDATE mesas SET reservable = 1 WHERE tipo = "mesa"');

    $directPayload = e2Payload('e2-test-direct-0000001', 'direct.flow@example.test', '2098-08-02', '18:00', 5);
    $otpBefore = e2Count($db, 'SELECT COUNT(*) total FROM verificaciones_contacto');
    $direct = ReservacionPublicaService::crearConfirmada($directPayload, [
        'contact_type' => 'email', 'contact_normalized' => 'direct.flow@example.test',
    ]);
    e2Same('creación con sesión verificada', $direct['codigo'] ?? '', ReservacionPublicaService::RESERVACION_CONFIRMADA);
    e2Same('creación verificada sin nuevo OTP', e2Count($db, 'SELECT COUNT(*) total FROM verificaciones_contacto'), $otpBefore);
    $directId = (int)($direct['reservation']['id'] ?? 0);
    e2Same('creación de dos mesas', e2Count($db, "SELECT COUNT(*) total FROM reservacion_mesas WHERE reservacion_id = {$directId}"), 2);
    $directList = Reservacion::buscarActivasPorContacto(
        'email',
        'direct.flow@example.test',
        ReservacionConfig::fechaActual(),
        ReservacionConfig::horaActual(),
        5
    );
    e2Assert('consulta actualizada después de crear', count(array_filter(
        $directList,
        static fn(array $fila): bool => (int)$fila['id'] === $directId
    )) === 1);

    $twelvePayload = e2Payload('e2-test-twelve-000001', 'twelve.flow@example.test', '2098-08-03', '18:00', 12);
    $twelve = ReservacionPublicaService::crearConfirmada($twelvePayload, [
        'contact_type' => 'email', 'contact_normalized' => 'twelve.flow@example.test',
    ]);
    $twelveId = (int)($twelve['reservation']['id'] ?? 0);
    e2Same('creación de tres mesas', e2Count($db, "SELECT COUNT(*) total FROM reservacion_mesas WHERE reservacion_id = {$twelveId}"), 3);

    $modify = ReservacionPublicaService::modificar([
        'reservacion_id' => $directId,
        'nombre' => 'Cliente Modificado',
        'fecha' => '2098-08-04',
        'hora' => '14:00',
        'personas' => 3,
        'notas' => 'Modificada',
    ], ['contact_type' => 'email', 'contact_normalized' => 'direct.flow@example.test']);
    e2Same('modificación exitosa', $modify['codigo'] ?? '', ReservacionPublicaService::RESERVACION_MODIFICADA);
    $modifiedRow = $db->query("SELECT fecha, hora, comensales FROM reservaciones WHERE id = {$directId}")->fetch_assoc();
    e2Same('modificación persiste fecha', $modifiedRow['fecha'] ?? '', '2098-08-04');
    $foreignModify = ReservacionPublicaService::modificar([
        'reservacion_id' => $directId, 'nombre' => 'Ajena', 'fecha' => '2098-08-04',
        'hora' => '14:00', 'personas' => 2, 'notas' => '',
    ], ['contact_type' => 'email', 'contact_normalized' => 'otro@example.test']);
    e2Same('modificación ajena', $foreignModify['codigo'] ?? '', ReservacionPublicaService::RESERVACION_NO_PERTENECE_AL_CONTACTO);

    $db->query('UPDATE mesas SET reservable = IF(id = 1, 1, 0)');
    $preserveSession = ['contact_type' => 'email', 'contact_normalized' => 'preserve.modify@example.test'];
    $preserveOriginal = ReservacionPublicaService::crearConfirmada(
        e2Payload('e2-preserve-original-0001', 'preserve.modify@example.test', '2098-08-16', '18:00', 2),
        $preserveSession
    );
    $preserveId = (int)($preserveOriginal['reservation']['id'] ?? 0);
    ReservacionPublicaService::crearConfirmada(
        e2Payload('e2-preserve-blocker-0001', 'preserve.blocker@example.test', '2098-08-17', '18:00', 2),
        ['contact_type' => 'email', 'contact_normalized' => 'preserve.blocker@example.test']
    );
    $beforeFailedModify = $db->query(
        "SELECT fecha, hora, comensales FROM reservaciones WHERE id = {$preserveId}"
    )->fetch_assoc();
    $beforeFailedAssignment = (string)$db->query(
        "SELECT GROUP_CONCAT(mesa_id ORDER BY orden) ids
         FROM reservacion_mesas WHERE reservacion_id = {$preserveId}"
    )->fetch_assoc()['ids'];
    $failedModify = ReservacionPublicaService::modificar([
        'reservacion_id' => $preserveId,
        'nombre' => 'No debe persistir',
        'fecha' => '2098-08-17',
        'hora' => '18:00',
        'personas' => 2,
        'notas' => 'Sin capacidad',
    ], $preserveSession);
    $afterFailedModify = $db->query(
        "SELECT fecha, hora, comensales FROM reservaciones WHERE id = {$preserveId}"
    )->fetch_assoc();
    $afterFailedAssignment = (string)$db->query(
        "SELECT GROUP_CONCAT(mesa_id ORDER BY orden) ids
         FROM reservacion_mesas WHERE reservacion_id = {$preserveId}"
    )->fetch_assoc()['ids'];
    e2Same('modificación sin capacidad', $failedModify['codigo'] ?? '', ReservacionPublicaService::SIN_DISPONIBILIDAD);
    e2Assert(
        'modificación fallida conserva datos y asignación original',
        $beforeFailedModify === $afterFailedModify && $beforeFailedAssignment === $afterFailedAssignment
    );
    $db->query('UPDATE mesas SET reservable = 1 WHERE tipo = "mesa"');

    $mesasAntesCancelar = e2Count($db, "SELECT COUNT(*) total FROM reservacion_mesas WHERE reservacion_id = {$directId}");
    $cancel = ReservacionPublicaService::cancelar($directId, [
        'contact_type' => 'email', 'contact_normalized' => 'direct.flow@example.test',
    ]);
    e2Same('cancelación exitosa', $cancel['codigo'] ?? '', ReservacionPublicaService::RESERVACION_CANCELADA);
    $cancelAgain = ReservacionPublicaService::cancelar($directId, [
        'contact_type' => 'email', 'contact_normalized' => 'direct.flow@example.test',
    ]);
    e2Assert('cancelación idempotente', (bool)($cancelAgain['ok'] ?? false) && ($cancelAgain['idempotente'] ?? false) === true);
    e2Same(
        'relación histórica conservada',
        e2Count($db, "SELECT COUNT(*) total FROM reservacion_mesas WHERE reservacion_id = {$directId}"),
        $mesasAntesCancelar
    );
    $foreignCancel = ReservacionPublicaService::cancelar($twelveId, [
        'contact_type' => 'email', 'contact_normalized' => 'otro@example.test',
    ]);
    e2Same('cancelación ajena', $foreignCancel['codigo'] ?? '', ReservacionPublicaService::RESERVACION_NO_PERTENECE_AL_CONTACTO);

    $pastId = (int)$db->query(
        "SELECT id FROM reservaciones WHERE request_token = 'e2-caso8-pasada-0000'"
    )->fetch_assoc()['id'];
    $pastSession = ['contact_type' => 'email', 'contact_normalized' => 'etapa2.pasada@example.test'];
    $pastModify = ReservacionPublicaService::modificar([
        'reservacion_id' => $pastId,
        'nombre' => 'Etapa 2 Pasada',
        'fecha' => '2098-08-19',
        'hora' => '18:00',
        'personas' => 2,
        'notas' => 'No debe cambiar',
    ], $pastSession);
    e2Same('modificación después de la hora', $pastModify['codigo'] ?? '', ReservacionPublicaService::MODIFICACION_NO_PERMITIDA);
    $pastCancel = ReservacionPublicaService::cancelar($pastId, $pastSession);
    e2Same('cancelación después de la hora', $pastCancel['codigo'] ?? '', ReservacionPublicaService::CANCELACION_NO_PERMITIDA);

    $expiredPayload = e2Payload('e2-test-expire-0000001', 'expire.flow@example.test', '2098-08-05', '18:00', 2);
    $expiredHold = ReservacionPublicaService::crearRetencion($expiredPayload);
    $expiredId = (int)$db->query(
        "SELECT id FROM reservaciones WHERE request_token = 'e2-test-expire-0000001'"
    )->fetch_assoc()['id'];
    $db->query("UPDATE reservaciones SET verification_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = {$expiredId}");
    $materialized = ReservacionPublicaService::expirarRetenciones(100, false);
    e2Assert('expiración materializada', (bool)($materialized['ok'] ?? false) && (int)$materialized['procesadas'] >= 1);
    e2Same('estado expirada', (string)$db->query("SELECT estado FROM reservaciones WHERE id = {$expiredId}")->fetch_assoc()['estado'], 'expirada');
    $materializedAgain = ReservacionPublicaService::expirarRetenciones(100, false);
    e2Same('expiración idempotente', (int)($materializedAgain['procesadas'] ?? -1), 0);

    $fourSession = ['contact_type' => 'email', 'contact_normalized' => 'etapa2.cuatro@example.test'];
    $fifth = ReservacionPublicaService::crearConfirmada(
        e2Payload('e2-test-fifth-00000001', 'etapa2.cuatro@example.test', '2098-08-06', '18:00', 2),
        $fourSession
    );
    e2Assert('contacto con cuatro crea quinta', (bool)($fifth['ok'] ?? false));
    $sixth = ReservacionPublicaService::crearConfirmada(
        e2Payload('e2-test-sixth-00000001', 'etapa2.cuatro@example.test', '2098-08-07', '18:00', 2),
        $fourSession
    );
    e2Same('intento de sexta', $sixth['codigo'] ?? '', ReservacionPublicaService::LIMITE_RESERVACIONES_ALCANZADO);

    $db->query('UPDATE mesas SET reservable = IF(id = 1, 1, 0)');
    $db->query(
        "INSERT INTO reservaciones
            (nombre, email, contacto_tipo, contacto_valor, contacto_normalizado,
             fecha, hora, comensales, estado, verification_expires_at,
             request_token, request_fingerprint)
         VALUES
            ('Final cancelada', 'final.cancelada@example.test', 'email', 'final.cancelada@example.test',
             'final.cancelada@example.test', '2098-08-24', '18:00:00', 2, 'cancelada', NULL,
             'e2-final-cancelada-001', SHA2('e2-final-cancelada-001', 256)),
            ('Final completada', 'final.completada@example.test', 'email', 'final.completada@example.test',
             'final.completada@example.test', '2098-08-24', '18:00:00', 2, 'completada', NULL,
             'e2-final-completada-01', SHA2('e2-final-completada-01', 256)),
            ('Final no show', 'final.noshow@example.test', 'email', 'final.noshow@example.test',
             'final.noshow@example.test', '2098-08-24', '18:00:00', 2, 'no_show', NULL,
             'e2-final-noshow-000001', SHA2('e2-final-noshow-000001', 256)),
            ('Final expirada', 'final.expirada@example.test', 'email', 'final.expirada@example.test',
             'final.expirada@example.test', '2098-08-24', '18:00:00', 2, 'expirada', NOW(),
             'e2-final-expirada-0001', SHA2('e2-final-expirada-0001', 256))"
    );
    $db->query(
        "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
         SELECT id, 1, 1
         FROM reservaciones
         WHERE request_token LIKE 'e2-final-%'"
    );
    $afterFinalStates = ReservacionPublicaService::crearConfirmada(
        e2Payload('e2-after-final-states-01', 'after.finals@example.test', '2098-08-24', '18:00', 2),
        ['contact_type' => 'email', 'contact_normalized' => 'after.finals@example.test']
    );
    e2Same('estados finales fuera de capacidad', $afterFinalStates['codigo'] ?? '', ReservacionPublicaService::RESERVACION_CONFIRMADA);
    $db->query('UPDATE mesas SET reservable = 1 WHERE tipo = "mesa"');

    $raceContact = 'etapa2.race.limit@example.test';
    for ($index = 1; $index <= 4; $index++) {
        $token = 'e2-race-limit-seed-' . str_pad((string)$index, 4, '0', STR_PAD_LEFT);
        $date = '2098-09-' . str_pad((string)$index, 2, '0', STR_PAD_LEFT);
        $db->query(
            "INSERT INTO reservaciones
                (nombre, email, contacto_tipo, contacto_valor, contacto_normalizado,
                 fecha, hora, comensales, nota, estado, confirmed_at,
                 request_token, request_fingerprint)
             VALUES
                ('Carrera límite', '{$raceContact}', 'email', '{$raceContact}', '{$raceContact}',
                 '{$date}', '10:00:00', 2, '', 'confirmada', NOW(),
                 '{$token}', SHA2('{$token}', 256))"
        );
    }
    $raceLimit = e2Race($databaseName, [
        e2Payload('e2-race-limit-create-a', $raceContact, '2098-08-08', '18:00', 2),
        e2Payload('e2-race-limit-create-b', $raceContact, '2098-08-09', '18:00', 2),
    ]);
    $limitSuccess = count(array_filter($raceLimit, static fn(array $r): bool => ($r['ok'] ?? false) === true));
    $limitRejected = count(array_filter($raceLimit, static fn(array $r): bool =>
        ($r['codigo'] ?? '') === ReservacionPublicaService::LIMITE_RESERVACIONES_ALCANZADO));
    e2Same('carrera real crea una sola quinta', $limitSuccess, 1);
    e2Same('carrera real rechaza la sexta', $limitRejected, 1);

    $db->query('UPDATE mesas SET reservable = IF(id = 1, 1, 0)');
    $raceCapacity = e2Race($databaseName, [
        e2Payload('e2-race-capacity-a-01', 'race.capacity.a@example.test', '2098-08-10', '18:00', 2),
        e2Payload('e2-race-capacity-b-01', 'race.capacity.b@example.test', '2098-08-10', '18:00', 2),
    ]);
    $capacitySuccess = count(array_filter($raceCapacity, static fn(array $r): bool => ($r['ok'] ?? false) === true));
    $capacityRejected = count(array_filter($raceCapacity, static fn(array $r): bool =>
        ($r['codigo'] ?? '') === ReservacionPublicaService::SIN_DISPONIBILIDAD));
    e2Same('carrera por última mesa produce un ganador', $capacitySuccess, 1);
    e2Same('carrera por última mesa rechaza al segundo', $capacityRejected, 1);
    $db->query('UPDATE mesas SET reservable = 1 WHERE tipo = "mesa"');

    $confirmExpireHold = ReservacionPublicaService::crearRetencion(
        e2Payload('e2-race-confirm-expire-01', 'race.confirm@example.test', '2098-08-26', '18:00', 2)
    );
    $confirmExpireCode = (string)($confirmExpireHold['preview_code'] ?? '');
    $confirmExpireId = (int)$db->query(
        "SELECT id FROM reservaciones WHERE request_token = 'e2-race-confirm-expire-01'"
    )->fetch_assoc()['id'];
    $db->query(
        "UPDATE reservaciones SET verification_expires_at = NOW()
         WHERE id = {$confirmExpireId}"
    );
    $confirmExpireRace = e2Race($databaseName, [
        [
            '_operation' => 'confirmar',
            'tipo' => 'email',
            'contacto' => 'race.confirm@example.test',
            'codigo' => $confirmExpireCode,
            'request_token' => 'e2-race-confirm-expire-01',
        ],
        ['_operation' => 'expirar', 'limite' => 100],
    ]);
    $confirmExpireState = (string)$db->query(
        "SELECT estado FROM reservaciones WHERE id = {$confirmExpireId}"
    )->fetch_assoc()['estado'];
    e2Assert(
        'confirmar mientras expira produce un único estado final',
        in_array($confirmExpireState, ['confirmada', 'expirada'], true)
        && !in_array('ERROR_INTERNO', array_column($confirmExpireRace, 'codigo'), true)
    );

    $modifyCancelSession = [
        'contact_type' => 'email',
        'contact_normalized' => 'race.modify.cancel@example.test',
    ];
    $modifyCancelCreated = ReservacionPublicaService::crearConfirmada(
        e2Payload('e2-race-modify-cancel-01', 'race.modify.cancel@example.test', '2098-08-27', '18:00', 2),
        $modifyCancelSession
    );
    $modifyCancelId = (int)($modifyCancelCreated['reservation']['id'] ?? 0);
    $modifyCancelRace = e2Race($databaseName, [
        [
            '_operation' => 'modificar',
            'tipo_contacto' => 'email',
            'contacto' => 'race.modify.cancel@example.test',
            'reservacion_id' => $modifyCancelId,
            'nombre' => 'Carrera modificada',
            'fecha' => '2098-08-28',
            'hora' => '18:00',
            'personas' => 3,
            'notas' => 'Carrera',
        ],
        [
            '_operation' => 'cancelar',
            'tipo_contacto' => 'email',
            'contacto' => 'race.modify.cancel@example.test',
            'reservacion_id' => $modifyCancelId,
        ],
    ]);
    $modifyCancelCodes = array_column($modifyCancelRace, 'codigo');
    $modifyCancelState = (string)$db->query(
        "SELECT estado FROM reservaciones WHERE id = {$modifyCancelId}"
    )->fetch_assoc()['estado'];
    e2Assert(
        'modificar mientras cancela conserva un resultado serializable',
        $modifyCancelState === 'cancelada'
        && in_array(ReservacionPublicaService::RESERVACION_CANCELADA, $modifyCancelCodes, true)
        && !in_array(ReservacionPublicaService::ERROR_INTERNO, $modifyCancelCodes, true)
    );

    $_ENV['APP_ENV'] = 'production';
    putenv('APP_ENV=production');
    $productionOtp = ContactoAccesoService::solicitarCodigo('email', 'production.e2@example.test');
    e2Assert('production sin preview', !array_key_exists('preview_code', $productionOtp));

    e2Same('DDL contiene fingerprint', e2Count($db,
        "SELECT COUNT(*) total FROM information_schema.columns
         WHERE table_schema = '{$databaseName}' AND table_name = 'reservaciones'
           AND column_name = 'request_fingerprint'"), 1);
    e2Same('sin columnas OTP planas', e2Count($db,
        "SELECT COUNT(*) total FROM information_schema.columns
         WHERE table_schema = '{$databaseName}' AND table_name = 'verificaciones_contacto'
           AND column_name IN ('codigo','otp','codigo_plano')"), 0);
} catch (Throwable $e) {
    $failures[] = 'Excepción: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine();
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (!$keepDatabase) {
        $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    }
    $db->close();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "OK: {$tests} comprobaciones de Etapa 2." . PHP_EOL;
