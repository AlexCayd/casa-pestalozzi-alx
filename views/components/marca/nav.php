<?php
/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  MARCA — compartido por la landing del restaurante y la de la │
 * │  panadería. No lee la configuración de ninguna de las dos.    │
 * └───────────────────────────────────────────────────────────────┘
 *
 * Marca fija + botón de menú + menú a pantalla completa. El índice lateral
 * (.rail) no vive aquí: es sólo de la portada del restaurante.
 *
 * Cada página le pasa sus destinos y su ficha. Lo que cambia entre las dos es
 * el contenido, no el comportamiento: nav.js abre, cierra, mueve el foco y
 * desplaza a las anclas igual en las dos.
 *
 * Parámetros:
 * - $navDestinos: [[href, nombre, pista], …]. Un href que empieza por «#» es un
 *   ancla de la misma página y lleva data-nav (nav.js cierra el menú y
 *   desplaza); cualquier otro es una página distinta y navega normal.
 * - $navMarcaHref: destino del wordmark fijo ('#hero', '#portada'…).
 * - $navMarcaSubtitulo: renglón bajo el wordmark.
 * - $navFichaCta: ['href' => …, 'texto' => …, 'externo' => bool]. La acción
 *   principal de la ficha.
 * - $navFichaDatos: [['texto' => …, 'href' => ?string, 'externo' => bool], …].
 *   Lo imprescindible para venir, sin rótulos delante.
 */

$navDestinos = is_array($navDestinos ?? null) ? $navDestinos : [];
$navMarcaHref = (string)($navMarcaHref ?? '#hero');
$navMarcaSubtitulo = (string)($navMarcaSubtitulo ?? '');
$navFichaCta = is_array($navFichaCta ?? null) ? $navFichaCta : null;
$navFichaDatos = is_array($navFichaDatos ?? null) ? $navFichaDatos : [];

$navEsAncla = static function (string $href): bool {
  return $href !== '' && $href[0] === '#';
};
?>

<?php /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
         Nivel <p> porque el <h1> de cada página es el título de su portada.
         `data-magnetic` en el contenedor: el imán mueve la caja entera. */ ?>
<?php
$hcpEtiqueta = 'div';
$hcpNivel = 'p';
$hcpHref = $navMarcaHref;
$hcpSubtitulo = $navMarcaSubtitulo;
$hcpClase = 'brand-mark';
$hcpAtributos = ['data-magnetic' => ''];
include __DIR__ . '/../../templates/header-casa-pestalozzi.php';
?>

<?php /* aria-expanded y aria-controls los mantiene nav.js: sin ellos el lector
         de pantalla anunciaba «Abrir menú» también con el menú abierto.
         Las barras van en su propia caja para el imán (magnetic.js): la caja
         se desplaza como etiqueta del botón, y las barras conservan su
         transform para dibujar la X del menú abierto. */ ?>
<button class="nav-toggle" id="navToggle" type="button" aria-label="Abrir menú"
        aria-expanded="false" aria-controls="navOverlay" data-magnetic>
  <span class="nav-toggle__barras" data-magnetic-label><span class="bar"></span><span class="bar"></span><span class="bar"></span></span>
</button>

<?php /* Papel crema y no verde: las portadas son claras, y abrir el menú no
         debería apagar la luz. `data-lenis-prevent`: en un teléfono la lista y
         la ficha no caben juntas y el menú se desplaza por su cuenta. */ ?>
