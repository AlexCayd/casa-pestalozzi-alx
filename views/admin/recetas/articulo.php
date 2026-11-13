<?php
/**
 * Ficha única del artículo (arquitectura §18). Reúne lo que antes eran tres
 * pantallas: datos generales (el escritor sigue siendo el catálogo de Almacenes),
 * «¿Cómo se obtiene?» en lugar de las siete casillas de capacidades, la receta de
 * elaboración sólo si es una preparación, y «Dónde se usa».
 */
$esc=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');
$c=$configuracion;$id=(int)$articulo['id'];$global=$ctx==='global';
$ctxNombre=\Services\Security\Contextos::NOMBRES[$ctx]??'Restaurante';
$preparacion=!empty($c['elaborable']);$versionCasa=$versionCasa??null;$identidad=$identidad??$articulo;
$activoCasa=!empty($articulo['activo']);$activoAqui=!empty($estadoEfectivo['efectivo']['activo']);
// DECIMAL exacto: se muestra sin ceros de relleno (750.000 → 750) y viaja tal cual.
$pieza=(string)($identidad['contenido_pieza']??'');if(str_contains($pieza,'.'))$pieza=rtrim(rtrim($pieza,'0'),'.');
$fichaUrl=$baseArticulos.'/editar?'.http_build_query(array_filter(['id'=>$id,'volver'=>$volverContextual]));
// El escritor de identidad vuelve a esta ficha conservando el origen, no a sí misma.
$identidadAccion=$baseCatalogo.'/editar?'.http_build_query(array_filter(['id'=>$id,'volver'=>$volverContextual]));
$errorClasificar=!empty($alertas['error'])&&($_POST['accion']??'')==='clasificar';
$avanzadasAbiertas=$errorClasificar||empty($c['componente'])||empty($c['almacenable']);
// Tras un error se conserva lo marcado; la receta sólo depende de lo que está guardado.
$tipoMarcado=($tipoCapturado??null)?:($preparacion?'preparacion':'insumo');
$tipoTexto=$preparacion?'Se prepara en casa':($versionCasa?'Preparación de la casa':'Se compra');
?>
<section class="admin-recetas admin-page m2-articulo m6-ficha">
<header class="admin-page__header"><div><h1 class="admin-page__title"><?= $esc($articulo['nombre']) ?></h1>
<p class="admin-page__subtitle m6-ficha__resumen"><span><?= $esc($articulo['unidad']) ?><?= $pieza!==''?' · pieza de '.$esc($pieza.' '.$articulo['unidad']):'' ?></span> <span><?= $tipoTexto ?></span> <span class="admin-status m6-estado<?= $activoAqui?' m6-estado--activo':' admin-status--neutral m6-estado--inactivo' ?>"><?= $activoAqui?'Activo':($activoCasa?'Inactivo en '.$esc($ctxNombre):'Inactivo') ?></span></p></div>
<div class="admin-actions"><a class="admin-btn admin-btn--secondary" href="<?= $esc($volverContextual?:$baseArticulos) ?>"><?= $volverContextual?'Volver':'Volver a la lista' ?></a></div></header>
<?php include __DIR__.'/../partials/alertas.php'; ?><?php if(!empty($mensajeExito)): ?><div class="admin-menu__flash admin-menu__flash--exito" role="status"><?= $esc($mensajeExito) ?></div><?php endif; ?>
<?php if(!$puedeEditar): ?><p class="admin-field__hint">Solo consulta. La edición requiere permiso de Artículos y preparaciones y las facultades correspondientes de Global.</p><?php endif; ?>
<?php if(in_array($estadoEfectivo['estado'],['incompleta','incompatible'],true)): ?><p class="admin-alert m6-ficha__aviso" role="status"><?= $esc($estadoEfectivo['detalle']) ?><?php if($preparacion&&!$versiones&&$puedeEditar): ?> <a href="#elaboracion">Ir a la receta</a><?php endif; ?></p><?php endif; ?>

