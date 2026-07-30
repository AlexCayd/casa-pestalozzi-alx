<?php
/**
 * Contenido del mapa de mesas dentro del shell operativo compartido.
 *
 * El header, drawer, maximización y layout viven en los parciales de
 * operation. Este archivo conserva únicamente el mapa POS, su leyenda y el
 * modal de mesa; los IDs mapa-* y #mesa-modal* son contrato con su adaptador.
 */
$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mapFecha = $mapFecha ?? date('Y-m-d');
$datePickerHtml = $datePickerHtml ?? '';
$usuarioNombre = $usuarioNombre ?? '';
$usuarioRol = $usuarioRol ?? 'Usuario';

$operationalView = 'map';
$operationalModule = 'tables';
$operationalModuleTitle = 'Mapa de mesas';
$operationalDate = $mapFecha;
$operationalHour = '';
$operationalBrandHref = '/punto-de-venta';
$operationalHeaderBackUrl = '/admin/punto-de-venta';
$operationalDrawerId = 'map-reservations-drawer';
$operationalActiveModule = 'map';
$operationalMapHref = '/punto-de-venta';
$operationalReservationsHref = '/admin/reservations/operation';
$operationalUsuarioNombre = $usuarioNombre;
$operationalUsuarioRol = $usuarioRol;
$operationalShellClass = 'mapa-shell pos-shell';
$operationalMainClass = 'mapa-body pos-body operational-layout';
$operationalMainId = '';
$operationalMainAttributes = ['aria-label' => 'Mapa de mesas', 'data-operational-main' => true];

ob_start();
?>
  <div class="pos-map" data-operational-workspace>
  <?php
  ob_start();
  $operationalMapToggleLabel = 'mapa';
  include __DIR__ . '/../../operation/partials/map-toggle.php';
  $mapToolbarActionsHtml = (string)ob_get_clean();
  $mapVisual = [
    'context' => 'mapa-mesas',
    'sectionClass' => 'mapa-operational-map',
    'titleId' => 'mapa-operational-title',
    'title' => 'Mapa de mesas',
    'subtitle' => 'Estado actual del salón.',
    'canvasId' => 'mapa-canvas',
    'canvasMode' => 'map',
    'loadingMode' => 'overlay',
    'legendPosition' => 'footer',
    'toolbarActionsHtml' => $mapToolbarActionsHtml,
  ];
  include __DIR__ . '/../../operation/partials/map.php';
  ?>
</div>

<?php
$operationalDrawerTitleId = 'map-reservations-title';
$operationalDrawerClass = 'mapa-sidebar';
$operationalDrawerAttributes = [];
$operationalDrawerDateHtml = '<span data-operational-map-date>' . $h($mapFecha) . '</span>';
$operationalDrawerCountHtml = '<span class="mapa-reserva-count" id="mapa-reserva-count">—</span>';
$operationalDrawerSlotHtml = '<div class="pos-drawer-date">' . $datePickerHtml . '</div>';
$operationalDrawerListId = 'mapa-reservas-list';
$operationalDrawerListClass = 'mapa-reservas-list';
$operationalDrawerListAttributes = [];
$operationalDrawerListHtml = '<div class="mapa-empty-state"><span class="mapa-empty-icon">◌</span><span>Cargando…</span></div>';
include __DIR__ . '/../../operation/partials/drawer.php';

?>

<div class="mesa-modal" id="mesa-modal">
  <div class="mesa-modal__bd" id="mesa-modal-bd"></div>
  <div class="mesa-modal__panel">
    <div class="mesa-modal__handle"></div>
    <button class="mesa-modal__close" id="mesa-modal-close" aria-label="Cerrar">×</button>
    <div id="mesa-modal-content"></div>
  </div>
</div>
<?php
$operationalContentHtml = (string)ob_get_clean();
include __DIR__ . '/../../operation/partials/shell.php';
?>
