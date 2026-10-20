<?php /* Lo mejor de la casa — galería draggable (renderizada via JS) */ ?>
<section class="section signature" id="firma" data-tono="beige" data-screen-label="Lo mejor de la casa">
  <div class="signature__head wrap" style="max-width:none">
    <div>
      <span class="eyebrow" data-reveal>03 — Le specialità</span>
      <h2 class="signature__title" data-lineas>Especiales de <em class="accent-italic">temporada</em></h2>
    </div>
    <span class="signature__hint" data-reveal>Clic para ampliar <span style="font-family:var(--serif)">→</span></span>
  </div>
  <?php /* La tira avanza sola con el scroll: la mueve GSAP sobre `x` desde
           vetrinaConScroll() en gallery.js. Sin scroll propio, así que tampoco
           necesita [data-lenis-prevent] — sólo lo recupera el respaldo sin
           GSAP, y esa marca la pone el JS. */ ?>
  <div class="gallery-track" id="galleryTrack"></div>
</section>
