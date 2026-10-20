<?php
use Services\Configuration\SitioConfig;
use Services\Configuration\PaletaLanding;

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

/*
 * SEO. Todo lo que lleva la URL sale de SitioConfig::url() (SITIO_URL), igual
 * que robots.txt y el sitemap: cambiar de dominio es tocar una variable.
 *
 * El título va a ~60 caracteres y dice QUÉ es y DÓNDE, que es lo que se
 * busca («restaurante italiano Del Valle»); la frase de marca vive en la
 * página. La descripción, a ~155, cuenta lo que hay y termina en la acción.
 */
$sitioUrl = SitioConfig::url();
$seoTitulo = 'Casa Pestalozzi | Restaurante italiano en la Del Valle, CDMX';
$seoDescripcion = 'Restaurante italiano con alma mexicana en la Del Valle, CDMX: pasta fresca, pizza de horno de leña, pan de masa madre, catas y catering. Reserva en línea.';
$seoImagen = $sitioUrl . '/build/images/banner.webp';
$seoImagenAlt = 'Mesa servida en el comedor de Casa Pestalozzi';
// El color de la barra del navegador en el móvil: el papel crema de la
// portada, leído de la paleta vigente (la de Configuración, si la hay) para
// no escribir un hex en la vista.
$seoColorTema = PaletaLanding::vigente()['crema'] ?? null;

/*
 * Datos estructurados: el restaurante y el sitio, enlazados por @id. Además de
 * lo de siempre (dirección, horario, cocina), dicen que se reserva en línea
 * —con la acción de reservar apuntando al formulario de la portada—, dónde
 * está la carta y el mapa. Sólo datos que existen en la configuración: nada
 * de coordenadas ni código postal inventados.
 */
$restaurantSchema = [
  '@type' => 'Restaurant',
  '@id' => $sitioUrl . '/#restaurante',
  'name' => 'Casa Pestalozzi',
  'description' => 'Cocina italiana con alma mexicana en la colonia Del Valle, Ciudad de México: pasta fresca, pizza de horno de leña, panadería de masa madre, catas de vino y catering para eventos.',
  'url' => $sitioUrl . '/',
  'logo' => $sitioUrl . '/build/images/logo.svg',
  'image' => [$seoImagen],
  'telephone' => SitioConfig::telefonoTel(),
  'email' => SitioConfig::correo(),
  'address' => [
    '@type' => 'PostalAddress',
    'streetAddress' => 'José Enrique Pestalozzi 1250',
    'addressLocality' => 'Del Valle',
    'addressRegion' => 'Ciudad de México',
    'addressCountry' => 'MX',
  ],
  'hasMap' => SitioConfig::mapsUrl(),
  'servesCuisine' => ['Italiana', 'Mediterránea', 'Mexicana'],
  'priceRange' => '$$',
  'menu' => $sitioUrl . '/menu/pdf',
  'hasMenu' => $sitioUrl . '/menu/pdf',
  'acceptsReservations' => true,
  'potentialAction' => [
    '@type' => 'ReserveAction',
    'target' => [
      '@type' => 'EntryPoint',
      'urlTemplate' => $sitioUrl . '/#reserva',
      'inLanguage' => 'es-MX',
      'actionPlatform' => [
        'http://schema.org/DesktopWebPlatform',
        'http://schema.org/MobileWebPlatform',
      ],
    ],
    'result' => ['@type' => 'FoodEstablishmentReservation', 'name' => 'Reservar mesa'],
  ],
  'sameAs' => [SitioConfig::instagramUrl()],
];

if ($schemaHorarios !== []) {
  $restaurantSchema['openingHoursSpecification'] = $schemaHorarios;
}

