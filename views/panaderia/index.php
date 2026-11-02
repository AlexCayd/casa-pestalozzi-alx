<?php
/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  PANADERÍA — shell de la landing /panaderia.                  │
 * └───────────────────────────────────────────────────────────────┘
 *
 * Variables del controlador (Controllers\Panaderia\PanaderiaController):
 * $vitrina, $enLaCarta, $temporada, $semana, $lineaDeHoy.
 *
 * Orden y tono de las bandas. Dos vecinas nunca comparten tono:
 *
 *   crema (portada) · beige (vitrina) · verde (temporada) · oro (encargos) ·
 *   beige (restaurante) · verde (visítanos) · verde (pie)
 *
 * Visítanos y el pie son verdes los dos, la misma excepción declarada de la
 * portada del restaurante: el pie pinta su --bg-alt y abre con el filete de
 * oro, así que el corte se ve.
 *
 * Al reordenar hay que sincronizar el número del eyebrow de cada parcial y
 * $navDestinos (aquí abajo).
 */

use Services\Configuration\SitioConfig;
use Services\Configuration\PaletaLanding;
use Services\Panaderia\PanaderiaConfig;

$sitioUrl = SitioConfig::url();
$panUrl = $sitioUrl . '/panaderia';

/*
 * SEO. El título dice qué y dónde, a ~60 caracteres; la descripción, a ~155,
 * cuenta lo que hay y termina en la acción.
 */
$seoTitulo = 'Panadería Casa Pestalozzi | Pan de masa madre en la Del Valle';
$seoDescripcion = 'Pan de masa madre, bollería y dulces horneados cada mañana en San Borja 1026, Del Valle, CDMX. Pan de temporada y encargos por WhatsApp.';
$seoImagen = $sitioUrl . '/build/images/panaderia.webp';
$seoImagenAlt = 'Vitrina y anaqueles de la panadería Casa Pestalozzi';
$seoColorTema = PaletaLanding::vigente()['crema'] ?? null;

$schemaDias = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];
$schemaHorarios = [];
foreach ($semana as $fila) {
  if ($fila['abre'] === null) {
    continue;
  }
  $schemaHorarios[] = [
    '@type' => 'OpeningHoursSpecification',
    'dayOfWeek' => $schemaDias[$fila['dia_semana']],
    'opens' => $fila['abre'],
    'closes' => $fila['cierra'],
  ];
}

/*
 * Datos estructurados: una Bakery con su propio @id, enlazada al restaurante
 * de la portada (`/#restaurante`) como su organización madre. Sólo datos que
 * existen: nada de coordenadas inventadas.
 */
$siteSchema = [
  '@context' => 'https://schema.org',
  '@type' => 'Bakery',
  '@id' => $panUrl . '#panaderia',
  'name' => PanaderiaConfig::nombre(),
  'description' => 'Panadería de masa madre de Casa Pestalozzi en la Del Valle, Ciudad de México: pan, bollería, dulces y pan de temporada.',
  'url' => $panUrl,
  'image' => [$seoImagen],
  'telephone' => PanaderiaConfig::telefonoTel(),
  'address' => ['@type' => 'PostalAddress'] + PanaderiaConfig::DIRECCION_POSTAL,
  'hasMap' => PanaderiaConfig::mapsUrl(),
  'priceRange' => '$',
  'openingHoursSpecification' => $schemaHorarios,
  'parentOrganization' => ['@id' => $sitioUrl . '/#restaurante'],
  'sameAs' => [SitioConfig::instagramUrl()],
];

