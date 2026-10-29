<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
$p=json_decode((string)fgets(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!in_array($_ENV['APP_ENV']??'',['development','test'],true)||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$p['base']??'')||$p['base']===($_ENV['DB_NAME']??''))exit(2);
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$p['base'],(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
if(!hash_equals($p['token'],(string)$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0]))exit(3);
Model\ActiveRecord::setDB($db);session_start();$_SESSION=['id'=>$p['actor'],'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
echo "READY\n";fflush(STDOUT);if(trim((string)fgets(STDIN))!=='GO')exit(4);
$r=match($p['accion']){'crear'=>Services\Menu\ProductosCasa::crear(...$p['args']),'producto'=>Services\Menu\ProductosCasa::publicar(...$p['args']),'receta'=>Services\Menu\RecetasCasa::publicar(...$p['args']),default=>['ok'=>false]};
echo json_encode($r,JSON_THROW_ON_ERROR);session_destroy();$db->close();
