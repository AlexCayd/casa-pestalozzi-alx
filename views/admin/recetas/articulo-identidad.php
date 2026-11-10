<?php
$esc=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');$id=(int)($identidad['id']??($_GET['id']??0));$puedeGuardar=$nuevo?$puedeCrearArticulo:$puedeIdentidad;
$self=$baseCatalogo.($nuevo?'/nuevo':'/editar?id='.$id);$retorno=$self.($volverContextual?($nuevo?'?':'&').http_build_query(['volver'=>$volverContextual]):'');
?>
<section class="admin-recetas admin-page m2-articulo">
<header class="admin-page__header"><div><h1 class="admin-page__title"><?= $nuevo?'Nuevo artículo':$esc($identidad['nombre']??'Identidad del artículo') ?></h1><p class="admin-page__subtitle">Identidad compartida · <?= $nuevo?'Empieza sin existencias; elige abajo los almacenes que lo manejarán.':'Nombre, unidad y estado comunes a los negocios.' ?></p></div><a class="admin-btn admin-btn--secondary" href="<?= $esc($volverContextual?:$baseCatalogo) ?>">Volver al origen</a></header>
<?php include __DIR__.'/../partials/alertas.php'; ?>
<?php if($mensajeExito): ?><p class="admin-alert admin-alert--success" role="status"><?= $esc($mensajeExito) ?></p><?php endif; ?>
<?php if(!$puedeGuardar): ?><p class="admin-field__hint">Identidad en consulta. Editarla requiere el ámbito Global y la facultad de catálogo; la configuración local se administra por separado.</p><?php endif; ?>
<section class="admin-panel admin-card" id="identidad"><h2>Información general</h2>
<form method="POST" id="articulo-identidad"><input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>">
<fieldset class="m2-fieldset" <?= $puedeGuardar?'':'disabled' ?>><div class="admin-form-grid">
<label class="admin-field">Nombre<input name="nombre" maxlength="120" required value="<?= $esc($identidad['nombre']??'') ?>" autocomplete="off"></label>
<label class="admin-field">Unidad<select name="unidad"><?php foreach(['g','kg','ml','l','pza'] as $u): ?><option <?= ($identidad['unidad']??'g')===$u?'selected':'' ?>><?= $u ?></option><?php endforeach; ?></select><span class="admin-field__hint">No cambia si tiene movimientos o composiciones referenciadas.</span></label>
<?php
// Contenido por pieza (arquitectura §16). DECIMAL exacto: se muestra sin los
// ceros de relleno (750.000 → 750) y viaja tal cual; vacío = sin pieza.
$contenidoPieza=(string)($identidad['contenido_pieza']??'');
if(str_contains($contenidoPieza,'.'))$contenidoPieza=rtrim(rtrim($contenidoPieza,'0'),'.');
?>
<label class="admin-field">Contenido por pieza (opcional)<input name="contenido_pieza" type="number" min="0.001" max="999999999.999" step="0.001" inputmode="decimal" value="<?= $esc($contenidoPieza) ?>" placeholder="750" aria-describedby="contenido-pieza-ayuda"><span class="admin-field__hint" id="contenido-pieza-ayuda">Cuánto contiene una pieza comercial, en la unidad del artículo: 750 si es vino en ml y se compra por botella. Sólo para g, kg, ml o l; déjalo vacío si no se maneja por pieza.</span></label>
<?php if($ctx==='global'||$nuevo): ?><label class="admin-field">Costo maestro por unidad<input name="costo" type="number" min="0" step="0.0001" inputmode="decimal" required value="<?= $esc($identidad['costo']??'0.0000') ?>"><span class="admin-field__hint">La capacidad «Costo de referencia conocido» distingue el cero real del costo desconocido.</span></label><?php endif; ?>
<label class="m2-capacidad"><input name="activo" type="checkbox" value="1" <?= !empty($identidad['activo'])?'checked':'' ?>> Artículo activo</label>
</div>
<?php // Alta y asociación en un solo paso: casillas por negocio con los almacenes donde el
// usuario edita. Es opcional; cada asociación empieza sin saldo.
if($nuevo&&!empty($almacenesAlta)): $porNegocioAlta=[];foreach($almacenesAlta as $a)$porNegocioAlta[$a['slug']][]=$a; ?>
<fieldset class="m2-fieldset m2-alta-almacenes"><legend>Almacenes que lo manejarán (opcional)</legend>
<p class="admin-field__hint">Marca todos los que apliquen. El artículo quedará asociado a cada uno, sin existencias; también puedes asociarlo después desde Almacenes.</p>
<?php foreach($porNegocioAlta as $slug=>$lista): ?><div class="m2-alta-almacenes__grupo" role="group" aria-label="<?= $esc(\Services\Security\Contextos::NOMBRES[$slug]??$slug) ?>"><p class="m2-alta-almacenes__negocio"><?= $esc(\Services\Security\Contextos::NOMBRES[$slug]??$slug) ?></p>
<?php foreach($lista as $a): ?><label class="m2-capacidad"><input type="checkbox" name="almacen_ids[]" value="<?= (int)$a['almacen_id'] ?>" <?= in_array((int)$a['almacen_id'],$almacenesMarcados??[],true)?'checked':'' ?>> <?= $esc($a['nombre']) ?></label><?php endforeach; ?>
</div><?php endforeach; ?>
</fieldset>
<?php endif; ?>
<?php if($puedeGuardar): ?><div class="admin-actions"><button class="admin-btn admin-btn--primary"><?= $nuevo?'Crear artículo':'Guardar identidad' ?></button></div><?php endif; ?></fieldset></form></section>
<?php if(!$nuevo): ?><p class="admin-field__hint">Guardar identidad no modifica capacidades, versiones, asociaciones, saldos ni movimientos.</p><?php include __DIR__.'/_articulo-configuracion.php'; include __DIR__.'/_articulo-usos.php'; ?><?php endif; ?>
</section>
