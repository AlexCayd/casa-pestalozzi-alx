<?php

declare(strict_types=1);

/**
 * Contratos de Etapa 7.2: creación desde mapa, advertencia POS y ciclo de
 * vida del shell ConfirmationModal.
 *
 * El modo normal es estático y no toca la base activa. --dynamic delega el
 * smoke HTTP autenticado existente, que trabaja contra una base temporal.
 */

date_default_timezone_set('America/Mexico_City');

$root = dirname(__DIR__, 2);
$dynamic = in_array('--dynamic', $argv, true);
$fallos = [];
$afirmar = static function (bool $condicion, string $mensaje) use (&$fallos): void {
    if (!$condicion) {
        $fallos[] = $mensaje;
    }
};
$leer = static function (string $ruta) use ($root): string {
    return (string)(file_get_contents($root . '/' . ltrim($ruta, '/')) ?: '');
};

$vistaMapa = $leer('views/operation/reservations/index.php');
$operacion = $leer('src/js/admin/reservations/operation.js');
$formulario = $leer('src/js/admin/reservations/form.js');
$pos = $leer('src/js/modules/punto-de-venta.js');
$modal = $leer('src/js/components/confirmation-modal.js');
$servicioPos = $leer('services/PuntoVentaReservacionService.php');
$catalogo = $leer('services/ReservacionErrorCatalog.php');
$publico = $leer('src/js/modules/reservation-access.js');
$bundleOperacion = $leer('public/build/js/admin/reservation-operation.js');
$bundleFormulario = $leer('public/build/js/admin/reservation-form.js');
$bundlePos = $leer('public/build/js/admin/map.js');

// A: creación desde mapa y barra de selección con dos acciones exactas.
$afirmar(str_contains($vistaMapa, 'data-operation-create'), 'A1: falta el disparador Crear reservación del mapa.');
$afirmar(str_contains($vistaMapa, 'data-operation-create-modal'), 'A1: falta el dialog nativo de creación.');
$afirmar(str_contains($operacion, 'createForm.addEventListener(\'reservation:jsonsubmit\', submitCreateModal)'), 'A2: el alta desde mapa no conserva el listener JSON único.');
$afirmar(str_contains($operacion, 'assignmentLater: payload.requiresManualAssignment === true'), 'A3: el alta no conserva el estado de asignación manual posterior.');
$afirmar(str_contains($operacion, 'allowAssignLater: options.assignmentLater === true'), 'A3: el modo de selección no distingue Asignar más tarde.');
$afirmar(str_contains($formulario, 'data-confirmations-accepted'), 'A4: las confirmaciones aceptadas no se conservan hasta el submit.');
$afirmar(str_contains($formulario, 'syncContactField(false)'), 'A4: el reset no sincroniza el contacto opcional.');

$inicioBarra = strpos($vistaMapa, 'data-operation-assignment-bar');
$finBarra = $inicioBarra === false ? false : strpos($vistaMapa, '</section>', $inicioBarra);
$barra = $inicioBarra === false || $finBarra === false ? '' : substr($vistaMapa, $inicioBarra, $finBarra - $inicioBarra);
$afirmar(substr_count($barra, '<button') === 2, 'A5: la barra de selección debe tener exactamente dos botones.');
$afirmar(!str_contains($barra, 'data-operation-clear'), 'A5: la barra de selección conserva un botón Liberar asignación.');
$afirmar(str_contains($barra, 'data-operation-assignment-cancel'), 'A5: falta Cancelar/Asignar más tarde.');
$afirmar(str_contains($barra, 'data-operation-assignment-save'), 'A5: falta Guardar asignación.');
$afirmar(str_contains($operacion, "state.assignmentCancelLabel = options.allowAssignLater ? 'Asignar más tarde' : 'Cancelar'"), 'A6: el botón secundario no cambia a Asignar más tarde cuando corresponde.');
$afirmar(
    str_contains($operacion, "els.assignmentSave.textContent = 'Guardar asignación'")
        || str_contains($operacion, "saveButton.textContent = 'Guardar asignación'"),
    'A6: el botón principal de la barra no usa Guardar asignación.'
);

