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
 foreach(['central'=>'global','r1'=>'restaurante','r2'=>'restaurante','pan'=>'panaderia'] as $key=>$ctx){$data=['propietario'=>$ctx,'nombre'=>'UX '.$key];$r=$ok($servicio->guardar('global','crear_almacen',$data),'Crear almacén');$alm[$key]=(int)$r['almacen_id'];}
 $r=$ok($servicio->guardar('global','crear_articulo',['nombre'=>'UX exacto','unidad'=>'g','costo'=>'1.0000','activo'=>'1']),'Crear artículo');$art=(int)$r['articulo_id'];
 foreach(['central'=>'global','r1'=>'restaurante','r2'=>'restaurante','pan'=>'panaderia'] as $key=>$ctx)$ok($servicio->guardar($ctx,'asociar',['almacen_id'=>(string)$alm[$key],'articulo_id'=>(string)$art,'minimo'=>'3.000']),'Asociar sin saldo');
 foreach(['r1'=>'1.001','pan'=>'4.004'] as $key=>$cantidad)$ok($servicio->guardar($key==='pan'?'panaderia':'restaurante','entrada',['almacen_id'=>(string)$alm[$key],'articulo_id'=>(string)$art,'cantidad'=>$cantidad,'clave'=>'ux-entrada-'.$key]),'Entrada exacta');
 $ok($servicio->guardar('restaurante','entrada',['almacen_id'=>(string)$alm['r2'],'articulo_id'=>(string)$art,'cantidad'=>'0.500','clave'=>'ux-entrada-r2']),'Entrada exacta en el segundo almacén');
 $assert($servicio->guardar('restaurante','ajuste',['almacen_id'=>(string)$alm['r2'],'articulo_id'=>(string)$art,'cantidad'=>'9.000','version'=>'1','clave'=>'ux-conteo-retirado'])['codigo']==='ACCION_INVALIDA','El conteo físico se retiró: «ajuste» ya no es una operación.');
 $todos=$ok($motor->consultarExistencias('global'),'Global por defecto');
 $assert($todos['articulos'][0]['saldo_total']==='5.505','Global suma exactamente los tres ámbitos, incluyendo cero.');
 $par=$ok($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1'],$alm['r2']]]),'Seleccionar dos propios');
 $assert($par['articulos'][0]['saldo_total']==='1.501','Dos almacenes conservan DECIMAL exacto.');
 $assert($par['total_alertas']===1&&$par['articulos'][0]['alerta'],'Resumen y artículo comparten alertas por asociación.');
 $uno=$ok($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1']]]),'Seleccionar uno');$assert($uno['articulos'][0]['saldo_total']==='1.001','La selección cambia el total.');
 $duplicados=$ok($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1'],$alm['r1'],$alm['r2']]]),'Deduplicar IDs');$assert($duplicados['articulos'][0]['saldo_total']==='1.501','IDs repetidos no duplican saldo.');
 $mov=$ok($motor->consultarMovimientos('restaurante',['almacen_ids'=>[$alm['r2']]]),'Historial seleccionado');$assert($mov['total']===1&&$mov['movimientos'][0]['delta']==='0.500','Historial conserva la selección exacta.');
 foreach([[],['mal'],[$alm['r1'],$alm['pan']],[$alm['r1'],999999],'1'] as $ids){foreach(['consultarExistencias','consultarMovimientos','consultarAlmacenes'] as $metodo)$assert($motor->$metodo('restaurante',['almacen_ids'=>$ids])['ok']===false,'Cada consulta rechaza selección vacía, inválida o ajena.');}
 $assert($motor->consultarExistencias('global',['almacen_ids'=>[999999]])['ok']===false,'Global tampoco acepta almacenes inexistentes.');
 $assert($motor->consultarExistencias('restaurante',['almacen_ids'=>[$alm['r1']],'almacen_id'=>$alm['r2']])['ok']===false,'Filtros físicos ambiguos se rechazan.');
 $lector=FixtureAutorizacion3B::usuario($db,'ux_lector',['restaurante'=>['permisos'=>['inventario'=>'ver']]]);$lectura=new AdministracionAlmacenes($db,$lector['id'],'contrasena');
 $r=$lectura->consultar('restaurante',['almacen_ids'=>[$alm['r1'],$alm['r2']]]);$assert(count($r['opcionesAlmacen'])===2&&$r['existencias']['articulos'][0]['saldo_total']==='1.501','Solo lectura ve únicamente almacenes propios.');
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
 // Asociar y dar entrada alcanzan los almacenes de todo negocio donde el actor edita (§5).
 $n=static fn(int $w,int $a):int=>(int)$db->query("SELECT COUNT(*) FROM almacen_articulos WHERE almacen_id=$w AND articulo_id=$a")->fetch_row()[0];
 $r=$ok($servicio->guardar('global','crear_articulo',['nombre'=>'UX multi','unidad'=>'g','costo'=>'1.0000','activo'=>'1','almacen_ids'=>[(string)$alm['central'],(string)$alm['r1'],(string)$alm['pan']]]),'Alta con almacenes');$multi=(int)$r['articulo_id'];
 $assert($r['almacenes_asociados']===[$alm['central'],$alm['r1'],$alm['pan']]&&$n($alm['central'],$multi)===1&&$n($alm['r1'],$multi)===1&&$n($alm['pan'],$multi)===1&&$n($alm['r2'],$multi)===0,'El alta asocia el artículo a los almacenes marcados de varios negocios.');
 $assert((int)$db->query("SELECT COUNT(*) FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id WHERE aa.articulo_id=$multi AND (aa.unidad_id<>a.unidad_id OR aa.saldo<>0)")->fetch_row()[0]===0,'Cada asociación lleva la unidad dueña de su almacén y empieza sin saldo.');
 $antes=(int)$db->query('SELECT COUNT(*) FROM ingredientes')->fetch_row()[0];
 $r=$servicio->guardar('global','crear_articulo',['nombre'=>'UX multi roto','unidad'=>'g','costo'=>'1.0000','activo'=>'1','almacen_ids'=>[(string)$alm['central'],'999999']]);
 $assert(($r['ok']??true)===false&&(int)$db->query('SELECT COUNT(*) FROM ingredientes')->fetch_row()[0]===$antes,'Un almacén inválido en el alta no deja ni el artículo.');
 $ok($servicio->guardar('global','asociar',['articulo_id'=>(string)$multi,'almacen_ids'=>[(string)$alm['r2']],'minimo'=>'2.000']),'Asociar un almacén de otro negocio desde Global');
 $assert($n($alm['r2'],$multi)===1,'Global asocia en Restaurante cuando el actor edita Restaurante.');
 $ok($servicio->guardar('global','entrada',['almacen_id'=>(string)$alm['r2'],'articulo_id'=>(string)$multi,'cantidad'=>'3.250','clave'=>'ux-entrada-cruzada']),'Entrada en almacén de otro negocio');
 $assert((string)$db->query("SELECT CONCAT(m.unidad_id,':',m.delta) FROM movimientos_inventario m WHERE m.almacen_id={$alm['r2']} AND m.articulo_id=$multi")->fetch_row()[0]===((int)$db->query("SELECT unidad_id FROM almacenes WHERE id={$alm['r2']}")->fetch_row()[0]).':3.250','La entrada cruzada queda con la unidad dueña del almacén.');
 $local=FixtureAutorizacion3B::usuario($db,'ux_local',['restaurante'=>['permisos'=>['inventario'=>'editar']]]);$servLocal=new AdministracionAlmacenes($db,$local['id'],'contrasena');
 $assert(array_values(array_unique(array_column($servLocal->almacenesEditables(),'slug')))===['restaurante'],'Un operador local sólo ve como editables los almacenes de su negocio.');
 $r=$ok($servicio->guardar('global','crear_articulo',['nombre'=>'UX local','unidad'=>'g','costo'=>'1.0000','activo'=>'1']),'Artículo sin asociaciones');$suelto=(int)$r['articulo_id'];
 $r=$servLocal->guardar('restaurante','asociar',['articulo_id'=>(string)$suelto,'almacen_ids'=>[(string)$alm['r1'],(string)$alm['pan']],'minimo'=>'0.000']);
 $assert(($r['ok']??true)===false&&$n($alm['r1'],$suelto)===0&&$n($alm['pan'],$suelto)===0,'Sin edición en Panadería se rechaza todo el lote: ninguna asociación parcial.');
 $r=$servLocal->guardar('restaurante','entrada',['almacen_id'=>(string)$alm['pan'],'articulo_id'=>(string)$multi,'cantidad'=>'1.000','clave'=>'ux-entrada-ajena']);
 $assert(($r['ok']??true)===false&&(int)$db->query("SELECT COUNT(*) FROM movimientos_inventario WHERE almacen_id={$alm['pan']} AND articulo_id=$multi")->fetch_row()[0]===0,'Sin edición en Panadería no se da entrada en su almacén.');
 $ok($servLocal->guardar('restaurante','asociar',['articulo_id'=>(string)$suelto,'almacen_ids'=>[(string)$alm['r1'],(string)$alm['r2']],'minimo'=>'0.000']),'Asociar a dos almacenes propios');
 $assert($n($alm['r1'],$suelto)===1&&$n($alm['r2'],$suelto)===1,'Un envío asocia a varios almacenes propios.');
 $assert(($servLocal->guardar('restaurante','asociar',['articulo_id'=>(string)$suelto,'minimo'=>'0.000'])['codigo']??'')==='ALMACEN_REQUERIDO','Asociar exige al menos un almacén.');
 $marcados=array_column(array_filter($servicio->almacenesEditables($suelto),static fn($a)=>$a['asociado']),'almacen_id');sort($marcados);$esperados=[$alm['r1'],$alm['r2']];sort($esperados);
 $assert($marcados===$esperados,'almacenesEditables marca dónde ya está asociado el artículo.');
 echo "Almacenes selección: {$count} comprobaciones OK.\n";
}finally{
 if($created&&$name!==$op['base']&&preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name)){$mark=$db->query('SELECT token FROM `'.$name.'`._cp_multinegocio_owner')->fetch_row()[0]??'';if(!hash_equals($token,(string)$mark))throw new RuntimeException('Propiedad no verificada.');$db->query('DROP DATABASE `'.$name.'`');echo "Base temporal propia eliminada.\n";}$db->close();
}
