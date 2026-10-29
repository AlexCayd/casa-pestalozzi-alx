<?php
declare(strict_types=1);
/** Selección múltiple contra DDL real; solo crea y elimina su propio destino. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
require __DIR__.'/../multinegocio/SqlArchivo.php';require __DIR__.'/fixture-3b.php';
use Services\Inventory\AdministracionAlmacenes;
use Services\Inventory\Almacenes;
use Multinegocio\SqlArchivo;
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
$op=getopt('',['host:','base:','entorno:']);
foreach(['host'=>'DB_HOST','base'=>'DB_NAME','entorno'=>'APP_ENV'] as $key=>$env)if(($op[$key]??null)!==($_ENV[$env]??null))throw new RuntimeException('Identifica --host --base --entorno según configuración local.');
if(!in_array($op['entorno'],['development','test'],true))throw new RuntimeException('Solo desarrollo/pruebas.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],'',(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
$name='cp_multinegocio_test_'.bin2hex(random_bytes(10));$token=bin2hex(random_bytes(32));$created=false;$count=0;
$assert=static function(bool $ok,string $msg)use(&$count):void{if(!$ok)throw new RuntimeException($msg);$count++;};
$ok=static function(array $r,string $msg)use($assert):array{$assert(($r['ok']??false)===true&&($r['confirmado']??false)===true,$msg.' '.($r['mensaje']??$r['codigo']??''));return $r;};
try{
 $db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;$db->select_db($name);
 $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY)');$s=$db->prepare('INSERT INTO _cp_multinegocio_owner VALUES (?)');$s->execute([$token]);
 foreach(['ddl','deploy'] as $file)SqlArchivo::ejecutar($db,$root.'/database/'.$file.'.sql');
 Model\ActiveRecord::setDB($db);
 $actor=FixtureAutorizacion3B::usuario($db,'ux_super',[],true);
 $servicio=new AdministracionAlmacenes($db,$actor['id'],'contrasena');$motor=new Almacenes($db,$actor['id'],'contrasena');
 $vacio=$ok($motor->consultarExistencias('global',['almacen_ids'=>[]]),'Sin almacenes permite selección vacía');$assert($vacio['total']===0,'El estado inicial vacío no inventa saldos.');
 $alm=[];
 foreach(['central'=>'global','r1'=>'restaurante','r2'=>'restaurante','pan'=>'panaderia'] as $key=>$ctx){$data=['propietario'=>$ctx,'nombre'=>'UX '.$key];if($key==='r2')$data['permitir_negativos']='1';$r=$ok($servicio->guardar('global','crear_almacen',$data),'Crear almacén');$alm[$key]=(int)$r['almacen_id'];}
 $r=$ok($servicio->guardar('global','crear_articulo',['nombre'=>'UX exacto','unidad'=>'g','costo'=>'1.0000','activo'=>'1']),'Crear artículo');$art=(int)$r['articulo_id'];
 foreach(['central'=>'global','r1'=>'restaurante','r2'=>'restaurante','pan'=>'panaderia'] as $key=>$ctx)$ok($servicio->guardar($ctx,'asociar',['almacen_id'=>(string)$alm[$key],'articulo_id'=>(string)$art,'minimo'=>'3.000']),'Asociar sin saldo');
 foreach(['r1'=>'1.001','pan'=>'4.004'] as $key=>$cantidad)$ok($servicio->guardar($key==='pan'?'panaderia':'restaurante','entrada',['almacen_id'=>(string)$alm[$key],'articulo_id'=>(string)$art,'cantidad'=>$cantidad,'clave'=>'ux-entrada-'.$key]),'Entrada exacta');
 $asoc=$ok($motor->existencia('restaurante',$alm['r2'],$art),'Leer versión')['existencia'];
 $ok($servicio->guardar('restaurante','ajuste',['almacen_id'=>(string)$alm['r2'],'articulo_id'=>(string)$art,'cantidad'=>'-1.000','version'=>(string)$asoc['version'],'clave'=>'ux-negativo']),'Conteo negativo autorizado');
 $todos=$ok($motor->consultarExistencias('global'),'Global por defecto');
 $assert($todos['articulos'][0]['saldo_total']==='4.005','Global suma exactamente los tres ámbitos, incluyendo cero y negativo.');
 $par=$ok($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1'],$alm['r2']]]),'Seleccionar dos propios');
 $assert($par['articulos'][0]['saldo_total']==='0.001','Dos almacenes conservan DECIMAL exacto con negativos.');
 $assert($par['total_alertas']===1&&$par['articulos'][0]['alerta'],'Resumen y artículo comparten alertas por asociación.');
 $uno=$ok($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1']]]),'Seleccionar uno');$assert($uno['articulos'][0]['saldo_total']==='1.001','La selección cambia el total.');
 $duplicados=$ok($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1'],$alm['r1'],$alm['r2']]]),'Deduplicar IDs');$assert($duplicados['articulos'][0]['saldo_total']==='0.001','IDs repetidos no duplican saldo.');
 $mov=$ok($motor->consultarMovimientos('restaurante',['almacen_ids'=>[$alm['r2']]]),'Historial seleccionado');$assert($mov['total']===1&&$mov['movimientos'][0]['delta']==='-1.000','Historial conserva la selección exacta.');
 foreach([[],['mal'],[$alm['r1'],$alm['pan']],[$alm['r1'],999999],'1'] as $ids){foreach(['consultarExistencias','consultarMovimientos','consultarAlmacenes'] as $metodo)$assert($motor->$metodo('restaurante',['almacen_ids'=>$ids])['ok']===false,'Cada consulta rechaza selección vacía, inválida o ajena.');}
 $assert($motor->consultarExistencias('global',['almacen_ids'=>[999999]])['ok']===false,'Global tampoco acepta almacenes inexistentes.');
 $assert($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1']],'almacen_id'=>$alm['r2']])['ok']===false,'Filtros físicos ambiguos se rechazan.');
 $lector=FixtureAutorizacion3B::usuario($db,'ux_lector',['restaurante'=>['permisos'=>['inventario'=>'ver']]]);$lectura=new AdministracionAlmacenes($db,$lector['id'],'contrasena');
 $r=$lectura->consultar('restaurante',['almacen_ids'=>[$alm['r1'],$alm['r2']]]);$assert(count($r['opcionesAlmacen'])===2&&$r['existencias']['articulos'][0]['saldo_total']==='0.001','Solo lectura ve únicamente almacenes propios.');
 $assert($lectura->guardar('restaurante','entrada',['almacen_id'=>(string)$alm['r1'],'articulo_id'=>(string)$art,'cantidad'=>'1.000','clave'=>'ux-denegada'])['ok']===false,'Selección no concede escritura.');
 $sin=$ok($servicio->guardar('global','crear_articulo',['nombre'=>'UX sin asociación','unidad'=>'g','costo'=>'0.0000','activo'=>'1']),'Artículo sin asociación');
 $r=$ok($motor->consultarExistencias('global',['almacen_ids'=>[$alm['central']]]),'Global con selección cero');$assert($r['total']===2&&$r['articulos'][1]['ambitos']===[],'El listado Global conserva artículos sin asociación en la selección.');
 $r=$ok($motor->consultarExistencias('global',['almacen_ids'=>[$alm['pan']],'por_pagina'=>1,'pagina'=>2]),'Paginación servidor');$assert($r['total']===2&&count($r['articulos'])===1&&$r['articulos'][0]['articulo_id']===(int)$sin['articulo_id'],'Paginación estable bajo selección.');
 $soloPan=$ok($servicio->guardar('global','crear_articulo',['nombre'=>'UX solo Panadería','unidad'=>'g','costo'=>'0.0000','activo'=>'1']),'Identidad exclusiva de otro almacén');$panArt=(int)$soloPan['articulo_id'];
 $ok($servicio->guardar('panaderia','asociar',['almacen_id'=>(string)$alm['pan'],'articulo_id'=>(string)$panArt,'minimo'=>'20.000']),'Asociación exclusiva ajena a selección');
 $ok($servicio->guardar('panaderia','entrada',['almacen_id'=>(string)$alm['pan'],'articulo_id'=>(string)$panArt,'cantidad'=>'9.009','clave'=>'ux-exclusivo-pan']),'Saldo exclusivo fuera de selección');
 $fuera=$ok($motor->consultarExistencias('global',['almacen_ids'=>[$alm['r1']],'articulo_id'=>$panArt]),'Identidad maestra bajo selección sin asociación');
 $assert($fuera['total']===1&&$fuera['total_alertas']===0&&$fuera['articulos'][0]['saldo_total']==='0.000'&&$fuera['articulos'][0]['minimo_total']==='0.000'&&$fuera['articulos'][0]['ambitos']===[],'Global conserva identidad, pero nunca saldos, mínimos o alertas de almacenes excluidos.');
 $orden=[];
 foreach(['000 sin alerta','AAA con alerta','ZZZ con alerta'] as $nombre){
  $nuevo=$ok($servicio->guardar('global','crear_articulo',['nombre'=>$nombre,'unidad'=>'g','costo'=>'0.0000','activo'=>'1']),'Artículo para ordenar');$orden[$nombre]=(int)$nuevo['articulo_id'];
  if($nombre!=='000 sin alerta')$ok($servicio->guardar('restaurante','asociar',['almacen_id'=>(string)$alm['r1'],'articulo_id'=>(string)$nuevo['articulo_id'],'minimo'=>'2.000']),'Alerta propia para ordenar');
 }
 foreach([1=>$orden['AAA con alerta'],2=>$art,3=>$orden['ZZZ con alerta']] as $pagina=>$esperado){
  $prioridad=$ok($motor->consultarExistencias('global',['almacen_ids'=>[$alm['r1']],'por_pagina'=>1,'pagina'=>$pagina]),'Prioridad antes de paginar');
  $assert($prioridad['articulos'][0]['articulo_id']===$esperado&&$prioridad['articulos'][0]['alerta'],'Alertas primero y orden alfabético estable entre alertas.');
 }
 $otraSeleccion=$ok($motor->consultarExistencias('global',['almacen_ids'=>[$alm['pan']],'por_pagina'=>1]),'Orden según almacenes seleccionados');
 $assert($otraSeleccion['articulos'][0]['articulo_id']===$panArt,'Las alertas de un almacén excluido no conceden prioridad.');
 $busqueda=$ok($motor->consultarExistencias('global',['almacen_ids'=>[$alm['r1']],'q'=>'con alerta']),'Prioridad durante búsqueda');
 $assert(array_column($busqueda['articulos'],'articulo_id')===[$orden['AAA con alerta'],$orden['ZZZ con alerta']],'La búsqueda conserva el orden dentro del grupo de alertas.');
 echo "Almacenes selección: {$count} comprobaciones OK.\n";
}finally{
 if($created&&$name!==$op['base']&&preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name)){$mark=$db->query('SELECT token FROM `'.$name.'`._cp_multinegocio_owner')->fetch_row()[0]??'';if(!hash_equals($token,(string)$mark))throw new RuntimeException('Propiedad no verificada.');$db->query('DROP DATABASE `'.$name.'`');echo "Base temporal propia eliminada.\n";}$db->close();
}
