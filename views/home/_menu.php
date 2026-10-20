<?php /* Menú — La carta (pestañas y lista las pinta menu.js desde /menu) */ ?>
<section class="section menu" id="menu" data-tono="verde" data-screen-label="Menú">
  <div class="wrap">
    <div class="menu__head">
      <div>
        <span class="eyebrow" data-reveal>02 — Il menù</span>
        <h2 class="menu__title" data-lineas>Il nostro <em class="accent-italic">menù</em></h2>
      </div>
      <p class="body menu__intro" data-reveal>Antipasti, pasta fresca, pizza de horno de leña y postres de la casa. Precios en pesos mexicanos.</p>
    </div>

    <?php /* Pestañas de verdad (role="tab" con flechas, en menu.js): antes eran
             botones sueltos y el lector de pantalla no sabía que elegían qué
             lista se ve debajo.

             La fila desborda en horizontal casi siempre (nueve secciones), y
             antes nada lo decía: la última pestaña salía cortada contra el
             borde. Ahora el lado donde queda carta se desvanece y lleva una
             flecha. Las flechas van FUERA del tablist —dentro, un botón que no
             es pestaña rompería el patrón ARIA— y fuera del recorrido de
             teclado y del lector: con teclado las flechas del propio grupo ya
             llevan la pestaña elegida a la vista, y son sólo un atajo de
             puntero para desplazar. */ ?>
    <div class="menu__pestanas" id="menuPestanas" data-reveal>
      <button type="button" class="menu__desliza menu__desliza--ant" data-desliza="-1" tabindex="-1" aria-hidden="true">
        <svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12.5 4 6.5 10l6 6"/></svg>
      </button>
      <div class="menu__tabs" id="menuTabs" role="tablist" aria-label="Secciones de la carta"></div>
      <button type="button" class="menu__desliza menu__desliza--sig" data-desliza="1" tabindex="-1" aria-hidden="true">
        <svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7.5 4l6 6-6 6"/></svg>
      </button>
    </div>

    <div class="menu__layout">
      <?php /* Sin aria-live: con pestañas el cambio de panel ya lo anuncia la
               pestaña elegida, y una región viva leía la lista ENTERA —26
               platillos con su descripción— cada vez que se cambiaba de sección.
               tabindex="0" porque el panel no tiene nada enfocable dentro
               cuando ningún platillo trae foto propia: es la parada que el
               patrón de pestañas pide para llegar a su contenido. */ ?>
      <div class="menu__list" id="menuList" role="tabpanel" tabindex="0" data-reveal></div>
      <?php /*
        El marco es cartón: relleno, filete y escuadras. El HUECO es un elemento
        aparte, y ahí dentro viven la fotografía, el velo y la cartela: todas las
        capas se posicionan contra él con `inset: 0` y no hay medida que
        repetir. `data-zoom` va en el hueco para que la chapa de ampliar caiga
        sobre la fotografía, no sobre el cartón.

        La foto es la del platillo si tiene, y si no la de su categoría (las dos
        se suben desde el panel, en Menú).
      */ ?>
      <aside class="menu__preview" aria-hidden="true">
        <div class="menu__preview-frame" id="previewFrame">
          <div class="menu__preview-hueco" id="previewHueco" data-zoom>
            <div class="menu__preview-cap">
              <div class="pc-cat" id="pcCat"></div>
              <div class="pc-name" id="pcName">Pasa el cursor por un platillo</div>
              <div class="pc-precio" id="pcPrecio"></div>
            </div>
          </div>
        </div>
        <div class="menu__preview-note">
          <span id="pcCount"></span><span>Casa Pestalozzi</span>
        </div>
      </aside>
    </div>

    <?php /*
      La carta impresa es la acción principal de esta sección y va en relleno
      de oro con su icono de cubiertos. La de maridaje no se enlaza desde aquí:
      vive con las copas, en Catas, con la copa y su propio color.
    */ ?>
    <div class="menu__foot" data-reveal>
      <a class="btn-line btn-line--solid btn-line--carta" href="/menu/pdf" target="_blank" rel="noopener" data-magnetic>
        <svg class="btn-line__icono" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7 3v8a2 2 0 0 0 2 2v8M5 3v5M9 3v5M17 21V3c-2 1-3 4-3 8 0 2 1 3 3 3"/></svg>
        <span>Carta completa en PDF</span>
      </a>
      <a class="btn-line menu__reservar" href="#reserva" data-magnetic><span>Reservar mesa</span><span class="arrow" aria-hidden="true">→</span></a>
    </div>
  </div>
</section>
