<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\CapacidadReservacionesService;
use Services\MesaEstadoService;
use Services\OcupacionMesasService;
use Services\ReservacionConfig;

/** @param mixed $condition */
function assertParity($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function at(string $time): DateTimeImmutable
{
    return new DateTimeImmutable('2026-08-06 ' . $time, ReservacionConfig::timezone());
}

$duration = ReservacionConfig::DURACION_RESERVACION_MINUTOS;
$reservationStart = at('15:00:00');
$reservationEnd = $reservationStart->modify('+' . $duration . ' minutes');
assertParity(
    OcupacionMesasService::intervalosSeTraslapan($reservationStart, $reservationStart),
    'inicio productivo incluido'
);
assertParity(
    OcupacionMesasService::intervalosSeTraslapan(
        $reservationStart,
        $reservationEnd->modify('-1 minute')
    ),
    'fin productivo menos un minuto traslapa'
);
assertParity(
    !OcupacionMesasService::intervalosSeTraslapan($reservationStart, $reservationEnd),
    'fin productivo exacto libera'
);
assertParity(
    OcupacionMesasService::intervalosSeTraslapan($reservationStart, at('14:00:00')),
    'consulta anterior cuyo fin cruza el inicio traslapa'
);

$mesa4 = [
    'id' => 4,
    'numero' => 4,
    'nombre' => 'Mesa 4',
    'tipo' => 'mesa',
    'capacidad' => 4,
    'activo' => 1,
    'reservable' => 1,
    'pos_x' => 10,
    'pos_y' => 10,
];
$reservation = [
    'id' => 100,
    'estado' => 'confirmada',
    'fecha' => '2026-08-06',
    'hora' => '15:00:00',
    'mesa_ids' => [4],
    'comensales' => 2,
];
$evaluationBlocked = [
    'mesa_ids_disponibles' => [],
    'mesa_ids_bloqueadas' => [4],
    'causas_bloqueo_por_mesa' => [4 => ['reservacion']],
    'mesas' => [],
    'tickets_por_mesa' => [],
    'intervalo' => ['inicio' => '2026-08-06 14:00:00', 'fin' => '2026-08-06 15:30:00'],
];
$capacityBlocked = CapacidadReservacionesService::calcular([$mesa4], $evaluationBlocked, []);
assertParity($capacityBlocked['mesa_ids_bloqueadas'] === [4], 'capacidad conserva mesa bloqueada');
assertParity($capacityBlocked['capacidad_fisica_comprometida'] === 4, 'capacidad descuenta mesa completa');
$mapBlocked = MesaEstadoService::normalizarMesas(
    [$mesa4],
    [$reservation],
    [],
    '2026-08-06',
    at('12:00:00'),
    '14:00:00',
    $evaluationBlocked
)[0];
assertParity($mapBlocked['estado_visual_mapa'] === 'libre', 'mapa comunica proximidad sin copiar capacidad');
assertParity($mapBlocked['modificadores_visual_mapa'] === ['reservacion_advertencia'], 'mapa usa borde de advertencia');
assertParity($mapBlocked['bloqueada_en_intervalo'] === true, 'mapa expone hecho de bloqueo');

$mesa7 = array_merge($mesa4, ['id' => 7, 'numero' => 7, 'nombre' => 'Mesa 7', 'capacidad' => 4]);
$mesa8 = array_merge($mesa4, ['id' => 8, 'numero' => 8, 'nombre' => 'Mesa 8', 'capacidad' => 4]);
$multiEvaluation = [
    'mesa_ids_disponibles' => [],
    'mesa_ids_bloqueadas' => [7, 8],
    'causas_bloqueo_por_mesa' => [7 => ['reservacion'], 8 => ['reservacion']],
    'mesas' => [],
    'tickets_por_mesa' => [],
    'intervalo' => ['inicio' => '2026-08-06 14:00:00', 'fin' => '2026-08-06 15:30:00'],
];
$multiCapacity = CapacidadReservacionesService::calcular([$mesa7, $mesa8], $multiEvaluation, []);
assertParity($multiCapacity['mesa_ids_bloqueadas'] === [7, 8], 'multimesa descuenta ambas mesas');
assertParity($multiCapacity['capacidad_fisica_comprometida'] === 8, 'multimesa descuenta toda la capacidad');
$multiReservation = array_merge($reservation, ['id' => 101, 'mesa_ids' => [7, 8]]);
$multiMap = MesaEstadoService::normalizarMesas(
    [$mesa7, $mesa8],
    [$multiReservation],
    [],
    '2026-08-06',
    at('12:00:00'),
    '14:00:00',
    $multiEvaluation
);
foreach ($multiMap as $mesaEstado) {
    assertParity($mesaEstado['estado_visual_mapa'] === 'libre', 'multimesa conserva visual de proximidad');
    assertParity($mesaEstado['bloqueada_en_intervalo'] === true, 'multimesa conserva bloqueo de intervalo');
}

$unassignedEvaluation = [
    'mesa_ids_disponibles' => [4],
    'mesa_ids_bloqueadas' => [],
    'causas_bloqueo_por_mesa' => [],
    'mesas' => [],
    'tickets_por_mesa' => [],
    'intervalo' => ['inicio' => '2026-08-06 14:00:00', 'fin' => '2026-08-06 15:30:00'],
];
$unassignedCapacity = CapacidadReservacionesService::calcular(
    [$mesa4],
    $unassignedEvaluation,
    [['id' => 102, 'estado' => 'confirmada', 'comensales' => 3]]
);
assertParity($unassignedCapacity['mesa_ids_bloqueadas'] === [], 'sin mesas asignadas no bloquea una mesa');
assertParity($unassignedCapacity['demanda_no_asignada'] === 3, 'sin mesas asignadas reduce demanda');
$unassignedMap = MesaEstadoService::normalizarMesas(
    [$mesa4],
    [],
    [],
    '2026-08-06',
    at('12:00:00'),
    '14:00:00',
    $unassignedEvaluation
)[0];
assertParity($unassignedMap['estado_visual_mapa'] === 'libre', 'sin mesas asignadas no inventa rojo');

$ticket = [
    'id' => 500,
    'estado' => 'abierto',
    'closed_at' => null,
    'hora_apertura' => '2026-08-06 13:00:00',
    'mesa_ids' => [4],
    'ticket_abierto' => true,
];
$ticketEvaluation = [
    'mesa_ids_disponibles' => [],
    'mesa_ids_bloqueadas' => [4],
    'causas_bloqueo_por_mesa' => [4 => ['ticket']],
    'mesas' => [],
    'tickets_por_mesa' => [4 => [
        'id' => 500,
        'ticket_id' => 500,
        'mesa_ids' => [4],
        'aplica_fecha' => true,
        'bloquea_en_consulta' => true,
        'ticket_abierto' => true,
    ]],
];
$ticketMap = MesaEstadoService::normalizarMesas(
    [$mesa4],
    [],
    [$ticket],
    '2026-08-06',
    at('14:00:00'),
    '14:00:00',
    $ticketEvaluation
)[0];
assertParity($ticketMap['estado_visual_mapa'] === 'ocupada', 'ticket bloqueante coincide en mapa');

$releasedEvaluation = $ticketEvaluation;
$releasedEvaluation['mesa_ids_disponibles'] = [4];
$releasedEvaluation['mesa_ids_bloqueadas'] = [];
$releasedEvaluation['causas_bloqueo_por_mesa'] = [];
$releasedEvaluation['tickets_por_mesa'][4]['bloquea_en_consulta'] = false;
$releasedMap = MesaEstadoService::normalizarMesas(
    [$mesa4],
    [],
    [$ticket],
    '2026-08-06',
    at('16:00:00'),
    '16:00:00',
    $releasedEvaluation
)[0];
assertParity($releasedMap['estado_visual_mapa'] === 'libre', 'ticket proyectado liberado deja de bloquear');

$alternativeDuration = 120;
$alternativeStart = at('15:00:00');
assertParity(
    !OcupacionMesasService::intervalosSeTraslapan($alternativeStart, at('13:00:00'), $alternativeDuration),
    'duracion alternativa respeta fin exacto anterior'
);
assertParity(
    OcupacionMesasService::intervalosSeTraslapan($alternativeStart, at('13:01:00'), $alternativeDuration),
    'duracion alternativa respeta traslape'
);
$alternativeEvaluation = [
    'mesa_ids_disponibles' => [],
    'mesa_ids_bloqueadas' => [4],
    'causas_bloqueo_por_mesa' => [4 => ['reservacion']],
    'mesas' => [],
    'tickets_por_mesa' => [],
];
$alternativeCapacity = CapacidadReservacionesService::calcular([$mesa4], $alternativeEvaluation, []);
assertParity($alternativeCapacity['mesa_ids_bloqueadas'] === [4], 'duracion alternativa llega a capacidad');
$alternativeMap = MesaEstadoService::normalizarMesas(
    [$mesa4],
    [$reservation],
    [],
    '2026-08-06',
    at('12:00:00'),
    '13:01:00',
    $alternativeEvaluation
)[0];
assertParity($alternativeMap['estado_visual_mapa'] === 'libre', 'duracion alternativa conserva visual independiente');
assertParity($alternativeMap['bloqueada_en_intervalo'] === true, 'duracion alternativa conserva bloqueo de capacidad');

fwrite(STDOUT, "Reservaciones: paridad capacidad-mapa OK\n");
