<?php
declare(strict_types=1);
function probarMenuM11(mysqli $db,string $root,callable $a):void
{
    $a((int)$db->query('SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=3')->fetch_row()[0]===13,'Trece ofertas propias de Panadería');
    $a((int)$db->query('SELECT COUNT(*) FROM categorias WHERE unidad_id=3')->fetch_row()[0]===4,'Cuatro categorías locales naturales');
    $a((int)$db->query('SELECT COUNT(*) FROM recetas WHERE unidad_id=3')->fetch_row()[0]===1,'Sin nuevas recetas de producción');
    $pan=Services\Menu\MenuPdf::datosAdministrativos('panaderia','comida');
    $names=[];foreach($pan['categorias'] as $cat)foreach($cat['platillos'] as $p)$names[]=$p->nombre;
    $a(count($names)===12&&in_array('Rol de canela',$names,true),'PDF contiene doce ofertas disponibles propias');
    $a(!in_array('Pan de chocolate',$names,true)&&!in_array('Café con leche',$names,true),'PDF no incluye oferta inactiva ni Restaurante');
    $a($pan['pieza']['archivo']==='menu-panaderia-casa-pestalozzi.pdf','Nombre independiente del PDF');
    foreach(['inexistente','temporada','maridaje'] as $carta){$failed=false;try{Services\Menu\MenuPdf::datosAdministrativos('panaderia',$carta);}catch(Services\Inventory\InventarioError $e){$failed=$e->codigo==='CARTA_INVALIDA';}$a($failed,'Panadería rechaza carta sin documento '.$carta);}
    $rest=Services\Menu\MenuPdf::datosAdministrativos('restaurante','comida');
    $public=Services\Menu\Carta::publica('comida');$expected=[];
    foreach($public as $cat){$rows=[];foreach($cat['dishes'] as $p)$rows[]=[$p['n'],$p['d'],number_format($p['p'],2,'.','')];$expected[]=[$cat['label'],$rows];}
    $actual=[];foreach($rest['categorias'] as $cat){$rows=[];foreach($cat['platillos'] as $p)$rows[]=[$p->nombre,(string)$p->descripcion,number_format((float)$p->precio,2,'.','')];$actual[]=[$cat['nombre'],$rows];}
    $a($actual===$expected,'PDF administrativo Restaurante conserva exactamente carta pública');
    $docs=Services\Menu\MenuPdf::biblioteca();$a(count($docs)===3,'Global ofrece tres PDF disponibles');
    $db->begin_transaction();
    try{$db->query('UPDATE ofertas_producto SET activo=0 WHERE unidad_id=3');$failed=false;try{Services\Menu\MenuPdf::datosAdministrativos('panaderia','comida');}catch(Services\Inventory\InventarioError $e){$failed=$e->codigo==='CARTA_VACIA';}$a($failed,'No presentar PDF vacío terminado');$a(count(Services\Menu\MenuPdf::biblioteca())===2,'Biblioteca omite documento vacío');}
    finally{$db->rollback();}
    $reader=FixtureAutorizacion3B::usuario($db,'m11_global_menu',['global'=>['permisos'=>['menu'=>'ver']]]);
    $actor=$_SESSION['id'];$_SESSION['id']=$reader['id'];
    try{$a(Services\Menu\MenuPdf::biblioteca()===[],'Global no hereda lectura de cartas ajenas');$denied=false;try{Services\Menu\MenuPdf::datosAdministrativos('panaderia','comida');}catch(Services\Inventory\InventarioError){$denied=true;}$a($denied,'PDF verifica permiso propietario en servidor');}
    finally{$_SESSION['id']=$actor;}
    $super=$_SESSION['id'];$serv=new Services\Inventory\AdministracionAlmacenes($db,$super,'contrasena');
    $r=$serv->guardar('panaderia','crear_articulo',['nombre'=>'Artículo de aceptación M1.1','unidad'=>'pza','costo'=>'1.2345','activo'=>'1']);
    $a(!empty($r['ok'])&&!empty($r['confirmado']),'Alta rápida requiere y utiliza ambas autorizaciones');$article=(int)$r['articulo_id'];
    $a((int)$db->execute_query('SELECT COUNT(*) FROM almacen_articulos WHERE articulo_id=?',[$article])->fetch_row()[0]===0,'Alta rápida no inventa asociación ni stock');
    $r=$serv->guardar('panaderia','crear_articulo',['nombre'=>'Artículo de aceptación M1.1','unidad'=>'pza','costo'=>'1.2345','activo'=>'1']);
    $a(($r['codigo']??'')==='ARTICULO_DUPLICADO','Duplicado informa identidad existente');
    $local=FixtureAutorizacion3B::usuario($db,'m11_local',['panaderia'=>['permisos'=>['inventario'=>'editar']]]);
    $r=(new Services\Inventory\AdministracionAlmacenes($db,$local['id'],'contrasena'))->guardar('panaderia','crear_articulo',['nombre'=>'No autorizado','unidad'=>'pza','costo'=>'1.0000']);
    $a(empty($r['ok']),'Edición local no concede facultad Global');
    $global=FixtureAutorizacion3B::usuario($db,'m11_global',['global'=>['permisos'=>['inventario'=>'editar'],'facultades'=>['catalogo.editar']]]);
    $r=(new Services\Inventory\AdministracionAlmacenes($db,$global['id'],'contrasena'))->guardar('panaderia','crear_articulo',['nombre'=>'Sin ámbito local','unidad'=>'pza','costo'=>'1.0000']);
    $a(empty($r['ok']),'Facultad Global no sustituye permiso local');
    $warehouses=(new Services\Inventory\Almacenes($db,$super,'contrasena'))->consultarAlmacenes('restaurante');
    $a(count($warehouses['almacenes'])===3,'Tres almacenes propios sin cruces');
    $fridge=array_values(array_filter($warehouses['almacenes'],static fn($row)=>(int)$row['almacen_id']===1006))[0];
    $a((int)$fridge['total_articulos']>0&&(int)$fridge['agotados']>0&&(int)$fridge['bajo_minimo']>0,'Indicadores físicos distinguen artículos agotados y mínimos');
}
