<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;
use Model\Reservacion;
use Model\ReservacionMesa;
use Services\AsignacionMesasService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionMantenimientoService;
use Services\ReservacionVigenciaService;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$databaseName = 'casa_pestalozzi_estabilizacion_test';
$db = mysqli_connect(
    (string)($_ENV['DB_HOST'] ?? 'localhost'),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? '')
);
$tests = 0;
$failures = [];

function s4Assert(string $name, bool $condition): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $name;
    }
}

function s4Same(string $name, mixed $actual, mixed $expected): void
{
    s4Assert(
        $name . ': esperado ' . var_export($expected, true) . ', recibido ' . var_export($actual, true),
        $actual === $expected
    );
}

function s4SqlFile(mysqli $db, string $path): void
{
    $db->multi_query((string)file_get_contents($path));
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function s4Clock(mysqli $db, string $now): void
{
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['RESERVATION_TEST_NOW'] = $now;
    putenv('APP_ENV=testing');
    putenv('RESERVATION_TEST_NOW=' . $now);
    $db->query("SET timestamp = UNIX_TIMESTAMP('" . $db->real_escape_string($now) . "')");
}

function s4Reservation(
    mysqli $db,
    string $time,
    int $tableId,
    string $state = 'confirmada',
    string $name = 'TEST-ESTABILIZACION',
    string $contact = 's4@example.test',
    int $people = 2,
    ?string $arrivedAt = null,
    ?string $holdExpiresAt = null
): int {
    $nameSql = $db->real_escape_string($name . '-' . bin2hex(random_bytes(2)));
    $contactSql = $db->real_escape_string($contact);
    $arrivedSql = $arrivedAt !== null ? "'" . $db->real_escape_string($arrivedAt) . "'" : 'NULL';
    $holdSql = $holdExpiresAt !== null ? "'" . $db->real_escape_string($holdExpiresAt) . "'" : 'NULL';
    $confirmedSql = $state === 'confirmada' ? 'NOW()' : 'NULL';
    $db->query(
        "INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, fecha, hora, comensales, estado,
             confirmed_at, arrived_at, hold_expires_at)
         VALUES
            ('{$nameSql}', 'email', '{$contactSql}', '2026-11-30', '{$time}',
             {$people}, '{$state}', {$confirmedSql}, {$arrivedSql}, {$holdSql})"
    );
    $id = (int)$db->insert_id;
    if ($tableId > 0) {
        $db->query(
            "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
             VALUES ({$id}, {$tableId}, 1)"
        );
    }
    return $id;
}

function s4Ticket(
    mysqli $db,
    array $tableIds,
    ?int $reservationId = null,
    ?string $openedAt = null
): int
{
    $reservationSql = $reservationId !== null ? (string)$reservationId : 'NULL';
    $openedSql = $openedAt !== null
        ? "'" . $db->real_escape_string($openedAt) . "'"
        : 'NOW()';
    $db->query(
        "INSERT INTO tickets (comensales, nombre, estado, reservacion_id, hora_apertura)
         VALUES (2, 'TEST-TICKET', 'abierto', {$reservationSql}, {$openedSql})"
    );
    $ticketId = (int)$db->insert_id;
    foreach (array_values($tableIds) as $index => $tableId) {
        $order = $index + 1;
        $db->query(
            "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden)
             VALUES ({$ticketId}, " . (int)$tableId . ", {$order})"
        );
    }
    return $ticketId;
}

function s4Version(mysqli $db, int $reservationId): string
{
    $row = $db->query(
        "SELECT created_at, updated_at FROM reservaciones WHERE id={$reservationId}"
    )->fetch_assoc();
    $ids = [];
    $result = $db->query(
        "SELECT mesa_id FROM reservacion_mesas WHERE reservacion_id={$reservationId} ORDER BY orden"
    );
    while ($item = $result->fetch_assoc()) {
        $ids[] = (int)$item['mesa_id'];
    }
    $result->free();
    return hash('sha256', (string)($row['updated_at'] ?: $row['created_at']) . '|' . implode(',', $ids));
}

