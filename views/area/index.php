<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= htmlspecialchars($area['nombre']) ?> · Casa Pestalozzi</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/build/css/app.css" />
</head>
<body class="area-page" data-page="area">

  <div class="area-shell">

    <header class="area-header">
      <a href="/mapa" class="area-back">← Mapa</a>
      <div class="area-title">
        <span class="area-dot" style="background:<?= htmlspecialchars($area['color']) ?>"></span>
        <span><?= htmlspecialchars($area['nombre']) ?></span>
      </div>
      <div class="area-header-right">
        <span class="area-refresh-info" id="area-refresh-info">—</span>
      </div>
    </header>

    <div class="area-body">

      <div class="area-col area-col--enviados">
        <div class="area-col-head">
          <span class="area-col-label">Enviados</span>
          <span class="area-col-count" id="count-enviados">0</span>
        </div>
        <div class="area-col-items" id="list-enviados">
          <div class="area-empty"><span class="area-empty__icon">◌</span><span>Sin pedidos</span></div>
        </div>
      </div>

      <div class="area-col area-col--prep">
        <div class="area-col-head">
          <span class="area-col-label">En preparación</span>
          <span class="area-col-count" id="count-prep">0</span>
        </div>
        <div class="area-col-items" id="list-prep">
          <div class="area-empty"><span class="area-empty__icon">◌</span><span>—</span></div>
        </div>
      </div>

      <div class="area-col area-col--listo">
        <div class="area-col-head">
          <span class="area-col-label">Listos</span>
          <span class="area-col-count" id="count-listo">0</span>
        </div>
        <div class="area-col-items" id="list-listo">
          <div class="area-empty"><span class="area-empty__icon">◌</span><span>—</span></div>
        </div>
      </div>

    </div>
  </div>

  <script>
    window.CP_AREA   = {
      id:     <?= (int)$area['id'] ?>,
      color:  '<?= htmlspecialchars($area['color'], ENT_QUOTES) ?>',
      nombre: '<?= htmlspecialchars($area['nombre'], ENT_QUOTES) ?>'
    };
    window.CP_TWEAKS = { hero: 'cinema', accent: 'oro', cursor: false, smooth: false, anim: false };
  </script>
  <script src="/build/js/bundle.min.js"></script>

</body>
</html>
