<?php
/**
 * Ficha de una reservación, acoplada al mapa del POS.
 *
 * No flota: en horizontal es una columna a la derecha y el plano se reacomoda
 * al ancho que queda; en vertical va debajo del plano. Flotando, a 1024×768
 * tapaba la Mesa 7, la Mesa 8 y las tres barras —justo el exterior de los
 * grupos grandes—. Tampoco es modal: el mapa sigue tocable para saltar de una
 * reserva a otra.
 *
 * Nace oculta y vacía; pos-ficha.js la pinta. Contrato: #pos-ficha,
 * data-ficha-cuerpo, data-ficha-acciones.
 */
?>
<aside class="pos-ficha" id="pos-ficha" role="dialog" aria-modal="false"
       aria-labelledby="pos-ficha-nombre" tabindex="-1" hidden>
  <div class="pos-ficha__cuerpo" data-ficha-cuerpo></div>
  <footer class="pos-ficha__acciones" data-ficha-acciones></footer>
</aside>
