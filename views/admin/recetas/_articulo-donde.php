<?php
/**
 * «Dónde se usa» (arquitectura §18): módulo informativo de la ficha. Lee la
 * composición vigente —la que hoy descuenta el almacén— y dice si cada producto se
 * prepara al momento o se revende. En Global es el consolidado de los negocios.
 * Sólo consulta: los enlaces existen donde la persona tiene acceso.
 *
 * Espera $uso (Articulos::dondeSeUsa), $id, $ctx, $esc y las bases de la ficha.
 */
// Las pastillas de almacén y el botón de asociar llevan iconos del catálogo.
require_once __DIR__ . '/../partials/_icons.php';
$uso=$uso??['visible'=>false,'consolidado'=>false,'productos'=>[],'preparaciones'=>[],'variantes'=>[],'almacenes'=>null,'recortado'=>false,'resumen'=>['recetas'=>0,'reventa'=>0,'preparaciones'=>0,'variantes'=>0]];
$negocio=static fn(string $slug):string=>\Services\Security\Contextos::NOMBRES[$slug]??$slug;
// DECIMAL exacto: sólo se quitan los ceros de relleno para leerlo (0.150 → 0.15).
$num=static fn($v):string=>str_contains((string)$v,'.')?rtrim(rtrim((string)$v,'0'),'.'):(string)$v;
$plural=static fn(int $n,string $uno,string $varios):string=>$n===1?$uno:$varios;
$origenUso=($puedeProductos?$baseArticulos:$baseCatalogo).'/editar?id='.$id.'#usos';
// El regreso sólo viaja a pantallas de este mismo ámbito, que son las que lo validan.
$conVolver=static function(?string $url,string $slug) use($ctx,$origenUso,$baseArticulos):?string{
    if($url===null||($slug!==$ctx&&!str_starts_with($url,$baseArticulos.'/')))return $url;
    [$ruta,$ancla]=array_pad(explode('#',$url,2),2,null);
    return $ruta.(str_contains($ruta,'?')?'&':'?').http_build_query(['volver'=>$origenUso]).($ancla!==null?'#'.$ancla:'');
};
$r=$uso['resumen'];$almacenesUso=$uso['almacenes'];$almacenesActivos=is_array($almacenesUso)?count(array_filter($almacenesUso,static fn($a)=>!empty($a['activo']))):0;
$llevaExistencias=!empty($estadoEfectivo['configuracion']['almacenable']);
$sinUso=$uso['visible']&&!$uso['productos']&&!$uso['preparaciones']&&!$uso['variantes'];
$asociarUrl=$baseAlmacenes.'?'.http_build_query(['articulo_id'=>$id,'asociar'=>$id,'volver'=>\Services\Security\RetornoAdministrativo::autorizado($origenUso,$ctx)??$origenUso]);
?>
<section class="admin-panel admin-card m6-uso" id="usos" aria-labelledby="m6-uso-titulo">
<h2 id="m6-uso-titulo">Dónde se usa</h2>
<p class="admin-field__hint"><?= $uso['consolidado']?'Recetas vigentes de Restaurante y Panadería, y almacenes de toda la casa.':'Recetas vigentes de '.$esc($negocio($ctx)).' y sus almacenes.' ?> Es sólo consulta.</p>
<?php if($uso['visible']||is_array($almacenesUso)): ?><dl class="m6-uso__resumen">
<?php if($uso['visible']): ?><div><dt><?= $plural($r['recetas'],'Producto que lo prepara','Productos que lo preparan') ?></dt><dd><?= (int)$r['recetas'] ?></dd></div>
<div><dt><?= $plural($r['reventa'],'Producto de reventa','Productos de reventa') ?></dt><dd><?= (int)$r['reventa'] ?></dd></div>
<div><dt><?= $plural($r['preparaciones'],'Preparación','Preparaciones') ?></dt><dd><?= (int)$r['preparaciones'] ?></dd></div><?php endif; ?>
<?php if(is_array($almacenesUso)): ?><div><dt><?= $plural($almacenesActivos,'Almacén','Almacenes') ?></dt><dd><?= $almacenesActivos ?></dd></div><?php endif; ?>
</dl><?php endif; ?>
<?php if(!$uso['visible']): ?><p class="admin-field__hint">Ver las recetas que lo usan requiere permiso de Artículos y preparaciones.</p>
<?php elseif($sinUso): ?><div class="admin-empty"><strong>Todavía no está en ninguna receta</strong><p>Agrégalo como ingrediente desde la receta de un producto o desde otra preparación.</p></div>
<?php endif; ?>
<?php if($uso['productos']): ?><h3 id="m6-uso-productos">Productos del menú</h3>
<?php if($r['reventa']>0): ?><p class="admin-field__hint">En reventa el artículo se vende tal cual: cada venta lo descuenta directo del almacén, sin preparación.</p><?php endif; ?>
<div class="admin-table-wrap" tabindex="0" role="region" aria-labelledby="m6-uso-productos"><table class="admin-table admin-table--compact m6-uso__tabla"><thead><tr><th scope="col">Producto</th><?php if($uso['consolidado']): ?><th scope="col">Negocio</th><?php endif; ?><th scope="col">Cómo se vende</th><th scope="col" class="admin-table__num">Cantidad por venta</th></tr></thead><tbody>
<?php foreach($uso['productos'] as $p):$url=$conVolver($p['url'],$p['slug']);$reventa=$p['tipo_venta']==='reventa'; ?><tr>
<td data-label="Producto"><?php if($url): ?><a href="<?= $esc($url) ?>"><?= $esc($p['producto']) ?></a><?php else: ?><?= $esc($p['producto']) ?><?php endif; ?><?php if($p['origen']==='presentacion'&&(string)$p['ref_nombre']!==''): ?><p class="admin-field__hint">Presentación: <?= $esc($p['ref_nombre']) ?></p><?php endif; ?></td>
<?php if($uso['consolidado']): ?><td data-label="Negocio"><?= $esc($negocio($p['slug'])) ?></td><?php endif; ?>
<td data-label="Cómo se vende"><span class="admin-status m6-venta<?= $reventa?' admin-status--info m6-venta--reventa':' admin-status--neutral m6-venta--preparado' ?>"><?= $reventa?'Reventa':'Se prepara al momento' ?></span><?php if($p['tipo_venta_estado']!=='confirmado'): ?> <span class="admin-field__hint">por confirmar</span><?php endif; ?></td>
<td data-label="Cantidad por venta" class="admin-table__num"><?= $esc($num($p['cantidad']).' '.$p['unidad']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if($uso['preparaciones']): ?><h3>Preparaciones que lo llevan</h3><ul class="m6-uso__lista">
<?php foreach($uso['preparaciones'] as $e):$url=empty($e['anterior'])?$conVolver($e['url'],$e['slug']):$e['url']; ?><li><span><?php if($url): ?><a href="<?= $esc($url) ?>"><?= $esc($e['nombre']) ?></a><?php else: ?><?= $esc($e['nombre']) ?><?php endif; ?> <small><?= !empty($e['anterior'])?'Preparación anterior':'Versión '.(int)$e['version'] ?><?= $uso['consolidado']||$e['slug']!==$ctx?' · '.$esc($negocio($e['slug'])):'' ?></small></span><span class="m6-uso__cantidad"><?= $esc($num($e['cantidad']).' '.$e['unidad']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
<?php if($uso['variantes']): ?><h3>Variantes que lo ajustan</h3><ul class="m6-uso__lista">
<?php foreach($uso['variantes'] as $v):$url=$conVolver($v['url'],$v['slug']);$entra=(int)$v['articulo_nuevo_id']===$id;
    $accion=match($v['accion']){'agregar'=>'Lo agrega','quitar'=>'Lo quita','sustituir'=>$entra?'Lo pone en lugar de otro':'Lo sustituye por otro',default=>'Lo ajusta'};
    $cantidad=$v['accion']==='sustituir'&&$entra?$v['nueva_cantidad']:$v['cantidad']; ?><li><span><?php if($url): ?><a href="<?= $esc($url) ?>"><?= $esc($v['producto'].' · '.$v['nombre']) ?></a><?php else: ?><?= $esc($v['producto'].' · '.$v['nombre']) ?><?php endif; ?> <small><?= $accion ?><?= $uso['consolidado']?' · '.$esc($negocio($v['slug'])):'' ?></small></span><?php if($cantidad!==null): ?><span class="m6-uso__cantidad"><?= $esc($num($cantidad).' '.$v['unidad']) ?></span><?php endif; ?></li><?php endforeach; ?></ul><?php endif; ?>
<?php if($uso['recortado']): ?><p class="admin-field__hint">Se muestran las primeras sesenta referencias de cada grupo.</p><?php endif; ?>
<?php if(is_array($almacenesUso)): ?><h3>Almacenes</h3>
<?php if(!$llevaExistencias): ?><p>No lleva existencias en <?= $esc($negocio($ctx)) ?>: se usa en recetas sin descontar de un almacén.</p>
<?php elseif(!$almacenesUso): ?><p>Todavía no está asociado a ningún almacén<?= $uso['consolidado']?'':' de '.$esc($negocio($ctx)) ?>. Sin asociación no se pueden registrar sus entradas.</p>
<?php else:
    // Pastillas: cada una abre las existencias del artículo en ese almacén. En el
    // consolidado se agrupan bajo su negocio (ya vienen ordenadas por negocio y
    // nombre) en lugar de repetir el negocio junto a cada nombre.
    $enlaza=static fn(array $a):bool=>$a['slug']===$ctx||$uso['consolidado'];
    $gruposAlmacen=[];foreach($almacenesUso as $a)$gruposAlmacen[$uso['consolidado']?$a['slug']:$ctx][]=$a; ?>
<?php if(array_filter($almacenesUso,$enlaza)): ?><p class="admin-field__hint">Abre un almacén para ver las existencias de este artículo.</p><?php endif; ?>
<div class="m6-almacenes">
<?php foreach($gruposAlmacen as $slug=>$lista): ?><div class="m6-almacenes__grupo"<?= $uso['consolidado']?' role="group" aria-labelledby="m6-almacenes-'.$esc($slug).'"':'' ?>>
<?php if($uso['consolidado']): ?><p class="m6-almacenes__negocio" id="m6-almacenes-<?= $esc($slug) ?>"><?= $esc($negocio($slug)) ?></p><?php endif; ?>
<ul class="m6-almacenes__lista"><?php foreach($lista as $a):$inactivo=empty($a['activo']);$clase='m6-almacen'.($inactivo?' m6-almacen--inactivo':''); ?><li>
<?php if($enlaza($a)): ?><a class="<?= $clase ?>" href="<?= $esc($baseAlmacenes.'?'.http_build_query(['almacen_id'=>$a['id'],'articulo_id'=>$id,'volver'=>$origenUso])) ?>"><span class="m6-almacen__nombre"><?= $esc($a['nombre']) ?></span><?php if($inactivo): ?><small class="m6-almacen__estado"><span class="admin-visually-hidden">, </span>Asociación inactiva</small><?php endif; ?><span class="admin-visually-hidden">: ver existencias</span><?= admin_icon('chevron-derecha',14,'m6-almacen__icono') ?></a>
<?php else: ?><span class="<?= $clase ?> m6-almacen--fijo"><span class="m6-almacen__nombre"><?= $esc($a['nombre']) ?></span><?php if($inactivo): ?><small class="m6-almacen__estado">Asociación inactiva</small><?php endif; ?></span><?php endif; ?>
</li><?php endforeach; ?></ul></div>
<?php endforeach; ?></div><?php endif; ?>
<?php if($llevaExistencias&&!empty($puedeInventarioEditar)): ?><p class="admin-actions"><a class="admin-btn admin-btn--secondary" href="<?= $esc($asociarUrl) ?>"><?= admin_icon('mas',16,'admin-btn__icon') ?><?= $almacenesUso?'Asociar a otro almacén':'Asociar a un almacén' ?></a></p><?php elseif($llevaExistencias&&!$almacenesUso): ?><p class="admin-field__hint">Asociarlo requiere edición de Almacenes en <?= $esc($negocio($ctx)) ?>.</p><?php endif; ?>
<?php endif; ?>
</section>
