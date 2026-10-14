<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Tables\MesaEstadoService;
use Services\Tables\OcupacionMesasService;
use Services\Reservations\ReservacionConfig;

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
    'hora' => '13:00:00',
    'mesa_ids' => [4],
    'comensales' => 2,
    'ticket_abierto' => false,
];
$ahora = new DateTimeImmutable('2026-08-06 12:00:00', ReservacionConfig::timezone());
$inicioReservacion = new DateTimeImmutable('2026-08-06 13:00:00', ReservacionConfig::timezone());

// Columnas: [estado de la antigua pantalla de Operación —ya no se emite; queda
// como referencia de la semántica de intervalo—, estado POS, bloqueo, permiso
// de ticket, advertencia].
$consultas = [
    '11:30:00' => ['libre', 'libre', false, true, false],
    // Una hora antes el POS ya aparta la mesa en azul (antes era verde con borde):
    // sentar a alguien sigue permitido con confirmación, por eso el permiso no cambia.
    '12:00:00' => ['libre', 'reservacion-proxima', true, true, true],
    '12:30:00' => ['reservacion-proxima', 'reservacion-proxima', true, false, false],
    '12:59:00' => ['reservacion-proxima', 'reservacion-proxima', true, false, false],
    '13:00:00' => ['ocupada', 'reservacion-proxima', true, false, false],
    '13:30:00' => ['ocupada', 'reservacion-proxima', true, false, false],
    '14:00:00' => ['ocupada', 'reservacion-proxima', true, false, false],
    '14:30:00' => ['libre', 'reservacion-proxima', false, false, false],
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

foreach ($consultas as $hora => [$mapa, $pos, $bloqueadaEsperada, $ticketEsperado, $warningEsperado]) {
    $ahoraConsulta = new DateTimeImmutable('2026-08-06 ' . $hora, ReservacionConfig::timezone());
    $mesaEstado = MesaEstadoService::normalizarMesas(
        [$mesa],
        [$reservacion],
        [],
        '2026-08-06',
        $ahoraConsulta,
        $hora,
        $mapaEvaluacion($hora)
    )[0];
    assertMesaFacts($mesaEstado['estado_visual_pos'] === $pos, "POS {$hora}");
    assertMesaFacts($mesaEstado['bloqueada_en_intervalo'] === $bloqueadaEsperada, "paridad bloqueo {$hora}");
    assertMesaFacts($mesaEstado['disponible_para_ticket'] === $ticketEsperado, "permiso ticket {$hora}");
    assertMesaFacts($mesaEstado['requiere_advertencia_ticket'] === $warningEsperado, "warning {$hora}");
    assertMesaFacts($mesaEstado['reservacion_id'] === 25, "reservacion_id {$hora}");
    assertMesaFacts($mesaEstado['reservacion_estado'] === 'confirmada', "reservacion_estado {$hora}");
    if ($hora >= '13:30:00') {
        assertMesaFacts($mesaEstado['ausencia_pendiente'] === true, "ausencia pendiente {$hora}");
        assertMesaFacts($mesaEstado['reservacion_influye_en_disponibilidad'] === false, "ausencia libera influencia {$hora}");
        assertMesaFacts(
            $mesaEstado['disponible_para_asignacion'] === ($hora === '14:30:00'),
            "la asignación sólo vuelve al terminar el intervalo: {$hora}"
        );
        assertMesaFacts($mesaEstado['disponible_para_ticket'] === false, "ausencia conserva bloqueo POS {$hora}");
        assertMesaFacts(in_array('ausencia_pendiente', $mesaEstado['modificadores'], true), "indicador de ausencia {$hora}");
    }
}

$sinToleranciaVisual = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$reservacion],
    [],
    '2026-08-06',
    new DateTimeImmutable('2026-08-06 13:05:00', ReservacionConfig::timezone()),
    '13:05:00',
    $mapaEvaluacion('13:05:00')
)[0];
assertMesaFacts($sinToleranciaVisual['modificadores_visual_pos'] === ['ventana_tolerancia'], 'POS conserva azul durante la tolerancia');
assertMesaFacts($sinToleranciaVisual['estado_visual_pos'] === 'reservacion-proxima' && $sinToleranciaVisual['subtitulo_pos'] === 'Esperando', 'la tolerancia se rotula «Esperando»');

$otraReservacion = [
    ...$reservacion,
    'id' => 26,
    'hora' => '14:00:00',
];
$ausenciaConOtra = MesaEstadoService::normalizarMesas(
    [$mesa],
    [$reservacion, $otraReservacion],
    [],
    '2026-08-06',
    new DateTimeImmutable('2026-08-06 13:16:00', ReservacionConfig::timezone()),
    '13:30:00',
    [
        'mesas' => [],
        'tickets_por_mesa' => [],
        'mesa_ids_bloqueadas' => [4],
        'causas_bloqueo_por_mesa' => [4 => ['reservacion']],
    ]
)[0];
assertMesaFacts($ausenciaConOtra['reservacion_mapa_id'] === 25, 'el mapa elige la reserva activa sobre otra próxima');
assertMesaFacts($ausenciaConOtra['reservacion_mapa_hora'] === '13:00', 'el resumen del mapa conserva la hora de la reserva activa');
assertMesaFacts($ausenciaConOtra['reservacion_id'] === 26, 'la autoridad POS conserva la reserva próxima para su operación');
assertMesaFacts($ausenciaConOtra['disponible_para_asignacion'] === false, 'otra reservacion conserva el bloqueo');
assertMesaFacts(in_array('ausencia_pendiente', $ausenciaConOtra['modificadores'], true), 'otra reservacion compone gris');

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

