<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\MesaEstadoService;
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

$consultas = [
    '15:00:00' => ['ocupada', 'reservacion-proxima', 'Mesa 4, ocupada por reservación a las 15:00.'],
    '14:30:00' => ['reservacion-proxima', 'reservacion-proxima', 'Mesa 4, reservación próxima en 30 minutos.'],
    '14:15:00' => ['libre', 'libre', 'Mesa 4, disponible con reservación en 45 minutos.'],
    '15:05:00' => ['ocupada', 'reservacion-proxima', 'Mesa 4, ocupada por reservación a las 15:00.'],
    '15:15:00' => ['ocupada', 'libre', 'Mesa 4, ocupada por reservación a las 15:00.'],
    '16:29:00' => ['ocupada', 'libre', 'Mesa 4, ocupada por reservación a las 15:00.'],
    '16:30:00' => ['libre', 'libre', 'Mesa 4, disponible.'],
    '13:59:00' => ['libre', 'libre', 'Mesa 4, disponible.'],
];

foreach ($consultas as $hora => [$mapa, $pos, $aria]) {
    $mesaEstado = MesaEstadoService::normalizarMesas(
        [$mesa],
        [$reservacion],
        [],
        '2026-08-06',
        $ahora,
        $hora,
        ['mesas' => [], 'tickets_por_mesa' => []]
    )[0];
    assertMesaFacts($mesaEstado['estado_visual_mapa'] === $mapa, "mapa {$hora}");
    assertMesaFacts($mesaEstado['estado_visual_pos'] === $pos, "POS {$hora}");
    assertMesaFacts($mesaEstado['aria_label_mapa'] === $aria, "aria-label {$hora}");
    assertMesaFacts($mesaEstado['reservacion_id'] === 25, "reservacion_id {$hora}");
    assertMesaFacts($mesaEstado['reservacion_estado'] === 'confirmada', "reservacion_estado {$hora}");
}

$sinToleranciaVisual = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$reservacion],
    [],
    '2026-08-06',
    $ahora,
    '15:05:00',
    ['mesas' => [], 'tickets_por_mesa' => []]
)[0];
assertMesaFacts($sinToleranciaVisual['modificadores_visual_mapa'] === ['reservacion_bloqueante'], 'mapa no proyecta tolerancia como modificador');
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
    ['mesas' => [], 'tickets_por_mesa' => []]
)[0];
assertMesaFacts($ticketFuturo['ticket_abierto'] === false, 'ticket actual no aplica a fecha futura');
assertMesaFacts($ticketFuturo['ticket_bloquea_consulta'] === false, 'ticket futuro no bloquea');

fwrite(STDOUT, "Reservaciones: hechos de mesa OK\n");
