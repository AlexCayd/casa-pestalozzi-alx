<?php
/**
 * PANADERÍA · De temporada.
 *
 * A la izquierda, la pieza de ahora (o la siguiente, si hoy no toca ninguna)
 * con su fecha límite y su botón para apartarla. A la derecha, el año entero:
 * una fila por pieza con su ventana en una regla de doce meses y la marca de
 * hoy. Todo sale de Services\Panaderia\Temporadas (panes y fechas de ejemplo).
 *
 * La regla es un dibujo y va aria-hidden; lo que se lee —nombre y fechas en
 * palabras— está en el texto de cada fila.
 */

use Services\Panaderia\PanaderiaConfig;

$tAhora = $temporada['ahora'];
$tPieza = $tAhora ?? $temporada['siguiente'];
$tMeses = ['E', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
?>
<section class="section pan-temporada" id="temporada" data-tono="verde" data-screen-label="De temporada">
  <div class="wrap">
    <header class="pan-cabeza">
      <span class="eyebrow" data-reveal>02 — Di stagione</span>
      <h2 class="pan-titulo" data-lineas>Cada temporada, <em class="accent-italic">su pan</em></h2>
      <p class="body" data-reveal>Tradiciones de México y de Italia, cada una en sus semanas. Lo de temporada se aparta por WhatsApp.</p>
    </header>

    <div class="pan-temporada__grid">
      <?php if ($tPieza !== null) : ?>
        <article class="pan-ahora" data-reveal>
          <p class="pan-ahora__rotulo">
            <?php echo $tAhora !== null
              ? 'Ahora · hasta el ' . s($tPieza['hasta_texto'])
              : 'Lo siguiente · desde el ' . s($tPieza['desde_texto']); ?>
          </p>
          <h3 class="pan-ahora__nombre"><?php echo s($tPieza['nombre']); ?></h3>
          <p class="pan-ahora__desc"><?php echo s($tPieza['descripcion']); ?></p>
          <p class="pan-ahora__nota"><?php echo s($tPieza['anticipacion']); ?></p>
          <a class="btn-line btn-line--solid" href="<?php echo s(PanaderiaConfig::whatsappUrl($tPieza['frase'])); ?>" target="_blank" rel="noopener" data-magnetic><span>Apartar por WhatsApp</span><span class="arrow" aria-hidden="true">↗</span></a>
        </article>
      <?php endif; ?>

      <div class="pan-calendario" data-reveal>
        <h3 class="sr-only">Calendario del pan de temporada</h3>
        <div class="pan-calendario__meses" aria-hidden="true">
          <span></span>
          <?php foreach ($tMeses as $tMes) : ?><span><?php echo $tMes; ?></span><?php endforeach; ?>
        </div>
        <ol class="pan-calendario__lista">
          <?php foreach ($temporada['calendario'] as $tFila) : ?>
            <li class="pan-calendario__fila<?php echo $tFila['ahora'] ? ' is-ahora' : ''; ?>">
              <span class="pan-calendario__pan">
                <span class="pan-calendario__nombre"><?php echo s($tFila['nombre']); ?><?php if ($tFila['ahora']) : ?><span class="sr-only"> (ahora)</span><?php endif; ?></span>
                <span class="pan-calendario__fechas"><?php echo s($tFila['fechas']); ?></span>
              </span>
              <span class="pan-calendario__pista" aria-hidden="true">
                <?php foreach ($tFila['tramos'] as $tTramo) : ?>
                  <i style="left:<?php echo (float) $tTramo['inicio']; ?>%;width:<?php echo (float) $tTramo['ancho']; ?>%"></i>
                <?php endforeach; ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ol>
        <span class="pan-calendario__hoy" style="--hoy:<?php echo (float) $temporada['hoy']; ?>" aria-hidden="true"><span>Hoy</span></span>
      </div>
    </div>
  </div>
</section>
<?php unset($tAhora, $tPieza, $tMeses, $tMes, $tFila, $tTramo); ?>
