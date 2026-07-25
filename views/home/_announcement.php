<?php
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
<aside
  class="hero-announcement hero-announcement--<?php echo $h($tipo); ?> hero-announcement--<?php echo $tieneEnlace ? 'has-link' : 'without-link'; ?>"
  data-announcement
  data-announcement-id="<?php echo (int) ($anuncioPublico->id ?? 0); ?>"
  data-announcement-version="<?php echo $h($versionAnuncio); ?>"
  data-announcement-type="<?php echo $h($tipo); ?>"
  data-duration="<?php echo \Services\AnuncioConfig::DURACION_VISIBLE_MS; ?>"
  style="--announcement-accent: <?php echo $h($configTipo['acento'] ?? '#9fc2c5'); ?>"
  aria-label="Anuncio del restaurante"
  hidden
>
  <span class="hero-announcement__indicator" aria-hidden="true"></span>
  <div class="hero-announcement__content">
    <div class="hero-announcement__heading">
      <span class="hero-announcement__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><?php echo $configTipo['icono'] ?? ''; ?></svg>
      </span>
      <span class="hero-announcement__type"><?php echo $h($configTipo['etiqueta'] ?? 'Evento'); ?></span>
    </div>
    <p class="hero-announcement__message"><?php echo $h($mensaje); ?></p>
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
  <div class="hero-announcement__progress" aria-hidden="true">
    <span class="hero-announcement__progress-remaining" data-announcement-progress></span>
  </div>
</aside>
