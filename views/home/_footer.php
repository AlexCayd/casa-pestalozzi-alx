<?php
/**
 * Pie de la portada del restaurante: sus columnas y su horario, que pinta el
 * componente de marca (views/components/marca/pie.php), más el visor de fotos
 * y el panel de tweaks, que es sólo de esta página.
 *
 * El horario se arma con las MISMAS excepciones que la sección de reservación
 * (Services\Scheduling\HorarioOperacionService::mapearExcepcionesDeLaSemana): antes el pie
 * listaba sólo el horario semanal y podía contradecir a la tabla de arriba —una
 * decía "martes 16:00–23:00 por comida privada" y la otra "martes 08:30–22:00"—.
 */

use Services\Scheduling\HorarioOperacionService;
use Services\Reservations\ReservacionConfig;
use Services\Configuration\SitioConfig;

$hoyFooter = ReservacionConfig::ahora();
$hoyDiaFooter = (int)$hoyFooter->format('w');
$excepcionesFooter = !empty($horariosOperacionDisponibles)
  ? HorarioOperacionService::mapearExcepcionesDeLaSemana(
      is_array($proximasExcepcionesOperacion ?? null) ? $proximasExcepcionesOperacion : [],
      $hoyFooter
    )
  : [];

$pieHorario = null;
if (!empty($horariosOperacionDisponibles)) {
  $pieHorario = [];
  foreach ($horariosOperacion as $horario) {
    $diaFooter = (int)($horario['dia_semana'] ?? -1);
    $excepcionFooter = $excepcionesFooter[$diaFooter] ?? null;
    $habitualFooter = !empty($horario['abierto'])
      ? ($horario['hora_apertura'] ?? '') . '–' . ($horario['hora_cierre'] ?? '')
      : 'Cerrado';
    $pieHorario[] = [
      'dia' => (string)($horario['nombre'] ?? ''),
      'hoy' => $diaFooter === $hoyDiaFooter,
      'texto' => $habitualFooter,
      'excepcion' => $excepcionFooter === null ? null : (($excepcionFooter['tipo'] ?? '') === 'horario_especial'
        ? ($excepcionFooter['hora_apertura'] ?? '') . '–' . ($excepcionFooter['hora_cierre'] ?? '')
        : 'Cerrado'),
      'habitual' => $habitualFooter,
    ];
  }
}

/* ═══ MARCA → views/components/marca/pie.php
   Las mismas palabras que el menú (_nav.php): un destino con dos nombres
   —«Lo de la Casa» aquí, «De temporada» allí; «Catering» y «Eventos»— obliga a
   comprobar que son el mismo sitio. */
$pieLema = 'Cucina italiana, cuore messicano.';
$pieRedes = SitioConfig::redes();
$pieColumnas = [
  ['titulo' => 'Explora', 'enlaces' => [
    ['texto' => 'La carta', 'href' => '#menu'],
    ['texto' => 'De temporada', 'href' => '#firma'],
    ['texto' => 'Panadería', 'href' => '#panaderia'],
    ['texto' => 'Catas y vinos', 'href' => '#catas'],
    ['texto' => 'Eventos', 'href' => '#catering'],
  ]],
  ['titulo' => 'Visita', 'enlaces' => [
    ['texto' => SitioConfig::direccionCorta(), 'href' => SitioConfig::mapsUrl(), 'externo' => true],
    ['texto' => SitioConfig::telefonoVisible(), 'href' => 'tel:' . SitioConfig::telefonoTel()],
    ['texto' => SitioConfig::correo(), 'href' => 'mailto:' . SitioConfig::correo()],
    ['texto' => 'Reservar mesa', 'href' => '#reserva'],
  ]],
];
$pieHorarioVacio = 'Consulta la disponibilidad al reservar.';
$pieCopia = 'Casa Pestalozzi · Del Valle, Ciudad de México';
$piePrivacidad = true;
include __DIR__ . '/../components/marca/pie.php';

unset($hoyFooter, $hoyDiaFooter, $excepcionesFooter, $diaFooter, $excepcionFooter, $habitualFooter, $horario);

include __DIR__ . '/../components/marca/visor.php';
?>

<!-- Panel de Tweaks -->
<aside id="tweaks" aria-label="Tweaks">
  <div class="tw-head"><h3>Tweaks</h3><button class="tw-close" id="twClose">✕</button></div>
  <?php /* «Estilo del hero» salió del panel con el hero de fotografía: sus
           tres variantes (cinemático, editorial, minimal) eran tratamientos de
           la foto, y el hero actual es una sola composición. */ ?>
  <?php /* El selector de acento salió del panel: la paleta la fija el manual
           de marca en los tokens y ya no se conmuta desde el navegador. */ ?>
  <div class="tw-group">
    <label>Interacción</label>
    <div class="tw-toggle"><span>Cursor personalizado</span><button class="tw-switch" data-tw="cursor"></button></div>
    <div class="tw-toggle"><span>Scroll suave</span><button class="tw-switch" data-tw="smooth"></button></div>
    <div class="tw-toggle"><span>Animaciones de entrada</span><button class="tw-switch" data-tw="anim"></button></div>
  </div>
</aside>
