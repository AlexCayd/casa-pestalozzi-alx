<?php
/* Marca fija + botón de menú + menú a pantalla completa + índice lateral */

use Services\Configuration\SitioConfig;
use Services\Reservations\ReservacionConfig;
use Services\Scheduling\HorarioOperacionService;

/*
 * El menú dice en español qué hay en cada sitio: lo abre quien busca algo
 * concreto —la carta, reservar, cómo llegar— y la voz italiana de los rótulos
 * («La nostra storia», «Il tavolo») obligaba a traducir antes de elegir. La voz
 * italiana sigue en los rótulos de cada sección.
 *
 * Una sola cosa por renglón: el destino. La pista (lo que el visitante va a
 * encontrar) aparece al apuntar o tabular, no siempre: con número, nombre y
 * pista a la vista en nueve renglones el panel era una tabla. Al reordenar se
 * sincronizan esta lista, el orden de las secciones y el índice lateral.
 */
$navDestinos = [
  ['#nosotros',  'Nosotros',      'La casa y su historia'],
  ['#menu',      'La carta',      'Platillos y precios'],
  ['#firma',     'De temporada',  'Los especiales de la casa'],
  ['#chef',      'La cocina',     'Quién cocina y cómo'],
  ['#panaderia', 'Panadería',     'Pan de masa madre, cada mañana'],
  ['#catas',     'Catas y vinos', 'Agenda, maridaje y coctelería'],
  ['#catering',  'Eventos',       'Catering y celebraciones'],
  ['#reserva',   'Reservar mesa', 'Elige día, hora y personas'],
  ['#ubicacion', 'Cómo llegar',   'Dirección, mapa y horario'],
];

/*
 * La línea de «Hoy», que es la pregunta que más se hace antes de salir de
 * casa. Sale del mismo servicio que la tabla de horarios y el pie (con sus
 * excepciones del día), así que nunca contradice a ninguno de los dos.
 */
$navHoy = null;
if (!empty($horariosOperacionDisponibles) && is_array($horariosOperacion ?? null)) {
  $navAhora = ReservacionConfig::ahora();
  $navDia = (int)$navAhora->format('w');
  $navExcepcion = HorarioOperacionService::mapearExcepcionesDeLaSemana(
    is_array($proximasExcepcionesOperacion ?? null) ? $proximasExcepcionesOperacion : [],
    $navAhora
  )[$navDia] ?? null;
  $navHorario = null;
  foreach ($horariosOperacion as $navFila) {
    if ((int)($navFila['dia_semana'] ?? -1) === $navDia) {
      $navHorario = $navFila;
    }
  }

  if ($navExcepcion !== null) {
    $navHoy = ($navExcepcion['tipo'] ?? '') === 'horario_especial'
      ? 'Horario especial: de ' . ($navExcepcion['hora_apertura'] ?? '') . ' a ' . ($navExcepcion['hora_cierre'] ?? '')
      : 'Hoy no abrimos';
  } elseif ($navHorario !== null) {
    $navHoy = !empty($navHorario['abierto'])
      ? 'De ' . ($navHorario['hora_apertura'] ?? '') . ' a ' . ($navHorario['hora_cierre'] ?? '')
      : 'Hoy no abrimos';
  }
}
?>

<?php /* ═══ COMPONENTE REUTILIZABLE → views/templates/header-casa-pestalozzi.php
         Nivel <p> porque el <h1> de la portada es el título del hero.
         `data-magnetic` en el contenedor: el imán mueve la caja entera. */ ?>
<?php
$hcpEtiqueta = 'div';
$hcpNivel = 'p';
$hcpHref = '#hero';
$hcpSubtitulo = 'Del Valle · México';
$hcpClase = 'brand-mark';
$hcpAtributos = ['data-magnetic' => ''];
include __DIR__ . '/../templates/header-casa-pestalozzi.php';
?>

