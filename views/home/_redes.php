<?php
/**
 * Directorio de redes sociales.
 *
 * Lo incluyen Ubicación y el pie, así que los iconos viven aquí y no
 * duplicados en las dos vistas. Las URLs salen de Services\SitioConfig; antes
 * estaban en el marcado con href="#", es decir, dos enlaces que no llevaban a
 * ninguna parte.
 *
 * Parámetros:
 * - $redesClase: clase del contenedor (por defecto 'loc__socials').
 * - $redesConNombre: además del icono, imprime el nombre de la red.
 */

$redesClase = (string)($redesClase ?? 'loc__socials');
$redesConNombre = (bool)($redesConNombre ?? false);

// Los trazos van aquí y no en un sprite: son dos, no se reutilizan fuera de
// este parcial y un <use> obligaría a un <svg> oculto en el shell de la home.
$redesIconos = [
    'instagram' => '<path fill-rule="evenodd" d="M3 8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v8a5 5 0 0 1-5 5H8a5 5 0 0 1-5-5V8Zm5-3a3 3 0 0 0-3 3v8a3 3 0 0 0 3 3h8a3 3 0 0 0 3-3V8a3 3 0 0 0-3-3H8Zm7.597 2.214a1 1 0 0 1 1-1h.01a1 1 0 1 1 0 2h-.01a1 1 0 0 1-1-1ZM12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6Zm-5 3a5 5 0 1 1 10 0 5 5 0 0 1-10 0Z" clip-rule="evenodd"/>',
    'whatsapp' => '<path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39a9.86 9.86 0 0 0 4.74 1.21h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.15h-.01a8.2 8.2 0 0 1-4.18-1.15l-.3-.18-3.1.81.83-3.03-.2-.31a8.17 8.17 0 0 1-1.25-4.39c0-4.54 3.7-8.23 8.23-8.23 2.2 0 4.26.86 5.82 2.41a8.18 8.18 0 0 1 2.41 5.83c0 4.54-3.7 8.24-8.25 8.24Zm4.52-6.17c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.16.24-.64.8-.78.97-.15.16-.29.18-.53.06-.25-.13-1.05-.39-1.99-1.23-.74-.66-1.23-1.47-1.38-1.71-.14-.25-.01-.38.11-.5.11-.11.25-.29.37-.44.13-.14.17-.24.25-.41.09-.16.05-.3-.02-.43-.06-.12-.55-1.34-.76-1.83-.2-.48-.4-.42-.55-.42l-.48-.01c-.16 0-.43.06-.65.31-.23.24-.86.84-.86 2.05s.88 2.38 1 2.54c.13.17 1.74 2.65 4.21 3.71.59.26 1.05.41 1.4.52.59.19 1.13.16 1.55.1.48-.07 1.47-.6 1.67-1.18.21-.58.21-1.08.15-1.18-.06-.11-.22-.17-.47-.29Z"/>',
];
?>
<div class="<?php echo s($redesClase); ?>" data-reveal>
  <?php foreach (\Services\SitioConfig::redes() as $red) : ?>
    <?php if (!isset($redesIconos[$red['id']])) { continue; } ?>
    <a href="<?php echo s($red['url']); ?>" target="_blank" rel="noopener"
       aria-label="<?php echo s($red['nombre']); ?>" data-magnetic>
      <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><?php echo $redesIconos[$red['id']]; ?></svg>
      <?php if ($redesConNombre) : ?><span><?php echo s($red['nombre']); ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>
<?php
// Los parciales incluidos más de una vez por página comparten scope: sin esto
// el segundo include heredaría los parámetros del primero.
unset($redesClase, $redesConNombre, $redesIconos, $red);
?>
