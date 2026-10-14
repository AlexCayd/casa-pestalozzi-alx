<?php

declare(strict_types=1);

/*
 * Contrato visual del POS: tres estados (libre, ocupada, reservacion-proxima),
 * cada uno con etiqueta y subrótulo. La política de walk-in (permiso y
 * advertencia) es la de siempre; lo que cambia es que una reserva a menos de
 * 60 min ya pinta la mesa de azul.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Pos\PosMesaProjectionPresenter;
use Services\Reservations\ReservacionConfig;
use Services\Pos\ReservacionPoliticaPosService;

/** @param mixed $condition */
function assertPosVisualContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reservacion = [
    'id' => 6030,
    'estado' => 'confirmada',
    'fecha' => '2026-08-08',
    'hora' => '13:00:00',
    'mesa_ids' => [4],
    'comensales' => 2,
];

$evaluar = static function (string $hora) use ($reservacion): array {
    $reloj = new DateTimeImmutable('2026-08-08 ' . $hora, ReservacionConfig::timezone());
    $politica = ReservacionPoliticaPosService::evaluar($reservacion, $reloj, null, $reloj);
    return [
        'politica' => $politica,
        'visual' => PosMesaProjectionPresenter::presentar([
            'nombre' => 'Mesa 4',
            'utilizable' => true,
            'ocupada_fisicamente' => false,
            'ticket_abierto' => false,
            'reservacion' => array_merge($reservacion, $politica),
        ]),
    ];
};

// hora => [estado, modificador, permiso de ticket, advertencia, subrótulo]
$esperados = [
    '11:59:59' => ['libre', null, true, false, ''],
    '12:00:00' => ['reservacion-proxima', 'ventana_advertencia', true, true, 'en 60 min'],
    '12:15:00' => ['reservacion-proxima', 'ventana_advertencia', true, true, 'en 45 min'],
    '12:29:00' => ['reservacion-proxima', 'ventana_advertencia', true, true, 'en 31 min'],
    '12:30:00' => ['reservacion-proxima', 'ventana_bloqueo', false, false, 'en 30 min'],
    '12:59:59' => ['reservacion-proxima', 'ventana_bloqueo', false, false, 'en 1 min'],
    '13:00:00' => ['reservacion-proxima', 'ventana_inicio', false, false, 'Llega ahora'],
    '13:01:00' => ['reservacion-proxima', 'ventana_tolerancia', false, false, 'Esperando'],
    '13:15:00' => ['reservacion-proxima', 'ventana_tolerancia', false, false, 'Esperando'],
    '13:15:01' => ['reservacion-proxima', 'sin_llegar', false, false, 'Se libera'],
];

foreach ($esperados as $hora => [$estado, $modificador, $ticketable, $advertencia, $subtitulo]) {
    $resultado = $evaluar($hora);
    $politica = $resultado['politica'];
    $visual = $resultado['visual'];
    assertPosVisualContract($visual['estado_visual'] === $estado, "estado POS {$hora}");
    assertPosVisualContract(
        $modificador === null || in_array($modificador, $visual['modificadores'], true),
        "modificador POS {$hora}"
    );
    assertPosVisualContract($visual['subtitulo'] === $subtitulo, "subrótulo POS {$hora}: «{$visual['subtitulo']}»");
    assertPosVisualContract(
        $visual['etiqueta'] === ($estado === 'libre' ? 'Libre' : 'Reservada'),
        "etiqueta POS {$hora}"
    );
    assertPosVisualContract(str_starts_with($visual['aria_label'], 'Mesa 4. '), "la lectura en voz nombra la mesa {$hora}");
    assertPosVisualContract($politica['disponible_para_ticket'] === $ticketable, "permiso ticket {$hora}");
    assertPosVisualContract($politica['requiere_advertencia_ticket'] === $advertencia, "advertencia {$hora}");
}

// Ticket abierto: rojo, y si llega una reserva a esa mesa se avisa en el subrótulo.
$conTicket = PosMesaProjectionPresenter::presentar([
    'utilizable' => true,
    'ocupada_fisicamente' => true,
    'ticket_abierto' => true,
    'reservacion' => array_merge($reservacion, ['ventana_visual_pos' => 'inicio']),
]);
assertPosVisualContract($conTicket['estado_visual'] === 'ocupada', 'ticket abierto siempre es rojo');
assertPosVisualContract(in_array('ticket_abierto', $conTicket['modificadores'], true), 'ticket conserva precedencia');
// Sólo tres estados: ni aro azul ni «Reserva 13:00» sobre el rojo. La
// reserva se reubica sola (ReservacionReubicacionPorTicketService).
assertPosVisualContract(!in_array('reserva_en_espera', $conTicket['modificadores'], true), 'una mesa ocupada no lleva señal compuesta de reserva');
assertPosVisualContract($conTicket['subtitulo'] === '', 'la mesa ocupada no anuncia la reserva: se reubica sola');
assertPosVisualContract($conTicket['etiqueta'] === 'Ocupada', 'la mesa ocupada se rotula «Ocupada»');

