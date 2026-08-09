<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\PosMesaProjectionPresenter;
use Services\ReservacionConfig;
use Services\ReservacionPoliticaPosService;

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
    $reloj = new DateTimeImmutable(
        '2026-08-08 ' . $hora,
        ReservacionConfig::timezone()
    );
    $politica = ReservacionPoliticaPosService::evaluar($reservacion, $reloj, null, $reloj);
    return [
        'politica' => $politica,
        'visual' => PosMesaProjectionPresenter::presentar([
            'utilizable' => true,
            'ocupada_fisicamente' => false,
            'ticket_abierto' => false,
            'reservacion' => array_merge($reservacion, $politica),
        ]),
    ];
};

$esperados = [
    '12:00:00' => ['libre', 'reservacion_advertencia', true, true],
    '12:15:00' => ['libre', 'reservacion_advertencia', true, true],
    '12:29:00' => ['libre', 'reservacion_advertencia', true, true],
    '12:30:00' => ['reservacion-proxima', 'reservacion_inminente', false, false],
    '12:59:00' => ['reservacion-proxima', 'reservacion_inminente', false, false],
    '13:00:00' => ['reservacion-proxima', 'reservacion_bloqueante', false, false],
    '13:01:00' => ['reservacion-proxima', 'reservacion_tolerancia', false, false],
    '13:15:00' => ['reservacion-proxima', 'reservacion_tolerancia', false, false],
    '13:15:01' => ['libre', 'ausencia_pendiente', false, false],
];

foreach ($esperados as $hora => [$estado, $modificador, $ticketable, $advertencia]) {
    $resultado = $evaluar($hora);
    $politica = $resultado['politica'];
    $visual = $resultado['visual'];
    assertPosVisualContract(
        $visual['estado_visual'] === $estado,
        "estado POS {$hora}"
    );
    assertPosVisualContract(
        in_array($modificador, $visual['modificadores'], true),
        "modificador POS {$hora}"
    );
    assertPosVisualContract(
        $politica['disponible_para_ticket'] === $ticketable,
        "permiso ticket {$hora}"
    );
    assertPosVisualContract(
        $politica['requiere_advertencia_ticket'] === $advertencia,
        "advertencia {$hora}"
    );
}

$conTicket = PosMesaProjectionPresenter::presentar([
    'utilizable' => true,
    'ocupada_fisicamente' => true,
    'ticket_abierto' => true,
    'reservacion' => array_merge($reservacion, [
        'ventana_visual_pos' => 'inicio',
    ]),
]);
assertPosVisualContract($conTicket['estado_visual'] === 'ocupada', 'ticket abierto siempre es rojo');
assertPosVisualContract(in_array('ticket_abierto', $conTicket['modificadores'], true), 'ticket conserva precedencia');

fwrite(STDOUT, "POS: simbologia antes e inicio de reservacion OK\n");
