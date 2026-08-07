<?php $mapLegendBlueLabel = (($mapVisual['context'] ?? '') === 'operacion-reservaciones') ? 'Mesa comprometida' : 'Reservación próxima'; ?>
<div class="operational-map__legend mesas-map__legend mapa-leyenda" aria-label="Estados de mesas" data-map-legend>
    <ul class="mapa-leyenda__row" aria-label="Estados de mesas">
        <li class="mapa-leyenda-item mapa-leyenda-item--libre">Verde &mdash; Disponible</li>
        <li class="mapa-leyenda-item mapa-leyenda-item--ocupada">Rojo &mdash; Ocupada</li>
        <li class="mapa-leyenda-item mapa-leyenda-item--seleccionada">Amarillo &mdash; Selecci&oacute;n actual</li>
        <li class="mapa-leyenda-item mapa-leyenda-item--reservacion-proxima">Azul &mdash; <?php echo htmlspecialchars($mapLegendBlueLabel, ENT_QUOTES, 'UTF-8'); ?></li>
        <?php if (($mapVisual['context'] ?? '') === 'operacion-reservaciones'): ?>
            <li class="mapa-leyenda-item mapa-leyenda-item--mod-reservacion_advertencia">Verde punteado &mdash; Reservación en 30&ndash;60 minutos</li>
            <li class="mapa-leyenda-item mapa-leyenda-item--mod-reservacion_tolerancia">Azul con borde gris &mdash; Dentro de tolerancia</li>
            <li class="mapa-leyenda-item mapa-leyenda-item--mod-accion_pendiente">Verde con borde gris &mdash; Ausencia pendiente</li>
        <?php endif; ?>
        <li class="mapa-leyenda-item mapa-leyenda-item--no-utilizable">Neutro &mdash; No utilizable</li>
    </ul>
</div>
