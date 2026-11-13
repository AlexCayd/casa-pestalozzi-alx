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
// Asociar y dar entrada alcanzan los almacenes de todo negocio donde el usuario edita
// Almacenes (arquitectura §5); merma y configuración siguen siendo del contexto actual.
$contextosEditables=$contextosEditables??($puedeEditar?[$ctx]:[]);
$almacenesEditables=$almacenesEditables??array_map(static fn($a)=>['almacen_id'=>(int)$a['almacen_id'],'nombre'=>$a['nombre'],'slug'=>$a['slug'],'asociado'=>false],$activos);
$proveedoresPorContexto=$proveedoresPorContexto??[];
$almacenesAsociarForm=$articuloContextual?$almacenesDisponiblesAsociacion:$almacenesEditables;
foreach($almacenesAsociarForm as $n=>$a)$almacenesAsociarForm[$n]['slug']=$a['slug']??$ctx;
$entradables=static fn(array $i): array=>array_values(array_filter(array_merge(...array_map(static fn($ambito)=>array_map(static fn($x)=>$x+['slug'=>$ambito['slug']],in_array($ambito['slug'],$contextosEditables,true)?$ambito['almacenes']:[]),$i['ambitos']?:[[ 'slug'=>'','almacenes'=>[]]])),static fn($x)=>!empty($x['activo'])&&!empty($x['asociacion_activa'])));
$puedeAsociarContextual=$puedeEditar&&(!$articuloContextual||(int)$articuloContextual['activo']===1);
$seleccionados=isset($filtros['almacen_ids'])?(array)$filtros['almacen_ids']:(isset($filtros['almacen_id'])?[(int)$filtros['almacen_id']]:array_column(array_filter($opcionesAlmacen,static fn($a)=>empty($filtros['negocio'])||$a['slug']===$filtros['negocio']),'almacen_id'));
$seleccionados=array_map('intval',$seleccionados);
$url=static fn(string $tab,array $cambios=[]): string=>$base.'?'.http_build_query(array_merge($filtros,array_filter(['volver'=>$volverContextual]),['vista'=>$tab],$cambios));
$campos=static function(string $accion,array $extra=[]) use($esc,$csrf,$filtros,$volverContextual): void {
    foreach(array_merge(['admin_csrf'=>$csrf,'volver_contextual'=>$volverContextual??'','accion'=>$accion,'volver_vista'=>'existencias','volver_filtros'=>http_build_query($filtros)],$extra) as $campo=>$valor) echo '<input type="hidden" name="'.$esc($campo).'" value="'.$esc($valor).'">';
};
$seleccionOculta=static function() use($seleccionados,$volverContextual,$esc): void { if($volverContextual)echo '<input type="hidden" name="volver" value="'.$esc($volverContextual).'">'; if(!$seleccionados)return; echo '<input type="hidden" name="seleccion" value="1">'; foreach($seleccionados as $id)echo '<input type="hidden" name="almacen_ids[]" value="'.$id.'">'; };
$modalInicio=static function(string $id,string $titulo,bool $inicial=false,string $clase='') use($esc): void { ?>
<div class="admin-modal almacen-modal<?php echo $clase!==''?' '.$esc($clase):''; ?>" id="<?php echo $esc($id); ?>" data-admin-modal <?= $inicial?'data-contextual-open="1"':'' ?> hidden>
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
    <?php /* La cabecera sólo lleva lo que cambia existencias: asociar (la principal, al
       final) y registrar entrada. Consultar y configurar viven en la franja de
       herramientas bajo la selección, y «Nuevo artículo» junto al catálogo, que es
       donde se crea la identidad. Sin almacenes propios activos, lo primero es crear
       uno: entonces esa es la acción principal. Con un artículo contextual, el panel
       de abajo trae su propia acción principal y aquí no se repite. */
    $accionEntrada=$puedeEditar&&$activos;
    $accionAsociar=$puedeEditar&&$almacenesAsociarForm&&!$articuloContextual;
    $accionPrimerAlmacen=$puedeCrearAlmacen&&!$activos;
    if($accionEntrada||$accionPrimerAlmacen): ?>
    <div class="almacen-actions almacen-page-actions">
      <?php if($accionEntrada): ?><button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="almacen-elegir-entrada"><?php echo admin_icon('mas'); ?> Registrar entrada</button><?php endif; ?>
      <?php if($accionAsociar): ?><button type="button" class="admin-btn admin-btn--primary" data-admin-modal-open="almacen-asociar"><?php echo admin_icon('inventario'); ?> Asociar artículo</button><?php endif; ?>
      <?php if($accionPrimerAlmacen): ?><button type="button" class="admin-btn admin-btn--primary" data-admin-modal-open="almacen-config"><?php echo admin_icon('inventario'); ?> Crear almacén</button><?php endif; ?>
    </div>
    <?php endif; ?>
  </header>
  <?php include __DIR__.'/../partials/alertas.php'; ?>
  <?php /* Lo urgente va antes que el filtro: una venta salió sin saldo y alguien tiene
     que declarar de dónde. El enlace carga la bandeja (sólo se consulta en esa vista). */
  $faltantesPendientes=(int)($faltantesPendientes??0); $faltantesConsolidado=!empty($faltantesConsolidado); if($faltantesPendientes>0): ?>
  <aside class="almacen-faltantes-aviso<?php echo $faltantesConsolidado?' almacen-faltantes-aviso--consulta':''; ?>" aria-labelledby="almacen-faltantes-aviso-titulo">
    <?php /* En Global la forma también informa: círculo de información, no triángulo de alerta. */ ?>
    <span class="almacen-faltantes-aviso__icono"><?php echo admin_icon($faltantesConsolidado?'info':'alerta',20); ?></span>
    <div class="almacen-faltantes-aviso__texto">
      <p class="almacen-faltantes-aviso__titulo" id="almacen-faltantes-aviso-titulo"><span class="admin-num"><?php echo $faltantesPendientes; ?></span> <?php echo $faltantesPendientes===1?'faltante por conciliar':'faltantes por conciliar'; ?></p>
      <?php if($faltantesConsolidado): ?><p>Ventas de Restaurante y Panadería que salieron sin saldo suficiente. Las concilia el administrador de cada negocio; desde aquí puedes revisarlas y declarar su origen si hace falta.</p><?php else: ?>
      <p>Ventas que salieron sin saldo suficiente en su almacén. <?php echo $puedeEditar?'Declara de qué almacén salió cada una.':'Conciliarlas requiere permiso de edición.'; ?></p><?php endif; ?>
    </div>
    <a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('faltantes')); ?>"><?php echo $faltantesConsolidado||!$puedeEditar?'Ver faltantes':'Conciliar faltantes'; ?></a>
  </aside>
  <?php endif; ?>
  <?php if($articuloContextual): ?><section class="admin-panel admin-card almacen-contextual" aria-labelledby="almacen-contextual-title"><div><h3 id="almacen-contextual-title">Artículo seleccionado</h3><p><strong><?= $esc($articuloContextual['nombre']) ?></strong> · <?= $esc($articuloContextual['unidad']) ?></p><?php if($almacenesAsociadosActuales): ?><p>Asociado a <?= $esc(implode(', ',$almacenesAsociadosActuales)) ?>.</p><p class="admin-field__hint">Una asociación nueva empieza sin existencias ni movimientos.</p><?php endif; ?><?php if($mensajeAsociacion): ?><p class="admin-field__hint" role="status"><?= $esc($mensajeAsociacion) ?></p><?php endif; ?></div><?php if($puedeAsociarContextual&&$almacenesAsociarForm): ?><button type="button" class="admin-btn admin-btn--primary" data-admin-modal-open="almacen-asociar"><?= $almacenesAsociadosActuales?'Asociar a otro almacén':'Asociar a un almacén' ?></button><?php elseif($puedeAsociarContextual&&!$mensajeAsociacion): ?><p class="admin-field__hint">No hay almacenes activos disponibles para una asociación nueva.</p><?php elseif(!$puedeEditar): ?><p class="admin-field__hint">Asociar artículos requiere permiso de edición de Almacenes en este contexto.</p><?php endif; ?></section><?php endif; ?>
  <form method="GET" action="<?php echo $esc($base); ?>" class="almacen-selection" data-warehouse-selection>
    <?php if($volverContextual): ?><input type="hidden" name="volver" value="<?= $esc($volverContextual) ?>"><?php endif; ?>
    <input type="hidden" name="seleccion" value="1">
    <?php foreach(['q','negocio','articulo_id','desde','hasta','tipo','solo_alertas'] as $campo): if(!isset($filtros[$campo])||$filtros[$campo]==='')continue; ?><input type="hidden" name="<?php echo $campo; ?>" value="<?php echo $esc($filtros[$campo]); ?>"><?php endforeach; ?>
    <?php if($vista!=='existencias'): ?><input type="hidden" name="vista" value="<?php echo $esc($vista); ?>"><?php endif; ?>
    <?php /* La selección es el filtro de la consulta y no debe crecer con el número de
       almacenes: en la página queda una sola línea (cuántos y de qué propietario) y las
       casillas viven en un panel. El panel es un popover nativo dentro del formulario:
       sin JavaScript abre igual y «Aplicar selección» envía el mismo GET. Dentro, cada
       propietario es una columna con su propia lista desplazable; cada almacén muestra
       nombre y sólo lo excepcional (inactivo, bajo mínimo, agotado). El espacio literal
       entre nombre y aviso separa las palabras en el nombre accesible. */
    $grupos=[];foreach(Contextos::NOMBRES as $slug=>$nombre){$g=array_values(array_filter($opcionesAlmacen,static fn($a)=>$a['slug']===$slug));if($g)$grupos[$slug]=['nombre'=>$nombre,'almacenes'=>$g,'elegidos'=>count(array_filter($g,static fn($a)=>in_array((int)$a['almacen_id'],$seleccionados,true)))];}
    $totalOpciones=count($opcionesAlmacen);$totalElegidos=array_sum(array_column($grupos,'elegidos'));
    $resumenGrupo=static fn(array $g): string=>$g['nombre'].': '.($g['elegidos']===count($g['almacenes'])?'todos':($g['elegidos']===0?'ninguno':$g['elegidos'].' de '.count($g['almacenes'])));
    $panelId='almacen-alcance'; ?>
    <div class="almacen-section-head"><div><h3 id="almacen-alcance-titulo">Almacenes en consulta</h3><p>Combina almacenes para consultar. Cada operación física se registra en uno solo.</p></div></div>
    <?php if($opcionesAlmacen): ?>
    <button type="button" class="almacen-alcance__trigger" popovertarget="<?php echo $panelId; ?>" aria-controls="<?php echo $panelId; ?>" aria-expanded="false" aria-describedby="almacen-alcance-titulo" data-alcance-trigger>
      <?php echo admin_icon('inventario',18); ?>
      <span class="almacen-alcance__texto"><span class="almacen-alcance__cuenta"><strong class="admin-num"><?php echo $totalElegidos; ?></strong> de <?php echo $totalOpciones; ?> almacenes</span><span class="almacen-alcance__resumen"><?php echo $esc(implode(' · ',array_map($resumenGrupo,$grupos))); ?></span></span>
      <span class="almacen-alcance__cambiar">Cambiar<?php echo admin_icon('chevron-abajo',16); ?></span>
    </button>
    <div class="almacen-alcance__panel" id="<?php echo $panelId; ?>" popover role="dialog" aria-labelledby="almacen-alcance-panel-titulo" data-alcance-panel>
      <div class="almacen-alcance__cabecera">
        <p class="almacen-alcance__titulo" id="almacen-alcance-panel-titulo">Elige dónde consultar</p>
        <?php /* El buscador sólo filtra lo visible y no tiene name: no viaja en el GET. Sin
           JavaScript no hace nada, así que nace oculto. */ ?>
        <div class="almacen-alcance__buscar" data-alcance-buscar hidden><label class="admin-visually-hidden" for="almacen-alcance-q">Buscar almacén</label><?php echo admin_icon('buscar',16); ?><input id="almacen-alcance-q" type="search" placeholder="Buscar almacén…" autocomplete="off" data-alcance-q></div>
        <button type="button" class="almacen-alcance__enlace" data-warehouse-all hidden>Seleccionar todos</button>
      </div>
      <div class="almacen-alcance__grupos">
      <?php foreach($grupos as $slug=>$g): ?>
        <fieldset class="almacen-owner" data-alcance-grupo>
          <legend class="admin-eyebrow"><?php echo $esc($g['nombre']); ?> <span class="almacen-owner__cuenta" data-alcance-cuenta><?php echo $g['elegidos'].'/'.count($g['almacenes']); ?></span></legend>
          <p class="almacen-owner__atajos" data-alcance-atajos hidden><button type="button" class="almacen-alcance__enlace" data-alcance-todos>Todos<span class="admin-visually-hidden"> en <?php echo $esc($g['nombre']); ?></span></button><button type="button" class="almacen-alcance__enlace" data-alcance-ninguno>Ninguno<span class="admin-visually-hidden"> en <?php echo $esc($g['nombre']); ?></span></button></p>
          <div class="almacen-owner__choices">
          <?php foreach($g['almacenes'] as $a): $aId=(int)$a['almacen_id']; $activo=(int)$a['activo']===1; $bajo=(int)($a['bajo_minimo']??0); $agotados=(int)($a['agotados']??0);
            $avisos=array_filter([$bajo?$bajo.' bajo mínimo':'',$agotados?$agotados.' '.($agotados===1?'agotado':'agotados'):'']); ?>
            <label class="almacen-choice<?php echo $activo?'':' is-inactive'; ?>" data-alcance-nombre="<?php echo $esc($a['nombre']); ?>"><input type="checkbox" name="almacen_ids[]" value="<?php echo $aId; ?>" <?php echo in_array($aId,$seleccionados,true)?'checked':''; ?>><span class="almacen-choice__name"><?php echo $esc($a['nombre']); ?></span><?php if(!$activo): ?> <span class="almacen-choice__meta">Inactivo</span><?php endif; ?><?php if($avisos): ?> <span class="almacen-choice__meta"><?php echo $esc(implode(' · ',$avisos)); ?></span><?php endif; ?></label>
          <?php endforeach; ?>
          </div>
          <p class="almacen-owner__vacio" data-alcance-vacio hidden>Sin coincidencias</p>
        </fieldset>
      <?php endforeach; ?>
      </div>
      <div class="almacen-alcance__pie">
        <p class="almacen-selection__status" role="status" aria-live="polite" data-selection-status><?php echo $totalElegidos; ?> almacenes en el alcance actual.</p>
        <div class="almacen-actions">
          <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" popovertarget="<?php echo $panelId; ?>" popovertargetaction="hide" data-alcance-cancelar>Cancelar</button>
          <button type="submit" class="admin-btn admin-btn--primary admin-btn--small" data-alcance-aplicar>Aplicar selección</button>
        </div>
      </div>
    </div>
    <?php else: ?><p class="admin-empty">Todavía no hay almacenes autorizados. Un administrador de Global puede crear el primero.</p><?php endif; ?>
  </form>
  <?php /* Cifras del alcance a la izquierda y, a la derecha, las herramientas de consulta
     y configuración: no cambian existencias, así que van un escalón por debajo de las
     acciones de la cabecera. Las transferencias pendientes viajan dentro de su botón. */
  $pendientes=(int)($transferenciasPendientes??0); ?>
  <div class="almacen-summary">
    <p class="almacen-summary__cifras"><span><strong><?php echo (int)($existencias['total']??0); ?></strong> artículos</span><span><strong><?php echo (int)($existencias['total_alertas']??0); ?></strong> con saldo bajo mínimo</span></p>
    <div class="almacen-tools" role="group" aria-label="Consultas y configuración de almacenes">
      <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-admin-modal-open="almacen-movimientos"><?php echo admin_icon('reloj',16); ?> Movimientos</button>
      <?php /* Proveedores es su propia pantalla (también en el menú lateral): quien
         surte los almacenes se consulta junto a sus movimientos. */ ?>
      <a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $esc($proveedoresUrl??($base.'/proveedores')); ?>"><?php echo admin_icon('proveedores',16); ?> Proveedores</a>
      <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-admin-modal-open="almacen-transferencias"><?php echo admin_icon('transferencias',16); ?> Transferencias<?php if($pendientes>0): ?> <span class="almacen-tools__meta"><span class="admin-num"><?php echo $pendientes; ?></span> <?php echo $pendientes===1?'pendiente':'pendientes'; ?></span><?php endif; ?></button>
      <button type="button" class="admin-btn admin-btn--secondary admin-btn--small" data-admin-modal-open="almacen-config"><?php echo admin_icon('configuration',16); ?> <?php echo $puedeCrearAlmacen?'Crear o configurar almacenes':($puedeEditar?'Configurar almacenes':'Ver almacenes'); ?></button>
    </div>
  </div>
  <section class="admin-panel almacen-list" data-stock-list>
    <div class="almacen-section-head"><div><h3>Existencias por artículo</h3><p>Saldo y mínimos del alcance seleccionado. Las alertas se evalúan en cada almacén.</p></div><?php /* Crear la identidad es tarea del catálogo: «Nuevo artículo» va junto a él. */ ?><div class="almacen-actions"><a class="admin-btn admin-btn--secondary admin-btn--small" href="<?= $esc($catalogoUrl) ?>"><?php echo admin_icon('editar',16); ?> Catálogo de artículos</a><?php if($puedeNuevoArticulo): ?><a class="admin-btn admin-btn--secondary admin-btn--small" href="<?= $esc($base.'/catalogo/nuevo?'.http_build_query(['volver'=>$origenCatalogo])) ?>"><?php echo admin_icon('mas',16); ?> Nuevo artículo</a><?php endif; ?></div></div>
    <form method="GET" action="<?php echo $esc($base); ?>" class="almacen-filters"><?php $seleccionOculta(); ?>
      <div class="admin-field"><label for="almacenes-q">Buscar artículo</label><input id="almacenes-q" name="q" type="search" maxlength="120" placeholder="Nombre del artículo…" value="<?php echo $esc($filtros['q']??''); ?>"></div>
      <label class="almacen-alert-filter"><input type="checkbox" name="solo_alertas" value="1" <?php echo !empty($filtros['solo_alertas'])?'checked':''; ?>> Solo bajo mínimo</label>
      <button class="admin-btn admin-btn--primary" type="submit"><?php echo admin_icon('buscar'); ?> Buscar</button><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($base.'?'.http_build_query(['almacen_ids'=>$seleccionados])); ?>">Limpiar búsqueda</a>
    </form>
    <div class="almacen-legend" aria-label="Leyenda de distribución"><?php foreach($opcionesAlmacen as $n=>$a): if(!in_array((int)$a['almacen_id'],$seleccionados,true))continue; ?><span><i class="almacen-swatch almacen-tone-<?php echo $n%6; ?>"></i><?php echo $esc($a['nombre'].' · '.Contextos::NOMBRES[$a['slug']]); ?></span><?php endforeach; ?><span>Las barras comparan el saldo de cada almacén; la cifra exacta se muestra por separado.</span></div>
    <?php if(!$articulos): ?><div class="admin-empty"><strong>No hay artículos con estos filtros</strong><p>Cambia la búsqueda, selecciona otro almacén o asocia un artículo existente.</p></div><?php else: ?>
    <div class="almacen-table" data-scrollable><table class="admin-table"><thead><tr><th>Artículo</th><th>Saldo seleccionado</th><th><?php echo $ctx==='global'?'Distribución por almacén':'Existencias por almacén'; ?></th><th>Acciones</th></tr></thead><tbody>
    <?php /* Estado de cada almacén del desglose: el almacén manda sobre la asociación (con
       el almacén apagado, reactivar la asociación no basta). Una sola marca por almacén;
       la explicación, con el negocio dueño que puede reactivarlo, va en su detalle. */
    // Faltante sin conciliar (§16): el saldo no baja de cero, la deuda se muestra aparte con signo menos.
    $hayFaltante=static fn($q): bool=>$q!==null&&DecimalInventario::leer((string)$q)>0;
    $estadoAlmacen=static fn(array $x): ?array=>empty($x['activo'])?['Almacén inactivo','almacén inactivo','lo reactive']:(empty($x['asociacion_activa'])?['Asociación inactiva','asociación inactiva','la reactive']:null);
    // Un solo texto para el segmento y la etiqueta: el estado va justo tras el nombre.
    // El nombre de un almacén puede leerse como estado («Producto Terminado»): se presenta como almacén.
    $detalleSaldo=static function(array $x,string $unidad) use($estadoAlmacen,$hayFaltante): string { $estado=$estadoAlmacen($x); return $x['nombre'].', almacén de '.Contextos::NOMBRES[$x['slug']].($estado?', '.$estado[1]:'').': '.$x['saldo'].' '.$unidad.(!empty($x['saldo_piezas'])?' ('.$x['saldo_piezas'].')':'').'; mínimo '.$x['minimo'].($x['alerta']?'; bajo mínimo':'').($hayFaltante($x['faltante']??null)?'; '.$x['faltante'].' '.$unidad.' sin conciliar':''); };
    foreach($articulos as $i): $artId=(int)$i['articulo_id']; $artActivo=(int)$i['activo']===1; $asociaciones=[]; $operables=[]; $magnitud=0;
      foreach($i['ambitos'] as $ambito)foreach($ambito['almacenes'] as $asociacion){$asociacion['slug']=$ambito['slug'];$asociaciones[]=$asociacion;$magnitud=DecimalInventario::sumar($magnitud,abs(DecimalInventario::leer($asociacion['saldo'],3,true)));if($puedeEditar&&$ambito['slug']===$ctx&&$asociacion['activo'])$operables[]=$asociacion;}
    ?>
    <?php /* El estado del artículo maestro va pegado al nombre, en la misma celda, para que
       el lector lo anuncie junto a él. Activo es lo normal: punto y palabra en tinta tenue,
       sin caja. Inactivo cambia de forma (insignia neutra con borde discontinuo, como en
       «Almacenes físicos») y atenúa el nombre; la consecuencia se explica en Acciones. */ ?>
    <tr><td data-label="Artículo" class="almacen-articulo<?php echo $artActivo?'':' is-inactive'; ?>"><strong class="almacen-articulo__nombre"><?php echo $esc($i['nombre']); ?></strong> <span class="almacen-articulo__meta"><span class="admin-status almacen-estado<?php echo $artActivo?' almacen-estado--activo':' admin-status--neutral almacen-estado--inactivo'; ?>"><?php echo $artActivo?'Activo':'Inactivo'; ?></span> <small><?php echo $esc($i['unidad']); ?></small></span></td><td data-label="Saldo seleccionado"><strong class="almacen-quantity"><?php echo $esc($i['saldo_total'].' '.$i['unidad']); ?></strong><?php if(!empty($i['saldo_piezas'])): ?><small class="almacen-piezas-lectura"><?php echo $esc($i['saldo_piezas']); ?></small><?php endif; ?><small>Mínimo: <?php echo $esc($i['minimo_total'].' '.$i['unidad']); ?></small><?php if($hayFaltante($i['faltante_total']??null)): ?><small class="almacen-faltante-marca"><span class="admin-num">−<?php echo $esc($i['faltante_total'].' '.$i['unidad']); ?></span> sin conciliar</small><?php endif; ?><?php if($i['alerta']): ?><span class="admin-badge admin-badge--warning">Bajo mínimo</span><?php endif; ?></td>
    <td data-label="Distribución">
      <?php if(!$asociaciones): ?><span class="almacen-unassociated">Sin asociación en la selección</span><?php else: ?>
      <div class="almacen-bar" aria-label="Distribución de <?php echo $esc($i['nombre']); ?>">
      <?php foreach($asociaciones as $asociacion): $saldo=DecimalInventario::leer($asociacion['saldo'],3,true);$color=0;foreach($opcionesAlmacen as $n=>$a)if((int)$a['almacen_id']===$asociacion['almacen_id'])$color=$n%6;$piezas=!empty($asociacion['saldo_piezas'])?' ('.$asociacion['saldo_piezas'].')':'';$detalle=$detalleSaldo($asociacion,$i['unidad']);$estado=$estadoAlmacen($asociacion);$tip='saldo-'.$artId.'-'.$asociacion['almacen_id']; ?>
        <button type="button" class="almacen-segment almacen-tone-<?php echo $color; ?>" style="--segment:<?php echo $magnitud>0?number_format(abs($saldo)/$magnitud*100,6,'.',''):0; ?>" popovertarget="<?php echo $tip; ?>" aria-label="<?php echo $esc($detalle); ?>" data-stock-tip><?php if($saldo===0): ?><span class="almacen-zero">0</span><?php endif; ?></button>
        <div id="<?php echo $tip; ?>" class="almacen-tooltip" popover role="note"><strong><?php echo $esc($asociacion['nombre']); ?></strong><span><?php echo $esc('Almacén de '.Contextos::NOMBRES[$asociacion['slug']]); ?></span><p><?php echo $esc($asociacion['saldo'].' '.$i['unidad'].$piezas.' · mínimo '.$asociacion['minimo']); ?></p><?php if($hayFaltante($asociacion['faltante']??null)): ?><p class="almacen-tooltip__faltante"><?php echo $esc('−'.$asociacion['faltante'].' '.$i['unidad'].' sin conciliar'.($hayFaltante($asociacion['faltante_en_confirmacion']??null)?' ('.$asociacion['faltante_en_confirmacion'].' en confirmación)':'')); ?></p><?php endif; ?><?php if($estado): /* Inactivo no evalúa mínimo: en vez de «Sin alerta», qué implica y quién lo resuelve. */ ?><strong><?php echo $estado[0]; ?></strong><p><?php echo $esc('No admite operaciones físicas hasta que '.Contextos::NOMBRES[$asociacion['slug']].' '.$estado[2].'.'); ?></p><?php else: ?><strong><?php echo $asociacion['alerta']?'Bajo mínimo':'Sin alerta de mínimo'; ?></strong><?php endif; ?></div>
      <?php endforeach; ?></div>
      <?php /* La marca visible repite lo que ya dice el nombre accesible de la etiqueta (el
         estado va justo tras el nombre): se oculta al lector para no anunciarlo dos veces. */ ?>
      <ul class="almacen-balances"><?php foreach($asociaciones as $asociacion): $estado=$estadoAlmacen($asociacion); ?><li<?php echo $estado?' class="is-inactive"':''; ?>><span class="almacen-balance-name"><button class="almacen-balance-label" type="button" popovertarget="saldo-<?php echo $artId.'-'.$asociacion['almacen_id']; ?>" data-stock-tip aria-label="<?php echo $esc($detalleSaldo($asociacion,$i['unidad'])); ?>"><?php echo $esc($asociacion['nombre']); ?></button><?php if($estado): ?> <span class="admin-status admin-status--neutral almacen-estado almacen-estado--inactivo almacen-estado--compacto" aria-hidden="true"><?php echo $estado[0]; ?></span><?php endif; ?></span><span class="almacen-balance-value"><span class="admin-num"><?php if($asociacion['alerta']): ?><span class="almacen-balance-alert"><?php echo admin_icon('baja',14); ?><span class="admin-visually-hidden">Bajo mínimo: </span></span><?php endif; ?><?php echo $esc($asociacion['saldo'].' '.$i['unidad']); ?></span><?php if(!empty($asociacion['saldo_piezas'])): ?><small><?php echo $esc($asociacion['saldo_piezas']); ?></small><?php endif; ?><?php if($hayFaltante($asociacion['faltante']??null)): ?><small class="almacen-faltante-marca" aria-hidden="true"><span class="admin-num">−<?php echo $esc($asociacion['faltante'].' '.$i['unidad']); ?></span> sin conciliar</small><?php endif; ?></span></li><?php endforeach; ?></ul><?php endif; ?>
    </td><td data-label="Acciones">
    <?php /* Dos niveles y nada más. Visibles: las operaciones físicas, como un solo grupo
       de botones (lo que el operador viene a hacer), o «Asociar a un almacén» cuando el
       artículo todavía no está en ninguno propio. Todo lo demás —asociación, ficha e
       historial— cabe en «Más», un popover nativo: va en la capa superior, así que el
       desplazamiento horizontal de la tabla no lo recorta, y abre aun sin JavaScript. */
      $propiasFila=count(array_filter($asociaciones,static fn($x)=>$x['slug']===$ctx));
      $asociable=$puedeEditar&&$activos&&$artActivo&&$propiasFila<count($activos);
      $asociarUrl=$url('existencias',['articulo_id'=>$artId,'asociar'=>$artId,'pagina'=>null]);
      $verFicha=\Classes\Auth::puede('productos','ver',$ctx);
      $menuId='almacen-acciones-'.$artId;
      // Entrada y merma sólo existen donde la asociación está activa: con todas
      // inactivas, la fila no ofrece un botón que abriría un diálogo vacío.
      $fisicosFila=array_filter($operables,static fn($x)=>!empty($x['asociacion_activa']));
      // La entrada alcanza también almacenes de otros negocios donde el usuario edita.
      $entradaFila=$puedeEditar?$entradables($i):[];
      $opsFila=array_filter(['entrada'=>$entradaFila?['mas','Entrada']:null,'merma'=>$fisicosFila?['menos','Merma']:null]);
      // Sin operaciones físicas, la celda dice por qué y quién lo resuelve, donde se
      // buscaría el botón. El estado ya se ve en el artículo o en su almacén: aquí no se
      // repite la palabra, sólo la consecuencia. El artículo inactivo se explica a todos
      // (es un hecho del maestro); asociación y almacén, sólo a quien puede reactivarlos.
      $notaFila='';
      if(!$artActivo)$notaFila=$ctx==='global'?'Se reactiva en el catálogo de artículos para registrar operaciones físicas.':'Global debe reactivarlo para registrar operaciones físicas.';
      elseif(!$opsFila&&$puedeEditar&&$propiasFila)$notaFila=$operables?(count($operables)===1?'Reactiva la asociación':'Reactiva una asociación').' desde «Más» para registrar operaciones físicas.':($propiasFila===1?'Reactiva el almacén':'Reactiva uno de los almacenes').' desde «'.($puedeCrearAlmacen?'Crear o configurar almacenes':'Configurar almacenes').'» para registrar operaciones físicas.'; ?>
    <div class="almacen-row-actions">
      <?php if($opsFila&&$artActivo): ?><div class="almacen-ops" role="group" aria-label="<?php echo $esc('Operación física · '.$i['nombre']); ?>"><?php foreach($opsFila as $accion=>$etiqueta): ?><button type="button" class="almacen-op" data-admin-modal-open="articulo-<?php echo $artId.'-'.$accion; ?>"><?php echo admin_icon($etiqueta[0],16); ?><span><?php echo $etiqueta[1]; ?></span></button><?php endforeach; ?></div>
      <?php elseif($asociable&&!$propiasFila): ?><a class="admin-btn admin-btn--secondary admin-btn--small" href="<?php echo $esc($asociarUrl); ?>"><?php echo admin_icon('inventario',16); ?> Asociar a un almacén<span class="admin-visually-hidden">: <?php echo $esc($i['nombre']); ?></span></a>
      <?php elseif($notaFila!==''): ?><p class="almacen-row-note"><?php echo $esc($notaFila); ?></p><?php endif; ?>
      <button type="button" class="almacen-more" popovertarget="<?php echo $menuId; ?>" aria-controls="<?php echo $menuId; ?>" aria-expanded="false" data-row-menu-trigger>Más<span class="admin-visually-hidden"> acciones: <?php echo $esc($i['nombre']); ?></span><?php echo admin_icon('chevron-abajo',16); ?></button>
      <div class="almacen-menu" id="<?php echo $menuId; ?>" popover data-row-menu>
        <p class="almacen-menu__titulo"><?php echo $esc($i['nombre']); ?></p>
        <?php if($operables||($asociable&&$propiasFila)): ?><div class="almacen-menu__grupo" role="group" aria-label="Asociación">
          <?php if($operables): ?><button type="button" class="almacen-menu__item" data-admin-modal-open="articulo-<?php echo $artId; ?>-configurar"><?php echo admin_icon('configuration',16); ?><span>Configurar asociación<small>Mínimo y estado en cada almacén</small></span></button><?php endif; ?>
          <?php if($asociable&&$propiasFila): ?><a class="almacen-menu__item" href="<?php echo $esc($asociarUrl); ?>"><?php echo admin_icon('inventario',16); ?><span>Asociar a otro almacén<small>Empieza sin saldo</small></span></a><?php endif; ?>
        </div><?php endif; ?>
        <div class="almacen-menu__grupo" role="group" aria-label="Consulta">
          <?php if($verFicha): ?><a class="almacen-menu__item" href="<?= $esc(\Services\Inventory\Articulos::base($ctx).'/editar?id='.$artId) ?>"><?php echo admin_icon('variantes',16); ?><span>Capacidades y elaboración</span></a><?php endif; ?>
          <a class="almacen-menu__item" href="<?php echo $esc($url('movimientos',['articulo_id'=>$artId,'pagina'=>1])); ?>"><?php echo admin_icon('reloj',16); ?><span>Movimientos</span></a>
        </div>
      </div>
    </div></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php $pagina=max(1,(int)($existencias['pagina']??1));$paginas=max(1,(int)ceil(($existencias['total']??0)/($existencias['por_pagina']??25))); ?>
    <nav class="admin-pagination" aria-label="Paginación de artículos"><span>Página <?php echo $pagina; ?> de <?php echo $paginas; ?></span><?php if($pagina>1): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('existencias',['pagina'=>$pagina-1])); ?>">Anterior</a><?php endif; ?><?php if($pagina<$paginas): ?><a class="admin-btn admin-btn--secondary" href="<?php echo $esc($url('existencias',['pagina'=>$pagina+1])); ?>">Siguiente</a><?php endif; ?></nav>
  </section>
  <?php /* Un diálogo por operación y artículo (articulo-{id}-{operación}), cada uno con
     sólo sus campos. Los formularios son los mismos que en el modal único anterior (uno
     por almacén operable y operación); sólo se reparten, y lo nuevo son los marcos.
     La configuración existe en todo almacén propio activo; entrada y merma, sólo donde
     la asociación está activa y el artículo también. El conteo físico se retiró (auditoría
     A-4): sobrescribía el saldo sin motivo. Una diferencia real se corrige con una merma
     con su motivo, o con una entrada. */
  $operacionesArticulo=[
    'entrada'=>['Registrar entrada','Registra lo que llegó a uno de los almacenes donde editas.'],
    'merma'=>['Registrar merma','Registra lo que se perdió, caducó o se dañó en un almacén de %s.'],
    'configurar'=>['Configurar asociación','Mínimo de alerta y estado del artículo en un almacén de %s.'],
  ];
  foreach($articulos as $i): $operables=[];foreach($i['ambitos'] as $ambito)foreach($ambito['almacenes'] as $asociacion)if($puedeEditar&&$ambito['slug']===$ctx&&$asociacion['activo'])$operables[]=$asociacion+['slug'=>$ambito['slug']];
    $entradaArticulo=$puedeEditar&&(int)$i['activo']?$entradables($i):[];
    if(!$operables&&!$entradaArticulo)continue;
    $fisicos=(int)$i['activo']?array_values(array_filter($operables,static fn($x)=>!empty($x['asociacion_activa']))):[];
    foreach($operacionesArticulo as $tipoOperacion=>[$tituloOperacion,$introOperacion]): $almacenesOperacion=match($tipoOperacion){'configurar'=>$operables,'entrada'=>$entradaArticulo,default=>$fisicos}; if(!$almacenesOperacion)continue;
      include __DIR__.'/_operacion.php';
    endforeach;
  endforeach; ?>

  <?php if($puedeEditar&&$activos): $modalInicio('almacen-elegir-entrada','Registrar entrada'); ?>
    <p>Elige el artículo y después el almacén físico que lo recibe. Usa la búsqueda de existencias para encontrar otros artículos.</p>
    <div class="almacen-entry-picker"><?php foreach($articulos as $i): if(!(int)$i['activo']||!$entradables($i))continue; ?>
      <button type="button" class="admin-btn admin-btn--secondary" data-admin-modal-open="articulo-<?php echo (int)$i['articulo_id']; ?>-entrada"><?php echo $esc($i['nombre'].' · '.$i['unidad']); ?></button>
    <?php endforeach; ?></div>
  <?php $modalFin();endif; ?>
  <?php $modalInicio('almacen-config','Almacenes físicos');$filasAlmacen=$almacenes['almacenes']??[];include __DIR__.'/_almacenes.php';$modalFin(); ?>

  <?php if(($articuloContextual?$puedeAsociarContextual:$puedeEditar)&&$almacenesAsociarForm): $modalInicio('almacen-asociar','Asociar artículo existente',!empty($abrirAsociacion));
    // Varias casillas, agrupadas por negocio: un artículo se asocia a todos sus almacenes
    // en un solo envío. Se listan los de cada negocio donde el usuario edita Almacenes.
    $porNegocio=[];foreach($almacenesAsociarForm as $a)$porNegocio[$a['slug']][]=$a; ?>
    <p>Marca todos los almacenes que manejarán el artículo. Cada asociación comienza sin saldo; después puedes registrar una entrada.</p>
    <form method="POST" action="<?php echo $esc($base.'/operacion'); ?>" class="admin-form-grid"><?php $campos('asociar'); ?>
      <?php // Primero qué artículo y después dónde: el orden en que se decide. ?>
      <?php if($articuloContextual): ?><div class="admin-field"><label>Artículo</label><p class="almacen-modal__balance"><?= $esc($articuloContextual['nombre'].' · '.$articuloContextual['unidad']) ?></p><input type="hidden" name="articulo_id" value="<?= (int)$articuloContextual['id'] ?>"></div><?php else: ?><div class="admin-field"><label for="asociar-articulo">Artículo compartido</label><select id="asociar-articulo" name="articulo_id" required><option value="">Elige el artículo</option><?php foreach($catalogo['articulos'] as $a): if(!(int)$a['activo'])continue; ?><option value="<?php echo (int)$a['id']; ?>" <?= (int)($filtros['articulo_id']??0)===(int)$a['id']?'selected':'' ?>><?php echo $esc($a['nombre'].' · '.$a['unidad']); ?></option><?php endforeach; ?></select><p class="admin-field__hint"><?php echo (int)$catalogo['total']; ?> coincidencias. Usa la búsqueda principal para afinar la lista.</p></div><?php endif; ?>
      <div class="admin-field"><label for="asociar-minimo">Mínimo físico</label><input id="asociar-minimo" name="minimo" type="number" min="0" step="0.001" value="0.000" required inputmode="decimal" aria-describedby="asociar-minimo-ayuda"><p class="admin-field__hint" id="asociar-minimo-ayuda">Se aplica a cada almacén marcado; puedes ajustarlo después en cada uno.</p></div>
      <fieldset class="almacen-asociar-almacenes admin-form-grid__full" data-asociar-almacenes aria-describedby="asociar-almacenes-ayuda asociar-almacenes-error"><legend class="admin-field__label">Almacenes</legend>
        <?php foreach($porNegocio as $slug=>$lista): ?><div class="almacen-asociar-almacenes__grupo" role="group" aria-label="<?php echo $esc(Contextos::NOMBRES[$slug]??$slug); ?>"><p class="almacen-asociar-almacenes__negocio"><?php echo $esc(Contextos::NOMBRES[$slug]??$slug); ?></p>
          <?php foreach($lista as $a): ?><label class="almacen-asociar-almacenes__opcion"><input type="checkbox" name="almacen_ids[]" value="<?php echo (int)$a['almacen_id']; ?>" <?php echo count($almacenesAsociarForm)===1?'checked':''; ?>> <span><?php echo $esc($a['nombre']); ?></span></label><?php endforeach; ?>
        </div><?php endforeach; ?>
        <p class="admin-field__hint" id="asociar-almacenes-ayuda">Elige al menos uno.</p>
        <?php // Lo muestra inventario.js si se envía sin casillas; sin JavaScript responde el servidor. ?>
        <p class="admin-field__error almacen-asociar-almacenes__error" id="asociar-almacenes-error" data-asociar-error role="alert" hidden></p>
      </fieldset>
      <button type="submit" class="admin-btn admin-btn--primary almacen-asociar__enviar">Asociar artículo</button>
    </form><?php $modalFin();endif; ?>
  <?php $modalInicio('almacen-movimientos','Movimientos del alcance seleccionado'); ?>
    <form method="GET" action="<?php echo $esc($base); ?>" class="admin-form-grid"><input type="hidden" name="vista" value="movimientos"><?php $seleccionOculta(); ?>
      <?php if(isset($filtros['articulo_id'])): ?><input type="hidden" name="articulo_id" value="<?php echo (int)$filtros['articulo_id']; ?>"><?php endif; ?>
      <div class="admin-field"><label for="mov-desde">Desde</label><input id="mov-desde" type="date" name="desde" value="<?php echo $esc($filtros['desde']??''); ?>"></div><div class="admin-field"><label for="mov-hasta">Hasta</label><input id="mov-hasta" type="date" name="hasta" value="<?php echo $esc($filtros['hasta']??''); ?>"></div>
      <div class="admin-field"><label for="mov-q">Artículo</label><input id="mov-q" name="q" type="search" value="<?php echo $esc($filtros['q']??''); ?>"></div><button class="admin-btn admin-btn--primary" type="submit">Filtrar movimientos</button>
    </form>
    <p><?php echo (int)$movimientos['total']; ?> movimientos autorizados. Las correcciones se registran con operaciones nuevas.</p>
    <div class="admin-table-wrap" data-scrollable><table class="admin-table"><thead><tr><th>Fecha / artículo</th><th>Almacén</th><th>Movimiento</th><th>Referencia</th></tr></thead><tbody><?php foreach($movimientos['movimientos'] as $m): ?><tr><td><?php echo $esc($m['articulo_nombre']); ?><small><?php echo $esc($m['fecha']); ?></small></td><td><?php echo $esc($m['almacen_nombre'].' · '.Contextos::NOMBRES[$m['slug']]); ?></td><td><?php /* Ningún flujo crea ya ajustes (conteo retirado, auditoría A-4): los que quedan son historial. */ echo $esc(($m['tipo']==='ajuste'?'ajuste (histórico)':$m['tipo']).' · '.$m['delta'].' '.$m['unidad']); ?></td><td><?php echo $esc($m['referencia']); ?><small><?php echo $esc($m['nota']); ?></small></td></tr><?php endforeach; ?></tbody></table></div>
    <?php $mp=(int)$movimientos['pagina'];$mt=max(1,(int)ceil($movimientos['total']/$movimientos['por_pagina'])); ?><nav class="admin-pagination" aria-label="Paginación de movimientos"><span>Página <?php echo $mp; ?> de <?php echo $mt; ?></span><?php if($mp>1): ?><a href="<?php echo $esc($url('movimientos',['pagina'=>$mp-1])); ?>">Anterior</a><?php endif;if($mp<$mt): ?><a href="<?php echo $esc($url('movimientos',['pagina'=>$mp+1])); ?>">Siguiente</a><?php endif; ?></nav>
  <?php $modalFin(); ?>
  <?php $modalInicio('almacen-transferencias','Transferencias entre almacenes');include __DIR__.'/_transferencias.php';$modalFin(); ?>
  <?php if($vista==='faltantes'){$modalInicio('almacen-faltantes','Faltantes por conciliar');include __DIR__.'/_faltantes.php';$modalFin();} ?>
</section>
