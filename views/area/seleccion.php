<?php
/**
 * Entrada de los tableros de producción.
 *
 * Un cocinero no tiene una vista única como el mesero: trabaja en la estación
 * que le toque ese turno. Esta pantalla es su destino tras iniciar sesión
 * (Auth::destinoPorRol) y solo elige estación.
 *
 * Comparte shell y header con el tablero y con el punto de venta: es la misma
 * consola de piso, y el cocinero entra por aquí a la pantalla en la que va a
 * pasar el turno.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
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
  <title>Áreas de producción · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <meta name="robots" content="noindex, nofollow" />
  <?php /* Geist locales: el piso funciona sin red. */ ?>
  <link rel="preload" href="/build/fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/geist-mono-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="/build/css/operation.css?v=consola-bn-v1">
</head>
<body class="admin-body area-page area-select-page operational-page" data-page="area-seleccion" data-operation-module="area">

<?php
$operationalView = 'map';
$operationalModule = 'area-seleccion';
// "Estaciones" y no "Producción": el rótulo de dentro ya dice Producción, y en
// el header repetido se leían como dos títulos de la misma pantalla.
$operationalModuleTitle = 'Estaciones';
$operationalShowLastUpdate = false;
$operationalHeaderDrawerToggle = false;
$operationalUserMenu = false;
$operationalHeaderBack = $esAdmin;
$operationalHeaderBackUrl = '/admin/area';
$operationalBrandHref = '/area';
$operationalUsuarioNombre = $usuarioNombre;
$operationalUsuarioRol = $usuarioRol;
$operationalShellClass = 'area-shell';
$operationalMainClass = 'area-main operational-layout';
$operationalMainId = 'area-main';
$operationalMainAttributes = ['aria-label' => 'Áreas de producción'];

ob_start();
?>
<div class="area-select">
  <div>
    <span class="area-select__eyebrow">Producción</span>
    <h1 class="area-select__title">Elige tu estación</h1>
    <?php if ($usuarioNombre !== '') : ?>
      <p class="area-select__user">Hola, <?php echo $h($usuarioNombre); ?>.</p>
    <?php endif; ?>
  </div>

  <div class="area-select__grid">
    <?php foreach ($areas as $slug => $area) : ?>
      <a class="area-select__card"
         style="--area-accent: <?php echo $h($area['color']); ?>"
         href="/area/<?php echo $h($slug); ?>">
        <span class="area-select__dot" aria-hidden="true"></span>
        <span class="area-select__name"><?php echo $h($area['nombre']); ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php
$operationalContentHtml = (string) ob_get_clean();
include __DIR__ . '/../operation/partials/shell.php';
?>

  <?php /* Sólo por el diálogo de confirmación de salida, que ahora este header
           trae: sin él, un toque en el icono cerraría la sesión sin preguntar. */ ?>
  <script>
    window.CP_TWEAKS = { hero: 'cinema', accent: 'oro', cursor: false, smooth: false, anim: false };
  </script>
  <script src="/build/js/bundle.min.js"></script>

</body>
</html>
