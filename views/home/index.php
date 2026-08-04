<?php
$schemaDias = [
  0 => 'Sunday',
  1 => 'Monday',
  2 => 'Tuesday',
  3 => 'Wednesday',
  4 => 'Thursday',
  5 => 'Friday',
  6 => 'Saturday',
];
$schemaHorarios = [];

if (!empty($horariosOperacionDisponibles) && is_array($horariosOperacion ?? null)) {
  foreach ($horariosOperacion as $horario) {
    $diaSemana = (int)($horario['dia_semana'] ?? -1);
    if (empty($horario['abierto']) || !isset($schemaDias[$diaSemana])) {
      continue;
    }

    $schemaHorarios[] = [
      '@type' => 'OpeningHoursSpecification',
      'dayOfWeek' => $schemaDias[$diaSemana],
      'opens' => (string)($horario['hora_apertura'] ?? ''),
      'closes' => (string)($horario['hora_cierre'] ?? ''),
    ];
  }
}

$restaurantSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'Restaurant',
  'name' => 'Casa Pestalozzi',
  'description' => 'Cocina mediterránea con corazón mexicano en Del Valle, CDMX.',
  'url' => 'https://casapestalozzi.com',
  'telephone' => '+525614818297',
  'address' => [
    '@type' => 'PostalAddress',
    'streetAddress' => 'José Enrique Pestalozzi 1250',
    'addressLocality' => 'Del Valle',
    'addressRegion' => 'Ciudad de México',
    'addressCountry' => 'MX',
  ],
  'servesCuisine' => ['Mediterranean', 'Mexican'],
  'priceRange' => '$$',
  'image' => 'https://casapestalozzi.com/build/images/banner.webp',
  'sameAs' => ['https://www.instagram.com/casapestalozzi'],
];

if ($schemaHorarios !== []) {
  $restaurantSchema['openingHoursSpecification'] = $schemaHorarios;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Casa Pestalozzi · Cocina mediterránea con alma mexicana</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <meta name="description" content="Casa Pestalozzi — cocina mediterránea con corazón mexicano en la Del Valle, CDMX. Restaurante, panadería artesanal y catering para eventos." />
  <meta name="robots" content="index, follow" />
  <link rel="canonical" href="https://casapestalozzi.com/" />

  <!-- Open Graph -->
  <meta property="og:type"        content="restaurant" />
  <meta property="og:title"       content="Casa Pestalozzi · Cocina mediterránea con alma mexicana" />
  <meta property="og:description" content="Restaurante mediterráneo con corazón mexicano en Del Valle, CDMX. Cocina de autor, panadería artesanal y catering para eventos." />
  <meta property="og:image"       content="https://casapestalozzi.com/build/images/banner.webp" />
  <meta property="og:url"         content="https://casapestalozzi.com/" />
  <meta property="og:locale"      content="es_MX" />
  <meta property="og:site_name"   content="Casa Pestalozzi" />

  <!-- Twitter Card -->
  <meta name="twitter:card"        content="summary_large_image" />
  <meta name="twitter:title"       content="Casa Pestalozzi · Del Valle, CDMX" />
  <meta name="twitter:description" content="Cocina mediterránea con corazón mexicano en la Del Valle, CDMX." />
  <meta name="twitter:image"       content="https://casapestalozzi.com/build/images/banner.webp" />

  <!-- JSON-LD: Restaurant schema -->
  <script type="application/ld+json">
  <?php echo json_encode($restaurantSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
  </script>

  <!-- Preconnect CDN libraries -->
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" />
  <link rel="preconnect" href="https://cdn.jsdelivr.net" />

  <link rel="stylesheet" href="/build/css/app.css?v=reservations-public-redesign-v16" />
</head>
<body class="reveal-ready" data-hero="cinema" data-page="home">

  <a class="skip-link" href="#main-content">Saltar al contenido principal</a>
  <?php include_once __DIR__ . '/_cursor.php'; ?>
  <?php include_once __DIR__ . '/_nav.php'; ?>

  <main id="main-content" tabindex="-1">
    <?php include_once __DIR__ . '/_hero.php'; ?>
    <?php include_once __DIR__ . '/_nosotros.php'; ?>
    <?php include_once __DIR__ . '/_menu.php'; ?>
    <?php include_once __DIR__ . '/_maridaje.php'; ?>
    <?php include_once __DIR__ . '/_firma.php'; ?>
    <?php include_once __DIR__ . '/_chef.php'; ?>
    <?php include_once __DIR__ . '/_panaderia.php'; ?>
    <?php include_once __DIR__ . '/_eventos.php'; ?>
    <?php include_once __DIR__ . '/_reserva.php'; ?>
    <?php include_once __DIR__ . '/_ubicacion.php'; ?>
  </main>

  <?php include_once __DIR__ . '/_footer.php'; ?>

  <!-- Tweaks defaults -->
  <script>
    window.CP_TWEAKS = <?php echo json_encode([
      'hero'   => 'cinema',
      'accent' => 'oro',
      'cursor' => true,
      'smooth' => true,
      'anim'   => true,
    ]); ?>;
  </script>

  <!-- Libs -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@studio-freight/lenis@1.0.42/dist/lenis.min.js"></script>

  <!-- App bundle (compilado por Gulp desde src/js/) -->
  <script src="/build/js/bundle.min.js?v=reservations-public-redesign-v19"></script>

</body>
</html>
