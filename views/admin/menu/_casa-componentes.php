<?php $filas=$d['componentes']??[];if(!$filas)$filas=[['articulo_id'=>0,'cantidad'=>'']]; ?>
<div data-casa-receta>
<div class="casa-componentes" data-casa-filas>
<?php foreach($filas as $i=>$fila): ?><div class="casa-componente" data-casa-fila>
<label class="admin-field">Ingrediente y unidad<select name="componentes[<?= $i ?>][articulo_id]"><option value="0">Elegir ingrediente</option><?php foreach($ingredientesCasa as $ing): ?><option value="<?= (int)$ing['id'] ?>" <?= (int)($fila['articulo_id']??0)===(int)$ing['id']?'selected':'' ?>><?= $esc($ing['nombre'].' · '.$ing['unidad']) ?></option><?php endforeach; ?></select></label>
<label class="admin-field">Cantidad<input name="componentes[<?= $i ?>][cantidad]" type="number" min="0.001" step="0.001" value="<?= $esc($fila['cantidad']??'') ?>"></label>
<?php if($puedeCasa): ?><button type="button" class="admin-btn admin-btn--secondary" data-casa-quitar aria-label="Quitar ingrediente"><?= admin_icon('basura') ?></button><?php endif; ?>
</div><?php endforeach; ?></div>
<?php if($puedeCasa): ?><p><button type="button" class="admin-btn admin-btn--secondary" data-casa-agregar>Agregar ingrediente</button></p><?php endif; ?>
</div>
