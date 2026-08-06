<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\ReservacionErrorCatalog;
use Services\PosReservacionSerializer;

/** @param mixed $condition */
function assertContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$required = [
    'SIN_ASIGNACION',
    'CAPACIDAD_OPERATIVA_EXCEDIDA',
    'CAPACIDAD_INSUFICIENTE',
    'RESERVACION_PROXIMA',
];

foreach ($required as $code) {
    $presentation = ReservacionErrorCatalog::presentar($code, [
        'hora' => '19:30',
        'minutos_restantes' => 25,
        'comensales_solicitados' => 8,
        'capacidad_disponible' => 4,
        'lugares_faltantes' => 4,
    ]);
    assertContract($presentation['codigo'] === $code, "{$code} conserva su codigo canonico");
    assertContract($presentation['codigo_canonico'] === $code, "{$code} expone codigo_canonico");
    assertContract($presentation['contexto']['hora'] === '19:30' || $code !== 'RESERVACION_PROXIMA', "{$code} conserva contexto");
    assertContract($presentation['mensaje'] !== '', "{$code} tiene mensaje");
    assertContract($presentation['descripcion'] !== '', "{$code} tiene descripcion");
    assertContract($presentation['consecuencia'] !== '', "{$code} tiene consecuencia");
    assertContract($presentation['acciones'] !== [], "{$code} tiene acciones");
    foreach ($presentation['acciones'] as $action) {
        assertContract(($action['label'] ?? '') !== '', "{$code} no deja acciones sin label");
    }
    if ($code !== 'CAPACIDAD_INSUFICIENTE') {
        assertContract($presentation['commit'] === false, "{$code} no confirma por accidente");
    }
}

$sinAsignacion = ReservacionErrorCatalog::presentar('SIN_ASIGNACION', [
    'comensales_solicitados' => 6,
    'capacidad_disponible' => 6,
    'asignacion_automatica_solicitada' => true,
]);
assertContract($sinAsignacion['http_status'] === 409, 'SIN_ASIGNACION usa HTTP 409');
assertContract($sinAsignacion['acciones'][0]['id'] === 'VOLVER', 'SIN_ASIGNACION ofrece volver primero');
assertContract($sinAsignacion['acciones'][1]['id'] === 'CONFIRMAR_SIN_MESAS', 'SIN_ASIGNACION ofrece confirmar despues');
assertContract(stripos($sinAsignacion['mensaje'], 'cupo') === false, 'SIN_ASIGNACION no usa lenguaje de cupo');

$decisions = ReservacionErrorCatalog::decisiones(
    ['SIN_ASIGNACION', 'CAPACIDAD_OPERATIVA_EXCEDIDA'],
    ['comensales_solicitados' => 12, 'capacidad_disponible' => 8]
);
assertContract(count($decisions) === 2, 'decisiones devuelve una presentacion por codigo');
assertContract(isset($decisions[0]['contexto']['comensales_solicitados']), 'decisiones propaga contexto');

$unknown = ReservacionErrorCatalog::presentar('CODIGO_INTERNO_DE_PRUEBA');
assertContract($unknown['codigo'] === 'ERROR_INTERNO', 'codigo desconocido se degrada a ERROR_INTERNO');
assertContract(stripos(json_encode($unknown, JSON_UNESCAPED_UNICODE), 'CODIGO_INTERNO_DE_PRUEBA') === false, 'codigo desconocido no llega a la UI');

$enriched = ReservacionErrorCatalog::enriquecer([
    'ok' => false,
    'codigo' => 'RESERVACION_PROXIMA',
    'contexto' => ['hora' => '20:00', 'minutos_restantes' => 30],
]);
assertContract($enriched['codigo'] === 'RESERVACION_PROXIMA', 'enriquecer conserva codigo canonico');
assertContract($enriched['descripcion'] !== '', 'enriquecer incluye descripcion');

$blockers = PosReservacionSerializer::bloqueosOperativos(
    [],
    [1],
    [['id' => 1, 'numero' => 7, 'activo' => 1, 'reservable' => 0, 'tipo' => 'mesa', 'capacidad' => 4]]
);
assertContract($blockers[0]['codigo'] === 'MESA_NO_RESERVABLE', 'mesa no utilizable usa codigo canonico');
assertContract(isset($blockers[0]['presentacion']['mensaje']), 'bloqueo de mesa incluye presentacion');
assertContract(!array_key_exists('motivo', $blockers[0]), 'bloqueo de mesa no expone motivo interno');

fwrite(STDOUT, "Reservaciones: catalogo contractual OK\n");
