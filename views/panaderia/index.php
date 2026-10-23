<?php
/**
 * Página de prueba del sitio de la Panadería. La sirve PanaderiaController.
 *
 * Sólo comprueba que cada ruta llega a su método: sin estilos ni JS. Lleva
 * `noindex` mientras sea un andamio.
 *
 * @var string $rutaActual
 * @var string $titulo
 * @var array<string, string> $secciones
 */
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo $e($titulo); ?> · Panadería Casa Pestalozzi</title>
  <meta name="robots" content="noindex, nofollow">
</head>
<body>
  <h1>Panadería · <?php echo $e($titulo); ?></h1>
  <p>Ruta: <code><?php echo $e($rutaActual); ?></code></p>

  <nav aria-label="Secciones de la panadería">
    <ul>
      <?php foreach ($secciones as $ruta => $nombre): ?>
        <li>
          <?php if ($ruta === $rutaActual): ?>
            <strong aria-current="page"><?php echo $e($nombre); ?></strong>
          <?php else: ?>
            <a href="<?php echo $e($ruta); ?>"><?php echo $e($nombre); ?></a>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </nav>

  <p><a href="/">Volver al restaurante</a></p>
</body>
</html>
