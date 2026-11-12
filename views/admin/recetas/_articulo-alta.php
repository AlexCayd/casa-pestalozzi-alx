<?php
/**
 * Alta en un paso (arquitectura §18): datos, «¿Cómo se obtiene?» y almacenes en un
 * solo formulario. Lo comparten el diálogo de la lista y la página de respaldo, que
 * atiende a quien llega sin JavaScript o vuelve de un error de validación.
 *
 * Espera $esc, $csrf, $baseCatalogo, $altaVolver, $altaPrefijo, $puedePrepararAlta y
 * $almacenesAlta; $identidad y $almacenesMarcados traen lo capturado cuando hubo error.
 */
$altaDatos=$identidad??[];
$altaTipo=($altaDatos['tipo']??'insumo')==='preparacion'&&$puedePrepararAlta?'preparacion':'insumo';
$altaAccion=$baseCatalogo.'/nuevo'.($altaVolver?'?'.http_build_query(['volver'=>$altaVolver]):'');
// DECIMAL exacto: se muestra sin ceros de relleno (750.000 → 750) y viaja tal cual.
$altaPieza=(string)($altaDatos['contenido_pieza']??'');if(str_contains($altaPieza,'.'))$altaPieza=rtrim(rtrim($altaPieza,'0'),'.');
$altaCosto=(string)($altaDatos['costo']??'');
$altaPorNegocio=[];foreach($almacenesAlta??[] as $a)$altaPorNegocio[$a['slug']][]=$a;
?>
<form method="POST" action="<?= $esc($altaAccion) ?>" class="m6-alta" data-m6-alta>
<input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>">
<?php /* El cuerpo es lo que se desplaza en el diálogo; los botones quedan fijos debajo. */ ?>
<div class="m6-alta__cuerpo">
<div class="admin-form-grid m6-alta__datos">
<label class="admin-field admin-form-grid__full">Nombre<input name="nombre" maxlength="120" required autofocus autocomplete="off" value="<?= $esc($altaDatos['nombre']??'') ?>" placeholder="Harina de trigo"></label>
<label class="admin-field">Unidad de medida<select name="unidad"><?php foreach(['g'=>'Gramos (g)','kg'=>'Kilogramos (kg)','ml'=>'Mililitros (ml)','l'=>'Litros (l)','pza'=>'Piezas (pza)'] as $u=>$label): ?><option value="<?= $u ?>" <?= ($altaDatos['unidad']??'g')===$u?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select><span class="admin-field__hint">En esta unidad se escriben las recetas y se cuentan las existencias.</span></label>
<label class="admin-field">Costo por unidad (opcional)<input name="costo" type="number" min="0" step="0.0001" inputmode="decimal" value="<?= $esc($altaCosto) ?>" placeholder="0.0000" aria-describedby="<?= $esc($altaPrefijo) ?>-costo-ayuda"><span class="admin-field__hint" id="<?= $esc($altaPrefijo) ?>-costo-ayuda">Déjalo vacío si aún no lo conoces.</span></label>
</div>
<fieldset class="admin-pills m6-tipo"><legend>¿Cómo se obtiene?</legend><div class="m6-tipo__opciones">
<label class="admin-pill m6-tipo__opcion"><input type="radio" name="tipo" value="insumo" data-m6-tipo <?= $altaTipo==='insumo'?'checked':'' ?>><span class="admin-pill__body"><strong class="admin-pill__title">Se compra</strong><small class="admin-pill__hint">Insumo o producto de reventa. Entra al almacén y se descuenta directo, sin receta propia.</small></span></label>
<label class="admin-pill m6-tipo__opcion"><input type="radio" name="tipo" value="preparacion" data-m6-tipo <?= $altaTipo==='preparacion'?'checked':'' ?> <?= $puedePrepararAlta?'':'disabled' ?>><span class="admin-pill__body"><strong class="admin-pill__title">Se prepara en casa</strong><small class="admin-pill__hint"><?= $puedePrepararAlta?'Tiene receta propia: una salsa, una masa, una base. Al crearlo se abre su receta.':'Requiere edición de Artículos y preparaciones en este negocio.' ?></small></span></label>
</div></fieldset>
<?php $piezaValor=$altaPieza;$piezaUnidad=(string)($altaDatos['unidad']??'g');$piezaPrefijo=$altaPrefijo;include __DIR__.'/_articulo-pieza.php'; ?>
<?php if($altaPorNegocio): ?>
<fieldset class="m2-fieldset m2-alta-almacenes"><legend>¿En qué almacenes se guarda? (opcional)</legend>
<p class="admin-field__hint">Queda asociado a cada almacén marcado, sin existencias. También puedes asociarlo después.</p>
<?php foreach($altaPorNegocio as $slug=>$lista): ?><div class="m2-alta-almacenes__grupo" role="group" aria-label="<?= $esc(\Services\Security\Contextos::NOMBRES[$slug]??$slug) ?>"><p class="m2-alta-almacenes__negocio"><?= $esc(\Services\Security\Contextos::NOMBRES[$slug]??$slug) ?></p>
<?php foreach($lista as $a): ?><label class="m2-capacidad"><input type="checkbox" name="almacen_ids[]" value="<?= (int)$a['almacen_id'] ?>" <?= in_array((int)$a['almacen_id'],$almacenesMarcados??[],true)?'checked':'' ?>> <?= $esc($a['nombre']) ?></label><?php endforeach; ?>
</div><?php endforeach; ?>
</fieldset>
<?php endif; ?>
</div>
<div class="admin-actions m6-alta__acciones">
<?php if(!empty($altaEnDialogo)): ?><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close>Cancelar</button><?php else: ?><a class="admin-btn admin-btn--secondary" href="<?= $esc($altaVolver?:$baseCatalogo) ?>">Cancelar</a><?php endif; ?>
<button class="admin-btn admin-btn--primary" data-m6-alta-enviar data-etiqueta-insumo="Crear artículo" data-etiqueta-preparacion="Crear y abrir su receta"><?= $altaTipo==='preparacion'?'Crear y abrir su receta':'Crear artículo' ?></button>
</div>
</form>
