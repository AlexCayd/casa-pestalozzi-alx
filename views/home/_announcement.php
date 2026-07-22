<?php
if (!isset($anuncioPublico) || !is_object($anuncioPublico)) {
  return;
}

$h = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$tiposPermitidos = ['informativo', 'advertencia', 'importante'];
$tipo = in_array((string) $anuncioPublico->tipo, $tiposPermitidos, true)
  ? (string) $anuncioPublico->tipo
  : 'informativo';
$etiquetasTipo = [
  'informativo' => 'Información',
  'advertencia' => 'Advertencia',
  'importante' => 'Importante',
];
$mensaje = trim((string) $anuncioPublico->mensaje);
$textoEnlace = trim((string) ($anuncioPublico->texto_enlace ?? ''));
$urlEnlace = trim((string) ($anuncioPublico->url_enlace ?? ''));
$urlEnlace = \Model\ConfiguracionAnuncio::esUrlPermitida($urlEnlace) ? $urlEnlace : '';
$tieneEnlace = $textoEnlace !== '' && $urlEnlace !== '';
$esExterno = preg_match('~^https?://~i', $urlEnlace) === 1;
$versionBase = implode('|', [
  (string) ($anuncioPublico->updated_at ?? ''),
  $mensaje,
  $tipo,
  $textoEnlace,
  $urlEnlace,
]);
$version = (string) ($anuncioPublico->updated_at ?? 'sin-fecha') . '-' . substr(hash('sha256', $versionBase), 0, 12);
?>
<aside
  class="hero-announcement hero-announcement--<?php echo $h($tipo); ?> hero-announcement--<?php echo $tieneEnlace ? 'has-link' : 'without-link'; ?>"
  data-announcement
  data-announcement-type="<?php echo $h($tipo); ?>"
  data-announcement-version="<?php echo $h($version); ?>"
  aria-label="Anuncio del restaurante"
>
  <span class="hero-announcement__indicator" aria-hidden="true"></span>
  <div class="hero-announcement__content">
    <div class="hero-announcement__heading">
      <span class="hero-announcement__icon" aria-hidden="true">
        <svg data-announcement-icon="informativo" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 8h.01"/></svg>
        <svg data-announcement-icon="advertencia" viewBox="0 0 24 24"><path d="M10.3 4.3 2.7 17.2A2 2 0 0 0 4.4 20h15.2a2 2 0 0 0 1.7-2.8L13.7 4.3a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4"/><path d="M12 16h.01"/></svg>
        <svg data-announcement-icon="importante" viewBox="0 0 24 24"><path d="M4 10v4"/><path d="M7 9v6"/><path d="m7 9 10-4v14L7 15"/><path d="m9 15 1 5H7l-1-5"/><path d="M20 10v4"/></svg>
      </span>
      <span class="hero-announcement__type"><?php echo $h($etiquetasTipo[$tipo]); ?></span>
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
</aside>
