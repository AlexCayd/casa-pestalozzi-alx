<?php

declare(strict_types=1);

/*
 * Contrato UX de las reservaciones dentro del POS: barra de contexto, cajón por
 * horario, ficha acoplada, «Nueva reservación» y el enlace inicial. Sustituye
 * al contrato de la antigua pantalla de Operación. Comprueba decisiones que se
 * pueden perder en silencio al tocar un archivo, no el diseño entero.
 */

$root = dirname(__DIR__, 2);

/** @param mixed $condition */
function assertPosUx($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function leerPosUx(string $root, string $path): string
{
    $contents = file_get_contents($root . '/' . $path);
    assertPosUx(is_string($contents), "se pudo leer {$path}");
    return $contents;
}

$index = leerPosUx($root, 'views/punto-de-venta/index.php');
$workspace = leerPosUx($root, 'views/punto-de-venta/partials/pos-workspace.php');
$contexto = leerPosUx($root, 'views/punto-de-venta/partials/pos-contexto.php');
$ficha = leerPosUx($root, 'views/punto-de-venta/partials/pos-ficha.php');
$alta = leerPosUx($root, 'views/punto-de-venta/partials/pos-alta.php');
$drawer = leerPosUx($root, 'views/operation/partials/drawer.php');
$pos = leerPosUx($root, 'src/js/modules/punto-de-venta.js');
$fichaJs = leerPosUx($root, 'src/js/operation/pos-ficha.js');
$altaJs = leerPosUx($root, 'src/js/operation/pos-alta.js');
$fichaScss = leerPosUx($root, 'src/scss/punto-de-venta/_ficha.scss');

// La pantalla retirada no deja rastro en el piso.
foreach ([
    'views/operation/reservations/index.php',
    'views/operation/layout.php',
    'src/js/admin/reservations/operation.js',
    'src/js/operation/reservation-operation-policy.js',
    'src/scss/operation/reservations.scss',
    'public/build/css/operation/reservations.css',
    'public/build/js/admin/reservation-operation.js',
] as $retirado) {
    assertPosUx(!file_exists($root . '/' . $retirado), "{$retirado} se retiró");
}
assertPosUx(
    !str_contains($workspace, '/admin/reservaciones/operacion') && !str_contains($drawer, '/admin/reservaciones/operacion'),
    'el cajón ya no enlaza a Operación'
);

// Enlace inicial validado en el servidor y recursos con versión por mtime.
assertPosUx(
    str_contains($index, 'data-initial-hora=')
        && str_contains($index, 'data-initial-reservation-id=')
        && str_contains($index, 'data-initial-mode=')
        && str_contains($index, "recursoVersionado('/build/js/admin/map.js')")
        && str_contains($index, "recursoVersionado('/build/css/operation.css')")
        && preg_match('/(href|src)="[^"]*?v=/', $index) !== 1,
    'el POS recibe el enlace inicial por data-* y versiona sus recursos por mtime'
);
assertPosUx(
    str_contains($pos, 'function continuarEnlaceInicial()')
        && str_contains($pos, 'window.history.replaceState'),
    'el enlace inicial se consume una sola vez y no se repite al recargar'
);

// Barra de contexto: anuncia el modo y el reloj no es región viva.
assertPosUx(
    str_contains($contexto, 'role="status" aria-live="polite" data-contexto-anuncio')
        && !preg_match('/aria-live[^>]*data-contexto-reloj|data-contexto-reloj[^>]*aria-live/', $contexto),
    'la barra de contexto anuncia cambios de modo pero no el reloj'
);
// La ayuda, los ajustes y el panel viven en el menú del avatar; salir sigue a
// un toque, fuera del menú.
assertPosUx(
    !str_contains($contexto, 'data-map-help-open')
        && substr_count($workspace, 'role="menuitem" data-map-help-open') === 1
        && str_contains($workspace, 'aria-controls="map-help-dialog-mapa-mesas"')
        && str_contains($workspace, 'id="pos-prefs-toggle" role="menuitem"')
        && str_contains($workspace, '$operationalHeaderMenuHtml =')
        && str_contains($workspace, '$operationalHeaderBrand = false;')
        && str_contains($workspace, '$operationalHeaderBack = false;'),
    'la ayuda, los ajustes y el panel se pliegan en el menú del avatar'
);
assertPosUx(str_contains($workspace, "'helpPosition' => 'externo'"), 'la ayuda del mapa no flota sobre el plano');

// El contexto vive en el centro de la barra operativa, no en una fila sobre el
// plano: una sola inclusión, capturada en el slot que sólo el POS llena.
$header = leerPosUx($root, 'views/operation/partials/header.php');
assertPosUx(
    substr_count($workspace, "include __DIR__ . '/pos-contexto.php'") === 1
        && preg_match("/ob_start\(\);\s*include __DIR__ \. '\/pos-contexto\.php';\s*\\\$operationalHeaderCenterHtml = \(string\)ob_get_clean\(\);/", $workspace) === 1
        && str_contains($header, '<?php echo $operationalHeaderCenterHtml; ?>')
        && preg_match('/unset\([^)]*\$operationalHeaderCenterHtml/', $header) === 1,
    'el contexto se pinta una vez, en el centro de la barra, y el slot se limpia tras el header'
);

// Ficha acoplada, no modal.
assertPosUx(
    str_contains($ficha, 'role="dialog" aria-modal="false"') && str_contains($ficha, ' hidden>'),
    'la ficha es un diálogo NO modal que nace oculto: el mapa sigue tocable'
);
assertPosUx(
    str_contains($fichaJs, 'data-mesero-slot') || str_contains($fichaJs, 'mesero'),
    'la ficha conoce la asignación de mesero'
);
assertPosUx(
    !preg_match('/\b(alert|confirm|prompt)\s*\(/', $fichaJs . $altaJs),
    'ni la ficha ni el alta usan diálogos nativos'
);

// Alta: diálogo nativo centrado, pie del cajón como disparador.
assertPosUx(
    str_contains($alta, '<dialog class="pos-alta" id="pos-alta"')
        && str_contains($workspace, 'data-alta-abrir data-cierra-cajon'),
    '«Nueva reservación» es un <dialog> que se abre desde el pie del cajón'
);
assertPosUx(
    preg_match('/\.pos-alta \{\s*(?:\/\/[^\n]*\n\s*)*margin: auto;/', $fichaScss) === 1,
    'el diálogo de alta declara margin:auto (un reset lo dejaba pegado arriba a la izquierda)'
);
assertPosUx(
    str_contains($fichaScss, '&.is-elegida:hover:not(:disabled)'),
    'la hora elegida no la apaga el hover'
);
assertPosUx(
    str_contains($altaJs, 'function reabrir()'),
    'el alta se aparta mientras confirma: un <dialog> modal deja inerte a ConfirmationModal'
);

fwrite(STDOUT, "POS: contrato UX de reservaciones (contexto, ficha, alta, enlace) OK\n");
