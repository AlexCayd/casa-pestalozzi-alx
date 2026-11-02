<?php
declare(strict_types=1);

/** Usuarios 3E.1: instala solo DDL/deploy sobre una base aleatoria marcada. */
if (PHP_SAPI !== 'cli') exit(1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
require_once $root.'/scripts/multinegocio/SqlArchivo.php';
require_once __DIR__.'/soporte-3d.php';
require_once __DIR__.'/fixture-3b.php';
require_once __DIR__.'/pruebas-usuarios-3e1.php';
$options=getopt('',['host:','base:','entorno:']);
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
if(($options['host']??'')!=='127.0.0.1'||($options['base']??'')!=='casa-pestalozzi'
    ||!in_array($options['entorno']??'', ['development','test'],true)
    ||($options['host']??'')!==($_ENV['DB_HOST']??'')||($options['base']??'')!==($_ENV['DB_NAME']??'')
    ||($options['entorno']??'')!==($_ENV['APP_ENV']??'')) {
    fwrite(STDERR,"Identifica el origen configurado con --host 127.0.0.1 --base casa-pestalozzi --entorno development|test.\n"); exit(1);
}
$db=null;$bases=[];$count=0;$error=null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],'',(int)($_ENV['DB_PORT']??3306));
    $db->set_charset('utf8mb4');
    restaurante3dCrearBase($db,$root,$bases);
    \Model\ActiveRecord::setDB($db);
    $_ENV['NIP_LOOKUP_SECRET']=bin2hex(random_bytes(32));
    putenv('NIP_LOOKUP_SECRET='.$_ENV['NIP_LOOKUP_SECRET']);
    $super=FixtureAutorizacion3B::usuario($db,'super_3e',[],true);
    probarUsuarios3E($db,(int)$super['id'],$count);
} catch(Throwable $e) { $error=$e instanceof mysqli_sql_exception?'Error SQL aislado, código '.$e->getCode():$e->getMessage(); }
finally {
    if($db instanceof mysqli) {
        foreach(array_reverse($bases) as [$name,$token]) {
            try {
                if(!restaurante3dBasePropia($db,$name,$token,(string)($_ENV['DB_NAME']??''))) throw new RuntimeException('Propietario no verificado.');
                $db->query('DROP DATABASE '.restaurante3dIdent($name));
                fwrite(STDOUT,"Base temporal verificada y eliminada: $name\n");
            } catch(Throwable) { $error='No se verificó la limpieza de la base temporal.'; }
        }
        $db->close();
    }
}
$result=['ok'=>$error===null,'fase'=>'Usuarios 3E.1','comprobaciones'=>$count];
if($error!==null)$result['error']=$error;
fwrite($error===null?STDOUT:STDERR,json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n");
exit($error===null?0:1);
