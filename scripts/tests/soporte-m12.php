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
