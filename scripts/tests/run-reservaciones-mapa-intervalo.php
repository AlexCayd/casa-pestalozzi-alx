<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\MesaEstadoService;
use Services\OcupacionMesasService;
use Services\ReservacionConfig;

/** @param mixed $condition */
function assertMapaIntervalo($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

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
$reservacion = [
    'id' => 1400,
    'estado' => 'confirmada',
    'fecha' => '2026-08-08',
    'hora' => '14:00:00',
    'mesa_ids' => [14],
    'comensales' => 2,
    'ticket_abierto' => false,
];
$ahora = new DateTimeImmutable('2026-08-08 13:00:00', ReservacionConfig::timezone());
$inicio = new DateTimeImmutable('2026-08-08 14:00:00', ReservacionConfig::timezone());
$fin = $inicio->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes');

$consultas = [
    '13:00:00' => 'libre',
    '13:30:00' => 'reservacion-proxima',
    '14:00:00' => 'ocupada',
    '14:15:00' => 'ocupada',
    '14:30:00' => 'ocupada',
    '15:00:00' => 'ocupada',
    '15:30:00' => 'libre',
];

foreach ($consultas as $hora => $estadoEsperado) {
    $consulta = new DateTimeImmutable('2026-08-08 ' . $hora, ReservacionConfig::timezone());
    $bloqueada = OcupacionMesasService::intervalosSeTraslapan($inicio, $consulta);
    $estado = MesaEstadoService::normalizarMesas(
        [$mesa],
        [$reservacion],
        [],
        '2026-08-08',
        $ahora,
        $hora,
        [
            'mesa_ids_bloqueadas' => $bloqueada ? [14] : [],
            'causas_bloqueo_por_mesa' => $bloqueada ? [14 => ['reservacion']] : [],
            'mesas' => [],
            'tickets_por_mesa' => [],
        ]
    )[0];

    assertMapaIntervalo(
        $estado['estado_visual_mapa'] === $estadoEsperado,
        "mapa {$hora} conserva {$estadoEsperado}"
    );
    assertMapaIntervalo(
        $estado['reservacion_influye_en_consulta'] === ($consulta >= $inicio && $consulta < $fin),
        "hecho temporal {$hora}"
    );
}

fwrite(STDOUT, "Reservaciones: intervalo visual configurable OK\n");
