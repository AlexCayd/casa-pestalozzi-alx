<?php
/**
 * Tablero de producción (KDS) de una estación.
 *
 * Comparte el shell operativo con el punto de venta: mismo header, misma
 * tipografía y los mismos tokens del sistema administrativo. Antes tenía un
 * encabezado propio de 56px y su vocabulario de tarjetas, y las dos pantallas
 * del piso —que el mismo turno usa a la vez— no se parecían en nada.
 *
 * Los ids list-*, count-* y #area-refresh-info son contrato con
 * src/js/modules/area.js; no renombrar.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$h = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$usuarioNombre = trim((string) ($_SESSION['nombre'] ?? ''));
$rolEtiquetas = ['admin' => 'Administrador', 'waiter' => 'Mesero', 'cook' => 'Cocinero'];
$usuarioRol = $rolEtiquetas[(string) ($_SESSION['rol'] ?? '')] ?? 'Usuario';
$esAdmin = ($_SESSION['rol'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="es" data-admin-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="theme-color" content="#0b0b0c" />
  <title><?= $h($area['nombre']) ?> · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <?php /* Geist locales: el piso funciona sin red. */ ?>
  <link rel="preload" href="/build/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/build/css/operation.css?v=consola-bn-v1">
</head>
<body class="admin-body area-page operational-page" data-page="area" data-operation-module="area">

<?php
// El indicador de actualización va como acción del header: el partial estándar
// (last-update.php) cablea sus ids al mapa de mesas, y aquí el texto lo escribe
// area.js sobre #area-refresh-info.
ob_start();
?>
<span class="operational-update area-refresh" role="status" aria-live="polite">
  <span class="operational-update__dot" aria-hidden="true"></span>
  <span class="operational-update__text" id="area-refresh-info">Conectando…</span>
</span>
<?php
$operationalHeaderActionsHtml = (string) ob_get_clean();

$operationalView = 'map';
$operationalModule = 'area';
$operationalModuleTitle = (string) $area['nombre'];
$operationalShowLastUpdate = false;
$operationalHeaderDrawerToggle = false;
// Chip informativo + salida de un toque, igual que en el POS: en el tablero se
// trabaja con las manos ocupadas y un desplegable intermedio sobra.
$operationalUserMenu = false;
// El destino vive bajo /admin: a un cocinero la guardia de rol lo rebotaría.
$operationalHeaderBack = $esAdmin;
$operationalHeaderBackUrl = '/admin/area';
$operationalBrandHref = '/area';
$operationalUsuarioNombre = $usuarioNombre;
$operationalUsuarioRol = $usuarioRol;
$operationalShellClass = 'area-shell';
$operationalMainClass = 'area-main operational-layout';
$operationalMainId = 'area-main';
$operationalMainAttributes = [
  'aria-label' => 'Tablero de producción',
  // El color de la estación es dato de negocio (areas_produccion.color), no un
  // token: se inyecta aquí y las tres columnas lo consumen por --area-accent.
  'style' => '--area-accent: ' . $area['color'],
];

ob_start();
?>
<div class="area-board" data-operational-workspace>

  <section class="area-col area-col--enviados" aria-labelledby="area-col-enviados-label">
    <header class="area-col-head">
      <span class="area-col-label" id="area-col-enviados-label">Enviados</span>
      <span class="area-col-count admin-num" id="count-enviados">0</span>
    </header>
    <div class="area-col-items" id="list-enviados" data-scrollable>
      <div class="area-empty"><span class="area-empty__icon" aria-hidden="true">◌</span><span>Sin pedidos</span></div>
    </div>
  </section>

  <section class="area-col area-col--prep" aria-labelledby="area-col-prep-label">
    <header class="area-col-head">
      <span class="area-col-label" id="area-col-prep-label">En preparación</span>
      <span class="area-col-count admin-num" id="count-prep">0</span>
    </header>
    <div class="area-col-items" id="list-prep" data-scrollable>
      <div class="area-empty"><span class="area-empty__icon" aria-hidden="true">◌</span><span>—</span></div>
    </div>
  </section>

  <section class="area-col area-col--listo" aria-labelledby="area-col-listo-label">
    <header class="area-col-head">
      <span class="area-col-label" id="area-col-listo-label">Listos</span>
      <span class="area-col-count admin-num" id="count-listo">0</span>
    </header>
    <div class="area-col-items" id="list-listo" data-scrollable>
      <div class="area-empty"><span class="area-empty__icon" aria-hidden="true">◌</span><span>—</span></div>
    </div>
  </section>

</div>
<?php
$operationalContentHtml = (string) ob_get_clean();
include __DIR__ . '/../operation/partials/shell.php';
?>

  <script>
    window.CP_AREA   = {
      id:     <?= (int) $area['id'] ?>,
      color:  '<?= $h($area['color']) ?>',
      nombre: '<?= $h($area['nombre']) ?>'
    };
    window.CP_TWEAKS = { hero: 'cinema', accent: 'oro', cursor: false, smooth: false, anim: false };
  </script>
  <script src="/build/js/bundle.min.js"></script>

</body>
</html>
