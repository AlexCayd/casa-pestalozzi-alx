<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

/** @param mixed $condition */
function assertReservationMapUx($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function readReservationMapUxSource(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assertReservationMapUx(is_string($contents), "se pudo leer {$path}");
    return $contents;
}

$view = readReservationMapUxSource($root, 'views/operation/reservations/index.php');
$filters = readReservationMapUxSource($root, 'views/operation/reservations/_filters.php');
$operation = readReservationMapUxSource($root, 'src/js/admin/reservations/operation.js');
$layout = readReservationMapUxSource($root, 'src/scss/operation/_layout.scss');
$drawer = readReservationMapUxSource($root, 'src/scss/operation/_drawer.scss');
$detail = readReservationMapUxSource($root, 'src/scss/operation/_reservation-detail.scss');
$mapToggle = readReservationMapUxSource($root, 'views/operation/partials/map-toggle.php');
$operationShell = readReservationMapUxSource($root, 'src/js/operation/shell.js');

assertReservationMapUx(
    !str_contains($view, "map-toggle.php")
        && !str_contains($filters, 'data-operation-load')
        && !str_contains($operation, 'data-operation-load'),
    'reservaciones no expone maximizar/restaurar ni actualizar manualmente'
);
assertReservationMapUx(
    str_contains($view, 'data-operation-capacity-real')
        && str_contains($view, 'lugares disponibles')
        && !str_contains($view, 'reservation-operation-capacity__icon')
        && !str_contains($view, 'data-operation-capacity-of')
        && !str_contains($operation, 'capacityOf'),
    'disponibilidad usa capacidad_real_disponible sin total redundante ni icono decorativo'
);
assertReservationMapUx(
    str_contains($operation, "summary.capacidad_real_disponible")
        && substr_count($operation, 'refreshDay({ preserveReservationId: reservacion.id') >= 6
        && str_contains($operation, "loadDay(fecha, {")
        && str_contains($operation, "postJson(API_BASE + '/asignar-mesas'")
        && str_contains($operation, "postJson(API_BASE + '/liberar-mesas'")
        && str_contains($operation, "postJson(API_BASE + '/comentario'")
        && str_contains($operation, "postJson(API_BASE + '/reasignar'")
        && str_contains($operation, "postJson(API_BASE + '/estado'"),
    'mutaciones exitosas vuelven a consultar la fuente autoritativa del mapa'
);

$assignmentStart = strpos($layout, '.reservation-operation.assignment-mode .reservation-operation__workspace');
$assignmentEnd = strpos($layout, '@media', $assignmentStart === false ? 0 : $assignmentStart);
$assignmentBlock = $assignmentStart !== false && $assignmentEnd !== false
    ? substr($layout, $assignmentStart, $assignmentEnd - $assignmentStart)
    : '';
assertReservationMapUx(
    str_contains($assignmentBlock, 'height: auto;')
        && str_contains($assignmentBlock, 'flex: 1 1 0;')
        && !str_contains($assignmentBlock, 'clamp('),
    'modo de asignación deja que workspace y barra se repartan el alto disponible'
);
assertReservationMapUx(
    str_contains($drawer, 'padding-inline: 42px 12px')
        && str_contains($drawer, 'transform: translateY(-50%);'),
    'búsqueda del drawer reserva espacio para el icono y lo centra verticalmente'
);
assertReservationMapUx(
    str_contains($operation, "'Registrar ausencia', 'admin-btn admin-btn--danger'")
        && str_contains($operation, 'data-operation-clear>Liberar mesas')
        && str_contains($operation, 'data-operation-comment-edit>')
        && str_contains($detail, 'background: var(--admin-danger-bg);'),
    'acciones secundarias y destructivas conservan una affordance visible'
);
assertReservationMapUx(
    str_contains($mapToggle, 'data-operational-map-toggle')
        && str_contains($operationShell, '[data-operational-map-toggle]'),
    'el control compartido del POS sigue disponible fuera de reservaciones'
);

fwrite(STDOUT, "OK: contrato UX/UI del mapa de reservaciones\n");
