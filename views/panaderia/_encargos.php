<?php
/**
 * PANADERÍA · Encargos.
 *
 * Pedidos grandes o con fecha. Cada caso es un enlace que abre WhatsApp con
 * el pedido ya escrito (PanaderiaConfig::ENCARGOS), y dice con cuánto tiempo
 * pedirlo. Ningún formulario: la landing no tiene formularios anónimos, y la
 * panadería tampoco.
 */

use Services\Panaderia\PanaderiaConfig;
?>
<section class="section pan-encargos" id="encargos" data-tono="oro" data-screen-label="Encargos">
  <div class="wrap">
    <header class="pan-cabeza">
      <span class="eyebrow" data-reveal>03 — Su ordinazione</span>
      <h2 class="pan-titulo" data-lineas>Encárgalo <em class="accent-italic">con tiempo</em></h2>
      <p class="body" data-reveal>Elige tu caso y te abrimos WhatsApp con el pedido empezado. Al lado de cada uno, la anticipación mínima.</p>
    </header>

    <div class="pan-encargos__grupos">
      <?php foreach (PanaderiaConfig::ENCARGOS as $eGrupo => $eCasos) : ?>
        <div class="pan-encargos__grupo" data-reveal>
          <h3 class="pan-encargos__titulo"><?php echo s($eGrupo); ?></h3>
          <ul class="pan-encargos__lista">
            <?php foreach ($eCasos as [$eCaso, $eTiempo, $eFrase]) : ?>
              <li>
                <a class="pan-caso" href="<?php echo s(PanaderiaConfig::whatsappUrl($eFrase)); ?>" target="_blank" rel="noopener">
                  <span class="pan-caso__nombre"><?php echo s($eCaso); ?></span>
                  <span class="pan-caso__tiempo"><span class="sr-only">, con </span><?php echo s($eTiempo); ?><span class="sr-only"> de anticipación. Abre WhatsApp</span></span>
                  <svg class="pan-caso__flecha" viewBox="0 0 12 12" aria-hidden="true" focusable="false"><path d="M2.5 9.5 9.5 2.5M4 2.5h5.5V8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>

    <p class="pan-encargos__otro" data-reveal>
      ¿No está tu caso?
      <a href="<?php echo s(PanaderiaConfig::whatsappUrl(PanaderiaConfig::MENSAJE_GENERICO)); ?>" target="_blank" rel="noopener">Escríbenos<span class="sr-only"> por WhatsApp</span></a>
    </p>
  </div>
</section>
<?php unset($eGrupo, $eCasos, $eCaso, $eTiempo, $eFrase); ?>
