<?php /* Menú — La Carta (tabs y lista se renderizan via JS) */ ?>
<section class="section menu" id="menu" data-screen-label="Menú">
  <div class="wrap">
    <div class="menu__head">
      <div>
        <span class="eyebrow" data-reveal>02 — La Carta</span>
        <h2 class="menu__title" data-reveal>Nuestra <em class="accent-italic">carta</em></h2>
      </div>
      <p class="body" data-reveal style="max-width:42ch">Una carta de amor al Mediterráneo y a la cocina mexicana. Explora cada sección — los precios están en pesos mexicanos.</p>
    </div>

    <div class="menu__tabs" id="menuTabs" data-reveal></div>

    <div class="menu__layout">
      <div class="menu__list" id="menuList" data-reveal></div>
      <aside class="menu__preview" aria-hidden="true">
        <div class="menu__preview-frame" id="previewFrame" data-zoom>
          <div class="menu__preview-cap">
            <div class="pc-cat" id="pcCat">Desayunos</div>
            <div class="pc-name" id="pcName">Pasa el cursor por un platillo</div>
          </div>
        </div>
        <div class="menu__preview-note">
          <span id="pcCount">— platillos</span><span>Casa Pestalozzi</span>
        </div>
      </aside>
    </div>

    <div class="menu__foot" data-reveal>
      <a class="btn-line" href="#reserva" data-magnetic><span>Reservar una mesa</span><span class="arrow">↗</span></a>
    </div>
  </div>
</section>
