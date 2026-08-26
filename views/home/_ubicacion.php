<?php
/**
 * Ubicación.
 *
 * El "mapa" es ilustración CSS: divs vacíos colocados en porcentajes, sin
 * librería ni tiles. Va marcado data-tono="crema" aunque la sección esté en
 * verde —es una lámina de papel sobre la mesa, y con el tono declarado sus
 * manzanas, calles y rótulos toman solos la tinta que les toca sin una regla
 * de color por pieza.
 */

use Services\SitioConfig;
?>
<section class="section location" id="ubicacion" data-tono="verde" data-screen-label="Ubicación">
  <div class="wrap loc__grid">
    <div class="loc__info">
      <span class="eyebrow" data-reveal>Venite a trovarci</span>
      <h2 class="loc__title" data-lineas>En el corazón <em class="accent-italic">de la Del Valle</em></h2>
      <div class="loc__line" data-reveal>
        <span class="ic">◆</span>
        <div class="ct"><b>Dirección</b><span><?php echo s(SitioConfig::direccion()); ?></span></div>
      </div>
      <div class="loc__line" data-reveal>
        <span class="ic">◆</span>
        <div class="ct"><b>Teléfono</b><a href="tel:<?php echo s(SitioConfig::telefonoTel()); ?>"><?php echo s(SitioConfig::telefonoVisible()); ?></a></div>
      </div>
      <div class="loc__line" data-reveal>
        <span class="ic">◆</span>
        <div class="ct"><b>Correo</b><a href="mailto:<?php echo s(SitioConfig::correo()); ?>"><?php echo s(SitioConfig::correo()); ?></a></div>
      </div>
      <?php include __DIR__ . '/_redes.php'; ?>
    </div>
    <div class="loc__map" data-tono="crema" data-reveal>
      <div class="map2__grid"></div>
      <div class="map2__park"><span class="map2__parklabel">Parque<br />Arboledas</span></div>
      <div class="map2__block b1"></div>
      <div class="map2__block b2"></div>
      <div class="map2__block b3"></div>
      <div class="map2__road road--frias"><span>Heriberto Frías</span></div>
      <div class="map2__road road--pilares"><span>Pilares</span></div>
      <div class="map2__road road--uni"><span>Av. Universidad</span></div>
      <div class="map2__metro"><span class="m">Ⓜ</span> División del Norte</div>
      <div class="loc__pin">
        <span class="ring"></span>
        <span class="dot"></span>
        <span class="tag">Casa Pestalozzi<small>Pestalozzi 1250</small></span>
      </div>
      <div class="map-cta">
        <a class="btn-line btn-line--solid" href="<?php echo s(SitioConfig::mapsUrl()); ?>" target="_blank" rel="noopener" data-magnetic><span>Cómo llegar</span><span class="arrow">↗</span></a>
      </div>
    </div>
  </div>
</section>
