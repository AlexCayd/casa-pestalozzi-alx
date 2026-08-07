<?php $mapLegendBlueLabel = (($mapVisual['context'] ?? '') === 'operacion-reservaciones') ? 'Mesa comprometida' : 'Reservación próxima'; ?>
<div class="operational-map__legend mesas-map__legend mapa-leyenda" aria-label="Estados de mesas" data-map-legend>
    <ul class="mapa-leyenda__row" aria-label="Estados de mesas">
        <li class="mapa-leyenda-item mapa-leyenda-item--libre">Verde &mdash; Disponible</li>
        <li class="mapa-leyenda-item mapa-leyenda-item--ocupada">Rojo &mdash; Ocupada</li>
        <li class="mapa-leyenda-item mapa-leyenda-item--seleccionada">Amarillo &mdash; Selecci&oacute;n actual</li>
        <li class="mapa-leyenda-item mapa-leyenda-item--reservacion-proxima">Azul &mdash; <?php echo htmlspecialchars($mapLegendBlueLabel, ENT_QUOTES, 'UTF-8'); ?></li>
        <li class="mapa-leyenda-item mapa-leyenda-item--no-utilizable">Neutro &mdash; No utilizable</li>
    </ul>
</div>
