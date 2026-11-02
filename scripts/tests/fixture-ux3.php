<?php
declare(strict_types=1);
/** Datos sintéticos reproducibles; únicamente sobre el harness identificado. */
require dirname(__DIR__,2).'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2).'/includes')->safeLoad();
if(PHP_SAPI!=='cli'||count($argv)!==3||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ARCHIVO_PRIVADO');
$f=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);$base=$f['base_temporal']??'';
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$base)||$base===($_ENV['DB_NAME']??''))throw new RuntimeException('Harness no identificado.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$base,(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
$m=$db->query('SELECT entorno,propietario_token FROM instalacion_desarrollo WHERE id=1')->fetch_assoc();$owner=$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0];
if($m['entorno']!=='test'||!hash_equals($owner,$m['propietario_token']))throw new RuntimeException('Base ajena al harness.');
use Services\Inventory\Articulos as A;
use Services\Menu\PresentacionesOferta as P;
use Services\Menu\ProductosCasa as PC;
use Services\Security\ContextoNegocio as C;
try{
 Model\ActiveRecord::setDB($db);session_start();$actor=(int)$db->query("SELECT id FROM usuarios WHERE username='admin_demo'")->fetch_row()[0];$_SESSION=['id'=>$actor,'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
 $motor=new Services\Inventory\Almacenes($db,$actor,'contrasena');$ids=[];
 foreach(['Masa UX3','Salsa UX3','Preparación incompleta UX3','Artículo sin existencias UX3'] as $nombre){$r=$motor->crearArticulo('global',['nombre'=>$nombre.' '.bin2hex(random_bytes(3)),'unidad'=>'g','costo'=>'0.0000']);if(empty($r['ok']))throw new RuntimeException('No se preparó artículo sintético.');$ids[]=(int)$r['articulo_id'];}
 [$masa,$salsa,$incompleto,$sinSaldo]=$ids;
 foreach([$masa,$salsa] as $id)A::configurar('global',$id,['componente'=>1,'elaborable'=>1,'almacenable'=>1,'activo'=>1],0);
 A::configurar('restaurante',$incompleto,['componente'=>1,'elaborable'=>1,'almacenable'=>0,'activo'=>1],0);
 A::configurar('panaderia',$sinSaldo,['comprable'=>1,'componente'=>1,'almacenable'=>1,'activo'=>1],0);
 $publicar=static function(int $id,array $raw):array{$f=A::ficha('global',$id);$p=A::prever('global',$id,$raw);$raw['huella']=$p['huella'];$r=A::publicar('global',$id,$raw,(int)$f['configuracion']['revision'],bin2hex(random_bytes(20)),true);if(empty($r['ok']))throw new RuntimeException('Elaboración sintética rechazada.');return $r;};
 $raw=['rendimiento'=>'100.000','unidad'=>'g','procedencia'=>'Ensayo UX3: versión inicial','componentes'=>[['articulo_id'=>1001,'cantidad'=>'100.000','unidad'=>'g','modalidad'=>'almacenado','merma'=>'0.000']]];
 $v1=$publicar($masa,$raw);$raw['componentes'][0]['cantidad']='200.000';$raw['procedencia']='Ensayo UX3: versión posterior';$v2=$publicar($masa,$raw);
 $b=$publicar($salsa,['rendimiento'=>'100.000','unidad'=>'g','procedencia'=>'Ensayo UX3: referencia a Masa versión 1','componentes'=>[['articulo_id'=>$masa,'cantidad'=>'50.000','unidad'=>'g','modalidad'=>'desglosar','version_receta_id'=>$v1['version_id'],'merma'=>'0.000'],['articulo_id'=>1002,'cantidad'=>'10.000','unidad'=>'ml','modalidad'=>'almacenado','merma'=>'0.000']]]);
 $ofertas=[];$destinos=[];
 foreach(['restaurante','panaderia'] as $ctx){$u=C::unidad($ctx);$o=$db->execute_query('SELECT categoria_id,area_id FROM ofertas_producto WHERE unidad_id=? LIMIT 1',[$u])->fetch_assoc();$db->execute_query('INSERT INTO productos(nombre) VALUES(?)',['Producto nuevo UX3 '.ucfirst($ctx)]);$pid=(int)$db->insert_id;$db->execute_query('INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo) VALUES(?,?,?,?,?,0)',[$pid,$u,$o['categoria_id'],'80.00',$o['area_id']]);$ofertas[$ctx]=(int)$db->insert_id;$destinos[$ctx]=['categoria_id'=>$o['categoria_id'],'area_id'=>$o['area_id'],'precio'=>'40.00','activo'=>0];}
 $r=PC::crear(['nombre'=>'Estándar UX3','descripcion'=>'Configuración canónica y adaptación local del harness.','precio'=>'40.00','activo'=>0,'reglas'=>['receta'=>1,'precio'=>1,'activo'=>1],'negocios'=>$destinos],bin2hex(random_bytes(20)));$estandar=(int)$r['producto_id'];$v=PC::vistaPrevia($estandar);PC::publicar($estandar,1,$v['huella'],bin2hex(random_bytes(20)),true);
 $oficial=['clave'=>'pieza_ux3','nombre'=>'Pieza UX3','precio'=>'40.00','activo'=>0,'componentes'=>[['articulo_id'=>$salsa,'cantidad'=>'10.000','unidad'=>'g','modalidad'=>'desglosar','version_receta_id'=>$b['version_id']]]];
 $v=P::previaEstandar($estandar);P::publicarEstandar($estandar,$oficial,$v['huella'],bin2hex(random_bytes(20)),true);
 $local=[];foreach(PC::ofertas($estandar) as $o){$lista=P::listar($o['slug'],(int)$o['id']);$local[$o['slug']]=['oferta'=>(int)$o['id'],'presentacion'=>(int)$lista[0]['id'],'version'=>(int)$lista[0]['version_id']];if($o['slug']==='panaderia'){$adaptada=$oficial;$adaptada['precio']='45.00';$adaptada['componentes']=[['articulo_id'=>1001,'cantidad'=>'30.000','unidad'=>'g','modalidad'=>'almacenado']];P::publicar('panaderia',(int)$o['id'],$adaptada,1,bin2hex(random_bytes(20)),true);}}
 $data=['masa'=>$masa,'salsa'=>$salsa,'incompleto'=>$incompleto,'sin_saldo'=>$sinSaldo,'masa_v1'=>(int)$v1['version_id'],'masa_v2'=>(int)$v2['version_id'],'salsa_v1'=>(int)$b['version_id'],'ofertas'=>$ofertas,'estandar'=>$estandar,'referencia'=>'pieza_ux3','destinos'=>$local];
 file_put_contents(dirname($argv[2]).'/escenario.json',json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}finally{if(session_status()===PHP_SESSION_ACTIVE)session_destroy();$db->close();}
