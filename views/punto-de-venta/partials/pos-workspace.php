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
 *   2) Cargar los assets: /build/css/app.css, /build/js/bundle.min.js
 *      (incluye punto-de-venta.js + CP_MENU/CP_AREAS de menu-data.js),
 *      /build/js/admin/map.js y el CDN de qrcode-generator.
 *   3) Definir antes de incluir este partial las variables:
 *      $h (escaper), $mapFecha (YYYY-MM-DD), $drawerToggleHtml, $datePickerHtml,
 *      $usuarioNombre, $usuarioRol. Ver views/punto-de-venta/index.php.
 *
 * IMPORTANTE: los IDs mapa-* y #mesa-modal* son el contrato con el JS; no renombrar.
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
    $operationalDrawerSlotHtml =
      '<div class="pos-drawer-date">' . $datePickerHtml . '</div>' .
      '<button type="button" class="admin-btn admin-btn--primary pdv-manage-reservations" ' .
      'id="pdv-manage-reservations"><span aria-hidden="true">📅</span> ' .
      'Gestionar reservaciones</button>';
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
</div>
