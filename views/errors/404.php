<?php
/**
 * 404 pública. La sirve ErrorController::noEncontrado().
 *
 * Documento suelto, como views/feedback/index.php: no pasa por
 * views/layout.php —legado muerto: DevWebCamp, Google Fonts, Leaflet— ni por el
 * layout del panel.
 *
 * Sin nav, sin rail, sin cursor propio y sin GSAP/Lenis/three, así que tampoco
 * lleva bundle.min.js. Y de ahí la regla que más importa de este archivo:
 * NI UN [data-reveal]. Sin GSAP se queda en opacity:0 (layout/_reset.scss) y la
 * página saldría en blanco, que es el peor fallo posible en un error.
 *
 * No se imprime la ruta pedida: es entrada del usuario y no le sirve de nada a
 * quien la lee.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Página no encontrada · Casa Pestalozzi</title>
  <meta name="robots" content="noindex, nofollow">
  <link rel="icon" type="image/svg+xml" href="/build/images/logo.svg">
  <link rel="apple-touch-icon" href="/build/images/logo.svg">
  <?php /* Las caras que se ven de entrada. Ojo, la cursiva de acento DENTRO de
           un heading va en Bodoni y no en Crimson (h1..h4 .accent-italic,
           layout/_reset.scss), así que aquí se precarga esa y no la que
           precarga feedback.
           Y con ella la REDONDA: el titular es lo único grande de la página y
           su mayor parte va en Bodoni 600 normal. Sin precargarla, esa cara
           llega por `font-display: swap` y el titular se pinta primero en
           Georgia y salta después — justo el gesto donde la casa habla. */ ?>
  <link rel="preload" href="/build/fonts/KudosKapsOneNF.otf" as="font" type="font/otf" crossorigin>
  <link rel="preload" href="/build/fonts/bodoni-moda-latin-standard-normal.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="/build/fonts/bodoni-moda-latin-standard-italic.woff2" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?php echo htmlspecialchars(recursoVersionado('/build/css/app.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>
<?php /* Tono crema: el de :root y el que eligió el otro documento suelto de
         marca. Sobre crema --accent es el café, que es el par de CTA de la
         landing en claro. Una 404 no es una alerta. */ ?>
<body class="cp-error-page" data-tono="crema" data-page="error-404">

  <main class="cp-error-shell">
    <div class="cp-error-content">

      <?php
      // El <h1> de esta página es el titular, así que la marca va en <p>: una
      // página no emite dos <h1>. El parcial hace unset de sus variables.
      $hcpEtiqueta = 'div';
      $hcpNivel = 'p';
      $hcpHref = '/';
      $hcpSubtitulo = 'Del Valle · México';
      $hcpClase = 'cp-error-brand';
      include __DIR__ . '/../templates/header-casa-pestalozzi.php';
      ?>

      <?php /* La voz italiana va marcada con lang: el documento es `es`, y sin
               esto un lector de pantalla pronuncia "Pagina non trovata" con las
               reglas del español. Obliga a partir el rótulo en dos elementos, y
               de ahí el display:block de .cp-error-eyebrow — el .eyebrow es un
               flex con `gap` pensado para la rayita, y en flujo el espacio
               entre las dos partes vuelve a ser el del carácter. */ ?>
      <span class="eyebrow no-rule cp-error-eyebrow">404 — <span lang="it">Pagina non trovata</span></span>

      <?php /* "No existe" repetía en frío lo que el rótulo ya dice en dos
               idiomas, y la casa no habla así. La culpa la asume el sitio, no
               quien escribió la dirección. */ ?>
      <h1 class="cp-error-title">Esta página <em class="accent-italic">se nos perdió</em></h1>

      <p class="cp-error-copy">
        Puede que el enlace haya cambiado de sitio o que la dirección traiga una
        errata. La carta, las reservaciones y el resto de la casa siguen donde
        estaban.
      </p>

      <?php /* Dos salidas y no una: el párrafo nombra la carta, así que dejarla
               sin enlace obliga a volver a la portada y buscarla. El secundario
               apunta al mismo ancla que el CTA del hero (#menu). */ ?>
      <div class="cp-error-actions">
        <a class="btn-line btn-line--solid cp-error-cta" href="/">
          <span class="arrow" aria-hidden="true">←</span><span>Volver al inicio</span>
        </a>
        <a class="btn-line btn-line--secondary" href="/#menu">
          <span>Ver la carta</span><span class="arrow" aria-hidden="true">↗</span>
        </a>
      </div>

    </div>
  </main>

</body>
</html>
