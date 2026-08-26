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
  'description' => 'Cocina italiana con corazón mexicano en Del Valle, CDMX.',
  'url' => 'https://casapestalozzi.com',
  'telephone' => '+525614818297',
  'address' => [
    '@type' => 'PostalAddress',
    'streetAddress' => 'José Enrique Pestalozzi 1250',
    'addressLocality' => 'Del Valle',
    'addressRegion' => 'Ciudad de México',
    'addressCountry' => 'MX',
  ],
  'servesCuisine' => ['Italian', 'Mediterranean', 'Mexican'],
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
  <title>Casa Pestalozzi · Cucina italiana con alma mexicana</title>
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <meta name="description" content="Casa Pestalozzi — cocina italiana con corazón mexicano en la Del Valle, CDMX. Pasta fresca, horno de leña, panadería artesanal y catering para eventos." />
  <meta name="robots" content="index, follow" />
  <link rel="canonical" href="https://casapestalozzi.com/" />

  <!-- Open Graph -->
  <meta property="og:type"        content="restaurant" />
  <meta property="og:title"       content="Casa Pestalozzi · Cucina italiana con alma mexicana" />
  <meta property="og:description" content="Restaurante italiano con corazón mexicano en Del Valle, CDMX. Pasta fresca, pizza de horno de leña, panadería artesanal y catering." />
  <meta property="og:image"       content="https://casapestalozzi.com/build/images/banner.webp" />
  <meta property="og:url"         content="https://casapestalozzi.com/" />
  <meta property="og:locale"      content="es_MX" />
  <meta property="og:site_name"   content="Casa Pestalozzi" />

  <!-- Twitter Card -->
  <meta name="twitter:card"        content="summary_large_image" />
  <meta name="twitter:title"       content="Casa Pestalozzi · Del Valle, CDMX" />
  <meta name="twitter:description" content="Cucina italiana con corazón mexicano en la Del Valle, CDMX." />
  <meta name="twitter:image"       content="https://casapestalozzi.com/build/images/banner.webp" />

  <!-- JSON-LD: Restaurant schema -->
  <script type="application/ld+json">
  <?php echo json_encode($restaurantSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
  </script>

  <?php /* Sólo queda three.js en cdnjs; GSAP, ScrollTrigger y Lenis se sirven
           desde /build/js/vendor y jsdelivr ya no se toca. */ ?>
  <link rel="preconnect" href="https://cdnjs.cloudflare.com" />

  <?php /* Las caras de la portada se piden por adelantado: sin preload el
           navegador no las descubre hasta haber parseado app.css entero.

           La primera es KudosKaps, y va primero porque es la que dibuja el h1:
           el hero usa --logo, no --serif. Antes se precargaba aquí la Bodoni
           normal "porque dibuja el h1", que era falso — y el efecto era el
           contrario del buscado: Bodoni es el respaldo de --logo, así que
           estaba lista al instante y el primer fotograma del título salía en
           didone hasta que llegaba la KudosKaps de verdad.

           La segunda es la itálica de Crimson, que sí dibuja la firma bajo el
           nombre. La Bodoni de titular entra ya en la segunda sección: no
           necesita adelantarse. */ ?>
  <link rel="preload" as="font" type="font/otf" crossorigin
        href="/build/fonts/KudosKapsOneNF.otf" />
  <link rel="preload" as="font" type="font/woff2" crossorigin
        href="/build/fonts/crimson-text-latin-400-italic.woff2" />

  <link rel="stylesheet" href="/build/css/app.css?v=diseno-v3" />
</head>
<body class="reveal-ready" data-hero="cinema" data-page="home">

  <?php include_once __DIR__ . '/_cursor.php'; ?>
  <?php include_once __DIR__ . '/_nav.php'; ?>

  <?php /*
    Orden y tono de las secciones. La regla es que dos vecinas nunca compartan
    tono, contando también las insegnas: son secciones a todos los efectos y
    con la misma tinta a los lados el corte desaparece. El ritmo actual es

      foto · crema · [cafe] · verde · beige · cafe · crema · [verde] ·
      cafe · verde · beige · verde · cafe(pie)

    Al reordenar hay que sincronizar tres sitios: el número del eyebrow de cada
    parcial, .nav-overlay__links y .rail (los dos últimos en _nav.php).
  */ ?>
  <main>
    <?php include_once __DIR__ . '/_hero.php'; ?>
    <?php include_once __DIR__ . '/_nosotros.php'; ?>

    <?php /* include, no include_once: la insegna se repite y con _once sólo
             saldría la primera. Sus variables las libera el propio parcial. */ ?>
    <?php include __DIR__ . '/_insegna.php'; ?>

    <?php include_once __DIR__ . '/_menu.php'; ?>
    <?php include_once __DIR__ . '/_firma.php'; ?>
    <?php include_once __DIR__ . '/_chef.php'; ?>
    <?php include_once __DIR__ . '/_panaderia.php'; ?>

    <?php
      $insegnaTono = 'verde';
      $insegnaLemas = [
        ['Vino & Cantina', 'catas dirigidas'],
        ['A Tavola', 'a la mesa'],
        ['Su Misura', 'a tu medida'],
        ['Benvenuti', 'bienvenidos'],
      ];
      include __DIR__ . '/_insegna.php';
    ?>

    <?php include_once __DIR__ . '/_catas.php'; ?>
    <?php include_once __DIR__ . '/_catering.php'; ?>
    <?php include_once __DIR__ . '/_reserva.php'; ?>
    <?php include_once __DIR__ . '/_ubicacion.php'; ?>
  </main>

  <?php include_once __DIR__ . '/_footer.php'; ?>

  <?php /* Anuncio del restaurante. Va al final del <body> porque es un diálogo:
           dentro del hero heredaría su transform y dejaría de ser fijo. */ ?>
  <?php include_once __DIR__ . '/_announcement.php'; ?>

  <?php /* Mismo motivo que el anuncio: es un diálogo y va fuera del hero. */ ?>
  <?php include_once __DIR__ . '/_privacidad.php'; ?>

  <!-- Tweaks defaults -->
  <script>
    window.CP_TWEAKS = <?php echo json_encode([
      'hero'   => 'cinema',
      'cursor' => true,
      'smooth' => true,
      'anim'   => true,
    ]); ?>;
  </script>

  <?php /*
    Libs de movimiento. Se sirven desde /build/js/vendor: las copia
    `gulp copyVendorJs` desde node_modules, así que la versión la fija
    package.json y el sitio anima igual sin salida a internet. Antes venían de
    dos CDN distintos en tres peticiones que además bloqueaban el render.

    Todos con defer: se ejecutan en orden y después del parseo, que es lo que
    necesita el bundle. ScrollTrigger se registra sobre el gsap global, así que
    el orden entre estas tres líneas no es opcional.
  */ ?>
  <script defer src="/build/js/vendor/gsap.min.js"></script>
  <script defer src="/build/js/vendor/ScrollTrigger.min.js"></script>
  <script defer src="/build/js/vendor/lenis.min.js"></script>
  <?php /*
    Three.js para el lienzo del hero. Se pide la compilación UMD (la que expone
    window.THREE): el bundle de la landing es un concat de ES5, no un módulo,
    así que un `import` no tendría dónde ir. Va con defer y el hero funciona
    igual si no llega —el <img> es el respaldo—, por eso no bloquea el render.
  */ ?>
  <script defer src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>

  <!-- App bundle (compilado por Gulp desde src/js/) -->
  <script defer src="/build/js/bundle.min.js?v=diseno-v3"></script>

</body>
</html>
