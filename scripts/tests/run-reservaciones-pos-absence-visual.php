<?php

declare(strict_types=1);

/*
 * Ausencia pendiente en el POS. La política (ventanas, permiso de walk-in y
 * no-show) no cambia; el presenter la pinta con los tres estados: la mesa sigue
 * azul («Se libera») los segundos que tarda la siguiente lectura en registrar
 * la ausencia sola (ReservacionAusenciaAutomaticaService); el walk-in sigue
 * bloqueado mientras tanto.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Pos\PosMesaProjectionPresenter;
use Services\Reservations\ReservacionConfig;
use Services\Pos\ReservacionPoliticaPosService;

/** @param mixed $condition */
function assertPosAbsenceVisual($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reservacion = [
    'id' => 1401,
    'estado' => 'confirmada',
    'fecha' => '2026-08-08',
    'hora' => '14:00:00',
    'mesa_ids' => [14],
    'ticket_abierto' => false,
];
$mesaHechos = ['utilizable' => true, 'ticket_bloquea_consulta' => false];

$limites = [
    ['2026-08-08 12:59:59', 'futura', false, false, 'un segundo antes del límite de advertencia sigue siendo futuro'],
    ['2026-08-08 13:00:00', 'advertencia', true, false, '60 minutos exactos inicia advertencia sin bloquear walk-in'],
    ['2026-08-08 13:30:00', 'bloqueo', false, true, '30 minutos exactos bloquea walk-in'],
    ['2026-08-08 14:00:00', 'bloqueo', false, true, 'el inicio exacto conserva el bloqueo canónico'],
    ['2026-08-08 14:15:00', 'tolerancia', false, true, '15 minutos exactos posteriores al inicio siguen en tolerancia'],
    ['2026-08-08 14:15:01', 'ausencia_pendiente', false, true, 'después de 15 minutos vence la tolerancia y requiere acción'],
];
foreach ($limites as [$marcaTiempo, $ventanaEsperada, $advertenciaEsperada, $bloqueoEsperado, $mensaje]) {
    $limite = ReservacionPoliticaPosService::evaluar(
        $reservacion,
        new DateTimeImmutable($marcaTiempo, ReservacionConfig::timezone())
    );
    assertPosAbsenceVisual($limite['ventana_pos'] === $ventanaEsperada, $mensaje . ' (ventana)');
    assertPosAbsenceVisual($limite['requiere_advertencia_ticket'] === $advertenciaEsperada, $mensaje . ' (advertencia)');
    assertPosAbsenceVisual($limite['bloqueo_walk_in'] === $bloqueoEsperado, $mensaje . ' (bloqueo)');
}

$presentarEn = static function (string $marcaTiempo, array $extra = []) use ($reservacion, $mesaHechos): array {
    $politica = ReservacionPoliticaPosService::evaluar(
        $reservacion,
        new DateTimeImmutable($marcaTiempo, ReservacionConfig::timezone())
    );
    return [$politica, PosMesaProjectionPresenter::presentar(array_merge($mesaHechos, $extra, [
        'reservacion' => array_merge($reservacion, $politica),
    ]))];
};

[$politicaTolerancia, $visualTolerancia] = $presentarEn('2026-08-08 14:15:00');
assertPosAbsenceVisual($politicaTolerancia['ausencia_pendiente'] === false, 'dentro de tolerancia no queda pendiente');
assertPosAbsenceVisual($visualTolerancia['estado_visual'] === 'reservacion-proxima', 'tolerancia posterior al inicio usa azul');
assertPosAbsenceVisual(!in_array('sin_llegar', $visualTolerancia['modificadores'], true), 'dentro de tolerancia todavía no es «sin llegar»');

[$politicaAusencia, $visualAusencia] = $presentarEn('2026-08-08 14:16:00');
assertPosAbsenceVisual($politicaAusencia['ausencia_pendiente'] === true, 'despues de tolerancia queda pendiente');
assertPosAbsenceVisual($politicaAusencia['disponible_para_ticket'] === false, 'ausencia pendiente sigue bloqueando walk-in');
assertPosAbsenceVisual($politicaAusencia['puede_marcar_no_show'] === true, 'ausencia pendiente permite no-show');
assertPosAbsenceVisual($visualAusencia['estado_visual'] === 'reservacion-proxima', 'ausencia pendiente sigue en azul');
assertPosAbsenceVisual($visualAusencia['modificadores'] === ['sin_llegar'], 'ausencia pendiente lleva sólo el indicador «sin llegar»');
assertPosAbsenceVisual($visualAusencia['subtitulo'] === 'Se libera', 'el subrótulo dice que la mesa se libera sola');
assertPosAbsenceVisual(str_contains($visualAusencia['aria_label'], 'se registra sola'), 'la lectura en voz dice que la ausencia se registra sola');

// Después del intervalo planificado sigue apartada: el walk-in tampoco la acepta.
[$politicaDespues, $visualDespues] = $presentarEn('2026-08-08 15:30:00');
assertPosAbsenceVisual($politicaDespues['intervalo_planificado_vigente'] === false, '15:30 termina el intervalo planificado');
assertPosAbsenceVisual($politicaDespues['disponible_para_ticket'] === false, 'después del intervalo el walk-in sigue bloqueado');
assertPosAbsenceVisual($visualDespues['estado_visual'] === 'reservacion-proxima', 'y el mapa no la pinta libre');
assertPosAbsenceVisual(in_array('sin_llegar', $visualDespues['modificadores'], true), 'conserva el indicador hasta registrar la ausencia');

// Con un ticket abierto en la mesa manda el rojo, y el indicador acompaña.
$visualRoja = PosMesaProjectionPresenter::presentar([
    'utilizable' => true,
    'ticket_bloquea_consulta' => true,
    'reservacion' => array_merge($reservacion, $politicaAusencia),
]);
assertPosAbsenceVisual($visualRoja['estado_visual'] === 'ocupada', 'rojo conserva el estado base con ausencia');
assertPosAbsenceVisual(in_array('sin_llegar', $visualRoja['modificadores'], true), 'rojo conserva el indicador');

// La ausencia gana a cualquier ventana.
foreach (['bloqueo', 'advertencia'] as $ventana) {
    $visual = PosMesaProjectionPresenter::presentar(array_merge($mesaHechos, [
        'reservacion' => array_merge($reservacion, ['ventana_visual_pos' => $ventana, 'ausencia_pendiente' => true]),
    ]));
    assertPosAbsenceVisual($visual['estado_visual'] === 'reservacion-proxima' && $visual['modificadores'] === ['sin_llegar'], "ausencia gana a la ventana {$ventana}");
}

// La advertencia (60→30 min) ya es azul aunque el walk-in siga permitido.
$visualAdvertencia = PosMesaProjectionPresenter::presentar(array_merge($mesaHechos, [
    'puede_abrir_ticket' => true,
    'reservacion' => array_merge($reservacion, ['ventana_visual_pos' => 'advertencia', 'minutos_para_inicio' => 45]),
]));
assertPosAbsenceVisual($visualAdvertencia['estado_visual'] === 'reservacion-proxima', 'advertencia pinta azul');
assertPosAbsenceVisual($visualAdvertencia['subtitulo'] === 'en 45 min', 'advertencia dice cuánto falta');

// Un no-show registrado deja de apartar la mesa.
$noShow = array_merge($reservacion, ['estado' => 'no_show']);
$politicaNoShow = ReservacionPoliticaPosService::evaluar(
    $noShow,
    new DateTimeImmutable('2026-08-08 14:16:00', ReservacionConfig::timezone())
);
$visualNoShow = PosMesaProjectionPresenter::presentar(array_merge($mesaHechos, [
    'reservacion' => array_merge($noShow, $politicaNoShow),
]));
assertPosAbsenceVisual($politicaNoShow['ausencia_pendiente'] === false, 'no-show elimina ausencia pendiente');
assertPosAbsenceVisual($visualNoShow['estado_visual'] === 'libre' && $visualNoShow['modificadores'] === [], 'no-show deja la mesa libre y sin indicador');

fwrite(STDOUT, "Reservaciones: visual POS de ausencia pendiente OK\n");