<section class="admin-panel admin-card m6-ficha__datos" id="identidad" aria-labelledby="m6-datos-titulo"><h2 id="m6-datos-titulo">Datos generales</h2>
<?php if($puedeIdentidad): ?>
<form method="POST" action="<?= $esc($identidadAccion) ?>" data-m6-descarta-borrador="Guardar los datos generales"><input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>">
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
<dl class="m2-configuracion__lista m6-ficha__lectura"><div><dt>Unidad de medida</dt><dd><?= $esc($articulo['unidad']) ?></dd></div><div><dt>Contenido por pieza</dt><dd><?= $pieza!==''?$esc($pieza.' '.$articulo['unidad']):'No se maneja por pieza' ?></dd></div><div><dt>En toda la casa</dt><dd><?= $activoCasa?'Disponible':'Inactivo' ?></dd></div></dl>
<p class="admin-field__hint">Nombre, unidad y costo son comunes a los negocios: se editan desde Global con la facultad de catálogo.</p>
<?php endif; ?>
</section>

<section class="admin-panel admin-card m6-clasificacion" id="capacidades" aria-labelledby="m6-clasificacion-titulo"><h2 id="m6-clasificacion-titulo">Uso en <?= $esc($ctxNombre) ?></h2>
<?php if($requiereConfiguracionFisica): ?><p class="admin-field__hint">Este artículo usa la configuración general de la casa. Guardar una propia para <?= $esc($ctxNombre) ?> requiere edición de Almacenes en este negocio; coordina ese paso con una persona autorizada.</p><?php endif; ?>
<?php if(!$preparacion&&$versionCasa): ?><p class="admin-field__hint">Global publica la receta de esta preparación (<a href="<?= $esc($baseArticulos.'/editar?'.http_build_query(array_filter(['id'=>$id,'version'=>$versionCasa['id'],'volver'=>$volverContextual]))) ?>">versión <?= (int)$versionCasa['version'] ?></a>). Elige «Se prepara en casa» sólo si <?= $esc($ctxNombre) ?> tendrá su propia receta.</p><?php endif; ?>
<form method="POST" data-m6-descarta-borrador="Guardar el uso"><input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>"><input type="hidden" name="accion" value="clasificar"><input type="hidden" name="revision" value="<?= (int)$c['revision'] ?>"><input type="hidden" name="avanzadas" value="1">
<fieldset class="m2-fieldset" <?= $puedeConfigurar?'':'disabled' ?>>
<fieldset class="admin-pills m6-tipo"><legend>¿Cómo se obtiene?</legend><div class="m6-tipo__opciones">
<label class="admin-pill m6-tipo__opcion"><input type="radio" name="tipo" value="insumo" <?= $tipoMarcado==='insumo'?'checked':'' ?>><span class="admin-pill__body"><strong class="admin-pill__title">Se compra</strong><small class="admin-pill__hint">Insumo o producto de reventa. Entra al almacén y se descuenta directo, sin receta propia.</small></span></label>
<label class="admin-pill m6-tipo__opcion"><input type="radio" name="tipo" value="preparacion" <?= $tipoMarcado==='preparacion'?'checked':'' ?>><span class="admin-pill__body"><strong class="admin-pill__title">Se prepara en casa</strong><small class="admin-pill__hint">Tiene receta propia: una salsa, una masa, una base. Su receta se captura aquí abajo.</small></span></label>
</div></fieldset>
<?php if($preparacion&&$versiones): ?><p class="admin-field__hint">Si lo cambias a «Se compra», sus versiones publicadas se conservan y las recetas que ya las usan no cambian.</p><?php endif; ?>
<label class="m2-capacidad"><input type="checkbox" name="activo" value="1" <?= !empty($c['activo'])?'checked':'' ?>><span>Activo en <?= $esc($ctxNombre) ?> <small>Apagado, no se puede elegir en recetas ni consumir en este negocio.</small></span></label>
<details class="m6-avanzadas" <?= $avanzadasAbiertas?'open':'' ?>><summary>Opciones avanzadas</summary>
<p class="admin-field__hint">Casi nunca hace falta tocarlas: un artículo nuevo se puede usar en recetas y lleva existencias.</p>
<label class="m2-capacidad"><input type="checkbox" name="componente" value="1" <?= !empty($c['componente'])?'checked':'' ?>><span>Se puede usar en recetas <small>Apágalo para que no pueda elegirse como ingrediente ni venderse como reventa.</small></span></label>
<label class="m2-capacidad"><input type="checkbox" name="almacenable" value="1" <?= !empty($c['almacenable'])?'checked':'' ?> <?= $puedeInventario?'':'disabled' ?>><span>Lleva existencias en almacén <small>Apágalo para lo que se usa sin contarse: las recetas no descontarán nada.<?= $puedeInventario?'':' Requiere edición de Almacenes.' ?></small></span></label>
<label class="m2-capacidad"><input type="checkbox" name="costo_conocido" value="1" <?= !empty($c['costo_conocido'])?'checked':'' ?> <?= $puedeInventario?'':'disabled' ?>><span>Su costo es conocido <small>Un costo de cero conocido es distinto de un costo sin capturar.<?= $puedeInventario?'':' Requiere edición de Almacenes.' ?></small></span></label>
</details>
<?php if($puedeConfigurar): ?><div class="admin-actions"><button class="admin-btn admin-btn--secondary">Guardar uso</button></div><?php endif; ?>
</fieldset></form></section>

