<?php
$esc=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');$id=(int)($identidad['id']??($_GET['id']??0));$puedeGuardar=$nuevo?$puedeCrearArticulo:$puedeIdentidad;
$self=$baseCatalogo.($nuevo?'/nuevo':'/editar?id='.$id);$retorno=$self.($volverContextual?($nuevo?'?':'&').http_build_query(['volver'=>$volverContextual]):'');
?>
<section class="admin-recetas admin-page m2-articulo">
<header class="admin-page__header"><div><h1 class="admin-page__title"><?= $nuevo?'Nuevo artículo':$esc($identidad['nombre']??'Identidad del artículo') ?></h1><p class="admin-page__subtitle">Identidad compartida · <?= $nuevo?'Sin existencias ni asociaciones automáticas.':'Nombre, unidad y estado comunes a los negocios.' ?></p></div><a class="admin-btn admin-btn--secondary" href="<?= $esc($volverContextual?:$baseCatalogo) ?>">Volver al origen</a></header>
<?php include __DIR__.'/../partials/alertas.php'; ?>
<?php if($mensajeExito): ?><p class="admin-alert admin-alert--success" role="status"><?= $esc($mensajeExito) ?></p><?php endif; ?>
<?php if(!$puedeGuardar): ?><p class="admin-field__hint">Identidad en consulta. Editarla requiere el ámbito Global y la facultad de catálogo; la configuración local se administra por separado.</p><?php endif; ?>
<section class="admin-panel admin-card" id="identidad"><h2>Información general</h2>
<form method="POST" id="articulo-identidad"><input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>">
<fieldset class="m2-fieldset" <?= $puedeGuardar?'':'disabled' ?>><div class="admin-form-grid">
<label class="admin-field">Nombre<input name="nombre" maxlength="120" required value="<?= $esc($identidad['nombre']??'') ?>" autocomplete="off"></label>
<label class="admin-field">Unidad<select name="unidad"><?php foreach(['g','kg','ml','l','pza'] as $u): ?><option <?= ($identidad['unidad']??'g')===$u?'selected':'' ?>><?= $u ?></option><?php endforeach; ?></select><span class="admin-field__hint">No cambia si tiene movimientos o composiciones referenciadas.</span></label>
<?php if($ctx==='global'||$nuevo): ?><label class="admin-field">Costo maestro por unidad<input name="costo" type="number" min="0" step="0.0001" inputmode="decimal" required value="<?= $esc($identidad['costo']??'0.0000') ?>"><span class="admin-field__hint">La capacidad «Costo de referencia conocido» distingue el cero real del costo desconocido.</span></label><?php endif; ?>
<label class="m2-capacidad"><input name="activo" type="checkbox" value="1" <?= !empty($identidad['activo'])?'checked':'' ?>> Artículo activo</label>
</div><?php if($puedeGuardar): ?><div class="admin-actions"><button class="admin-btn admin-btn--primary"><?= $nuevo?'Crear artículo':'Guardar identidad' ?></button></div><?php endif; ?></fieldset></form></section>
<?php if(!$nuevo): ?><section class="admin-panel admin-card"><h2>Continuar con este artículo</h2><div class="admin-actions"><?php if($puedeProductos): ?><a class="admin-btn admin-btn--primary" href="<?= $esc($baseArticulos.'/editar?'.http_build_query(['id'=>$id,'volver'=>$retorno])) ?>">Capacidades y elaboración</a><?php endif; ?><a class="admin-btn admin-btn--secondary" href="<?= $esc($baseAlmacenes.'?'.http_build_query(['articulo_id'=>$id,'volver'=>$retorno])) ?>">Asociaciones y existencias</a></div><p class="admin-field__hint">Guardar identidad no modifica capacidades, versiones, saldos ni movimientos.</p></section><?php include __DIR__.'/_articulo-usos.php'; ?><?php endif; ?>
</section>
