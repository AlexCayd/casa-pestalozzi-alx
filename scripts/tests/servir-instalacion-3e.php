<?php
declare(strict_types=1);

/** Instalación HTTP 3E sin paquete privado: DB, cuentas adicionales y sesiones desechables. */
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
use Services\Development\Instalacion;
use Services\Security\PermisosUsuario;
use Services\Users\NipService;

function servir3eOpciones(array $args): array
{
 $out=[];for($i=0;$i<count($args);$i++){
  if(!preg_match('/^--(host|base|entorno|fixture-salida)$/D',$args[$i],$m)||!isset($args[$i+1])||isset($out[$m[1]])||str_starts_with($args[$i+1],'--'))throw new RuntimeException('Uso: --host HOST --base BASE_ORIGEN --entorno development|test [--fixture-salida ABS_PRIVADO].');
  $out[$m[1]]=$args[++$i];
 }
 foreach(['host','base','entorno'] as $key)if(!isset($out[$key])||$out[$key]==='')throw new RuntimeException('Falta identificar origen y entorno.');
 if(!in_array($out['entorno'],['development','test'],true))throw new RuntimeException('Entorno no permitido.');return $out;
}
function servir3eIdent(string $name): string {if(!preg_match('/^[a-zA-Z0-9_]+$/D',$name))throw new RuntimeException('Identificador inválido.');return '`'.$name.'`';}
function servir3eCuenta(mysqli $db,string $alias,string $contexto,array $permisos,array $facultades=[],?string $nip=null): array
{
 $username='qa3e_'.$alias.'_'.bin2hex(random_bytes(4));$password='QA3E_'.bin2hex(random_bytes(12));$hash=password_hash($password,PASSWORD_DEFAULT);$nipHash=$lookup=null;
 if($nip!==null){$credential=NipService::credencial($nip);$nipHash=$credential['hash'];$lookup=$credential['lookup'];}
 $catalogo=PermisosUsuario::catalogo($contexto);
 foreach($permisos as $mod=>$nivel)if(!isset($catalogo[$mod])||!in_array($nivel,$catalogo[$mod]['niveles'],true))throw new RuntimeException('Permiso de fixture inválido.');
 $s=$db->prepare('INSERT INTO usuarios(username,nombre,password_hash,nip_hash,nip_lookup,activo,es_superadmin) VALUES (?,?,?,?,?,1,0)');
 $nombre='Validación 3E '.$alias;$s->execute([$username,$nombre,$hash,$nipHash,$lookup]);$id=(int)$db->insert_id;$s->close();
 $s=$db->prepare('SELECT id FROM unidades WHERE slug=?');$s->execute([$contexto]);$unit=(int)($s->get_result()->fetch_row()[0]??0);$s->close();if($unit<1)throw new RuntimeException('Falta el ámbito del fixture.');
 $s=$db->prepare('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,1)');$s->execute([$id,$unit]);$s->close();
 $s=$db->prepare('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,?,?)');foreach($permisos as $mod=>$nivel)$s->execute([$id,$unit,$mod,$nivel]);$s->close();
 $s=$db->prepare('INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES (?,?,?)');foreach($facultades as $facultad)$s->execute([$id,$unit,$facultad]);$s->close();
 return ['id'=>$id,'username'=>$username,'password'=>$nip===null?$password:null,'nip'=>$nip,'contexto'=>$contexto,'unidad_id'=>$unit];
}
function servir3ePropia(mysqli $db,string $name,string $token,string $original): bool
{
 if($name===$original||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name)||!preg_match('/^[a-f0-9]{64}$/D',$token))return false;
 $s=$db->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$s->execute([$name]);$exists=$s->get_result()->fetch_row();$s->close();if(!$exists)return false;
 $tables=$db->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('_cp_instalacion_owner','instalacion_desarrollo','_cp_multinegocio_owner')");$tables->execute([$name]);$present=array_column($tables->get_result()->fetch_all(MYSQLI_NUM),0);$tables->close();
 $valid=false;
 if(in_array('instalacion_desarrollo',$present,true)){$mark=$db->query('SELECT propietario_token,entorno FROM '.servir3eIdent($name).'.instalacion_desarrollo WHERE id=1')->fetch_assoc();$valid=$mark&&$mark['entorno']==='test'&&hash_equals($token,(string)$mark['propietario_token']);}
 if(in_array('_cp_instalacion_owner',$present,true)){$initial=$db->query('SELECT token FROM '.servir3eIdent($name).'._cp_instalacion_owner')->fetch_all(MYSQLI_NUM);if(count($initial)!==1||!hash_equals($token,(string)$initial[0][0]))return false;$valid=true;}
 if(in_array('_cp_multinegocio_owner',$present,true)){$extra=$db->query('SELECT token FROM '.servir3eIdent($name).'._cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);if(count($extra)!==1||!hash_equals($token,(string)$extra[0][0]))return false;}
 return (bool)$valid;
}
function servir3eLimpiarSesion(string $path,string $token,string $temp): void
{
 if(!file_exists($path))return;$real=realpath($path);$parent=realpath($temp);$mark=$path.DIRECTORY_SEPARATOR.'owner';
 if(!$real||!$parent||dirname($real)!==$parent||is_link($path)||!preg_match('/^cp-instalacion-3e-session-[a-f0-9]{24}$/D',basename($real))||!is_file($mark)||is_link($mark)||!hash_equals($token,trim((string)file_get_contents($mark))))throw new RuntimeException('No se verificó la carpeta temporal.');
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $entry){if($entry->isLink())throw new RuntimeException('Enlace inesperado en carpeta temporal.');if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($real);
}
function servir3eSalidaPrivada(string $path,string $token,array $fixture): string
{
 $parent=realpath(dirname($path));$home=(string)(getenv('CODEX_HOME')?:rtrim((string)getenv('USERPROFILE'),'\\/').'/.codex');$allowed=realpath($home.'/visualizations');
 $normalized=str_replace('\\','/',$path);$normalizedParent=$parent?str_replace('\\','/',$parent):'';$normalizedAllowed=$allowed?str_replace('\\','/',$allowed):'';
 $absolute=preg_match('~^[a-zA-Z]:/~',$normalized)===1||str_starts_with($normalized,'/');
 if(!$parent||!$allowed||!$absolute||!str_starts_with(strtolower(rtrim($normalizedParent,'/').'/'),strtolower(rtrim($normalizedAllowed,'/').'/'))||is_link(dirname($path))||file_exists($path)||file_exists($path.'.owner'))throw new RuntimeException('La salida de credenciales debe ser un archivo nuevo dentro de visualizations privado.');
 $json=json_encode($fixture,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
 if(file_put_contents($path,$json,LOCK_EX)!==strlen($json)||file_put_contents($path.'.owner',$token,LOCK_EX)===false)throw new RuntimeException('No se pudo guardar la salida privada.');chmod($path,0600);chmod($path.'.owner',0600);return hash('sha256',$json);
}

$db=null;$server=null;$pipes=[];$created=[];$sessionPath='';$sessionOwner='';$fixturePath='';$fixtureHash='';$temp=sys_get_temp_dir();$phase='configuración';$error=null;
try {
 Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();$config=$_ENV;$options=servir3eOpciones(array_slice($argv,1));
 if(!hash_equals((string)($config['DB_HOST']??''),$options['host'])||!hash_equals((string)($config['DB_NAME']??''),$options['base'])||!hash_equals((string)($config['APP_ENV']??''),$options['entorno']))throw new RuntimeException('Host, base y entorno deben identificar exactamente la configuración de origen.');
 $password=(string)($_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD'));if(strlen($password)<12)throw new RuntimeException('Configura localmente DEV_SUPERADMIN_PASSWORD antes del ensayo.');
 $probe=@fsockopen('127.0.0.1',8094,$errno,$errstr,.2);if(is_resource($probe)){fclose($probe);throw new RuntimeException('127.0.0.1:8094 ya está ocupado.');}
 mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$phase='conexión sin seleccionar la base original';
 $db=new mysqli((string)$config['DB_HOST'],(string)$config['DB_USER'],(string)$config['DB_PASS'],'',(int)($config['DB_PORT']??3306));$db->set_charset('utf8mb4');
 $name='cp_multinegocio_test_'.bin2hex(random_bytes(10));if($name===(string)$config['DB_NAME'])throw new RuntimeException('El destino coincide con el origen.');
 $_ENV['APP_ENV']='test';$_ENV['DB_NAME']=$name;putenv('APP_ENV=test');putenv('DB_NAME='.$name);
 $phase='instalación desde DDL y DML versionados';$installation=Instalacion::ejecutar($db,$name,'test',$password,true,static function(string $base,string $token)use(&$created):void{$created[]=[$base,$token];});unset($password);
 $token=(string)$installation['propietario_token'];$db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY) ENGINE=InnoDB');$s=$db->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');$s->execute([$token]);$s->close();
 $db->query('UPDATE configuracion_pos SET impresion_activa=0');
 $nipSecret=bin2hex(random_bytes(32));$_ENV['NIP_LOOKUP_SECRET']=$nipSecret;putenv('NIP_LOOKUP_SECRET='.$nipSecret);$phase='actores de pruebas';$accounts=[];
 foreach(['global','restaurante','panaderia'] as $ctx){
  $accounts['lectura_'.$ctx]=servir3eCuenta($db,'lectura_'.$ctx,$ctx,['users'=>'ver','inventario'=>'ver','menu'=>'ver'],['usuarios.gestionar']);
  $accounts['operador_'.$ctx]=servir3eCuenta($db,'operador_'.$ctx,$ctx,['inventario'=>'editar','menu'=>'editar']);
 }
 $accounts['administrador_global']=servir3eCuenta($db,'administrador_global','global',['users'=>'editar','inventario'=>'editar','menu'=>'editar'],['usuarios.gestionar','permisos.delegar','almacenes.crear','catalogo.editar']);
 $nip=str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);$accounts['piso_nip']=servir3eCuenta($db,'piso','restaurante',['pdv'=>'operar','area'=>'operar','reservations_map'=>'operar'],[],$nip);
 $sessionOwner=bin2hex(random_bytes(24));$sessionPath=$temp.DIRECTORY_SEPARATOR.'cp-instalacion-3e-session-'.bin2hex(random_bytes(12));if(!mkdir($sessionPath,0700))throw new RuntimeException('No se creó la carpeta privada de sesiones.');file_put_contents($sessionPath.DIRECTORY_SEPARATOR.'owner',$sessionOwner,LOCK_EX);
 $fixture=['url'=>'http://127.0.0.1:8094/','base_temporal'=>$name,'admin_demo'=>['username'=>'admin_demo'],'cuentas'=>$accounts,'fixture'=>['almacen_global'=>1001,'almacen_restaurante'=>1002,'almacen_panaderia'=>1003,'articulo_harina'=>1001,'articulo_leche'=>1002,'articulo_cafe'=>1003,'mesa_libre'=>1005,'oferta_restaurante'=>1001,'oferta_panaderia'=>1003,'variante_extra'=>1002,'area_panaderia'=>10],'session_path'=>$sessionPath];
 if(isset($options['fixture-salida'])){$phase='salida privada del fixture';$fixturePath=$options['fixture-salida'];$fixtureHash=servir3eSalidaPrivada($fixturePath,$sessionOwner,$fixture);}
 $external=bin2hex(random_bytes(24));$callback=bin2hex(random_bytes(24));$env=[];$parentEnv=getenv();if(!is_array($parentEnv))$parentEnv=[];
 foreach($parentEnv as $key=>$value)if(in_array(strtolower((string)$key),['path','systemroot','windir','comspec','pathext','lang'],true))$env[$key]=$value;
 $env=array_merge($env,['CP_3E_TEST_DB'=>$name,'CP_3E_OWNER_TOKEN'=>$token,'CP_3E_BASE_ORIGEN'=>(string)$config['DB_NAME'],'CP_3E_DB_HOST'=>(string)$config['DB_HOST'],'CP_3E_DB_USER'=>(string)$config['DB_USER'],'CP_3E_DB_PASS'=>(string)$config['DB_PASS'],'CP_3E_DB_PORT'=>(string)($config['DB_PORT']??3306),
  'CP_3E_SESSION_PATH'=>$sessionPath,'CP_3E_SESSION_OWNER'=>$sessionOwner,'CP_3E_TEMP_ROOT'=>$temp,'CP_3E_NIP_SECRET'=>$nipSecret,'CP_3E_EXTERNAL_SECRET'=>$external,'CP_3E_CALLBACK_SECRET'=>$callback,
  'APP_ENV'=>'test','DB_HOST'=>(string)$config['DB_HOST'],'DB_USER'=>(string)$config['DB_USER'],'DB_PASS'=>(string)$config['DB_PASS'],'DB_NAME'=>$name,'DB_PORT'=>(string)($config['DB_PORT']??3306),'SESSION_SAVE_PATH'=>$sessionPath,'NIP_LOOKUP_SECRET'=>$nipSecret,'TMP'=>$sessionPath,'TEMP'=>$sessionPath,'TMPDIR'=>$sessionPath,
  'N8N_BASE_URL'=>'http://127.0.0.1:9','N8N_WEBHOOK_SUGERENCIAS_URL'=>'http://127.0.0.1:9/sugerencias','N8N_WEBHOOK_AREAS_MEJORA_URL'=>'http://127.0.0.1:9/areas','IA_N8N_WEBHOOK_URL'=>'http://127.0.0.1:9/ia','N8N_SECRET'=>$external,'N8N_RESERVATIONS_WEBHOOK_SECRET'=>$external,'N8N_RESERVATIONS_CALLBACK_SECRET'=>$callback,
  'SMTP_HOST'=>'','SMTP_USER'=>'','SMTP_PASS'=>'','MAIL_HOST'=>'','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'','META_ACCESS_TOKEN'=>'','WHATSAPP_ACCESS_TOKEN'=>'','IMPRESION_ACTIVA'=>'0']);
 $phase='servidor HTTP loopback';$log=$sessionPath.DIRECTORY_SEPARATOR.'php-server.log';
 $server=proc_open([PHP_BINARY,'-S','127.0.0.1:8094','-t',$root.'/public',$root.'/scripts/tests/router-instalacion-3e.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root,$env,['create_process_group'=>true]);if(!is_resource($server))throw new RuntimeException('No inició el servidor PHP.');
 $ready=false;$deadline=microtime(true)+15;while(microtime(true)<$deadline){$socket=@fsockopen('127.0.0.1',8094,$errno,$errstr,.15);if(is_resource($socket)){fclose($socket);$ready=true;break;}$status=proc_get_status($server);if(!$status['running'])break;usleep(100000);}if(!$ready)throw new RuntimeException('El servidor temporal no escuchó.');
 $safeAccounts=[];foreach($accounts as $key=>$account)$safeAccounts[$key]=['id'=>$account['id'],'username'=>$account['username'],'contexto'=>$account['contexto']];
 echo json_encode(['ok'=>true,'fase'=>'3E instalación HTTP independiente','url'=>$fixture['url'],'base_temporal'=>$name,'administrador'=>'admin_demo','cuentas_sin_credenciales'=>$safeAccounts,'fixture_salida'=>$fixturePath!==''?$fixturePath:null,'instruccion'=>'Enviar Enter por STDIN para detener y limpiar los recursos temporales.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;fflush(STDOUT);
 while(($line=fgets(STDIN))!==false)if(trim($line)==='')break;
} catch(Throwable $e){$error=$e instanceof mysqli_sql_exception?'Error MySQL; código '.$e->getCode().'.':$e->getMessage();}
finally {
 if(is_resource($server)){proc_terminate($server);if(isset($pipes[0])&&is_resource($pipes[0]))fclose($pipes[0]);proc_close($server);}
 $cleanupFailed=false;$cleanupBases=[];
 if($db instanceof mysqli){foreach(array_reverse($created) as [$name,$token]){try{if(servir3ePropia($db,$name,$token,(string)($config['DB_NAME']??''))){$db->query('DROP DATABASE '.servir3eIdent($name));fwrite(STDOUT,'Base temporal verificada y eliminada: '.$name.PHP_EOL);}else {$cleanupFailed=true;$cleanupBases[]=$name;}}catch(Throwable){$cleanupFailed=true;$cleanupBases[]=$name;}}$db->close();}
 if($fixturePath!==''&&file_exists($fixturePath)){try{if(is_link($fixturePath)||is_link($fixturePath.'.owner')||!is_file($fixturePath.'.owner')||!hash_equals($sessionOwner,trim((string)file_get_contents($fixturePath.'.owner')))||!hash_equals($fixtureHash,(string)hash_file('sha256',$fixturePath)))throw new RuntimeException('Salida privada modificada.');unlink($fixturePath);unlink($fixturePath.'.owner');}catch(Throwable){$cleanupFailed=true;}}
 if($sessionPath!==''&&$sessionOwner!==''){try{servir3eLimpiarSesion($sessionPath,$sessionOwner,$temp);}catch(Throwable){$cleanupFailed=true;}}
 if($cleanupFailed&&$error===null)$error='No se verificó la limpieza completa de recursos temporales.';
}
if($error!==null){fwrite(STDERR,json_encode(['ok'=>false,'fase'=>$phase,'error'=>$error,'limpieza_completa'=>!($cleanupFailed??false),'bases_pendientes'=>array_values(array_unique($cleanupBases??[]))],JSON_UNESCAPED_UNICODE).PHP_EOL);exit(1);}
