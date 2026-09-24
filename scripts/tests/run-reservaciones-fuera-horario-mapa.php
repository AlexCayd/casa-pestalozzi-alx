<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Pos\PosReservacionQueryService;
use Services\Pos\PosReservacionSerializer;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionMapaAdministrativaService;
use Services\Tables\MesaEstadoService;

function assertFueraHorarioMapa(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$now = new DateTimeImmutable('2026-08-19 13:00:00', ReservacionConfig::timezone());
$reservation = PosReservacionSerializer::reservacion([
    'id' => 9901,
    'estado' => 'confirmada',
    'fecha' => '2026-08-19',
    'hora' => '11:00:00',
    'comensales' => 4,
    'mesa_ids' => [14],
    'hold_expires_at' => null,
], null, [], $now, [
    'horario_efectivo' => [
        'abierto' => true,
        'hora_apertura' => '12:00:00',
        'hora_cierre' => '18:00:00',
    ],
]);
$reservation['mesa_ids'] = [14];
$reservation['aplica_hora_consultada'] = true;
assertFueraHorarioMapa($reservation['fuera_horario_operacion'] === true, 'el serializer identifica el horario fuera de operación');

$admin = ReservacionMapaAdministrativaService::proyectar([$reservation], [$reservation]);
assertFueraHorarioMapa(count($admin['reservaciones_admin']) === 1, 'el seguimiento conserva la fila administrativa');
assertFueraHorarioMapa($admin['reservaciones_admin'][0]['en_proyeccion_mapa'] === false, 'la fila no entra a la proyección del mapa');

$projectionReservations = PosReservacionQueryService::reservacionesParaProyeccionVisual([$reservation], 'admin');
assertFueraHorarioMapa($projectionReservations === [], 'la consulta administrativa excluye la reserva del cálculo visual');

$table = [
    'id' => 14,
    'numero' => 14,
    'nombre' => 'Mesa 14',
    'tipo' => 'mesa',
    'capacidad' => 4,
    'activo' => 1,
    'reservable' => 1,
    'pos_x' => 50,
    'pos_y' => 50,
];
$baseFacts = [
    'mesa_ids_bloqueadas' => [],
    'causas_bloqueo_por_mesa' => [],
    'mesas' => [],
    'tickets_por_mesa' => [],
];
$pin = MesaEstadoService::normalizarMesas(
    [$table], $projectionReservations, [], '2026-08-19', $now, '13:00:00', $baseFacts
)[0];
assertFueraHorarioMapa($pin['estado_visual_mapa'] === 'libre', 'la reservación excluida no colorea el pin');
assertFueraHorarioMapa($pin['modificadores_visual_mapa'] === [], 'la reservación excluida no agrega indicadores al pin');

$independentBlock = $baseFacts;
$independentBlock['mesa_ids_bloqueadas'] = [14];
$independentBlock['causas_bloqueo_por_mesa'] = [14 => ['hold']];
$independentBlock['mesas'] = [14 => ['fuente' => 'hold']];
$blockedPin = MesaEstadoService::normalizarMesas(
    [$table], $projectionReservations, [], '2026-08-19', $now, '13:00:00', $independentBlock
)[0];
assertFueraHorarioMapa($blockedPin['estado_visual_mapa'] === 'ocupada', 'un bloqueo independiente conserva rojo');
assertFueraHorarioMapa(
    str_contains($blockedPin['aria_label_mapa'], 'retención vigente'),
    'el pin conserva la causa del bloqueo independiente'
);

fwrite(STDOUT, "Reservaciones: ARQ-002 y fuera de horario del mapa OK\n");
