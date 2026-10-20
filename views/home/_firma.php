<?php
/**
 * Especiales de temporada — la vitrina que avanza con el scroll.
 *
 * Los platillos salen de la base: los activos de las categorías con
 * carta = 'temporada' (Services\Menu\Carta::especiales(), que inyecta
 * HomeController como $especialesTemporada). La cocina los da de alta en
 * Menú como cualquier platillo y cambian cada semana; antes eran un arreglo
 * fijo en src/js/modules/gallery.js.
 *
 * Se imprimen en el servidor, como la agenda de catas, y con el MISMO marcado
 * que generaba aquel arreglo: la tarjeta de foto en su marco, ampliable, con
 * la cartela de dos líneas. La foto ya viene resuelta —la propia del platillo
 * o, si no la tiene, la de su categoría—, así que aquí no hay variantes.
 * gallery.js ya sólo engancha el clic de ampliar y el avance con el scroll.
 */

$especialesTemporada = is_array($especialesTemporada ?? null) ? $especialesTemporada : [];
$h = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
?>
<section class="section signature" id="firma" data-tono="beige" data-screen-label="Lo mejor de la casa">
  <div class="signature__head wrap" style="max-width:none">
    <div>
      <span class="eyebrow" data-reveal>03 — Le specialità</span>
      <h2 class="signature__title" data-lineas>Especiales de <em class="accent-italic">temporada</em></h2>
      <?php if ($especialesTemporada === []) : ?>
        <?php /* Semana sin especiales: la sección se queda —su número y su
                 enlace del índice no cambian— y lo dice en una línea, con el
                 párrafo destacado de la casa, en vez de dejar la banda vacía. */ ?>
        <p class="lead" data-reveal>La cocina está preparando los especiales de esta semana. Pregunta por ellos en tu próxima visita.</p>
      <?php endif; ?>
    </div>
    <?php if ($especialesTemporada !== []) : ?>
      <span class="signature__hint" data-reveal>Clic para ampliar <span style="font-family:var(--serif)">→</span></span>
    <?php endif; ?>
  </div>
  <?php /* La tira avanza sola con el scroll: la mueve GSAP sobre `x` desde
           vetrinaConScroll() en gallery.js. Sin scroll propio, así que tampoco
           necesita [data-lenis-prevent] — sólo lo recupera el respaldo sin
           GSAP, y esa marca la pone el JS. */ ?>
  <div class="gallery-track" id="galleryTrack">
    <?php foreach ($especialesTemporada as $especial) : ?>
      <?php
      $nombre = (string) ($especial['n'] ?? '');
      $categoria = (string) ($especial['cat'] ?? '');
      ?>
      <?php /* La foto y todo lo que va encima viven dentro del hueco; la
               tarjeta en sí es el cartón del marco (ver .gcard en
               _galeria.scss). En la línea chica va la categoría del platillo,
               como el tipo de platillo que ponía el arreglo fijo — y también
               en el lightbox. */ ?>
      <div class="gcard" data-cursor="Ampliar" data-zoom
           data-zoom-name="<?php echo $h($nombre); ?>"
           data-zoom-cat="<?php echo $h($categoria); ?>">
        <div class="gcard__hueco"><img src="<?php echo $h($especial['img']); ?>" alt="<?php echo $h($nombre); ?>" loading="lazy" draggable="false" /><div class="gcard__cap"><div class="t"><?php echo $h($categoria); ?></div><div class="n"><?php echo $h($nombre); ?></div></div></div>
      </div>
    <?php endforeach; ?>
  </div>
</section>
<?php unset($especialesTemporada, $especial, $nombre, $categoria, $h); ?>
