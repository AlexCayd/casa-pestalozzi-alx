<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\MesaEstadoService;
use Services\PosReservacionSerializer;
use Services\ReservacionConfig;

/** @param mixed $condition */
function assertReassignmentCycle($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mesas = [];
foreach ([4, 7, 8, 9, 10] as $id) {
    $mesas[] = [
        'id' => $id,
        'numero' => $id,
        'nombre' => 'Mesa ' . $id,
        'tipo' => 'mesa',
        'capacidad' => 4,
        'activo' => 1,
        'reservable' => 1,
        'pos_x' => 10,
        'pos_y' => 10,
    ];
}

$evaluacionLibre = [
    'mesas' => [],
    'tickets_por_mesa' => [],
    'mesa_ids_bloqueadas' => [],
    'causas_bloqueo_por_mesa' => [],
];

$render = static function (array $assignmentIds) use ($mesas, $evaluacionLibre): array {
    $estados = MesaEstadoService::normalizarMesas(
        $mesas,
        [],
        [],
        '2026-08-08',
        new DateTimeImmutable('2026-08-08 12:00:00', ReservacionConfig::timezone()),
        '12:00:00',
        $evaluacionLibre,
        [
            'reservacion_en_edicion_id' => 25,
            'current_assignment_ids' => $assignmentIds,
        ]
    );

    $byId = [];
    foreach ($estados as $estado) {
        $byId[(int)$estado['id']] = $estado;
    }
    return $byId;
};

$secuencias = [
    'A-B-A' => [[4], [7], [4]],
    'A-C-A' => [[4], [9], [4]],
    'A-B-A-C' => [[4], [7], [4], [9]],
    '[7,8]-[9,10]-[7,8]' => [[7, 8], [9, 10], [7, 8]],
    '[7,8]-[8,9]-[7,8]' => [[7, 8], [8, 9], [7, 8]],
];

foreach ($secuencias as $nombre => $ciclo) {
    $anterior = [];
    foreach ($ciclo as $paso => $assignmentIds) {
        $assignmentIds = array_values(array_unique(array_map('intval', $assignmentIds)));
        $estados = $render($assignmentIds);
        $actuales = [];
        foreach ($estados as $id => $estado) {
            if ($estado['asignada_actualmente'] === true) {
                $actuales[] = (int)$id;
            }
            assertReassignmentCycle(
                $estado['disponible_para_asignacion'] === true,
                "{$nombre} paso {$paso}: mesa {$id} disponible para asignación"
            );
        }
        sort($actuales);
        $esperados = $assignmentIds;
        sort($esperados);
        assertReassignmentCycle($actuales === $esperados, "{$nombre} paso {$paso}: currentAssignmentIds coincide");

        $snapshot = PosReservacionSerializer::reservacion(
            [
                'id' => 25,
                'estado' => 'confirmada',
                'fecha' => '2026-08-08',
                'hora' => '13:00:00',
                'nombre' => 'Reserva de ciclo',
                'comensales' => 2,
                'mesa_ids' => $assignmentIds,
                'updated_at' => '2026-08-08 11:00:00',
            ],
            null,
            $mesas,
            new DateTimeImmutable('2026-08-08 12:00:00', ReservacionConfig::timezone()),
            ['incluir_contexto_administrativo' => true]
        );
        $snapshotIds = array_map('intval', $snapshot['assignment_snapshot']['mesa_ids'] ?? []);
        sort($snapshotIds);
        assertReassignmentCycle($snapshotIds === $esperados, "{$nombre} paso {$paso}: assignment_snapshot coincide");

        $candidateIds = array_keys(array_filter($estados, static function (array $estado): bool {
            return $estado['reservable'] === true
                && $estado['disponible_para_asignacion'] === true
                && $estado['ticket_abierto'] !== true;
        }));
        $candidateIds = array_map('intval', $candidateIds);
        sort($candidateIds);
        assertReassignmentCycle(
            $candidateIds === [4, 7, 8, 9, 10],
            "{$nombre} paso {$paso}: candidateSelectionIds no hereda mesas históricas"
        );

        foreach ($assignmentIds as $id) {
            assertReassignmentCycle($estados[$id]['asignada_actualmente'] === true, "{$nombre} paso {$paso}: asignada_actualmente {$id}");
            assertReassignmentCycle($estados[$id]['disponible_para_asignacion'] === true, "{$nombre} paso {$paso}: seleccionable {$id}");
        }

        foreach ($anterior as $id) {
            if (!in_array($id, $assignmentIds, true)) {
                assertReassignmentCycle(
                    $estados[$id]['asignada_actualmente'] === false,
                    "{$nombre} paso {$paso}: mesa {$id} deja de estar asignada"
                );
                assertReassignmentCycle(
                    $estados[$id]['disponible_para_asignacion'] === true,
                    "{$nombre} paso {$paso}: mesa {$id} vuelve a estar disponible"
                );
            }
        }
        $anterior = $assignmentIds;
    }
}

fwrite(STDOUT, "Reservaciones: ciclos de reasignación sin fuga de estado OK\n");