<?php /* aria-expanded y aria-controls los mantiene nav.js: sin ellos el lector
         de pantalla anunciaba «Abrir menú» también con el menú abierto. */ ?>
<button class="nav-toggle" id="navToggle" type="button" aria-label="Abrir menú"
        aria-expanded="false" aria-controls="navOverlay" data-magnetic>
  <span class="bar"></span><span class="bar"></span><span class="bar"></span>
</button>

<?php /* Papel crema y no verde: la portada es clara, y abrir el menú no debería
         apagar la luz. `data-lenis-prevent`: en un teléfono la lista y la ficha
         no caben juntas y el menú se desplaza por su cuenta. */ ?>
<nav class="nav-overlay" id="navOverlay" data-tono="crema" aria-label="Navegación principal" data-lenis-prevent>
  <div class="nav-overlay__principal">
    <ol class="nav-overlay__links">
      <?php foreach ($navDestinos as $navI => [$navHref, $navNombre, $navPista]) : ?>
        <li style="--i:<?php echo $navI; ?>">
          <a href="<?php echo s($navHref); ?>" data-nav>
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

    <?php /* Lo imprescindible para venir, y nada más: reservar, el horario de
             hoy, la dirección y el teléfono. Sin rótulos delante: cada dato se
             explica solo. Las cartas en PDF, el correo y la marca repetida
             salieron; están en sus secciones y en el pie. */ ?>
    <div class="nav-overlay__ficha">
      <a class="btn-line btn-line--solid nav-overlay__reservar" href="#reserva" data-nav>
        <span>Reservar mesa</span><span class="arrow" aria-hidden="true">→</span>
      </a>
      <ul class="nav-overlay__datos">
        <?php if ($navHoy !== null) : ?>
          <li><?php echo s($navHoy === 'Hoy no abrimos' ? $navHoy : 'Hoy, ' . lcfirst($navHoy)); ?></li>
        <?php endif; ?>
        <li><a href="<?php echo s(SitioConfig::mapsUrl()); ?>" target="_blank" rel="noopener"><?php echo s(SitioConfig::direccion()); ?></a></li>
        <li><a href="tel:<?php echo s(SitioConfig::telefonoTel()); ?>"><?php echo s(SitioConfig::telefonoVisible()); ?></a></li>
      </ul>
    </div>
  </div>
</nav>

<?php /*
  Índice de secciones. Mismas palabras que el menú, más cortas: es un indicador
  de posición que se lee de reojo mientras se baja. Son enlaces de navegación
  reales y se leen todos.
*/ ?>
<nav class="rail" id="rail" aria-label="Índice de secciones">
  <a href="#hero" data-rail="hero"><span class="rlabel">Inicio</span><span class="tick"></span></a>
  <a href="#nosotros" data-rail="nosotros"><span class="rlabel">Nosotros</span><span class="tick"></span></a>
  <a href="#menu" data-rail="menu"><span class="rlabel">Carta</span><span class="tick"></span></a>
  <a href="#firma" data-rail="firma"><span class="rlabel">Temporada</span><span class="tick"></span></a>
  <a href="#chef" data-rail="chef"><span class="rlabel">Cocina</span><span class="tick"></span></a>
  <a href="#panaderia" data-rail="panaderia"><span class="rlabel">Panadería</span><span class="tick"></span></a>
  <a href="#catas" data-rail="catas"><span class="rlabel">Catas</span><span class="tick"></span></a>
  <a href="#catering" data-rail="catering"><span class="rlabel">Eventos</span><span class="tick"></span></a>
  <a href="#reserva" data-rail="reserva"><span class="rlabel">Reservar</span><span class="tick"></span></a>
  <a href="#ubicacion" data-rail="ubicacion"><span class="rlabel">Cómo llegar</span><span class="tick"></span></a>
</nav>
<?php unset($navDestinos, $navHoy, $navAhora, $navDia, $navExcepcion, $navHorario, $navFila, $navI, $navHref, $navNombre, $navPista); ?>
