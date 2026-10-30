<?php $filas=$d['componentes']??[];if(!$filas)$filas=[['articulo_id'=>0,'cantidad'=>'']]; ?>
<div data-casa-receta>
<div class="casa-componentes" data-casa-filas>
<?php foreach($filas as $i=>$fila): ?><div class="casa-componente <?= !empty($versionesArticulosCasa)?'casa-componente--versionada':'' ?>" data-casa-fila>
<label class="admin-field">Ingrediente y unidad<select name="componentes[<?= $i ?>][articulo_id]"><option value="0">Elegir ingrediente</option><?php foreach($ingredientesCasa as $ing): ?><option value="<?= (int)$ing['id'] ?>" <?= (int)($fila['articulo_id']??0)===(int)$ing['id']?'selected':'' ?>><?= $esc($ing['nombre'].' · '.$ing['unidad']) ?></option><?php endforeach; ?></select></label>
<label class="admin-field">Cantidad<input name="componentes[<?= $i ?>][cantidad]" type="number" min="0.001" step="0.001" value="<?= $esc($fila['cantidad']??'') ?>"></label>
<?php if(!empty($versionesArticulosCasa)): ?><label class="admin-field">Modalidad de consumo<select name="componentes[<?= $i ?>][elaboracion]"><option value="0">Desde existencias del artículo</option><?php foreach($versionesArticulosCasa as $v): ?><option value="<?= (int)$v['id'] ?>" <?= (int)($fila['version_receta_id']??0)===(int)$v['id']?'selected':'' ?>><?= $esc('Preparar '.$v['nombre'].' · v'.$v['version']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
<?php if($puedeCasa): ?><button type="button" class="admin-btn admin-btn--secondary" data-casa-quitar aria-label="Quitar ingrediente"><?= admin_icon('basura') ?></button><?php endif; ?>
</div><?php endforeach; ?></div>
<?php if($puedeCasa): ?><p><button type="button" class="admin-btn admin-btn--secondary" data-casa-agregar>Agregar ingrediente</button></p><?php endif; ?>
</div>
