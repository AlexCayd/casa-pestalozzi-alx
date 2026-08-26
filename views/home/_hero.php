<?php
/**
 * Hero.
 *
 * Tres bandas dentro de una pantalla exacta (100vh): rótulo arriba, nombre
 * al centro, ficha de la casa abajo. La foto queda debajo de un velo y de un
 * lienzo WebGL opcional —ver src/js/modules/hero-webgl.js—; el <img> se
 * conserva porque es el respaldo cuando no hay WebGL y, de paso, lo que el
 * navegador precarga mientras el shader compila.
 *
 * La banda de arriba llevaba además un sello circular con las iniciales. Se
 * retiró: repetía el nombre que ya ocupa media pantalla justo debajo y era el
 * único elemento que competía con el título.
 */
?>
<header class="hero" id="hero" data-tono="foto" data-screen-label="Hero">

  <div class="hero__bg" data-parallax-bg>
    <img src="/build/images/navegacion.webp" alt="Mesa servida en el comedor de Casa Pestalozzi" fetchpriority="high" />
  </div>
  <div class="hero__canvas" data-hero-canvas aria-hidden="true"></div>
  <div class="hero__velo" aria-hidden="true"></div>
  <div class="hero__grano" aria-hidden="true"></div>

  <div class="hero__banda hero__insignia">
    <span class="eyebrow hero__eyebrow">Cucina Italiana · Del Valle · CDMX</span>
  </div>

  <?php /* El <br /> del título NO es decorativo: splitTitle() envuelve cada
           línea en un .word con white-space: nowrap, así que sin el salto
           "CASAPESTALOZZI" quedaría en una sola caja sin poder romper. */ ?>
  <div class="hero__banda hero__centro">
    <h1 class="hero__title" data-split>CASA<br />PESTALOZZI</h1>
    <p class="hero__firma" data-reveal><em>Cucina italiana, cuore messicano</em></p>
    <div class="hero__cta" data-reveal>
      <a class="btn-line btn-line--solid" href="#menu" data-magnetic><span>Ver la carta</span><span class="arrow">↗</span></a>
      <a class="btn-text" href="#reserva" data-magnetic>Reservar mesa →</a>
    </div>
  </div>

  <div class="hero__banda hero__pie">
    <div class="hero__meta" data-reveal>
      <div class="mi"><i>Cucina</i><b>Mediterránea</b><span>De temporada</span></div>
      <div class="mi"><i>Forno a legna</i><b>Horno de piedra</b><span>Pizza napolitana</span></div>
      <div class="mi"><i>Panificio</i><b>Panadería</b><span>Masa madre</span></div>
    </div>
    <div class="scroll-cue"><span>Desliza</span><span class="line"></span></div>
  </div>

</header>
