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
  <link rel="stylesheet" href="/build/css/operation.css?v=kds-monocromo-v1">
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

// Estado vacío del primer render, antes de que area.js pinte nada. El icono va
// en SVG y no en el glifo ◌ que había: un carácter lo dibuja la fuente del
// sistema, así que no hereda currentColor y cambia de forma entre plataformas.
// area.js repite exactamente este marcado (SVG_PATHS.idle).
$areaVacio = '<div class="area-empty">'
  . '<span class="area-empty__icon" aria-hidden="true">'
  . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
  . ' stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
  . '<circle cx="12" cy="12" r="8.5" stroke-dasharray="3 3.2"/></svg></span>'
  . '<span>Sin pedidos</span></div>';

$operationalView = 'map';
$operationalModule = 'area';
$operationalModuleTitle = (string) $area['nombre'];
$operationalShowLastUpdate = false;
$operationalHeaderDrawerToggle = false;
// Salida de un toque, igual que en el POS: en el tablero se trabaja con las
// manos ocupadas y un desplegable intermedio sobra.
$operationalUserMenu = false;
/*
 * En la barra quedan tres cosas y ninguna más: la estación, la hora y salir.
 *
 * Se van el wordmark —una pantalla fija de cocina no necesita presentarse—, el
 * subtítulo, el chip con el nombre de quien inició sesión (la estación es
 * compartida, así que no es dato de trabajo) y el botón de rejilla.
 *
 * El título TAMPOCO es enlace. Lo fue un momento, para dejarle al admin un
 * camino de vuelta al panel sin gastar un botón, pero un rótulo subrayado es un
 * afordance ambiguo en una tablet sin hover y contradecía el encargo: esta
 * barra tiene tres cosas.
 *
 * ⚠ Consecuencia asumida: desde el tablero NO se vuelve al panel. Ni rejilla,
 * ni wordmark, ni título enlazado. El admin llega a /admin por la barra de
 * direcciones o cerrando sesión. Es el precio de una barra de tres elementos en
 * una pantalla que pasa el turno entero mostrando lo mismo.
 */
$operationalHeaderBrand = false;
$operationalHeaderUserChip = false;
$operationalHeaderBack = false;
$operationalBrandHref = '/area';
$operationalUsuarioNombre = $usuarioNombre;
$operationalUsuarioRol = $usuarioRol;
$operationalShellClass = 'area-shell';
$operationalMainClass = 'area-main operational-layout';
$operationalMainId = 'area-main';
// El color de la estación (areas_produccion.color) es dato de negocio, no un
// token, y es EL color del tablero: corona las tres columnas por igual. Ya no
// hay un color por estado con el que pudiera chocar — el estado de cada banda
// lo dice su rótulo, en palabras.
$operationalMainAttributes = [
  'aria-label' => 'Tablero de producción',
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
      <?= $areaVacio ?>
    </div>
  </section>

  <section class="area-col area-col--prep" aria-labelledby="area-col-prep-label">
    <header class="area-col-head">
      <span class="area-col-label" id="area-col-prep-label">En preparación</span>
      <span class="area-col-count admin-num" id="count-prep">0</span>
    </header>
    <div class="area-col-items" id="list-prep" data-scrollable>
      <?= $areaVacio ?>
    </div>
  </section>

  <section class="area-col area-col--listo" aria-labelledby="area-col-listo-label">
    <header class="area-col-head">
      <span class="area-col-label" id="area-col-listo-label">Listos</span>
      <span class="area-col-count admin-num" id="count-listo">0</span>
    </header>
    <div class="area-col-items" id="list-listo" data-scrollable>
      <?= $areaVacio ?>
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
