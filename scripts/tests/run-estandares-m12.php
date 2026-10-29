<?php
require __DIR__.'/soporte-m12.php';
use Services\Menu\ProductosCasa as P;
use Services\Security\ContextoNegocio as C;
m12Pruebas(static function(mysqli $db,string $root,callable $a){
    $no=static function(callable $fn,string $m)use($a){try{$r=$fn();$ok=is_array($r)&&empty($r['ok']);}catch(Throwable){$ok=true;}$a($ok,$m);};
    $super=$_SESSION['id'];$antes=$db->query('SELECT * FROM productos WHERE id<1000 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $d=['nombre'=>'Producto estándar de prueba','descripcion'=>'Preparación compartida.','precio'=>'42.35','activo'=>1,'tipo'=>'compartido','reglas'=>['descripcion'=>true,'img'=>true,'precio'=>true],'negocios'=>['restaurante'=>['categoria_id'=>1001,'area_id'=>1,'precio'=>'43.10','activo'=>1],'panaderia'=>['categoria_id'=>1002,'area_id'=>10,'precio'=>'39.20','activo'=>1]]];
    // Resolver áreas reales, no asumir que los IDs de otro fixture son locales.
    foreach($d['negocios'] as $ctx=>&$n){$u=C::unidad($ctx);$n['categoria_id']=(int)$db->query('SELECT id FROM categorias WHERE unidad_id='.$u.' LIMIT 1')->fetch_row()[0];$n['area_id']=(int)$db->query('SELECT id FROM areas_produccion WHERE unidad_id='.$u.' LIMIT 1')->fetch_row()[0];}unset($n);
    $r=P::crear($d,'crear_m12_productos_0001');$a(!empty($r['ok']),'Crear borrador Global');$id=(int)$r['producto_id'];
    $r2=P::crear($d,'crear_m12_productos_0001');$a($r2['producto_id']===$id,'Reintento no duplica identidad');
    $a(P::publicada($id)===null&&P::ofertas($id)===[],'Borrador no crea ofertas');
    $v=P::vistaPrevia($id);$no(fn()=>P::publicar($id,1,$v['huella'],'publicar_m12_productos_01',false),'Publicar exige confirmación');
    $r=P::publicar($id,1,$v['huella'],'publicar_m12_productos_01',true);$a(!empty($r['ok']),'Publicación conjunta confirmada');
    $re=P::publicar($id,1,$v['huella'],'publicar_m12_productos_01',true);$a($re['publicacion_id']===$r['publicacion_id'],'Publicación idempotente');
    $offers=P::ofertas($id);$a(count($offers)===2&&count(array_unique(array_column($offers,'producto_id')))===1,'Dos ofertas, una identidad');
    $pan=array_values(array_filter($offers,fn($o)=>$o['slug']==='panaderia'))[0];$oid=(int)$pan['id'];
    $local=FixtureAutorizacion3B::usuario($db,'m12_local',['panaderia'=>['permisos'=>['menu'=>'editar']]]);$_SESSION['id']=$local['id'];
    $r=P::guardarLocal('panaderia',$oid,['descripcion'=>'','precio'=>'48.21','img'=>'/build/images/local-m12.webp']);$a(!empty($r['ok']),'Personalización autorizada '.json_encode($r));
    $vista=$db->execute_query('SELECT * FROM productos_oferta WHERE oferta_id=?',[$oid])->fetch_assoc();
    $a($vista['descripcion']===''&&$vista['img']==='/build/images/local-m12.webp','Vacío explícito e imagen local resueltos por la vista');
    $no(fn()=>P::guardarLocal('panaderia',$oid,['nombre'=>'Manipulado']),'POST protegido rechazado');
    $no(fn()=>P::guardarLocal('restaurante',$oid,['precio'=>'1.00']),'Oferta extranjera rechazada');
    $no(fn()=>P::crear($d,'crear_sin_facultad_m12_1'),'Local no estandariza');
    $a(!empty(P::guardarLocal('panaderia',$oid,[],['descripcion'])['ok']),'Retirar personalización');
    $a($db->execute_query('SELECT descripcion FROM productos_oferta WHERE oferta_id=?',[$oid])->fetch_row()[0]===$d['descripcion'],'Volver al oficial sin copiar valor');
    $_SESSION['id']=$super;$v=P::vistaPrevia($id);$d['precio']='51.99';$d['reglas']['precio']=false;$d['reglas']['nombre']=false;
    $a(!empty(P::guardar($id,$d,(int)$v['configuracion']['revision'])['ok']),'Editar borrador');$v=P::vistaPrevia($id);$r=P::publicar($id,(int)$v['configuracion']['revision'],$v['huella'],'publicar_m12_productos_02',true);$a(!empty($r['ok']),'Revocar precio local mediante publicación');
    $a(array_unique(array_column(P::ofertas($id),'precio'))===['51.99'],'Precio oficial aplicado a ambos negocios');
    $a(P::locales($oid)['img']==='/build/images/local-m12.webp','Imagen autorizada se conserva');
    $a((int)$db->execute_query('SELECT COUNT(*) FROM historial_precios WHERE oferta_id=?',[$oid])->fetch_row()[0]>=3,'Historial de importes conservado');
    $v=P::vistaPrevia($id);P::guardarLocal('panaderia',$oid,['descripcion'=>'Cambio concurrente']);$no(fn()=>P::publicar($id,(int)$v['configuracion']['revision'],$v['huella'],'publicar_m12_conflicto_03',true),'Huella detecta cambio local concurrente');
    $bad=$d;$bad['negocios']['panaderia']['categoria_id']=$d['negocios']['restaurante']['categoria_id'];$v=P::vistaPrevia($id);P::guardar($id,$bad,(int)$v['configuracion']['revision']);$v=P::vistaPrevia($id);$n=(int)$db->query('SELECT COUNT(*) FROM producto_publicaciones')->fetch_row()[0];
    $no(fn()=>P::publicar($id,(int)$v['configuracion']['revision'],$v['huella'],'publicar_m12_rollback_04',true),'Categoría ajena revierte publicación');$a((int)$db->query('SELECT COUNT(*) FROM producto_publicaciones')->fetch_row()[0]===$n,'Rollback sin publicación parcial');
    $a($antes===$db->query('SELECT * FROM productos WHERE id<1000 ORDER BY id')->fetch_all(MYSQLI_ASSOC),'112 identidades originales intactas');
    foreach(['restaurante','panaderia'] as $ctx){$ex=$d;$ex['tipo']='exclusivo';$ex['negocios']=[$ctx=>$d['negocios'][$ctx]];$ex['nombre']='Exclusivo '.$ctx;$r=P::crear($ex,'exclusivo_m12_'.$ctx);$v=P::vistaPrevia((int)$r['producto_id']);$a(!empty(P::publicar((int)$r['producto_id'],1,$v['huella'],'publicacion_exclusivo_'.$ctx,true)['ok']),'Exclusivo '.$ctx);$otro=$ctx==='restaurante'?'panaderia':'restaurante';$no(fn()=>Services\Menu\Ofertas::crear((int)$r['producto_id'],$otro,$d['negocios'][$otro]),'No asociar exclusivo al otro negocio');}
    $race=m12Carrera($db,'crear',[$ex,'crear_concurrente_m12_0001']);$a(!empty($race[0]['ok'])&&!empty($race[1]['ok'])&&$race[0]['producto_id']===$race[1]['producto_id'],'Dos procesos no duplican identidad');
    $rid=(int)$race[0]['producto_id'];$v=P::vistaPrevia($rid);$race=m12Carrera($db,'producto',[$rid,1,$v['huella'],'publicacion_concurrente_m12',true]);
    $a(!empty($race[0]['ok'])&&!empty($race[1]['ok'])&&$race[0]['publicacion_id']===$race[1]['publicacion_id'],'Dos publicaciones simultáneas tienen una única versión e idempotencia');
});
