<?php
/**
 * Lista única de artículos (arquitectura §18): quince por página, alta en un diálogo
 * y una sola acción por fila. Con `$maestro` la ve quien sólo tiene Almacenes: mismas
 * columnas físicas, sin lectura de recetas y con la identidad como destino.
 */
require_once __DIR__.'/../partials/_icons.php';
$esc=static fn($v)=>htmlspecialchars(is_scalar($v)?(string)$v:'',ENT_QUOTES,'UTF-8');
$maestro=$maestro??false;$estado=$estado??'todos';$tipo=$tipo??'todos';$pagina=(int)($pagina??1);
$baseLista=$maestro?$baseCatalogo:$baseArticulos;
// En el catálogo de Almacenes `tipo` es el de movimientos: allí el filtro no viaja en el retorno.
$filtros=array_filter(['q'=>$q,'estado'=>$estado==='todos'?'':$estado,'tipo'=>$tipo==='todos'?'':$tipo]);
$urlLista=static fn(array $extra=[]):string=>$baseLista.(($p=array_filter($filtros+$extra+['volver'=>$volverContextual]))?'?'.http_build_query($p):'');
$retornoLista=$baseLista.(($p=array_filter(array_diff_key($filtros,$maestro?['tipo'=>1]:[])+['pagina'=>$pagina>1?$pagina:null,'volver'=>$volverContextual]))?'?'.http_build_query($p):'');
$verUso=$puedeProductos;$verAlmacenes=\Classes\Auth::puede('inventario','ver',$ctx);
$plural=static fn(int $n,string $uno,string $varios):string=>$n.' '.($n===1?$uno:$varios);
$desde=$total?($pagina-1)*\Services\Inventory\Articulos::POR_PAGINA+1:0;$hasta=$desde?$desde+count($articulos)-1:0;
$tipos=['todos'=>'Todos','insumo'=>'Se compran','preparacion'=>'Se preparan en casa']+($verUso?['reventa'=>'En productos de reventa']:[]);
$altaVolver=$retornoLista;$altaPrefijo='alta-dialogo';$altaEnDialogo=true;
?>
<section class="admin-recetas admin-page m2-catalogo m6-lista">
<header class="admin-page__header"><div class="admin-page__intro"><h1 class="admin-page__title"><?= $maestro?'Catálogo de artículos':'Artículos y preparaciones' ?></h1><p class="admin-page__subtitle">Todo lo que se compra o se prepara para usarlo en recetas. Las existencias se llevan en Almacenes.</p></div>
<div class="admin-actions"><?php if($puedeCrearArticulo): ?><a class="admin-btn admin-btn--primary" href="<?= $esc($baseCatalogo.'/nuevo?'.http_build_query(['volver'=>$retornoLista])) ?>" data-admin-modal-open="articulo-alta" data-m6-abrir-alta>Nuevo artículo</a><?php endif; ?><?php if($verAlmacenes): ?><a class="admin-btn admin-btn--secondary" href="<?= $esc($baseAlmacenes.'?'.http_build_query(['volver'=>$retornoLista])) ?>">Almacenes</a><?php endif; ?><?php if($puedeProductos): ?><a class="admin-btn admin-btn--secondary" href="<?= $esc($ctx==='restaurante'?'/admin/recetas':'/admin/'.$ctx.'/recetas') ?>">Recetas de productos</a><?php endif; ?></div></header>
<?php include __DIR__.'/../partials/alertas.php'; ?>
<?php if(!empty($articuloCreado)):$creadoId=(int)$articuloCreado['articulo_id'];$creadoN=(int)($articuloCreado['almacenes']??0); ?>
<div class="admin-menu__flash admin-menu__flash--exito m6-creado" role="status"><p><strong><?= $esc($articuloCreado['nombre']) ?></strong> ya está en el catálogo<?= $creadoN>0?' y asociado a '.$plural($creadoN,'almacén','almacenes'):'' ?>. Ya puedes usarlo en recetas.<?php if(!empty($articuloCreado['aviso'])): ?> <?= $esc($articuloCreado['aviso']) ?><?php endif; ?></p><a class="admin-btn admin-btn--secondary admin-btn--small" href="<?= $esc(($puedeProductos?$baseArticulos:$baseCatalogo).'/editar?'.http_build_query(['id'=>$creadoId,'volver'=>$retornoLista])) ?>">Abrir su ficha</a></div>
<?php endif; ?>
<?php if($volverContextual): ?><p><a class="admin-btn admin-btn--secondary" href="<?= $esc($volverContextual) ?>">Volver a la configuración de origen</a></p><?php endif; ?>
<?php if(!$puedeCrearArticulo&&!$maestro&&!empty($puedeEditar)): ?><p class="admin-field__hint">El alta de artículos requiere edición de Almacenes y la facultad de catálogo Global. Puedes consultar y configurar los que ya existen.</p><?php endif; ?>
<form method="GET" class="admin-form-grid m2-catalogo__filtros m6-lista__filtros" role="search" aria-label="Filtrar artículos"><?php if($volverContextual): ?><input type="hidden" name="volver" value="<?= $esc($volverContextual) ?>"><?php endif; ?>
<label class="admin-field">Buscar artículo<input name="q" type="search" maxlength="120" value="<?= $esc($q) ?>" placeholder="Nombre del artículo"></label>
<label class="admin-field">Tipo<select name="tipo"><?php foreach($tipos as $valor=>$label): ?><option value="<?= $valor ?>" <?= $tipo===$valor?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label class="admin-field">Estado<select name="estado"><?php foreach(['todos'=>'Todos','activos'=>'Activos','inactivos'=>'Inactivos'] as $valor=>$label): ?><option value="<?= $valor ?>" <?= $estado===$valor?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<div class="admin-actions"><button class="admin-btn admin-btn--secondary">Buscar</button><?php if($filtros): ?><a class="admin-btn admin-btn--ghost" href="<?= $esc($baseLista.($volverContextual?'?'.http_build_query(['volver'=>$volverContextual]):'')) ?>">Limpiar</a><?php endif; ?></div></form>
<section class="admin-panel admin-card" aria-labelledby="m6-lista-titulo"><h2 id="m6-lista-titulo">Artículos <span class="admin-field__hint">· <?= $total?$desde.'–'.$hasta.' de '.(int)$total:'0' ?></span></h2>
<?php if(!$articulos): ?><div class="admin-empty"><strong><?= $filtros?'No hay artículos con esta búsqueda':'Todavía no hay artículos' ?></strong><p><?= $filtros?'Cambia el nombre o los filtros.':($puedeCrearArticulo?'Crea el primero con «Nuevo artículo».':'Coordina el alta con una persona autorizada.') ?></p></div><?php else: ?>
<div class="admin-table-wrap" tabindex="0" role="region" aria-label="Artículos"><table class="admin-table m6-tabla"><thead><tr><th scope="col">Artículo</th><th scope="col">Tipo</th><?php if($verUso): ?><th scope="col">Se usa en</th><?php endif; ?><?php if($verAlmacenes): ?><th scope="col">Almacenes</th><?php endif; ?><th scope="col">Estado</th><th scope="col"><span class="admin-visually-hidden">Acciones</span></th></tr></thead><tbody>
<?php foreach($articulos as $a):
    $fichaUrl=($puedeProductos?$baseArticulos:$baseCatalogo).'/editar?'.http_build_query(['id'=>$a['id'],'volver'=>$retornoLista]);
    $pieza=(string)($a['contenido_pieza']??'');if(str_contains($pieza,'.'))$pieza=rtrim(rtrim($pieza,'0'),'.');
    $activoAqui=!empty($a['estadoEfectivo']['efectivo']['activo']);
    $u=$a['uso']??null;$usos=[];
    if($u){
        // Un solo total de productos; la reventa se dice aparte porque descuenta directo.
        $enProductos=(int)$u['recetas']+(int)$u['reventa'];
        if($enProductos)$usos[]=$plural($enProductos,'producto','productos').((int)$u['reventa']===$enProductos?' de reventa':($u['reventa']?' ('.(int)$u['reventa'].' de reventa)':''));
        if($u['preparaciones'])$usos[]=$plural($u['preparaciones'],'preparación','preparaciones');}
