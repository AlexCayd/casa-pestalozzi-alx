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
]);
assertMapContract($reservation['estado_visual'] === 'ocupada', 'reservacion traslapada usa rojo');
assertMapContract($reservation['modificadores'] === [], 'reservacion bloqueante no agrega borde azul');
assertMapContract($reservation['precedencia'] === 'ocupacion', 'bloqueo usa ocupacion canonica');
assertMapContract(str_contains($reservation['label'], 'reservaci'), 'bloqueo explica la causa de reservacion');

$ticket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket'],
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
