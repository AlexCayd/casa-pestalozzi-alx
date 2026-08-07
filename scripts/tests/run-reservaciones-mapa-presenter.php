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
    'inicio_exacto' => true,
]);
assertMapContract($exact['estado_visual'] === 'ocupada', 'inicio exacto usa rojo');
assertMapContract(in_array('reservacion_bloqueante', $exact['modificadores'], true), 'inicio exacto conserva modificador bloqueante');

$warning = presentMap(['minutos_para_inicio' => 60]);
assertMapContract($warning['estado_visual'] === 'libre', 'sesenta minutos conserva fondo verde');
assertMapContract($warning['modificadores'] === ['reservacion_advertencia'], 'sesenta minutos usa advertencia punteada');

$imminent = presentMap(['minutos_para_inicio' => 30]);
assertMapContract($imminent['estado_visual'] === 'reservacion-proxima', 'treinta minutos usa azul');
assertMapContract($imminent['precedencia'] === 'reservacion_0_30', 'treinta minutos cae en ventana 0-30');

$tolerance = presentMap(['minutos_para_inicio' => 0, 'en_tolerancia' => true]);
assertMapContract($tolerance['estado_visual'] === 'reservacion-proxima', 'tolerancia usa azul');
assertMapContract($tolerance['modificadores'] === ['reservacion_tolerancia'], 'tolerancia usa borde gris');

$absence = presentMap([
    'minutos_para_inicio' => -15,
    'inicio_exacto' => false,
    'tolerancia_vencida' => true,
    'ausencia_pendiente' => true,
]);
assertMapContract($absence['estado_visual'] === 'libre', 'tolerancia vencida con ausencia conserva verde');
assertMapContract(in_array('accion_pendiente', $absence['modificadores'], true), 'ausencia pendiente usa modificador visual');
assertMapContract(in_array('AUSENCIA_PENDIENTE', $absence['modificadores'], true), 'ausencia pendiente conserva codigo de accion');

$unusable = presentMap(['minutos_para_inicio' => 0, 'inicio_exacto' => true], ['utilizable' => false]);
assertMapContract($unusable['estado_visual'] === 'no-utilizable', 'mesa no utilizable domina reservacion');

fwrite(STDOUT, "Reservaciones: presenter del mapa OK\n");
