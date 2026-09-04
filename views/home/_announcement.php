<?php
/**
 * Anuncio del restaurante al entrar al sitio.
 *
 * Dos presentaciones según el tipo, decididas en AnuncioConfig y nunca aquí:
 * los eventos y los avisos operativos cambian el plan de quien va a venir y se
 * muestran como diálogo modal, que se lee una vez y se cierra a voluntad; las
 * promociones y novedades de la carta van como aviso discreto en la esquina,
 * que se retira solo y no bloquea la página.
 *
 * El marcado se emite al final del <body> (ver views/home/index.php) para que
 * no herede transform ni overflow de ningún contenedor del hero.
 */
if (!isset($anuncioPublico) || !is_object($anuncioPublico)) {
  return;
}

$h = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tiposPermitidos = array_keys(\Services\AnuncioConfig::TIPOS);
$tipo = in_array((string) $anuncioPublico->tipo, $tiposPermitidos, true)
  ? (string) $anuncioPublico->tipo
  : \Services\AnuncioConfig::TIPO_PREDETERMINADO;
$configTipo = \Services\AnuncioConfig::tipo($tipo);
$mensaje = trim((string) $anuncioPublico->mensaje);
$textoEnlace = trim((string) ($anuncioPublico->texto_enlace ?? ''));
$urlEnlace = trim((string) ($anuncioPublico->url_enlace ?? ''));
$urlEnlace = \Model\ConfiguracionAnuncio::esUrlPermitida($urlEnlace) ? $urlEnlace : '';
$tieneEnlace = $textoEnlace !== '' && $urlEnlace !== '';
$esExterno = preg_match('~^https?://~i', $urlEnlace) === 1;
$versionAnuncio = trim((string) ($anuncioPublico->updated_at ?? ''));
if ($versionAnuncio === '') {
  $versionAnuncio = hash('sha256', implode('|', [
    $tipo,
    $mensaje,
    $textoEnlace,
    $urlEnlace,
    (string) ($anuncioPublico->fecha_inicio ?? ''),
    (string) ($anuncioPublico->fecha_fin ?? ''),
  ]));
}
$presentacion = \Services\AnuncioConfig::presentacion($tipo);
$esModal = $presentacion === \Services\AnuncioConfig::PRESENTACION_MODAL;
$raiz = $esModal ? 'announcement-dialog' : 'announcement-toast';
?>
<div
  class="<?php echo $raiz; ?>"
  data-announcement
  data-announcement-id="<?php echo (int) ($anuncioPublico->id ?? 0); ?>"
  data-announcement-version="<?php echo $h($versionAnuncio); ?>"
  data-announcement-type="<?php echo $h($tipo); ?>"
  data-announcement-presentacion="<?php echo $h($presentacion); ?>"
  <?php if (!$esModal) : ?>
    data-announcement-duracion="<?php echo (int) \Services\AnuncioConfig::DURACION_VISIBLE_MS; ?>"
  <?php endif; ?>
  hidden
>
  <?php if ($esModal) : ?>
    <button class="announcement-dialog__backdrop" type="button" tabindex="-1" aria-hidden="true" data-announcement-close></button>
  <?php endif; ?>
  <?php /* El discreto no es un diálogo: no atrapa el foco ni exige respuesta,
           así que anunciarlo como role="dialog" le mentiría al lector de
           pantalla. Va como region con cortesía polite, que lo lee al terminar
           lo que esté diciendo. */ ?>
  <div
    class="<?php echo $raiz; ?>__panel"
    <?php if ($esModal) : ?>
      role="dialog"
      aria-modal="true"
    <?php else : ?>
      role="status"
      aria-live="polite"
    <?php endif; ?>
    aria-labelledby="announcement-dialog-type"
    aria-describedby="announcement-dialog-message"
    tabindex="-1"
    data-announcement-panel
  >
    <?php /* Se reutiliza la tarjeta .hero-announcement: es la misma pieza que
             pinta la vista previa del panel, así que el administrador sigue
             viendo exactamente lo que verá el comensal. */ ?>
    <aside
      class="hero-announcement hero-announcement--<?php echo $h($tipo); ?> hero-announcement--<?php echo $tieneEnlace ? 'has-link' : 'without-link'; ?>"
      style="--announcement-accent: <?php echo $h($configTipo['acento'] ?? 'var(--accent)'); ?>"
    >
      <span class="hero-announcement__indicator" aria-hidden="true"></span>
      <div class="hero-announcement__content">
        <div class="hero-announcement__heading">
          <span class="hero-announcement__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><?php echo $configTipo['icono'] ?? ''; ?></svg>
          </span>
          <span class="hero-announcement__type" id="announcement-dialog-type"><?php echo $h($configTipo['etiqueta'] ?? 'Evento'); ?></span>
        </div>
        <p class="hero-announcement__message" id="announcement-dialog-message"><?php echo $h($mensaje); ?></p>
        <?php if ($tieneEnlace) : ?>
          <a
            class="hero-announcement__link"
            href="<?php echo $h($urlEnlace); ?>"
            <?php echo $esExterno ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
          ><?php echo $h($textoEnlace); ?><span aria-hidden="true"> ↗</span></a>
        <?php endif; ?>
      </div>
      <button class="hero-announcement__close" type="button" data-announcement-close aria-label="Cerrar anuncio">
        <span aria-hidden="true">×</span>
      </button>
      <?php /* Barra de tiempo restante. Sólo la lleva el aviso discreto: es el
               único que se retira solo, y sin ella la desaparición parece un
               fallo del sitio. Va aria-hidden porque no aporta nada a quien no
               ve la pantalla —el texto ya se anunció por role="status"— y
               porque el temporizador se detiene con el foco dentro. */ ?>
      <?php if (!$esModal) : ?>
        <span class="hero-announcement__progress" aria-hidden="true">
          <span class="hero-announcement__progress-fill" data-announcement-progress></span>
        </span>
      <?php endif; ?>
    </aside>
  </div>
</div>
