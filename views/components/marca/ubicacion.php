<?php
/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  MARCA — compartido por la landing del restaurante y la de la │
 * │  panadería. No lee la configuración de ninguna de las dos.    │
 * └───────────────────────────────────────────────────────────────┘
 *
 * «Cómo llegar»: los datos a un lado y, al otro, la lámina de papel con el
 * plano. Pinta el interior de la sección; el <section> con su id y su tono lo
 * pone cada página.
 *
 * La lámina va marcada data-tono="crema" aunque la sección esté en verde —es
 * una hoja de papel sobre la mesa, y con el tono declarado sus manzanas,
 * calles y rótulos toman solos la tinta que les toca sin una regla de color
 * por pieza—. Lo que cambia entre las dos casas es el PLANO (las calles): cada
 * una lo dibuja y lo pasa ya hecho.
 *
 * Parámetros:
 * - $ubiEyebrow, $ubiTitulo, $ubiTituloAcento: el rótulo y el titular (el
 *   acento va con el subrayado de oro de la casa).
 * - $ubiLineas: [['etiqueta' => …, 'texto' => …, 'href' => ?string, 'externo' => bool], …].
 * - $ubiRedes: lista para views/components/marca/redes.php ([] la omite).
 * - $ubiPlano: HTML ya armado con las calles (de confianza: lo escribe la vista).
 * - $ubiPin: ['nombre' => …, 'detalle' => …, 'x' => '60%', 'y' => '56%',
 *   'ancla' => 'punto'|null]. Con 'punto' el punto del pin cae exactamente en
 *   (x, y); sin él, la columna entera del pin se centra ahí.
 * - $ubiMetro: nombre de la estación para la chapa, o null.
 * - $ubiMapaUrl, $ubiCta: el enlace a Google Maps y su texto.
 */

$ubiEyebrow = (string)($ubiEyebrow ?? '');
$ubiTitulo = (string)($ubiTitulo ?? '');
$ubiTituloAcento = (string)($ubiTituloAcento ?? '');
$ubiLineas = is_array($ubiLineas ?? null) ? $ubiLineas : [];
$ubiRedes = is_array($ubiRedes ?? null) ? $ubiRedes : [];
$ubiPlano = (string)($ubiPlano ?? '');
$ubiPin = is_array($ubiPin ?? null) ? $ubiPin : [];
$ubiMetro = isset($ubiMetro) && $ubiMetro !== '' ? (string)$ubiMetro : null;
$ubiMapaUrl = (string)($ubiMapaUrl ?? '');
$ubiCta = (string)($ubiCta ?? 'Cómo llegar');

$ubiPinEstilo = '';
if (isset($ubiPin['x'], $ubiPin['y'])) {
  $ubiPinEstilo = ' style="--pin-x:' . s($ubiPin['x']) . ';--pin-y:' . s($ubiPin['y']) . '"';
}
$ubiPinClase = 'loc__pin' . (($ubiPin['ancla'] ?? null) === 'punto' ? ' loc__pin--punto' : '');
?>
  <div class="wrap loc__grid">
    <div class="loc__info">
      <span class="eyebrow" data-reveal><?php echo s($ubiEyebrow); ?></span>
      <h2 class="loc__title" data-lineas><?php echo s($ubiTitulo); ?><?php if ($ubiTituloAcento !== '') : ?> <em class="accent-italic"><?php echo s($ubiTituloAcento); ?></em><?php endif; ?></h2>
      <?php foreach ($ubiLineas as $ubiLinea) : ?>
      <div class="loc__line" data-reveal>
        <span class="ic">◆</span>
        <?php $ubiHref = (string)($ubiLinea['href'] ?? ''); ?>
        <div class="ct"><b><?php echo s($ubiLinea['etiqueta'] ?? ''); ?></b><?php if ($ubiHref !== '') : ?><a href="<?php echo s($ubiHref); ?>"<?php echo !empty($ubiLinea['externo']) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo s($ubiLinea['texto'] ?? ''); ?></a><?php else : ?><span><?php echo s($ubiLinea['texto'] ?? ''); ?></span><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
      <?php if ($ubiRedes !== []) : ?>
        <?php $redesLista = $ubiRedes; include __DIR__ . '/redes.php'; ?>
      <?php endif; ?>
    </div>
    <div class="loc__map" data-tono="crema" data-reveal>
      <div class="map2__grid"></div>
      <?php echo $ubiPlano . "\n"; ?>
      <?php if ($ubiMetro !== null) : ?>
      <?php /* Dibujado y no el glifo Ⓜ: lo pintaba la fuente del sistema, no
               heredaba el color de la chapa y cambiaba de forma entre
               plataformas. */ ?>
      <div class="map2__metro"><svg class="m" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="7.25" fill="none" stroke="currentColor" stroke-width="1.3"/><path d="M4.6 11.2V5l3.4 3.9L11.4 5v6.2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg> <?php echo s($ubiMetro); ?></div>
      <?php endif; ?>
      <div class="<?php echo $ubiPinClase; ?>"<?php echo $ubiPinEstilo; ?>>
        <span class="ring"></span>
        <span class="dot"></span>
        <span class="tag"><?php echo s($ubiPin['nombre'] ?? ''); ?><small><?php echo s($ubiPin['detalle'] ?? ''); ?></small></span>
      </div>
      <div class="map-cta">
        <a class="btn-line btn-line--solid" href="<?php echo s($ubiMapaUrl); ?>" target="_blank" rel="noopener" data-magnetic><span><?php echo s($ubiCta); ?></span><span class="arrow">↗</span></a>
      </div>
    </div>
  </div>
<?php
unset(
  $ubiEyebrow, $ubiTitulo, $ubiTituloAcento, $ubiLineas, $ubiLinea, $ubiHref, $ubiRedes,
  $ubiPlano, $ubiPin, $ubiMetro, $ubiMapaUrl, $ubiCta, $ubiPinEstilo, $ubiPinClase
);
?>
