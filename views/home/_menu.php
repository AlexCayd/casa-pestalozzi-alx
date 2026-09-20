<?php /* Menú — La Carta (tabs y lista se renderizan via JS) */ ?>
<section class="section menu" id="menu" data-tono="verde" data-screen-label="Menú">
  <div class="wrap">
    <div class="menu__head">
      <div>
        <span class="eyebrow" data-reveal>02 — Il menù</span>
        <h2 class="menu__title" data-lineas>Il nostro <em class="accent-italic">menù</em></h2>
      </div>
      <p class="body" data-reveal style="max-width:42ch">Antipasti, pasta fresca, pizza de horno de leña y postres de la casa. Explora cada sección — los precios están en pesos mexicanos.</p>
    </div>

    <div class="menu__tabs" id="menuTabs" data-reveal></div>

    <div class="menu__layout">
      <div class="menu__list" id="menuList" data-reveal></div>
      <?php /*
        El marco es cartón: relleno, filete y escuadras. El HUECO es un elemento
        aparte, y ahí dentro viven la fotografía, el velo y la cartela.

        No es un div de adorno. Antes la imagen la insertaba menu.js dentro del
        propio marco y se colocaba `absolute` con `inset: var(--menu-marco)`;
        el velo, la cartela y la chapa de "Ampliar" repetían esa misma medida
        cada uno por su cuenta. Cuatro capas cuadrándose a mano contra el mismo
        rectángulo es justo donde se descuadran. Con un hueco real, todas se
        posicionan contra ÉL y la alineación deja de ser un acuerdo entre
        cuatro reglas.

        `data-zoom` va en el hueco y no en el marco para que la chapa de ampliar
        caiga sobre la fotografía, no sobre el cartón.
      */ ?>
      <aside class="menu__preview" aria-hidden="true">
        <div class="menu__preview-frame" id="previewFrame">
          <div class="menu__preview-hueco" id="previewHueco" data-zoom>
            <div class="menu__preview-cap">
              <div class="pc-cat" id="pcCat">Desayunos</div>
              <div class="pc-name" id="pcName">Pasa el cursor por un platillo</div>
            </div>
          </div>
        </div>
        <div class="menu__preview-note">
          <span id="pcCount">— platillos</span><span>Casa Pestalozzi</span>
        </div>
      </aside>
    </div>

    <div class="menu__foot" data-reveal>
      <a class="btn-line" href="#reserva" data-magnetic><span>Reservar una mesa</span><span class="arrow">↗</span></a>
      <?php /*
        Las dos cartas de la casa, apiladas: la de comida —lo que pinta la
        rejilla de arriba— y la de maridaje, que no tiene sección propia en la
        landing y vive entera en su PDF.

        Van agrupadas y no sueltas en la fila porque son la misma acción
        —llevarse la carta— sobre dos piezas distintas; sueltas, el pie ofrecía
        tres destinos del mismo peso y el CTA de reserva dejaba de ser el
        único principal de la sección.

        El primero deja de llamarse "Ver en PDF" a secas: con dos piezas,
        esa etiqueta ya no dice cuál de las dos se abre.
      */ ?>
      <div class="menu__docs">
        <a class="btn-line btn-line--pdf" href="/menu/pdf" target="_blank" rel="noopener" data-magnetic><span>Menú en PDF</span><span class="arrow">↓</span></a>
        <a class="btn-line btn-line--pdf" href="/maridaje/pdf" target="_blank" rel="noopener" data-magnetic><span>Maridaje en PDF</span><span class="arrow">↓</span></a>
      </div>
    </div>
  </div>
</section>
