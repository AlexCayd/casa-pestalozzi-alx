<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\ReservacionMapaMesaPresenter;

/** @param mixed $condition */
function assertMapContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$available = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => false,
]);
assertMapContract($available['estado_visual'] === 'libre', 'mesa libre usa verde');
assertMapContract($available['modificadores'] === [], 'mesa libre no tiene modificadores de proximidad');

$reservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'advertencia'],
]);
assertMapContract($reservation['estado_visual'] === 'libre', 'reservacion cercana conserva verde');
assertMapContract($reservation['modificadores'] === ['reservacion_advertencia'], 'reservacion cercana agrega borde azul');
assertMapContract($reservation['precedencia'] === 'reservacion_advertencia', 'advertencia usa proyeccion temporal');
assertMapContract(str_contains($reservation['label'], 'reservaci'), 'advertencia explica la causa de reservacion');

$blockingReservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'bloqueo'],
]);
assertMapContract($blockingReservation['estado_visual'] === 'reservacion-proxima', '30 minutos usa azul de reservacion');
assertMapContract($blockingReservation['modificadores'] === ['reservacion_inminente'], '30 minutos usa modificador inminente');

$redAbsence = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'ticket_bloquea_consulta' => true,
    'reservacion' => [
        'ventana_mapa' => 'inicio',
        'ausencia_pendiente' => true,
    ],
]);
assertMapContract($redAbsence['estado_visual'] === 'ocupada', 'rojo conserva la base con ausencia');
assertMapContract(in_array('ausencia_pendiente', $redAbsence['modificadores'], true), 'rojo agrega ausencia como modificador');

$blueAbsence = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'reservacion' => [
        'ventana_mapa' => 'bloqueo',
        'ausencia_pendiente' => true,
    ],
]);
assertMapContract($blueAbsence['estado_visual'] === 'reservacion-proxima', 'azul conserva la base con ausencia');
assertMapContract($blueAbsence['modificadores'] === ['reservacion_inminente', 'ausencia_pendiente'], 'azul compone ausencia');

$warningAbsence = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'reservacion' => [
        'ventana_mapa' => 'advertencia',
        'ausencia_pendiente' => true,
    ],
]);
assertMapContract($warningAbsence['estado_visual'] === 'libre', 'verde conserva la base con ausencia');
assertMapContract($warningAbsence['modificadores'] === ['reservacion_advertencia', 'ausencia_pendiente'], 'advertencia azul y ausencia gris coexisten');
assertMapContract(str_contains($warningAbsence['label'], 'ausencia'), 'mapa anuncia ausencia en su etiqueta');

$start = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'inicio'],
]);
assertMapContract($start['estado_visual'] === 'ocupada', 'inicio exacto usa rojo');

$tolerance = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => [
        'ventana_mapa' => 'tolerancia',
        'reservacion_influye_en_consulta' => true,
    ],
]);
assertMapContract($tolerance['estado_visual'] === 'ocupada', 'tolerancia no conserva azul');

$redAfterTolerance = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => [
        'ventana_mapa' => 'ausencia_pendiente',
        'reservacion_en_intervalo_planificado' => true,
        'ausencia_pendiente' => true,
    ],
]);
assertMapContract($redAfterTolerance['estado_visual'] === 'ocupada', 'despues de tolerancia usa rojo dentro del intervalo');
assertMapContract(in_array('ausencia_pendiente', $redAfterTolerance['modificadores'], true), 'rojo dentro del intervalo compone ausencia');

$greenAfterInterval = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => false,
    'reservacion' => [
        'ventana_mapa' => 'ausencia_pendiente',
        'reservacion_en_intervalo_planificado' => false,
        'ausencia_pendiente' => true,
    ],
]);
assertMapContract($greenAfterInterval['estado_visual'] === 'libre', 'fin del intervalo recalcula a verde');
assertMapContract(in_array('ausencia_pendiente', $greenAfterInterval['modificadores'], true), 'verde posterior conserva ausencia');

$ticket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket'],
    'ticket_bloquea_consulta' => true,
]);
assertMapContract($ticket['estado_visual'] === 'ocupada', 'ticket bloqueante usa rojo');
assertMapContract($ticket['modificadores'] === [], 'ticket bloqueante no agrega proximidad');
assertMapContract(str_contains($ticket['label'], 'ticket'), 'ticket conserva la causa para detalle');

$unusable = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => false,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
]);
assertMapContract($unusable['estado_visual'] === 'no-utilizable', 'mesa no utilizable domina el bloqueo');

fwrite(STDOUT, "Reservaciones: presenter del mapa OK\n");
