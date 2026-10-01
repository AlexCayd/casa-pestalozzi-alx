<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Reservations\ReservacionConfig;
use Services\Tables\MesaEstadoService;

function assertMapMatrix(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$fecha = '2037-02-15';
$mesa = [
    'id' => 14,
    'numero' => 14,
    'nombre' => 'Mesa 14',
    'tipo' => 'mesa',
    'capacidad' => 4,
    'activo' => 1,
    'reservable' => 1,
    'pos_x' => 10,
    'pos_y' => 10,
];

/** Build one final table contract through MesaEstadoService. */
function mapMatrixState(
    string $hora,
    string $ahora,
    array $options = []
): array {
    global $fecha, $mesa;
    $causas = array_values(array_map('strval', (array)($options['causas'] ?? [])));
    $bloqueada = (bool)($options['bloqueada'] ?? ($causas !== []));
    $disponible = (bool)($options['asignacion_disponible'] ?? !$bloqueada);
    $fuente = match (true) {
        in_array('ticket', $causas, true) => 'ticket_abierto',
        in_array('hold', $causas, true) => 'hold',
        in_array('reservacion', $causas, true) => 'reservacion',
        default => 'libre',
    };
    $ticket = $options['ticket'] ?? null;
    $evaluation = [
        'contexto' => 'proyectado',
        'mesa_ids_bloqueadas' => $bloqueada ? [14] : [],
        'causas_bloqueo_por_mesa' => $bloqueada ? [14 => $causas] : [],
        'mesas' => [14 => [
            'mesa_id' => 14,
            'fuente' => $fuente,
            'disponible' => !$bloqueada,
            'bloqueada_en_intervalo' => $bloqueada,
            'causas_bloqueo' => $causas,
            'ticket_id' => $ticket['id'] ?? null,
            'reservacion_id' => null,
        ]],
        'tickets_por_mesa' => $ticket === null ? [] : [14 => $ticket],
    ];
    $assignmentEvaluation = $evaluation;
    $assignmentEvaluation['mesas'][14]['disponible'] = $disponible;
    $assignmentEvaluation['mesas'][14]['fuente'] = $disponible ? 'libre' : $fuente;
    if ($disponible) {
        $assignmentEvaluation['mesa_ids_bloqueadas'] = [];
        $assignmentEvaluation['causas_bloqueo_por_mesa'] = [];
    }

    $tickets = $ticket === null ? [] : [[
        'id' => (int)($ticket['id'] ?? 1),
        'estado' => 'abierto',
        'closed_at' => null,
        'reservacion_id' => $ticket['reservacion_id'] ?? null,
        'mesa_ids' => [14],
        'hora_apertura' => (string)($ticket['hora_apertura'] ?? $fecha . ' 19:00:00'),
        'ticket_abierto' => true,
    ]];
    $now = new DateTimeImmutable($ahora, ReservacionConfig::timezone());
    $mesaActual = $options['mesa'] ?? $mesa;
    $rows = MesaEstadoService::normalizarMesas(
        [$mesaActual],
        (array)($options['reservaciones'] ?? []),
        $tickets,
        $fecha,
        $now,
        $hora,
        $evaluation,
        [
            'current_assignment_ids' => !empty($options['asignada_actualmente']) ? [14] : [],
            'evaluacion_ocupacion_asignacion' => $assignmentEvaluation,
            'reservacion_en_edicion_id' => (int)($options['reservacion_en_edicion_id'] ?? 0),
        ]
    );
    return $rows[0];
}

function mapMatrixReservation(string $hora, string $estado = 'confirmada', int $id = 20): array
{
    return [
        'id' => $id,
        'estado' => $estado,
        'fecha' => '2037-02-15',
        'hora' => $hora,
        'mesa_ids' => [14],
        'comensales' => 2,
        'ticket_abierto' => false,
    ];
}

function assertCommonMapFacts(array $mesaEstado, string $case): void
{
    foreach ([
        'utilizable',
        'ocupada_fisicamente',
        'ticket_abierto_hecho',
        'ticket_bloquea_consulta',
        'bloqueada_en_intervalo',
        'causas_bloqueo',
        'disponible_para_asignacion',
        'disponible_para_ticket',
        'reservacion_cercana_mapa',
        'ausencia_pendiente',
        'puede_marcar_no_show',
        'estado_visual_mapa',
        'modificadores_visual_mapa',
        'aria_label_mapa',
        'titulo_mapa',
        'label_visual_mapa',
    ] as $field) {
        assertMapMatrix(array_key_exists($field, $mesaEstado), "{$case}: falta hecho {$field}");
    }
    assertMapMatrix(
        $mesaEstado['aria_label_mapa'] === $mesaEstado['titulo_mapa'],
        "{$case}: title y ARIA deben compartir la etiqueta backend"
    );
    if ($mesaEstado['disponible_para_asignacion']) {
        assertMapMatrix(
            !$mesaEstado['bloqueada_en_intervalo']
                || ($mesaEstado['asignada_actualmente'] && $mesaEstado['reservacion_id'] !== null),
            "{$case}: asignabilidad contradice el bloqueo del intervalo sin ser la propia reserva"
        );
    }
    if ($mesaEstado['ticket_bloquea_consulta']) {
        assertMapMatrix(
            $mesaEstado['bloqueada_en_intervalo'] && in_array('ticket', $mesaEstado['causas_bloqueo'], true),
            "{$case}: un ticket bloqueante debe bloquear y nombrar su causa"
        );
    }
    if ($mesaEstado['estado_visual_mapa'] === 'reservacion-proxima') {
        assertMapMatrix(
            $mesaEstado['bloqueada_en_intervalo'] && in_array('reservacion', $mesaEstado['causas_bloqueo'], true),
            "{$case}: el estado azul requiere un bloqueo real por reservación"
        );
    }
    if (in_array('reservacion_advertencia', $mesaEstado['modificadores_visual_mapa'], true)) {
        assertMapMatrix(
            $mesaEstado['estado_visual_mapa'] !== 'reservacion-proxima',
            "{$case}: una advertencia sola no puede volver azul la mesa"
        );
    }
    if ($mesaEstado['label_visual_mapa'] === 'Ocupada por servicio activo') {
        assertMapMatrix($mesaEstado['ocupada_fisicamente'], "{$case}: servicio activo requiere ocupación física");
    }
}

$reservationAt19 = mapMatrixReservation('19:00:00');

// A — Libre absoluto.
$caseA = mapMatrixState('17:00:00', $fecha . ' 16:00:00');
assertCommonMapFacts($caseA, 'A');
assertMapMatrix(
    !$caseA['bloqueada_en_intervalo']
        && $caseA['causas_bloqueo'] === []
        && $caseA['disponible_para_asignacion']
        && $caseA['estado_visual_mapa'] === 'libre'
        && $caseA['modificadores_visual_mapa'] === []
        && $caseA['label_visual_mapa'] === 'Disponible',
    'A: la mesa libre no inventa ocupación ni modificadores'
);

// B — Reserva adyacente; la autoridad de solapamiento usa intervalos semiabiertos.
$caseB = mapMatrixState('17:30:00', $fecha . ' 16:00:00', [
    'reservaciones' => [$reservationAt19],
]);
assertCommonMapFacts($caseB, 'B');
assertMapMatrix(
    !$caseB['bloqueada_en_intervalo']
        && $caseB['disponible_para_asignacion']
        && $caseB['reservacion_cercana_mapa']
        && $caseB['estado_visual_mapa'] === 'libre'
        && in_array('reservacion_advertencia', $caseB['modificadores_visual_mapa'], true)
        && $caseB['label_visual_mapa'] === 'Disponible con reservación cercana',
    'B: la reserva adyacente añade advertencia sin bloquear'
);

// C — Un minuto de solapamiento.
$caseC = mapMatrixState('17:31:00', $fecha . ' 16:00:00', [
    'reservaciones' => [$reservationAt19],
    'causas' => ['reservacion'],
]);
assertCommonMapFacts($caseC, 'C');
assertMapMatrix(
    $caseC['bloqueada_en_intervalo']
        && in_array('reservacion', $caseC['causas_bloqueo'], true)
        && !$caseC['disponible_para_asignacion']
        && $caseC['estado_visual_mapa'] === 'reservacion-proxima'
        && !in_array('reservacion_advertencia', $caseC['modificadores_visual_mapa'], true)
        && $caseC['label_visual_mapa'] === 'No disponible por reservación',
    'C: un minuto de solapamiento bloquea en azul sin borde secundario'
);

// D — El reloj sólo cambia acciones temporales; no la ocupación del intervalo.
$caseDStates = [];
foreach (['18:00:00', '19:54:00', '20:15:00'] as $clock) {
    $caseDStates[] = mapMatrixState('19:30:00', $fecha . ' ' . $clock, [
        'reservaciones' => [mapMatrixReservation('20:30:00')],
        'causas' => ['reservacion'],
    ]);
}
foreach ($caseDStates as $index => $state) {
    assertCommonMapFacts($state, 'D' . $index);
    assertMapMatrix(
        $state['bloqueada_en_intervalo']
            && $state['causas_bloqueo'] === ['reservacion']
            && $state['estado_visual_mapa'] === 'reservacion-proxima'
            && $state['label_visual_mapa'] === 'No disponible por reservación',
        "D{$index}: la reserva que cruza el intervalo sigue siendo causa del azul"
    );
}
foreach (['bloqueada_en_intervalo', 'causas_bloqueo', 'disponible_para_asignacion', 'estado_visual_mapa', 'modificadores_visual_mapa', 'label_visual_mapa'] as $field) {
    assertMapMatrix(
        $caseDStates[0][$field] === $caseDStates[1][$field]
            && $caseDStates[1][$field] === $caseDStates[2][$field],
        "D: cambiar ahora no altera {$field}"
    );
}

// E — La reserva inicia exactamente al comienzo del intervalo.
$caseE = mapMatrixState('20:30:00', $fecha . ' 18:00:00', [
    'reservaciones' => [mapMatrixReservation('20:30:00')],
    'causas' => ['reservacion'],
]);
assertCommonMapFacts($caseE, 'E');
assertMapMatrix(
    $caseE['bloqueada_en_intervalo'] && $caseE['estado_visual_mapa'] === 'reservacion-proxima'
        && $caseE['label_visual_mapa'] === 'No disponible por reservación',
    'E: una reserva que inicia al comienzo bloquea el intervalo'
);

// F — La reserva termina exactamente cuando inicia la consulta.
$caseF = mapMatrixState('19:30:00', $fecha . ' 17:00:00', [
    'reservaciones' => [mapMatrixReservation('18:00:00')],
]);
assertCommonMapFacts($caseF, 'F');
assertMapMatrix(
    !$caseF['bloqueada_en_intervalo'] && $caseF['estado_visual_mapa'] === 'libre',
    'F: el fin exclusivo no deja azul una reserva ya terminada'
);

$ticketAt19 = [
    'id' => 91,
    'reservacion_id' => null,
    'mesa_ids' => [14],
    'estado' => 'abierto',
    'closed_at' => null,
    'hora_apertura' => $fecha . ' 19:00:00',
    'aplica_fecha' => true,
    'bloquea_en_consulta' => true,
    'bloquea_disponibilidad' => true,
    'ocupada_fisicamente' => true,
    'estado_proyeccion' => 'ocupada',
];

// G — Ticket bloqueante.
$caseG = mapMatrixState('19:30:00', $fecha . ' 18:00:00', [
    'ticket' => $ticketAt19,
    'causas' => ['ticket'],
]);
assertCommonMapFacts($caseG, 'G');
assertMapMatrix(
    $caseG['ticket_bloquea_consulta'] && $caseG['bloqueada_en_intervalo']
        && in_array('ticket', $caseG['causas_bloqueo'], true)
        && $caseG['estado_visual_mapa'] === 'ocupada'
        && $caseG['label_visual_mapa'] === 'Ocupada por servicio activo',
    'G: un ticket bloqueante conserva rojo y su label requiere ocupación física'
);

// H — El ticket continúa abierto físicamente, pero se libera antes del intervalo.
$releasedTicket = $ticketAt19;
$releasedTicket['hora_apertura'] = $fecha . ' 17:20:00';
$releasedTicket['bloquea_en_consulta'] = false;
$releasedTicket['bloquea_disponibilidad'] = false;
$releasedTicket['ocupada_fisicamente'] = true;
$releasedTicket['estado_proyeccion'] = 'liberado_proyectado';
$releasedTicket['tipo'] = 'ticket_proyectado';
$caseH = mapMatrixState('19:30:00', $fecha . ' 19:00:00', [
    'ticket' => $releasedTicket,
    'bloqueada' => false,
]);
assertCommonMapFacts($caseH, 'H');
assertMapMatrix(
    $caseH['ocupada_fisicamente'] && $caseH['ticket_abierto_hecho']
        && !$caseH['ticket_bloquea_consulta'] && !$caseH['bloqueada_en_intervalo']
        && $caseH['estado_visual_mapa'] === 'libre'
        && $caseH['disponible_para_asignacion'],
    'H: ocupación física actual no fuerza rojo después de liberar el intervalo'
);

// I — Ticket y reserva solapados: el ticket conserva prioridad roja.
$caseI = mapMatrixState('19:30:00', $fecha . ' 18:00:00', [
    'reservaciones' => [mapMatrixReservation('20:00:00')],
    'ticket' => $ticketAt19,
    'causas' => ['ticket', 'reservacion'],
]);
assertCommonMapFacts($caseI, 'I');
assertMapMatrix(
    $caseI['estado_visual_mapa'] === 'ocupada'
        && in_array('ticket', $caseI['causas_bloqueo'], true)
        && in_array('reservacion', $caseI['causas_bloqueo'], true)
        && $caseI['label_visual_mapa'] === 'Ocupada por servicio activo',
    'I: ticket bloqueante domina el label de una reserva solapada'
);

// J — Hold vigente.
$caseJ = mapMatrixState('19:30:00', $fecha . ' 18:00:00', [
    'causas' => ['hold'],
]);
assertCommonMapFacts($caseJ, 'J');
assertMapMatrix(
    $caseJ['bloqueada_en_intervalo'] && $caseJ['causas_bloqueo'] === ['hold']
        && $caseJ['estado_visual_mapa'] === 'ocupada'
        && $caseJ['label_visual_mapa'] === 'No disponible por retención',
    'J: retención vigente bloquea en rojo con label propio'
);

// K — Mesa no utilizable.
$inactiveMesa = $mesa;
$inactiveMesa['activo'] = 0;
$caseK = mapMatrixState('19:30:00', $fecha . ' 18:00:00', [
    'mesa' => $inactiveMesa,
]);
assertCommonMapFacts($caseK, 'K');
assertMapMatrix(
    !$caseK['utilizable'] && !$caseK['disponible_para_asignacion']
        && $caseK['estado_visual_mapa'] === 'no-utilizable',
    'K: mesa inactiva es segura y no asignable'
);

// P — Ausencia pendiente conserva su modificador y acción sobre el bloqueo.
$absenceReservation = mapMatrixReservation('18:00:00');
$caseP = mapMatrixState('18:00:00', $fecha . ' 18:20:00', [
    'reservaciones' => [$absenceReservation],
    'causas' => ['reservacion'],
]);
assertCommonMapFacts($caseP, 'P');
assertMapMatrix(
    $caseP['ausencia_pendiente'] && $caseP['puede_marcar_no_show']
        && in_array('ausencia_pendiente', $caseP['modificadores_visual_mapa'], true)
        && $caseP['bloqueada_en_intervalo']
        && !$caseP['disponible_para_asignacion']
        && $caseP['estado_visual_mapa'] === 'reservacion-proxima',
    'P: ausencia pendiente es una acción y conserva el bloqueo del intervalo'
);

// Q — Tras el no-show persistido, no queda reserva ni modificador de ausencia.
$caseQ = mapMatrixState('18:00:00', $fecha . ' 18:20:00');
assertCommonMapFacts($caseQ, 'Q');
assertMapMatrix(
    !$caseQ['bloqueada_en_intervalo'] && !$caseQ['ausencia_pendiente']
        && !$caseQ['puede_marcar_no_show']
        && $caseQ['estado_visual_mapa'] === 'libre'
        && $caseQ['modificadores_visual_mapa'] === [],
    'Q: después de no-show confirmado, el intervalo vuelve a libre'
);

fwrite(STDOUT, "Reservaciones: matriz contractual de estados del mapa A–Q OK\n");
