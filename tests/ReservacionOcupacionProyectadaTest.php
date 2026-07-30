<?php

/**
 * Regresión de ocupación física/proyectada y agrupaciones autorizadas.
 */

declare(strict_types=1);

use Controllers\AdminReservacionController;
use Controllers\ReservacionController;
use Controllers\ReservacionOperacionController;
use Dotenv\Dotenv;
use Model\ActiveRecord;
use Model\Mesa;
use Services\AsignacionMesasService;
use Services\DisponibilidadReservacionService;
use Services\MesaEstadoService;
use Services\OcupacionMesasService;
use Services\ReservacionConfig;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set(ReservacionConfig::TIMEZONE);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$databaseName = 'casa_pestalozzi_ocupacion_proyectada_test';
$checks = 0;
$failures = [];

function opAssert(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $name;
    }
}

function opSame(string $name, mixed $actual, mixed $expected): void
{
    opAssert(
        $name . ': esperado ' . var_export($expected, true)
            . ', recibido ' . var_export($actual, true),
        $actual === $expected
    );
}

function opSqlFile(mysqli $db, string $path): void
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

function opClock(mysqli $db, string $now): void
{
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['RESERVATION_TEST_NOW'] = $now;
    putenv('APP_ENV=testing');
    putenv('RESERVATION_TEST_NOW=' . $now);
    $db->query("SET timestamp = UNIX_TIMESTAMP('" . $db->real_escape_string($now) . "')");
}

function opTableId(mysqli $db, int $number): int
{
    $row = $db->query("SELECT id FROM mesas WHERE numero={$number} LIMIT 1")->fetch_assoc();
    return (int)($row['id'] ?? 0);
}

function opTicket(mysqli $db, string $openedAt, array $numbers): int
{
    $openedAt = $db->real_escape_string($openedAt);
    $db->query(
        "INSERT INTO tickets (comensales, nombre, hora_apertura, estado, closed_at)
         VALUES (2, 'OP-TICKET', '{$openedAt}', 'abierto', NULL)"
    );
    $ticketId = (int)$db->insert_id;
    foreach (array_values($numbers) as $index => $number) {
        $mesaId = opTableId($db, (int)$number);
        $order = $index + 1;
        $db->query(
            "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden)
             VALUES ({$ticketId}, {$mesaId}, {$order})"
        );
    }
    return $ticketId;
}

function opReservation(mysqli $db, string $date, string $time, int $people, array $numbers): int
{
    $token = 'op-' . bin2hex(random_bytes(12));
    $db->query(
        "INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, fecha, hora, comensales, estado,
             request_token, confirmed_at)
         VALUES
            ('OP-RESERVA', 'email', 'op@example.test', '{$date}', '{$time}',
             {$people}, 'confirmada', '{$token}', NOW())"
    );
    $reservationId = (int)$db->insert_id;
    foreach (array_values($numbers) as $index => $number) {
        $mesaId = opTableId($db, (int)$number);
        $order = $index + 1;
        $db->query(
            "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
             VALUES ({$reservationId}, {$mesaId}, {$order})"
        );
    }
    return $reservationId;
}

/** @return array{status:int,body:array<string,mixed>} */
function opHttp(callable $callback): array
{
    http_response_code(200);
    ob_start();
    $callback();
    $body = json_decode((string)ob_get_clean(), true);
    return [
        'status' => http_response_code(),
        'body' => is_array($body) ? $body : [],
    ];
}

