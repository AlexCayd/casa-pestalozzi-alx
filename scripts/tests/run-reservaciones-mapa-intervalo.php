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

$consultas = [
    '17:30:00' => 'libre',
    '17:31:00' => 'libre',
    '18:00:00' => 'libre',
    '18:15:00' => 'libre',
    '18:30:00' => 'reservacion-proxima',
    '18:59:00' => 'reservacion-proxima',
    '19:00:00' => 'ocupada',
    '20:29:00' => 'ocupada',
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
        ]
    )[0];

    assertMapaIntervalo(
        $estado['estado_visual_mapa'] === $estadoEsperado,
        "mapa {$hora} conserva {$estadoEsperado}"
    );
    assertMapaIntervalo(
        $estado['reservacion_influye_en_consulta'] === ($consulta >= $inicio && $consulta < $inicioConsecutivo),
        "hecho de inicio consultado {$hora}"
    );

    if ($hora === '17:30:00') {
        assertMapaIntervalo(!$estado['bloqueada_en_intervalo'], '17:30–19:00 y 19:00–20:30 no se solapan');
        assertMapaIntervalo($estado['disponible_para_asignacion'], 'el intervalo sin bloqueo es asignable');
        assertMapaIntervalo(!$estado['ocupada_fisicamente'], 'una reserva futura no crea ocupación física');
        assertMapaIntervalo(!$estado['ticket_bloquea_consulta'], 'el caso principal no tiene ticket');
        assertMapaIntervalo(
            !in_array('reservacion_advertencia', $estado['modificadores_visual_mapa'], true),
            'una reserva a más de 60 min no añade alerta'
        );
        assertMapaIntervalo(
            $estado['aria_label_mapa'] === 'Mesa 14, disponible.',
            'la etiqueta accesible anuncia disponibilidad sin alerta'
        );
    }

    if ($hora === '17:31:00') {
        assertMapaIntervalo(
            in_array('reservacion', $estado['causas_bloqueo'], true)
                && !$estado['disponible_para_asignacion']
                && $estado['estado_visual_mapa'] === 'libre',
            '17:31 se solapa un minuto: asignación bloqueada y proyección verde'
        );
        assertMapaIntervalo(
            !in_array('reservacion_advertencia', $estado['modificadores_visual_mapa'], true),
            'una proyección futura a más de 60 min no muestra borde de advertencia'
        );
        assertMapaIntervalo(
            $estado['aria_label_mapa'] === 'Mesa 14, disponible.',
            'el label sigue describiendo la proyección verde aunque el intervalo esté bloqueado'
        );
    }

    if ($hora === '18:00:00') {
        assertMapaIntervalo(
            $estado['bloqueada_en_intervalo']
                && $estado['estado_visual_mapa'] === 'libre'
                && in_array('reservacion_advertencia', $estado['modificadores_visual_mapa'], true)
                && $estado['label_visual_mapa'] === 'Disponible con reservación próxima',
            '60 min: verde con borde, aunque el intervalo se solape'
        );
    }

    if ($hora === '18:30:00') {
        assertMapaIntervalo(
            $estado['estado_visual_mapa'] === 'reservacion-proxima'
                && $estado['label_visual_mapa'] === 'Reservación próxima',
            '30 min: azul preventivo antes del inicio'
        );
    }

    if ($hora === '19:00:00') {
        assertMapaIntervalo(
            $estado['estado_visual_mapa'] === 'ocupada'
                && $estado['label_visual_mapa'] === 'Ocupada por reservación',
            'inicio: rojo durante el periodo programado'
        );
    }

    if ($hora === '20:30:00') {
        assertMapaIntervalo(
            !$estado['bloqueada_en_intervalo'] && $estado['ventana_mapa'] === 'irrelevante',
            'fin exacto: verde por el límite final exclusivo'
        );
    }
}

fwrite(STDOUT, "Reservaciones: disponibilidad visual por intervalo semiabierto OK\n");
