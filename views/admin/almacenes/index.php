<?php
require_once __DIR__.'/../partials/_icons.php';
use Services\Security\Contextos;
use Services\Inventory\DecimalInventario;
$volverContextual=$volverContextual??null;
$esc=static fn($v): string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$ctx=(string)$contextoAlmacenes;
$opcionesAlmacen=$opcionesAlmacen??[];
$almacenesDisponiblesAsociacion=$almacenesDisponiblesAsociacion??[];
$almacenesAsociadosActuales=$almacenesAsociadosActuales??[];
$articuloContextual=$articuloContextual??null;
$puedeNuevoArticulo=$puedeNuevoArticulo??false;
$propios=array_values(array_filter($opcionesAlmacen,static fn($a)=>(string)$a['slug']===$ctx));
$activos=array_values(array_filter($propios,static fn($a)=>(int)$a['activo']===1));
$almacenesAsociarForm=$articuloContextual?$almacenesDisponiblesAsociacion:$activos;
$puedeAsociarContextual=$puedeEditar&&(!$articuloContextual||(int)$articuloContextual['activo']===1);
$seleccionados=isset($filtros['almacen_ids'])?(array)$filtros['almacen_ids']:(isset($filtros['almacen_id'])?[(int)$filtros['almacen_id']]:array_column(array_filter($opcionesAlmacen,static fn($a)=>empty($filtros['negocio'])||$a['slug']===$filtros['negocio']),'almacen_id'));
$seleccionados=array_map('intval',$seleccionados);
$url=static fn(string $tab,array $cambios=[]): string=>$base.'?'.http_build_query(array_merge($filtros,array_filter(['volver'=>$volverContextual]),['vista'=>$tab],$cambios));
$campos=static function(string $accion,array $extra=[]) use($esc,$csrf,$filtros,$volverContextual): void {
    foreach(array_merge(['admin_csrf'=>$csrf,'volver_contextual'=>$volverContextual??'','accion'=>$accion,'volver_vista'=>'existencias','volver_filtros'=>http_build_query($filtros)],$extra) as $campo=>$valor) echo '<input type="hidden" name="'.$esc($campo).'" value="'.$esc($valor).'">';
};
$seleccionOculta=static function() use($seleccionados,$volverContextual,$esc): void { if($volverContextual)echo '<input type="hidden" name="volver" value="'.$esc($volverContextual).'">'; if(!$seleccionados)return; echo '<input type="hidden" name="seleccion" value="1">'; foreach($seleccionados as $id)echo '<input type="hidden" name="almacen_ids[]" value="'.$id.'">'; };
$modalInicio=static function(string $id,string $titulo,bool $inicial=false) use($esc): void { ?>
<div class="admin-modal almacen-modal" id="<?php echo $esc($id); ?>" data-admin-modal <?= $inicial?'data-contextual-open="1"':'' ?> hidden>
  <div class="admin-modal__backdrop" data-admin-modal-close></div>
  <section class="admin-modal__dialog" data-admin-modal-dialog role="dialog" aria-modal="true" aria-labelledby="<?php echo $esc($id); ?>-title" tabindex="-1">
    <header class="almacen-modal__header"><h3 id="<?php echo $esc($id); ?>-title"><?php echo $esc($titulo); ?></h3><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close aria-label="Cerrar ventana"><?php echo admin_icon('cerrar'); ?></button></header>
<?php };
$modalFin=static function(): void { echo '</section></div>'; };
$origenCatalogo=$url('existencias');$catalogoUrl=$base.'/catalogo?'.http_build_query(['volver'=>$origenCatalogo]);
$articulos=$existencias['articulos']??[];
$transferencias=$transferencias??[];$contrapartes=$contrapartes??[];$catalogoTransferencias=$catalogoTransferencias??[];
?>
<?php if($volverContextual): ?><p><a class="admin-btn admin-btn--secondary" href="<?= $esc($volverContextual) ?>">Volver a la configuración de origen</a></p><?php endif; ?>
<section class="admin-inventario admin-page almacen-workspace" data-almacenes-workspace data-initial-view="<?php echo $esc($vista); ?>">
  <header class="admin-page__header">
    <div class="admin-page__intro"><h2 class="admin-page__title">Almacenes</h2><p class="admin-page__subtitle">Existencias de <?php echo $esc($nombreContexto); ?>. Elige dónde consultar y trabaja artículo por artículo.</p></div>
    <div class="almacen-actions">
      <?php if($puedeNuevoArticulo): ?><a class="admin-btn admin-btn--primary" href="<?= $esc($base.'/catalogo/nuevo?'.http_build_query(['volver'=>$origenCatalogo])) ?>"><?php echo admin_icon('mas'); ?> Nuevo artículo</a><?php endif; ?>
      <?php if($puedeEditar && $activos): ?><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-elegir-entrada"><?php echo admin_icon('mas'); ?> Registrar entrada</button><?php endif; ?>
      <?php if($puedeEditar && $activos&&!$articuloContextual): ?><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-asociar"><?php echo admin_icon('mas'); ?> Asociar artículo</button><?php endif; ?>
      <?php if($puedeCrearAlmacen): ?><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-config"><?php echo admin_icon('inventario'); ?> Crear almacén</button><?php endif; ?>
      <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-config"><?php echo admin_icon('configuration'); ?> <?php echo $puedeEditar?'Configurar almacenes':'Ver almacenes'; ?></button>
      <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-transferencias"><?php echo admin_icon('reloj'); ?> Transferencias</button>
    </div>
  </header>
  <?php include __DIR__.'/../partials/alertas.php'; ?>
  <?php /* Lo urgente va antes que el filtro: una venta salió sin saldo y alguien tiene
     que declarar de dónde. El enlace carga la bandeja (sólo se consulta en esa vista). */
  $faltantesPendientes=(int)($faltantesPendientes??0); if($faltantesPendientes>0): ?>
  <aside class="almacen-faltantes-aviso" aria-labelledby="almacen-faltantes-aviso-titulo">
    <span class="almacen-faltantes-aviso__icono"><?php echo admin_icon('alerta',20); ?></span>
    <div class="almacen-faltantes-aviso__texto">
      <p class="almacen-faltantes-aviso__titulo" id="almacen-faltantes-aviso-titulo"><span class="admin-num"><?php echo $faltantesPendientes; ?></span> <?php echo $faltantesPendientes===1?'faltante por conciliar':'faltantes por conciliar'; ?></p>
      <p>Ventas que salieron sin saldo suficiente en su almacén. <?php echo $puedeEditar?'Declara de qué almacén salió cada una.':'Conciliarlas requiere permiso de edición.'; ?></p>
    </div>
    <a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('faltantes')); ?>"><?php echo $puedeEditar?'Conciliar faltantes':'Ver faltantes'; ?></a>
  </aside>
  <?php endif; ?>
  <?php if($articuloContextual): ?><section class="admin-panel admin-card almacen-contextual" aria-labelledby="almacen-contextual-title"><div><h3 id="almacen-contextual-title">Artículo seleccionado</h3><p><strong><?= $esc($articuloContextual['nombre']) ?></strong> · <?= $esc($articuloContextual['unidad']) ?></p><?php if($almacenesAsociadosActuales): ?><p>Asociado a <?= $esc(implode(', ',$almacenesAsociadosActuales)) ?>.</p><p class="admin-field__hint">Una asociación nueva empieza sin existencias ni movimientos.</p><?php endif; ?><?php if($mensajeAsociacion): ?><p class="admin-field__hint" role="status"><?= $esc($mensajeAsociacion) ?></p><?php endif; ?></div><?php if($puedeAsociarContextual&&$almacenesAsociarForm): ?><button type="button" class="admin-btn admin-btn--primary" data-admin-modal-open="almacen-asociar"><?= $almacenesAsociadosActuales?'Asociar a otro almacén':'Asociar a un almacén' ?></button><?php elseif($puedeAsociarContextual&&!$mensajeAsociacion): ?><p class="admin-field__hint">No hay almacenes activos disponibles para una asociación nueva.</p><?php elseif(!$puedeEditar): ?><p class="admin-field__hint">Asociar artículos requiere permiso de edición de Almacenes en este contexto.</p><?php endif; ?></section><?php endif; ?>
  <form method="GET" action="<?php echo $esc($base); ?>" class="almacen-selection" data-warehouse-selection>
    <?php if($volverContextual): ?><input type="hidden" name="volver" value="<?= $esc($volverContextual) ?>"><?php endif; ?>
    <input type="hidden" name="seleccion" value="1">
    <?php foreach(['q','negocio','articulo_id','desde','hasta','tipo','solo_alertas'] as $campo): if(!isset($filtros[$campo])||$filtros[$campo]==='')continue; ?><input type="hidden" name="<?php echo $campo; ?>" value="<?php echo $esc($filtros[$campo]); ?>"><?php endforeach; ?>
    <?php if($vista!=='existencias'): ?><input type="hidden" name="vista" value="<?php echo $esc($vista); ?>"><?php endif; ?>
    <div class="almacen-section-head"><div><h3>Almacenes en consulta</h3><p>Combina almacenes para consultar. Cada operación física se registra en uno solo.</p></div><div class="almacen-actions"><button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-warehouse-all hidden>Seleccionar todos</button><button type="submit" class="admin-btn admin-btn--secondary admin-btn--small">Aplicar selección</button></div></div>
    <?php /* La selección es el filtro de la consulta: cada almacén es una casilla con su
       nombre y sólo lo excepcional (inactivo, bajo mínimo, agotado). Lo que siempre está
       —total de artículos, estado activo— ya lo dicen el resumen y la tabla. El espacio
       literal entre nombre y aviso separa las palabras en el nombre accesible. */ ?>
    <?php foreach(Contextos::NOMBRES as $slug=>$nombre): $grupo=array_values(array_filter($opcionesAlmacen,static fn($a)=>$a['slug']===$slug)); if(!$grupo)continue; ?>
    <fieldset class="almacen-owner"><legend class="admin-eyebrow"><?php echo $esc($nombre); ?></legend><div class="almacen-owner__choices">
      <?php foreach($grupo as $a): $aId=(int)$a['almacen_id']; $activo=(int)$a['activo']===1; $bajo=(int)($a['bajo_minimo']??0); $agotados=(int)($a['agotados']??0);
        $avisos=array_filter([$bajo?$bajo.' bajo mínimo':'',$agotados?$agotados.' '.($agotados===1?'agotado':'agotados'):'']); ?>
      <label class="almacen-choice<?php echo $activo?'':' is-inactive'; ?>"><input type="checkbox" name="almacen_ids[]" value="<?php echo $aId; ?>" <?php echo in_array($aId,$seleccionados,true)?'checked':''; ?>><span class="almacen-choice__name"><?php echo $esc($a['nombre']); ?></span><?php if(!$activo): ?> <span class="almacen-choice__meta">Inactivo</span><?php endif; ?><?php if($avisos): ?> <span class="almacen-choice__meta"><?php echo $esc(implode(' · ',$avisos)); ?></span><?php endif; ?></label>
      <?php endforeach; ?>
    </div></fieldset><?php endforeach; ?>
    <?php if(!$opcionesAlmacen): ?><p class="admin-empty">Todavía no hay almacenes autorizados. Un administrador de Global puede crear el primero.</p><?php endif; ?>
    <p class="almacen-selection__status" role="status" aria-live="polite" data-selection-status><?php echo count($seleccionados); ?> almacenes en el alcance actual.</p>
  </form>
  <div class="almacen-summary" aria-label="Resumen del alcance filtrado"><span><strong><?php echo (int)($existencias['total']??0); ?></strong> artículos</span><span><strong><?php echo (int)($existencias['total_alertas']??0); ?></strong> con saldo bajo mínimo</span><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-movimientos"><?php echo admin_icon('reloj'); ?> Consultar movimientos</button><span><?php $pendientes=(int)($transferenciasPendientes??0);echo $pendientes.' '.($pendientes===1?'transferencia pendiente':'transferencias pendientes'); ?></span></div>
  <section class="admin-panel almacen-list">
    <div class="almacen-section-head"><div><h3>Existencias por artículo</h3><p>Saldo y mínimos del alcance seleccionado. Las alertas se evalúan en cada almacén.</p></div><a class="admin-btn admin-btn--secondary" href="<?= $esc($catalogoUrl) ?>"><?php echo admin_icon('editar'); ?> Catálogo de artículos</a></div>
    <form method="GET" action="<?php echo $esc($base); ?>" class="almacen-filters"><?php $seleccionOculta(); ?>
      <div class="admin-field"><label for="almacenes-q">Buscar artículo</label><input id="almacenes-q" name="q" type="search" maxlength="120" placeholder="Nombre del artículo…" value="<?php echo $esc($filtros['q']??''); ?>"></div>
      <label class="almacen-alert-filter"><input type="checkbox" name="solo_alertas" value="1" <?php echo !empty($filtros['solo_alertas'])?'checked':''; ?>> Solo bajo mínimo</label>
      <button class="admin-btn admin-btn--primary" type="submit"><?php echo admin_icon('buscar'); ?> Buscar</button><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($base.'?'.http_build_query(['almacen_ids'=>$seleccionados])); ?>">Limpiar búsqueda</a>
    </form>
    <div class="almacen-legend" aria-label="Leyenda de distribución"><?php foreach($opcionesAlmacen as $n=>$a): if(!in_array((int)$a['almacen_id'],$seleccionados,true))continue; ?><span><i class="almacen-swatch almacen-tone-<?php echo $n%6; ?>"></i><?php echo $esc($a['nombre'].' · '.Contextos::NOMBRES[$a['slug']]); ?></span><?php endforeach; ?><span>Las barras comparan el saldo de cada almacén; la cifra exacta se muestra por separado.</span></div>
    <?php if(!$articulos): ?><div class="admin-empty"><strong>No hay artículos con estos filtros</strong><p>Cambia la búsqueda, selecciona otro almacén o asocia un artículo existente.</p></div><?php else: ?>
    <div class="almacen-table" data-scrollable><table class="admin-table"><thead><tr><th>Artículo</th><th>Saldo seleccionado</th><th><?php echo $ctx==='global'?'Distribución por almacén':'Existencias por almacén'; ?></th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($articulos as $i): $artId=(int)$i['articulo_id']; $asociaciones=[]; $operables=[]; $magnitud=0;
      foreach($i['ambitos'] as $ambito)foreach($ambito['almacenes'] as $asociacion){$asociacion['slug']=$ambito['slug'];$asociaciones[]=$asociacion;$magnitud=DecimalInventario::sumar($magnitud,abs(DecimalInventario::leer($asociacion['saldo'],3,true)));if($puedeEditar&&$ambito['slug']===$ctx&&$asociacion['activo'])$operables[]=$asociacion;}
    ?>
    <tr><td data-label="Artículo"><strong><?php echo $esc($i['nombre']); ?></strong><small><?php echo (int)$i['activo']?$esc($i['unidad']):'Artículo inactivo'; ?></small></td><td data-label="Saldo seleccionado"><strong class="almacen-quantity"><?php echo $esc($i['saldo_total'].' '.$i['unidad']); ?></strong><?php if(!empty($i['saldo_piezas'])): ?><small class="almacen-piezas-lectura"><?php echo $esc($i['saldo_piezas']); ?></small><?php endif; ?><small>Mínimo: <?php echo $esc($i['minimo_total'].' '.$i['unidad']); ?></small><?php if($i['alerta']): ?><span class="admin-badge admin-badge--warning">Bajo mínimo</span><?php endif; ?></td>
    <td data-label="Distribución">
      <?php if(!$asociaciones): ?><span class="almacen-unassociated">Sin asociación en la selección</span><?php else: ?>
      <div class="almacen-bar" aria-label="Distribución de <?php echo $esc($i['nombre']); ?>">
      <?php foreach($asociaciones as $asociacion): $saldo=DecimalInventario::leer($asociacion['saldo'],3,true);$color=0;foreach($opcionesAlmacen as $n=>$a)if((int)$a['almacen_id']===$asociacion['almacen_id'])$color=$n%6;$piezas=!empty($asociacion['saldo_piezas'])?' ('.$asociacion['saldo_piezas'].')':'';$detalle=$asociacion['nombre'].' · '.Contextos::NOMBRES[$asociacion['slug']].': '.$asociacion['saldo'].' '.$i['unidad'].$piezas.'; mínimo '.$asociacion['minimo'].($asociacion['alerta']?'; bajo mínimo':'');$tip='saldo-'.$artId.'-'.$asociacion['almacen_id']; ?>
        <button type="button" class="almacen-segment almacen-tone-<?php echo $color; ?>" style="--segment:<?php echo $magnitud>0?number_format(abs($saldo)/$magnitud*100,6,'.',''):0; ?>" popovertarget="<?php echo $tip; ?>" aria-label="<?php echo $esc($detalle); ?>" data-stock-tip><?php if($saldo===0): ?><span class="almacen-zero">0</span><?php endif; ?></button>
        <div id="<?php echo $tip; ?>" class="almacen-tooltip" popover role="note"><strong><?php echo $esc($asociacion['nombre']); ?></strong><span><?php echo $esc(Contextos::NOMBRES[$asociacion['slug']]); ?></span><p><?php echo $esc($asociacion['saldo'].' '.$i['unidad'].$piezas.' · mínimo '.$asociacion['minimo']); ?></p><strong><?php echo $asociacion['alerta']?'Bajo mínimo':'Sin alerta de mínimo'; ?></strong></div>
      <?php endforeach; ?></div>
      <ul class="almacen-balances"><?php foreach($asociaciones as $asociacion): ?><li><button class="almacen-balance-label" type="button" popovertarget="saldo-<?php echo $artId.'-'.$asociacion['almacen_id']; ?>" data-stock-tip aria-label="<?php echo $esc($asociacion['nombre'].' · '.Contextos::NOMBRES[$asociacion['slug']].': '.$asociacion['saldo'].' '.$i['unidad'].(!empty($asociacion['saldo_piezas'])?' ('.$asociacion['saldo_piezas'].')':'').'; mínimo '.$asociacion['minimo'].($asociacion['alerta']?'; bajo mínimo':'')); ?>"><?php echo $esc($asociacion['nombre']); ?></button><span class="almacen-balance-value"><span class="admin-num"><?php if($asociacion['alerta']): ?><span class="almacen-balance-alert"><?php echo admin_icon('baja',14); ?><span class="admin-visually-hidden">Bajo mínimo: </span></span><?php endif; ?><?php echo $esc($asociacion['saldo'].' '.$i['unidad']); ?></span><?php if(!empty($asociacion['saldo_piezas'])): ?><small><?php echo $esc($asociacion['saldo_piezas']); ?></small><?php endif; ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </td><td data-label="Acciones"><div class="almacen-row-actions"><?php if(\Classes\Auth::puede('productos','ver',$ctx)): ?><a class="almacen-text-button" href="<?= $esc(\Services\Inventory\Articulos::base($ctx).'/editar?id='.$artId) ?>">Capacidades y elaboración</a><?php endif; ?>
      <?php if($operables): foreach(['entrada'=>['mas','Entrada'],'merma'=>['menos','Merma'],'ajuste'=>['editar','Conteo físico']] as $accion=>$etiqueta): if(!(int)$i['activo'])continue; ?><button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-admin-modal-open="articulo-<?php echo $artId; ?>" data-operation="<?php echo $accion; ?>"><?php echo admin_icon($etiqueta[0]); ?><?php echo $etiqueta[1]; ?></button><?php endforeach; ?><button type="button" class="almacen-text-button" data-admin-modal-open="articulo-<?php echo $artId; ?>" data-operation="configurar">Configurar asociación</button><?php endif; ?>
      <a class="almacen-text-button" href="<?php echo $esc($url('movimientos',['articulo_id'=>$artId,'pagina'=>1])); ?>"><?php echo admin_icon('reloj'); ?> Movimientos</a>
    </div></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php $pagina=max(1,(int)($existencias['pagina']??1));$paginas=max(1,(int)ceil(($existencias['total']??0)/($existencias['por_pagina']??25))); ?>
    <nav class="admin-pagination" aria-label="Paginación de artículos"><span>Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span><?php if($pagina>1): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('existencias',['pagina'=>$pagina-1])); ?>">Anterior</a><?php endif; ?><?php if($pagina<$paginas): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('existencias',['pagina'=>$pagina+1])); ?>">Siguiente</a><?php endif; ?></nav>
  </section>
  <?php foreach($articulos as $i): $operables=[];foreach($i['ambitos'] as $ambito)foreach($ambito['almacenes'] as $asociacion)if($puedeEditar&&$ambito['slug']===$ctx&&$asociacion['activo'])$operables[]=$asociacion;if(!$operables)continue;$modalInicio('articulo-'.(int)$i['articulo_id'],$i['nombre']); ?>
    <p class="almacen-modal__intro">Registra la operación en un único almacén de <?php echo $esc($nombreContexto); ?>.</p>
    <label class="admin-field" for="destino-<?php echo (int)$i['articulo_id']; ?>">Almacén físico<select id="destino-<?php echo (int)$i['articulo_id']; ?>" data-warehouse-picker><?php if(count($operables)>1): ?><option value="">Elige un almacén físico</option><?php endif; ?><?php foreach($operables as $asociacion): ?><option value="<?php echo $asociacion['almacen_id']; ?>"><?php echo $esc($asociacion['nombre']); ?></option><?php endforeach; ?></select></label>
    <?php foreach($operables as $n=>$asociacion): ?><div data-warehouse-panel="<?php echo $asociacion['almacen_id']; ?>" <?php echo count($operables)>1||$n?'hidden':''; ?>><p class="almacen-modal__balance"><?php echo $esc($asociacion['nombre'].' · Saldo '.$asociacion['saldo'].' '.$i['unidad'].(!empty($asociacion['saldo_piezas'])?' · '.$asociacion['saldo_piezas']:'')); ?></p><?php include __DIR__.'/_asociacion.php'; ?></div><?php endforeach;$modalFin();endforeach; ?>

  <?php if($puedeEditar&&$activos): $modalInicio('almacen-elegir-entrada','Registrar entrada'); ?>
    <p>Elige el artículo y después un único almacén físico propio. Usa la búsqueda de existencias para encontrar otros artículos.</p>
    <div class="almacen-entry-picker"><?php foreach($articulos as $i): if(!(int)$i['activo'])continue;$operable=false;foreach($i['ambitos'] as $ambito)if($ambito['slug']===$ctx)foreach($ambito['almacenes'] as $asoc)if($asoc['activo']&&$asoc['asociacion_activa'])$operable=true;if(!$operable)continue; ?>
      <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="articulo-<?php echo (int)$i['articulo_id']; ?>" data-operation="entrada"><?php echo $esc($i['nombre'].' · '.$i['unidad']); ?></button>
    <?php endforeach; ?></div>
  <?php $modalFin();endif; ?>
  <?php $modalInicio('almacen-config','Almacenes físicos');$filasAlmacen=$almacenes['almacenes']??[];include __DIR__.'/_almacenes.php';$modalFin(); ?>

  <?php if(($articuloContextual?$puedeAsociarContextual:$puedeEditar)&&($articuloContextual?$almacenesAsociarForm:$activos)): $modalInicio('almacen-asociar','Asociar artículo existente',!empty($abrirAsociacion)); ?>
    <p>La asociación comienza sin saldo. Después puedes registrar una entrada.</p>
    <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid"><?php $campos('asociar'); ?>
      <div class="admin-field"><label for="asociar-almacen">Almacén propio</label><select id="asociar-almacen" name="almacen_id" required><option value="">Elige el almacén</option><?php foreach($almacenesAsociarForm as $a): ?><option value="<?php echo (int)$a['almacen_id']; ?>"><?php echo $esc($a['nombre']); ?></option><?php endforeach; ?></select></div>
      <?php if($articuloContextual): ?><div class="admin-field"><label>Artículo</label><p class="almacen-modal__balance"><?= $esc($articuloContextual['nombre'].' · '.$articuloContextual['unidad']) ?></p><input type="hidden" name="articulo_id" value="<?= (int)$articuloContextual['id'] ?>"></div><?php else: ?><div class="admin-field"><label for="asociar-articulo">Artículo compartido</label><select id="asociar-articulo" name="articulo_id" required><option value="">Elige el artículo</option><?php foreach($catalogo['articulos'] as $a): if(!(int)$a['activo'])continue; ?><option value="<?php echo (int)$a['id']; ?>" <?= (int)($filtros['articulo_id']??0)===(int)$a['id']?'selected':'' ?>><?php echo $esc($a['nombre'].' · '.$a['unidad']); ?></option><?php endforeach; ?></select><p class="admin-field__hint"><?php echo (int)$catalogo['total']; ?> coincidencias. Usa la búsqueda principal para afinar la lista.</p></div><?php endif; ?>
      <div class="admin-field"><label for="asociar-minimo">Mínimo físico</label><input id="asociar-minimo" name="minimo" type="number" min="0" step="0.001" value="0.000" required></div><button type="submit" class="admin-btn admin-btn--primary">Asociar artículo</button>
    </form><?php $modalFin();endif; ?>
  <?php $modalInicio('almacen-movimientos','Movimientos del alcance seleccionado'); ?>
    <form method="GET" action="<?php echo $esc($base); ?>" class="admin-form-grid"><input type="hidden" name="vista" value="movimientos"><?php $seleccionOculta(); ?>
      <?php if(isset($filtros['articulo_id'])): ?><input type="hidden" name="articulo_id" value="<?php echo (int)$filtros['articulo_id']; ?>"><?php endif; ?>
      <div class="admin-field"><label for="mov-desde">Desde</label><input id="mov-desde" type="date" name="desde" value="<?php echo $esc($filtros['desde']??''); ?>"></div><div class="admin-field"><label for="mov-hasta">Hasta</label><input id="mov-hasta" type="date" name="hasta" value="<?php echo $esc($filtros['hasta']??''); ?>"></div>
      <div class="admin-field"><label for="mov-q">Artículo</label><input id="mov-q" name="q" type="search" value="<?php echo $esc($filtros['q']??''); ?>"></div><button class="admin-btn admin-btn--primary" type="submit">Filtrar movimientos</button>
    </form>
    <p><?php echo (int)$movimientos['total']; ?> movimientos autorizados. Las correcciones se registran con operaciones nuevas.</p>
    <div class="admin-table-wrap" data-scrollable><table class="admin-table"><thead><tr><th>Fecha / artículo</th><th>Almacén</th><th>Movimiento</th><th>Referencia</th></tr></thead><tbody><?php foreach($movimientos['movimientos'] as $m): ?><tr><td><?php echo $esc($m['articulo_nombre']); ?><small><?php echo $esc($m['fecha']); ?></small></td><td><?php echo $esc($m['almacen_nombre'].' · '.Contextos::NOMBRES[$m['slug']]); ?></td><td><?php echo $esc($m['tipo'].' · '.$m['delta'].' '.$m['unidad']); ?></td><td><?php echo $esc($m['referencia']); ?><small><?php echo $esc($m['nota']); ?></small></td></tr><?php endforeach; ?></tbody></table></div>
    <?php $mp=(int)$movimientos['pagina'];$mt=max(1,(int)ceil($movimientos['total']/$movimientos['por_pagina'])); ?><nav class="admin-pagination" aria-label="Paginación de movimientos"><span>Página <?php echo $mp; ?> de <?php echo $mt; ?></span><?php if($mp>1): ?><a href="<?php echo $esc($url('movimientos',['pagina'=>$mp-1])); ?>">Anterior</a><?php endif;if($mp<$mt): ?><a href="<?php echo $esc($url('movimientos',['pagina'=>$mp+1])); ?>">Siguiente</a><?php endif; ?></nav>
  <?php $modalFin(); ?>
  <?php $modalInicio('almacen-transferencias','Transferencias entre almacenes');include __DIR__.'/_transferencias.php';$modalFin(); ?>
  <?php if($vista==='faltantes'){$modalInicio('almacen-faltantes','Faltantes por conciliar');include __DIR__.'/_faltantes.php';$modalFin();} ?>
</section>