$siteSchema = [
  '@context' => 'https://schema.org',
  '@graph' => [
    $restaurantSchema,
    [
      '@type' => 'WebSite',
      '@id' => $sitioUrl . '/#sitio',
      'url' => $sitioUrl . '/',
      'name' => 'Casa Pestalozzi',
      'inLanguage' => 'es-MX',
      'publisher' => ['@id' => $sitioUrl . '/#restaurante'],
    ],
  ],
];
?>
<!DOCTYPE html>
<html lang="es-MX">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo s($seoTitulo); ?></title>
  <meta name="description" content="<?php echo s($seoDescripcion); ?>" />
  <meta name="robots" content="index, follow, max-image-preview:large" />
  <link rel="canonical" href="<?php echo s($sitioUrl); ?>/" />
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <?php if ($seoColorTema) : ?>
    <meta name="theme-color" content="<?php echo s($seoColorTema); ?>" />
  <?php endif; ?>
  <meta name="geo.region" content="MX-CMX" />
  <meta name="geo.placename" content="Del Valle, Ciudad de México" />

  <!-- Open Graph -->
  <meta property="og:type"         content="restaurant.restaurant" />
  <meta property="og:site_name"    content="Casa Pestalozzi" />
  <meta property="og:locale"       content="es_MX" />
  <meta property="og:url"          content="<?php echo s($sitioUrl); ?>/" />
  <meta property="og:title"        content="Casa Pestalozzi · Restaurante italiano con alma mexicana" />
  <meta property="og:description"  content="<?php echo s($seoDescripcion); ?>" />
  <meta property="og:image"        content="<?php echo s($seoImagen); ?>" />
  <meta property="og:image:width"  content="2560" />
  <meta property="og:image:height" content="1920" />
  <meta property="og:image:alt"    content="<?php echo s($seoImagenAlt); ?>" />

  <!-- Twitter Card -->
  <meta name="twitter:card"        content="summary_large_image" />
  <meta name="twitter:title"       content="Casa Pestalozzi · Del Valle, CDMX" />
  <meta name="twitter:description" content="<?php echo s($seoDescripcion); ?>" />
  <meta name="twitter:image"       content="<?php echo s($seoImagen); ?>" />
  <meta name="twitter:image:alt"   content="<?php echo s($seoImagenAlt); ?>" />

  <!-- Datos estructurados: Restaurant + WebSite -->
  <script type="application/ld+json">
  <?php echo json_encode($siteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>
  </script>

  <?php /* Las caras de la portada se piden por adelantado: sin preload el
           navegador no las descubre hasta haber parseado app.css entero.

           KudosKaps va primero porque dibuja el h1 (el isologo usa --logo).
           Luego The Seasons, que escribe la frase bajo la marca, y la regular de TT Wellingtons, que es el cuerpo de todo el
           primer encuadre. precargaFuente() no emite nada mientras el archivo
           de una cara del manual no esté en public/build/fonts/marca/. */ ?>
  <link rel="preload" as="font" type="font/otf" crossorigin
        href="/build/fonts/KudosKapsOneNF.otf" />
  <?php echo precargaFuente('/build/fonts/marca/the-seasons-regular.woff2'); ?>
  <?php echo precargaFuente('/build/fonts/marca/tt-wellingtons-regular.woff2'); ?>

  <?php /* Versión por fecha del archivo (recursoVersionado), no a mano: el
           .htaccess los sirve con caché larga e inmutable, así que una
           versión olvidada dejaría la hoja vieja en el navegador. */ ?>
  <link rel="stylesheet" href="<?php echo s(recursoVersionado('/build/css/app.css')); ?>" />
  <?php /* La paleta que se edita en Configuración → Paleta de la landing.
           Redeclara la capa 1 de _reset.scss y va DESPUÉS de app.css: misma
           especificidad que su :root, así que gana por orden. Sin cambios
           guardados no emite nada. */ ?>
  <?php echo \Services\Configuration\PaletaLanding::estiloEnLinea(); ?>
</head>
<?php /* `marca-en-hero` sale ya en el marcado: la marca fija y el índice lateral
         nacen retirados porque la página abre con el isologo a la vista.
         Ponerla desde JS dejaba un parpadeo en cada carga —la marca pintada
         encima del rótulo del hero hasta que llegaba el bundle— y, sin JS, las
         dos piezas montadas sobre el rótulo y sobre la mesa. initMarcaHero()
         (hero.js) la quita cuando el hero termina de pasar bajo la marca. */ ?>
<body class="reveal-ready marca-en-hero" data-hero="cinema" data-page="home">

  <?php include_once __DIR__ . '/_cursor.php'; ?>
  <?php include_once __DIR__ . '/_nav.php'; ?>

  <?php /*
    Orden y tono de las secciones. La regla es que dos vecinas nunca compartan
    tono, contando también las insegnas: son secciones a todos los efectos y
    con la misma tinta a los lados el corte desaparece. El ritmo actual es

      crema(hero) · beige · [oro] · verde · beige · oro · crema · [verde] ·
      oro · verde · beige · verde · verde(pie)

    El hero dejó de ser fotografía y es papel crema, así que Nosotros pasó de
    crema a lino: con las dos en crema el corte bajo la portada desaparecía.

    El pie es la única excepción declarada: Ubicación y él son verdes, pero el
    pie pinta su --bg-alt (el verde hondo) y abre con el filete de oro, así que
    el corte se ve. Con el café fuera de la marca no queda otro fondo oscuro, y
    un pie de oro dejaba su texto tenue en 3.6:1 sobre ese mismo --bg-alt.

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
  <?php /* Three.js salió con el hero de fotografía: el hero de ahora es SVG y
           CSS, y la landing ya no pide nada a un CDN. */ ?>

  <!-- App bundle (compilado por Gulp desde src/js/) -->
  <script defer src="<?php echo s(recursoVersionado('/build/js/bundle.min.js')); ?>"></script>

</body>
</html>
