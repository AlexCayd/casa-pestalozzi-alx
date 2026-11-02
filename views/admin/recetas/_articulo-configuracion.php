<?php
$estadoM2=$estadoM2??$estadoEfectivo??null;$configM2=$estadoM2['configuracion']??[];
$ctxNombre=match($ctx){'global'=>'Global','panaderia'=>'Panadería',default=>'Restaurante'};
$revisionM2=(int)($configM2['revision']??0);
$estadoCapacidadesM2=!$puedeProductos?'Requiere permiso de Artículos y preparaciones':($revisionM2>0?'Guardadas en '.$ctxNombre:match($estadoM2['origen']??'heredada'){'compartida'=>'Heredadas de la configuración Global','heredada'=>'Efectivas por compatibilidad; aún no guardadas aquí',default=>'Pendientes de configurar'});
$versionesM2=$versionesM2??$versiones??[];
$estadoFisicoM2=(string)($estadoM2['fisico']??'');
$asociacionConfiguradaM2=str_starts_with($estadoFisicoM2,'Con asociación');
$saldoRegistradoM2=str_starts_with($estadoFisicoM2,'Con asociación y existencias registradas');
$baseM2=$baseArticulos.'/editar?'.http_build_query(array_filter(['id'=>$id,'volver'=>$volverContextual]));
$identidadM2=$baseCatalogo.'/editar?'.http_build_query(array_filter(['id'=>$id,'volver'=>$volverContextual]));
$retornoM2=$puedeProductos?$baseM2.'#capacidades':$identidadM2;
$retornoM2=Services\Security\RetornoAdministrativo::autorizado($retornoM2,$ctx)??($puedeProductos?$baseArticulos.'/editar?id='.$id.'#capacidades':$baseCatalogo.'/editar?id='.$id);
$asociarM2=$baseAlmacenes.'?'.http_build_query(['articulo_id'=>$id,'asociar'=>$id,'volver'=>$retornoM2]);
?>
<section class="admin-panel admin-card m2-configuracion" aria-labelledby="m2-configuracion-title">
<h2 id="m2-configuracion-title">Estado de configuración</h2>
<dl class="m2-configuracion__lista">
<div><dt>Identidad</dt><dd>Creada</dd></div>
<div><dt>Capacidades</dt><dd><?= $esc($estadoCapacidadesM2) ?><?php if($puedeConfigurar): ?> · <a href="<?= $esc($baseM2.'#capacidades') ?>">Revisar capacidades</a><?php endif; ?></dd></div>
<div><dt>Elaboración</dt><dd><?php if(!$puedeProductos): ?>Requiere permiso de Artículos y preparaciones<?php elseif(empty($configM2['elaborable'])): ?>No aplica<?php elseif($versionesM2): ?>Configurada · versión <?= (int)$versionesM2[0]['version'] ?><?php elseif(!$puedeEditar): ?>Pendiente · requiere permiso de edición de Artículos y preparaciones<?php else: ?>Pendiente de configurar y publicar<?php endif; ?><?php if(!empty($configM2['elaborable'])&&$puedeEditar): ?> · <a href="<?= $esc($baseM2.'#elaboracion') ?>">Abrir elaboración</a><?php endif; ?></dd></div>
<div><dt>Almacenes</dt><dd><?php if(empty($configM2['almacenable'])): ?>No aplica<?php elseif(!$puedeInventarioVer): ?>Requiere permiso de Almacenes para consultar<?php elseif($asociacionConfiguradaM2): ?>Asociación configurada<?php if($saldoRegistradoM2): ?> · con saldo registrado<?php else: ?> · sin saldo registrado<?php endif; ?><?php else: ?>Pendiente de asociar<?php endif; ?></dd></div>
</dl>
<?php if(!empty($configM2['almacenable'])&&$puedeInventarioEditar): ?><p class="admin-actions"><a class="admin-btn admin-btn--primary" href="<?= $esc($asociarM2) ?>"><?= $asociacionConfiguradaM2?'Revisar asociaciones en Almacenes':'Asociar a un almacén' ?></a></p><?php elseif(!empty($configM2['almacenable'])&&!$puedeInventarioEditar): ?><p class="admin-field__hint">La asociación requiere permiso de edición de Almacenes en <?= $esc($ctxNombre) ?>.</p><?php endif; ?>
</section>
