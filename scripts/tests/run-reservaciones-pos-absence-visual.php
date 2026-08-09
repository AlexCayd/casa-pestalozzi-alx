<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\PosMesaProjectionPresenter;
use Services\ReservacionConfig;
use Services\ReservacionPoliticaPosService;

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

$dentroTolerancia = new DateTimeImmutable('2026-08-08 14:15:00', ReservacionConfig::timezone());
$politicaTolerancia = ReservacionPoliticaPosService::evaluar($reservacion, $dentroTolerancia);
$visualTolerancia = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, $politicaTolerancia),
]);
assertPosAbsenceVisual($politicaTolerancia['ausencia_pendiente'] === false, 'dentro de tolerancia no queda pendiente');
assertPosAbsenceVisual($visualTolerancia['estado_visual'] === 'ocupada', 'tolerancia posterior al inicio usa rojo');
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
assertPosAbsenceVisual($visualAusencia['estado_visual'] === 'libre', 'ausencia pendiente no fuerza rojo');
assertPosAbsenceVisual(!in_array('reservacion_bloqueante', $visualAusencia['modificadores'], true), 'ausencia pendiente no agrega bloqueo visual');
assertPosAbsenceVisual(in_array('ausencia_pendiente', $visualAusencia['modificadores'], true), 'ausencia pendiente agrega indicador gris');
assertPosAbsenceVisual(str_contains($visualAusencia['aria_label'], 'Acción pendiente: registrar ausencia'), 'aria anuncia la accion pendiente');

$despuesIntervalo = new DateTimeImmutable('2026-08-08 15:30:00', ReservacionConfig::timezone());
$politicaDespuesIntervalo = ReservacionPoliticaPosService::evaluar($reservacion, $despuesIntervalo);
$visualDespuesIntervalo = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, $politicaDespuesIntervalo),
]);
assertPosAbsenceVisual($politicaDespuesIntervalo['intervalo_planificado_vigente'] === false, '15:30 termina el intervalo planificado');
assertPosAbsenceVisual($visualDespuesIntervalo['estado_visual'] === 'libre', 'despues del intervalo recalcula verde');
assertPosAbsenceVisual(in_array('ausencia_pendiente', $visualDespuesIntervalo['modificadores'], true), 'despues del intervalo conserva gris');

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
assertPosAbsenceVisual($visualAzul['modificadores'] === ['reservacion_inminente', 'ausencia_pendiente'], 'azul compone el indicador gris');

$visualAdvertenciaAusencia = PosMesaProjectionPresenter::presentar([
    ...$mesaHechos,
    'reservacion' => array_merge($reservacion, [
        'ventana_visual_pos' => 'advertencia',
        'ausencia_pendiente' => true,
    ]),
]);
assertPosAbsenceVisual($visualAdvertenciaAusencia['estado_visual'] === 'libre', 'advertencia conserva el verde base con ausencia');
assertPosAbsenceVisual($visualAdvertenciaAusencia['modificadores'] === ['reservacion_advertencia', 'ausencia_pendiente'], 'advertencia y ausencia son composables');

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