// Barra: se llena por tickets hasta su tope; nunca azul.
$barra = static fn(int $abiertos): array => PosMesaProjectionPresenter::presentar([
    'nombre' => 'Barra Blanca',
    'utilizable' => false,
    'multi_ticket' => true,
    'tickets_elemento' => $abiertos,
    'tope_tickets' => 4,
    'etiqueta_operacion_pos' => 'Barra',
]);
assertPosVisualContract($barra(0)['estado_visual'] === 'libre' && $barra(0)['subtitulo'] === '', 'barra vacía: libre y sin conteo');
assertPosVisualContract($barra(2)['estado_visual'] === 'libre' && $barra(2)['subtitulo'] === '2/4', 'barra con 2: libre y 2/4');
assertPosVisualContract(in_array('ticket_abierto', $barra(2)['modificadores'], true), 'barra con tickets lleva el modificador');
assertPosVisualContract($barra(4)['estado_visual'] === 'ocupada' && $barra(4)['subtitulo'] === '4/4', 'barra en el tope: ocupada y 4/4');
assertPosVisualContract($barra(4)['etiqueta'] === 'Llena', 'barra en el tope se rotula «Llena»');
assertPosVisualContract($barra(5)['estado_visual'] === 'ocupada', 'una barra por encima del tope sigue llena');

// Caja y Llevar: fichas sin estado.
$caja = PosMesaProjectionPresenter::presentar(['nombre' => 'Caja', 'ficha' => true, 'utilizable' => false]);
assertPosVisualContract($caja['ficha'] === true && $caja['etiqueta'] === '' && $caja['subtitulo'] === '', 'Caja es una ficha sin estado');
$llevar = PosMesaProjectionPresenter::presentar([
    'nombre' => 'Llevar',
    'ficha' => true,
    'multi_ticket' => true,
    'tickets_elemento' => 3,
    'utilizable' => false,
]);
assertPosVisualContract($llevar['ficha'] === true && $llevar['subtitulo'] === '3 pedidos', 'Llevar dice cuántos pedidos lleva');
$llevarUno = PosMesaProjectionPresenter::presentar(['ficha' => true, 'multi_ticket' => true, 'tickets_elemento' => 1, 'utilizable' => false]);
assertPosVisualContract($llevarUno['subtitulo'] === '1 pedido', 'un pedido va en singular');

// Restricción operativa sin otra causa: no disponible.
$restringida = PosMesaProjectionPresenter::presentar(['utilizable' => true, 'puede_abrir_ticket' => false]);
assertPosVisualContract($restringida['estado_visual'] === 'ocupada'
    && in_array('restriccion_operativa', $restringida['modificadores'], true), 'una regla operativa pinta no disponible');

// Hold vigente: una reserva a medio confirmar aparta la mesa en azul.
$hold = PosMesaProjectionPresenter::presentar(['utilizable' => true, 'hold_vigente' => true, 'puede_abrir_ticket' => false]);
assertPosVisualContract($hold['estado_visual'] === 'reservacion-proxima' && $hold['subtitulo'] === 'Apartada'
    && !in_array('restriccion_operativa', $hold['modificadores'], true), 'un hold se pinta azul «Apartada», no rojo');

// ── Proyección: el mapa a una hora consultada desde el cajón ──────────
// La reserva de las 13:00 vista desde el reloj de las 09:00 (lejísimos) pero
// proyectando a otras horas: manda la hora consultada, no el reloj.
$proyectar = static function (string $horaConsulta, array $extra = []) use ($reservacion): array {
    $reloj = new DateTimeImmutable('2026-08-08 09:00:00', ReservacionConfig::timezone());
    $consulta = new DateTimeImmutable('2026-08-08 ' . $horaConsulta, ReservacionConfig::timezone());
    $politica = ReservacionPoliticaPosService::evaluar($reservacion, $reloj, null, $consulta);
    $mapa = (array)$politica['proyeccion_mapa'];
    return PosMesaProjectionPresenter::presentar($extra + [
        'utilizable' => true,
        'proyeccion' => true,
        'puede_abrir_ticket' => true,
        'reservacion' => array_merge($reservacion, $politica, ['ventana_mapa' => $mapa['ventana_mapa']]),
    ]);
};
$antes = $proyectar('11:30:00');
assertPosVisualContract($antes['estado_visual'] === 'libre', 'proyección: 90 min antes la mesa sigue libre');
$hora1215 = $proyectar('12:15:00');
assertPosVisualContract($hora1215['estado_visual'] === 'reservacion-proxima' && $hora1215['subtitulo'] === '13:00',
    'proyección: 45 min antes ya es azul y dice la HORA, no «en N min»');
$hora1330 = $proyectar('13:30:00');
assertPosVisualContract($hora1330['estado_visual'] === 'reservacion-proxima', 'proyección: dentro del intervalo sigue azul');
$hora1430 = $proyectar('14:30:00');
assertPosVisualContract($hora1430['estado_visual'] === 'libre', 'proyección: al acabar los 90 min vuelve a libre');
$ticketQueSeVa = $proyectar('12:15:00', ['ticket_abierto' => true, 'ocupada_fisicamente' => true, 'ticket_bloquea_consulta' => false]);
assertPosVisualContract($ticketQueSeVa['estado_visual'] === 'reservacion-proxima',
    'proyección: un ticket que se habrá liberado no pinta de rojo');
$ticketQueSigue = $proyectar('12:15:00', ['ticket_abierto' => true, 'ticket_bloquea_consulta' => true]);
assertPosVisualContract($ticketQueSigue['estado_visual'] === 'ocupada'
    && !in_array('reserva_en_espera', $ticketQueSigue['modificadores'], true),
    'proyección: el ticket que sigue ocupando gana, sin estado compuesto');
$sinReglasDeAhora = $proyectar('11:00:00', ['puede_abrir_ticket' => false]);
assertPosVisualContract($sinReglasDeAhora['estado_visual'] === 'libre', 'proyección: las reglas de ahora no tiñen la mesa');

fwrite(STDOUT, "POS: tres estados, barras, fichas y proyección OK\n");
