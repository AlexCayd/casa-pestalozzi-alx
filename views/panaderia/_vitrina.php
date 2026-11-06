<?php
/**
 * PANADERÍA · La vitrina.
 *
 * Lo que hay y cuánto cuesta, con el vocabulario de la carta del restaurante:
 * pestañas, puntos guía hasta el precio y una foto por familia. Sale de Menú
 * (categorías con carta «Panadería»), impresa en el servidor: sin JS se leen
 * todas las familias seguidas, con su título; initPestanasImpresas()
 * (modules/pestanas.js) las convierte en pestañas.
 *
 * De fondo, el papel de envoltura de la casa (.pan-papel): óvalos del medallón
 * y rombos, como el papel en que se entrega el pan.
 */

use Services\Panaderia\PanaderiaConfig;

// Sin centavos cuando no los hay, como la carta impresa.
$vitPrecio = static function ($p): string {
  $n = (float) $p;
  return '$' . number_format($n, floor($n) == $n ? 0 : 2);
};
$vitFoto = '/build/images/panaderia-vitrina.webp';
$vitApartar = PanaderiaConfig::whatsappUrl(PanaderiaConfig::MENSAJE_VITRINA);
?>
<section class="section pan-vitrina" id="vitrina" data-tono="beige" data-screen-label="La vitrina">
  <div class="pan-papel" aria-hidden="true"></div>
  <div class="wrap">
    <header class="pan-cabeza">
      <span class="eyebrow" data-reveal>01 — La vetrina</span>
      <h2 class="pan-titulo" data-lineas>Hoy en la <em class="accent-italic">vitrina</em></h2>
      <p class="body" data-reveal>Precios por pieza. Lo más buscado se acaba temprano: si vienes por algo en especial, apártalo.</p>
    </header>

    <?php if ($vitrina === []) : ?>
      <div class="pan-vitrina__vacia" data-reveal>
        <p class="lead">La vitrina cambia cada día. Escríbenos y te decimos qué hay hoy.</p>
        <a class="btn-line btn-line--solid" href="<?php echo s($vitApartar); ?>" target="_blank" rel="noopener" data-magnetic><span>Preguntar por WhatsApp</span><span class="arrow" aria-hidden="true">↗</span></a>
      </div>
    <?php else : ?>
      <div class="pan-vitrina__carta" data-vitrina data-reveal>
        <div class="pan-vitrina__pestanas" role="tablist" aria-label="Familias de la vitrina" hidden>
          <?php foreach ($vitrina as $vi => $vcat) : ?>
            <button type="button" class="pan-vitrina__pestana" role="tab"
                    id="vit-tab-<?php echo (int) $vi; ?>" aria-controls="vit-panel-<?php echo (int) $vi; ?>"
                    aria-selected="<?php echo $vi === 0 ? 'true' : 'false'; ?>"
                    tabindex="<?php echo $vi === 0 ? '0' : '-1'; ?>"><?php echo s($vcat['label']); ?></button>
          <?php endforeach; ?>
        </div>

        <?php /* Marco de los paneles: su altura se interpola al cambiar de
                 familia (src/js/panaderia/vitrina.js). No es el [data-reveal]
                 de arriba, al que GSAP le escribe opacidad y transform. */ ?>
        <div class="pan-vitrina__paneles">
        <?php foreach ($vitrina as $vi => $vcat) : ?>
          <div class="pan-vitrina__panel" id="vit-panel-<?php echo (int) $vi; ?>" role="tabpanel" aria-labelledby="vit-tab-<?php echo (int) $vi; ?>">
            <div class="pan-vitrina__piezas">
              <h3 class="pan-vitrina__familia"><?php echo s($vcat['label']); ?></h3>
              <ul class="pan-vitrina__lista">
                <?php foreach ($vcat['dishes'] as $vpieza) : ?>
                  <li class="pan-pieza">
                    <span class="pan-pieza__fila">
                      <span class="pan-pieza__nombre"><?php echo s($vpieza['n']); ?></span>
                      <span class="pan-pieza__puntos" aria-hidden="true"></span>
                      <span class="pan-pieza__precio"><?php echo s($vitPrecio($vpieza['p'])); ?></span>
                    </span>
                    <?php if (trim((string) $vpieza['d']) !== '') : ?>
                      <span class="pan-pieza__desc"><?php echo s($vpieza['d']); ?></span>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
            <?php $vImg = $vcat['img'] ?: $vitFoto; ?>
            <figure class="pan-vitrina__foto" data-zoom data-zoom-cat="La vitrina" data-zoom-name="<?php echo s($vcat['label']); ?>">
              <img src="<?php echo s($vImg); ?>"<?php echo srcsetImagen($vImg, '(max-width: 880px) 90vw, 34vw'); ?> alt="<?php echo s($vcat['label']); ?> de la panadería" loading="lazy" decoding="async" />
            </figure>
          </div>
        <?php endforeach; ?>
        </div>
      </div>

      <p class="pan-vitrina__apartar" data-reveal>
        ¿Vienes por algo en especial?
        <a href="<?php echo s($vitApartar); ?>" target="_blank" rel="noopener">Apártalo por WhatsApp<span class="sr-only"> (abre WhatsApp)</span></a>
      </p>
    <?php endif; ?>
  </div>
</section>
<?php unset($vitPrecio, $vitFoto, $vitApartar, $vi, $vcat, $vpieza, $vImg); ?>