// B: advertencia POS de reservación próxima, con confirmación explícita.
$afirmar(str_contains($pos, "result.codigo === 'REQUIERE_CONFIRMACION' && result.advertencia"), 'B1: falta la rama POS de reservación próxima.');
$afirmar(str_contains($pos, "title: 'Hay una reservación próxima'"), 'B2: falta el título de la advertencia POS.');
$afirmar(str_contains($pos, "cancelLabel: 'Volver'"), 'B2: la salida POS no usa Volver.');
$afirmar(str_contains($pos, "confirmLabel: 'Abrir ticket de todas formas'"), 'B2: falta la acción primaria explícita del POS.');
$afirmar(str_contains($pos, 'payload.confirmar_reservacion_proxima = 1'), 'B3: el POS no envía confirmar_reservacion_proxima=1.');
$afirmar(str_contains($servicioPos, 'confirmar_reservacion_proxima'), 'B4: el backend POS no clasifica la confirmación próxima.');
$afirmar(str_contains($catalogo, "'REQUIERE_CONFIRMACION'"), 'B4: el catálogo no declara REQUIERE_CONFIRMACION.');
$afirmar(str_contains($bundlePos, 'confirmar_reservacion_proxima'), 'B5: el bundle POS no contiene la confirmación explícita.');

// C: ConfirmationModal limpio, reabrible y resoluble en todas las salidas.
$afirmar(substr_count($modal, 'window.ConfirmationModal =') === 1, 'C1: hay más de una definición global de ConfirmationModal.');
foreach ([
    'return new Promise',
    'var resolveOpen = null',
    "action: 'reopened'",
    "action: 'secondary'",
    "action: 'backdrop'",
    "action: 'escape'",
    "action: 'error'",
    'No fue posible completar la acción',
    'setLoading(false)',
    'primary.removeAttribute(\'data-disabled\')',
] as $contrato) {
    $afirmar(str_contains($modal, $contrato), "C2: falta el contrato de ciclo {$contrato}.");
}
$afirmar(str_contains($modal, '(activeDialog || document.body || host).appendChild(root)'), 'C3: el shell no respeta el top layer del dialog nativo.');
$afirmar(str_contains($bundleOperacion, 'No fue posible completar la acción'), 'C4: el bundle operativo no contiene el estado de error retryable.');
$afirmar(str_contains($bundleFormulario, 'data-confirmations-accepted'), 'C4: el bundle de formulario no contiene el transporte de confirmaciones.');

// D: consumidores conocidos conservan sus variantes de causa y consecuencia.
foreach ([
    'Conflicto de asignación',
    'La capacidad de las mesas es insuficiente',
    'Hay una reservación próxima',
    'Registrar que el cliente no se presentó',
    'Liberar asignación',
] as $titulo) {
    $afirmar(str_contains($operacion . $pos, $titulo), "D1: falta el consumidor operativo {$titulo}.");
}
$afirmar(
    str_contains($publico, 'title: "Confirma la nueva reservaci')
        && str_contains($publico, 'primaryLabel: "Confirmar modificaci'),
    'D2: falta el consumidor público de creación/modificación.'
);

if ($dynamic) {
    $runner = $root . '/scripts/tests/run-etapa6-flujos-autenticados.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' --dynamic';
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $fallos[] = 'DYNAMIC: falló el smoke HTTP autenticado: ' . implode(' | ', array_slice($output, -4));
    } else {
        fwrite(STDOUT, "DYNAMIC: smoke HTTP autenticado aprobado.\n");
    }
}

if ($fallos !== []) {
    fwrite(STDERR, "FAIL: contratos de creación y modales de Etapa 7.2\n");
    foreach ($fallos as $fallo) {
        fwrite(STDERR, '- ' . $fallo . "\n");
    }
    exit(1);
}

fwrite(STDOUT, 'PASS: contratos de creación y modales de Etapa 7.2' . ($dynamic ? ' + dynamic' : '') . "\n");
