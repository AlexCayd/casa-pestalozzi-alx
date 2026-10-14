<?php
/**
 * Contexto del mapa del POS: qué momento está mirando el mesero.
 *
 * Vive en el CENTRO de la barra operativa (slot $operationalHeaderCenterHtml,
 * que llena pos-workspace.php), no en una fila propia: entre la barra y el
 * plano le quitaba al salón el alto de una fila, mientras el centro de la
 * barra —el POS no pinta título— se quedaba vacío. El modo («Ahora 17:31» o la
 * hora proyectada con sus ‹ ›) hace de título de la pantalla y abre la barra,
 * junto al cajón, en el sitio que dejó el wordmark; los datos y el aviso de
 * reservas sin mesa se arriman al avatar. La ayuda «?» ya no vive aquí: está
 * en el menú del avatar (pos-workspace.php). Cuando falta ancho se pliega por
 * partes (_proyeccion.scss), sin cambiar de sitio. pos-contexto.js reescribe
 * el modo y los datos con cada respuesta del servidor.
 *
 * Contrato con el JS: data-pos-contexto (uno solo en la página),
 * data-contexto-modo, data-contexto-datos, data-contexto-anuncio.
 */
$posContextoReloj = \Services\Reservations\ReservacionConfig::ahora()->format('H:i');
?>
<div class="pos-contexto" id="pos-contexto" role="group" aria-label="Momento del mapa" tabindex="-1" data-pos-contexto data-modo="ahora">
  <div class="pos-contexto__modo" data-contexto-modo>
    <span class="pos-contexto__reloj">
      <span>Ahora</span><time data-contexto-reloj><?php echo htmlspecialchars($posContextoReloj, ENT_QUOTES, 'UTF-8'); ?></time>
    </span>
  </div>

  <div class="pos-contexto__datos" data-contexto-datos></div>

  <?php /* Sólo anuncia cambios de modo. El reloj de «Ahora» NO va en región
           viva: se anunciaría cada minuto. */ ?>
  <p class="operational-visually-hidden" role="status" aria-live="polite" data-contexto-anuncio></p>
</div>
<?php unset($posContextoReloj); ?>
