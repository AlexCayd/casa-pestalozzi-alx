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
// El nombre del rol sale de Roles y permisos; «volver al panel» sólo se
// ofrece a quien entra al panel.
$usuarioRol = \Classes\Auth::nombreRol() ?: 'Usuario';
$esAdmin = \Classes\Auth::tienePanel();
/*
 * El color de la estación (areas_produccion.color) es dato de negocio, no un
 * token, y es la IDENTIDAD del tablero: llena la barra superior y tiñe el fondo
 * (ver src/scss/area/_area.scss). Los estados no lo usan; van en rojo, amarillo
 * y verde. --area-ink es la tinta sobre la barra llena
 * (AreasProduccion::tintaSobre), siempre una de las dos anclas del panel.
 *
 * Va en el <body> y no en el <main>: el fondo con el tinte de la estación lo
 * pinta .area-shell, que está por encima del main y no vería la variable.
 */
$areaEstilo = '--area-accent: ' . $area['color']
  . '; --area-ink: var(--admin-' . (($area['tinta'] ?? 'ink') === 'paper' ? 'paper' : 'ink') . ')';
?>
<!DOCTYPE html>
<html lang="es" data-admin-theme="dark">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <?php /* La barra del navegador de la tablet sigue a la de la pantalla. */ ?>
  <meta name="theme-color" content="<?= $h($area['color']) ?>" />
  <title><?= $h($area['nombre']) ?> · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <?php /* Geist locales: el piso funciona sin red. */ ?>
  <link rel="preload" href="/build/fonts/satoshi-variable.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <?php include __DIR__ . '/../components/view-transition-opt-in.php'; ?>
  <link rel="stylesheet" href="<?= $h(recursoVersionado('/build/css/operation.css')) ?>">
</head>
<?php /* .area-tablero separa el tablero del selector de estación, que
          comparte .area-page, .area-shell y el header operativo. */ ?>
<body class="admin-body area-page area-tablero operational-page" data-page="area" data-operation-module="area" style="<?= $h($areaEstilo) ?>">

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
$operationalMainAttributes = [
  'aria-label' => 'Tablero de producción',
];

ob_start();
?>
<div class="area-board" data-operational-workspace>

  <section class="area-banda area-banda--enviados" aria-labelledby="area-banda-enviados-label">
    <header class="area-banda__lomo">
      <div class="area-banda__marca">
        <span class="area-banda__rotulo" id="area-banda-enviados-label">Enviados</span>
        <span class="area-banda__cuenta admin-num" id="count-enviados">0</span>
      </div>
    </header>
    <div class="area-banda__riel" id="list-enviados">
      <?= $areaVacio ?>
    </div>
  </section>

  <section class="area-banda area-banda--prep" aria-labelledby="area-banda-prep-label">
    <header class="area-banda__lomo">
      <div class="area-banda__marca">
        <span class="area-banda__rotulo" id="area-banda-prep-label">En preparación</span>
        <span class="area-banda__cuenta admin-num" id="count-prep">0</span>
      </div>
    </header>
    <div class="area-banda__riel" id="list-prep">
      <?= $areaVacio ?>
    </div>
  </section>

  <section class="area-banda area-banda--listo" aria-labelledby="area-banda-listo-label">
    <header class="area-banda__lomo">
      <div class="area-banda__marca">
        <span class="area-banda__rotulo" id="area-banda-listo-label">Listos</span>
        <span class="area-banda__cuenta admin-num" id="count-listo">0</span>
      </div>
    </header>
    <div class="area-banda__riel" id="list-listo">
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
  <script src="<?= $h(recursoVersionado('/build/js/bundle.min.js')) ?>"></script>

</body>
</html>
