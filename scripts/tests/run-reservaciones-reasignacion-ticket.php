<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\AsignacionMesasService;
use Services\MesaEstadoService;
use Services\PosReservacionSerializer;
use Services\ReservacionConfig;

/** @param mixed $condition */
function assertReassignmentContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ahora = new \DateTimeImmutable('2026-08-06 12:00:00', ReservacionConfig::timezone());
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
$mesa7 = array_replace($mesa4, [
    'id' => 7,
    'numero' => 7,
    'nombre' => 'Mesa 7',
    'pos_x' => 20,
]);
$ticketWalkIn = [
    'id' => 77,
    'estado' => 'abierto',
    'closed_at' => null,
    'hora_apertura' => '2026-08-06 12:30:00',
    'mesa_ids' => [4],
    'origen' => 'walk_in',
];

$evaluacion = [
    'mesas' => [],
    'tickets_por_mesa' => [],
    'mesa_ids_bloqueadas' => [4],
    'causas_bloqueo_por_mesa' => [4 => ['ticket']],
];
$estados = MesaEstadoService::normalizarMesas(
    [$mesa4, $mesa7],
    [],
    [$ticketWalkIn],
    '2026-08-06',
    $ahora,
    '13:00:00',
    $evaluacion,
    [
        'reservacion_en_edicion_id' => 25,
        'current_assignment_ids' => [4],
    ]
);
$porId = [];
foreach ($estados as $estado) {
    $porId[(int)$estado['id']] = $estado;
}

assertReassignmentContract($porId[4]['asignada_actualmente'] === true, 'la mesa actual conserva su snapshot');
assertReassignmentContract($porId[4]['causa_conflicto_asignacion'] === 'ticket_abierto', 'el conflicto ajeno se clasifica como ticket abierto');
assertReassignmentContract($porId[4]['ticket_abierto'] === true, 'la ocupacion fisica permanece visible');
assertReassignmentContract($porId[4]['disponible_para_asignacion'] === false, 'la mesa ocupada no es candidata');
assertReassignmentContract($porId[7]['asignada_actualmente'] === false, 'la mesa candidata no se confunde con la actual');
assertReassignmentContract($porId[7]['disponible_para_asignacion'] === true, 'la mesa libre sigue siendo candidata');

$snapshot = PosReservacionSerializer::reservacion(
    [
        'id' => 25,
        'estado' => 'confirmada',
        'fecha' => '2026-08-06',
        'hora' => '13:00:00',
        'nombre' => 'Reserva de prueba',
        'comensales' => 2,
        'mesa_ids' => [4],
        'updated_at' => '2026-08-06 11:00:00',
    ],
    null,
    [$mesa4],
    $ahora,
    ['incluir_contexto_administrativo' => true]
);
assertReassignmentContract($snapshot['assignment_snapshot']['mesa_ids'] === [4], 'el serializer expone el snapshot de mesas');
assertReassignmentContract($snapshot['assignment_snapshot']['version'] === $snapshot['version'], 'el snapshot conserva la version atomica');

$reflection = new \ReflectionMethod(AsignacionMesasService::class, 'conflictosTicketsSeleccionados');
$reflection->setAccessible(true);
$withoutConflict = $reflection->invoke(null, [$ticketWalkIn], [7]);
$withConflict = $reflection->invoke(null, [$ticketWalkIn], [4]);
assertReassignmentContract($withoutConflict === [], 'una mesa libre no hereda el ticket ajeno');
assertReassignmentContract(count($withConflict) === 1, 'la mesa ocupada detecta el ticket ajeno');
assertReassignmentContract($withConflict[0]['ticket_id'] === 77, 'el conflicto conserva el ticket que ocupa la mesa');
assertReassignmentContract($withConflict[0]['mesas_conflicto'] === [4], 'el conflicto identifica la mesa superpuesta');

$assignmentSource = (string)file_get_contents(dirname(__DIR__, 2) . '/services/AsignacionMesasService.php');
$controllerSource = (string)file_get_contents(dirname(__DIR__, 2) . '/controllers/ReservacionOperacionController.php');
assertReassignmentContract(
    strpos($assignmentSource, 'if ($modoMapaAdministrativo || empty($opciones[\'permitir_superposicion_ticket_abierto\']))') !== false,
    'el mapa administrativo no abre confirmacion para un ticket ajeno'
);
assertReassignmentContract(strpos($assignmentSource, "'codigo' => self::SUPERPOSICION_NO_AUTORIZADA") !== false, 'el backend devuelve el conflicto no autorizado');
assertReassignmentContract(strpos($controllerSource, 'permitir_superposicion_ticket_abierto') === false, 'la API de reasignacion no habilita superposiciones');

fwrite(STDOUT, "Reservaciones: reasignacion ante ticket ajeno OK\n");
