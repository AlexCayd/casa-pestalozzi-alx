<?php /* Footer + lightbox + tweaks panel + scripts */ ?>

<footer class="foot">
  <div class="wrap">
    <div class="foot__top">
      <div class="foot__brand">
        <h3 class="bm">Casa Pestalozzi</div>
        <p>Cocina mediterránea con corazón mexicano.</p>
      </div>
      <div class="foot__cols">
        <div class="foot__col">
          <h6>Explora</h6>
          <a href="#menu">La Carta</a>
          <a href="#maridaje">Maridaje</a>
          <a href="#panaderia">Panadería</a>
          <a href="#eventos">Eventos</a>
        </div>
        <div class="foot__col">
          <h6>Visita</h6>
          <span>Pestalozzi 1250, CDMX</span>
          <a href="tel:+525614818297">56 1481 8297</a>
          <a href="#reserva">Reservar mesa</a>
        </div>
        <div class="foot__col">
          <h6>Horario</h6>
          <span>Lun 8:30 — 15:00</span>
          <span>Mar–Sáb 8:30 — 22:00</span>
          <span>Dom 8:30 — 19:00</span>
        </div>
      </div>
    </div>
    <div class="foot__bottom">
      <span>© <span id="year"></span> Casa Pestalozzi · Del Valle, Ciudad de México</span>
      <span>Rediseño conceptual</span>
    </div>
  </div>
</footer>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" aria-hidden="true">
  <div class="lightbox__counter"><b id="lbCur">1</b> / <span id="lbTotal">1</span></div>
  <button class="lightbox__close" id="lbClose" aria-label="Cerrar">✕</button>
  <button class="lightbox__nav prev" id="lbPrev" aria-label="Anterior">‹</button>
  <button class="lightbox__nav next" id="lbNext" aria-label="Siguiente">›</button>
  <div>
    <div class="lightbox__img"><img id="lbImg" alt="" /></div>
    <div class="lightbox__hint">Usa ← → para navegar · Esc para cerrar</div>
    <div class="lightbox__cap"><div class="t" id="lbT"></div><div class="n" id="lbN"></div></div>
  </div>
</div>

<!-- Panel de Tweaks -->
<aside id="tweaks" aria-label="Tweaks">
  <div class="tw-head"><h3>Tweaks</h3><button class="tw-close" id="twClose">✕</button></div>
  <div class="tw-group">
    <label>Estilo del hero</label>
    <div class="tw-opts" data-tw="hero">
      <button class="tw-opt" data-val="cinema">Cinemático</button>
      <button class="tw-opt" data-val="editorial">Editorial</button>
      <button class="tw-opt" data-val="minimal">Minimal</button>
    </div>
  </div>
  <div class="tw-group">
    <label>Acento</label>
    <div class="tw-opts" data-tw="accent">
      <button class="tw-swatch" data-val="oro" style="background:#cca352" title="Oro"></button>
      <button class="tw-swatch" data-val="terracota" style="background:#b9602f" title="Terracota"></button>
      <button class="tw-swatch" data-val="salvia" style="background:#7c9a6f" title="Salvia"></button>
    </div>
  </div>
  <div class="tw-group">
    <label>Interacción</label>
    <div class="tw-toggle"><span>Cursor personalizado</span><button class="tw-switch" data-tw="cursor"></button></div>
    <div class="tw-toggle"><span>Scroll suave</span><button class="tw-switch" data-tw="smooth"></button></div>
    <div class="tw-toggle"><span>Animaciones de entrada</span><button class="tw-switch" data-tw="anim"></button></div>
  </div>
</aside>
