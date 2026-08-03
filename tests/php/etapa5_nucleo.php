<?php

declare(strict_types=1);

/**
 * Suite integrada de Etapa 5. Usa una transacción y revierte todos los
 * fixtures prefijados al terminar; nunca cambia la hora del sistema.
 */

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\AsignacionMesasService;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\OcupacionMesasService;
use Services\ReservacionConfig;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexión MySQL para la suite de Etapa 5.\n");
    exit(2);
}

$passed = 0;
$failed = [];

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $message;
};

$query = static function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
    return $result;
};

$fechaLibre = static function (string $prefijo, array $omit = []) use ($db, $query): string {
    for ($offset = 5; $offset <= 80; $offset++) {
        $fecha = (new DateTimeImmutable('2026-11-01', ReservacionConfig::timezone()))
            ->modify('+' . $offset . ' days')->format('Y-m-d');
        $fechaSql = $db->real_escape_string($fecha);
        $res = $query("SELECT
                (SELECT COUNT(*) FROM reservaciones WHERE fecha = '{$fechaSql}') AS reservas,
                (SELECT COUNT(*) FROM excepciones_operacion WHERE fecha = '{$fechaSql}') AS excepciones");
        $fila = $res->fetch_assoc();
        $res->free();
        if ((int)$fila['reservas'] === 0 && (int)$fila['excepciones'] === 0 && !in_array($fecha, $omit, true)) {
            return $fecha;
        }
    }
    throw new RuntimeException('No se encontró una fecha libre para ' . $prefijo);
};

$insertException = static function (string $fecha, string $tipo, ?string $apertura, ?string $cierre) use ($db, $query): int {
    $fecha = $db->real_escape_string($fecha);
    $tipo = $db->real_escape_string($tipo);
    $aperturaSql = $apertura === null ? 'NULL' : "'" . $db->real_escape_string($apertura) . "'";
    $cierreSql = $cierre === null ? 'NULL' : "'" . $db->real_escape_string($cierre) . "'";
    $query("INSERT INTO excepciones_operacion
        (fecha, tipo, motivo, hora_apertura, hora_cierre, activo)
        VALUES ('{$fecha}', '{$tipo}', 'ETAPA5_NUCLEO', {$aperturaSql}, {$cierreSql}, 1)");
    return (int)$db->insert_id;
};

$mesaIds = [];
$res = $query('SELECT id, numero FROM mesas ORDER BY numero');
while ($fila = $res->fetch_assoc()) {
    $mesaIds[(int)$fila['numero']] = (int)$fila['id'];
}
$res->free();

$insertReservation = static function (
    string $nombre,
    string $fecha,
    string $hora,
    int $comensales,
    string $estado,
    ?string $hold,
    ?int $replacement = null
) use ($db, $query): int {
    $nombre = $db->real_escape_string($nombre);
    $fecha = $db->real_escape_string($fecha);
    $hora = $db->real_escape_string($hora);
    $estado = $db->real_escape_string($estado);
    $holdSql = $hold === null ? 'NULL' : "'" . $db->real_escape_string($hold) . "'";
    $replacementSql = $replacement === null ? 'NULL' : (string)$replacement;
    $query("INSERT INTO reservaciones
        (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado,
         hold_expires_at, reemplaza_reservacion_id, request_token)
        VALUES ('{$nombre}', 'ninguno', NULL, '{$fecha}', '{$hora}', {$comensales},
                'admin', '{$estado}', {$holdSql}, {$replacementSql},
                'ETAPA5_NUCLEO_{$nombre}')");
    return (int)$db->insert_id;
};

$assign = static function (int $reservacionId, array $numeros) use ($mesaIds, $query): void {
    foreach (array_values($numeros) as $orden => $numero) {
        if (!isset($mesaIds[(int)$numero])) {
            throw new RuntimeException('No existe mesa número ' . $numero);
        }
        $query(sprintf(
            'INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES (%d, %d, %d)',
            $reservacionId,
            $mesaIds[(int)$numero],
            $orden + 1
        ));
    }
};

$insertTicket = static function (
    string $nombre,
    string $apertura,
    array $numeros,
    ?int $reservacionId = null
) use ($db, $mesaIds, $query): int {
    $nombre = $db->real_escape_string($nombre);
    $apertura = $db->real_escape_string($apertura);
    $reservaSql = $reservacionId === null ? 'NULL' : (string)$reservacionId;
    $query("INSERT INTO tickets (comensales, nombre, hora_apertura, estado, reservacion_id)
            VALUES (2, '{$nombre}', '{$apertura}', 'abierto', {$reservaSql})");
    $ticketId = (int)$db->insert_id;
    foreach (array_values($numeros) as $orden => $numero) {
        $query(sprintf(
            'INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES (%d, %d, %d)',
            $ticketId,
            $mesaIds[(int)$numero],
            $orden + 1
        ));
    }
    return $ticketId;
};

$fakeMesa = static function (int $numero, int $id, int $capacidad = 4, int $activo = 1, int $reservable = 1, string $tipo = 'mesa'): object {
    return (object)[
        'id' => $id,
        'numero' => $numero,
        'nombre' => 'Fixture Mesa ' . $numero,
        'capacidad' => $capacidad,
        'activo' => $activo,
        'reservable' => $reservable,
        'tipo' => $tipo,
    ];
};

$now = new DateTimeImmutable('2026-11-01 12:00:00', ReservacionConfig::timezone());
$normalDate = '2026-11-02';
$specialDate = $fechaLibre('horario especial');
$closedDate = $fechaLibre('cierre especial', [$specialDate]);
$availabilityDate = $fechaLibre('disponibilidad', [$specialDate, $closedDate]);

try {
    $db->begin_transaction();

    // 5.1 — calendario operativo y excepciones.
    $query("UPDATE horarios_operacion SET abierto = 1, hora_apertura = '13:00:00', hora_cierre = '22:00:00' WHERE dia_semana = 1");
    $query("UPDATE horarios_operacion SET abierto = 1, hora_apertura = '08:30:00', hora_cierre = '19:00:00' WHERE dia_semana = 0");
    $normal = HorarioReservacionService::resolverFecha($normalDate, $now);
    $assert($normal['reservable'] === true, '5.1: día normal abierto');
    $assert(($normal['horarios_candidatos'][0] ?? null) === '13:00', '5.1: apertura semanal y primer candidato ' . json_encode($normal, JSON_UNESCAPED_UNICODE));
    $assert(end($normal['horarios_candidatos']) === '20:30', '5.1: última reservación semanal');

    $insertException($closedDate, 'cerrado', null, null);
    $insertException($specialDate, 'horario_especial', '14:00:00', '21:00:00');
    $closed = HorarioReservacionService::resolverFecha($closedDate, $now);
    $special = HorarioReservacionService::resolverFecha($specialDate, $now);
    $assert($closed['reservable'] === false && $closed['motivo_no_disponible'] === 'dia_no_operativo', '5.1: cierre especial');
    $assert($special['reservable'] === true && $special['hora_apertura'] === '14:00' && $special['hora_cierre'] === '21:00', '5.1: horario especial prioritario');
    $assert($special['horarios_candidatos'][0] === '14:00' && end($special['horarios_candidatos']) === '19:30', '5.1: candidatos del horario especial');

    $today = HorarioReservacionService::resolverFecha('2026-11-01', $now);
    $assert(($today['horarios_candidatos'][0] ?? null) === '13:00', '5.1: anticipación mínima de 40 minutos ' . json_encode($today, JSON_UNESCAPED_UNICODE));
    $assert(HorarioReservacionService::validarHora('2026-11-01', '12:30', $now)['motivo_no_disponible'] === 'anticipacion_insuficiente', '5.1: hora dentro de anticipación rechazada');
    $assert(HorarioReservacionService::validarHora('2026-11-01', '13:00', $now)['ok'] === true, '5.1: primera hora válida ' . json_encode(HorarioReservacionService::validarHora('2026-11-01', '13:00', $now), JSON_UNESCAPED_UNICODE));
    $assert(HorarioReservacionService::validarHora($normalDate, '21:00', $now)['motivo_no_disponible'] === 'despues_de_ultima_reservacion', '5.1: después de última reservación');
    $horizonte = $now->modify('+' . ReservacionConfig::HORIZONTE_MAXIMO_DIAS . ' days')->format('Y-m-d');
    $assert(HorarioReservacionService::resolverFecha($horizonte, $now)['motivo_no_disponible'] === null, '5.1: límite de horizonte incluido');
    $assert(HorarioReservacionService::resolverFecha($now->modify('+91 days')->format('Y-m-d'), $now)['motivo_no_disponible'] === 'fecha_fuera_de_horizonte', '5.1: fecha fuera de horizonte');
    $assert(HorarioReservacionService::resolverFecha('2026-10-31', $now)['motivo_no_disponible'] === 'fecha_pasada', '5.1: fecha pasada');
    $assert(ReservacionConfig::timezone()->getName() === 'America/Mexico_City', '5.1: zona horaria');

    // Falta de configuración: se revierte junto con el resto de fixtures.
    $query('DELETE FROM horarios_operacion WHERE dia_semana = 0');
    $sinHorario = HorarioReservacionService::resolverFecha('2026-11-01', $now);
    $assert($sinHorario['motivo_no_disponible'] === 'horario_sin_configuracion', '5.1: horario sin configuración');
    $query("INSERT INTO horarios_operacion (dia_semana, abierto, hora_apertura, hora_cierre) VALUES (0, 1, '08:30:00', '19:00:00')");

    // 5.2 — fixtures de ocupación sobre el día actual.
    $todayDate = '2026-11-01';
    $hold = $insertReservation('ETAPA5_HOLD_VIGENTE', $todayDate, '14:00:00', 2, 'pendiente_verificacion', '2026-11-01 12:15:00');
    $expiredHold = $insertReservation('ETAPA5_HOLD_VENCIDO', $todayDate, '14:00:00', 2, 'pendiente_verificacion', '2026-11-01 11:59:59');
    $confirmed = $insertReservation('ETAPA5_CONFIRMADA', $todayDate, '14:00:00', 2, 'confirmada', null);
    $cancelled = $insertReservation('ETAPA5_CANCELADA', $todayDate, '14:00:00', 2, 'cancelada', null);
    $noShow = $insertReservation('ETAPA5_NO_SHOW', $todayDate, '14:00:00', 2, 'no_show', null);
    $replaced = $insertReservation('ETAPA5_REEMPLAZADA', $todayDate, '14:00:00', 2, 'reemplazada', null);
    $inCourse = $insertReservation('ETAPA5_EN_CURSO', $todayDate, '14:00:00', 2, 'en_curso', null);
    $assign($hold, [1]);
    $assign($expiredHold, [2]);
    $assign($confirmed, [3]);
    $assign($cancelled, [4]);
    $assign($noShow, [5]);
    $assign($replaced, [6]);
    $assign($inCourse, [7]);
    $ticketCourse = $insertTicket('ETAPA5_TICKET_RESERVACION', '2026-11-01 13:30:00', [8], $inCourse);
    $ticketProjected = $insertTicket('ETAPA5_TICKET_PROYECTADO', '2026-11-01 11:00:00', [9]);
    $ticketExpiredEstimate = $insertTicket('ETAPA5_TICKET_ESTIMACION_VENCIDA', '2026-11-01 07:00:00', [10]);
    $ticketPreviousDay = $insertTicket('ETAPA5_TICKET_DIA_ANTERIOR', '2026-10-31 23:00:00', [11]);

    $occupancy = OcupacionMesasService::evaluarHorario($todayDate, '14:00:00', 0, false, null, $now);
    $assert($occupancy['mesas'][$mesaIds[1]]['fuente'] === 'hold', '5.2: hold vigente bloquea');
    $assert($occupancy['mesas'][$mesaIds[2]]['fuente'] === 'libre', '5.2: hold vencido no bloquea');
    $assert($occupancy['mesas'][$mesaIds[3]]['fuente'] === 'reservacion', '5.2: reservación confirmada bloquea');
    $assert($occupancy['mesas'][$mesaIds[4]]['fuente'] === 'libre' && $occupancy['mesas'][$mesaIds[5]]['fuente'] === 'libre', '5.2: cancelada/no-show liberan');
    $assert($occupancy['mesas'][$mesaIds[6]]['fuente'] === 'libre', '5.2: reemplazada libera');
    $assert($occupancy['mesas'][$mesaIds[7]]['fuente'] === 'libre', '5.2: en_curso no duplica asignación');
    $assert($occupancy['mesas'][$mesaIds[8]]['fuente'] === 'ticket_abierto' && $occupancy['mesas'][$mesaIds[8]]['ticket_id'] === $ticketCourse, '5.2: ticket físico gana a reservacion_mesas');
    $assert($occupancy['mesas'][$mesaIds[9]]['fuente'] === 'ticket_proyectado' && $occupancy['mesas'][$mesaIds[9]]['disponible'] === true, '5.2: liberación futura estimada');

    $currentPhysical = OcupacionMesasService::evaluarHorario($todayDate, '09:00:00', 0, false, null, $now);
    $assert($currentPhysical['mesas'][$mesaIds[10]]['fuente'] === 'ticket_abierto', '5.2: ticket con estimación vencida sigue físico');
    $assert($currentPhysical['mesas'][$mesaIds[11]]['fuente'] === 'ticket_abierto', '5.2: ticket abierto de día anterior sigue físico');
    $futurePhysical = OcupacionMesasService::evaluarHorario('2026-11-02', '14:00:00', 0, false, null, $now);
    $assert($futurePhysical['mesas'][$mesaIds[8]]['fuente'] === 'libre', '5.2: fecha futura ignora tickets actuales');
    $exact = OcupacionMesasService::ocupacionReservacionesEnVentana(
        [['mesa_id' => 1, 'reservacion_id' => 1, 'hora' => '14:00:00', 'estado' => 'confirmada']],
        '15:30:00'
    );
    $assert($exact === [], '5.2: intervalos consecutivos no se superponen');

    // 5.3 — combinaciones autorizadas y desempate.
    $mesas = [];
    for ($numero = 1; $numero <= 11; $numero++) {
        $mesas[] = $fakeMesa($numero, 1000 + $numero);
    }
    $mesas[] = $fakeMesa(12, 1012, 8, 1, 0, 'barra');
    $uno = AsignacionMesasService::seleccionarMesasPublicas($mesas, 1);
    $cinco = AsignacionMesasService::seleccionarMesasPublicas($mesas, 5);
    $ocho = AsignacionMesasService::seleccionarMesasPublicas($mesas, 8);
    $nueve = AsignacionMesasService::seleccionarMesasPublicas($mesas, 9);
    $trece = AsignacionMesasService::seleccionarMesasPublicas($mesas, 13);
    $assert(count($uno) === 1 && $uno[0]->numero === 1, '5.3: mesa individual');
    $assert(array_map(static fn($mesa): int => $mesa->numero, $cinco) === [7, 8], '5.3: par predefinido por número');
    $assert(array_map(static fn($mesa): int => $mesa->numero, $ocho) === [7, 8], '5.3: límite de 8 determinista');
    $assert(array_map(static fn($mesa): int => $mesa->numero, $nueve) === [2, 4, 5], '5.3: trío predefinido');
    $assert($trece === [], '5.3: más de 12 requiere asignación manual');
    $sinSiete = array_values(array_filter($mesas, static fn($mesa): bool => $mesa->numero !== 7));
    $siguientePar = AsignacionMesasService::seleccionarMesasPublicas($sinSiete, 5);
    $assert(array_map(static fn($mesa): int => $mesa->numero, $siguientePar) === [6, 9], '5.3: mesa faltante no se sustituye arbitrariamente');
    $conBarra = AsignacionMesasService::seleccionarMesasPublicas([$mesas[0], $mesas[11]], 5);
    $assert($conBarra === [], '5.3: barra excluida aunque tenga capacidad');

    // 5.4 — disponibilidad canónica y fachada pública binaria.
    $one = DisponibilidadReservacionService::consultarUna($availabilityDate, '14:00:00', 6, 0, $now);
    $public = DisponibilidadReservacionService::respuestaPublica($one);
    $assert($one['disponible'] === true && count($one['mesa_ids']) === 2, '5.4: solicitud válida con combinación física');
    $assert($one['tipo_combinacion'] === 'par_predefinido', '5.4: tipo de combinación');
    $assert($public === ['disponible' => true], '5.4: salida pública binaria');
    $publicSlots = DisponibilidadReservacionService::consultar($availabilityDate, 6);
    $assert(isset($publicSlots['horarios']) && $publicSlots['horarios'] !== [], '5.4: horarios alternativos canónicos');
    foreach ($publicSlots['horarios'] as $slot) {
        $assert(array_keys($slot) === ['hora', 'disponible'], '5.4: slot público sin capacidad');
    }
    $invalid = DisponibilidadReservacionService::consultarUna($availabilityDate, '14:00:00', 13, 0, $now);
    $assert($invalid['disponible'] === false && $invalid['requiere_asignacion_manual'] === true, '5.4: grupo grande requiere asignación manual ' . json_encode($invalid, JSON_UNESCAPED_UNICODE));
    $assert(DisponibilidadReservacionService::respuestaPublica($invalid)['motivo'] === 'requiere_contactar_restaurante', '5.4: motivo público de grupo grande ' . json_encode(DisponibilidadReservacionService::respuestaPublica($invalid), JSON_UNESCAPED_UNICODE));

    $start = microtime(true);
    HorarioReservacionService::resolverFecha($availabilityDate, $now);
    $scheduleMs = (microtime(true) - $start) * 1000;
    $start = microtime(true);
    OcupacionMesasService::evaluarHorario($todayDate, '14:00:00', 0, false, null, $now);
    $occupancyMs = (microtime(true) - $start) * 1000;
    $start = microtime(true);
    DisponibilidadReservacionService::consultarUna($availabilityDate, '14:00:00', 6, 0, $now);
    $availabilityMs = (microtime(true) - $start) * 1000;

    $db->rollback();
    echo json_encode([
        'ok' => $failed === [],
        'passed' => $passed,
        'failed' => $failed,
        'fixtures_rolled_back' => true,
        'clock' => $now->format('Y-m-d H:i:s'),
        'timezone' => ReservacionConfig::timezone()->getName(),
        'performance_ms' => [
            'schedule' => round($scheduleMs, 2),
            'occupancy' => round($occupancyMs, 2),
            'availability' => round($availabilityMs, 2),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "ETAPA5_FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
