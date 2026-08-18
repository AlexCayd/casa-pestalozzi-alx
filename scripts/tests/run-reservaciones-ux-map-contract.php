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
$toolbar = readReservationMapUxSource($root, 'src/scss/operation/_toolbar.scss');
$drawer = readReservationMapUxSource($root, 'src/scss/operation/_drawer.scss');
$detail = readReservationMapUxSource($root, 'src/scss/operation/_reservation-detail.scss');
$mapToggle = readReservationMapUxSource($root, 'views/operation/partials/map-toggle.php');
$operationShell = readReservationMapUxSource($root, 'src/js/operation/shell.js');

assertReservationMapUx(
    !str_contains($view, "map-toggle.php")
        && !str_contains($view, 'data-operation-load')
        && !str_contains($operation, 'data-operation-load')
        && str_contains($view, 'data-operation-tables-open')
        && str_contains($view, '<rect x="5" y="5" width="14" height="10" rx="2"></rect>')
        && str_contains($view, '<path d="M8 15v4M16 15v4M5 19h14"></path>')
        && !str_contains($view, '<rect x="3" y="3" width="7" height="7" rx="1"></rect>')
        && str_contains($view, 'reservation-operation__toolbar-left')
        && str_contains($view, 'reservation-operation__toolbar-center'),
    'reservaciones conserva Mesas con un icono de mesa reconocible y separa los tres bloques finales del toolbar'
);
assertReservationMapUx(
    str_contains($view, 'data-operation-capacity-real')
        && str_contains($view, 'data-operation-capacity-of')
        && str_contains($view, 'lugares disponibles')
        && !str_contains($view, 'reservation-operation-capacity__label')
        && !str_contains($view, 'reservation-operation-capacity__available')
        && !str_contains($view, 'data-operation-capacity-secondary')
        && !str_contains($operation, 'capacitySecondary')
        && str_contains($operation, 'capacityOf')
        && str_contains($operation, "setAttribute('title', accessibleLabel)"),
    'disponibilidad muestra solo X/Y y conserva un nombre accesible con la capacidad autoritativa'
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
        && str_contains($operation, 'reservation-operation__detail-inline-action')
        && str_contains($operation, "var mesaHeading = mesaIds.length > 1 ? 'Mesas asignadas' : 'Mesa asignada'")
        && str_contains($operation, "var mesaChangeLabel = mesaIds.length > 1 ? 'Cambiar mesas' : 'Cambiar mesa'")
        && str_contains($operation, 'data-operation-comment-edit>')
        && str_contains($operation, 'Sin notas')
        && str_contains($operation, "<h4>Acciones</h4>")
        && str_contains($detail, 'background: transparent;'),
    'notas y acciones secundarias/destructivas conservan una affordance visible'
);
assertReservationMapUx(
    str_contains($detail, 'margin-top: auto;')
        && str_contains($detail, 'reservation-operation-comment-summary')
        && str_contains($detail, 'color: var(--admin-muted);')
        && str_contains($detail, 'font-style: italic;'),
    'el detalle reserva el cierre para acciones y hace legibles notas llenas y vacías'
);
assertReservationMapUx(
    str_contains($detail, 'background: color-mix(in srgb, var(--admin-surface-soft) 58%, transparent);')
        && str_contains($detail, 'reservation-operation-detail-value-row')
        && str_contains($detail, 'grid-template-columns: minmax(0, 1fr);')
        && str_contains($detail, 'grid-template-columns: minmax(0, 1fr) auto;')
        && str_contains($detail, 'height: 40px;')
        && str_contains($detail, 'height: 44px;')
        && str_contains($detail, 'border-radius: var(--admin-radius-sm, 10px);')
        && str_contains($detail, 'min-height: 100%;'),
    'el detalle normaliza controles y notas, usa zonas sutiles y mantiene scroll natural'
);
assertReservationMapUx(
    str_contains($toolbar, 'grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);')
        && str_contains($toolbar, 'reservation-operation__toolbar-left')
        && str_contains($toolbar, 'grid-area: left;')
        && str_contains($toolbar, 'grid-area: center;'),
    'la estructura responsive del toolbar conserva un centro independiente de los extremos'
);
assertReservationMapUx(
    str_contains($mapToggle, 'data-operational-map-toggle')
        && str_contains($operationShell, '[data-operational-map-toggle]'),
    'el control compartido del POS sigue disponible fuera de reservaciones'
);

fwrite(STDOUT, "OK: contrato UX/UI del mapa de reservaciones\n");