function s4Race(string $database, array $payloads): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'casa_s4_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = $dir . DIRECTORY_SEPARATOR . 'go';
    $processes = [];
    $paths = [];
    foreach ($payloads as $i => $payload) {
        $ready = $dir . DIRECTORY_SEPARATOR . "ready{$i}";
        $result = $dir . DIRECTORY_SEPARATOR . "result{$i}.json";
        $process = proc_open([
            PHP_BINARY,
            __DIR__ . '/ReservacionEtapa3ConcurrencyWorker.php',
            $database,
            base64_encode((string)json_encode($payload)),
            $ready,
            $go,
            $result,
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        fclose($pipes[0]);
        $processes[] = [$process, $pipes];
        $paths[] = [$ready, $result];
    }
    $deadline = microtime(true) + 10;
    while (microtime(true) < $deadline && (!is_file($paths[0][0]) || !is_file($paths[1][0]))) {
        usleep(10000);
    }
    file_put_contents($go, 'go');
    $responses = [];
    foreach ($processes as $i => [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException("Worker {$i}: {$stdout} {$stderr}");
        }
        $responses[] = json_decode((string)file_get_contents($paths[$i][1]), true);
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
    return $responses;
}

try {
    $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    $db->query("CREATE DATABASE `{$databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->select_db($databaseName);
    $db->query("SET time_zone = '-06:00'");
    s4Clock($db, '2026-11-30 13:14:59');
    s4SqlFile($db, __DIR__ . '/../database/ddl.sql');
    s4SqlFile($db, __DIR__ . '/../database/dml.sql');
    $db->query('DELETE FROM feedback_tokens');
    $db->query('DELETE FROM ticket_pagos');
    $db->query('DELETE FROM ticket_items');
    $db->query('DELETE FROM ticket_mesas');
    $db->query('DELETE FROM tickets');
    $db->query('DELETE FROM verificaciones_contacto');
    $db->query('DELETE FROM reservacion_mesas');
    $db->query('DELETE FROM reservaciones');
    ActiveRecord::setDB($db);

    $limitId = s4Reservation($db, '13:00:00', 1, 'confirmada', 'TEST-LIMITE', 'limite@example.test');
    $limitRow = $db->query("SELECT * FROM reservaciones WHERE id={$limitId}")->fetch_assoc();
    $before = ReservacionVigenciaService::clasificar($limitRow);
    s4Assert('+14:59 cuenta para límite', $before['cuenta_limite']);
    s4Assert('+14:59 visible al cliente', $before['visible_cliente']);
    s4Assert('+14:59 bloquea disponibilidad', $before['influye_disponibilidad']);
    s4Assert('+14:59 permite llegada', $before['puede_confirmar_llegada']);
    s4Same(
        '+14:59 conteo por contacto',
        Reservacion::contarActivasPorContacto('email', 'limite@example.test', '2026-11-30', '13:14:59'),
        1
    );
    s4Assert(
        '+14:59 ocupa mesa lógicamente',
        in_array(1, array_column(ReservacionMesa::obtenerOcupacionDelDia('2026-11-30'), 'mesa_id'), true)
    );

    s4Clock($db, '2026-11-30 13:15:00');
    $atLimit = ReservacionVigenciaService::clasificar($limitRow);
    s4Assert('+15:00 tolerancia vencida', $atLimit['tolerancia_vencida']);
    s4Assert('+15:00 elegible no-show', $atLimit['elegible_no_show']);
    s4Assert('+15:00 deja de contar', !$atLimit['cuenta_limite']);
    s4Assert('+15:00 deja de bloquear', !$atLimit['influye_disponibilidad']);
    s4Same(
        '+15:00 conteo por contacto liberado',
        Reservacion::contarActivasPorContacto('email', 'limite@example.test', '2026-11-30', '13:15:00'),
        0
    );
    s4Same(
        '+15:00 desaparece de Mis reservaciones',
        count(Reservacion::buscarActivasPorContacto(
            'email',
            'limite@example.test',
            '2026-11-30',
            '13:15:00',
            5
        )),
        0
    );
    s4Assert(
        '+15:00 libera mesa lógica',
        !in_array(1, array_column(ReservacionMesa::obtenerOcupacionDelDia('2026-11-30'), 'mesa_id'), true)
    );
    s4Assert(
        '+15:00 sigue visible administrativamente',
        in_array($limitId, array_map(
            static fn($r): int => (int)$r->id,
            Reservacion::buscarPorDiaOperacionAdmin('2026-11-30')
        ), true)
    );

    $arrivedEvidence = s4Reservation(
        $db,
        '13:00:00',
        2,
        'confirmada',
        'TEST-EVIDENCIA-LLEGADA',
        'evidencia@example.test',
        2,
        '2026-11-30 13:05:00'
    );
    $arrivedRow = $db->query("SELECT * FROM reservaciones WHERE id={$arrivedEvidence}")->fetch_assoc();
    $arrivedClass = ReservacionVigenciaService::clasificar($arrivedRow);
    s4Assert('arrived_at conserva operación después de tolerancia', $arrivedClass['influye_disponibilidad']);
    s4Assert('estado inconsistente con arrived_at es recuperable', $arrivedClass['inconsistencia_recuperable']);

    $ticketEvidence = s4Reservation($db, '13:00:00', 3, 'confirmada', 'TEST-EVIDENCIA-TICKET');
    $evidenceTicketId = s4Ticket($db, [3], $ticketEvidence);
    $ticketRow = $db->query("SELECT * FROM reservaciones WHERE id={$ticketEvidence}")->fetch_assoc();
    $ticketClass = ReservacionVigenciaService::clasificar($ticketRow, null, [
        'id' => $evidenceTicketId,
        'estado' => 'abierto',
        'closed_at' => null,
    ]);
    s4Assert('ticket abierto conserva operación', $ticketClass['influye_disponibilidad']);
    s4Assert('ticket abierto impide no-show', !$ticketClass['elegible_no_show']);

    $arrivalAtLimit = s4Reservation($db, '13:00:00', 4, 'confirmada', 'TEST-LLEGADA-EXACTA');
    $arrivalResult = PuntoVentaReservacionService::registrarLlegada($arrivalAtLimit, 1);
    s4Assert('llegada exactamente a +15:00', (bool)($arrivalResult['ok'] ?? false));
    $arrivalStored = $db->query("SELECT estado, arrived_at FROM reservaciones WHERE id={$arrivalAtLimit}")->fetch_assoc();
    s4Same('llegada exacta cambia a llego', $arrivalStored['estado'], 'llego');
    s4Assert('llegada exacta registra arrived_at', !empty($arrivalStored['arrived_at']));
    s4Same(
        'no-show rechazado desde llego',
        PuntoVentaReservacionService::noShow($arrivalAtLimit, 1, false, false)['codigo'],
        PuntoVentaReservacionService::ESTADO_INVALIDO
    );

    $lateOccupied = s4Reservation($db, '13:00:00', 5, 'confirmada', 'TEST-LLEGADA-OCUPADA');
    s4Reservation(
        $db,
        '13:00:00',
        5,
        'llego',
        'TEST-OCUPANTE',
        'ocupante@example.test',
        2,
        '2026-11-30 13:01:00'
    );
    $lateOccupiedResult = PuntoVentaReservacionService::registrarLlegada($lateOccupied, 1);
    s4Same(
        'llegada tardía con mesas ocupadas requiere reasignación',
        $lateOccupiedResult['codigo'],
        PuntoVentaReservacionService::REQUIERE_REASIGNACION
    );

    $lateNoCapacity = s4Reservation($db, '13:00:00', 6, 'confirmada', 'TEST-LLEGADA-CAPACIDAD', 'capacidad@example.test', 40);
    s4Same(
        'llegada tardía sin capacidad requiere resolución',
        PuntoVentaReservacionService::registrarLlegada($lateNoCapacity, 1)['codigo'],
        PuntoVentaReservacionService::SIN_CAPACIDAD
    );

    $validNoShow = s4Reservation($db, '13:00:00', 7, 'confirmada', 'TEST-NO-SHOW');
    s4Assert('no-show válido a +15', PuntoVentaReservacionService::noShow($validNoShow, 1, false, false)['ok']);

    $ticketNoShow = s4Reservation($db, '12:00:00', 8, 'confirmada', 'TEST-NO-SHOW-TICKET');
    $ticketNoShowId = s4Ticket($db, [8], $ticketNoShow);
    s4Same(
        'no-show rechazado con ticket abierto',
        PuntoVentaReservacionService::noShow($ticketNoShow, 1, false, false)['codigo'],
        PuntoVentaReservacionService::TICKET_ABIERTO
    );
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$ticketNoShowId}");

    $directStart = s4Reservation($db, '13:00:00', 9, 'confirmada', 'TEST-INICIO-DIRECTO');
    $startResult = PuntoVentaReservacionService::comenzar($directStart, 1);
    s4Assert('inicio directo abre ticket', (bool)($startResult['ok'] ?? false));
    $directStartRow = $db->query("SELECT estado, arrived_at FROM reservaciones WHERE id={$directStart}")->fetch_assoc();
    s4Same('inicio directo cambia a en_curso', $directStartRow['estado'], 'en_curso');
    s4Assert('inicio directo completa arrived_at', !empty($directStartRow['arrived_at']));
    s4Assert('cierre completa reservación iniciada', PuntoVentaReservacionService::cerrarTicket(
        (int)$startResult['ticket_id'],
        'efectivo',
        0,
        [],
        1
    )['ok']);

    $raceArrival = s4Reservation($db, '13:00:00', 10, 'confirmada', 'TEST-RACE-LLEGADA');
    $arrivalNoShowRace = s4Race($databaseName, [
        ['mode' => 'arrival', 'reservacion_id' => $raceArrival, 'usuario_id' => 1],
        ['mode' => 'no_show', 'reservacion_id' => $raceArrival, 'usuario_id' => 1],
    ]);
    s4Same(
        'llegada y no-show simultáneos tienen un ganador',
        count(array_filter($arrivalNoShowRace, static fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    s4Assert(
        'carrera llegada/no-show deja estado coherente',
        in_array(
            (string)$db->query("SELECT estado FROM reservaciones WHERE id={$raceArrival}")->fetch_assoc()['estado'],
            ['llego', 'no_show'],
            true
        )
    );

    s4Clock($db, '2026-11-30 14:00:00');
    $manualReservation = s4Reservation($db, '16:00:00', 11, 'confirmada', 'TEST-ASIGNACION-MANUAL');
    $manualTicket = s4Ticket($db, [1]);
    $manualConflict = AsignacionMesasService::asignarManual(
        $manualReservation,
        [1],
        false,
        true,
        ['permitir_superposicion_ticket_abierto' => true]
    );
    s4Same(
        'mapa detecta ticket abierto',
        $manualConflict['codigo'],
        AsignacionMesasService::CONFLICTO_TICKETS_ABIERTOS
    );
    s4Same('conflicto informa ticket exacto', $manualConflict['conflictos_ticket'][0]['ticket_id'], $manualTicket);
    $manualAccepted = AsignacionMesasService::asignarManual(
        $manualReservation,
        [1],
        false,
        true,
        [
            'ticket_ids_aceptados' => [$manualTicket],
            'conflicto_token' => $manualConflict['conflicto_token'],
            'usuario_id' => 1,
            'permitir_superposicion_ticket_abierto' => true,
        ]
    );
    s4Assert('confirmación manual válida', (bool)($manualAccepted['ok'] ?? false));
    s4Same('confirmación manual no vincula ticket', $db->query("SELECT reservacion_id FROM tickets WHERE id={$manualTicket}")->fetch_assoc()['reservacion_id'], null);
    $db->query("UPDATE reservaciones SET estado='cancelada' WHERE id={$manualReservation}");

    $autoReservation = s4Reservation($db, '17:00:00', 2, 'confirmada', 'TEST-AUTOMATICA');
    $autoResult = AsignacionMesasService::asignarAutomaticamente($autoReservation);
    s4Assert('asignación automática conserva éxito con otras mesas', (bool)($autoResult['ok'] ?? false));
    s4Assert('asignación automática rechaza mesa con ticket', !in_array(1, $autoResult['mesa_ids'] ?? [], true));
    $db->query("UPDATE reservaciones SET estado='cancelada' WHERE id={$autoReservation}");
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$manualTicket}");

    $closeConflictReservation = s4Reservation($db, '18:00:00', 2, 'confirmada', 'TEST-CONFLICT-CLOSE');
    $closingTicket = s4Ticket($db, [2], null, '2026-11-30 17:00:00');
    $closingPreview = AsignacionMesasService::asignarManual(
        $closeConflictReservation,
        [2],
        false,
        true,
        ['permitir_superposicion_ticket_abierto' => true]
    );
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$closingTicket}");
    $closingConfirm = AsignacionMesasService::asignarManual(
        $closeConflictReservation,
        [2],
        false,
        true,
        [
            'ticket_ids_aceptados' => [$closingTicket],
            'conflicto_token' => $closingPreview['conflicto_token'],
            'permitir_superposicion_ticket_abierto' => true,
        ]
    );
    s4Same('ticket cerrado antes de confirmar produce 409 lógico', $closingConfirm['codigo'], AsignacionMesasService::CONFLICTO_CONCURRENTE);
    $db->query("UPDATE reservaciones SET estado='cancelada' WHERE id={$closeConflictReservation}");

    $moveConflictReservation = s4Reservation($db, '18:30:00', 3, 'confirmada', 'TEST-CONFLICT-MOVE');
    $movingTicket = s4Ticket($db, [3], null, '2026-11-30 17:30:00');
    $movingPreview = AsignacionMesasService::asignarManual(
        $moveConflictReservation,
        [3, 4],
        true,
        true,
        ['permitir_superposicion_ticket_abierto' => true]
    );
    $db->query("UPDATE ticket_mesas SET mesa_id=4 WHERE ticket_id={$movingTicket}");
    $movingConfirm = AsignacionMesasService::asignarManual(
        $moveConflictReservation,
        [3, 4],
        true,
        true,
        [
            'ticket_ids_aceptados' => [$movingTicket],
            'conflicto_token' => $movingPreview['conflicto_token'],
            'permitir_superposicion_ticket_abierto' => true,
        ]
    );
    s4Same('ticket cambiado de mesa produce conflicto concurrente', $movingConfirm['codigo'], AsignacionMesasService::CONFLICTO_CONCURRENTE);
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$movingTicket}");
    $db->query("UPDATE reservaciones SET estado='cancelada' WHERE id={$moveConflictReservation}");

    $newConflictReservation = s4Reservation($db, '19:00:00', 5, 'confirmada', 'TEST-CONFLICT-NEW');
    $existingTicket = s4Ticket($db, [5], null, '2026-11-30 18:00:00');
    $newPreview = AsignacionMesasService::asignarManual(
        $newConflictReservation,
        [5, 6],
        true,
        true,
        ['permitir_superposicion_ticket_abierto' => true]
    );
    $newTicket = s4Ticket($db, [6], null, '2026-11-30 18:00:00');
    $newConfirm = AsignacionMesasService::asignarManual(
        $newConflictReservation,
        [5, 6],
        true,
        true,
        [
            'ticket_ids_aceptados' => [$existingTicket],
            'conflicto_token' => $newPreview['conflicto_token'],
            'permitir_superposicion_ticket_abierto' => true,
        ]
    );
    s4Same('nuevo ticket antes de confirmar produce conflicto concurrente', $newConfirm['codigo'], AsignacionMesasService::CONFLICTO_CONCURRENTE);
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id IN ({$existingTicket},{$newTicket})");
    $db->query("UPDATE reservaciones SET estado='cancelada' WHERE id={$newConflictReservation}");

    $raceAssign = s4Reservation($db, '20:00:00', 7, 'confirmada', 'TEST-RACE-ASSIGN');
    $version = s4Version($db, $raceAssign);
    $assignRace = s4Race($databaseName, [
        ['mode' => 'assign', 'reservacion_id' => $raceAssign, 'mesa_ids' => [8], 'version_esperada' => $version, 'usuario_id' => 1],
        ['mode' => 'assign', 'reservacion_id' => $raceAssign, 'mesa_ids' => [9], 'version_esperada' => $version, 'usuario_id' => 1],
    ]);
    s4Same(
        'dos operadores reasignando tienen un ganador',
        count(array_filter($assignRace, static fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    s4Same(
        'segundo operador recibe versión desactualizada ' . json_encode($assignRace),
        count(array_filter($assignRace, static fn(array $r): bool => ($r['codigo'] ?? '') === AsignacionMesasService::VERSION_DESACTUALIZADA)),
        1
    );

    $expiredPending = s4Reservation(
        $db,
        '21:00:00',
        0,
        'pendiente_verificacion',
        'TEST-MAINT-EXPIRED',
        'maint@example.test',
        2,
        null,
        '2026-11-30 13:59:59'
    );
    $validPending = s4Reservation(
        $db,
        '21:30:00',
        0,
        'pendiente_verificacion',
        'TEST-MAINT-VALID',
        'maint@example.test',
        2,
        null,
        '2026-11-30 14:05:00'
    );
    $pendingPreview = ReservacionMantenimientoService::vistaPreviaPendientesVencidas();
    s4Same('vista previa de pendientes vencidas', $pendingPreview['total'], 1);
    $pendingProcess = ReservacionMantenimientoService::procesarPendientesVencidas(true);
    s4Same('pendiente vencida procesada', $pendingProcess['procesadas'], 1);
    s4Same('pendiente vencida cambia a expirada', $db->query("SELECT estado FROM reservaciones WHERE id={$expiredPending}")->fetch_assoc()['estado'], 'expirada');
    s4Same('pendiente vigente no se procesa', $db->query("SELECT estado FROM reservaciones WHERE id={$validPending}")->fetch_assoc()['estado'], 'pendiente_verificacion');

    $cleanNoShow = s4Reservation($db, '10:00:00', 0, 'no_show', 'TEST-CLEAN');
    $cleanExpired = s4Reservation($db, '10:30:00', 0, 'expirada', 'TEST-CLEAN');
    $protectedNoShow = s4Reservation($db, '11:00:00', 1, 'no_show', 'TEST-CLEAN-PROTECTED');
    $protectedTicket = s4Ticket($db, [1], $protectedNoShow);
    $cleanupInput = [
        'fecha_desde' => '2026-11-30',
        'fecha_hasta' => '2026-11-30',
        'estados' => ['no_show', 'expirada'],
        'prefijo' => 'TEST-CLEAN',
    ];
    $cleanupPreview = ReservacionMantenimientoService::vistaPreviaLimpieza($cleanupInput);
    s4Same('limpieza previa cuenta procesables', $cleanupPreview['procesables'], 2);
    s4Same('registro con ticket abierto se omite', $cleanupPreview['omitidas'], 1);
    s4Same(
        'confirmación incorrecta rechaza limpieza',
        ReservacionMantenimientoService::limpiar(array_merge($cleanupInput, ['confirmacion' => 'LIMPIAR']))['codigo'],
        ReservacionMantenimientoService::CONFIRMACION_INVALIDA
    );
    $cleanupResult = ReservacionMantenimientoService::limpiar(array_merge($cleanupInput, [
        'confirmacion' => ReservacionMantenimientoService::CONFIRMACION_LIMPIEZA,
    ]));
    s4Same('limpieza reporta procesadas', $cleanupResult['procesadas'], 2);
    s4Same('limpieza reporta omitidas', $cleanupResult['omitidas'], 1);
    s4Same('limpieza reporta fallidas', $cleanupResult['fallidas'], 0);
    s4Same('no-show de prueba eliminado', (int)$db->query("SELECT COUNT(*) total FROM reservaciones WHERE id={$cleanNoShow}")->fetch_assoc()['total'], 0);
    s4Same('expirada de prueba eliminada', (int)$db->query("SELECT COUNT(*) total FROM reservaciones WHERE id={$cleanExpired}")->fetch_assoc()['total'], 0);
    s4Same('evidencia operativa preservada', (int)$db->query("SELECT COUNT(*) total FROM reservaciones WHERE id={$protectedNoShow}")->fetch_assoc()['total'], 1);
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$protectedTicket}");

    $rollbackId = s4Reservation($db, '09:00:00', 0, 'no_show', 'TEST-ROLLBACK');
    $rollbackInput = [
        'fecha_desde' => '2026-11-30',
        'fecha_hasta' => '2026-11-30',
        'estados' => ['no_show'],
        'prefijo' => 'TEST-ROLLBACK',
        'confirmacion' => ReservacionMantenimientoService::CONFIRMACION_LIMPIEZA,
    ];
    $rollbackResult = ReservacionMantenimientoService::limpiar($rollbackInput, ['forzar_error' => true]);
    s4Assert('error intermedio devuelve fallo', !($rollbackResult['ok'] ?? false));
    s4Same('error intermedio produce rollback', (int)$db->query("SELECT COUNT(*) total FROM reservaciones WHERE id={$rollbackId}")->fetch_assoc()['total'], 1);

    $_ENV['APP_ENV'] = 'production';
    putenv('APP_ENV=production');
    s4Same(
        'mantenimiento rechazado en producción',
        ReservacionMantenimientoService::vistaPreviaPendientesVencidas()['codigo'],
        ReservacionMantenimientoService::AMBIENTE_NO_PERMITIDO
    );
    s4Clock($db, '2026-11-30 14:00:00');
} catch (Throwable $e) {
    $failures[] = 'Excepción no controlada: ' . $e->getMessage();
} finally {
    try {
        $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    } catch (Throwable $e) {
    }
    $db->close();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: {$tests} comprobaciones de estabilización." . PHP_EOL;
