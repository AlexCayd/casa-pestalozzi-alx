<?php
declare(strict_types=1);
function m12Pruebas(callable $pruebas): void {
    if(PHP_SAPI!=='cli')exit(1);
    $root=dirname(__DIR__,2);require_once $root.'/vendor/autoload.php';
    require_once $root.'/scripts/multinegocio/SqlArchivo.php';require_once __DIR__.'/fixture-3b.php';
    Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
    $args=getopt('',['host:','base:','entorno:']);
    foreach(['host'=>'DB_HOST','base'=>'DB_NAME','entorno'=>'APP_ENV'] as $key=>$env)if(($args[$key]??null)!==($_ENV[$env]??null))throw new RuntimeException('Identifica --host --base --entorno según configuración local.');
    if(!in_array($args['entorno'],['development','test'],true))throw new RuntimeException('Solo pruebas/desarrollo.');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],'',(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
    $name='cp_multinegocio_test_'.bin2hex(random_bytes(10));$token=bin2hex(random_bytes(32));$created=false;$n=0;
    $a=static function(bool $v,string $m)use(&$n){if(!$v)throw new RuntimeException($m);$n++;};
    try {
        $db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$created=true;$db->select_db($name);
        $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY)');$db->execute_query('INSERT INTO _cp_multinegocio_owner VALUES (?)',[$token]);
        foreach(['ddl','deploy','menu-restaurante','development'] as $file)Multinegocio\SqlArchivo::ejecutar($db,$root.'/database/'.$file.'.sql');
        Model\ActiveRecord::setDB($db);if(session_status()!==PHP_SESSION_ACTIVE)session_start();
        $u=FixtureAutorizacion3B::usuario($db,'m12_super',[],true);
        $_SESSION=['id'=>$u['id'],'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
        $pruebas($db,$root,$a);echo "M1.2: {$n} comprobaciones OK.\n";
    } finally {
        if($created&&$name!==($_ENV['DB_NAME']??'')&&hash_equals($token,(string)$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0])){$db->query('DROP DATABASE `'.$name.'`');echo "Base temporal M1.2 propia eliminada.\n";}
        $db->close();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
    }
}
function m12Carrera(mysqli $db,string $accion,array $args): array {
    $base=$db->query('SELECT DATABASE()')->fetch_row()[0];$token=$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0];$jobs=[];$out=[];
    try{
        for($i=0;$i<2;$i++){
            $proc=proc_open([PHP_BINARY,__DIR__.'/worker-m12.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__,2));if(!is_resource($proc))throw new RuntimeException('No se inició el worker M1.2.');
            $a=$args;if($accion==='receta')$a[3].='_'.$i;
            fwrite($pipes[0],json_encode(['base'=>$base,'token'=>$token,'actor'=>$_SESSION['id'],'accion'=>$accion,'args'=>$a],JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
            if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Worker M1.2 no listo.');$jobs[]=[$proc,$pipes];
        }
        foreach($jobs as [$proc,$pipes]){fwrite($pipes[0],"GO\n");fflush($pipes[0]);}
        foreach($jobs as [$proc,$pipes]){$json=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$code=proc_close($proc);if($code!==0)throw new RuntimeException('Worker M1.2 falló: '.substr($err,0,200));$out[]=json_decode($json,true,512,JSON_THROW_ON_ERROR);}
        $jobs=[];return $out;
    }finally{foreach($jobs as [$proc,$pipes]){if(is_resource($proc))proc_terminate($proc);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($proc))proc_close($proc);}}
}
