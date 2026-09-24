<?php

declare(strict_types=1);

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

$dentroTolerancia = new DateTimeImmutable('2026-08-08 14:15:00', ReservacionConfig::timezone());
$politicaTolerancia = ReservacionPoliticaPosService::evaluar($reservacion, $dentroTolerancia);
$visualTolerancia = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, $politicaTolerancia),
]);
assertPosAbsenceVisual($politicaTolerancia['ausencia_pendiente'] === false, 'dentro de tolerancia no queda pendiente');
assertPosAbsenceVisual($visualTolerancia['estado_visual'] === 'reservacion-proxima', 'tolerancia posterior al inicio usa azul');
assertPosAbsenceVisual($visualTolerancia['modificadores'] !== ['ausencia_pendiente'], 'tolerancia no muestra borde gris');

$despuesTolerancia = new DateTimeImmutable('2026-08-08 14:16:00', ReservacionConfig::timezone());
$politicaAusencia = ReservacionPoliticaPosService::evaluar($reservacion, $despuesTolerancia);
$visualAusencia = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, $politicaAusencia),
]);
assertPosAbsenceVisual($politicaAusencia['ausencia_pendiente'] === true, 'despues de tolerancia queda pendiente');
assertPosAbsenceVisual($politicaAusencia['disponible_para_ticket'] === false, 'ausencia pendiente sigue bloqueando walk-in');
assertPosAbsenceVisual($politicaAusencia['puede_marcar_no_show'] === true, 'ausencia pendiente permite no-show');
assertPosAbsenceVisual($visualAusencia['estado_visual'] === 'reservacion-proxima', 'ausencia pendiente bloqueante usa azul oscuro');
assertPosAbsenceVisual(!in_array('reservacion_bloqueante', $visualAusencia['modificadores'], true), 'ausencia pendiente no agrega bloqueo visual');
assertPosAbsenceVisual(in_array('ausencia_pendiente', $visualAusencia['modificadores'], true), 'ausencia pendiente agrega indicador secundario');
assertPosAbsenceVisual(in_array('accion_pendiente', $visualAusencia['modificadores'], true), 'ausencia pendiente muestra la acción pendiente');
assertPosAbsenceVisual($visualAusencia['aria_label'] === 'Tolerancia vencida. Registra que el cliente no llegó antes de utilizar la mesa.', 'aria explica la restricción y la acción pendiente');

$despuesIntervalo = new DateTimeImmutable('2026-08-08 15:30:00', ReservacionConfig::timezone());
$politicaDespuesIntervalo = ReservacionPoliticaPosService::evaluar($reservacion, $despuesIntervalo);
$visualDespuesIntervalo = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, $politicaDespuesIntervalo),
]);
assertPosAbsenceVisual($politicaDespuesIntervalo['intervalo_planificado_vigente'] === false, '15:30 termina el intervalo planificado');
assertPosAbsenceVisual($visualDespuesIntervalo['estado_visual'] === 'reservacion-proxima', 'ausencia pendiente sigue bloqueando walk-in después del intervalo');
assertPosAbsenceVisual(in_array('accion_pendiente', $visualDespuesIntervalo['modificadores'], true), 'despues del intervalo conserva la señal pendiente');

$visualRoja = PosMesaProjectionPresenter::presentar([
    'utilizable' => true,
    'ticket_bloquea_consulta' => true,
    'reservacion' => array_merge($reservacion, $politicaAusencia),
]);
assertPosAbsenceVisual($visualRoja['estado_visual'] === 'ocupada', 'rojo conserva el estado base con ausencia');
assertPosAbsenceVisual(in_array('ausencia_pendiente', $visualRoja['modificadores'], true), 'rojo conserva el indicador gris');

$visualAzul = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, [
        'ventana_visual_pos' => 'bloqueo',
        'ausencia_pendiente' => true,
    ]),
]);
assertPosAbsenceVisual($visualAzul['estado_visual'] === 'reservacion-proxima', 'azul conserva el estado base con ausencia');
assertPosAbsenceVisual($visualAzul['modificadores'] === ['accion_pendiente', 'reservacion_inminente', 'ausencia_pendiente'], 'azul compone las alertas secundarias');

$visualAdvertenciaAusencia = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, [
        'ventana_visual_pos' => 'advertencia',
        'ausencia_pendiente' => true,
    ]),
]);
assertPosAbsenceVisual($visualAdvertenciaAusencia['estado_visual'] === 'reservacion-proxima', 'ausencia bloqueante prevalece sobre advertencia verde');
assertPosAbsenceVisual(in_array('reservacion_advertencia', $visualAdvertenciaAusencia['modificadores'], true), 'advertencia sigue como condición secundaria');
assertPosAbsenceVisual(in_array('accion_pendiente', $visualAdvertenciaAusencia['modificadores'], true), 'ausencia y advertencia son composables');

$visualAdvertenciaUtilizable = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'puede_abrir_ticket' => true,
    'reservacion' => array_merge($reservacion, ['ventana_visual_pos' => 'advertencia']),
]);
assertPosAbsenceVisual($visualAdvertenciaUtilizable['estado_visual'] === 'libre', 'advertencia conserva verde si el backend permite el ticket');
assertPosAbsenceVisual(in_array('reservacion_advertencia', $visualAdvertenciaUtilizable['modificadores'], true), 'verde utiliza borde de advertencia azul');

$visualConTicket = PosMesaProjectionPresenter::presentar([
    'utilizable' => true,
    'ticket_bloquea_consulta' => true,
    'reservacion' => array_merge($reservacion, $politicaAusencia),
]);
assertPosAbsenceVisual($visualConTicket['estado_visual'] === 'ocupada', 'ticket abierto conserva precedencia roja');

$noShow = array_merge($reservacion, ['estado' => 'no_show']);
$politicaNoShow = ReservacionPoliticaPosService::evaluar($noShow, $despuesTolerancia);
$visualNoShow = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($noShow, $politicaNoShow),
]);
assertPosAbsenceVisual($politicaNoShow['ausencia_pendiente'] === false, 'no-show elimina ausencia pendiente');
assertPosAbsenceVisual($visualNoShow['modificadores'] === [], 'no-show no deja borde gris residual');

fwrite(STDOUT, "Reservaciones: visual POS de ausencia pendiente OK\n");
