<?php
/**
 * Adelanto de la panadería en la portada del RESTAURANTE.
 *
 * Es una sección de esta página, no la panadería: la landing de la panadería
 * vive en /panaderia (views/panaderia/). Se llamaba _panaderia.php y se
 * renombró para que el nombre no confunda las dos capas.
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
  en verde — y lo que hay debajo de ella no es el fondo crema sino este mosaico
  de fotografías oscuras. El atributo le dice al negativo de scroll-art.js que
  mientras cruza esta franja se comporte como si fuera una portada.
*/ ?>
<section class="section pan" id="panaderia" data-tono="crema" data-screen-label="Panadería">
  <div class="wrap pan__lienzo">

    <div class="pan__mosaic" data-reveal data-tono-franja="foto">

      <div class="pm pm--alta arco" data-portada data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Pan artesanal">
        <img src="/build/images/galeria-2.webp"<?php echo srcsetImagen('/build/images/galeria-2.webp'); ?> decoding="async" alt="Pan artesanal de masa madre" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Repostería de la casa">
        <img src="/build/images/galeria-4.webp"<?php echo srcsetImagen('/build/images/galeria-4.webp'); ?> decoding="async" alt="Repostería artesanal" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Vitrina del día">
        <img src="/build/images/panaderia-vitrina.webp"<?php echo srcsetImagen('/build/images/panaderia-vitrina.webp'); ?> decoding="async" alt="Vitrina con la panadería del día" loading="lazy" />
      </div>
      <div class="pm pm--alta arco" data-portada data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Croissants">
        <img src="/build/images/panaderia-1.webp"<?php echo srcsetImagen('/build/images/panaderia-1.webp'); ?> decoding="async" alt="Croissants recién horneados" loading="lazy" />
      </div>

      <?php /*
        La tarjeta ocupa el hueco central de la rejilla. Conserva su fibra de
        mantel, su filete y el desenfoque del fondo: sigue leyéndose como una
        pieza apoyada sobre las fotos, sólo que ahora la rejilla le reserva el
        sitio en vez de tener que flotar sobre él.

        Va en NEGATIVO respecto de la sección: la banda es crema y la tarjeta
        de oro. El color no se escribe en el SCSS — `data-tono="oro"` redeclara
        la capa 3 entera dentro de la tarjeta (fondo, tintas, acento, las tres
        líneas) y el rótulo, el título, el párrafo y el botón se readaptan
        solos. Es un tono anidado de los que contempla CLAUDE.md: no es el
        fondo bajo la marca fija, así que scroll-art.js lo ignora igual que a
        la lámina del mapa.
      */ ?>
      <?php /* Sin data-reveal propio: la tarjeta entra dentro del bloque del
               mosaico, y dos reveals anidados encadenan dos opacidades sobre
               el mismo texto. */ ?>
      <div class="pan__text" data-tono="oro">
        <span class="eyebrow">05 — Il panificio</span>
        <h2 class="pan__title" data-lineas>Del horno <em class="accent-italic">a tu mesa</em></h2>
        <p class="body">Aquí el tiempo no corre: fermenta, reposa y se hornea con paciencia. Focaccia, ciabatta, pan de masa madre y la repostería del día. Una experiencia que se siente y se comparte.</p>
        <div class="pan__cta">
          <?php /*
            La panadería tiene su propia landing (/panaderia) y otra dirección
            (Calle San Borja 1026). Por eso «Cómo llegar» no abre un mapa desde
            aquí: abría el mismo enlace que el restaurante, y uno de los dos
            llevaba al sitio equivocado. Ahora lleva a la sección de la
            panadería, que tiene su mapa y sus datos (PanaderiaConfig), y la
            portada no necesita leer nada de la panadería.
          */ ?>
          <a class="btn-line btn-line--solid" href="/panaderia" data-magnetic><span>Conocer la panadería</span><span class="arrow" aria-hidden="true">→</span></a>
          <a class="btn-line btn-line--secondary" href="/panaderia#visitanos" data-magnetic><span>Cómo llegar</span><span class="arrow" aria-hidden="true">→</span></a>
        </div>
      </div>

      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Horno artesanal">
        <img src="/build/images/galeria-6.webp"<?php echo srcsetImagen('/build/images/galeria-6.webp'); ?> decoding="async" alt="Horno artesanal" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Panadería tradicional">
        <img src="/build/images/galeria-1.webp"<?php echo srcsetImagen('/build/images/galeria-1.webp'); ?> decoding="async" alt="Panadería tradicional" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Panes del día">
        <img src="/build/images/galeria-7.webp"<?php echo srcsetImagen('/build/images/galeria-7.webp'); ?> decoding="async" alt="Panes artesanales del día" loading="lazy" />
      </div>
      <div class="pm" data-parallax-img data-zoom data-zoom-cat="Panadería" data-zoom-name="Masa madre">
        <img src="/build/images/panaderia-3.webp"<?php echo srcsetImagen('/build/images/panaderia-3.webp'); ?> decoding="async" alt="Hogazas de masa madre en el obrador" loading="lazy" />
      </div>

    </div>

  </div>
</section>
