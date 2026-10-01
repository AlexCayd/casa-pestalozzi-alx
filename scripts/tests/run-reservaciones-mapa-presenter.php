<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Pos\ReservacionPoliticaPosService;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionMapaMesaPresenter;

/** @param mixed $condition */
function assertMapContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$free = ReservacionMapaMesaPresenter::presentar(['utilizable' => true]);
assertMapContract($free['estado_visual'] === 'libre' && $free['label'] === 'Disponible', 'mesa libre usa verde');

// V24: la ventana completa bloquea asignación, pero todavía no la proyección visual.
$futureOverlap = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'futura'],
]);
assertMapContract($futureOverlap['estado_visual'] === 'libre', 'un solapamiento futuro del intervalo no fuerza color');
assertMapContract($futureOverlap['label'] === 'Disponible', 'la consulta futura conserva label disponible');

$warning = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'advertencia'],
]);
assertMapContract($warning['estado_visual'] === 'libre', 'advertencia conserva fondo verde aunque cruce el intervalo');
assertMapContract($warning['modificadores'] === ['reservacion_advertencia'], 'advertencia da borde discontinuo');
assertMapContract($warning['label'] === 'Disponible con reservación próxima', 'label canónico de advertencia');

$block = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'bloqueo'],
]);
assertMapContract($block['estado_visual'] === 'reservacion-proxima', 'bloqueo previo usa azul');
assertMapContract($block['label'] === 'Reservación próxima', 'azul comunica el bloqueo preventivo');

$activeReservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
    'reservacion' => ['ventana_mapa' => 'activa'],
]);
assertMapContract($activeReservation['estado_visual'] === 'ocupada', 'reservación activa usa rojo');
assertMapContract($activeReservation['label'] === 'Ocupada por reservación', 'rojo identifica la reserva');

$ticket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket'],
    'ticket_bloquea_consulta' => true,
]);
assertMapContract($ticket['estado_visual'] === 'ocupada', 'ticket bloqueante usa rojo');
assertMapContract($ticket['label'] === 'No disponible por ticket', 'ticket proyectado describe su causa');

$activeServiceTicket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket'],
    'ticket_bloquea_consulta' => true,
    'ocupada_fisicamente' => true,
]);
assertMapContract($activeServiceTicket['label'] === 'Ocupada por servicio activo', 'ticket físico tiene label operativo');

$ticketAndWarning = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket', 'reservacion'],
    'ticket_bloquea_consulta' => true,
    'ocupada_fisicamente' => true,
    'reservacion' => ['ventana_mapa' => 'advertencia'],
]);
assertMapContract($ticketAndWarning['estado_visual'] === 'ocupada', 'ticket mantiene precedencia roja');
assertMapContract(
    in_array('reservacion_advertencia', $ticketAndWarning['modificadores'], true),
    'advertencia temporal acompaña el rojo del ticket'
);

$ticketAndActiveReservation = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['ticket', 'reservacion'],
    'ticket_bloquea_consulta' => true,
    'ocupada_fisicamente' => true,
    'reservacion' => ['ventana_mapa' => 'activa'],
]);
assertMapContract($ticketAndActiveReservation['estado_visual'] === 'ocupada', 'ticket y reserva activa siguen rojos');
assertMapContract($ticketAndActiveReservation['label'] === 'Ocupada por servicio activo', 'ticket físico prioriza el texto');
assertMapContract($ticketAndActiveReservation['modificadores'] === [], 'reserva activa no simula advertencia');

$hold = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['hold'],
]);
assertMapContract($hold['estado_visual'] === 'ocupada', 'hold vigente usa rojo');
assertMapContract($hold['label'] === 'No disponible por retención', 'hold conserva label propio');

$releasedPhysicalTicket = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => true,
    'bloqueada_en_intervalo' => false,
    'ticket_bloquea_consulta' => false,
    'causas_bloqueo' => [],
    'ocupada_fisicamente' => true,
]);
assertMapContract($releasedPhysicalTicket['estado_visual'] === 'libre', 'ticket físico estimado como liberado no fuerza rojo');

$unusable = ReservacionMapaMesaPresenter::presentar([
    'utilizable' => false,
    'bloqueada_en_intervalo' => true,
    'causas_bloqueo' => ['reservacion'],
]);
assertMapContract($unusable['estado_visual'] === 'no-utilizable', 'no utilizable domina cualquier conflicto');

// Las ventanas de reservación se proyectan respecto al instante consultado,
// sin depender del reloj actual ni de la vigencia operativa POS.
$reservation = [
    'estado' => 'confirmada',
    'fecha' => '2037-02-15',
    'hora' => '12:00:00',
];
$now = new DateTimeImmutable('2037-02-15 08:00:00', ReservacionConfig::timezone());
$windows = [
    '10:59:59' => 'futura',
    '11:00:00' => 'advertencia',
    '11:00:01' => 'advertencia',
    '11:29:59' => 'advertencia',
    '11:30:00' => 'bloqueo',
    '11:30:01' => 'bloqueo',
    '12:00:00' => 'inicio',
    '12:14:59' => 'activa',
    '12:15:00' => 'activa',
    '12:15:01' => 'activa',
    '13:29:59' => 'activa',
    '13:30:00' => 'irrelevante',
];
foreach ($windows as $time => $expected) {
    $projection = ReservacionPoliticaPosService::proyeccionMapa(
        $reservation,
        new DateTimeImmutable('2037-02-15 ' . $time, ReservacionConfig::timezone()),
        $now
    );
    assertMapContract(
        $projection['ventana_mapa'] === $expected,
        "ventana proyectada en {$time}: esperado {$expected}, recibido {$projection['ventana_mapa']}"
    );
}

fwrite(STDOUT, "Reservaciones: presenter proyecta la hora consultada y conserva asignabilidad independiente OK\n");
