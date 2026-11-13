<?php
/**
 * «Se compra por pieza» (arquitectura §16): el contenido por pieza sólo tiene sentido
 * cuando se compra en botellas, latas o paquetes de un artículo en g, kg, ml o l. Por
 * eso el campo se bloquea hasta marcar la casilla; en `pza` la casilla también.
 * Desmarcarla borra el valor: el campo oculto vacío viaja cuando el número está
 * deshabilitado, y el servidor lo borra con `por_pieza_control` aunque no haya
 * JavaScript. El bloqueo lo pone articulos.js al cargar (mejora progresiva): si el
 * servidor pintara `disabled`, sin JavaScript no habría forma de capturarlo.
 *
 * Espera $esc, $piezaValor (sin ceros de relleno), $piezaUnidad y $piezaPrefijo.
 */
$piezaActiva=(string)$piezaValor!==''&&$piezaUnidad!=='pza';
?>
<div class="admin-form-grid__full m6-pieza" data-m6-pieza>
<input type="hidden" name="por_pieza_control" value="1">
<label class="m2-capacidad"><input type="checkbox" name="por_pieza" value="1" data-m6-pieza-activar <?= $piezaActiva?'checked':'' ?> <?= $piezaUnidad==='pza'?'disabled':'' ?> aria-controls="<?= $esc($piezaPrefijo) ?>-pieza"><span>Se compra por pieza (botella, lata, paquete) <small>Sólo para artículos en g, kg, ml o l. Actívalo para capturar cuánto contiene cada pieza.</small></span></label>
<input type="hidden" name="contenido_pieza" value="">
<label class="admin-field m6-pieza__campo">Contenido por pieza<input id="<?= $esc($piezaPrefijo) ?>-pieza" name="contenido_pieza" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" value="<?= $esc($piezaActiva?$piezaValor:'') ?>" data-m6-pieza-campo aria-describedby="<?= $esc($piezaPrefijo) ?>-pieza-ayuda"><span class="admin-field__hint" id="<?= $esc($piezaPrefijo) ?>-pieza-ayuda">Cuánto contiene una pieza, en la unidad del artículo: 750 si es un vino en ml que se compra por botella.</span></label>
</div>
