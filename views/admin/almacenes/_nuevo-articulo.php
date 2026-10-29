<p>La identidad, unidad y costo pertenecen al catálogo inventariable compartido. Se crea sin existencias ni asociación automática. Después puedes asociarla a un almacén propio.</p>
<form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid"><?php $campos('crear_articulo'); ?>
    <div class="admin-field"><label class="admin-field__label" for="nuevo-articulo-nombre">Nombre</label><input id="nuevo-articulo-nombre" name="nombre" maxlength="120" required></div>
    <div class="admin-field"><label class="admin-field__label" for="nuevo-articulo-unidad">Unidad</label><select id="nuevo-articulo-unidad" name="unidad"><?php foreach(['g','kg','ml','l','pza'] as $medida): ?><option value="<?php echo $medida; ?>"><?php echo $medida; ?></option><?php endforeach; ?></select></div>
    <div class="admin-field"><label class="admin-field__label" for="nuevo-articulo-costo">Costo unitario</label><input id="nuevo-articulo-costo" name="costo" type="number" min="0" step="0.0001" value="0.0000" inputmode="decimal" required></div>
    <div class="admin-field"><label><input name="activo" type="checkbox" value="1" checked> Artículo activo</label></div>
    <div class="admin-form-grid__actions"><button type="submit" class="admin-btn admin-btn--primary">Crear artículo</button></div>
</form>