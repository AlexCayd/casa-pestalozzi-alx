<div class="operational-map__legend mesas-map__legend mapa-leyenda" aria-label="Estados de mesas" data-map-legend>
    <ul class="mapa-leyenda__row" aria-label="Estados de mesas">
        <li class="mapa-leyenda-item mapa-leyenda-item--libre">
            <span class="mesa-pin mesa-pin--libre" aria-hidden="true"></span>
            Verde &mdash; Disponible en el contexto mostrado
        </li>
        <li class="mapa-leyenda-item mapa-leyenda-item--mod-reservacion_advertencia">
            <span class="mesa-pin mesa-pin--libre mesa-pin--mod-reservacion_advertencia" aria-hidden="true"></span>
            Borde azul discontinuo &mdash; Reservaci&oacute;n cercana; revisa la disponibilidad real
        </li>
        <li class="mapa-leyenda-item mapa-leyenda-item--reservacion-proxima">
            <span class="mesa-pin mesa-pin--reservacion-proxima mesa-pin--mod-reservacion_inminente" aria-hidden="true"></span>
            Azul &mdash; Reservaci&oacute;n pr&oacute;xima o cliente en tolerancia
        </li>
        <li class="mapa-leyenda-item mapa-leyenda-item--ausencia-pendiente">
            <span class="mesa-pin mesa-pin--libre mesa-pin--mod-ausencia_pendiente mesa-pin--mod-accion_pendiente" aria-hidden="true"><span class="mesa-pin__pending">!</span></span>
            Alerta secundaria &mdash; Tolerancia vencida; conserva el estado del intervalo
        </li>
        <li class="mapa-leyenda-item mapa-leyenda-item--ocupada">
            <span class="mesa-pin mesa-pin--ocupada" aria-hidden="true"></span>
            Rojo &mdash; Ticket abierto o intervalo no disponible
        </li>
        <li class="mapa-leyenda-item mapa-leyenda-item--seleccionada">
            <span class="mesa-pin mesa-pin--libre mesa-pin--seleccionada" aria-hidden="true"></span>
            Amarillo &mdash; Mesa seleccionada; se conservan los bloqueos
        </li>
        <li class="mapa-leyenda-item mapa-leyenda-item--no-utilizable">
            <span class="mesa-pin mesa-pin--no-utilizable" aria-hidden="true"></span>
            Neutro &mdash; No utilizable en este contexto
        </li>
    </ul>
</div>
