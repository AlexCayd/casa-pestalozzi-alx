<?php
/* Menú de la portada del restaurante: sus destinos y su ficha, que pinta el
   componente de marca, más el índice lateral, que sólo existe aquí. */

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

<?php
/* ═══ MARCA → views/components/marca/nav.php
   El menú es el mismo en las dos landings; aquí sólo se le dan los destinos y
   la ficha del restaurante. */
$navMarcaHref = '#hero';
$navMarcaSubtitulo = 'Del Valle · México';
$navFichaCta = ['href' => '#reserva', 'texto' => 'Reservar mesa', 'externo' => false];
$navFichaDatos = [];
if ($navHoy !== null) {
  $navFichaDatos[] = ['texto' => $navHoy === 'Hoy no abrimos' ? $navHoy : 'Hoy, ' . lcfirst($navHoy)];
}
$navFichaDatos[] = ['texto' => SitioConfig::direccion(), 'href' => SitioConfig::mapsUrl(), 'externo' => true];
$navFichaDatos[] = ['texto' => SitioConfig::telefonoVisible(), 'href' => 'tel:' . SitioConfig::telefonoTel()];
include __DIR__ . '/../components/marca/nav.php';
?>

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
<?php unset($navHoy, $navAhora, $navDia, $navExcepcion, $navHorario, $navFila); ?>
