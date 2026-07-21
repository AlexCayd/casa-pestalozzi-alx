<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Punto de Venta · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/build/css/app.css" />
</head>
<body class="pdv-page" data-page="punto-de-venta">

  <div class="pdv-shell">

    <!-- ── Header ─────────────────────────────────────────── -->
    <header class="pdv-header">
      <a href="/" class="pdv-logo">Casa Pestalozzi</a>

      <div class="pdv-header-center">
        <h1 class="pdv-title">Punto de Venta</h1>
      </div>

      <div class="pdv-header-controls">
        <div class="pdv-date-wrap" id="pdv-date-picker">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
          </svg>
          <button class="pdv-date-btn" id="pdv-date-display" type="button"></button>
          <input type="hidden" id="pdv-fecha" value="<?php echo date('Y-m-d'); ?>" />
          <div class="pdv-cal" id="pdv-calendar" aria-hidden="true">
            <div class="pdv-cal__nav">
              <button class="pdv-cal__nav-btn" id="pdv-cal-prev" type="button">‹</button>
              <span class="pdv-cal__label" id="pdv-cal-label"></span>
              <button class="pdv-cal__nav-btn" id="pdv-cal-next" type="button">›</button>
            </div>
            <div class="pdv-cal__weekdays">
              <span>D</span><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span>
            </div>
            <div class="pdv-cal__grid" id="pdv-cal-grid"></div>
          </div>
        </div>
      </div>
    </header>

    <!-- ── Body ───────────────────────────────────────────── -->
    <div class="pdv-body">

      <!-- Sidebar -->
      <aside class="pdv-sidebar">
        <div class="pdv-sidebar-head">
          <span class="pdv-sidebar-title">Reservaciones</span>
          <span class="pdv-reserva-count" id="pdv-reserva-count">—</span>
        </div>

        <div class="pdv-leyenda">
          <span class="pdv-leyenda-item pdv-leyenda-item--libre">Libre</span>
          <span class="pdv-leyenda-item pdv-leyenda-item--proxima">Próxima</span>
          <span class="pdv-leyenda-item pdv-leyenda-item--bloqueada">Bloqueada</span>
        </div>

        <div class="pdv-reservas-list" id="pdv-reservas-list">
          <div class="pdv-empty-state">
            <span class="pdv-empty-icon">◌</span>
            <span>Cargando…</span>
          </div>
        </div>
      </aside>

      <!-- Canvas del plano -->
      <div class="pdv-canvas-wrap">
        <div class="pdv-canvas" id="pdv-canvas">
          <!-- Pines de mesas renderizados por JS -->
        </div>
        <div class="pdv-canvas-overlay" id="pdv-loading">
          <div class="pdv-spinner"></div>
        </div>
      </div>

    </div>

    <!-- ── Modal de acción de mesa ───────────────────────── -->
    <div class="mesa-modal" id="mesa-modal">
      <div class="mesa-modal__bd" id="mesa-modal-bd"></div>
      <div class="mesa-modal__panel">
        <div class="mesa-modal__handle"></div>
        <button class="mesa-modal__close" id="mesa-modal-close" aria-label="Cerrar">✕</button>
        <div id="mesa-modal-content"></div>
      </div>
    </div>

  </div><!-- /.pdv-shell -->

  <script>
    window.CP_TWEAKS = {
      hero:   'cinema',
      accent: 'oro',
      cursor: false,
      smooth: false,
      anim:   false
    };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
  <script src="/build/js/bundle.min.js"></script>

</body>
</html>
