<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit(1); }
$root=dirname(__DIR__,2); require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
try {
    $op=getopt('',['host:','base:','entorno:','restablecer']);
    foreach (['host'=>'DB_HOST','base'=>'DB_NAME','entorno'=>'APP_ENV'] as $clave=>$variable) {
        if (!isset($op[$clave]) || $op[$clave]!==($_ENV[$variable]??null)) throw new RuntimeException('El argumento --'.$clave.' debe coincidir con la configuración local.');
    }
    if (!in_array($op['entorno'],['development','test'],true)) throw new RuntimeException('Bootstrap bloqueado en este entorno.');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli($op['host'],(string)($_ENV['DB_USER']??''),(string)($_ENV['DB_PASS']??''),$op['base'],(int)($_ENV['DB_PORT']??3306));
    $r=Services\Development\BootstrapDesarrollo::ejecutar($db,$op['entorno'],(string)($_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD')),isset($op['restablecer']));
    $db->close(); echo json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,json_encode(['ok'=>false,'mensaje'=>$e instanceof mysqli_sql_exception?'No se pudo verificar el aprovisionamiento.':$e->getMessage()],JSON_UNESCAPED_UNICODE).PHP_EOL); exit(1); }
