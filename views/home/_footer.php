<?php
/**
 * Pie + lightbox + panel de tweaks.
 *
 * El horario se pinta con las MISMAS excepciones que la sección de reservación
 * (Services\HorarioOperacionService::mapearExcepcionesDeLaSemana): antes el pie
 * listaba sólo el horario semanal y podía contradecir a la tabla de arriba —una
 * decía "martes 16:00–23:00 por comida privada" y la otra "martes 08:30–22:00"—.
 */

use Services\HorarioOperacionService;
use Services\ReservacionConfig;
use Services\SitioConfig;

$hoyFooter = ReservacionConfig::ahora();
$hoyDiaFooter = (int)$hoyFooter->format('w');
$excepcionesFooter = !empty($horariosOperacionDisponibles)
  ? HorarioOperacionService::mapearExcepcionesDeLaSemana(
      is_array($proximasExcepcionesOperacion ?? null) ? $proximasExcepcionesOperacion : [],
      $hoyFooter
    )
  : [];
?>

<footer class="foot" data-tono="cafe">
  <div class="wrap">
    <div class="foot__top">
      <div class="foot__brand">
        <h3 class="bm">Casa Pestalozzi</h3>
        <p>Cucina italiana, cuore messicano.</p>
        <?php
          $redesClase = 'foot__social';
          $redesConNombre = true;
          include __DIR__ . '/_redes.php';
        ?>
      </div>
      <div class="foot__cols">
        <div class="foot__col">
          <h6>Explora</h6>
          <a href="#menu">La Carta</a>
          <a href="#firma">Lo de la Casa</a>
          <a href="#panaderia">Panadería</a>
          <a href="#catas">Catas</a>
          <a href="#catering">Catering</a>
        </div>
        <div class="foot__col">
          <h6>Visita</h6>
          <a href="<?php echo s(SitioConfig::mapsUrl()); ?>" target="_blank" rel="noopener"><?php echo s(SitioConfig::direccionCorta()); ?></a>
          <a href="tel:<?php echo s(SitioConfig::telefonoTel()); ?>"><?php echo s(SitioConfig::telefonoVisible()); ?></a>
          <a href="mailto:<?php echo s(SitioConfig::correo()); ?>"><?php echo s(SitioConfig::correo()); ?></a>
          <a href="#reserva">Reservar mesa</a>
        </div>
        <div class="foot__col foot__col--horario">
          <h6>Horario</h6>
          <?php if (!empty($horariosOperacionDisponibles)) : ?>
            <?php foreach ($horariosOperacion as $horario) : ?>
              <?php
                $diaFooter = (int)($horario['dia_semana'] ?? -1);
                $esHoyFooter = $diaFooter === $hoyDiaFooter;
                $excepcionFooter = $excepcionesFooter[$diaFooter] ?? null;
                $especialFooter = $excepcionFooter !== null
                  && ($excepcionFooter['tipo'] ?? '') === 'horario_especial';
                $claseFooter = 'foot__horario';
                if ($esHoyFooter) {
                  $claseFooter .= ' is-hoy';
                }
                if ($excepcionFooter !== null) {
                  $claseFooter .= ' is-excepcion';
                }
              ?>
              <span class="<?php echo $claseFooter; ?>">
                <b><?php echo s($horario['nombre'] ?? ''); ?><?php if ($esHoyFooter) : ?><small>Hoy</small><?php endif; ?></b>
                <?php if ($excepcionFooter !== null) : ?>
                  <em><?php echo $especialFooter
                    ? s(($excepcionFooter['hora_apertura'] ?? '') . '–' . ($excepcionFooter['hora_cierre'] ?? ''))
                    : 'Cerrado'; ?></em>
                <?php elseif (!empty($horario['abierto'])) : ?>
                  <span><?php echo s(($horario['hora_apertura'] ?? '') . '–' . ($horario['hora_cierre'] ?? '')); ?></span>
                <?php else : ?>
                  <span>Cerrado</span>
                <?php endif; ?>
              </span>
            <?php endforeach; ?>
          <?php else : ?>
            <span>Consulta la disponibilidad al reservar.</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="foot__bottom">
      <span>© <span id="year"></span> Casa Pestalozzi · Del Valle, Ciudad de México</span>
      <span class="foot__legal">
        <button type="button" class="foot__link" data-privacidad-open>Aviso de privacidad</button>
        <span aria-hidden="true">·</span>
        <span>Rediseño conceptual</span>
      </span>
    </div>
  </div>
</footer>
<?php unset($hoyFooter, $hoyDiaFooter, $excepcionesFooter, $diaFooter, $esHoyFooter, $excepcionFooter, $especialFooter, $claseFooter, $horario); ?>

<?php /* Lightbox. Lleva data-tono="cafe" como cualquier banda oscura de la
         landing: es lo que hace que sus textos, líneas y acento se resuelvan
         solos. Sin el tono heredaba la capa 3 de :root —pensada para fondo
         crema— y pintaba café sobre café. */ ?>
<div class="lightbox" id="lightbox" data-tono="cafe" aria-hidden="true">
  <div class="lightbox__counter"><b id="lbCur">1</b> / <span id="lbTotal">1</span></div>
  <button class="lightbox__close" id="lbClose" aria-label="Cerrar">✕</button>
  <button class="lightbox__nav prev" id="lbPrev" aria-label="Anterior">‹</button>
  <button class="lightbox__nav next" id="lbNext" aria-label="Siguiente">›</button>
  <div>
    <div class="lightbox__img"><img id="lbImg" alt="" /></div>
    <div class="lightbox__hint">Usa ← → para navegar · Esc para cerrar</div>
    <div class="lightbox__cap"><div class="t" id="lbT"></div><div class="n" id="lbN"></div></div>
  </div>
</div>

<!-- Panel de Tweaks -->
<aside id="tweaks" aria-label="Tweaks">
  <div class="tw-head"><h3>Tweaks</h3><button class="tw-close" id="twClose">✕</button></div>
  <div class="tw-group">
    <label>Estilo del hero</label>
    <div class="tw-opts" data-tw="hero">
      <button class="tw-opt" data-val="cinema">Cinemático</button>
      <button class="tw-opt" data-val="editorial">Editorial</button>
      <button class="tw-opt" data-val="minimal">Minimal</button>
    </div>
  </div>
  <?php /* El selector de acento salió del panel: la paleta la fija el manual
           de marca en los tokens y ya no se conmuta desde el navegador. */ ?>
  <div class="tw-group">
    <label>Interacción</label>
    <div class="tw-toggle"><span>Cursor personalizado</span><button class="tw-switch" data-tw="cursor"></button></div>
    <div class="tw-toggle"><span>Scroll suave</span><button class="tw-switch" data-tw="smooth"></button></div>
    <div class="tw-toggle"><span>Animaciones de entrada</span><button class="tw-switch" data-tw="anim"></button></div>
  </div>
</aside>
