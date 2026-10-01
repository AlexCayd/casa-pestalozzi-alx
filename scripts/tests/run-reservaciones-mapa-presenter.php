<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Reservations\ReservacionMapaMesaPresenter;

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
assertMapContract($available['estado_visual'] === 'libre', 'intervalo disponible usa verde');
assertMapContract($available['modificadores'] === [], 'intervalo disponible sin señales secundarias');

$adjacentReservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => false,
    'reservacion' => [
        'ventana_mapa' => 'futura',
        'reservacion_cercana_mapa' => true,
    ],
]);
assertMapContract($adjacentReservation['estado_visual'] === 'libre', 'reserva consecutiva conserva verde');
assertMapContract(
    $adjacentReservation['modificadores'] === ['reservacion_advertencia'],
    'reserva consecutiva añade sólo borde azul discontinuo'
);
assertMapContract(
    $adjacentReservation['label'] === 'disponible con reservación cercana',
    'reserva consecutiva tiene etiqueta accesible de disponibilidad'
);

$blockingReservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => [
        'ventana_mapa' => 'inicio',
        'reservacion_cercana_mapa' => true,
    ],
]);
assertMapContract($blockingReservation['estado_visual'] === 'reservacion-proxima', 'reserva que ocupa el intervalo usa azul');
assertMapContract($blockingReservation['modificadores'] === [], 'el bloqueo por reserva no duplica advertencia');
assertMapContract($blockingReservation['label'] === 'no disponible por reservación', 'azul explica la causa real');

$oneMinuteOverlap = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'futura'],
]);
assertMapContract($oneMinuteOverlap['estado_visual'] === 'reservacion-proxima', 'solapamiento de un minuto bloquea en azul');

$ticket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket'],
    'ticket_bloquea_consulta' => true,
]);
assertMapContract($ticket['estado_visual'] === 'ocupada', 'ticket que intersecta usa rojo');
assertMapContract($ticket['modificadores'] === [], 'ticket sin señales secundarias');
assertMapContract($ticket['label'] === 'no disponible por ticket', 'rojo explica el ticket');

$ticketWithNearbyReservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket'],
    'ticket_bloquea_consulta' => true,
    'reservacion' => [
        'ventana_mapa' => 'futura',
        'reservacion_cercana_mapa' => true,
    ],
]);
assertMapContract($ticketWithNearbyReservation['estado_visual'] === 'ocupada', 'ticket conserva prioridad roja');
assertMapContract(
    in_array('reservacion_advertencia', $ticketWithNearbyReservation['modificadores'], true),
    'la reserva consecutiva se mantiene como borde discontinuo secundario'
);

$ticketAndOverlappingReservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket', 'reservacion'],
    'ticket_bloquea_consulta' => true,
    'reservacion' => ['ventana_mapa' => 'advertencia'],
]);
assertMapContract($ticketAndOverlappingReservation['estado_visual'] === 'ocupada', 'ticket conserva prioridad sobre reserva solapada');
assertMapContract(
    !in_array('reservacion_advertencia', $ticketAndOverlappingReservation['modificadores'], true),
    'una reserva que también solapa no se duplica como advertencia'
);

$independentBlock = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion', 'hold'],
]);
assertMapContract($independentBlock['estado_visual'] === 'ocupada', 'hold independiente usa rojo');
assertMapContract($independentBlock['label'] === 'no disponible por retención', 'retención conserva prioridad descriptiva sobre reserva');

$releasedTicket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => false,
    'ticket_bloquea_consulta' => false,
    'causas_bloqueo' => [],
]);
assertMapContract($releasedTicket['estado_visual'] === 'libre', 'ticket liberado no fuerza rojo');

$physicalTicketWithoutIntervalBlock = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => false,
    'ticket_bloquea_consulta' => false,
    'causas_bloqueo' => [],
    'ocupada_fisicamente' => true,
]);
assertMapContract(
    $physicalTicketWithoutIntervalBlock['estado_visual'] === 'libre',
    'ocupación física por sí sola no se deriva como bloqueo de la proyección'
);

$absence = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => [
        'ventana_mapa' => 'ausencia_pendiente',
        'ausencia_pendiente_mapa' => true,
    ],
]);
assertMapContract($absence['estado_visual'] === 'reservacion-proxima', 'ausencia pendiente conserva el estado azul del bloqueo');
assertMapContract(in_array('ausencia_pendiente', $absence['modificadores'], true), 'ausencia pendiente se superpone');

$unusable = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => false,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
]);
assertMapContract($unusable['estado_visual'] === 'no-utilizable', 'no utilizable domina los bloqueos');

fwrite(STDOUT, "Reservaciones: presenter del mapa por hechos del intervalo OK\n");