/* El menú: los destinos de esta página y su ficha. */
$navDestinos = [
  ['#vitrina',     'La vitrina',     'Pan, bollería y dulces'],
  ['#temporada',   'De temporada',   'Lo que sólo hay unas semanas'],
  ['#encargos',    'Encargos',       'Pedidos grandes o con fecha'],
  ['#restaurante', 'El restaurante', 'Nuestro pan, en la mesa de la casa'],
  ['#visitanos',   'Cómo llegar',    'Dirección, mapa y horario'],
];
$navMarcaHref = '#portada';
$navMarcaSubtitulo = 'Panadería · Del Valle';
$navFichaCta = ['href' => PanaderiaConfig::whatsappUrl(PanaderiaConfig::MENSAJE_GENERICO), 'texto' => 'Encargar por WhatsApp', 'externo' => true];
$navFichaDatos = [
  ['texto' => $lineaDeHoy],
  ['texto' => PanaderiaConfig::direccion(), 'href' => PanaderiaConfig::mapsUrl(), 'externo' => true],
  ['texto' => PanaderiaConfig::telefonoVisible(), 'href' => 'tel:' . PanaderiaConfig::telefonoTel()],
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
  <link rel="canonical" href="<?php echo s($panUrl); ?>" />
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg" />
  <link rel="apple-touch-icon" href="/build/images/logo.svg" />
  <?php if ($seoColorTema) : ?>
    <meta name="theme-color" content="<?php echo s($seoColorTema); ?>" />
  <?php endif; ?>
  <meta name="geo.region" content="MX-CMX" />
  <meta name="geo.placename" content="Del Valle, Ciudad de México" />

  <!-- Open Graph -->
  <meta property="og:type"         content="website" />
  <meta property="og:site_name"    content="Casa Pestalozzi" />
  <meta property="og:locale"       content="es_MX" />
  <meta property="og:url"          content="<?php echo s($panUrl); ?>" />
  <meta property="og:title"        content="Panadería Casa Pestalozzi · Pan de masa madre en la Del Valle" />
  <meta property="og:description"  content="<?php echo s($seoDescripcion); ?>" />
  <meta property="og:image"        content="<?php echo s($seoImagen); ?>" />
  <meta property="og:image:alt"    content="<?php echo s($seoImagenAlt); ?>" />
  <meta name="twitter:card"        content="summary_large_image" />

  <!-- Datos estructurados: Bakery -->
  <script type="application/ld+json">
  <?php echo json_encode($siteSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>
  </script>

  <?php /* KudosKaps dibuja el isologo de la portada; The Seasons y TT
           Wellingtons, la frase y el cuerpo del primer encuadre. */ ?>
  <link rel="preload" as="font" type="font/otf" crossorigin href="/build/fonts/KudosKapsOneNF.otf" />
  <?php echo precargaFuente('/build/fonts/marca/the-seasons-regular.woff2'); ?>
  <?php echo precargaFuente('/build/fonts/marca/tt-wellingtons-regular.woff2'); ?>

  <?php /* Hoja propia de la panadería (src/scss/panaderia/app-panaderia.scss):
           trae la base de marca y sus bandas, no la portada del restaurante.
           Versión por fecha del archivo, como app.css. */ ?>
  <link rel="stylesheet" href="<?php echo s(recursoVersionado('/build/css/panaderia.css')); ?>" />
  <?php /* La paleta de Configuración → Paleta de la landing. DESPUÉS de la hoja:
           misma especificidad que su :root, gana por orden. */ ?>
  <?php echo PaletaLanding::estiloEnLinea(); ?>
</head>
<?php /* `marca-en-hero`: la marca fija nace retirada porque la página abre con
         el isologo a la vista; panaderia.js la devuelve cuando la portada
         termina de pasar bajo ella. */ ?>
<body class="reveal-ready marca-en-hero" data-page="panaderia">

  <?php include __DIR__ . '/../components/marca/cursor.php'; ?>
  <?php include __DIR__ . '/../components/marca/nav.php'; ?>

  <main>
    <?php include __DIR__ . '/_portada.php'; ?>
    <?php include __DIR__ . '/_vitrina.php'; ?>
    <?php include __DIR__ . '/_temporada.php'; ?>
    <?php include __DIR__ . '/_encargos.php'; ?>
    <?php include __DIR__ . '/_restaurante.php'; ?>
    <?php include __DIR__ . '/_visitanos.php'; ?>
  </main>

  <?php
    /* ═══ MARCA → views/components/marca/pie.php, con los datos de la panadería. */
    $pieLema = 'Il pane di casa, ogni mattina.';
    $pieRedes = PanaderiaConfig::redes();
    $pieColumnas = [
      ['titulo' => 'La panadería', 'enlaces' => [
        ['texto' => 'La vitrina', 'href' => '#vitrina'],
        ['texto' => 'De temporada', 'href' => '#temporada'],
        ['texto' => 'Encargos', 'href' => '#encargos'],
        ['texto' => PanaderiaConfig::direccionCorta(), 'href' => PanaderiaConfig::mapsUrl(), 'externo' => true],
        ['texto' => PanaderiaConfig::telefonoVisible(), 'href' => 'tel:' . PanaderiaConfig::telefonoTel()],
      ]],
      ['titulo' => 'La casa', 'enlaces' => [
        ['texto' => 'El restaurante', 'href' => '/'],
        ['texto' => 'La carta', 'href' => '/#menu'],
        ['texto' => 'Catas y vinos', 'href' => '/#catas'],
        ['texto' => 'Reservar mesa', 'href' => '/#reserva'],
      ]],
    ];
    $pieHorario = array_map(static fn (array $f): array => [
      'dia' => $f['nombre'],
      'hoy' => $f['hoy'],
      'texto' => $f['abre'] === null ? 'Cerrado' : $f['abre'] . '–' . $f['cierra'],
      'excepcion' => null,
    ], $semana);
    $pieCopia = 'Casa Pestalozzi · Panadería · San Borja 1026, Del Valle';
    // Sin aviso de privacidad: esta página no recoge datos (los encargos van
    // por WhatsApp) y el diálogo vive en la portada.
    $piePrivacidad = false;
    include __DIR__ . '/../components/marca/pie.php';
  ?>

  <?php include __DIR__ . '/../components/marca/visor.php'; ?>

  <script>
    window.CP_TWEAKS = <?php echo json_encode(['hero' => 'cinema', 'cursor' => true, 'smooth' => true, 'anim' => true]); ?>;
  </script>

  <?php /* Las mismas libs de movimiento que la portada, servidas desde
           /build/js/vendor y con defer. ScrollTrigger se registra sobre el gsap
           global: el orden de estas tres líneas no es opcional. */ ?>
  <script defer src="/build/js/vendor/gsap.min.js"></script>
  <script defer src="/build/js/vendor/ScrollTrigger.min.js"></script>
  <script defer src="/build/js/vendor/lenis.min.js"></script>

  <?php /* Bundle propio (paths.panaderiaJs en gulpfile.js): la base de marca y
           src/js/panaderia/. No carga el JS de la portada del restaurante. */ ?>
  <script defer src="<?php echo s(recursoVersionado('/build/js/panaderia.min.js')); ?>"></script>
</body>
</html>
