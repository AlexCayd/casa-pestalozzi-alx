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

$start = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'inicio'],
]);
assertMapContract($start['estado_visual'] === 'ocupada', 'inicio exacto usa rojo');

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
