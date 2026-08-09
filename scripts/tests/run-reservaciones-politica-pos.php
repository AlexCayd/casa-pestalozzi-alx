<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\ReservacionConfig;
use Services\ReservacionPoliticaPosService;

/** @param mixed $condition */
function assertPolitica($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reservacion = [
    'id' => 1300,
    'estado' => 'confirmada',
    'fecha' => '2026-08-08',
    'hora' => '13:00:00',
    'mesa_ids' => [4],
    'comensales' => 2,
];

$evaluar = static function (string $hora) use ($reservacion): array {
    $reloj = new DateTimeImmutable('2026-08-08 ' . $hora, ReservacionConfig::timezone());
    return ReservacionPoliticaPosService::evaluar(
        $reservacion,
        $reloj,
        null,
        $reloj
    );
};

$futura = $evaluar('11:30:00');
assertPolitica($futura['disponible_para_ticket'] === true, '11:30 permite walk-in');
assertPolitica($futura['requiere_advertencia_ticket'] === false, '11:30 no advierte');
assertPolitica($futura['ventana_visual_pos'] === 'futura', '11:30 es futura');

$aviso = $evaluar('12:00:00');
assertPolitica($aviso['disponible_para_ticket'] === true, 'exactamente 60 permite walk-in');
assertPolitica($aviso['requiere_advertencia_ticket'] === true, 'exactamente 60 requiere warning');
assertPolitica($aviso['bloqueo_walk_in'] === false, 'exactamente 60 no bloquea walk-in');
assertPolitica($aviso['ventana_visual_pos'] === 'advertencia', 'exactamente 60 es advertencia');

foreach (['12:01:00', '12:15:00', '12:29:00'] as $horaWarning) {
    $warningIntermedio = $evaluar($horaWarning);
    assertPolitica($warningIntermedio['disponible_para_ticket'] === true, "{$horaWarning} permite walk-in");
    assertPolitica($warningIntermedio['requiere_advertencia_ticket'] === true, "{$horaWarning} requiere warning");
    assertPolitica($warningIntermedio['bloqueo_walk_in'] === false, "{$horaWarning} no bloquea walk-in");
    assertPolitica($warningIntermedio['ventana_visual_pos'] === 'advertencia', "{$horaWarning} es advertencia");
}

$bloqueo = $evaluar('12:30:00');
assertPolitica($bloqueo['disponible_para_ticket'] === false, 'exactamente 30 bloquea walk-in');
assertPolitica($bloqueo['requiere_advertencia_ticket'] === false, 'exactamente 30 no es warning');
assertPolitica($bloqueo['bloqueo_walk_in'] === true, 'exactamente 30 marca bloqueo walk-in');
assertPolitica($bloqueo['ventana_visual_pos'] === 'bloqueo', 'exactamente 30 es bloqueo');

$inicio = $evaluar('13:00:00');
assertPolitica($inicio['disponible_para_ticket'] === false, 'inicio exacto bloquea walk-in');
assertPolitica($inicio['puede_iniciar_reservacion'] === true, 'inicio exacto permite servicio propio');
assertPolitica($inicio['ventana_visual_pos'] === 'inicio', 'inicio exacto es rojo de mapa');

$unSegundoDespues = $evaluar('13:00:01');
assertPolitica($unSegundoDespues['ventana_visual_pos'] === 'tolerancia', 'un segundo despues del inicio entra en tolerancia');

$tolerancia = $evaluar('13:15:00');
assertPolitica($tolerancia['ausencia_pendiente'] === false, 'borde de tolerancia sigue protegido');
assertPolitica($tolerancia['disponible_para_ticket'] === false, 'tolerancia bloquea walk-in');
assertPolitica($tolerancia['ventana_visual_pos'] === 'tolerancia', 'tolerancia mantiene la ventana POS azul');

$ausencia = $evaluar('13:16:00');
assertPolitica($ausencia['ausencia_pendiente'] === true, '13:16 registra ausencia pendiente');
assertPolitica($ausencia['reservacion_influye_en_disponibilidad'] === false, 'ausencia pendiente libera influencia de capacidad');
assertPolitica($ausencia['disponible_para_ticket'] === false, 'ausencia pendiente no permite walk-in');
assertPolitica($ausencia['puede_marcar_no_show'] === true, 'ausencia pendiente permite no-show manual');
assertPolitica($ausencia['accion_primaria'] === ReservacionPoliticaPosService::ACCION_REGISTRAR_AUSENCIA, 'ausencia prioriza registrar ausencia');

$noShowPolicy = ReservacionPoliticaPosService::evaluar([
    ...$reservacion,
    'estado' => 'no_show',
], new DateTimeImmutable('2026-08-08 13:16:00', ReservacionConfig::timezone()));
assertPolitica($noShowPolicy['disponible_para_ticket'] === true, 'no-show permite revalidar walk-in');
assertPolitica($noShowPolicy['ausencia_pendiente'] === false, 'no-show elimina ausencia pendiente');

fwrite(STDOUT, "Reservaciones: política POS configurable OK\n");
