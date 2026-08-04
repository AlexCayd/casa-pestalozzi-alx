<?php /* Footer + lightbox + tweaks panel + scripts */ ?>

<footer class="foot">
  <div class="wrap">
    <div class="foot__top">
      <div class="foot__brand">
        <h2 class="bm">Casa Pestalozzi</h2>
        <p>Cocina mediterránea con corazón mexicano.</p>
      </div>
      <div class="foot__cols">
        <div class="foot__col">
          <h3>Explora</h3>
          <a href="#menu">La Carta</a>
          <a href="#maridaje">Maridaje</a>
          <a href="#panaderia">Panadería</a>
          <a href="#eventos">Eventos</a>
        </div>
        <div class="foot__col">
          <h3>Visita</h3>
          <span>Pestalozzi 1250, CDMX</span>
          <a href="tel:+525614818297">56 1481 8297</a>
          <a href="#reserva">Reservar mesa</a>
        </div>
        <div class="foot__col">
          <h3>Horario</h3>
          <?php if (!empty($horariosOperacionDisponibles)) : ?>
            <?php foreach ($horariosOperacion as $horario) : ?>
              <span>
                <?php echo s($horario['nombre'] ?? ''); ?>
                <?php echo !empty($horario['abierto'])
                  ? s(($horario['hora_apertura'] ?? '') . '–' . ($horario['hora_cierre'] ?? ''))
                  : 'Cerrado'; ?>
              </span>
            <?php endforeach; ?>
          <?php else : ?>
            <span>Consulta la disponibilidad al reservar.</span>
          <?php endif; ?>
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
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-hidden="true" inert aria-labelledby="lightbox-title">
  <h2 id="lightbox-title" class="visually-hidden">Vista ampliada</h2>
  <div class="lightbox__counter"><b id="lbCur">1</b> / <span id="lbTotal">1</span></div>
  <button type="button" class="lightbox__close" id="lbClose" aria-label="Cerrar vista ampliada">✕</button>
  <button type="button" class="lightbox__nav prev" id="lbPrev" aria-label="Imagen anterior">‹</button>
  <button type="button" class="lightbox__nav next" id="lbNext" aria-label="Imagen siguiente">›</button>
  <div>
    <div class="lightbox__img"><img id="lbImg" alt="" /></div>
    <div class="lightbox__hint">Usa ← → para navegar · Esc para cerrar</div>
    <div class="lightbox__cap"><div class="t" id="lbT"></div><div class="n" id="lbN"></div></div>
  </div>
</div>

<!-- Panel de Tweaks -->
<aside id="tweaks" aria-label="Tweaks">
  <div class="tw-head"><h3>Tweaks</h3><button type="button" class="tw-close" id="twClose" aria-label="Cerrar panel de ajustes">✕</button></div>
  <div class="tw-group">
    <label>Estilo del hero</label>
    <div class="tw-opts" data-tw="hero">
      <button type="button" class="tw-opt" data-val="cinema">Cinemático</button>
      <button type="button" class="tw-opt" data-val="editorial">Editorial</button>
      <button type="button" class="tw-opt" data-val="minimal">Minimal</button>
    </div>
  </div>
  <div class="tw-group">
    <label>Acento</label>
    <div class="tw-opts" data-tw="accent">
      <button type="button" class="tw-swatch" data-val="oro" style="background:#cca352" title="Oro" aria-label="Acento oro"></button>
      <button type="button" class="tw-swatch" data-val="terracota" style="background:#b9602f" title="Terracota" aria-label="Acento terracota"></button>
      <button type="button" class="tw-swatch" data-val="salvia" style="background:#7c9a6f" title="Salvia" aria-label="Acento salvia"></button>
    </div>
  </div>
  <div class="tw-group">
    <label>Interacción</label>
    <div class="tw-toggle"><span>Cursor personalizado</span><button type="button" class="tw-switch" data-tw="cursor" aria-label="Activar o desactivar cursor personalizado"></button></div>
    <div class="tw-toggle"><span>Scroll suave</span><button type="button" class="tw-switch" data-tw="smooth" aria-label="Activar o desactivar scroll suave"></button></div>
    <div class="tw-toggle"><span>Animaciones de entrada</span><button type="button" class="tw-switch" data-tw="anim" aria-label="Activar o desactivar animaciones de entrada"></button></div>
  </div>
</aside>
