<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\MesaEstadoService;
use Services\ReservacionConfig;
use Services\TicketTemporalService;

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
    assertTicketProjection($estado['disponible_para_asignacion'] === !$bloqueaEsperado, "asignabilidad {$hora}");
    assertTicketProjection(
        $estado['causa_conflicto_asignacion'] === ($bloqueaEsperado ? 'ticket_abierto' : null),
        "causa de conflicto temporal {$hora}"
    );
}

fwrite(STDOUT, "Reservaciones: proyección temporal de tickets OK\n");