$capacidadesPorElemento = [
    ['Mesa 8', 'mesa', 'Mesa 8', 1, 1, [true, true, true, false, false]],
    ['Barra 8', 'barra', 'Barra 8', 0, 1, [false, true, true, false, false]],
    ['Caja', 'especial', 'Caja', 0, 1, [false, true, false, true, false]],
    ['Llevar', 'especial', 'Llevar', 0, 1, [false, true, false, false, true]],
    ['Elemento 8', 'mesa', 'Elemento 8', 0, 1, [false, false, false, false, false]],
    ['Mesa 9', 'mesa', 'Mesa 9', 1, 0, [false, false, false, false, false]],
];
foreach ($capacidadesPorElemento as [$id, $tipo, $nombre, $reservable, $activo, $esperadas]) {
    $estado = MesaEstadoService::normalizarMesas(
        [[
            'id' => (int)preg_replace('/\D+/', '', $id) ?: 90,
            'tipo' => $tipo,
            'nombre' => $nombre,
            'activo' => $activo,
            'reservable' => $reservable,
            'capacidad' => $tipo === 'mesa' ? 4 : 0,
        ]],
        [],
        [],
        '2026-08-06',
        $ahora,
        '12:00:00',
        ['mesa_ids_bloqueadas' => []]
    )[0];
    $capacidad = $estado['capacidades_pos'];
    [$participaReservaciones, $operable, $ticketable, $abrirCaja, $crearLlevar] = $esperadas;
    assertMesaFacts($capacidad['reservable'] === $participaReservaciones, "capacidad Reservaciones {$nombre}");
    assertMesaFacts($capacidad['operable'] === $operable, "operabilidad POS {$nombre}");
    assertMesaFacts($capacidad['ticketable'] === $ticketable, "ticketable POS {$nombre}");
    assertMesaFacts($capacidad['abrir_caja'] === $abrirCaja, "flujo de Caja {$nombre}");
    assertMesaFacts($capacidad['crear_pedido_llevar'] === $crearLlevar, "flujo de Llevar {$nombre}");
    assertMesaFacts(
        $capacidad['independiente_consulta'] === ($abrirCaja || $crearLlevar || ($activo && !$operable)),
        "dependencia del snapshot {$nombre}"
    );
}
$estadoBarra = MesaEstadoService::normalizarMesas(
    [[
        'id' => 12,
        'tipo' => 'barra',
        'nombre' => 'Barra 12',
        'activo' => 1,
        'reservable' => 0,
    ]],
    [],
    [],
    '2026-08-06',
    $ahora,
    '12:00:00',
    ['mesa_ids_bloqueadas' => []]
)[0];
assertMesaFacts($estadoBarra['estado_visual_pos'] === 'libre', 'Barra operativa recibe su estado POS desde backend');
assertMesaFacts($estadoBarra['capacidades_pos']['multi_ticket'] === true, 'la barra admite varios tickets');
assertMesaFacts($estadoBarra['capacidades_pos']['tope_tickets'] === 4, 'sin base, el tope de la barra es el histórico de 4');

// Una barra se pinta por cuántos tickets la ocupan, nunca azul.
$ticketsBarra = static function (int $cuantos): array {
    $tickets = [];
    for ($i = 1; $i <= $cuantos; $i++) {
        $tickets[] = [
            'id' => 900 + $i,
            'estado' => 'abierto',
            'closed_at' => null,
            'hora_apertura' => '2026-08-06 11:30:00',
            'mesa_ids' => [12],
            'ticket_abierto' => true,
        ];
    }
    return $tickets;
};
$barra = ['id' => 12, 'tipo' => 'barra', 'nombre' => 'Barra Blanca', 'activo' => 1, 'reservable' => 0, 'capacidad' => 8];
$barraDos = MesaEstadoService::normalizarMesas([$barra], [], $ticketsBarra(2), '2026-08-06', $ahora, '', ['mesa_ids_bloqueadas' => []])[0];
assertMesaFacts($barraDos['estado_visual_pos'] === 'libre' && $barraDos['subtitulo_pos'] === '2/4', 'dos tickets dejan la barra libre con 2/4');
assertMesaFacts($barraDos['tickets_abiertos'] === 2, 'la barra cuenta sus tickets');
$barraLlena = MesaEstadoService::normalizarMesas([$barra], [], $ticketsBarra(4), '2026-08-06', $ahora, '', ['mesa_ids_bloqueadas' => []])[0];
assertMesaFacts($barraLlena['estado_visual_pos'] === 'ocupada' && $barraLlena['subtitulo_pos'] === '4/4', 'cuatro tickets llenan la barra');
assertMesaFacts($barraLlena['etiqueta_estado_pos'] === 'Llena', 'la barra llena se rotula «Llena»');

// Caja y Llevar son fichas de acción, sin estado de color.
foreach (['Caja', 'Llevar'] as $indice => $nombreFicha) {
    $ficha = MesaEstadoService::normalizarMesas(
        [['id' => 13 + $indice, 'tipo' => 'especial', 'nombre' => $nombreFicha, 'activo' => 1, 'reservable' => 0, 'capacidad' => 0]],
        [],
        [],
        '2026-08-06',
        $ahora,
        '',
        ['mesa_ids_bloqueadas' => []]
    )[0];
    assertMesaFacts($ficha['ficha'] === true && $ficha['etiqueta_estado_pos'] === '', "{$nombreFicha} es una ficha sin estado");
}

fwrite(STDOUT, "Reservaciones: hechos de mesa OK\n");
