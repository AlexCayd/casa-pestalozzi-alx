<?php
/**
 * Workspace reutilizable del Punto de Venta.
 * Contiene el cuerpo completo del POS: header, mapa de mesas, leyenda, cajón de
 * reservaciones, botón de maximizar y el shell del modal de mesa. La lógica y el
 * estado los maneja `src/js/modules/punto-de-venta.js` (auto-init vía `#mapa-canvas`).
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
 *   4) Definir antes de incluir este partial las variables:
 *      $h (escaper), $mapFecha (YYYY-MM-DD), $drawerToggleHtml, $datePickerHtml,
 *      $usuarioNombre, $usuarioRol. Ver views/punto-de-venta/index.php.
 *
 * IMPORTANTE: los IDs mapa-*, #mesa-modal* y #pos-prefs-* son el contrato con
 * el JS; no renombrar. El overlay #pos-prefs-overlay debe emitirse siempre:
 * es donde vive el panel de ajustes del mesero (fuera del modal, que se
 * reescribe entero en cada apertura de mesa).
 */

$h = $h ?? static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$mapFecha = $mapFecha ?? date('Y-m-d');
$drawerToggleHtml = $drawerToggleHtml ?? '';
$datePickerHtml = $datePickerHtml ?? '';
$usuarioNombre = $usuarioNombre ?? '';
$usuarioRol = $usuarioRol ?? 'Usuario';
?>
<div class="mapa-shell pos-shell">
  <main class="mapa-body pos-body" aria-label="Mapa de mesas" data-operational-main>
    <header class="pos-header" data-operational-header>
      <div class="pos-header__left">
        <?php echo $drawerToggleHtml; ?>
        <a class="pos-header__brand" href="/punto-de-venta" title="Casa Pestalozzi">
          Casa Pestalozzi<span>Del Valle · México</span>
        </a>
      </div>
      <div class="pos-header__right">
        <?php if ($usuarioNombre !== '') : ?>
          <div class="pos-header__user" title="<?php echo $h($usuarioNombre); ?>">
            <span class="pos-header__user-avatar" aria-hidden="true"><?php echo $h(mb_strtoupper(mb_substr($usuarioNombre, 0, 1))); ?></span>
            <span class="pos-header__user-info">
              <span class="pos-header__user-name"><?php echo $h($usuarioNombre); ?></span>
              <span class="pos-header__user-role"><?php echo $h($usuarioRol); ?></span>
            </span>
          </div>
        <?php endif; ?>
        <?php /* Ajustes del mesero. Vive en el header y no dentro del modal:
                antes el acceso estaba en una barra oculta bajo 768px, así que
                en tablet no había forma de llegar a la configuración. */ ?>
        <button type="button" class="pos-header__prefs" id="pos-prefs-toggle"
                aria-haspopup="dialog" aria-expanded="false" aria-controls="pos-prefs-overlay"
                title="Ajustes de la vista">
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>
          </svg>
          <span>Ajustes</span>
        </button>
        <form class="pos-header__logout-form" method="POST" action="/logout">
          <button type="submit" class="pos-header__logout">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
              <path d="m16 17 5-5-5-5"/>
              <path d="M21 12H9"/>
            </svg>
            <span>Cerrar sesión</span>
          </button>
        </form>
      </div>
    </header>

    <div class="pos-map" data-operational-workspace>
      <?php
      $mapVisual = [
        'context' => 'mapa-mesas',
        'sectionClass' => 'mapa-operational-map',
        'titleId' => 'mapa-operational-title',
        'title' => 'Mapa de mesas',
        'subtitle' => 'Estado actual del salón.',
        'canvasId' => 'mapa-canvas',
        'canvasMode' => 'map',
        'loadingMode' => 'overlay',
      ];
      include __DIR__ . '/../../operation/partials/map.php';
      ?>
    </div>

    <div class="pos-legend" aria-label="Estados de las mesas">
      <span class="pos-legend__item pos-legend__item--libre">Libre</span>
      <span class="pos-legend__item pos-legend__item--ocupada">Ocupada</span>
      <span class="pos-legend__item pos-legend__item--bloqueada">Asignada</span>
      <span class="pos-legend__item pos-legend__item--seleccionada">Seleccionada</span>
      <span class="pos-legend__item pos-legend__item--zona">No reservable</span>
    </div>

    <?php
    $operationalDrawerId = 'map-reservations-drawer';
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

    <button
      type="button"
      class="operational-map-toggle"
      data-operational-map-toggle
      aria-pressed="false"
      aria-label="Maximizar mapa"
      title="Maximizar mapa">
      <svg class="operational-map-toggle__expand" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M8 3H3v5"/><path d="M21 8V3h-5"/><path d="M16 21h5v-5"/><path d="M3 16v5h5"/>
      </svg>
      <svg class="operational-map-toggle__collapse" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M8 3v5H3"/><path d="M21 8h-5V3"/><path d="M16 21v-5h5"/><path d="M3 16h5v5"/>
      </svg>
      <span class="operational-map-toggle__label">Maximizar mapa</span>
    </button>
  </main>

  <div class="mesa-modal" id="mesa-modal">
    <div class="mesa-modal__bd" id="mesa-modal-bd"></div>
    <div class="mesa-modal__panel">
      <div class="mesa-modal__handle"></div>
      <button class="mesa-modal__close" id="mesa-modal-close" aria-label="Cerrar">×</button>
      <div id="mesa-modal-content"></div>
    </div>
  </div>

  <?php /*
    Preferencias del mesero. Va FUERA de #mesa-modal-content a propósito: ese
    nodo se reescribe entero en cada apertura de mesa y se llevaría por delante
    los listeners del panel. Aquí se enlaza una sola vez y sobrevive.
    El cuerpo lo pinta punto-de-venta.js (panelAjustesHtml), porque depende de
    localStorage, que PHP no ve.
  */ ?>
  <div class="pos-prefs" id="pos-prefs-overlay" hidden>
    <div class="pos-prefs__bd" id="pos-prefs-bd"></div>
    <div class="pos-prefs__dialog" role="dialog" aria-modal="true" aria-labelledby="pos-prefs-title">
      <header class="pos-prefs__head">
        <h3 class="pos-prefs__title" id="pos-prefs-title">Ajustes de la vista</h3>
        <button type="button" class="pos-prefs__close" id="pos-prefs-close" aria-label="Cerrar ajustes">×</button>
      </header>
      <div class="pos-prefs__body mmodal-prefs" id="pos-prefs-panel"></div>
    </div>
  </div>
</div>