<nav class="nav-overlay" id="navOverlay" data-tono="crema" aria-label="Navegación principal" data-lenis-prevent>
  <div class="nav-overlay__principal">
    <ol class="nav-overlay__links">
      <?php foreach ($navDestinos as $navI => [$navHref, $navNombre, $navPista]) : ?>
        <li style="--i:<?php echo $navI; ?>">
          <a href="<?php echo s($navHref); ?>"<?php echo $navEsAncla((string)$navHref) ? ' data-nav' : ''; ?>>
            <span class="nav-overlay__texto">
              <span class="nav-overlay__nombre"><?php echo s($navNombre); ?></span>
              <span class="nav-overlay__pista"><?php echo s($navPista); ?></span>
            </span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>

  <div class="nav-overlay__aside" data-tono="beige">
    <?php /* Donde estaba la fotografía del comedor, la casa dibujada en la misma
             línea que la mesa del hero: el arco de la entrada, la lámpara, el
             olivo y una mesa para dos. Se dibuja al abrir el menú (no con el
             observador de los adornos, que lo daría por visible aunque el menú
             esté cerrado: el panel es fijo y cubre la pantalla). */ ?>
    <svg class="orn orn--nav" data-orn-listo viewBox="0 0 420 470" fill="none" stroke="currentColor"
         stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
      <path class="t r" pathLength="1" d="M78 452 V214 A132 132 0 0 1 342 214 V452"/>
      <path class="t" pathLength="1" d="M104 452 V222 A106 106 0 0 1 316 222 V452"/>
      <path class="t" pathLength="1" d="M210 116 V452 M104 300 H316"/>
      <path class="t t--ruta" pathLength="1" d="M126 300 V452 M294 300 V452"/>
      <path class="t" pathLength="1" d="M210 82 V136"/>
      <g class="v" data-vida="halo" style="transform-origin:210px 162px"><circle class="r r--halo" cx="210" cy="164" r="34"/></g>
      <path class="t r r--fuerte" pathLength="1" d="M186 160 L234 160 L224 136 L196 136 Z"/>
      <path class="t" pathLength="1" d="M202 160 C202 168 218 168 218 160"/>
      <g class="v" data-vida="mecer" style="transform-origin:62px 452px">
        <path class="t" pathLength="1" d="M62 408 C60 380 68 360 60 330 M62 372 C72 360 82 356 92 352"/>
        <g transform="translate(60 330) rotate(-100)"><path class="t r" pathLength="1" d="M0 0 C6 -7 18 -8 26 0 C18 8 6 7 0 0 Z"/></g>
        <g transform="translate(60 340) rotate(-150)"><path class="t r" pathLength="1" d="M0 0 C6 -7 18 -8 26 0 C18 8 6 7 0 0 Z"/></g>
        <g transform="translate(61 336) rotate(-40)"><path class="t r" pathLength="1" d="M0 0 C6 -7 18 -8 26 0 C18 8 6 7 0 0 Z"/></g>
        <g transform="translate(64 362) rotate(-170)"><path class="t r" pathLength="1" d="M0 0 C5 -6 16 -7 23 0 C16 7 5 6 0 0 Z"/></g>
        <g transform="translate(92 352) rotate(-30)"><path class="t r" pathLength="1" d="M0 0 C5 -6 16 -7 23 0 C16 7 5 6 0 0 Z"/></g>
        <g transform="translate(80 357) rotate(40)"><path class="t r" pathLength="1" d="M0 0 C5 -6 16 -7 22 0 C16 7 5 6 0 0 Z"/></g>
        <ellipse class="t r r--fuerte" pathLength="1" cx="72" cy="348" rx="3.6" ry="5"/>
      </g>
      <path class="t r r--fuerte" pathLength="1" d="M34 452 L42 408 H84 L92 452 Z"/>
      <path class="t" pathLength="1" d="M300 404 V452 M276 452 H324"/>
      <path class="t r" pathLength="1" d="M236 398 C236 391 364 391 364 398 C364 405 236 405 236 398 Z"/>
      <path class="t r r--fuerte" pathLength="1" d="M270 396 V356 C270 346 276 342 276 332 V318 H284 V332 C284 342 290 346 290 356 V396 Z"/>
      <path class="t" pathLength="1" d="M318 372 C317 386 322 392 328 392 C334 392 339 386 338 372 Z M328 392 V396"/>
      <path class="t" pathLength="1" d="M344 376 C343 388 347 393 352 393 C357 393 361 388 360 376 Z M352 393 V396"/>
      <g transform="translate(362 120)"><g class="v" data-vida="brillo"><path class="r r--fuerte" d="M0 -12 C1.5 -3 3 -1.5 12 0 C3 1.5 1.5 3 0 12 C-1.5 3 -3 1.5 -12 0 C-3 -1.5 -1.5 -3 0 -12 Z"/></g></g>
      <g transform="translate(66 156)"><g class="v" data-vida="brillo" style="animation-delay:-1.4s"><path class="r r--fuerte" d="M0 -8 C1 -2 2 -1 8 0 C2 1 1 2 0 8 C-1 2 -2 1 -8 0 C-2 -1 -1 -2 0 -8 Z"/></g></g>
      <path class="t" pathLength="1" d="M14 452 H406"/>
    </svg>

    <?php /* Lo imprescindible para venir, y nada más: la acción principal, el
             horario de hoy, la dirección y el teléfono. Sin rótulos delante:
             cada dato se explica solo. */ ?>
    <div class="nav-overlay__ficha">
      <?php if ($navFichaCta !== null) : ?>
        <?php $navCtaHref = (string)($navFichaCta['href'] ?? ''); ?>
        <a class="btn-line btn-line--solid nav-overlay__reservar" href="<?php echo s($navCtaHref); ?>"<?php
          echo $navEsAncla($navCtaHref) ? ' data-nav' : '';
          echo !empty($navFichaCta['externo']) ? ' target="_blank" rel="noopener"' : '';
        ?>>
          <span><?php echo s($navFichaCta['texto'] ?? ''); ?></span><span class="arrow" aria-hidden="true"><?php echo !empty($navFichaCta['externo']) ? '↗' : '→'; ?></span>
        </a>
      <?php endif; ?>
      <ul class="nav-overlay__datos">
        <?php foreach ($navFichaDatos as $navDato) : ?>
          <?php $navDatoHref = (string)($navDato['href'] ?? ''); ?>
          <?php if ($navDatoHref === '') : ?>
            <li><?php echo s($navDato['texto'] ?? ''); ?></li>
          <?php else : ?>
            <li><a href="<?php echo s($navDatoHref); ?>"<?php echo !empty($navDato['externo']) ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo s($navDato['texto'] ?? ''); ?></a></li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</nav>
<?php
unset(
  $navDestinos, $navMarcaHref, $navMarcaSubtitulo, $navFichaCta, $navFichaDatos,
  $navEsAncla, $navI, $navHref, $navNombre, $navPista, $navCtaHref, $navDato, $navDatoHref
);
?>
