<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\MesaEstadoService;
use Services\OcupacionMesasService;
use Services\ReservacionConfig;

/** @param mixed $condition */
function assertMesaFacts($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mesa = [
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
$reservacion = [
    'id' => 25,
    'estado' => 'confirmada',
    'fecha' => '2026-08-06',
    'hora' => '13:00:00',
    'mesa_ids' => [4],
    'comensales' => 2,
    'ticket_abierto' => false,
];
$ahora = new DateTimeImmutable('2026-08-06 12:00:00', ReservacionConfig::timezone());
$inicioReservacion = new DateTimeImmutable('2026-08-06 13:00:00', ReservacionConfig::timezone());

$consultas = [
    '11:30:00' => ['libre', 'libre', false, true, false],
    '12:00:00' => ['libre', 'libre', true, true, true],
    '12:30:00' => ['reservacion-proxima', 'reservacion-proxima', true, false, false],
    '12:59:00' => ['reservacion-proxima', 'reservacion-proxima', true, false, false],
    '13:00:00' => ['ocupada', 'reservacion-proxima', true, false, false],
    '13:30:00' => ['libre', 'libre', false, false, false],
    '14:00:00' => ['libre', 'libre', false, false, false],
    '14:30:00' => ['libre', 'libre', false, false, false],
];

$mapaEvaluacion = static function (string $hora) use ($inicioReservacion): array {
    $inicioConsulta = new DateTimeImmutable(
        '2026-08-06 ' . $hora,
        ReservacionConfig::timezone()
    );
    $bloqueada = $inicioConsulta < new DateTimeImmutable(
        '2026-08-06 13:15:00',
        ReservacionConfig::timezone()
    ) && OcupacionMesasService::intervalosSeTraslapan($inicioReservacion, $inicioConsulta);
    return [
        'mesas' => [],
        'tickets_por_mesa' => [],
        'mesa_ids_bloqueadas' => $bloqueada ? [4] : [],
        'causas_bloqueo_por_mesa' => $bloqueada ? [4 => ['reservacion']] : [],
    ];
};

foreach ($consultas as $hora => [$mapa, $pos, $bloqueadaEsperada, $ticketEsperado, $warningEsperado]) {
    $ahoraConsulta = new DateTimeImmutable('2026-08-06 ' . $hora, ReservacionConfig::timezone());
    $mesaEstado = MesaEstadoService::normalizarMesas(
        [$mesa],
        [$reservacion],
        [],
        '2026-08-06',
        $ahoraConsulta,
        $hora,
        $mapaEvaluacion($hora)
    )[0];
    assertMesaFacts($mesaEstado['estado_visual_mapa'] === $mapa, "mapa {$hora}");
    assertMesaFacts($mesaEstado['estado_visual_pos'] === $pos, "POS {$hora}");
    assertMesaFacts($mesaEstado['bloqueada_en_intervalo'] === $bloqueadaEsperada, "paridad bloqueo {$hora}");
    assertMesaFacts($mesaEstado['disponible_para_ticket'] === $ticketEsperado, "permiso ticket {$hora}");
    assertMesaFacts($mesaEstado['requiere_advertencia_ticket'] === $warningEsperado, "warning {$hora}");
    assertMesaFacts($mesaEstado['reservacion_id'] === 25, "reservacion_id {$hora}");
    assertMesaFacts($mesaEstado['reservacion_estado'] === 'confirmada', "reservacion_estado {$hora}");
    if ($hora >= '13:30:00') {
        assertMesaFacts($mesaEstado['ausencia_pendiente'] === true, "ausencia pendiente {$hora}");
        assertMesaFacts($mesaEstado['reservacion_influye_en_disponibilidad'] === false, "ausencia libera influencia {$hora}");
        assertMesaFacts($mesaEstado['disponible_para_asignacion'] === true, "ausencia libera asignacion {$hora}");
        assertMesaFacts($mesaEstado['disponible_para_ticket'] === false, "ausencia conserva bloqueo POS {$hora}");
        assertMesaFacts(in_array('ausencia_pendiente', $mesaEstado['modificadores_visual_mapa'], true), "gris {$hora}");
    }
}

$sinToleranciaVisual = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$reservacion],
    [],
    '2026-08-06',
    new DateTimeImmutable('2026-08-06 13:05:00', ReservacionConfig::timezone()),
    '13:05:00',
    $mapaEvaluacion('13:05:00')
)[0];
assertMesaFacts($sinToleranciaVisual['modificadores_visual_pos'] === ['reservacion_tolerancia'], 'POS conserva azul durante la tolerancia');

$otraReservacion = [
    ...$reservacion,
    'id' => 26,
    'hora' => '14:00:00',
];
$ausenciaConOtra = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$reservacion, $otraReservacion],
    [],
    '2026-08-06',
    new DateTimeImmutable('2026-08-06 13:16:00', ReservacionConfig::timezone()),
    '13:30:00',
    [
        'mesas' => [],
        'tickets_por_mesa' => [],
        'mesa_ids_bloqueadas' => [4],
        'causas_bloqueo_por_mesa' => [4 => ['reservacion']],
    ]
)[0];
assertMesaFacts($ausenciaConOtra['estado_visual_mapa'] === 'reservacion-proxima', 'otra reservacion conserva azul');
assertMesaFacts($ausenciaConOtra['disponible_para_asignacion'] === false, 'otra reservacion conserva el bloqueo');
assertMesaFacts(in_array('ausencia_pendiente', $ausenciaConOtra['modificadores_visual_mapa'], true), 'otra reservacion compone gris');

$ticket = [
    'id' => 77,
    'estado' => 'abierto',
    'closed_at' => null,
    'hora_apertura' => '2026-08-06 13:00:00',
    'mesa_ids' => [4],
    'ticket_abierto' => true,
];
$ticketFuturo = MesaEstadoService::normalizarMesas(
    [$mesa],
    [],
    [$ticket],
    '2026-08-07',
    $ahora,
    '14:00:00',
    [
        'mesas' => [],
        'tickets_por_mesa' => [],
        'mesa_ids_bloqueadas' => [],
        'causas_bloqueo_por_mesa' => [],
    ]
)[0];
assertMesaFacts($ticketFuturo['ticket_abierto'] === false, 'ticket actual no aplica a fecha futura');
assertMesaFacts($ticketFuturo['ticket_bloquea_consulta'] === false, 'ticket futuro no bloquea');

fwrite(STDOUT, "Reservaciones: hechos de mesa OK\n");
