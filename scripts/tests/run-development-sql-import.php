<?php
declare(strict_types=1);

/** Valida el flujo de importación SQL directa en una base temporal propia. */
if (PHP_SAPI !== 'cli') { exit(1); }
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
require_once $root.'/scripts/multinegocio/SqlArchivo.php';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();

$owned=[];$db=null;$name='';$token='';$count=0;$exitCode=0;$phase='configuración';
$assert=static function(bool $ok,string $message) use (&$count): void { if(!$ok) throw new RuntimeException($message); $count++; };
$ownedByUs=static function(mysqli $server,string $database,string $expected): bool {
    if(!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$database)) return false;
    try {
        $stmt=$server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $stmt->execute([$database]);$exists=$stmt->get_result()->fetch_row();$stmt->close();if(!$exists)return false;
        $server->select_db($database);
        $rows=$server->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
        return count($rows)===1&&hash_equals($expected,(string)$rows[0][0]);
    } catch(Throwable) { return false; }
};
try {
    $options=getopt('',['host:','base:','entorno:']);
    foreach(['host'=>'DB_HOST','base'=>'DB_NAME','entorno'=>'APP_ENV'] as $key=>$env) {
        if(!isset($options[$key])||$options[$key]!==($_ENV[$env]??null)) throw new RuntimeException("Identifica el entorno local con --{$key}.");
    }
    if(!in_array($options['entorno'],['development','test'],true)) throw new RuntimeException('Importación directa permitida solo en development/test.');
    $password=(string)(getenv('DEV_SUPERADMIN_PASSWORD')?:($_ENV['DEV_SUPERADMIN_PASSWORD']??''));
    if(strlen($password)<12) throw new RuntimeException('Configura localmente la contraseña de admin_demo.');
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $db=new mysqli((string)$_ENV['DB_HOST'],(string)$_ENV['DB_USER'],(string)$_ENV['DB_PASS'],'',(int)($_ENV['DB_PORT']??3306));
    $db->set_charset('utf8mb4');
    $name='cp_multinegocio_test_'.bin2hex(random_bytes(10));$token=bin2hex(random_bytes(32));
    $db->query("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$owned[]=[$name,$token];$db->select_db($name);
    $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY) ENGINE=InnoDB');
    $stmt=$db->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');$stmt->execute([$token]);$stmt->close();
    foreach(['database/ddl.sql','database/deploy.sql','database/menu-restaurante.sql','database/development.sql'] as $file) {
        $phase=$file;
        Multinegocio\SqlArchivo::ejecutar($db,$root.'/'.$file);
    }
    $user=$db->query("SELECT id,password_hash,activo,es_superadmin,nip_hash FROM usuarios WHERE username='admin_demo'")->fetch_assoc()?:[];
    $assert((int)($user['id']??0)>0&&(int)$user['activo']===1&&(int)$user['es_superadmin']===1&&$user['nip_hash']===null&&password_verify($password,(string)$user['password_hash']),'El DML directo debe crear admin_demo con hash de contraseña y sin NIP.');
    $stmt=$db->prepare('SELECT COUNT(DISTINCT u.slug) FROM usuario_unidades m JOIN unidades u ON u.id=m.unidad_id WHERE m.usuario_id=? AND m.activo=1');$stmt->execute([(int)$user['id']]);$memberships=(int)$stmt->get_result()->fetch_row()[0];$stmt->close();
    $assert($memberships===3,'admin_demo debe recibir exactamente los tres ámbitos.');
    $assert((int)$db->query("SELECT COUNT(*) FROM usuarios WHERE id BETWEEN 1001 AND 1007")->fetch_row()[0]===7,'El bloque de usuarios históricos debe conservarse en el DML.');
    fwrite(STDOUT,json_encode(['ok'=>true,'flujo'=>'importación SQL directa','comprobaciones'=>$count,'admin_demo'=>'hash verificado','membresias'=>$memberships],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL);
} catch(Throwable $e) {
    fwrite(STDERR,json_encode(['ok'=>false,'fase'=>$phase,'error'=>$e instanceof mysqli_sql_exception?'Error MySQL '.$e->getCode().': '.$e->getMessage():$e->getMessage()],JSON_UNESCAPED_UNICODE).PHP_EOL);
    $exitCode=1;
} finally {
    if($db instanceof mysqli) {
        foreach($owned as [$ownedName,$ownedToken]) if($ownedByUs($db,$ownedName,$ownedToken)) $db->query("DROP DATABASE `{$ownedName}`");
        $db->close();
    }
}
exit($exitCode);
