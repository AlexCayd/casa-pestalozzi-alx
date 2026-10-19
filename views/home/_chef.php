<?php /* La cocina: quién cocina y cómo, en lo que se lee de un vistazo.

  Tenía cita, un párrafo, dos columnas con rótulo y la firma: cinco bloques de
  texto junto a una foto que ya cuenta lo mismo. Queda una línea de entrada,
  tres claves que se leen en dos segundos, la firma y el enlace a la carta. */ ?>
<section class="section chef" id="chef" data-tono="oro" data-screen-label="La cocina">
  <?php echo ornamento('cubiertos', 'orn--seccion', true); ?>
  <div class="wrap chef__grid">
    <div class="chef__portrait arco" data-reveal data-portada data-parallax-img data-zoom
         data-zoom-name="La brigada de Casa Pestalozzi" data-zoom-cat="La cocina">
      <img src="/build/images/testimonios.webp" alt="La brigada de cocina de Casa Pestalozzi" loading="lazy" />
    </div>
    <div class="chef__text">
      <span class="eyebrow" data-reveal>04 — La cucina</span>
      <?php /* UX writing: el título responde a la pregunta que el visitante tiene
               delante de la foto —¿quién cocina?— en vez de una metáfora. Cada
               clave dice qué es y qué significa en el plato, en una línea, y la
               sección termina en un siguiente paso. */ ?>
      <h2 class="chef__title" data-lineas>Quién cocina <em class="accent-italic">para ti</em></h2>
      <p class="lead" data-reveal>Una brigada que respeta el producto como en Italia y se atreve como en México.</p>
      <dl class="chef__claves" data-reveal>
        <div>
          <dt>Pasta fresca</dt>
          <dd>Se extiende a diario en nuestra cocina.</dd>
        </div>
        <div>
          <dt>Horno de leña</dt>
          <dd>Pizza napolitana y pan, con piedra y fuego de leña.</dd>
        </div>
        <div>
          <dt>Producto local</dt>
          <dd>De productores de la región; la carta cambia con cada temporada.</dd>
        </div>
      </dl>
      <div class="chef__pie" data-reveal>
        <p class="chef__sig">— La brigada de Casa Pestalozzi</p>
        <a class="chef__enlace" href="#menu">Ver la carta
          <svg viewBox="0 0 18 12" width="18" height="12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M1 6h15M11 1l5 5-5 5"/></svg>
        </a>
      </div>
    </div>
  </div>
</section>
