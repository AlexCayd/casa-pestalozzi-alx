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
if(($argv[1]??'')==='--worker'){
 $p=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
 if(!in_array($_ENV['APP_ENV']??'', ['development','test'],true)||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$p['base'])||$p['base']===($_ENV['DB_NAME']??''))exit(2);
 mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
 $wdb=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$p['base'],(int)($_ENV['DB_PORT']??3306));$wdb->set_charset('utf8mb4');
 $marca=$wdb->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0]??'';
 if(!hash_equals($p['token'],(string)$marca))exit(3);
 fwrite(STDOUT,"READY\n");fflush(STDOUT);
 $r=(new Services\Inventory\Transferencias($wdb,$p['actor'],'contrasena'))->ejecutar($p['ctx'],$p['accion'],$p['datos']);
 echo json_encode($r,JSON_THROW_ON_ERROR);$wdb->close();exit;
}
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
 foreach(['ddl','deploy','menu-restaurante','development'] as $file)SqlArchivo::ejecutar($db,$root.'/database/'.$file.'.sql');
 Model\ActiveRecord::setDB($db);
 $usuarios=[];$serv=[];
 foreach(['global','restaurante','panaderia'] as $ctx){$u=FixtureAutorizacion3B::usuario($db,'transfer_'.$ctx,[$ctx=>['permisos'=>['inventario'=>'editar']]]);$usuarios[$ctx]=$u['id'];$serv[$ctx]=new Services\Inventory\Transferencias($db,$u['id'],'contrasena');}
 $super=FixtureAutorizacion3B::usuario($db,'transfer_super',[],true);$su=new Services\Inventory\Transferencias($db,$super['id'],'contrasena');$motor=new Almacenes($db,$super['id'],'contrasena');
 $saldo=static fn(int $w,int $a):string=>(string)$db->query("SELECT saldo FROM almacen_articulos WHERE almacen_id=$w AND articulo_id=$a")->fetch_row()[0];
 $key=0;
 $solicitar=static function(string $ctx,int $o,int $d,array $lineas)use(&$key,$serv,$ok):array{return $ok($serv[$ctx]->ejecutar($ctx,'solicitar',['clave'=>'sol-'.++$key,'origen_id'=>$o,'destino_id'=>$d,'lineas'=>$lineas,'motivo'=>'Reposición documentada']),'Solicitar');};
 $act=static function(string $ctx,string $accion,array $t,array $lineas=[])use(&$key,$serv):array{$d=['clave'=>'paso-'.++$key,'transferencia_id'=>$t['transferencia_id'],'version'=>$t['version'],'motivo'=>'Confirmación física con diferencia documentada'];if($lineas)$d['lineas']=$lineas;return $serv[$ctx]->ejecutar($ctx,$accion,$d);};
 $lineas=[['articulo_id'=>1001,'cantidad'=>'12.125'],['articulo_id'=>1002,'cantidad'=>'10.250']];
 $inicioO=$saldo(1002,1001);$inicioD=$saldo(1005,1001);
 $t=$solicitar('restaurante',1002,1005,$lineas);$assert($t['estado']==='solicitada'&&$saldo(1002,1001)===$inicioO&&$saldo(1005,1001)===$inicioD,'Solicitud no mueve saldos.');
 $t=$ok($act('restaurante','autorizar',$t),'Autorizar');$assert($t['estado']==='autorizada'&&$saldo(1002,1001)===$inicioO,'Autorizar no reserva ni descuenta.');
 $assert($act('global','enviar',$t)['ok']===false,'Global consolidado no envía stock de Restaurante.');
 $assert($su->ejecutar('global','enviar',['clave'=>'super-global','transferencia_id'=>$t['transferencia_id'],'version'=>$t['version'],'motivo'=>'Prueba de contexto'])['ok']===false,'Superadministrador respeta propietario físico del contexto.');
 $t=$ok($act('restaurante','enviar',$t),'Enviar');$assert($t['estado']==='enviada'&&$saldo(1005,1001)===$inicioD,'Enviar no acredita destino.');
 $mov=$db->query("SELECT id FROM movimientos_inventario WHERE transferencia_linea_id IS NOT NULL AND tipo='transferencia_salida' LIMIT 1")->fetch_row()[0];
 $r=$motor->revertirMovimientos('restaurante',['clave'=>'reversa-ajena','referencia'=>'reversa-ajena','motivo'=>'No duplicar retorno','lineas'=>[['movimiento_id'=>(int)$mov,'cantidad'=>'1.000']]]);$assert($r['ok']===false,'Reversa genérica no duplica compensación de transferencia.');
 $assert($act('restaurante','cancelar',$t)['ok']===false,'No se cancela stock en tránsito.');
 $stale=$t;$t=$ok($act('restaurante','recibir',$t,$lineas),'Recibir total');$assert($t['estado']==='recibida','Recepción total cierra documento.');
 $assert($act('restaurante','recibir',$stale,$lineas)['codigo']==='VERSION_CONFLICTO','Recepción duplicada con clave nueva rechaza versión.');
 $assert($saldo(1005,1001)===Services\Inventory\DecimalInventario::cantidad(Services\Inventory\DecimalInventario::leer($inicioD)+12125),'Destino recibe decimal exacto.');
 // Dos negocios: origen Global y recepción independiente Panadería.
 $t=$solicitar('panaderia',1001,1003,$lineas);
 $assert($act('panaderia','autorizar',$t)['ok']===false,'Solicitante destino no autoriza origen ajeno.');
 $t=$ok($act('global','autorizar',$t),'Autoriza origen Global');$t=$ok($act('global','enviar',$t),'Envía origen Global');
 $assert($act('global','recibir',$t,$lineas)['ok']===false,'Origen no recibe por destino ajeno.');
 $antes=$saldo(1003,1001);
 $parcial=[['articulo_id'=>1001,'cantidad'=>'7.001'],['articulo_id'=>1002,'cantidad'=>'10.250']];
 $d=['clave'=>'recepcion-idempotente','transferencia_id'=>$t['transferencia_id'],'version'=>$t['version'],'motivo'=>'Faltan 5.124 g de harina; retorno al origen','lineas'=>$parcial];
 $t=$ok($serv['panaderia']->ejecutar('panaderia','recibir',$d),'Recepción parcial');$assert($t['estado']==='incidencia','Parcial deja incidencia explícita.');
 $replay=$ok($serv['panaderia']->ejecutar('panaderia','recibir',$d),'Replay recepción');$assert($replay['reintento']===true&&$saldo(1003,1001)===Services\Inventory\DecimalInventario::cantidad(Services\Inventory\DecimalInventario::leer($antes)+7001),'Replay no repite entrada.');
 $cambio=$d;$cambio['lineas'][0]['cantidad']='1.000';$assert($serv['panaderia']->ejecutar('panaderia','recibir',$cambio)['codigo']==='CONFLICTO_IDEMPOTENCIA','Clave con otro contenido denegada.');
 $assert($act('global','retornar',$t,[['articulo_id'=>1001,'cantidad'=>'5.125']])['codigo']==='CANTIDAD_EXCEDIDA','Retorno no supera tránsito.');
 $t=$ok($act('global','retornar',$t,[['articulo_id'=>1001,'cantidad'=>'5.124']]),'Retorno exacto');$assert($t['estado']==='cerrada','Incidencia resuelta conserva cierre con retorno.');
 $assert($act('global','retornar',$t,[['articulo_id'=>1001,'cantidad'=>'5.124']])['ok']===false,'Retorno no se repite con otra clave.');
 foreach(['rechazar','cancelar'] as $accion){$t=$solicitar('restaurante',1002,1005,$lineas);$t=$ok($act('restaurante',$accion,$t),$accion);$assert(in_array($t['estado'],['rechazada','cancelada'],true),'Cierre sin movimiento.');}
 $base=['clave'=>'solicitud-exacta','origen_id'=>1001,'destino_id'=>1003,'lineas'=>$lineas,'motivo'=>'Repetición de solicitud'];
 $a=$ok($serv['global']->ejecutar('global','solicitar',$base),'Solicitud idempotente');$b=$ok($serv['global']->ejecutar('global','solicitar',$base),'Repetición solicitud');$assert($a['transferencia_id']===$b['transferencia_id']&&$b['reintento'],'Solicitud repetida no duplica documento.');
 $same=$base;$same['clave']='mismo-almacen';$same['destino_id']=1001;$assert($serv['global']->ejecutar('global','solicitar',$same)['codigo']==='MISMO_ALMACEN','No se transfiere al mismo almacén.');
 $foreign=$base;$foreign['clave']='dos-ajenos';$foreign['origen_id']=1002;$assert($serv['global']->ejecutar('global','solicitar',$foreign)['ok']===false,'No solicita dos almacenes extranjeros.');
 $inactiva=$base;$inactiva['clave']='articulo-inactivo';$inactiva['lineas']=[['articulo_id'=>1029,'cantidad'=>'1.000']];$assert($serv['global']->ejecutar('global','solicitar',$inactiva)['ok']===false,'Artículo inactivo o no asociado denegado.');
 $lector=FixtureAutorizacion3B::usuario($db,'transfer_lector',['restaurante'=>['permisos'=>['inventario'=>'ver']]]);$ro=new Services\Inventory\Transferencias($db,$lector['id'],'contrasena');
 $assert($ro->consultar('restaurante')['ok']===true,'Consulta permitida sin edición.');$assert($ro->ejecutar('restaurante','solicitar',$base)['ok']===false,'Solo lectura no solicita.');
 $assert($serv['restaurante']->consultar('panaderia')['ok']===false,'Contexto ajeno denegado.');
 $nip=new Services\Inventory\Transferencias($db,$super['id'],'nip');$assert($nip->ejecutar('global','solicitar',$base)['ok']===false,'NIP no accede a transferencia administrativa.');
 // Autorización no reserva: al envío se revalida stock de todas las líneas.
 $large=[['articulo_id'=>1001,'cantidad'=>'1.000'],['articulo_id'=>1002,'cantidad'=>'999999.000']];$t=$solicitar('global',1001,1003,$large);$antes=$saldo(1001,1001);$assert($act('global','autorizar',$t)['codigo']==='SALDO_INSUFICIENTE'&&$saldo(1001,1001)===$antes,'Fallo de última línea no modifica saldos.');
 // Coordinador externo: nunca publicar éxito antes de commit y rollback integral.
 $db->begin_transaction();$ext=$base;$ext['clave']='externa-pendiente';$r=$serv['global']->ejecutar('global','solicitar',$ext);$assert($r['ok']===null&&$r['pendiente']===true,'Operación exterior queda pendiente.');$extid=$r['transferencia_id'];$db->rollback();$assert((int)$db->query('SELECT COUNT(*) FROM transferencias WHERE id='.$extid)->fetch_row()[0]===0,'Rollback exterior elimina solicitud e idempotencia.');
 $t=$solicitar('panaderia',1001,1003,$lineas);$t=$ok($act('global','autorizar',$t),'Autorizar no recibido');$t=$ok($act('global','enviar',$t),'Enviar no recibido');
 $t=$ok($act('panaderia','incidencia',$t),'Registrar no recibido');$t=$ok($act('global','retornar',$t,$lineas),'Retornar envío completo');$assert($t['estado']==='cerrada','Retorno total sin entrada ficticia al destino.');
 $t=$solicitar('panaderia',1001,1003,$lineas);$t=$ok($act('global','autorizar',$t),'Autorizar recepción fraccionada');$t=$ok($act('global','enviar',$t),'Enviar recepción fraccionada');
 $t=$ok($act('panaderia','recibir',$t,[['articulo_id'=>1001,'cantidad'=>'1.001']]),'Primera recepción');
 $assert($motor->configurarAsociacion('panaderia',1003,1002,['activo'=>0])['ok']===false,'No se desactiva asociación con transferencia pendiente.');
 $t=$ok($act('panaderia','recibir',$t,[['articulo_id'=>1001,'cantidad'=>'11.124'],['articulo_id'=>1002,'cantidad'=>'10.250']]),'Segunda recepción');$assert($t['estado']==='recibida','Varias recepciones completan sin retorno.');
 $t=$solicitar('global',1001,1003,[['articulo_id'=>1001,'cantidad'=>'1.000'],['articulo_id'=>1002,'cantidad'=>'1.000']]);$t=$ok($act('global','autorizar',$t),'Autorizar antes de cambio de stock');
 $before1=$saldo(1001,1001);$milk=$motor->existencia('global',1001,1002)['existencia'];
 $ok($motor->registrarAjuste('global',['clave'=>'bajar-despues-autorizacion','referencia'=>'bajar-despues-autorizacion','motivo'=>'Conteo entre autorización y envío','lineas'=>[['almacen_id'=>1001,'articulo_id'=>1002,'cantidad'=>'0.000','version'=>$milk['version']]]]),'Cambio de stock después de autorizar');
 $assert($act('global','enviar',$t)['codigo']==='SALDO_INSUFICIENTE'&&$saldo(1001,1001)===$before1,'Fallo en última línea revierte salida y operación enteras.');
 $milk=$motor->existencia('global',1001,1002)['existencia'];$ok($motor->registrarAjuste('global',['clave'=>'reponer-conteo','referencia'=>'reponer-conteo','motivo'=>'Restituir conteo de prueba','lineas'=>[['almacen_id'=>1001,'articulo_id'=>1002,'cantidad'=>'5000.000','version'=>$milk['version']]]]),'Restituir stock');
 // Dos procesos vivos esperan la misma versión bloqueada: solo uno confirma.
 $race=static function(string $ctx,string $accion,array $t,array $lineas=[])use($db,$name,$token,$usuarios,$assert):void{
  $workers=[];$db->begin_transaction();$db->query('SELECT id FROM transferencias WHERE id='.(int)$t['transferencia_id'].' FOR UPDATE');
  try{
   for($i=0;$i<2;$i++){
    $datos=['clave'=>'concurrencia-'.$accion.'-'.$t['transferencia_id'].'-'.$i,'transferencia_id'=>$t['transferencia_id'],'version'=>$t['version'],'motivo'=>'Confirmación concurrente'];if($lineas)$datos['lineas']=$lineas;
    $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('No se pudo iniciar worker.');
    fwrite($pipes[0],json_encode(['base'=>$name,'token'=>$token,'actor'=>$usuarios[$ctx],'ctx'=>$ctx,'accion'=>$accion,'datos'=>$datos],JSON_THROW_ON_ERROR));fclose($pipes[0]);
    stream_set_timeout($pipes[1],20);$assert(trim((string)fgets($pipes[1]))==='READY','Worker preparado contra base propia.');$workers[]=[$proc,$pipes];
   }
   $db->commit();$res=[];
   foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);$assert($code===0&&$err==='','Worker termina sin errores.');$res[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
   $assert(count(array_filter($res,static fn($r)=>$r['ok']===true))===1,'Solo una acción concurrente confirma.');
   $assert(count(array_filter($res,static fn($r)=>($r['codigo']??'')==='VERSION_CONFLICTO'))===1,'La otra acción detecta versión concurrente.');
  }finally{$db->rollback();foreach($workers as [$proc,$pipes])if(is_resource($proc)){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_terminate($proc);proc_close($proc);}}
 };
 $t=$solicitar('global',1001,1003,$lineas);$t=$ok($act('global','autorizar',$t),'Autorizar carrera');$race('global','enviar',$t);
 $actual=$db->query('SELECT version,estado FROM transferencias WHERE id='.$t['transferencia_id'])->fetch_assoc();$t['version']=(int)$actual['version'];$assert($actual['estado']==='enviada','Una sola salida concurrente.');
 $race('panaderia','recibir',$t,$lineas);
 $assert((string)$db->query('SELECT estado FROM transferencias WHERE id='.$t['transferencia_id'])->fetch_row()[0]==='recibida','Una sola recepción concurrente.');
 // Conservación y ledger exacto de todas las asociaciones, sin tolerancia flotante.
 $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos aa LEFT JOIN (SELECT almacen_id,articulo_id,SUM(delta) saldo FROM movimientos_inventario GROUP BY almacen_id,articulo_id) m ON m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id WHERE aa.saldo<>COALESCE(m.saldo,0)')->fetch_row()[0]===0,'Cada saldo coincide exactamente con movimientos.');
 $assert((int)$db->query("SELECT COUNT(*) FROM transferencia_lineas l WHERE l.cantidad_recibida+l.cantidad_retornada>l.cantidad_enviada OR l.cantidad_enviada>l.cantidad_solicitada")->fetch_row()[0]===0,'Ninguna línea crea cantidades.');
 foreach($db->query('SELECT l.*,t.almacen_origen_id,t.almacen_destino_id FROM transferencia_lineas l JOIN transferencias t ON t.id=l.transferencia_id')->fetch_all(MYSQLI_ASSOC) as $l){
  $id=(int)$l['id'];$m=$db->query("SELECT COALESCE(SUM(CASE WHEN tipo='transferencia_salida' THEN -delta ELSE 0 END),0) salida,COALESCE(SUM(CASE WHEN tipo='transferencia_entrada' AND movimiento_origen_id IS NULL THEN delta ELSE 0 END),0) recibida,COALESCE(SUM(CASE WHEN movimiento_origen_id IS NOT NULL THEN delta ELSE 0 END),0) retorno FROM movimientos_inventario WHERE transferencia_linea_id=$id")->fetch_assoc();
  $assert($m['salida']===$l['cantidad_enviada']&&$m['recibida']===$l['cantidad_recibida']&&$m['retorno']===$l['cantidad_retornada'],'Documento y ledger conservan salida/recepción/retorno por línea.');
 }
 $consulta=$ok($serv['global']->consultar('global'),'Consolidado documental');$assert($consulta['transferencias_total']>0&&count($consulta['transferencias'])===$consulta['transferencias_total'],'Historial completo dentro de la primera página.');
 $assert($serv['global']->consultar('global',0)['codigo']==='FILTRO_INVALIDO','Página inválida no accede a historial.');
 $assert($serv['global']->consultar('global',2)['transferencias']===[],'Página posterior no repite documentos.');
 foreach($ok($ro->consultar('restaurante'),'Historial solo lectura')['transferencias'] as $row)$assert($row['acciones']===[],'Lector no recibe acciones ejecutables.');
 $h=(int)$db->query('SELECT MIN(id) FROM transferencia_historial')->fetch_row()[0];
 foreach(['UPDATE transferencia_historial SET motivo=\'alterado\' WHERE id='.$h,'DELETE FROM transferencia_historial WHERE id='.$h] as $sql){$rechazado=false;try{$db->query($sql);}catch(mysqli_sql_exception){$rechazado=true;}$assert($rechazado,'Historial documental inmutable.');}
 echo "Transferencias M0.5-B: {$count} comprobaciones OK.\n";
}finally{
 if($created&&$name!==$op['base']&&preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name)){$mark=$db->query('SELECT token FROM `'.$name.'`._cp_multinegocio_owner')->fetch_row()[0]??'';if(!hash_equals($token,(string)$mark))throw new RuntimeException('Propiedad no verificada.');$db->query('DROP DATABASE `'.$name.'`');echo "Base temporal propia eliminada.\n";}$db->close();
}
