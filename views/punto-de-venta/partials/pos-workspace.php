<?php
/**
 * Contenido del mapa de mesas dentro del shell operativo compartido.
 *
 * El header, drawer, maximización y layout viven en los parciales de
 * operation. Este archivo conserva únicamente el mapa POS, su leyenda y el
 * modal de mesa; los IDs mapa-* y #mesa-modal* son contrato con su adaptador.
 *
 * Para embeberlo en otra sección del proyecto, el contenedor debe:
 *   1) Tener en el <body> los hooks: class "mapa-page operational-page",
 *      data-page="mapa" y data-operational-page (para shell.js).
 *   2) Cargar los assets: /build/css/app.css, /build/js/bundle.min.js,
 *      /build/js/admin/map.js (que trae punto-de-venta.js) y el CDN de
 *      qrcode-generator.
 *   3) Emitir en línea, ANTES de esos <script src>, las globales
 *      window.CP_MENU, window.CP_AREAS y window.CP_USER. Es un contrato duro:
 *      el JS las lee de forma síncrona al abrir una mesa. Se arman con
 *      Services\Carta::paraPos() / ::areasPos() (antes venían del archivo
 *      escrito a mano src/js/data/menu-data.js, ya eliminado).
 *   4) Definir antes de incluir este partial las variables $h, $mapFecha,
 *      $datePickerHtml, $usuarioNombre y $usuarioRol.
 *
 * IMPORTANTE: los IDs mapa-*, #mesa-modal* y #pos-prefs-* son el contrato con
 * el JS; no renombrar. El overlay #pos-prefs-overlay debe emitirse siempre:
 * es donde vive el panel de ajustes del mesero (fuera del modal, que se
 * reescribe entero en cada apertura de mesa).
 */
$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mapFecha = $mapFecha ?? date('Y-m-d');
$datePickerHtml = $datePickerHtml ?? '';
$usuarioNombre = $usuarioNombre ?? '';
$usuarioRol = $usuarioRol ?? 'Usuario';

$esAdmin = ($_SESSION['rol'] ?? '') === 'admin';

$operationalView = 'map';
$operationalModule = 'tables';
// Sin título ni reloj ni flecha: el POS ya es la pantalla en la que está el
// mesero y cada rótulo de más le quita sitio al mapa.
$operationalModuleTitle = '';
$operationalShowLastUpdate = false;
// Chip informativo + icono de salida: el mesero ve con qué cuenta trabaja y
// cierra sesión de un toque, sin desplegable intermedio.
$operationalUserMenu = false;
// Solo para administradores: el destino está bajo /admin y a un mesero la
// guardia de rol lo rebotaría.
$operationalHeaderBack = $esAdmin;
$operationalDate = $mapFecha;
$operationalHour = '';
$operationalBrandHref = '/punto-de-venta';
$operationalHeaderBackUrl = '/admin/punto-de-venta';
$operationalDrawerId = 'map-reservations-drawer';
$operationalActiveModule = 'map';
$operationalMapHref = '/punto-de-venta';
// Ambos destinos viven bajo /admin: a un mesero la guardia lo rebotaría, así
// que solo se ofrecen si quien mira es administrador.
$operationalReservationsHref = $esAdmin ? '/admin/reservations/operation' : '';
$operationalAdminHref = $esAdmin ? '/admin/analytics' : '';
$operationalUsuarioNombre = $usuarioNombre;
$operationalUsuarioRol = $usuarioRol;
$operationalShellClass = 'mapa-shell pos-shell';
$operationalMainClass = 'mapa-body pos-body operational-layout';
$operationalMainId = '';
$operationalMainAttributes = ['aria-label' => 'Mapa de mesas', 'data-operational-main' => true];

ob_start();
?>
<button type="button" class="pos-header__prefs" id="pos-prefs-toggle"
        aria-haspopup="dialog" aria-expanded="false" aria-controls="pos-prefs-overlay"
        aria-label="Ajustes de la vista" title="Ajustes de la vista">
  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
    <circle cx="12" cy="12" r="3"/>
    <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1A1.7 1.7 0 0 0 19.4 15Z"/>
  </svg>
</button>
<?php
$operationalHeaderActionsHtml = (string)ob_get_clean();

ob_start();
?>
  <div class="pos-map" data-operational-workspace>
  <?php /*
    Barra de selección múltiple. Aparece solo dentro del modo selección, que
    ahora arranca desde "Unir mesas" en el modal de la mesa. Los IDs son
    contrato con punto-de-venta.js; no renombrar.
  */ ?>
  <div class="pos-ticket-selection-bar" id="pos-ticket-selection-bar" hidden>
    <p class="pos-ticket-selection-message" id="pos-ticket-selection-message" role="status" tabindex="-1">
      Selecciona una o m&aacute;s mesas para abrir el ticket.
    </p>
    <div class="pos-ticket-selection-bar__actions">
      <button type="button" class="operational-map-action operational-map-action--cancel" id="pos-ticket-selection-cancel">
        Cancelar
      </button>
      <button type="button" class="operational-map-action operational-map-action--primary" id="pos-ticket-selection-toggle">
        Confirmar apertura
      </button>
    </div>
  </div>
  <?php
  // Sin encabezado ni botonera: el mapa es la pantalla del mesero y cada
  // rótulo o columna de acciones le quitaba ancho útil al salón. 'titleId'
  // debe ir vacío o map.php dejaría un aria-labelledby apuntando a la nada.
  $mapVisual = [
    'context' => 'mapa-mesas',
    'sectionClass' => 'mapa-operational-map',
    'titleId' => '',
    'title' => '',
    'subtitle' => '',
    'ariaLabel' => 'Mapa de mesas',
    'canvasId' => 'mapa-canvas',
    'canvasMode' => 'map',
    'loadingMode' => 'overlay',
    // Sin leyenda: el color de cada mesa ya dice su estado y la lista de
    // abreviaturas ocupaba más que el propio mapa.
    'legendPosition' => 'none',
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

<div class="mesa-modal" id="mesa-modal" role="dialog" aria-modal="true" aria-hidden="true" inert aria-labelledby="mesa-modal-title" tabindex="-1">
  <div class="mesa-modal__bd" id="mesa-modal-bd"></div>
  <div class="mesa-modal__panel">
    <div class="mesa-modal__handle"></div>
    <button type="button" class="mesa-modal__close" id="mesa-modal-close" aria-label="Cerrar detalle de mesa">×</button>
    <div id="mesa-modal-content"></div>
  </div>
</div>

<?php /*
  Preferencias del mesero. Va fuera de #mesa-modal: el modal se abre y cierra
  de forma independiente y #mesa-modal-content se reescribe en cada apertura.
*/ ?>
<div class="pos-prefs" id="pos-prefs-overlay" hidden aria-hidden="true">
  <div class="pos-prefs__bd" id="pos-prefs-bd"></div>
  <div class="pos-prefs__dialog" role="dialog" aria-modal="true" aria-labelledby="pos-prefs-title">
    <header class="pos-prefs__head">
      <h3 class="pos-prefs__title" id="pos-prefs-title">Ajustes de la vista</h3>
      <button type="button" class="pos-prefs__close" id="pos-prefs-close" aria-label="Cerrar ajustes">×</button>
    </header>
    <div class="pos-prefs__body mmodal-prefs" id="pos-prefs-panel"></div>
  </div>
</div>
<?php
$operationalContentHtml = (string)ob_get_clean();
include __DIR__ . '/../../operation/partials/shell.php';
?>
