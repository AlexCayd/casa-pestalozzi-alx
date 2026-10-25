<?php
declare(strict_types=1);
/** Dos instalaciones completas desde archivos versionados, sin paquete privado. */
if (PHP_SAPI!=='cli') exit(1);
$root=dirname(__DIR__,2); require $root.'/vendor/autoload.php';
require_once __DIR__.'/pruebas-datos-3e.php';
require_once __DIR__.'/fixture-3b.php';
require_once __DIR__.'/pruebas-usuarios-3e1.php';
require_once __DIR__.'/pruebas-almacenes-3e1.php';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
$original=(string)($_ENV['DB_NAME']??'');$db=null;$bases=[];$count=0;$phase='configuración';$error=null;$results=[];$fixtureCanonico=null;
$assert=static function(bool $ok,string $msg) use(&$count): void {if(!$ok)throw new RuntimeException($msg);$count++;};
$reject=static function(callable $fn,string $msg) use($assert): void {$failed=false;try{$fn();}catch(Throwable){$failed=true;}$assert($failed,$msg);};
try {
    $op=getopt('',['host:','base:','entorno:']);
    foreach(['host'=>'DB_HOST','base'=>'DB_NAME','entorno'=>'APP_ENV'] as $k=>$env) if(!isset($op[$k])||$op[$k]!==($_ENV[$env]??null))throw new RuntimeException('Identifica el origen local mediante --host --base --entorno.');
    if(!in_array($op['entorno'],['development','test'],true))throw new RuntimeException('Ensayo exclusivo de desarrollo/pruebas.');
    $password=(string)(getenv('DEV_SUPERADMIN_PASSWORD')?:($_ENV['DEV_SUPERADMIN_PASSWORD']??''));
    if(strlen($password)<12)throw new RuntimeException('Configura DEV_SUPERADMIN_PASSWORD localmente para ensayar la cuenta acordada.');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],'',(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
    $_ENV['APP_ENV']='test';$_ENV['NIP_LOOKUP_SECRET']=bin2hex(random_bytes(32));putenv('NIP_LOOKUP_SECRET='.$_ENV['NIP_LOOKUP_SECRET']);
    for($i=1;$i<=2;$i++) {
        $phase='instalación versionada '.$i;$name='cp_multinegocio_test_'.bin2hex(random_bytes(10));$_ENV['DB_NAME']=$name;
        $result=Services\Development\Instalacion::ejecutar($db,$name,'test',$password,true,static function($n,$token)use(&$bases){$bases[$n]=$token;});
        $assert($result['ok']===true,'La instalación debe finalizar.');
        $datos=probarDatos3E($db,$root,$count);
        if($fixtureCanonico===null)$fixtureCanonico=$datos;else $assert($fixtureCanonico['conteos']===$datos['conteos']&&$fixtureCanonico['periodo_tickets']===$datos['periodo_tickets'],'Las dos bases nuevas deben reproducir los mismos conteos y periodos.');
        $user=$db->query("SELECT id,password_hash,activo,es_superadmin,nip_hash,nip_lookup FROM usuarios WHERE username='admin_demo'")->fetch_assoc();
        $id=(int)($user['id']??0);
        $assert($id>0&&password_verify($password,$user['password_hash'])&&(int)$user['es_superadmin']===1&&(int)$user['activo']===1,'El bootstrap crea una sola cuenta superadministradora con hash válido.');
        $assert($user['nip_hash']===null&&$user['nip_lookup']===null,'El superadministrador no tiene acceso por NIP.');
        $assert((int)$db->query('SELECT COUNT(*) FROM usuario_unidades WHERE activo=1 AND usuario_id='.$id)->fetch_row()[0]===3,'El administrador tiene las tres membresías.');
        $again=Services\Development\BootstrapDesarrollo::ejecutar($db,'test',$password);
        $assert($again['ok']&& !$again['creado']&&$db->query('SELECT password_hash FROM usuarios WHERE id='.$id)->fetch_row()[0]===$user['password_hash'],'Reejecutar bootstrap no duplica ni rehace el hash.');
        $reject(fn()=>Services\Development\BootstrapDesarrollo::ejecutar($db,'test',''),'Se rechaza contraseña ausente.');
        $different=bin2hex(random_bytes(24));
        $reject(fn()=>Services\Development\BootstrapDesarrollo::ejecutar($db,'test',$different),'No se restablece contraseña implícitamente.');
        $assert($db->query('SELECT password_hash FROM usuarios WHERE id='.$id)->fetch_row()[0]===$user['password_hash'],'El rechazo conserva el hash existente.');
        $_ENV['APP_ENV']='production';
        $reject(fn()=>Services\Development\BootstrapDesarrollo::ejecutar($db,'production',$password),'Producción no admite bootstrap.');
        $_ENV['APP_ENV']='test';
        $db->query('UPDATE instalacion_desarrollo SET administrador_id=NULL WHERE id=1');
        $reject(fn()=>Services\Development\BootstrapDesarrollo::ejecutar($db,'test',$password),'No se promueve una cuenta homónima sin propiedad de bootstrap.');
        $db->query('UPDATE instalacion_desarrollo SET administrador_id='.$id.' WHERE id=1');
        $reset=Services\Development\BootstrapDesarrollo::ejecutar($db,'test',$different,true);
        $assert($reset['ok']&&password_verify($different,$db->query('SELECT password_hash FROM usuarios WHERE id='.$id)->fetch_row()[0]),'El restablecimiento explícito funciona solo en la identidad propia.');
        Services\Development\BootstrapDesarrollo::ejecutar($db,'test',$password,true);
        $reject(fn()=>Services\Development\Instalacion::ejecutar($db,$name,'test',$password),'El instalador no destruye una base existente.');
        $assert((int)$db->query('SELECT COUNT(*) FROM productos WHERE id<1000')->fetch_row()[0]===112,'La reejecución rechazada conserva el menú.');
        Model\ActiveRecord::setDB($db);
        $oldSession=$_SESSION??[];$oldPath=$_SERVER['PATH_INFO']??null;
        \Classes\Auth::start();$_SESSION=['login'=>true,'id'=>$id,'auth_nivel'=>'contrasena','_auth3b'=>true];$_SERVER['PATH_INFO']='/admin';
        $ventasIA=Services\Analytics\ConsultasRapidas::responderEstructurada('ventas_total',null,null,'30d');
        $sinVentaIA=Services\Analytics\ConsultasRapidas::responderEstructurada('sin_venta',null,null,'30d');
        $meserosIA=Services\Analytics\ConsultasRapidas::responderEstructurada('meseros',null,null,'30d');
        $horasIA=Services\Analytics\ConsultasRapidas::responderEstructurada('horas_pico',null,null,'30d');
        $consumoIA=Services\Analytics\ConsultasRapidas::responderEstructurada('consumo',1002,null,'semana');
        $stockIA=Services\Analytics\ConsultasRapidas::responderEstructurada('stock',1002,null,null);
        $assert($ventasIA['ok']&&$ventasIA['entendida']&&isset($ventasIA['respuesta']['cifra']),'El asistente local consulta ventas reales del periodo.');
        $assert((int)$sinVentaIA['respuesta']['cifra']>=8,'El asistente local encuentra ofertas activas sin venta.');
        $assert(count($meserosIA['respuesta']['detalle'])>0&&count($horasIA['respuesta']['detalle'])>0,'El asistente local consulta meseros y horas pico.');
        $assert((float)str_replace(',','',(string)$consumoIA['respuesta']['cifra'])>0&&$stockIA['respuesta']['cifra']!=='','El asistente local consulta consumo trazado y existencias.');
        $asistente=['ventas_total_30d'=>$ventasIA['respuesta']['cifra'],'ofertas_sin_venta_30d'=>(int)$sinVentaIA['respuesta']['cifra'],'meseros_consultables'=>count($meserosIA['respuesta']['detalle']),'franjas_horarias'=>count($horasIA['respuesta']['detalle']),'consumo_semana'=>$consumoIA['respuesta']['cifra'],'stock_consultable'=>$stockIA['respuesta']['cifra']];
        $_SESSION=$oldSession;if($oldPath===null)unset($_SERVER['PATH_INFO']);else $_SERVER['PATH_INFO']=$oldPath;
        $profile=Services\Security\PermisosUsuario::cargar($id,$db);
        $assert(Services\Security\PermisosUsuario::tipoAcceso($profile)==='contrasena'&&count($profile['ambitos'])===3,'La autorización reconoce el superadministrador en los tres ámbitos.');
        $stmt=$db->prepare('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY) ENGINE=InnoDB');$stmt->execute();$stmt->close();
        $stmt=$db->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');$stmt->execute([$bases[$name]]);$stmt->close();
        probarUsuarios3E($db,$id,$count);
        probarAlmacenes3E1($db,$count);
        $results[]=['instalación'=>$i,'menu'=>$datos['menu'],'fixtures'=>$datos['conteos'],'periodo_tickets'=>$datos['periodo_tickets'],'asistente_local'=>$asistente,'superadministrador'=>'admin_demo','comprobaciones_acumuladas'=>$count];
    }
} catch(Throwable $e){$error=$e->getMessage();}
finally {
    if($db instanceof mysqli) {
        foreach(array_reverse($bases,true) as $name=>$token) {
            try {
                if($name===$original||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name))throw new RuntimeException('Destino de limpieza inválido.');
                $actual=null;
                try{$actual=$db->query('SELECT propietario_token FROM `'.$name.'`.instalacion_desarrollo WHERE id=1')->fetch_row()[0]??null;}catch(Throwable){}
                if($actual===null){try{$actual=$db->query('SELECT token FROM `'.$name.'`._cp_instalacion_owner')->fetch_row()[0]??null;}catch(Throwable){}}
                if(!is_string($actual)||!hash_equals($token,$actual))throw new RuntimeException('Propietario no verificado.');
                $db->query('DROP DATABASE `'.$name.'`');fwrite(STDOUT,'Base temporal verificada y eliminada: '.$name.PHP_EOL);
            }catch(Throwable){$error=($error?$error.' ':'').'No se verificó la limpieza de un destino temporal.';}
        }
        $db->close();
    }
}
$out=['ok'=>$error===null,'fase'=>$phase,'comprobaciones'=>$count,'instalaciones'=>$results];if($error!==null)$out['error']=$error;
fwrite($error===null?STDOUT:STDERR,json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL);exit($error===null?0:1);
