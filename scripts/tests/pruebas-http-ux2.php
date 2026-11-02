<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';require __DIR__.'/cliente-http-3e.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2).'/includes')->safeLoad();
if(PHP_SAPI!=='cli'||count($argv)!==3||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ARCHIVO_PRIVADO');
$f=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);$base=$f['base_temporal']??'';
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$base)||$base===($_ENV['DB_NAME']??''))throw new RuntimeException('Harness no identificado.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$base,(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
$mark=$db->query('SELECT entorno,propietario_token FROM instalacion_desarrollo WHERE id=1')->fetch_assoc();$owner=$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0];
if(($mark['entorno']??'')!=='test'||!hash_equals((string)$mark['propietario_token'],(string)$owner))throw new RuntimeException('Base fuera del harness.');
use Services\Security\ContextoNegocio as C;
use Services\Menu\PresentacionesOferta as P;
$n=0;$clients=[];$permisosAntes=[];$a=static function(bool $ok,string $m)use(&$n){if(!$ok)throw new RuntimeException($m);$n++;};
$valid=static fn($r)=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
try{
 Model\ActiveRecord::setDB($db);session_start();$actor=(int)$db->query("SELECT id FROM usuarios WHERE username='admin_demo'")->fetch_row()[0];$_SESSION=['id'=>$actor,'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
 $saldo=hash('sha256',json_encode($db->query('SELECT * FROM almacen_articulos ORDER BY almacen_id,articulo_id')->fetch_all(MYSQLI_ASSOC)));$mov=(int)$db->query('SELECT COUNT(*) FROM movimientos_inventario')->fetch_row()[0];
 $casos=[];
 foreach(['restaurante','panaderia'] as $ctx){
  $ficha=Services\Inventory\Articulos::ficha($ctx,1001);
  Services\Inventory\Articulos::configurar($ctx,1001,['componente'=>1,'comprable'=>1,'almacenable'=>1,'activo'=>1],(int)$ficha['configuracion']['revision']);
  $u=C::unidad($ctx);$o=$db->execute_query('SELECT categoria_id,area_id FROM ofertas_producto WHERE unidad_id=? LIMIT 1',[$u])->fetch_assoc();
  $db->execute_query('INSERT INTO productos(nombre) VALUES(?)',['Producto UX2 '.ucfirst($ctx)]);$producto=(int)$db->insert_id;
  $db->execute_query('INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo) VALUES(?,?,?,?,?,0)',[$producto,$u,$o['categoria_id'],'50.00',$o['area_id']]);$oferta=(int)$db->insert_id;
  $ids=[];foreach(['pieza','doble'] as $clave){$r=P::publicar($ctx,$oferta,['clave'=>$clave,'nombre'=>ucfirst($clave),'precio'=>'50.00','activo'=>0,'componentes'=>[['articulo_id'=>1001,'cantidad'=>'10.000','unidad'=>'g','modalidad'=>'almacenado']]],0,'ux2_'.bin2hex(random_bytes(16)),true);$a(!empty($r['ok']),'Preparación sintética fuera de venta');$ids[]=(int)$r['presentacion_id'];}
  $casos[$ctx]=['oferta'=>$oferta,'presentaciones'=>$ids];
 }
 // Solo las cuentas de esta base identificada: perfiles de prueba, sin facultades Global.
 foreach(['global','restaurante','panaderia'] as $ctx)foreach(['lectura'=>'ver','operador'=>'editar'] as $tipo=>$nivel){$cuenta=$f['cuentas'][$tipo.'_'.$ctx];$u=C::unidad($ctx);$antes=$db->execute_query('SELECT nivel FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$cuenta['id'],$u,'productos'])->fetch_row();$permisosAntes[]=[$cuenta['id'],$u,$antes[0]??null];$db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)',[$cuenta['id'],$u,'productos',$nivel]);}
 session_destroy();
 $admin=new ClienteInstalacion3E();$clients[]=$admin;$admin->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']]);
 foreach($casos as $ctx=>$caso){
  $prefix=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;$volver=$prefix.'/menu?q=UX2&page=2';$urls=[];
  foreach($caso['presentaciones'] as $pid){
   $path=$prefix.'/menu/presentaciones?'.http_build_query(['id'=>$caso['oferta'],'presentacion'=>$pid,'volver'=>$volver]);$page=$admin->request('GET',$path);$a($valid($page),'Abrir presentación seleccionada');
   $datos=ClienteInstalacion3E::form($page['body'],'origenes');$datos['origenes']=[1001=>$ctx==='panaderia'?1003:1002];$r=$admin->request('POST',$path,$datos);$a($r['status']===303,'Guardar orígenes mediante PRG');
   parse_str(parse_url($r['location'],PHP_URL_QUERY),$q);$a((int)$q['presentacion']===$pid&&(int)$q['id']===$caso['oferta']&&$q['volver']===$volver&&str_ends_with($r['location'],'#origenes'),'Conservar oferta, presentación, filtro y sección');$urls[]=$r['location'];
  }
  // Guardados en dos recorridos antes de consumir sus resultados.
  foreach(array_reverse($urls) as $url){$page=$admin->request('GET',$url);$a($valid($page)&&str_contains($page['body'],'Orígenes de consumo guardados correctamente.'),'Resultado correcto en cada pestaña');$a(!str_contains($admin->request('GET',$url)['body'],'Orígenes de consumo guardados correctamente.'),'Resultado de un solo uso');}
  $path=$prefix.'/menu/presentaciones?id='.$caso['oferta'];
  $editar=$prefix.'/menu/edit?'.http_build_query(['id'=>$caso['oferta'],'volver'=>$volver]);
  $page=$admin->request('GET',$path.'&volver='.urlencode($editar));$xp=ClienteInstalacion3E::dom($page['body']);$a($xp->query('//a[@href="'.$editar.'" and normalize-space(.)="Volver al producto"]')->length===1,'Retorno al producto sin duplicar la ficha en la cadena');
  $a($admin->request('GET',$path.'&presentacion[]=1')['status']===400,'Presentación estructurada rechazada');
  $otro=$casos[$ctx==='panaderia'?'restaurante':'panaderia']['presentaciones'][0];$a($admin->request('GET',$path.'&presentacion='.$otro)['status']===404,'Presentación ajena rechazada');
  foreach(['https://example.com','/admin/global/productos','/admin/menu/edit?id=999999'] as $bad){$page=$admin->request('GET',$path.'&volver='.urlencode($bad));$xp=ClienteInstalacion3E::dom($page['body']);$a($xp->query('//a[@href="'.$bad.'"]')->length===0,'Retorno inseguro omitido');}
 }
 foreach(['global','restaurante','panaderia'] as $ctx){
  $prefix=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;
  foreach(['lectura','operador'] as $tipo){$c=new ClienteInstalacion3E();$clients[]=$c;$c->login($f['cuentas'][$tipo.'_'.$ctx]);
   $r=$c->request('GET',$prefix);$xp=ClienteInstalacion3E::dom($r['body']);$a($valid($r)&&$xp->query('//main//a[@href="'.$prefix.'/recetas/articulos"]')->length===1,'Inicio con tarjeta autorizada '.$tipo.' '.$ctx);
   $r=$c->request('GET',$prefix.'/recetas/articulos');$a($valid($r)&&!str_contains($r['body'],'Alta de artículo en Almacenes'),'Sin alta maestra engañosa');
   $xp=ClienteInstalacion3E::dom($r['body']);$a($xp->query('//a[@aria-current="page" and @href="'.$prefix.'/recetas/articulos"]')->length===1,'Módulo activo coherente');
   $r=$c->request('GET',$prefix.'/recetas/articulos/editar?id=1001');$a($valid($r),'Ficha existente accesible');
   if($tipo==='lectura'){$data=ClienteInstalacion3E::form($r['body'],'configurar');$a(!str_contains($r['body'],'>Guardar capacidades<'),'Lectura sin acción de edición');$a(in_array($c->request('POST',$prefix.'/recetas/articulos/editar?id=1001',$data)['status'],[403,303],true),'POST de lectura rechazado');}
   elseif($ctx!=='global'){$data=ClienteInstalacion3E::form($r['body'],'configurar');$a($c->request('POST',$prefix.'/recetas/articulos/editar?id=1001',$data)['status']===303,'Edición local conserva configuración autorizada');$a(in_array($c->request('GET','/admin/global/recetas/articulos')['status'],[403,303],true),'Local sin facultad Global');}
   if($ctx==='restaurante'){$r=$c->request('GET','/admin/recetas/subrecetas');$a($valid($r)&&str_contains($r['body'],'Preparaciones anteriores'),'Compatibilidad del listado anterior');if($tipo==='lectura')$a(!str_contains($r['body'],'admin-create-button')&&!str_contains($r['body'],'data-confirm-delete'),'Lectura anterior sin enlaces de mutación');}
  }
 }
 $partial=new ClienteInstalacion3E();$clients[]=$partial;$partial->login($f['cuentas']['administrador_global']);
 // La cuenta parcial original no recibió productos: se conserva el contraste de acceso.
 $r=$partial->request('GET','/admin/global/recetas/articulos');$a(in_array($r['status'],[403,303],true),'Permiso parcial sin módulo de artículos');
 foreach($casos as $ctx=>$caso){$prefix=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;$a($valid($admin->request('GET',$prefix.'/menu/presentaciones?id='.$caso['oferta'].'&presentacion='.$caso['presentaciones'][0])),'Presentación sigue consultable tras configuración local');}
 $a($saldo===hash('sha256',json_encode($db->query('SELECT * FROM almacen_articulos ORDER BY almacen_id,articulo_id')->fetch_all(MYSQLI_ASSOC)))&&$mov===(int)$db->query('SELECT COUNT(*) FROM movimientos_inventario')->fetch_row()[0],'Sin cambios de saldos ni movimientos');
 echo 'Evidencia sintética: '.json_encode($casos,JSON_UNESCAPED_UNICODE)."\n";
 echo "UX2 HTTP: {$n} comprobaciones OK.\n";
}finally{foreach($clients as $c)$c->close();foreach($permisosAntes as [$id,$u,$nivel]){if($nivel===null)$db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$id,$u,'productos']);else $db->execute_query('UPDATE usuario_unidad_permisos SET nivel=? WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$nivel,$id,$u,'productos']);}if(session_status()===PHP_SESSION_ACTIVE)session_destroy();$db->close();}
