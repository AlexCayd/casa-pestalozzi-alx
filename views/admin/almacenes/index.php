<?php
require_once __DIR__.'/../partials/_icons.php';
use Services\Security\Contextos;
use Services\Inventory\DecimalInventario;
$esc=static fn($v): string=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$ctx=(string)$contextoAlmacenes;
$opcionesAlmacen=$opcionesAlmacen??[];
$propios=array_values(array_filter($opcionesAlmacen,static fn($a)=>(string)$a['slug']===$ctx));
$activos=array_values(array_filter($propios,static fn($a)=>(int)$a['activo']===1));
$seleccionados=isset($filtros['almacen_ids'])?(array)$filtros['almacen_ids']:(isset($filtros['almacen_id'])?[(int)$filtros['almacen_id']]:array_column(array_filter($opcionesAlmacen,static fn($a)=>empty($filtros['negocio'])||$a['slug']===$filtros['negocio']),'almacen_id'));
$seleccionados=array_map('intval',$seleccionados);
$url=static fn(string $tab,array $cambios=[]): string=>$base.'?'.http_build_query(array_merge($filtros,['vista'=>$tab],$cambios));
$campos=static function(string $accion,array $extra=[]) use($esc,$csrf,$filtros): void {
    foreach(array_merge(['admin_csrf'=>$csrf,'accion'=>$accion,'volver_vista'=>'existencias','volver_filtros'=>http_build_query($filtros)],$extra) as $campo=>$valor) echo '<input type="hidden" name="'.$esc($campo).'" value="'.$esc($valor).'">';
};
$seleccionOculta=static function() use($seleccionados): void { if(!$seleccionados)return; echo '<input type="hidden" name="seleccion" value="1">'; foreach($seleccionados as $id)echo '<input type="hidden" name="almacen_ids[]" value="'.$id.'">'; };
$modalInicio=static function(string $id,string $titulo) use($esc): void { ?>
<div class="admin-modal almacen-modal" id="<?php echo $esc($id); ?>" data-admin-modal hidden>
  <div class="admin-modal__backdrop" data-admin-modal-close></div>
  <section class="admin-modal__dialog" data-admin-modal-dialog role="dialog" aria-modal="true" aria-labelledby="<?php echo $esc($id); ?>-title" tabindex="-1">
    <header class="almacen-modal__header"><h3 id="<?php echo $esc($id); ?>-title"><?php echo $esc($titulo); ?></h3><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-close aria-label="Cerrar ventana"><?php echo admin_icon('cerrar'); ?></button></header>
<?php };
$modalFin=static function(): void { echo '</section></div>'; };
$articulos=$existencias['articulos']??[];
$transferencias=$transferencias??[];$contrapartes=$contrapartes??[];$catalogoTransferencias=$catalogoTransferencias??[];
?>
<section class="admin-inventario admin-page almacen-workspace" data-almacenes-workspace data-initial-view="<?php echo $esc($vista); ?>">
  <header class="admin-page__header">
    <div class="admin-page__intro"><h2 class="admin-page__title">Almacenes</h2><p class="admin-page__subtitle">Existencias de <?php echo $esc($nombreContexto); ?>. Elige dónde consultar y trabaja artículo por artículo.</p></div>
    <div class="almacen-actions">
      <?php if($puedeEditar && $activos): ?><button type="button" class="admin-btn admin-btn--primary" data-admin-modal-open="almacen-asociar"><?php echo admin_icon('mas'); ?> Asociar artículo</button><?php endif; ?>
      <?php if($puedeCrearAlmacen): ?><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-config"><?php echo admin_icon('inventario'); ?> Crear almacén</button><?php endif; ?>
      <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-config"><?php echo admin_icon('configuration'); ?> <?php echo $puedeEditar?'Configurar almacenes':'Ver almacenes'; ?></button>
      <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-transferencias"><?php echo admin_icon('reloj'); ?> Transferencias</button>
    </div>
  </header>
  <?php include __DIR__.'/../partials/alertas.php'; ?>
  <form method="GET" action="<?php echo $esc($base); ?>" class="almacen-selection" data-warehouse-selection>
    <input type="hidden" name="seleccion" value="1">
    <?php if(!empty($filtros['q'])): ?><input type="hidden" name="q" value="<?php echo $esc($filtros['q']); ?>"><?php endif; ?>
    <?php if(!empty($filtros['solo_alertas'])): ?><input type="hidden" name="solo_alertas" value="1"><?php endif; ?>
    <div class="almacen-section-head"><div><h3>Almacenes en consulta</h3><p>Puedes combinar varios. Cada operación física se registra en uno solo.</p></div><button type="submit" class="admin-btn admin-btn--secondary">Aplicar selección</button></div>
    <?php foreach(Contextos::NOMBRES as $slug=>$nombre): $grupo=array_values(array_filter($opcionesAlmacen,static fn($a)=>$a['slug']===$slug)); if(!$grupo)continue; ?>
    <fieldset class="almacen-owner"><legend><?php echo $esc($nombre); ?></legend><div class="almacen-cards">
      <?php foreach($grupo as $a): $aId=(int)$a['almacen_id']; ?>
      <label class="almacen-choice"><input type="checkbox" name="almacen_ids[]" value="<?php echo $aId; ?>" <?php echo in_array($aId,$seleccionados,true)?'checked':''; ?>><span class="almacen-choice__body"><?php echo admin_icon('inventario'); ?><span><strong><?php echo $esc($a['nombre']); ?></strong><small><?php echo (int)$a['activo']?'Activo':'Inactivo'; ?><?php echo (int)$a['permitir_negativos']?' · Admite negativos':''; ?></small></span></span></label>
      <?php endforeach; ?>
    </div></fieldset><?php endforeach; ?>
    <?php if(!$opcionesAlmacen): ?><p class="admin-empty">Todavía no hay almacenes autorizados. Un administrador de Global puede crear el primero.</p><?php endif; ?>
    <p class="almacen-selection__status" role="status" aria-live="polite" data-selection-status><?php echo count($seleccionados); ?> almacenes en el alcance actual.</p>
  </form>
  <div class="almacen-summary" aria-label="Resumen del alcance filtrado"><span><strong><?php echo (int)($existencias['total']??0); ?></strong> artículos</span><span><strong><?php echo (int)($existencias['total_alertas']??0); ?></strong> con saldo bajo mínimo</span><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-movimientos"><?php echo admin_icon('reloj'); ?> Consultar movimientos</button><span><?php $pendientes=(int)($transferenciasPendientes??0);echo $pendientes.' '.($pendientes===1?'transferencia pendiente':'transferencias pendientes'); ?></span></div>
  <section class="admin-panel almacen-list">
    <div class="almacen-section-head"><div><h3>Existencias por artículo</h3><p>Saldo y mínimos del alcance seleccionado. Las alertas se evalúan en cada almacén.</p></div><?php if($ctx==='global'): ?><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-catalogo"><?php echo admin_icon('editar'); ?> Catálogo maestro</button><?php endif; ?></div>
    <form method="GET" action="<?php echo $esc($base); ?>" class="almacen-filters"><?php $seleccionOculta(); ?>
      <div class="admin-field"><label for="almacenes-q">Buscar artículo</label><input id="almacenes-q" name="q" type="search" maxlength="120" placeholder="Nombre del artículo…" value="<?php echo $esc($filtros['q']??''); ?>"></div>
      <label class="almacen-alert-filter"><input type="checkbox" name="solo_alertas" value="1" <?php echo !empty($filtros['solo_alertas'])?'checked':''; ?>> Solo bajo mínimo</label>
      <button class="admin-btn admin-btn--primary" type="submit"><?php echo admin_icon('buscar'); ?> Buscar</button><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($base.'?'.http_build_query(['almacen_ids'=>$seleccionados])); ?>">Limpiar búsqueda</a>
    </form>
    <?php if($ctx==='global'): ?><div class="almacen-legend" aria-label="Leyenda de distribución"><?php foreach($opcionesAlmacen as $n=>$a): if(!in_array((int)$a['almacen_id'],$seleccionados,true))continue; ?><span><i class="almacen-swatch almacen-tone-<?php echo $n%6; ?>"></i><?php echo $esc($a['nombre'].' · '.Contextos::NOMBRES[$a['slug']]); ?></span><?php endforeach; ?><span>Rayado: saldo negativo</span></div><?php endif; ?>
    <?php if(!$articulos): ?><div class="admin-empty"><strong>No hay artículos con estos filtros</strong><p>Cambia la búsqueda, selecciona otro almacén o asocia un artículo existente.</p></div><?php else: ?>
    <div class="almacen-table" data-scrollable><table class="admin-table"><thead><tr><th>Artículo</th><th>Saldo seleccionado</th><th><?php echo $ctx==='global'?'Distribución por almacén':'Existencias por almacén'; ?></th><th>Acciones</th></tr></thead><tbody>
    <?php foreach($articulos as $i): $artId=(int)$i['articulo_id']; $asociaciones=[]; $operables=[]; $magnitud=0;
      foreach($i['ambitos'] as $ambito)foreach($ambito['almacenes'] as $asociacion){$asociacion['slug']=$ambito['slug'];$asociaciones[]=$asociacion;$magnitud=DecimalInventario::sumar($magnitud,abs(DecimalInventario::leer($asociacion['saldo'],3,true)));if($puedeEditar&&$ambito['slug']===$ctx&&$asociacion['activo'])$operables[]=$asociacion;}
    ?>
    <tr><td data-label="Artículo"><strong><?php echo $esc($i['nombre']); ?></strong><small><?php echo (int)$i['activo']?$esc($i['unidad']):'Artículo inactivo'; ?></small></td><td data-label="Saldo seleccionado"><strong class="almacen-quantity"><?php echo $esc($i['saldo_total'].' '.$i['unidad']); ?></strong><small>Mínimo: <?php echo $esc($i['minimo_total'].' '.$i['unidad']); ?></small><?php if($i['alerta']): ?><span class="admin-badge admin-badge--warning">Bajo mínimo</span><?php endif; ?></td>
    <td data-label="Distribución">
      <?php if(!$asociaciones): ?><span class="almacen-unassociated">Sin asociación en la selección</span><?php else: ?>
      <?php if($ctx==='global'): ?><div class="almacen-bar" aria-label="Distribución de <?php echo $esc($i['nombre']); ?>">
      <?php foreach($asociaciones as $asociacion): $saldo=DecimalInventario::leer($asociacion['saldo'],3,true);$color=0;foreach($opcionesAlmacen as $n=>$a)if((int)$a['almacen_id']===$asociacion['almacen_id'])$color=$n%6;$detalle=$asociacion['nombre'].' · '.Contextos::NOMBRES[$asociacion['slug']].': '.$asociacion['saldo'].' '.$i['unidad'].'; mínimo '.$asociacion['minimo'].($asociacion['alerta']?'; bajo mínimo':'');$tip='saldo-'.$artId.'-'.$asociacion['almacen_id']; ?>
        <button type="button" class="almacen-segment almacen-tone-<?php echo $color; ?> <?php echo $saldo<0?'is-negative':''; ?>" style="--segment:<?php echo $magnitud>0?number_format(abs($saldo)/$magnitud*100,6,'.',''):0; ?>" popovertarget="<?php echo $tip; ?>" aria-label="<?php echo $esc($detalle); ?>" data-stock-tip><?php if($saldo===0): ?><span class="almacen-zero">0</span><?php endif; ?></button>
        <div id="<?php echo $tip; ?>" class="almacen-tooltip" popover role="note"><strong><?php echo $esc($asociacion['nombre']); ?></strong><span><?php echo $esc(Contextos::NOMBRES[$asociacion['slug']]); ?></span><p><?php echo $esc($asociacion['saldo'].' '.$i['unidad'].' · mínimo '.$asociacion['minimo']); ?></p><?php if($asociacion['alerta']): ?><strong>Bajo mínimo</strong><?php endif; ?></div>
      <?php endforeach; ?></div><?php endif; ?>
      <ul class="almacen-balances"><?php foreach($asociaciones as $asociacion): ?><li><?php if($ctx==='global'): ?><button class="almacen-balance-label" type="button" popovertarget="saldo-<?php echo $artId.'-'.$asociacion['almacen_id']; ?>" data-stock-tip aria-label="<?php echo $esc($asociacion['nombre'].' · '.Contextos::NOMBRES[$asociacion['slug']].': '.$asociacion['saldo'].' '.$i['unidad'].'; mínimo '.$asociacion['minimo']); ?>"><?php echo $esc($asociacion['nombre']); ?></button><?php else: ?><span><?php echo $esc($asociacion['nombre']); ?><?php if(!$asociacion['activo']||!$asociacion['asociacion_activa']): ?> · Inactivo<?php endif; ?></span><?php endif; ?><span class="admin-num"><?php echo $esc($asociacion['saldo'].' '.$i['unidad']); ?><?php if($asociacion['alerta']): ?> <span aria-label="Bajo mínimo">↓</span><?php endif; ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </td><td data-label="Acciones"><div class="almacen-row-actions">
      <?php if($operables): foreach(['entrada'=>['mas','Entrada'],'merma'=>['menos','Merma'],'ajuste'=>['editar','Conteo físico']] as $accion=>$etiqueta): if(!(int)$i['activo'])continue; ?><button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-admin-modal-open="articulo-<?php echo $artId; ?>" data-operation="<?php echo $accion; ?>"><?php echo admin_icon($etiqueta[0]); ?><?php echo $etiqueta[1]; ?></button><?php endforeach; ?><button type="button" class="almacen-text-button" data-admin-modal-open="articulo-<?php echo $artId; ?>" data-operation="configurar">Configurar asociación</button><?php endif; ?>
      <a class="almacen-text-button" href="<?php echo $esc($url('movimientos',['articulo_id'=>$artId,'pagina'=>1])); ?>"><?php echo admin_icon('reloj'); ?> Movimientos</a>
    </div></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php $pagina=max(1,(int)($existencias['pagina']??1));$paginas=max(1,(int)ceil(($existencias['total']??0)/($existencias['por_pagina']??25))); ?>
    <nav class="admin-pagination" aria-label="Paginación de artículos"><span>Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span><?php if($pagina>1): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('existencias',['pagina'=>$pagina-1])); ?>">Anterior</a><?php endif; ?><?php if($pagina<$paginas): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('existencias',['pagina'=>$pagina+1])); ?>">Siguiente</a><?php endif; ?></nav>
  </section>
  <?php foreach($articulos as $i): $operables=[];foreach($i['ambitos'] as $ambito)foreach($ambito['almacenes'] as $asociacion)if($puedeEditar&&$ambito['slug']===$ctx&&$asociacion['activo'])$operables[]=$asociacion;if(!$operables)continue;$modalInicio('articulo-'.(int)$i['articulo_id'],$i['nombre']); ?>
    <p class="almacen-modal__intro">Registra la operación en un único almacén de <?php echo $esc($nombreContexto); ?>.</p>
    <label class="admin-field" for="destino-<?php echo (int)$i['articulo_id']; ?>">Almacén físico<select id="destino-<?php echo (int)$i['articulo_id']; ?>" data-warehouse-picker><?php foreach($operables as $asociacion): ?><option value="<?php echo $asociacion['almacen_id']; ?>"><?php echo $esc($asociacion['nombre']); ?></option><?php endforeach; ?></select></label>
    <?php foreach($operables as $n=>$asociacion): ?><div data-warehouse-panel="<?php echo $asociacion['almacen_id']; ?>" <?php echo $n?'hidden':''; ?>><p class="almacen-modal__balance"><?php echo $esc($asociacion['nombre'].' · Saldo '.$asociacion['saldo'].' '.$i['unidad']); ?></p><?php include __DIR__.'/_asociacion.php'; ?></div><?php endforeach;$modalFin();endforeach; ?>
  <?php $modalInicio('almacen-config','Almacenes físicos');$filasAlmacen=$almacenes['almacenes']??[];include __DIR__.'/_almacenes.php';$modalFin(); ?>
  <?php if($ctx==='global'): $modalInicio('almacen-catalogo','Catálogo maestro');include __DIR__.'/_catalogo.php';$modalFin();endif; ?>
  <?php if($puedeEditar&&$activos): $modalInicio('almacen-asociar','Asociar artículo existente'); ?>
    <p>La asociación comienza sin saldo. Después puedes registrar una entrada.</p>
    <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid"><?php $campos('asociar'); ?>
      <div class="admin-field"><label for="asociar-almacen">Almacén propio</label><select id="asociar-almacen" name="almacen_id" required><option value="">Elige el almacén</option><?php foreach($activos as $a): ?><option value="<?php echo (int)$a['almacen_id']; ?>"><?php echo $esc($a['nombre']); ?></option><?php endforeach; ?></select></div>
      <div class="admin-field"><label for="asociar-articulo">Artículo compartido</label><select id="asociar-articulo" name="articulo_id" required><option value="">Elige el artículo</option><?php foreach($catalogo['articulos'] as $a): if(!(int)$a['activo'])continue; ?><option value="<?php echo (int)$a['id']; ?>"><?php echo $esc($a['nombre'].' · '.$a['unidad']); ?></option><?php endforeach; ?></select><p class="admin-field__hint"><?php echo (int)$catalogo['total']; ?> coincidencias. Usa la búsqueda principal para afinar la lista.</p></div>
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
</section>
