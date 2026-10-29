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
}
