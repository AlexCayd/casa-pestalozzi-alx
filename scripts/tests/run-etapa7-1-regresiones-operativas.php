<?php

declare(strict_types=1);

/**
 * Contratos de regresión para las superficies administrativas y operativas.
 *
 * El modo normal valida que el código fuente y el catálogo mantengan una sola
 * decisión por causa. --dynamic delega el smoke HTTP autenticado en el runner
 * de Etapa 6, que usa el front controller y una base temporal.
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

$formulario = $leer('src/js/admin/reservations/form.js');
$operacion = $leer('src/js/admin/reservations/operation.js');
$modal = $leer('src/js/components/confirmation-modal.js');
$estilos = $leer('src/scss/components/_confirmation-modal.scss');
$servicio = $leer('services/ReservacionAdministrativaService.php');
$catalogoFuente = $leer('services/ReservacionErrorCatalog.php');
$vistaFormulario = $leer('views/admin/reservations/_form.php');
$vistaOperacion = $leer('views/operation/reservations/index.php');
$controladorOperacion = $leer('controllers/ReservacionOperacionController.php');
$normativa = $leer('reservaciones_fuente_de_verdad.md');

require_once $root . '/vendor/autoload.php';

use Services\ReservacionErrorCatalog;

$capacidad = ReservacionErrorCatalog::presentar('CAPACIDAD_INSUFICIENTE');
$sinAsignacion = ReservacionErrorCatalog::presentar('SIN_ASIGNACION');
$accionesCapacidad = array_column((array)$capacidad['acciones'], 'label', 'id');
$accionesSinAsignacion = array_column((array)$sinAsignacion['acciones'], 'label', 'id');

// B1: más de 12 personas y confirmación sin mesas.
$afirmar(str_contains($vistaFormulario, 'data-auto-disabled'), 'B1: el formulario no declara el estado de deshabilitación automática.');
$afirmar(str_contains($vistaFormulario, 'Asignar mesas automáticamente'), 'B1: falta la etiqueta canónica del checkbox.');
$afirmar(str_contains($formulario, "? 'Asignar más tarde'"), 'B1: falta la acción Asignar más tarde.');
$afirmar(str_contains($formulario, "requiresManualAssignment\n                            ? 'Asignar más tarde'"), 'B1: la acción primaria manual debe ser Asignar más tarde.');
$afirmar(str_contains($formulario, "backLabel: requiresManualAssignment ? 'Volver'"), 'B1: la salida del modal manual no usa Volver.');
$afirmar(!str_contains($formulario, 'Confirmar sin mesas\'') || str_contains($formulario, "title: exceedsCapacity"), 'B1: Confirmar sin mesas no debe ser la acción primaria de posponer.');
$afirmar(!str_contains($vistaOperacion, 'Asignar mesas después') && !str_contains($operacion, 'Asignar mesas después'), 'B1: permanece la acción duplicada Asignar después.');
$afirmar(($accionesSinAsignacion['CONFIRMAR_SIN_MESAS'] ?? '') === 'Asignar más tarde', 'B1: el catálogo no etiqueta SIN_ASIGNACION como Asignar más tarde.');
$afirmar(($accionesSinAsignacion['VOLVER'] ?? '') === 'Volver', 'B1: el catálogo no etiqueta la salida como Volver.');

// B2/B3: la capacidad manual y los tickets tienen códigos y presentaciones distintas.
$afirmar(($capacidad['titulo'] ?? '') === 'La capacidad de las mesas es insuficiente', 'B2: título de capacidad incorrecto.');
$afirmar(($capacidad['mensaje'] ?? '') === 'Las mesas seleccionadas no tienen suficientes lugares para esta reservación.', 'B2: descripción de capacidad incorrecta.');
$afirmar(($capacidad['consecuencia'] ?? '') === 'Selecciona mesas con mayor capacidad antes de guardar la asignación.', 'B2: consecuencia de capacidad incorrecta.');
$afirmar(($accionesCapacidad['VOLVER_A_SELECCIONAR'] ?? '') === 'Volver a seleccionar', 'B2: falta Volver a seleccionar.');
$afirmar(($accionesCapacidad['GUARDAR_DE_TODAS_FORMAS'] ?? '') === 'Guardar de todas formas', 'B2: falta Guardar de todas formas.');
$afirmar(!str_contains((string)($capacidad['mensaje'] ?? ''), 'ticket'), 'B2: capacidad contiene una referencia a tickets.');
$afirmar(str_contains($operacion, "error.codigo === 'CAPACIDAD_INSUFICIENTE'"), 'B2: no existe una rama frontend exclusiva para capacidad.');
$afirmar(str_contains($operacion, "title: 'La capacidad de las mesas es insuficiente'"), 'B2: el modal operativo no usa el contrato de capacidad.');
$afirmar(str_contains($operacion, "title: 'Conflicto de asignación'"), 'B3: falta la presentación de conflicto de tickets.');
$capacityBranch = strpos($operacion, "error.codigo === 'CAPACIDAD_INSUFICIENTE'");
$ticketBranch = strpos($operacion, "CONFLICTO_TICKETS_ABIERTOS");
$afirmar($capacityBranch !== false && $ticketBranch !== false && $capacityBranch < $ticketBranch, 'B2/B3: la rama de capacidad debe preceder a la de tickets.');

// B4/B5/B6: el checkbox llega al servicio como booleano real.
$afirmar(substr_count($servicio, 'FILTER_VALIDATE_BOOLEAN') >= 2, 'B4/B5: el backend no normaliza ambos caminos de asignación automática.');
$afirmar(str_contains($servicio, "\$post['asignar_automaticamente'] ?? false"), 'B4/B5: el valor ausente no tiene default falso explícito.');
$afirmar(str_contains($vistaFormulario, 'name="asignar_automaticamente"'), 'B4/B5: el formulario no envía asignar_automaticamente.');
$afirmar(str_contains($formulario, "if (jsonTransport && !modal)"), 'B4: el alta del mapa conserva dos listeners de envío.');

// B7/B8/B9: edición canónica y errores específicos.
$afirmar(str_contains($operacion, "API_BASE + '/assign-tables'"), 'B7: falta el endpoint canónico de asignación.');
$afirmar(str_contains($operacion, 'assignmentInitialMesaIds'), 'B8: falta el snapshot de mesas para cancelar.');
$afirmar(str_contains($operacion, 'assignmentInitialVersion'), 'B7: falta conservar la versión de edición.');
$afirmar(str_contains($operacion, 'mesa_ids_actuales_presentes'), 'B7: falta declarar el snapshot enviado al backend.');
$afirmar(!str_contains($operacion, "title: 'Acción no permitida'"), 'B9: permanece el título genérico de regresión.');
$afirmar(str_contains($operacion, "title: error.titulo || 'No se pudo completar la acción'"), 'B9: los conflictos no consumen el título catalogado.');
$afirmar(str_contains($controladorOperacion, 'AdminCsrfService::validar'), 'B7: el controlador no valida CSRF.');
$afirmar(str_contains($controladorOperacion, 'version_esperada'), 'B7: el controlador no exige versión esperada.');

// B10: portal y legibilidad del shell común.
$afirmar(
    str_contains($modal, '(activeDialog || document.body || host).appendChild(root)')
        || str_contains($modal, '(document.body || host).appendChild(root)'),
    'B10: ConfirmationModal no se monta en un portal visible.'
);
$afirmar(str_contains($estilos, 'width: clamp(620px, 72vw, 840px)'), 'B10: falta el ancho de escritorio.');
$afirmar(str_contains($estilos, 'max-width: calc(100vw - 48px)'), 'B10: falta el límite horizontal.');
$afirmar(str_contains($estilos, 'font-size: clamp(24px, 2.5vw, 30px)'), 'B10: el título no cumple el mínimo tipográfico.');
$afirmar(str_contains($estilos, 'min-height: 46px'), 'B10: los botones no cumplen la altura mínima.');
$afirmar(str_contains($estilos, 'overflow-y: auto'), 'B10: el scroll no está confinado al cuerpo.');

$afirmar(str_contains($normativa, 'viewing → assignment_edit → saving → viewing'), 'Normativa: falta el flujo canónico de edición.');
$afirmar(str_contains($normativa, 'Asignar más tarde'), 'Normativa: falta la etiqueta administrativa exacta.');

if ($dynamic) {
    $runner = $root . '/scripts/tests/run-etapa6-flujos-autenticados.php';
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' --dynamic';
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        $fallos[] = 'DYNAMIC: falló el smoke HTTP autenticado de Etapa 6: ' . implode(' | ', array_slice($output, -4));
    } else {
        fwrite(STDOUT, "DYNAMIC: smoke HTTP autenticado de Etapa 6 aprobado.\n");
    }
}

if ($fallos !== []) {
    fwrite(STDERR, "FAIL: regresiones operativas de Etapa 7.1\n");
    foreach ($fallos as $fallo) {
        fwrite(STDERR, '- ' . $fallo . "\n");
    }
    exit(1);
}

fwrite(STDOUT, 'PASS: contratos de regresión operativa de Etapa 7.1' . ($dynamic ? ' + dynamic' : '') . "\n");