try {
    $db = mysqli_connect(
        (string)($_ENV['DB_HOST'] ?? 'localhost'),
        (string)($_ENV['DB_USER'] ?? ''),
        (string)($_ENV['DB_PASS'] ?? '')
    );
    if (!$db) {
        throw new RuntimeException('No fue posible conectar con MySQL.');
    }
    $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    $db->query(
        "CREATE DATABASE `{$databaseName}`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $db->select_db($databaseName);
    $db->query("SET time_zone = '-06:00'");
    opClock($db, '2026-12-04 10:00:00');
    opSqlFile($db, __DIR__ . '/../database/ddl.sql');
    opSqlFile($db, __DIR__ . '/../database/dml.sql');
    $db->query('DELETE FROM ticket_pagos');
    $db->query('DELETE FROM ticket_items');
    $db->query('DELETE FROM ticket_mesas');
    $db->query('DELETE FROM tickets');
    $db->query('DELETE FROM verificaciones_contacto');
    $db->query('DELETE FROM reservacion_mesas');
    $db->query('DELETE FROM reservaciones');
    ActiveRecord::setDB($db);

    $ticketId = opTicket($db, '2026-12-04 09:00:00', [1]);
    $mesa1 = opTableId($db, 1);

    $actual = OcupacionMesasService::evaluarHorario('2026-12-04', '10:00');
    opSame('horario actual usa ocupación física', $actual['contexto'], OcupacionMesasService::CONTEXTO_ACTUAL);
    opAssert('ticket actual bloquea su mesa', isset($actual['ocupacion_bloqueante'][$mesa1]));

    $variasHoras = OcupacionMesasService::evaluarHorario('2026-12-04', '14:00');
    opSame('horario futuro del día usa proyección', $variasHoras['contexto'], OcupacionMesasService::CONTEXTO_PROYECTADO);
    opAssert('ticket varias horas después queda proyectado', !isset($variasHoras['ocupacion_bloqueante'][$mesa1]));
    opAssert('mesa conserva indicador proyectado', in_array($mesa1, $variasHoras['mesas_proyectadas'], true));

    $db->query("UPDATE tickets SET hora_apertura='2026-12-04 09:50:00' WHERE id={$ticketId}");
    $antesBloqueo = OcupacionMesasService::evaluarHorario('2026-12-04', '12:30');
    $despuesBloqueo = OcupacionMesasService::evaluarHorario('2026-12-04', '12:00');
    opAssert('liberación antes del bloqueo permite la mesa', !isset($antesBloqueo['ocupacion_bloqueante'][$mesa1]));
    opAssert('liberación después del bloqueo mantiene ocupación', isset($despuesBloqueo['ocupacion_bloqueante'][$mesa1]));

    $db->query("UPDATE tickets SET hora_apertura='2026-12-04 08:00:00' WHERE id={$ticketId}");
    $reservationId = opReservation($db, '2026-12-04', '10:25:00', 2, [1]);
    $conflicto = OcupacionMesasService::evaluarHorario('2026-12-04', '10:25');
    opSame('ticket abierto al entrar al bloqueo genera alerta', count($conflicto['alertas_operativas']), 1);
    opSame(
        'alerta identifica la reservación afectada',
        (int)$conflicto['alertas_operativas'][0]['reservacion_id'],
        $reservationId
    );

    $futura = OcupacionMesasService::evaluarHorario('2026-12-05', '10:00');
    $historica = OcupacionMesasService::evaluarHorario('2026-12-03', '10:00');
    opAssert('fecha futura ignora tickets actuales', !isset($futura['ocupacion_bloqueante'][$mesa1]));
    opAssert('fecha histórica ignora tickets actuales', !isset($historica['ocupacion_bloqueante'][$mesa1]));

    $mesaVisual = [[
        'id' => $mesa1,
        'numero' => 1,
        'nombre' => 'Mesa 1',
        'tipo' => 'mesa',
        'capacidad' => 4,
        'activo' => 1,
        'reservable' => 1,
    ]];
    $ticketVisual = [[
        'id' => $ticketId,
        'reservacion_id' => null,
        'origen' => 'walk_in',
        'hora_apertura' => '2026-12-04 08:00:00',
        'mesa_ids' => [$mesa1],
    ]];
    $mapActual = MesaEstadoService::normalizarMesas(
        $mesaVisual,
        [],
        $ticketVisual,
        '2026-12-04',
        ReservacionConfig::ahora(),
        '10:00',
        $actual
    )[0];
    $mapProyectado = MesaEstadoService::normalizarMesas(
        $mesaVisual,
        [],
        $ticketVisual,
        '2026-12-04',
        ReservacionConfig::ahora(),
        '14:00',
        $variasHoras
    )[0];
    $mapFuturo = MesaEstadoService::normalizarMesas(
        $mesaVisual,
        [],
        $ticketVisual,
        '2026-12-05',
        ReservacionConfig::ahora(),
        '10:00',
        $futura
    )[0];
    $mapHistorico = MesaEstadoService::normalizarMesas(
        $mesaVisual,
        [],
        $ticketVisual,
        '2026-12-03',
        ReservacionConfig::ahora(),
        '10:00',
        $historica
    )[0];
    $mapConflicto = MesaEstadoService::normalizarMesas(
        $mesaVisual,
        [[
            'id' => $reservationId,
            'fecha' => '2026-12-04',
            'hora' => '10:25',
            'estado' => 'confirmada',
            'mesa_ids' => [$mesa1],
        ]],
        $ticketVisual,
        '2026-12-04',
        ReservacionConfig::ahora(),
        '10:25',
        $conflicto
    )[0];
    opSame('mapa actual muestra ocupada', $mapActual['estado_base'], MesaEstadoService::OCUPADA);
    opAssert('mapa proyectado no oculta el ticket', in_array('disponible_proyectada', $mapProyectado['modificadores'], true));
    opSame('mapa de fecha futura no traslada ticket', $mapFuturo['estado_base'], MesaEstadoService::DISPONIBLE);
    opSame('mapa histórico no traslada ticket', $mapHistorico['estado_base'], MesaEstadoService::DISPONIBLE);
    opAssert('mapa distingue conflicto próximo', in_array('conflicto_proximo', $mapConflicto['modificadores'], true));

    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id={$reservationId}");
    $db->query("DELETE FROM reservaciones WHERE id={$reservationId}");
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$ticketId}");
    $mesas = Mesa::reservables();

    foreach ([5, 6, 7, 8] as $people) {
        $selection = AsignacionMesasService::seleccionarMesasPublicas($mesas, $people);
        opSame("grupo de {$people} usa pareja", count($selection), 2);
        opAssert(
            "pareja de {$people} está autorizada",
            OcupacionMesasService::agrupacionValida($selection, $people)
        );
    }
    foreach ([9, 10, 11, 12] as $people) {
        $selection = AsignacionMesasService::seleccionarMesasPublicas($mesas, $people);
        opSame("grupo de {$people} usa trío", count($selection), 3);
        opAssert(
            "trío de {$people} está autorizado",
            OcupacionMesasService::agrupacionValida($selection, $people)
        );
    }

    $fallbackOcho = AsignacionMesasService::seleccionarMesasPublicas([
        (object)['id' => 2, 'numero' => 2, 'tipo' => 'mesa', 'capacidad' => 3, 'activo' => 1, 'reservable' => 1],
        (object)['id' => 4, 'numero' => 4, 'tipo' => 'mesa', 'capacidad' => 4, 'activo' => 1, 'reservable' => 1],
        (object)['id' => 5, 'numero' => 5, 'tipo' => 'mesa', 'capacidad' => 4, 'activo' => 1, 'reservable' => 1],
    ], 8);
    opSame('grupo de 8 usa trío cuando la pareja no alcanza', count($fallbackOcho), 3);

    $sinMesa2 = array_values(array_filter(
        $mesas,
        static fn($mesa): bool => (int)$mesa->id !== 2
    ));
    $parejaAlterna = AsignacionMesasService::seleccionarMesasPublicas($sinMesa2, 6);
    opAssert(
        'pareja parcialmente ocupada no se selecciona',
        !in_array(2, array_map(static fn($mesa): int => (int)$mesa->id, $parejaAlterna), true)
    );

    $mesasSobrante = [];
    foreach ([2 => 4, 4 => 4, 5 => 6, 11 => 4] as $id => $capacity) {
        $mesasSobrante[] = (object)[
            'id' => $id,
            'numero' => $id,
            'tipo' => 'mesa',
            'capacidad' => $capacity,
            'activo' => 1,
            'reservable' => 1,
        ];
    }
    opSame(
        'selección minimiza capacidad sobrante',
        array_map(
            static fn($mesa): int => (int)$mesa->id,
            AsignacionMesasService::seleccionarMesasPublicas($mesasSobrante, 6)
        ),
        [2, 4]
    );

    $ticketGrupo = opTicket($db, '2026-12-04 16:30:00', [2]);
    $resumenTicket = DisponibilidadReservacionService::resumenHorario(
        '2026-12-04',
        '17:00',
        6,
        0,
        false,
        true
    );
    opAssert('ticket bloqueante excluye la pareja afectada', !in_array(2, $resumenTicket['mesa_ids'], true));
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$ticketGrupo}");

    $reservaGrupo = opReservation($db, '2026-12-04', '17:00:00', 2, [5]);
    $resumenReserva = DisponibilidadReservacionService::resumenHorario(
        '2026-12-04',
        '17:00',
        6,
        0,
        false,
        true
    );
    opAssert('reservación futura excluye la pareja afectada', !in_array(5, $resumenReserva['mesa_ids'], true));

    $publica = DisponibilidadReservacionService::evaluarHorario('2026-12-04', '17:00', 6);
    opSame('consulta y resumen público eligen las mismas mesas', $publica['mesa_ids'], $resumenReserva['mesa_ids']);

    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id={$reservaGrupo}");
    $db->query("DELETE FROM reservaciones WHERE id={$reservaGrupo}");
    $antesDeCambio = DisponibilidadReservacionService::evaluarHorario('2026-12-04', '17:00', 9);
    $mesaQueCambio = (int)($antesDeCambio['mesa_ids'][0] ?? 0);
    opAssert('precondición obtiene un trío antes del cambio', count($antesDeCambio['mesa_ids'] ?? []) === 3);
    $numeroQueCambio = (int)($db->query(
        "SELECT numero FROM mesas WHERE id={$mesaQueCambio}"
    )->fetch_assoc()['numero'] ?? 0);
    $ticketConcurrente = opTicket($db, '2026-12-04 16:30:00', [$numeroQueCambio]);
    $reservaRevalidada = opReservation($db, '2026-12-04', '17:00:00', 9, []);
    $asignacionRevalidada = AsignacionMesasService::asignarAutomaticamentePublica($reservaRevalidada);
    opAssert('asignación final revalida el cambio de ocupación', (bool)($asignacionRevalidada['ok'] ?? false));
    opAssert(
        'asignación final evita la mesa ocupada después de consultar',
        !in_array($mesaQueCambio, (array)($asignacionRevalidada['mesa_ids'] ?? []), true)
    );
    $db->query("UPDATE tickets SET estado='cerrado', closed_at=NOW() WHERE id={$ticketConcurrente}");

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['fecha' => '2026-12-04', 'personas' => '6'];
    $httpPublico = opHttp(static function (): void {
        ReservacionController::disponibilidad(new \MVC\Router());
    });
    opSame('HTTP público responde disponibilidad', $httpPublico['status'], 200);
    opAssert(
        'HTTP público expone capacidad real y proyectada por horario',
        isset($httpPublico['body']['detalle_horarios'])
            && isset($httpPublico['body']['horarios'][0]['capacidad_realmente_libre'])
    );

    $_GET = ['fecha' => '2026-12-04', 'personas' => '6'];
    $httpAdmin = opHttp(static function (): void {
        AdminReservacionController::disponibilidad();
    });
    opSame('HTTP administrativo responde disponibilidad', $httpAdmin['status'], 200);
    opAssert('HTTP administrativo usa el mismo contrato de capacidad', isset($httpAdmin['body']['detalle_horarios']));

    $_GET = ['fecha' => '2026-12-05', 'hora' => '17:00'];
    $httpMapaFuturo = opHttp(static function (): void {
        ReservacionOperacionController::operationData();
    });
    opSame('HTTP mapa futuro responde', $httpMapaFuturo['status'], 200);
    opSame('HTTP mapa futuro no expone tickets actuales', $httpMapaFuturo['body']['ocupacion_fisica'] ?? null, []);
    opAssert('HTTP mapa expone resumen unificado', isset($httpMapaFuturo['body']['capacidad_horario']));

    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id={$reservaRevalidada}");
    $db->query("DELETE FROM reservaciones WHERE id={$reservaRevalidada}");
    $db->query("DROP DATABASE `{$databaseName}`");
    $db->close();
} catch (Throwable $e) {
    $failures[] = 'Excepción: ' . $e->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'OK: ' . $checks . " comprobaciones de ocupación proyectada y grupos autorizados." . PHP_EOL;
