<?php
/**
 * PANADERÍA · El restaurante.
 *
 * El puente a la landing principal (/): quien conoce la casa por el pan llega
 * al restaurante con una razón concreta, que el mismo pan está en la carta.
 * Los platillos salen de PanaderiaConfig::EN_LA_CARTA y sólo se enseñan los
 * que siguen activos (PanaderiaCatalogo::enLaCarta()).
 *
 * No escribe la dirección del restaurante: eso es de SitioConfig, que la
 * panadería no lee. La landing principal la tiene, con su mapa.
 */
?>
<section class="section pan-resto" id="restaurante" data-tono="beige" data-screen-label="El restaurante">
  <div class="wrap pan-resto__grid">
    <figure class="pan-resto__foto arco" data-portada data-zoom data-zoom-cat="Casa Pestalozzi" data-zoom-name="El restaurante">
      <img src="/build/images/restaurante.webp"<?php echo srcsetImagen('/build/images/restaurante.webp', '(max-width: 880px) 90vw, 40vw'); ?> alt="Pizza margarita y un florero en una mesa del restaurante Casa Pestalozzi" loading="lazy" decoding="async" />
    </figure>

    <div class="pan-resto__texto">
      <span class="eyebrow" data-reveal>04 — Il ristorante</span>
      <h2 class="pan-titulo" data-lineas>Este pan también <em class="accent-italic">llega a la mesa</em></h2>
      <p class="body" data-reveal>Casa Pestalozzi es también un restaurante italiano con alma mexicana, en la Del Valle. El pan de su carta sale de este horno.</p>

      <?php if ($enLaCarta !== []) : ?>
        <ul class="pan-resto__platos" data-reveal>
          <?php foreach ($enLaCarta as $rPlato) : ?>
            <li>
              <span class="pan-resto__plato"><?php echo s($rPlato['nombre']); ?></span>
              <span class="pan-resto__pan"><?php echo s($rPlato['pan']); ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <div class="pan-resto__cta" data-reveal>
        <a class="btn-line btn-line--solid" href="/" data-magnetic><span>Conocer el restaurante</span><span class="arrow" aria-hidden="true">→</span></a>
        <a class="btn-line btn-line--secondary" href="/#reserva" data-magnetic><span>Reservar mesa</span><span class="arrow" aria-hidden="true">→</span></a>
      </div>
    </div>
  </div>
</section>
<?php unset($rPlato); ?>
