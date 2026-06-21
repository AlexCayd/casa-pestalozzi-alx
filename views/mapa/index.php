<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Mapa de Mesas · Casa Pestalozzi</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/build/css/app.css" />
</head>
<body class="mapa-page" data-page="mapa">

  <div class="mapa-shell">

    <!-- ── Header ─────────────────────────────────────────── -->
    <header class="mapa-header">
      <a href="/" class="mapa-logo">Casa Pestalozzi</a>

      <div class="mapa-header-center">
        <h1 class="mapa-title">Mapa de Mesas</h1>
      </div>

      <div class="mapa-header-controls">
        <div class="mapa-date-wrap" id="mapa-date-picker">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
          </svg>
          <button class="mapa-date-btn" id="mapa-date-display" type="button"></button>
          <input type="hidden" id="mapa-fecha" value="<?php echo date('Y-m-d'); ?>" />
          <div class="mapa-cal" id="mapa-calendar" aria-hidden="true">
            <div class="mapa-cal__nav">
              <button class="mapa-cal__nav-btn" id="mapa-cal-prev" type="button">‹</button>
              <span class="mapa-cal__label" id="mapa-cal-label"></span>
              <button class="mapa-cal__nav-btn" id="mapa-cal-next" type="button">›</button>
            </div>
            <div class="mapa-cal__weekdays">
              <span>D</span><span>L</span><span>M</span><span>X</span><span>J</span><span>V</span><span>S</span>
            </div>
            <div class="mapa-cal__grid" id="mapa-cal-grid"></div>
          </div>
        </div>
        <div class="mapa-live-badge" id="mapa-live-badge">
          <span class="mapa-live-dot"></span>
        </div>
      </div>
    </header>

    <!-- ── Body ───────────────────────────────────────────── -->
    <div class="mapa-body">

      <!-- Sidebar -->
      <aside class="mapa-sidebar">
        <div class="mapa-sidebar-head">
          <span class="mapa-sidebar-title">Reservaciones</span>
          <span class="mapa-reserva-count" id="mapa-reserva-count">—</span>
        </div>

        <div class="mapa-leyenda">
          <span class="mapa-leyenda-item mapa-leyenda-item--libre">Libre</span>
          <span class="mapa-leyenda-item mapa-leyenda-item--proxima">Próxima</span>
          <span class="mapa-leyenda-item mapa-leyenda-item--bloqueada">Bloqueada</span>
        </div>

        <div class="mapa-reservas-list" id="mapa-reservas-list">
          <div class="mapa-empty-state">
            <span class="mapa-empty-icon">◌</span>
            <span>Cargando…</span>
          </div>
        </div>
      </aside>

      <!-- Canvas del plano -->
      <div class="mapa-canvas-wrap">
        <div class="mapa-canvas" id="mapa-canvas">
          <!-- Pines de mesas renderizados por JS -->
        </div>
        <div class="mapa-canvas-overlay" id="mapa-loading">
          <div class="mapa-spinner"></div>
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

  </div><!-- /.mapa-shell -->

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
