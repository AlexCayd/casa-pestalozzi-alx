<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Tables\MesaEstadoService;
use Services\Tables\OcupacionMesasService;
use Services\Reservations\ReservacionConfig;

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
    'hora' => '19:00:00',
    'mesa_ids' => [14],
    'comensales' => 2,
    'ticket_abierto' => false,
];
$ahora = new DateTimeImmutable('2026-08-08 17:00:00', ReservacionConfig::timezone());
$inicio = new DateTimeImmutable('2026-08-08 19:00:00', ReservacionConfig::timezone());
$inicioConsecutivo = $inicio->modify('+' . ReservacionConfig::DURACION_RESERVACION_MINUTOS . ' minutes');
$inicioUnMinutoAntes = $inicioConsecutivo->modify('-1 minute');

assertMapaIntervalo(
    !OcupacionMesasService::intervalosSeTraslapan($inicio, $inicioConsecutivo),
    'los intervalos consecutivos exactos no se solapan'
);
assertMapaIntervalo(
    OcupacionMesasService::intervalosSeTraslapan($inicio, $inicioUnMinutoAntes),
    'un inicio un minuto antes del fin sí se solapa'
);
$asignacionCompatibilidad = [[
    'mesa_id' => 14,
    'reservacion_id' => 1400,
    'hora' => '19:00:00',
]];
assertMapaIntervalo(
    OcupacionMesasService::ocupacionReservacionesEnVentana($asignacionCompatibilidad, '20:30:00') === [],
    'la lectura de compatibilidad usa el mismo fin exclusivo'
);
assertMapaIntervalo(
    isset(OcupacionMesasService::ocupacionReservacionesEnVentana($asignacionCompatibilidad, '20:29:00')[14]),
    'la lectura de compatibilidad reconoce el último minuto de traslape'
);

/*
 * El mapa del POS proyectado a la hora consultada (el mesero toca un horario
 * en el cajón). La regla: azul desde 60 min antes del inicio hasta el fin del
 * intervalo [inicio, inicio + 90), semiabierto. Lo que bloquea la ASIGNACIÓN es
 * otra cosa —el solape de intervalos— y aquí se comprueba que no se confundan:
 * a las 17:31 el intervalo ya choca, pero la mesa sigue verde en el mapa.
 */
$consultas = [
    '17:30:00' => 'libre',
    '17:31:00' => 'libre',
    '17:59:00' => 'libre',
    '18:00:00' => 'reservacion-proxima',
    '18:30:00' => 'reservacion-proxima',
    '18:59:00' => 'reservacion-proxima',
    '19:00:00' => 'reservacion-proxima',
    '20:29:00' => 'reservacion-proxima',
    '20:30:00' => 'libre',
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
        ],
        ['modo' => 'proyeccion']
    )[0];

    assertMapaIntervalo(
        $estado['estado_visual_pos'] === $estadoEsperado,
        "POS proyectado {$hora}: se esperaba {$estadoEsperado}, salió {$estado['estado_visual_pos']}"
    );
    assertMapaIntervalo(
        $estado['reservacion_influye_en_consulta'] === ($consulta >= $inicio && $consulta < $inicioConsecutivo),
        "hecho de inicio consultado {$hora}"
    );
    if ($estadoEsperado === 'reservacion-proxima') {
        // En proyección el subrótulo es la hora de la reserva, no un conteo.
        assertMapaIntervalo($estado['subtitulo_pos'] === '19:00', "subrótulo con la hora de la reserva {$hora}");
    }

    if ($hora === '17:30:00') {
        assertMapaIntervalo(!$estado['bloqueada_en_intervalo'], '17:30–19:00 y 19:00–20:30 no se solapan');
        assertMapaIntervalo($estado['disponible_para_asignacion'], 'el intervalo sin bloqueo es asignable');
        assertMapaIntervalo(!$estado['ocupada_fisicamente'], 'una reserva futura no crea ocupación física');
        assertMapaIntervalo(!$estado['ticket_bloquea_consulta'], 'el caso principal no tiene ticket');
    }

    if ($hora === '17:31:00') {
        assertMapaIntervalo(
            in_array('reservacion', $estado['causas_bloqueo'], true)
                && !$estado['disponible_para_asignacion'],
            '17:31 se solapa un minuto: la asignación se bloquea aunque el mapa siga verde'
        );
    }

    if ($hora === '20:30:00') {
        assertMapaIntervalo(
            !$estado['bloqueada_en_intervalo'] && $estado['ventana_mapa'] === 'irrelevante',
            'fin exacto: verde por el límite final exclusivo'
        );
    }
}

fwrite(STDOUT, "Reservaciones: mapa POS proyectado por intervalo semiabierto OK\n");
