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
    'hora' => '15:00:00',
    'mesa_ids' => [4],
    'comensales' => 2,
    'ticket_abierto' => false,
];
$ahora = new DateTimeImmutable('2026-08-06 12:00:00', ReservacionConfig::timezone());
$inicioReservacion = new DateTimeImmutable('2026-08-06 15:00:00', ReservacionConfig::timezone());

$consultas = [
    '15:00:00' => ['ocupada', 'reservacion-proxima'],
    '14:30:00' => ['ocupada', 'reservacion-proxima'],
    '14:15:00' => ['ocupada', 'libre'],
    '15:05:00' => ['ocupada', 'reservacion-proxima'],
    '15:15:00' => ['ocupada', 'libre'],
    '16:29:00' => ['ocupada', 'libre'],
    '16:30:00' => ['libre', 'libre'],
    '13:59:00' => ['ocupada', 'libre'],
    '13:30:00' => ['libre', 'libre'],
];

$mapaEvaluacion = static function (string $hora) use ($inicioReservacion): array {
    $inicioConsulta = new DateTimeImmutable(
        '2026-08-06 ' . $hora,
        ReservacionConfig::timezone()
    );
    $bloqueada = OcupacionMesasService::intervalosSeTraslapan($inicioReservacion, $inicioConsulta);
    return [
        'mesas' => [],
        'tickets_por_mesa' => [],
        'mesa_ids_bloqueadas' => $bloqueada ? [4] : [],
        'causas_bloqueo_por_mesa' => $bloqueada ? [4 => ['reservacion']] : [],
    ];
};

foreach ($consultas as $hora => [$mapa, $pos]) {
    $mesaEstado = MesaEstadoService::normalizarMesas(
        [$mesa],
        [$reservacion],
        [],
        '2026-08-06',
        $ahora,
        $hora,
        $mapaEvaluacion($hora)
    )[0];
    assertMesaFacts($mesaEstado['estado_visual_mapa'] === $mapa, "mapa {$hora}");
    assertMesaFacts($mesaEstado['estado_visual_pos'] === $pos, "POS {$hora}");
    assertMesaFacts(
        $mapa === 'ocupada'
            ? str_contains($mesaEstado['aria_label_mapa'], 'no disponible')
            : str_contains($mesaEstado['aria_label_mapa'], 'disponible para el intervalo'),
        "aria-label {$hora}"
    );
    assertMesaFacts($mesaEstado['reservacion_id'] === 25, "reservacion_id {$hora}");
    assertMesaFacts($mesaEstado['reservacion_estado'] === 'confirmada', "reservacion_estado {$hora}");
    assertMesaFacts($mesaEstado['bloqueada_en_intervalo'] === ($mapa === 'ocupada'), "paridad bloqueo {$hora}");
    assertMesaFacts($mesaEstado['modificadores_visual_mapa'] === [], "mapa sin proximidad {$hora}");
}

$sinToleranciaVisual = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$reservacion],
    [],
    '2026-08-06',
    $ahora,
    '15:05:00',
    $mapaEvaluacion('15:05:00')
)[0];
assertMesaFacts($sinToleranciaVisual['modificadores_visual_mapa'] === [], 'mapa no proyecta tolerancia como modificador');
assertMesaFacts($sinToleranciaVisual['modificadores_visual_pos'] === ['reservacion_tolerancia'], 'POS conserva tolerancia');

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
