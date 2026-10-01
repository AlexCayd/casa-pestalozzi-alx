<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Tables\MesaEstadoService;
use Services\Reservations\ReservacionConfig;
use Services\Pos\TicketTemporalService;

/** @param mixed $condition */
function assertTicketProjection($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mesa = [
    'id' => 1,
    'numero' => 1,
    'nombre' => 'Mesa 1',
    'tipo' => 'mesa',
    'capacidad' => 4,
    'activo' => 1,
    'reservable' => 1,
    'pos_x' => 10,
    'pos_y' => 10,
];
$ticket = [
    'id' => 77,
    'estado' => 'abierto',
    'closed_at' => null,
    'hora_apertura' => '2026-08-18 09:30:00',
    'mesa_ids' => [1],
    'ticket_abierto' => true,
];
$ahora = new DateTimeImmutable('2026-08-18 10:00:00', ReservacionConfig::timezone());

foreach (['10:30' => true, '10:59' => true, '11:00' => false, '12:00' => false] as $hora => $bloqueaEsperado) {
    $proyeccion = TicketTemporalService::proyectar($ticket, '2026-08-18', $hora, $ahora);
    $evaluacion = [
        'mesas' => [],
        'tickets_por_mesa' => [],
        'mesa_ids_bloqueadas' => $bloqueaEsperado ? [1] : [],
        'causas_bloqueo_por_mesa' => $bloqueaEsperado ? [1 => ['ticket']] : [],
    ];
    $estado = MesaEstadoService::normalizarMesas(
        [$mesa],
        [],
        [$ticket],
        '2026-08-18',
        $ahora,
        $hora,
        $evaluacion,
        ['current_assignment_ids' => [1], 'reservacion_en_edicion_id' => 25]
    )[0];

    assertTicketProjection($proyeccion['liberacion_estimada'] === '2026-08-18 11:00:00', "liberación estimada {$hora}");
    assertTicketProjection($estado['ticket_abierto'] === true, "ocupación física {$hora}");
    assertTicketProjection($estado['ocupada_fisicamente'] === true, "hecho físico {$hora}");
    assertTicketProjection($estado['ticket_bloquea_consulta'] === $bloqueaEsperado, "bloqueo del ticket {$hora}");
    assertTicketProjection($estado['bloqueada_en_intervalo'] === $bloqueaEsperado, "bloqueo de intervalo {$hora}");
    assertTicketProjection(
        $estado['estado_visual_mapa'] === ($bloqueaEsperado ? 'ocupada' : 'libre'),
        "fondo del mapa de reservaciones {$hora}"
    );
    assertTicketProjection($estado['disponible_para_asignacion'] === !$bloqueaEsperado, "asignabilidad {$hora}");
    assertTicketProjection(
        $estado['causa_conflicto_asignacion'] === ($bloqueaEsperado ? 'ticket_abierto' : null),
        "causa de conflicto temporal {$hora}"
    );
}

$reservacionCercana = [
    'id' => 88,
    'estado' => 'confirmada',
    'fecha' => '2026-08-18',
    'hora' => '19:00:00',
    'mesa_ids' => [1],
    'comensales' => 2,
    'ticket_abierto' => false,
];
$ticketBloqueante = [
    'id' => 89,
    'estado' => 'abierto',
    'closed_at' => null,
    'hora_apertura' => '2026-08-18 17:10:00',
    'mesa_ids' => [1],
    'ticket_abierto' => true,
];
$estadoRojoConReservaCercana = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$reservacionCercana],
    [$ticketBloqueante],
    '2026-08-18',
    new DateTimeImmutable('2026-08-18 17:30:00', ReservacionConfig::timezone()),
    '17:30:00',
    [
        'mesa_ids_bloqueadas' => [1],
        'causas_bloqueo_por_mesa' => [1 => ['ticket']],
    ]
)[0];
assertTicketProjection($estadoRojoConReservaCercana['estado_visual_mapa'] === 'ocupada', 'ticket que intersecta conserva rojo');
assertTicketProjection($estadoRojoConReservaCercana['ocupada_fisicamente'], 'el ticket sigue siendo ocupación física');
assertTicketProjection($estadoRojoConReservaCercana['ticket_bloquea_consulta'], 'el ticket bloquea el intervalo');
assertTicketProjection(
    in_array('reservacion_advertencia', $estadoRojoConReservaCercana['modificadores_visual_mapa'], true),
    'la reserva consecutiva coexiste como señal secundaria azul'
);
assertTicketProjection(
    $estadoRojoConReservaCercana['aria_label_mapa'] === 'Mesa 1, ocupada por servicio activo.',
    'servicio activo sólo se anuncia por la ocupación física del ticket'
);

$ticketLiberado = [
    ...$ticketBloqueante,
    'id' => 90,
    'hora_apertura' => '2026-08-18 15:50:00',
];
$estadoTicketLiberado = MesaEstadoService::normalizarMesas(
    [$mesa],
    [],
    [$ticketLiberado],
    '2026-08-18',
    new DateTimeImmutable('2026-08-18 17:00:00', ReservacionConfig::timezone()),
    '17:30:00',
    [
        'mesa_ids_bloqueadas' => [],
        'causas_bloqueo_por_mesa' => [],
    ]
)[0];
assertTicketProjection($estadoTicketLiberado['ticket_abierto'], 'el ticket liberado sigue físicamente abierto');
assertTicketProjection($estadoTicketLiberado['ocupada_fisicamente'], 'la proyección no borra ocupación física actual');
assertTicketProjection(!$estadoTicketLiberado['ticket_bloquea_consulta'], 'la liberación estimada antecede al intervalo consultado');
assertTicketProjection($estadoTicketLiberado['estado_visual_mapa'] === 'libre', 'ticket proyectado liberado no fuerza rojo');

fwrite(STDOUT, "Reservaciones: proyección temporal de tickets OK\n");