?><tr<?= $activoAqui?'':' class="is-inactive"' ?>>
<td data-label="Artículo"><a class="m6-tabla__nombre" href="<?= $esc($fichaUrl) ?>"><?= $esc($a['nombre']) ?></a><p class="admin-field__hint"><?= $esc($a['unidad']) ?><?= $pieza!==''?' · pieza de '.$esc($pieza.' '.$a['unidad']):'' ?></p></td>
<td data-label="Tipo"><?php if($a['tipo']==='preparacion'): ?><strong>Se prepara en casa</strong><p class="admin-field__hint"><?php if(!empty($a['de_casa'])): ?>Receta de la casa · versión <?= (int)$a['version_casa'] ?><?php elseif($a['version']): ?>Receta publicada · versión <?= (int)$a['version'] ?><?php else: ?><span class="admin-status admin-status--warning m6-pendiente">Receta pendiente</span><?php endif; ?></p><?php else: ?>Se compra<?php endif; ?></td>
<?php if($verUso): ?><td data-label="Se usa en"><?php if($usos): ?><?= $esc(implode(' · ',$usos)) ?><?php else: ?><span class="m6-tabla__vacio">Sin uso todavía</span><?php endif; ?></td><?php endif; ?>
<?php if($verAlmacenes): ?><td data-label="Almacenes"><?php if(empty($a['estadoEfectivo']['efectivo']['almacenable'])): ?><span class="m6-tabla__vacio">No lleva existencias</span><?php elseif((int)$a['almacenes']>0): ?><?= $esc($plural((int)$a['almacenes'],'almacén','almacenes')) ?><?php else: ?><span class="m6-tabla__vacio">Sin asociar</span><?php endif; ?></td><?php endif; ?>
<td data-label="Estado"><span class="admin-status m6-estado<?= $activoAqui?' m6-estado--activo':' admin-status--neutral m6-estado--inactivo' ?>"><?= $activoAqui?'Activo':(empty($a['activo'])?'Inactivo':'Inactivo aquí') ?></span></td>
<td class="m6-tabla__accion"><a class="admin-btn admin-btn--secondary admin-btn--small" href="<?= $esc($fichaUrl) ?>">Abrir<span class="admin-visually-hidden"> <?= $esc($a['nombre']) ?></span></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php $pagPagina=$pagina;$pagTotal=(int)$paginas;$pagEtiqueta='Páginas de artículos';$pagUrl=static fn(int $p):string=>$urlLista(['pagina'=>$p>1?$p:null]);include __DIR__.'/../partials/_pagination.php'; ?></section>
<?php if($pendientes): ?><details class="admin-panel admin-card m2-disclosure" id="preparaciones-anteriores" <?= !empty($alertas['error'])?'open':'' ?>><summary>Preparaciones anteriores por vincular <span class="admin-field__hint">· <?= count($pendientes) ?></span></summary><p>Relaciona cada preparación existente con su artículo. La asociación es explícita: no se fusionan registros por nombre. Después se edita desde el artículo y sus versiones.</p><form method="POST"><input type="hidden" name="m2_csrf" value="<?= $esc($csrf) ?>"><fieldset class="m2-fieldset" <?= $puedeEditar?'':'disabled' ?>><div class="admin-form-grid"><label class="admin-field">Preparación existente<select name="subreceta_id" required><option value="">Elige la preparación</option><?php foreach($pendientes as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $esc($p['nombre'].' · '.$p['rendimiento'].' '.$p['unidad']) ?></option><?php endforeach; ?></select></label><label class="admin-field">Artículo que representa<select name="articulo_id" required><option value="">Elige el artículo</option><?php foreach($opcionesArticulos as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $esc($a['nombre'].' · '.$a['unidad']) ?></option><?php endforeach; ?></select></label></div><label><input type="checkbox" name="confirmar" value="1" required> Confirmo que la preparación corresponde al artículo elegido y conservaré sus versiones.</label><?php if($puedeEditar): ?><p><button class="admin-btn admin-btn--secondary">Vincular preparación existente</button></p><?php endif; ?></fieldset></form></details><?php endif; ?>
</section>
<?php if($puedeCrearArticulo): ?>
<div class="admin-modal m6-alta-modal" id="articulo-alta" data-admin-modal hidden>
  <div class="admin-modal__backdrop" data-admin-modal-close></div>
  <section class="admin-modal__dialog admin-modal__dialog--wide" data-admin-modal-dialog role="dialog" aria-modal="true" aria-labelledby="articulo-alta-title" tabindex="-1">
    <div class="admin-modal__head"><div><h2 class="admin-modal__title" id="articulo-alta-title">Nuevo artículo</h2><p class="admin-modal__text">Un solo paso: al crearlo ya se puede usar en recetas.</p></div><button class="admin-modal__close" type="button" aria-label="Cerrar" data-admin-modal-close><?= admin_icon('cerrar', 18) ?></button></div>
    <?php $identidad=null;$almacenesMarcados=[];include __DIR__.'/_articulo-alta.php'; ?>
  </section>
</div>
<?php endif; ?>
