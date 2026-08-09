<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\ReservacionAdministrativaService;

/** @param mixed $condition */
function assertAdministrativeContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$reflection = new ReflectionClass(ReservacionAdministrativaService::class);
$warningCodes = $reflection->getMethod('warningCodes');
$warningCodes->setAccessible(true);
$respuestaAdvertencias = $reflection->getMethod('respuestaAdvertencias');
$respuestaAdvertencias->setAccessible(true);

$evaluacionSuficiente = [
    'capacidad_estimada_suficiente' => true,
    'asignacion_automatica_posible' => false,
    'capacidad_estimada' => 20,
];
$datosSinContacto = [
    'contacto_tipo' => 'ninguno',
    'contacto' => '',
    'comensales' => 14,
    'asignacion_automatica_solicitada' => false,
];

$warnings = $warningCodes->invoke(null, $datosSinContacto, $evaluacionSuficiente, false, false);
assertAdministrativeContract(
    $warnings === [
        ReservacionAdministrativaService::REQUIERE_CONFIRMACION_SIN_CONTACTO,
        ReservacionAdministrativaService::ASIGNACION_MANUAL_REQUERIDA,
    ],
    '14 personas sin contacto devuelve decisiones separables y ordenadas'
);

$primeraDecision = $respuestaAdvertencias->invoke(
    null,
    [$warnings[0]],
    $evaluacionSuficiente,
    $datosSinContacto
);
assertAdministrativeContract($primeraDecision['ok'] === true, 'decision administrativa comprendida usa ok=true');
assertAdministrativeContract($primeraDecision['commit'] === false, 'decision administrativa no confirma commit');
assertAdministrativeContract($primeraDecision['codigo'] === ReservacionAdministrativaService::REQUIERE_CONFIRMACION_SIN_CONTACTO, 'servidor entrega primero sin contacto');
assertAdministrativeContract($primeraDecision['confirmaciones_requeridas'] === [$warnings[0]], 'servidor entrega una sola decision pendiente');

$manual = $warningCodes->invoke(null, [
    'contacto_tipo' => 'email',
    'contacto' => 'cliente@example.com',
    'comensales' => 14,
    'asignacion_automatica_solicitada' => false,
], $evaluacionSuficiente, false, false);
assertAdministrativeContract($manual === [ReservacionAdministrativaService::ASIGNACION_MANUAL_REQUERIDA], '14 personas con contacto requiere asignacion manual');

$evaluacionAsignable = $evaluacionSuficiente;
$evaluacionAsignable['asignacion_automatica_posible'] = true;
$sinCombinacion = $warningCodes->invoke(null, [
    'contacto_tipo' => 'email',
    'contacto' => 'cliente@example.com',
    'comensales' => 8,
    'asignacion_automatica_solicitada' => true,
], $evaluacionAsignable, true, false);
assertAdministrativeContract($sinCombinacion === [], 'asignacion automatica exitosa no abre decision');

$sinCombinacionReal = $warningCodes->invoke(null, [
    'contacto_tipo' => 'email',
    'contacto' => 'cliente@example.com',
    'comensales' => 8,
    'asignacion_automatica_solicitada' => true,
], $evaluacionSuficiente, false, false);
assertAdministrativeContract($sinCombinacionReal === [ReservacionAdministrativaService::SIN_ASIGNACION], 'hasta 12 sin combinacion usa SIN_ASIGNACION');

$capacidadInsuficiente = $warningCodes->invoke(null, [
    'contacto_tipo' => 'email',
    'contacto' => 'cliente@example.com',
    'comensales' => 8,
    'asignacion_automatica_solicitada' => true,
], [
    'capacidad_estimada_suficiente' => false,
    'asignacion_automatica_posible' => false,
], false, false);
assertAdministrativeContract($capacidadInsuficiente === [ReservacionAdministrativaService::CAPACIDAD_OPERATIVA_EXCEDIDA], 'capacidad insuficiente conserva su decision especifica');

fwrite(STDOUT, "Reservaciones: decisiones administrativas OK\n");
