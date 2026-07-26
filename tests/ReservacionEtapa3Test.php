<?php

/**
 * Integración reproducible de horarios, ocupación POS y concurrencia — Etapa 3.
 * Sólo muta casa_pestalozzi_etapa3_test después de comprobar SELECT DATABASE().
 */

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;
use Model\TicketMesa;
use Services\DisponibilidadReservacionService;
use Services\HorarioOperacionService;
use Services\HorarioReservacionService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionService;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$databaseName = 'casa_pestalozzi_etapa3_test';
$keepDatabase = getenv('E3_KEEP_DATABASE') === '1';
$db = mysqli_connect(
    (string)($_ENV['DB_HOST'] ?? 'localhost'),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? '')
);
$tests = 0;
$failures = [];

function e3Assert(string $name, bool $condition): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $name;
    }
}

function e3Same(string $name, mixed $actual, mixed $expected): void
{
    e3Assert($name . ': esperado ' . var_export($expected, true) . ', recibido ' . var_export($actual, true), $actual === $expected);
}

function e3SqlFile(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    $db->multi_query((string)$sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function e3Count(mysqli $db, string $sql): int
{
    return (int)($db->query($sql)->fetch_assoc()['total'] ?? 0);
}

function e3Reservation(mysqli $db, string $date, string $time, int $table, string $state = 'confirmada'): int
{
    $name = $db->real_escape_string('Automática Etapa 3 ' . bin2hex(random_bytes(3)));
    $db->query(
        "INSERT INTO reservaciones
            (nombre, email, contacto_tipo, contacto_valor, contacto_normalizado,
             fecha, hora, comensales, estado, confirmed_at)
         VALUES
            ('{$name}', 'e3.auto@example.test', 'email', 'e3.auto@example.test',
             'e3.auto@example.test', '{$date}', '{$time}', 2, '{$state}', NOW())"
    );
    $id = (int)$db->insert_id;
    $db->query("INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES ({$id}, {$table}, 1)");
    return $id;
}

/** Ejecuta dos conexiones mysqli reales contra la misma barrera. */
function e3Race(string $database, array $payloads): array
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'casa_e3_' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $go = $dir . DIRECTORY_SEPARATOR . 'go';
    $processes = [];
    $paths = [];
    foreach ($payloads as $i => $payload) {
        $ready = $dir . DIRECTORY_SEPARATOR . "ready{$i}";
        $result = $dir . DIRECTORY_SEPARATOR . "result{$i}.json";
        $command = [
            PHP_BINARY,
            __DIR__ . '/ReservacionEtapa3ConcurrencyWorker.php',
            $database,
            base64_encode((string)json_encode($payload)),
            $ready,
            $go,
            $result,
        ];
        $process = proc_open($command, [
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
        $status = proc_close($process);
        if ($status !== 0) {
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
    $selected = (string)$db->query('SELECT DATABASE() AS db')->fetch_assoc()['db'];
    if ($selected !== $databaseName) {
        throw new RuntimeException('SELECT DATABASE() no coincide con la base desechable.');
    }
    e3Assert('diagnóstico SELECT DATABASE antes de mutar', $selected === $databaseName);
    e3SqlFile($db, __DIR__ . '/../database/ddl.sql');
    e3SqlFile($db, __DIR__ . '/../database/dml.sql');
    ActiveRecord::setDB($db);

    // Horarios.
    $week = HorarioOperacionService::obtenerHorarioSemanal();
    e3Same('leer siete días semanales', count($week), 7);
    $special = HorarioOperacionService::obtenerHorarioEfectivo('2097-03-04');
    e3Same('horario especial tiene prioridad', $special['origen'], 'excepcion');
    e3Same('horario especial abre', $special['abierto'], true);
    $closed = HorarioOperacionService::obtenerHorarioEfectivo('2097-03-05');
    e3Same('excepción de cierre gana', $closed['abierto'], false);
    $slots = HorarioReservacionService::generarIntervalos('12:00', '20:00');
    e3Same('primer slot especial', $slots[0], '12:00:00');
    e3Same('última reservación una hora antes', end($slots), '19:00:00');
    e3Assert('intervalo posterior rechazado', !in_array('19:30:00', $slots, true));
    e3Same('intervalos cada treinta minutos', count($slots), 15);

    $modified = $week;
    $modified[2]['hora_apertura'] = '10:00';
    $modified[2]['hora_cierre'] = '21:00';
    $saved = HorarioOperacionService::guardarHorarioSemanal($modified, 1, true);
    e3Assert('actualizar apertura y cierre', (bool)$saved['ok']);
    e3Same('contrato de éxito de horarios', $saved['codigo'] ?? '', 'HORARIOS_ACTUALIZADOS');
    e3Same('respuesta de éxito devuelve siete días', count($saved['horarios'] ?? []), 7);
    $reloaded = HorarioOperacionService::obtenerHorarioSemanal();
    e3Same('persistencia apertura', $reloaded[2]['hora_apertura'], '10:00');
    e3Same('persistencia cierre', $reloaded[2]['hora_cierre'], '21:00');
    $invalid = $reloaded;
    $invalid[2]['hora_apertura'] = '21:00';
    $invalid[2]['hora_cierre'] = '10:00';
    $invalidResult = HorarioOperacionService::guardarHorarioSemanal($invalid, 1);
    e3Assert('horario imposible rechazado', !$invalidResult['ok']);
    e3Same('horario imposible devuelve contrato 422', $invalidResult['codigo'] ?? '', 'HORARIO_INVALIDO');

    // Los upserts y la sincronización reparan filas ausentes sin depender de
    // que el esquema ya tenga sembrados los siete días.
    $db->query('DELETE FROM horarios_operacion WHERE dia_semana = 6');
    $missingWeekly = HorarioOperacionService::guardarHorarioSemanal($reloaded, 1, true);
    e3Assert('crear fila semanal faltante', $missingWeekly['ok']);
    e3Same('fila semanal recreada', e3Count($db, 'SELECT COUNT(*) total FROM horarios_operacion WHERE dia_semana = 6'), 1);

    $db->query('DELETE FROM dias_reservacion WHERE dia_semana = 6');
    $missingProjection = HorarioOperacionService::guardarHorarioSemanal($reloaded, 1, true);
    e3Assert('crear día legacy faltante', $missingProjection['ok']);
    e3Same('día legacy recreado', e3Count($db, 'SELECT COUNT(*) total FROM dias_reservacion WHERE dia_semana = 6'), 1);
    e3Assert(
        'slots legacy recreados',
        e3Count(
            $db,
            'SELECT COUNT(*) total
             FROM horarios_reservacion hr
             JOIN dias_reservacion dr ON dr.id = hr.dia_id
             WHERE dr.dia_semana = 6'
        ) > 0
    );

    $closedWeek = $reloaded;
    $closedWeek[2]['abierto'] = false;
    $closedWeek[2]['hora_apertura'] = '';
    $closedWeek[2]['hora_cierre'] = '';
    e3Assert('cerrar día semanal', HorarioOperacionService::guardarHorarioSemanal($closedWeek, 1, true)['ok']);
    e3Same('día cerrado persiste', HorarioOperacionService::obtenerHorarioSemanal()[2]['abierto'], false);
    e3Same(
        'cerrar día elimina slots anteriores',
        e3Count(
            $db,
            'SELECT COUNT(*) total
             FROM horarios_reservacion hr
             JOIN dias_reservacion dr ON dr.id = hr.dia_id
             WHERE dr.dia_semana = 2'
        ),
        0
    );
    e3Assert('reabrir día semanal', HorarioOperacionService::guardarHorarioSemanal($reloaded, 1, true)['ok']);
    e3Same(
        'reabrir día regenera slots',
        e3Count(
            $db,
            'SELECT COUNT(*) total
             FROM horarios_reservacion hr
             JOIN dias_reservacion dr ON dr.id = hr.dia_id
             WHERE dr.dia_semana = 2'
        ),
        count(HorarioReservacionService::generarIntervalos('10:00', '21:00'))
    );

    $conflictWeek = HorarioOperacionService::obtenerHorarioSemanal();
    $conflictWeek[1]['hora_cierre'] = '20:00';
    $conflict = HorarioOperacionService::guardarHorarioSemanal($conflictWeek, 1, false);
    e3Same('cambio con reservaciones exige confirmación', $conflict['codigo'] ?? '', 'RESERVACIONES_AFECTADAS');
    e3Assert('reservaciones afectadas no se cancelan', e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE id = @e3_horario_afectado AND estado = 'confirmada'") === 1);
    $confirmed = HorarioOperacionService::guardarHorarioSemanal($conflictWeek, 1, true);
    e3Assert('confirmación administrativa guarda', (bool)$confirmed['ok']);
    e3Assert('conflicto queda auditado', e3Count($db, "SELECT COUNT(*) total FROM reservacion_eventos WHERE evento = 'cambio_horario_con_conflictos'") > 0);

    // Rollback forzado al recalcular la proyección legacy.
    $beforeRollback = HorarioOperacionService::obtenerHorarioSemanal()[3]['hora_apertura'];
    $db->query("CREATE TRIGGER e3_fail_slot BEFORE INSERT ON horarios_reservacion FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='rollback e3'");
    $rollbackWeek = HorarioOperacionService::obtenerHorarioSemanal();
    $rollbackWeek[3]['hora_apertura'] = '10:30';
    $rollback = HorarioOperacionService::guardarHorarioSemanal($rollbackWeek, 1, true);
    $db->query('DROP TRIGGER e3_fail_slot');
    e3Assert('fallo derivado devuelve error', !$rollback['ok']);
    e3Same('rollback devuelve código técnico estable', $rollback['codigo'] ?? '', 'ERROR_ACTUALIZACION_HORARIOS');
    e3Same('rollback conserva horario anterior', HorarioOperacionService::obtenerHorarioSemanal()[3]['hora_apertura'], $beforeRollback);

    // Capacidad con ticket canónico y legacy.
    $future = '2098-08-04';
    $db->query("INSERT INTO tickets (mesa_id, comensales, nombre, hora_apertura, estado) VALUES (1, 2, 'Bloqueo futuro', '2098-08-04 17:30:00', 'abierto')");
    $futureTicket = (int)$db->insert_id;
    $db->query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$futureTicket}, 1, 1)");
    $occupied = TicketMesa::ocupacionAbierta($future, '18:00:00');
    e3Assert('ticket abierto ocupa mesa', in_array(1, array_column($occupied, 'mesa_id'), true));
    e3Assert('ticket canónico no se marca legacy', empty($occupied[array_search(1, array_column($occupied, 'mesa_id'), true)]['legacy']));

    $db->query("INSERT INTO tickets (mesa_id, comensales, nombre, hora_apertura, estado) VALUES (2, 2, 'Legacy futuro', '2098-08-04 17:30:00', 'abierto')");
    $legacyTicket = (int)$db->insert_id;
    $occupiedLegacy = TicketMesa::ocupacionAbierta($future, '18:00:00');
    $legacyRows = array_values(array_filter($occupiedLegacy, fn(array $r): bool => $r['ticket_id'] === $legacyTicket));
    e3Assert('ticket legacy participa', count($legacyRows) === 1 && $legacyRows[0]['legacy']);
    e3Assert('walk-in no crea reservación', e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE id = {$futureTicket}") >= 0);

    // Flujo POS sobre hoy.
    $today = ReservacionConfig::fechaActual();
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE estado='abierto' AND (mesa_id IN (1,2,3) OR mesa_secundaria_id IN (1,2,3))");
    $arrivalId = e3Reservation($db, $today, '23:00:00', 1);
    $arrival = PuntoVentaReservacionService::registrarLlegada($arrivalId, 1);
    e3Assert('registrar llegada', $arrival['ok']);
    e3Assert('llegada no abre ticket', e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id = {$arrivalId}") === 0);
    e3Assert('llegada idempotente', PuntoVentaReservacionService::registrarLlegada($arrivalId, 1)['idempotente']);
    $begin = PuntoVentaReservacionService::comenzar($arrivalId, 1);
    e3Assert('comenzar llegada crea ticket', $begin['ok'] && $begin['ticket_id'] > 0);
    e3Same('comenzar cambia a en_curso', $db->query("SELECT estado FROM reservaciones WHERE id={$arrivalId}")->fetch_assoc()['estado'], 'en_curso');
    e3Same('ticket y mesa atómicos', e3Count($db, "SELECT COUNT(*) total FROM ticket_mesas WHERE ticket_id=" . (int)$begin['ticket_id']), 1);
    e3Assert('doble inicio idempotente', PuntoVentaReservacionService::comenzar($arrivalId, 1)['idempotente']);
    $context = PuntoVentaReservacionService::contextoMesa(1);
    e3Assert('contexto muestra ticket abierto', $context['ok'] && $context['ticket_abierto']['id'] === $begin['ticket_id']);
    $closedTicket = PuntoVentaReservacionService::cerrarTicket((int)$begin['ticket_id'], 'efectivo', 0, [], 1);
    e3Assert('cierre de ticket', $closedTicket['ok']);
    e3Same('cierre completa reservación', $db->query("SELECT estado FROM reservaciones WHERE id={$arrivalId}")->fetch_assoc()['estado'], 'completada');
    e3Assert('doble cierre idempotente', PuntoVentaReservacionService::cerrarTicket((int)$begin['ticket_id'], 'efectivo', 0, [], 1)['idempotente']);

    $futureNoShow = e3Reservation($db, $today, '23:59:00', 2);
    $tooSoon = PuntoVentaReservacionService::noShow($futureNoShow, 1, false, false);
    e3Same('no-show antes de tolerancia rechazado', $tooSoon['codigo'], PuntoVentaReservacionService::TOLERANCIA_VIGENTE);
    $overrideNoShow = PuntoVentaReservacionService::noShow($futureNoShow, 1, true, true, 'Override de prueba');
    e3Assert('override con motivo permitido', $overrideNoShow['ok']);
    e3Same('override conserva no_show', $db->query("SELECT estado FROM reservaciones WHERE id={$futureNoShow}")->fetch_assoc()['estado'], 'no_show');

    $pastNoShow = e3Reservation($db, $today, '00:00:00', 2);
    e3Assert('no-show después de tolerancia', PuntoVentaReservacionService::noShow($pastNoShow, 1, false, false)['ok']);
    $cancelId = e3Reservation($db, $today, '22:00:00', 2);
    e3Assert('cancelación administrativa', PuntoVentaReservacionService::cancelar($cancelId, 1, 'Cliente llamó')['ok']);
    e3Same('cancelación libera estado', $db->query("SELECT estado FROM reservaciones WHERE id={$cancelId}")->fetch_assoc()['estado'], 'cancelada');

    $walkin = PuntoVentaReservacionService::abrirWalkIn([
        'mesa_ids' => [1, 2, 3],
        'comensales' => 8,
        'nombre' => 'Walk-in tres mesas',
        'confirmar_advertencia' => true,
    ], 1);
    e3Assert('walk-in de tres mesas', $walkin['ok'] && count($walkin['mesa_ids']) === 3);
    e3Same('walk-in N:M completo', e3Count($db, "SELECT COUNT(*) total FROM ticket_mesas WHERE ticket_id=" . (int)$walkin['ticket_id']), 3);
    $todayList = PuntoVentaReservacionService::listar($today);
    e3Assert('walk-in no visible en lista de reservaciones', !in_array($walkin['ticket_id'], array_column($todayList['reservaciones'], 'id'), true));
    e3Assert('walk-in visible en contexto', PuntoVentaReservacionService::contextoMesa(3)['ticket_abierto'] !== null);
    e3Assert('segunda apertura misma mesa rechazada', !PuntoVentaReservacionService::abrirWalkIn(['mesa_ids' => [3]], 1)['ok']);
    PuntoVentaReservacionService::cerrarTicket((int)$walkin['ticket_id'], 'efectivo', 0, [], 1);
    e3Assert('cierre libera walk-in', PuntoVentaReservacionService::contextoMesa(3)['ticket_abierto'] === null);

    // Dos conexiones reales.
    $raceReservation = e3Reservation($db, $today, '21:00:00', 1);
    $race = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $raceReservation, 'usuario_id' => 1],
        ['mode' => 'begin', 'reservacion_id' => $raceReservation, 'usuario_id' => 1],
    ]);
    e3Same('doble inicio crea un ticket', e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id={$raceReservation}"), 1);
    e3Assert('doble inicio sin estado parcial', count(array_filter($race, fn(array $r): bool => (bool)($r['ok'] ?? false))) === 2);

    $raceTicket = (int)$db->query("SELECT id FROM tickets WHERE reservacion_id={$raceReservation}")->fetch_assoc()['id'];
    $closeRace = e3Race($databaseName, [
        ['mode' => 'close', 'ticket_id' => $raceTicket, 'usuario_id' => 1],
        ['mode' => 'close', 'ticket_id' => $raceTicket, 'usuario_id' => 1],
    ]);
    e3Assert('cierre simultáneo idempotente', count(array_filter($closeRace, fn(array $r): bool => (bool)($r['ok'] ?? false))) === 2);
    e3Same('cierre simultáneo deja un token', e3Count($db, "SELECT COUNT(*) total FROM feedback_tokens WHERE ticket_id={$raceTicket}"), 1);

    $walkRace = e3Race($databaseName, [
        ['mode' => 'walkin', 'mesa_id' => 1, 'usuario_id' => 1],
        ['mode' => 'walkin', 'mesa_id' => 1, 'usuario_id' => 1],
    ]);
    e3Same('doble ticket tiene un ganador', count(array_filter($walkRace, fn(array $r): bool => (bool)($r['ok'] ?? false))), 1);
    e3Same('una mesa no tiene dos tickets abiertos', e3Count($db, "SELECT COUNT(DISTINCT t.id) total FROM tickets t LEFT JOIN ticket_mesas tm ON tm.ticket_id=t.id WHERE t.estado='abierto' AND tm.mesa_id=1"), 1);

    $walkRaceTicket = (int)$db->query(
        "SELECT t.id
         FROM tickets t
         JOIN ticket_mesas tm ON tm.ticket_id = t.id
         WHERE t.estado = 'abierto' AND tm.mesa_id = 1
         LIMIT 1"
    )->fetch_assoc()['id'];
    PuntoVentaReservacionService::cerrarTicket($walkRaceTicket, 'efectivo', 0, [], 1);

    $noShowRaceId = e3Reservation($db, $today, '00:00:00', 2);
    $noShowRace = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $noShowRaceId, 'usuario_id' => 1],
        ['mode' => 'no_show', 'reservacion_id' => $noShowRaceId, 'usuario_id' => 1],
    ]);
    $noShowRaceState = (string)$db->query(
        "SELECT estado FROM reservaciones WHERE id = {$noShowRaceId}"
    )->fetch_assoc()['estado'];
    e3Same(
        'no-show contra inicio tiene un ganador',
        count(array_filter($noShowRace, fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    e3Assert('no-show contra inicio deja estado terminal válido', in_array($noShowRaceState, ['no_show', 'en_curso'], true));
    e3Same(
        'no-show contra inicio no deja ticket parcial',
        e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id = {$noShowRaceId}"),
        $noShowRaceState === 'en_curso' ? 1 : 0
    );
    if ($noShowRaceState === 'en_curso') {
        $ticketId = (int)$db->query(
            "SELECT id FROM tickets WHERE reservacion_id = {$noShowRaceId} LIMIT 1"
        )->fetch_assoc()['id'];
        PuntoVentaReservacionService::cerrarTicket($ticketId, 'efectivo', 0, [], 1);
    }

    $cancelRaceId = e3Reservation($db, $today, '22:00:00', 2);
    $cancelRace = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $cancelRaceId, 'usuario_id' => 1],
        ['mode' => 'cancel', 'reservacion_id' => $cancelRaceId, 'usuario_id' => 1],
    ]);
    $cancelRaceState = (string)$db->query(
        "SELECT estado FROM reservaciones WHERE id = {$cancelRaceId}"
    )->fetch_assoc()['estado'];
    e3Same(
        'cancelación contra inicio tiene un ganador',
        count(array_filter($cancelRace, fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    e3Assert('cancelación contra inicio deja estado válido', in_array($cancelRaceState, ['cancelada', 'en_curso'], true));
    e3Same(
        'cancelación contra inicio no deja ticket parcial',
        e3Count($db, "SELECT COUNT(*) total FROM tickets WHERE reservacion_id = {$cancelRaceId}"),
        $cancelRaceState === 'en_curso' ? 1 : 0
    );
    if ($cancelRaceState === 'en_curso') {
        $ticketId = (int)$db->query(
            "SELECT id FROM tickets WHERE reservacion_id = {$cancelRaceId} LIMIT 1"
        )->fetch_assoc()['id'];
        PuntoVentaReservacionService::cerrarTicket($ticketId, 'efectivo', 0, [], 1);
    }

    $ticketReservationRaceId = e3Reservation($db, $today, '21:30:00', 3);
    $ticketReservationRace = e3Race($databaseName, [
        ['mode' => 'begin', 'reservacion_id' => $ticketReservationRaceId, 'usuario_id' => 1],
        ['mode' => 'walkin', 'mesa_id' => 3, 'usuario_id' => 1],
    ]);
    e3Same(
        'ticket contra reservación tiene un ganador',
        count(array_filter($ticketReservationRace, fn(array $r): bool => (bool)($r['ok'] ?? false))),
        1
    );
    e3Same(
        'ticket contra reservación deja una ocupación',
        e3Count($db, "SELECT COUNT(DISTINCT t.id) total FROM tickets t JOIN ticket_mesas tm ON tm.ticket_id=t.id WHERE t.estado='abierto' AND tm.mesa_id=3"),
        1
    );

    $scheduleDate = '2099-07-27';
    $scheduleDay = (int)(new DateTimeImmutable($scheduleDate))->format('N');
    $scheduleBeforeRace = HorarioOperacionService::obtenerHorarioSemanal();
    $availableForRace = ReservacionService::obtenerHorariosDisponiblesParaFecha($scheduleDate, true);
    $scheduleRace = e3Race($databaseName, [
        ['mode' => 'schedule_close', 'dia_semana' => $scheduleDay, 'usuario_id' => 1],
        [
            'mode' => 'reserve',
            'fecha' => $scheduleDate,
            'hora' => (string)($availableForRace['horarios'][0] ?? '12:00:00'),
            'email' => 'e3.schedule.race@example.test',
            'request_token' => 'e3-schedule-race-0001',
        ],
    ]);
    e3Assert('horario contra creación guarda cierre', (bool)($scheduleRace[0]['ok'] ?? false));
    e3Same('horario contra creación resuelve fecha cerrada', HorarioOperacionService::obtenerHorarioEfectivo($scheduleDate)['abierto'], false);
    e3Assert(
        'horario contra creación no deja más de una reservación',
        e3Count($db, "SELECT COUNT(*) total FROM reservaciones WHERE email='e3.schedule.race@example.test'") <= 1
    );
    e3Assert('restaurar horario después de carrera', HorarioOperacionService::guardarHorarioSemanal($scheduleBeforeRace, 1, true)['ok']);

    if ($failures !== []) {
        fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
        exit(1);
    }
    echo "OK: {$tests} comprobaciones de Etapa 3." . PHP_EOL;
} finally {
    if ($db instanceof mysqli) {
        // La base sólo se conserva bajo opt-in para pruebas visuales locales.
        if (!$keepDatabase) {
            $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
        }
        $db->close();
    }
}
