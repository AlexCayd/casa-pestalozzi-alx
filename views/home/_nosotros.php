<?php /* Nosotros */ ?>
<section class="section" id="nosotros" data-tono="crema" data-screen-label="Nosotros">
  <div class="wrap about__grid">
    <div class="about__text">
      <div class="about__head" data-reveal>
        <span class="eyebrow">01 — La nostra storia</span>
      </div>
      <h2 class="about__title" data-lineas>Cocina italiana, <em class="accent-italic">contada en mexicano.</em></h2>
      <p class="body" data-reveal>Pasta extendida cada mañana, masas de fermentación larga y un horno de leña encendido desde temprano. Sobre esa base entran el chile, el maíz y el producto mexicano — no como guiño, sino como ingrediente de pleno derecho.</p>
      <div class="about__stats" data-reveal>
        <div class="stat"><b data-count="40" data-suffix="+">0</b><span>Platillos de autor</span></div>
        <div class="stat"><b data-count="100" data-suffix="%">0</b><span>Pasta fatta in casa</span></div>
        <div class="stat"><b>Del Valle</b><span>Corazón de la CDMX</span></div>
      </div>
    </div>
    <?php /*
      Mosaico escalonado de diez piezas en cuatro columnas por tres filas. Sólo
      la que ocupa dos filas —el cóctel— lleva arco rebajado: en una celda baja
      el medio punto queda casi recto y su filete se lee como un marco pegado
      alrededor de la foto (el comentario de _nosotros.scss documenta ese
      tropiezo).

      La fachada nocturna llegó aquí desde la portada, y va en la celda ancha:
      es el único plano general del mosaico y en cuadrado se perdía el frente
      del local, que es lo que cuenta.

      El orden no es decorativo: alterna plato, sala y producto para que
      ninguna columna quede siendo "la de la comida".
    */ ?>
    <div class="about__media" data-reveal>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Pescado fresco"><img src="/build/images/nosotros-1.webp" alt="Pescado fresco con hierbas finas" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Emplatado de la casa"><img src="/build/images/emplatado.webp" alt="Emplatado de autor con salsa" loading="lazy" /></div>
      <div class="m arco--rebajado" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Cóctel cítrico"><img src="/build/images/nosotros-3.webp" alt="Cóctel cítrico de la casa" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Corte a la brasa"><img src="/build/images/carne.webp" alt="Corte de carne a la brasa en su punto" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Espárragos de temporada"><img src="/build/images/esparragos.webp" alt="Espárragos de temporada a la brasa" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="La fachada"><img src="/build/images/banner.webp" alt="Fachada de Casa Pestalozzi de noche" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Pizza de horno de piedra"><img src="/build/images/pizza-horno.webp" alt="Pizza recién salida del horno de piedra" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Plato con bebida fresca"><img src="/build/images/nosotros-2.webp" alt="Plato con bebida fresca" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="Pizza y vino"><img src="/build/images/pizza-vino.webp" alt="Pizza acompañada de una copa de vino" loading="lazy" /></div>
      <div class="m" data-portada data-parallax-img data-zoom data-zoom-cat="Nosotros" data-zoom-name="El comedor"><img src="/build/images/mesas.webp" alt="Mesas vestidas en el comedor" loading="lazy" /></div>
    </div>
  </div>
</section>
