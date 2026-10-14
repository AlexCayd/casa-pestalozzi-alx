<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Pos\TicketTemporalService;
use Services\Pos\ReservacionPoliticaPosService;
use Services\Reservations\ReservacionConfig;
use Services\Pos\PosMesaProjectionPresenter;
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

/** Build the public table facts through MesaEstadoService. */
function mapMatrixState(string $hora, string $ahora, array $options = []): array
{
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
        'hora_apertura' => (string)($ticket['hora_apertura'] ?? $fecha . ' 09:10:00'),
        'ticket_abierto' => true,
    ]];
    $reservaciones = [];
    if (is_array($options['reservacion'] ?? null)) {
        $reservaciones[] = $options['reservacion'];
    }
    $rows = MesaEstadoService::normalizarMesas(
        [$options['mesa'] ?? $mesa],
        $reservaciones,
        $tickets,
        $fecha,
        new DateTimeImmutable($ahora, ReservacionConfig::timezone()),
        $hora,
        $evaluation,
        [
            'current_assignment_ids' => !empty($options['asignada_actualmente']) ? [14] : [],
            'evaluacion_ocupacion_asignacion' => $assignmentEvaluation,
            'reservacion_en_edicion_id' => (int)($options['reservacion_en_edicion_id'] ?? 0),
            'modo' => (string)($options['modo'] ?? 'proyeccion'),
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

/*
 * Cada escenario se mira con el mapa del POS PROYECTADO a la hora consultada
 * (el mesero toca un horario en el cajón). Tres estados: rojo si un ticket
 * sigue ahí a esa hora, azul de inicio − 60 a inicio + 90 y verde lo demás.
 * Lo que decide la ASIGNACIÓN es otro hecho —el solape de intervalos— y la
 * matriz comprueba que no se confundan.
 */
function assertScenario(
    string $id,
    array $state,
    string $visual,
    bool $blocked,
    bool $assignable,
    array $modifiers = []
): void {
    foreach ([
        'utilizable', 'ocupada_fisicamente', 'ticket_abierto_hecho', 'ticket_bloquea_consulta',
        'bloqueada_en_intervalo', 'causas_bloqueo', 'disponible_para_asignacion',
        'disponible_para_ticket', 'ventana_mapa', 'reservacion_cercana_mapa',
        'ausencia_pendiente', 'puede_marcar_no_show', 'estado_visual_pos',
        'modificadores_visual_pos', 'aria_label_pos', 'etiqueta_estado_pos',
    ] as $field) {
        assertMapMatrix(array_key_exists($field, $state), "{$id}: falta hecho {$field}");
    }
    assertMapMatrix($state['bloqueada_en_intervalo'] === $blocked, "{$id}: bloqueada_en_intervalo esperado {$blocked}");
    assertMapMatrix($state['disponible_para_asignacion'] === $assignable, "{$id}: asignabilidad esperada {$assignable}");
    assertMapMatrix($state['estado_visual_pos'] === $visual, "{$id}: estado visual esperado {$visual}, recibido {$state['estado_visual_pos']}");
    assertMapMatrix(
        $state['etiqueta_estado_pos'] === PosMesaProjectionPresenter::ETIQUETAS[$visual],
        "{$id}: rótulo esperado " . PosMesaProjectionPresenter::ETIQUETAS[$visual] . ", recibido {$state['etiqueta_estado_pos']}"
    );
    assertMapMatrix(trim((string)$state['aria_label_pos']) !== '', "{$id}: el pin tiene nombre accesible");
    foreach ($modifiers as $modifier) {
        assertMapMatrix(
            in_array($modifier, $state['modificadores'], true),
            "{$id}: falta modificador {$modifier}"
        );
    }
    if ($assignable) {
        assertMapMatrix(
            !$blocked || ($state['asignada_actualmente'] && $state['reservacion_id'] !== null),
            "{$id}: asignabilidad sólo conserva su propia reserva"
        );
    }
    if ($state['ticket_bloquea_consulta']) {
        assertMapMatrix(
            in_array('ticket', $state['causas_bloqueo'], true),
            "{$id}: ticket proyectado requiere causa de bloqueo"
        );
        assertMapMatrix($visual === 'ocupada', "{$id}: un ticket que sigue ahí pinta rojo");
    }
}

$nowMorning = $fecha . ' 09:00:00';
$atNoon = mapMatrixReservation('12:00:00');

// V01–V10: reservaciones y sus ventanas visuales.
assertScenario('V01', mapMatrixState('10:00:00', $nowMorning), 'libre', false, true);
assertScenario('V02', mapMatrixState('10:00:00', $nowMorning, ['reservacion' => $atNoon]), 'libre', false, true);
assertScenario('V03', mapMatrixState('11:00:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false, ['reservacion_advertencia']);
assertScenario('V04', mapMatrixState('11:15:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false, ['reservacion_advertencia']);
assertScenario('V05', mapMatrixState('11:30:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false);
assertScenario('V06', mapMatrixState('11:45:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false);
assertScenario('V07', mapMatrixState('12:00:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false);
assertScenario('V08', mapMatrixState('12:10:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false);
$absence = mapMatrixState('12:20:00', $fecha . ' 12:20:00', ['reservacion' => $atNoon, 'causas' => ['reservacion']]);
assertScenario('V09', $absence, 'reservacion-proxima', true, false, ['ausencia_pendiente']);
assertMapMatrix($absence['ausencia_pendiente'], 'V09: la ausencia posterior a tolerancia se informa como hecho');
$absenceNow = mapMatrixState('12:20:00', $fecha . ' 12:20:00', ['reservacion' => $atNoon, 'causas' => ['reservacion'], 'modo' => 'ahora']);
assertMapMatrix(
    $absenceNow['estado_visual_pos'] === 'reservacion-proxima' && in_array('sin_llegar', $absenceNow['modificadores_visual_pos'], true),
    'V09: en Ahora la ausencia sigue azul, marcada «sin llegar»'
);
$noShow = mapMatrixState('12:20:00', $fecha . ' 12:20:00', ['reservacion' => mapMatrixReservation('12:00:00', 'no_show')]);
assertScenario('V10', $noShow, 'libre', false, true);

// V11–V15: un ticket tiene su proyección y «Ahora» conserva la realidad física.
$ticketRecent = ['id' => 91, 'hora_apertura' => $fecha . ' 09:10:00'];
$ticketOld = ['id' => 92, 'hora_apertura' => $fecha . ' 09:10:00'];
assertScenario('V11', mapMatrixState('09:30:00', $fecha . ' 09:00:00', ['ticket' => $ticketRecent, 'causas' => ['ticket']]), 'ocupada', true, false);
$ticketAfterRelease = mapMatrixState('10:41:00', $fecha . ' 10:00:00', ['ticket' => $ticketOld]);
assertScenario('V12', $ticketAfterRelease, 'libre', false, true);
assertMapMatrix($ticketAfterRelease['ticket_abierto_hecho'] && $ticketAfterRelease['ocupada_fisicamente'], 'V12: ticket aún abierto físicamente');
$ticketAfterReleaseNow = mapMatrixState('10:41:00', $fecha . ' 10:00:00', ['ticket' => $ticketOld, 'modo' => 'ahora']);
assertMapMatrix($ticketAfterReleaseNow['estado_visual_pos'] === 'ocupada', 'V12: en Ahora sigue rojo mientras el ticket esté abierto');
$ticketAtRelease = mapMatrixState('10:40:00', $fecha . ' 10:00:00', ['ticket' => $ticketOld]);
assertScenario('V13', $ticketAtRelease, 'libre', false, true);
$ticketAtReleaseNow = mapMatrixState('10:40:00', $fecha . ' 10:00:00', ['ticket' => $ticketOld, 'modo' => 'ahora']);
assertMapMatrix($ticketAtReleaseNow['estado_visual_pos'] === 'ocupada', 'V13: «Ahora» no confunde proyección con cierre físico');
$ticketWarning = mapMatrixState('11:00:00', $fecha . ' 09:00:00', [
    'reservacion' => mapMatrixReservation('11:45:00'),
    'ticket' => ['id' => 93, 'hora_apertura' => $fecha . ' 10:00:00'],
    'causas' => ['ticket', 'reservacion'],
]);
assertScenario('V14', $ticketWarning, 'ocupada', true, false, ['reservacion_advertencia']);
$ticketAndReservation = mapMatrixState('12:00:00', $fecha . ' 09:00:00', [
    'reservacion' => $atNoon,
    'ticket' => ['id' => 94, 'hora_apertura' => $fecha . ' 11:30:00'],
    'causas' => ['ticket', 'reservacion'],
]);
assertScenario('V15', $ticketAndReservation, 'ocupada', true, false);

// V16–V22: restricciones, selección, edición propia y estado no verificado.
$hold = mapMatrixState('10:00:00', $nowMorning, ['causas' => ['hold']]);
// Un hold es una reserva a medio confirmar: se pinta azul («Apartada»), como
// cualquier reserva, no rojo como un ticket.
assertScenario('V16', $hold, 'reservacion-proxima', true, false);
assertMapMatrix(!$hold['disponible_para_ticket'], 'V16: el hold vigente no permite abrir ticket en POS');
assertMapMatrix(in_array('hold_vigente', $hold['modificadores_visual_pos'], true) && $hold['subtitulo_pos'] === 'Apartada', 'V16: la retención se nombra «Apartada»');
$inactive = $mesa;
$inactive['activo'] = 0;
assertScenario('V17', mapMatrixState('10:00:00', $nowMorning, ['mesa' => $inactive]), 'no-utilizable', false, false);
$invalidContract = PosMesaProjectionPresenter::presentar(['utilizable' => false]);
assertMapMatrix($invalidContract['estado_visual'] === 'no-utilizable', 'V18: el fallback seguro es neutro');
$selectedFree = mapMatrixState('10:00:00', $nowMorning, ['asignada_actualmente' => true]);
assertScenario('V19', $selectedFree, 'libre', false, true);
assertMapMatrix($selectedFree['asignada_actualmente'], 'V19: la selección no sustituye el estado base');
$selectedBlue = mapMatrixState('11:45:00', $nowMorning, [
    'reservacion' => $atNoon,
    'causas' => ['reservacion'],
    'asignada_actualmente' => true,
    'reservacion_en_edicion_id' => 20,
    'asignacion_disponible' => true,
]);
assertScenario('V20', $selectedBlue, 'reservacion-proxima', true, true);
$selectedRed = mapMatrixState('09:30:00', $fecha . ' 09:00:00', [
    'ticket' => $ticketRecent,
    'causas' => ['ticket'],
    'asignada_actualmente' => true,
]);
assertScenario('V21', $selectedRed, 'ocupada', true, false);
$ownReservation = mapMatrixState('12:00:00', $nowMorning, [
    'reservacion' => $atNoon,
    'causas' => ['reservacion'],
    'asignada_actualmente' => true,
    'reservacion_en_edicion_id' => 20,
    'asignacion_disponible' => true,
]);
assertScenario('V22', $ownReservation, 'reservacion-proxima', true, true);

// V23–V29: límites de asignabilidad contra el estado visual proyectado.
assertScenario('V23', mapMatrixState('10:30:00', $nowMorning, ['reservacion' => $atNoon]), 'libre', false, true);
$earlyOverlap = mapMatrixState('10:45:00', $nowMorning, [
    'reservacion' => $atNoon,
    'causas' => ['reservacion'],
]);
assertScenario('V24', $earlyOverlap, 'libre', true, false);
assertMapMatrix($earlyOverlap['bloqueada_en_intervalo'] && !$earlyOverlap['disponible_para_asignacion'], 'V24: la reserva cruza los siguientes 90 min');
assertScenario('V25', mapMatrixState('11:00:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false, ['reservacion_advertencia']);
assertScenario('V26', mapMatrixState('11:30:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false);
assertScenario('V27', mapMatrixState('12:00:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false);
assertScenario('V28', mapMatrixState('13:29:00', $nowMorning, ['reservacion' => $atNoon, 'causas' => ['reservacion']]), 'reservacion-proxima', true, false);
assertScenario('V29', mapMatrixState('13:30:00', $nowMorning, ['reservacion' => $atNoon]), 'libre', false, true);

// Invariante A: el bloqueo de asignación no decide por sí mismo el color.
assertMapMatrix($earlyOverlap['estado_visual_pos'] === 'libre', 'invariante A: V24 sigue visualmente verde');

// Invariantes B/C y los límites de segundo se evalúan con la proyección canónica.
$reservation = ['estado' => 'confirmada', 'fecha' => $fecha, 'hora' => '12:00:00'];
$now = new DateTimeImmutable($fecha . ' 09:00:00', ReservacionConfig::timezone());
$boundaryWindows = [
    '10:59:59' => 'futura',
    '11:00:00' => 'advertencia',
    '11:00:01' => 'advertencia',
    '11:29:59' => 'advertencia',
    '11:30:00' => 'bloqueo',
    '11:30:01' => 'bloqueo',
    '12:00:00' => 'inicio',
    '12:14:59' => 'activa',
    '12:15:00' => 'activa',
    '12:15:01' => 'activa',
    '13:29:59' => 'activa',
    '13:30:00' => 'irrelevante',
];
foreach ($boundaryWindows as $time => $expected) {
    $projection = ReservacionPoliticaPosService::proyeccionMapa(
        $reservation,
        new DateTimeImmutable($fecha . ' ' . $time, ReservacionConfig::timezone()),
        $now
    );
    assertMapMatrix($projection['ventana_mapa'] === $expected, "ventana {$time} = {$expected}");
    $visual = PosMesaProjectionPresenter::presentar([
        'utilizable' => true,
        'proyeccion' => true,
        'reservacion' => array_merge($reservation, $projection),
    ]);
    // Invariante B: de inicio − 60 a inicio + 90 la mesa es de la reserva
    // (azul); antes y después, verde. Sin ticket no hay rojo que valga.
    $azul = in_array($expected, ['advertencia', 'bloqueo', 'inicio', 'activa'], true);
    assertMapMatrix(
        $visual['estado_visual'] === ($azul ? 'reservacion-proxima' : 'libre'),
        "invariante B: {$time} ({$expected}) debe ser " . ($azul ? 'azul' : 'verde') . ", salió {$visual['estado_visual']}"
    );
}

// Los tickets usan el mismo fin semiabierto y duración canónica de la reserva.
$ticket = [
    'id' => 99,
    'estado' => 'abierto',
    'closed_at' => null,
    'hora_apertura' => $fecha . ' 09:10:00',
    'mesa_ids' => [14],
    'ticket_abierto' => true,
];
foreach ([
    '10:39:59' => true,
    '10:40:00' => false,
    '10:40:01' => false,
] as $time => $blocks) {
    $projection = TicketTemporalService::proyectar(
        $ticket,
        $fecha,
        $time,
        new DateTimeImmutable($fecha . ' 10:00:00', ReservacionConfig::timezone())
    );
    assertMapMatrix(
        $projection['bloquea_en_consulta'] === $blocks,
        "ticket hasta límite exclusivo en {$time}"
    );
}

fwrite(STDOUT, "Reservaciones: matriz V01–V29, proyección temporal y límites semiabiertos OK\n");
