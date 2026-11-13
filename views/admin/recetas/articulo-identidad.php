<?php
/**
 * Página de respaldo del catálogo (arquitectura §18). Con `$nuevo` es el alta en un
 * paso fuera del diálogo: sin JavaScript o al volver de un error de validación. Sin
 * `$nuevo` es la ficha de quien sólo tiene Almacenes: datos generales y almacenes,
 * sin lectura de recetas. Quien ve Artículos y preparaciones usa la ficha única.
 */
$esc=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');$id=(int)($identidad['id']??($_GET['id']??0));
$pieza=(string)($identidad['contenido_pieza']??'');if(str_contains($pieza,'.'))$pieza=rtrim(rtrim($pieza,'0'),'.');
$origen=$volverContextual?:($puedeProductos?$baseArticulos:$baseCatalogo);
$accion=$baseCatalogo.'/editar?'.http_build_query(array_filter(['id'=>$id,'volver'=>$volverContextual]));
?>
<section class="admin-recetas admin-page m2-articulo m6-ficha">
<header class="admin-page__header"><div><h1 class="admin-page__title"><?= $nuevo?'Nuevo artículo':$esc($identidad['nombre']??'Artículo') ?></h1><p class="admin-page__subtitle"><?= $nuevo?'Un solo paso: al crearlo ya se puede usar en recetas.':'Datos comunes a los negocios y almacenes que lo manejan.' ?></p></div><div class="admin-actions"><a class="admin-btn admin-btn--secondary" href="<?= $esc($origen) ?>"><?= $volverContextual?'Volver':'Volver a la lista' ?></a></div></header>
<?php include __DIR__.'/../partials/alertas.php'; ?>
<?php if(!empty($mensajeExito)): ?><div class="admin-menu__flash admin-menu__flash--exito" role="status"><?= $esc($mensajeExito) ?></div><?php endif; ?>
<?php if($nuevo): ?>
<section class="admin-panel admin-card m6-alta-pagina" id="identidad" aria-label="Datos del artículo nuevo"><?php $altaVolver=$volverContextual;$altaPrefijo='alta-pagina';$altaEnDialogo=false;include __DIR__.'/_articulo-alta.php'; ?></section>
<?php else: ?>
<section class="admin-panel admin-card m6-ficha__datos" id="identidad" aria-labelledby="m6-datos-titulo"><h2 id="m6-datos-titulo">Datos generales</h2>
<?php if($puedeIdentidad): ?>
<form method="POST" action="<?= $esc($accion) ?>" id="articulo-identidad"><input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>">
<div class="admin-form-grid">
<label class="admin-field">Nombre<input name="nombre" maxlength="120" required autocomplete="off" value="<?= $esc($identidad['nombre']??'') ?>"></label>
<label class="admin-field">Unidad de medida<select name="unidad"><?php foreach(['g','kg','ml','l','pza'] as $u): ?><option <?= ($identidad['unidad']??'g')===$u?'selected':'' ?>><?= $u ?></option><?php endforeach; ?></select><span class="admin-field__hint">No cambia si ya tiene movimientos o recetas que lo usan.</span></label>
<label class="admin-field">Costo por unidad<input name="costo" type="number" min="0" step="0.0001" inputmode="decimal" required value="<?= $esc($identidad['costo']??'0.0000') ?>"></label>
<?php $piezaValor=$pieza;$piezaUnidad=(string)($identidad['unidad']??'g');$piezaPrefijo='datos';include __DIR__.'/_articulo-pieza.php'; ?>
</div>
<label class="m2-capacidad"><input name="activo" type="checkbox" value="1" <?= !empty($identidad['activo'])?'checked':'' ?>><span>Disponible en toda la casa <small>Apagado, deja de ofrecerse en Restaurante, Panadería y Global.</small></span></label>
<div class="admin-actions"><button class="admin-btn admin-btn--secondary">Guardar datos generales</button></div>
</form>
<?php else: ?>
<dl class="m2-configuracion__lista m6-ficha__lectura"><div><dt>Unidad de medida</dt><dd><?= $esc($identidad['unidad']??'') ?></dd></div><div><dt>Contenido por pieza</dt><dd><?= $pieza!==''?$esc($pieza.' '.($identidad['unidad']??'')):'No se maneja por pieza' ?></dd></div><div><dt>En toda la casa</dt><dd><?= !empty($identidad['activo'])?'Disponible':'Inactivo' ?></dd></div></dl>
<p class="admin-field__hint">Nombre, unidad y costo son comunes a los negocios: se editan desde Global con la facultad de catálogo.</p>
<?php endif; ?>
</section>
<?php include __DIR__.'/_articulo-donde.php'; ?>
<?php endif; ?>
</section>
