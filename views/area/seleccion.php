<?php
/**
 * Entrada de los tableros de producción.
 *
 * Un cocinero no tiene una vista única como el mesero: trabaja en la estación
 * que le toque ese turno. Esta pantalla es su destino tras iniciar sesión
 * (Auth::destinoPorRol) y solo elige estación.
 *
 * Vive fuera del layout de admin a propósito: es pantalla de piso, como los
 * propios tableros.
 */
$h = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$usuarioNombre = trim((string) ($_SESSION['nombre'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Áreas de producción · Casa Pestalozzi</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <meta name="robots" content="noindex, nofollow" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="/build/css/app.css" />
</head>
<body class="area-page area-select-page" data-page="area-seleccion" data-modo="oscuro">

  <div class="area-select">
    <header class="area-select__head">
      <div>
        <span class="area-select__eyebrow">Producción</span>
        <h1 class="area-select__title">Elige tu estación</h1>
        <?php if ($usuarioNombre !== '') : ?>
          <p class="area-select__user">Hola, <?php echo $h($usuarioNombre); ?>.</p>
        <?php endif; ?>
      </div>
      <form class="area-select__logout" method="POST" action="/logout">
        <button type="submit">Cerrar sesión</button>
      </form>
    </header>

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

</body>
</html>
