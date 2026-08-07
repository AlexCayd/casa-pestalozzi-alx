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

function presentMap(array $reservation, array $base = ['utilizable' => true]): array
{
    return ReservacionMapaMesaPresenter::presentar(array_merge($base, [
        'reservaciones' => [$reservation],
    ]));
}

$available = ReservacionMapaMesaPresenter::presentar(['utilizable' => true]);
assertMapContract($available['estado_visual'] === 'libre', 'disponible usa verde');

$ticket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'ticket_abierto' => true,
    'reservaciones' => [['minutos_para_inicio' => 45]],
]);
assertMapContract($ticket['estado_visual'] === 'ocupada', 'ticket abierto usa rojo');
assertMapContract($ticket['precedencia'] === 'ticket', 'ticket domina cualquier reservacion');

$exact = presentMap([
    'minutos_para_inicio' => 0,
    'bloquea_intervalo_reservacion' => true,
]);
assertMapContract($exact['estado_visual'] === 'ocupada', 'inicio exacto usa rojo');
assertMapContract(in_array('reservacion_bloqueante', $exact['modificadores'], true), 'inicio exacto conserva modificador bloqueante');

$started = presentMap([
    'minutos_para_inicio' => -30,
    'en_tolerancia' => true,
    'bloquea_intervalo_reservacion' => true,
]);
assertMapContract($started['estado_visual'] === 'ocupada', 'reservacion iniciada dentro del intervalo usa rojo');
assertMapContract($started['modificadores'] === ['reservacion_bloqueante'], 'mapa no agrega modificador de tolerancia');

$warning = presentMap(['minutos_para_inicio' => 60]);
assertMapContract($warning['estado_visual'] === 'libre', 'sesenta minutos conserva fondo verde');
assertMapContract($warning['modificadores'] === ['reservacion_advertencia'], 'sesenta minutos usa advertencia punteada');

$imminent = presentMap(['minutos_para_inicio' => 30]);
assertMapContract($imminent['estado_visual'] === 'reservacion-proxima', 'treinta minutos usa azul');
assertMapContract($imminent['precedencia'] === 'reservacion_0_30', 'treinta minutos cae en ventana 0-30');

$afterInterval = presentMap([
    'minutos_para_inicio' => -100,
    'tolerancia_vencida' => true,
    'ausencia_pendiente' => true,
    'bloquea_intervalo_reservacion' => false,
]);
assertMapContract($afterInterval['estado_visual'] === 'libre', 'despues del intervalo usa verde');
assertMapContract($afterInterval['modificadores'] === [], 'mapa no agrega estado de ausencia');

$unusable = presentMap(['minutos_para_inicio' => 0, 'inicio_exacto' => true], ['utilizable' => false]);
assertMapContract($unusable['estado_visual'] === 'no-utilizable', 'mesa no utilizable domina reservacion');

fwrite(STDOUT, "Reservaciones: presenter del mapa OK\n");