<?php if($preparacion): ?>
<section class="admin-panel admin-card" id="elaboracion" aria-labelledby="m6-elaboracion-titulo"><h2 id="m6-elaboracion-titulo">Receta de elaboración</h2><p>Publicar crea una versión que ya no cambia. Las recetas que la usan conservan la versión que eligieron.</p>
<form method="POST" <?= !empty($borradorPendiente)?'data-m2-pendiente':'' ?>><p class="admin-field__hint" role="status" data-m2-draft-status><?= !empty($borradorPendiente)?'Cambios pendientes de publicar. La revisión no guarda la receta.':($versiones?'Base de edición: versión '.(int)$versiones[0]['version'].' publicada. Publicar guardará una versión nueva.':'Borrador de elaboración sin publicar.') ?></p><input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>"><input type="hidden" name="revision" value="<?= (int)$c['revision'] ?>"><input type="hidden" name="clave" value="<?= bin2hex(random_bytes(24)) ?>"><input type="hidden" name="huella" value="<?= $esc($preview['huella']??'') ?>">
<fieldset class="m2-fieldset" <?= $puedeEditar?'':'disabled' ?>><div class="admin-form-grid"><label class="admin-field">Rendimiento completo<input name="rendimiento" type="number" min="0.001" step="0.001" required value="<?= $esc($raw['rendimiento']) ?>"></label><label class="admin-field">Unidad del rendimiento<select name="unidad"><?php foreach(['g','kg','ml','l','pza'] as $u): ?><option <?= ($raw['unidad']??'')===$u?'selected':'' ?>><?= $u ?></option><?php endforeach; ?></select></label><label class="admin-field admin-form-grid__full">Procedencia e instrucciones breves<input name="procedencia" maxlength="160" required value="<?= $esc($raw['procedencia']??'Elaboración propia') ?>"></label></div>
<?php include __DIR__.'/_articulo-componentes.php'; ?>
<?php if($puedeEditar): ?><div class="admin-actions"><button class="admin-btn admin-btn--secondary" name="accion" value="prever">Revisar consumo previsto</button><?php if($preview): ?><label><input type="checkbox" name="confirmar" value="1"> Revisé composición y rendimiento</label><button class="admin-btn admin-btn--primary" name="accion" value="publicar">Publicar nueva versión</button><?php endif; ?></div><?php endif; ?></fieldset></form></section>
<?php include __DIR__.'/_articulo-prevision.php'; ?>
<?php endif; ?>

<?php include __DIR__.'/_articulo-donde.php'; ?>

<?php if($preparacion||$versiones): ?><details class="admin-panel admin-card m2-disclosure" id="versiones"><summary>Versiones publicadas <span class="admin-field__hint">· <?= count($versiones) ?></span></summary><?php if(!$versiones): ?><p>Todavía no hay recetas publicadas para este artículo en <?= $esc($ctxNombre) ?>.</p><?php else: ?><ul><?php foreach($versiones as $v): ?><li><a href="<?= $esc($baseArticulos.'/editar?'.http_build_query(array_filter(['id'=>$id,'version'=>$v['id'],'volver'=>$volverContextual]))) ?>">Consultar versión <?= (int)$v['version'] ?></a> · <?= $esc($v['rendimiento'].' '.$v['unidad'].' · '.$v['procedencia'].' · '.$v['created_at']) ?></li><?php endforeach; ?></ul><?php endif; ?></details><?php endif; ?>
</section>
