<?php
/**
 * Anuncio del restaurante, como diálogo al entrar al sitio.
 *
 * Antes era una franja flotando sobre el hero que se cerraba sola a los 8 s: el
 * aviso competía con el título de la portada y el que llegaba tarde se lo
 * perdía. Como diálogo se lee una vez, sin prisa, y se cierra a voluntad.
 *
 * El marcado se emite al final del <body> (ver views/home/index.php) para que
 * el diálogo no herede transform ni overflow de ningún contenedor del hero.
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
?>
<div
  class="announcement-dialog"
  data-announcement
  data-announcement-id="<?php echo (int) ($anuncioPublico->id ?? 0); ?>"
  data-announcement-version="<?php echo $h($versionAnuncio); ?>"
  data-announcement-type="<?php echo $h($tipo); ?>"
  hidden
>
  <button class="announcement-dialog__backdrop" type="button" tabindex="-1" aria-hidden="true" data-announcement-close></button>
  <div
    class="announcement-dialog__panel"
    role="dialog"
    aria-modal="true"
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
      style="--announcement-accent: <?php echo $h($configTipo['acento'] ?? '#9fc2c5'); ?>"
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
    </aside>
  </div>
</div>
