<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\ReservacionErrorCatalog;
use Services\ReservacionMapaMesaPresenter;
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
    'ASIGNACION_MANUAL_REQUERIDA',
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
assertContract($sinAsignacion['tipo'] === ReservacionErrorCatalog::TIPO_DECISION, 'SIN_ASIGNACION conserva decision requerida');
assertContract($sinAsignacion['titulo'] === 'Asignación de mesas pendiente', 'SIN_ASIGNACION usa titulo contractual');
assertContract($sinAsignacion['descripcion'] === 'No se encontró una combinación automática de mesas para esta reservación.', 'SIN_ASIGNACION usa descripcion contractual');
assertContract($sinAsignacion['consecuencia'] === 'Puedes crearla sin mesas y realizar la asignación manualmente después.', 'SIN_ASIGNACION usa consecuencia contractual');
assertContract($sinAsignacion['commit'] === false, 'SIN_ASIGNACION no confirma el commit');
assertContract($sinAsignacion['acciones'][0]['id'] === 'VOLVER', 'SIN_ASIGNACION ofrece volver primero');
assertContract($sinAsignacion['acciones'][1]['id'] === 'CONFIRMAR_SIN_MESAS', 'SIN_ASIGNACION ofrece confirmar despues');
assertContract($sinAsignacion['acciones'][0]['label'] === 'Volver', 'SIN_ASIGNACION etiqueta volver');
assertContract($sinAsignacion['acciones'][1]['label'] === 'Asignar más tarde', 'SIN_ASIGNACION etiqueta asignar mas tarde');
assertContract(stripos($sinAsignacion['mensaje'], 'cupo') === false, 'SIN_ASIGNACION no usa lenguaje de cupo');

$manual = ReservacionErrorCatalog::presentar('ASIGNACION_MANUAL_REQUERIDA', [
    'comensales' => 14,
]);
assertContract($manual['tipo'] === ReservacionErrorCatalog::TIPO_DECISION, 'ASIGNACION_MANUAL_REQUERIDA es decision');
assertContract($manual['commit'] === false, 'ASIGNACION_MANUAL_REQUERIDA no confirma el commit');
assertContract($manual['titulo'] === 'Asignación manual de mesas', 'ASIGNACION_MANUAL_REQUERIDA usa titulo especifico');
assertContract($manual['descripcion'] === 'Las reservaciones de más de 12 personas requieren asignar las mesas manualmente.', 'ASIGNACION_MANUAL_REQUERIDA usa descripcion especifica');
assertContract($manual['consecuencia'] === 'Puedes crear la reservación ahora y asignar las mesas posteriormente desde el mapa de reservaciones.', 'ASIGNACION_MANUAL_REQUERIDA usa consecuencia especifica');
assertContract($manual['acciones'][1]['label'] === 'Asignar más tarde', 'ASIGNACION_MANUAL_REQUERIDA etiqueta asignar mas tarde');

$withoutContact = ReservacionErrorCatalog::presentar('REQUIERE_CONFIRMACION_SIN_CONTACTO');
assertContract($withoutContact['titulo'] === 'Crear reservación sin contacto', 'sin contacto usa titulo especifico');
assertContract($withoutContact['descripcion'] === 'No se agregó un correo electrónico ni teléfono para esta reservación.', 'sin contacto usa descripcion especifica');
assertContract($withoutContact['consecuencia'] === 'La reservación podrá crearse, pero no habrá un medio de contacto asociado al cliente.', 'sin contacto usa consecuencia especifica');
assertContract($withoutContact['acciones'][1]['id'] === 'CONFIRMAR_SIN_CONTACTO', 'sin contacto usa accion canonica');
assertContract($withoutContact['acciones'][1]['label'] === 'Crear sin contacto', 'sin contacto etiqueta accion canonica');

$creada = ReservacionErrorCatalog::presentar('RESERVACION_CREADA', [
    'reservacion_id' => 77,
    'mesa_ids' => [3, 4],
]);
assertContract($creada['tipo'] === ReservacionErrorCatalog::TIPO_EXITO, 'RESERVACION_CREADA usa tipo exito');
assertContract($creada['commit'] === true, 'RESERVACION_CREADA confirma el commit');
assertContract($creada['mensaje'] === 'Reservación creada', 'RESERVACION_CREADA usa mensaje de exito');
assertContract($creada['consecuencia'] === 'La reservación quedó confirmada con sus mesas asignadas.', 'RESERVACION_CREADA usa consecuencia de exito');

$reconciliada = ReservacionErrorCatalog::enriquecer([
    'ok' => true,
    'codigo' => 'RESERVACION_CREADA',
    'commit' => false,
]);
assertContract($reconciliada['commit'] === true, 'enriquecer reconcilia commit contradictorio');
assertContract($reconciliada['tipo'] === ReservacionErrorCatalog::TIPO_EXITO, 'enriquecer conserva tipo canonico de exito');

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

$resultadoContextual = ReservacionErrorCatalog::contextoResultado([
    'comensales_solicitados' => 9,
    'capacidad_disponible' => 5,
]);
assertContract($resultadoContextual['comensales'] === 9, 'contexto de resultado expone comensales');

assertContract(
    ReservacionMapaMesaPresenter::presentar(['utilizable' => true])['estado_visual'] === 'libre',
    'presenter mapa pinta mesa disponible en verde'
);

fwrite(STDOUT, "Reservaciones: catalogo contractual OK\n");
