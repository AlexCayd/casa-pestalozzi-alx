<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\MesaEstadoService;
use Services\PosReservacionSerializer;
use Services\ReservacionConfig;
use Services\ReservacionVigenciaService;

$fixturePath = dirname(__DIR__) . '/fixtures/pos_reservacion_contrato.json';
$fixture = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$ahora = new DateTimeImmutable($fixture['ahora'], ReservacionConfig::timezone());
$mesas = array_map(
    static fn(array $mesa): array => PosReservacionSerializer::mesa($mesa),
    $fixture['mesas']
);

$porId = [];
foreach ($fixture['tickets'] as $ticket) {
    $porId[(int)$ticket['reservacion_id']] = $ticket;
}

$serializadas = [];
foreach ($fixture['reservaciones'] as $fila) {
    $serializadas[] = PosReservacionSerializer::reservacion(
        $fila,
        $porId[(int)$fila['id']] ?? null,
        $mesas,
        $ahora
    );
}

$esperadas = [
    101 => 'futura',
    102 => '30_60',
    103 => '0_30',
    104 => 'tolerancia',
    105 => 'tolerancia_vencida',
    106 => 'en_curso',
];

foreach ($serializadas as $reservacion) {
    $id = (int)$reservacion['reservacion_id'];
    foreach ([
        'schema_version', 'reservacion_id', 'estado', 'fecha', 'hora', 'nombre',
        'contacto', 'comensales', 'nota', 'mesa_ids', 'mesas', 'ticket_id',
        'ticket_abierto', 'ticket_mesa_ids', 'ventana_operativa',
        'minutos_para_reservacion', 'minutos_retraso', 'puede_iniciar_servicio',
        'puede_registrar_ausencia', 'bloquea_walk_ins', 'muestra_advertencia',
        'influye_disponibilidad', 'motivo',
    ] as $campo) {
        if (!array_key_exists($campo, $reservacion)) {
            throw new RuntimeException("Campo contractual ausente {$campo} para {$id}");
        }
    }
    if (($reservacion['schema_version'] ?? '') !== PosReservacionSerializer::SCHEMA_VERSION) {
        throw new RuntimeException("schema_version inválido para {$id}");
    }
    if (($reservacion['ventana_operativa'] ?? '') !== $esperadas[$id]) {
        throw new RuntimeException("ventana inválida para {$id}");
    }
    if ($reservacion['id'] !== $reservacion['reservacion_id']) {
        throw new RuntimeException("identidad divergente para {$id}");
    }
}

$inminente = $serializadas[2];
if (!$inminente['puede_iniciar_servicio'] || !$inminente['bloquea_walk_ins']) {
    throw new RuntimeException('La capacidad de inicio/bloqueo de la ventana 0_30 no coincide.');
}

$vencida = $serializadas[4];
if (!$vencida['puede_registrar_ausencia']
    || !$vencida['puede_marcar_no_show']
    || $vencida['puede_iniciar']
    || $vencida['accion_pendiente'] !== 'REGISTRAR_AUSENCIA'
    || $vencida['motivo_bloqueo'] !== 'TOLERANCIA_LLEGADA_VENCIDA'
    || $vencida['minutos_retraso'] !== 30) {
    throw new RuntimeException('La ausencia de tolerancia vencida no coincide.');
}

$limite = ReservacionVigenciaService::clasificar([
    'estado' => 'confirmada',
    'fecha' => '2026-08-02',
    'hora' => '11:45:00',
], $ahora);
if ($limite['tolerancia_vencida'] || !$limite['puede_iniciar_servicio'] || $limite['ventana_operativa']['estado'] !== 'tolerancia') {
    throw new RuntimeException('El límite exacto todavía debe permanecer dentro de tolerancia.');
}

$posterior = ReservacionVigenciaService::clasificar([
    'estado' => 'confirmada',
    'fecha' => '2026-08-02',
    'hora' => '11:44:00',
], $ahora);
if (!$posterior['tolerancia_vencida'] || $posterior['puede_iniciar_servicio'] || !$posterior['elegible_no_show']) {
    throw new RuntimeException('Un minuto después del límite debe bloquear inicio y permitir no-show.');
}

$enCurso = $serializadas[5];
if ($enCurso['ticket_mesa_ids'] !== [2] || !$enCurso['ticket_abierto']) {
    throw new RuntimeException('La ocupación física del ticket no coincide.');
}

$estadoMesas = MesaEstadoService::normalizarMesas(
    $mesas,
    $serializadas,
    array_map(
        static fn(array $ticket): array => PosReservacionSerializer::ticket($ticket),
        $fixture['tickets']
    ),
    $fixture['fecha'],
    $ahora,
    '12:00:00'
);
$mesaPorId = [];
foreach ($estadoMesas as $mesa) {
    $mesaPorId[(int)$mesa['id']] = $mesa;
}
if ($mesaPorId[2]['estado_base'] !== MesaEstadoService::OCUPADA) {
    throw new RuntimeException('ticket_mesa_ids no domina visualmente la mesa física.');
}
if (array_search('reservacion_proxima', $mesaPorId[3]['modificadores'], true) === false) {
    throw new RuntimeException('La mesa no expone la reservación próxima canónica.');
}
$pendingMesa = MesaEstadoService::normalizarMesas(
    [$mesas[2]],
    [$vencida],
    [],
    $fixture['fecha'],
    $ahora,
    '12:00:00'
)[0];
if ($pendingMesa['estado_base'] !== MesaEstadoService::DISPONIBLE
    || array_search('accion_pendiente', $pendingMesa['modificadores'], true) === false
    || !str_contains($pendingMesa['titulo'], 'acción pendiente: registrar ausencia')) {
    throw new RuntimeException('La mesa libre no conserva el fondo disponible con ausencia pendiente.');
}

echo "OK: contrato POS–reservaciones y estados de mesa\n";
