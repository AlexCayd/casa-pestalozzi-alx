<?php
/**
 * ┌───────────────────────────────────────────────────────────────┐
 * │  MARCA — compartido por la landing del restaurante y la de la │
 * │  panadería.                                                   │
 * └───────────────────────────────────────────────────────────────┘
 *
 * El visor de fotos. Lo abre lightbox.js sobre cualquier [data-zoom] de la
 * página, así que basta con incluirlo una vez al final del <body>.
 */
?>
<?php /* Lightbox. Lleva data-tono="verde" como la banda oscura de la
         landing: es lo que hace que sus textos, líneas y acento se resuelvan
         solos. Sin el tono heredaba la capa 3 de :root —pensada para fondo
         crema— y pintaba tinta verde sobre el fondo hondo del visor. */ ?>
<div class="lightbox" id="lightbox" data-tono="verde" role="dialog" aria-modal="true" aria-label="Foto ampliada" aria-hidden="true">
  <div class="lightbox__counter"><b id="lbCur">1</b> / <span id="lbTotal">1</span></div>
  <?php /* Acercamiento. Tres controles y no dos: el del medio dice a cuánto
           está la foto y la devuelve a su tamaño (es el «0» del teclado).
           aria-disabled y no disabled en los extremos: un botón deshabilitado
           pierde el foco y, con el fondo inerte, el foco caería al <body>. */ ?>
  <div class="lightbox__zoom" role="group" aria-label="Acercamiento">
    <button type="button" class="lightbox__zoom-btn" id="lbAlejar" aria-label="Alejar" aria-keyshortcuts="-" aria-disabled="true"><svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4.5 10h11"/></svg></button>
    <button type="button" class="lightbox__zoom-btn lightbox__zoom-nivel" id="lbNivel" aria-keyshortcuts="0" aria-disabled="true"><span class="sr-only">Restablecer tamaño, </span><span id="lbNivelTxt">100 %</span></button>
    <button type="button" class="lightbox__zoom-btn" id="lbAcercar" aria-label="Acercar" aria-keyshortcuts="+"><svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4.5 10h11M10 4.5v11"/></svg></button>
  </div>
  <?php /* Iconos dibujados y no los glifos ✕ ‹ ›: los pinta la fuente del
           sistema, no heredan el color y cambian de forma entre plataformas. */ ?>
  <button type="button" class="lightbox__close" id="lbClose" aria-label="Cerrar"><svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M4 4l12 12M16 4L4 16"/></svg></button>
  <button type="button" class="lightbox__nav prev" id="lbPrev" aria-label="Foto anterior"><svg viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12.5 4 6.5 10l6 6"/></svg></button>
  <button type="button" class="lightbox__nav next" id="lbNext" aria-label="Foto siguiente"><svg viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M7.5 4l6 6-6 6"/></svg></button>
  <?php /* El visor recorta la foto y es lo que se acerca: la imagen se escala
           y se desplaza DENTRO de él (lightbox.js). Es también la pieza que
           hace el morfo con la miniatura, así que la foto ampliada crece y se
           encoge con su recorte puesto. `draggable="false"`: el arrastre es
           para recorrer la foto ampliada, no para sacarla de la página. */ ?>
  <div class="lightbox__escena">
    <div class="lightbox__img" id="lbVisor"><img id="lbImg" alt="" draggable="false" /></div>
    <p class="lightbox__hint">
      <span class="lightbox__hint-reposo">Flechas para navegar · + y − para acercar · Esc para cerrar</span>
      <span class="lightbox__hint-ampliada">Arrastra o usa las flechas para recorrer · 0 para volver al tamaño</span>
    </p>
    <div class="lightbox__cap"><div class="t" id="lbT"></div><div class="n" id="lbN"></div></div>
  </div>
  <p class="sr-only" id="lbAnuncio" aria-live="polite"></p>
</div>
