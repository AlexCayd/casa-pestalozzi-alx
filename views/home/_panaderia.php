<?php
/**
 * Panadería.
 *
 * Va en tono crema —la sección más clara de la página— justo antes de Catas
 * (vino) y Catering (verde), para que el salto entre las tres se lea de golpe.
 */
?>
<?php /*
  El texto va AL CENTRO y las fotos lo rodean: la tarjeta es una celda más de
  la rejilla, no un bloque flotando encima. Antes se apoyaba pegada a la
  izquierda del mosaico y la mitad derecha quedaba siendo sólo fotografía.

  Por debajo de 900px los dos vuelven al flujo normal, apilados: un anillo de
  ocho fotos alrededor de un texto no cabe en 390px.

  data-tono-franja: la sección es crema, así que la marca fija pasa por encima
  en café — y lo que hay debajo de ella no es el fondo crema sino este mosaico
  de fotografías oscuras. El atributo le dice al negativo de scroll-art.js que
  mientras cruza esta franja se comporte como si fuera una portada.
*/ ?>
<section class="section pan" id="panaderia" data-tono="crema" data-screen-label="Panadería">
  <div class="wrap pan__lienzo">

    <div class="pan__mosaic" data-reveal data-tono-franja="foto">

      <div class="pm pm--alta arco" data-portada data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Pan artesanal">
        <img src="/build/images/galeria-2.webp" alt="Pan artesanal de masa madre" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Repostería de la casa">
        <img src="/build/images/galeria-4.webp" alt="Repostería artesanal" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Vitrina del día">
        <img src="/build/images/panaderia-vitrina.webp" alt="Vitrina con la panadería del día" loading="lazy" />
      </div>
      <div class="pm pm--alta arco" data-portada data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Croissants">
        <img src="/build/images/panaderia-1.webp" alt="Croissants recién horneados" loading="lazy" />
      </div>

      <?php /*
        La tarjeta ocupa el hueco central de la rejilla. Conserva su fibra de
        mantel, su filete y el desenfoque del fondo: sigue leyéndose como una
        pieza apoyada sobre las fotos, sólo que ahora la rejilla le reserva el
        sitio en vez de tener que flotar sobre él.

        Va en NEGATIVO respecto de la sección: la banda es crema y la tarjeta
        café. El color no se escribe en el SCSS — `data-tono="cafe"` redeclara
        la capa 3 entera dentro de la tarjeta (fondo, tintas, acento, las tres
        líneas) y el rótulo, el título, el párrafo y el botón se readaptan
        solos. Es un tono anidado de los que contempla CLAUDE.md: no es el
        fondo bajo la marca fija, así que scroll-art.js lo ignora igual que a
        la lámina del mapa.
      */ ?>
      <?php /* Sin data-reveal propio: la tarjeta entra dentro del bloque del
               mosaico, y dos reveals anidados encadenan dos opacidades sobre
               el mismo texto. */ ?>
      <div class="pan__text" data-tono="cafe">
        <span class="eyebrow">05 — Il panificio</span>
        <h2 class="pan__title" data-lineas>Del horno <em class="accent-italic">a tu mesa</em></h2>
        <p class="body">Aquí el tiempo no corre: fermenta, reposa y se hornea con paciencia. Focaccia, ciabatta, pan de masa madre y la repostería del día. Una experiencia que se siente y se comparte.</p>
        <div class="pan__cta">
          <?php /*
            El texto dice "a la panadería" a propósito: el CTA de Ubicación
            apuntaba al mismo mapa con la misma etiqueta "Cómo llegar", y dos
            botones idénticos en la misma página no se distinguían.
          */ ?>
          <a class="btn-line btn-line--solid" href="https://maps.app.goo.gl/NwDmN5Tjbz3Etf7r7" target="_blank" rel="noopener" data-magnetic><span>Cómo llegar a la panadería</span><span class="arrow">↗</span></a>
        </div>
      </div>

      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Horno artesanal">
        <img src="/build/images/galeria-6.webp" alt="Horno artesanal" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Panadería tradicional">
        <img src="/build/images/galeria-1.webp" alt="Panadería tradicional" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Panes del día">
        <img src="/build/images/galeria-7.webp" alt="Panes artesanales del día" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Masa madre">
        <img src="/build/images/panaderia-3.webp" alt="Hogazas de masa madre en el obrador" loading="lazy" />
      </div>

    </div>

  </div>
</section>
