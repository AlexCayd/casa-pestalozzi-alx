<?php
/**
 * PANADERÍA · Portada.
 *
 * El isologo con la firma de la fachada —«Casa Pestalozzi» y, debajo,
 * «panadería»—, la frase, la línea de hoy (dirección y horario) y la foto de la
 * tienda por dentro en arco, como las portadas del restaurante.
 *
 * «panadería» va en The Seasons y no en la letra manuscrita del rótulo de la
 * fachada: esa letra no está en el manual de identidad.
 */

use Services\Panaderia\PanaderiaConfig;
?>
<section class="section pan-portada" id="portada" data-tono="crema" data-screen-label="Portada">
  <div class="wrap pan-portada__grid">
    <div class="pan-portada__texto">
      <?php /* El isologo es el <h1>. La frase oculta le dice a buscadores y
               lectores de pantalla qué es la casa y dónde está. */ ?>
      <h1 class="pan-portada__isologo">
        <span class="pan-portada__casa">CASA</span>
        <span class="pan-portada__nombre">PESTALOZZI</span>
        <span class="pan-portada__filete" aria-hidden="true">
          <span class="pan-portada__linea"></span>
          <?php
            $medClase = 'pan-portada__medallon';
            $medClasePuntos = 'pan-portada__medallon-puntos';
            $medTrazo = 'var(--accent)';
            $medTinta = 'var(--txt-strong)';
            include __DIR__ . '/../components/marca/medallon.php';
          ?>
          <span class="pan-portada__linea pan-portada__linea--der"></span>
        </span>
        <span class="pan-portada__unidad">panadería</span>
        <span class="sr-only"> de masa madre en la Del Valle, Ciudad de México</span>
      </h1>

      <p class="pan-portada__frase">Pan de masa madre, horneado <em class="accent-italic">cada mañana</em></p>
      <p class="pan-portada__hoy">
        <span><?php echo s(PanaderiaConfig::direccionCorta()); ?></span>
        <span class="pan-portada__sep" aria-hidden="true"></span>
        <span><?php echo s($lineaDeHoy); ?></span>
      </p>

      <div class="pan-portada__cta">
        <a class="btn-line btn-line--solid" href="#vitrina" data-magnetic><span>Ver la vitrina</span><span class="arrow" aria-hidden="true">→</span></a>
        <a class="btn-line btn-line--secondary" href="<?php echo s(PanaderiaConfig::whatsappUrl(PanaderiaConfig::MENSAJE_GENERICO)); ?>" target="_blank" rel="noopener" data-magnetic><span>Encargar por WhatsApp</span><span class="arrow" aria-hidden="true">↗</span></a>
      </div>
    </div>

    <figure class="pan-portada__foto arco" data-portada data-zoom data-zoom-cat="Panadería" data-zoom-name="La panadería por dentro">
      <img src="/build/images/panaderia.webp"<?php echo srcsetImagen('/build/images/panaderia.webp', '(max-width: 880px) 90vw, 40vw'); ?>
           alt="La vitrina de bollería y los anaqueles de pan de la panadería" decoding="async" fetchpriority="high" />
    </figure>
  </div>
</section>
