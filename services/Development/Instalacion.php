<?php
declare(strict_types=1);
namespace Services\Development;

/** Reconstruye únicamente una base nueva; nunca aplica DDL sobre una existente. */
final class Instalacion
{
    public static function ejecutar(\mysqli $server,string $base,string $entorno,string $password,bool $simulados=true,?callable $alCrear=null): array
    {
        if (PHP_SAPI!=='cli' || !in_array($entorno,['development','test'],true)
            || $entorno!==(string)($_ENV['APP_ENV']??getenv('APP_ENV'))
            || $base!==(string)($_ENV['DB_NAME']??'')) throw new \RuntimeException('Identifica explícitamente base y entorno local antes de instalar.');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D',$base)) throw new \RuntimeException('Nombre de base inválido.');
        if (strlen($password)<12 || strlen($password)>1024) throw new \RuntimeException('Falta la contraseña local de bootstrap.');
        $root=dirname(__DIR__,2);
        require_once $root.'/scripts/multinegocio/SqlArchivo.php';
        $archivos=['database/ddl.sql','database/deploy.sql','database/menu-restaurante.sql'];
        if ($simulados) $archivos[]='database/development.sql';
        foreach ($archivos as $archivo) if (!is_file($root.'/'.$archivo)) throw new \RuntimeException('Falta un recurso versionado de instalación: '.$archivo);
        $stmt=$server->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
        $stmt->execute([$base]); $existente=$stmt->get_result()->fetch_row(); $stmt->close();
        if ($existente) throw new \RuntimeException('La base ya existe. No se elimina ni se reconstruye automáticamente.');
        $server->query('CREATE DATABASE `'.$base.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $server->select_db($base); $server->set_charset('utf8mb4'); $server->query("SET time_zone='-06:00'");
        $token=bin2hex(random_bytes(32));
        // La marca inicial permite identificar un intento incompleto sin borrar datos ajenos.
        $server->query('CREATE TABLE _cp_instalacion_owner(token CHAR(64) PRIMARY KEY) ENGINE=InnoDB');
        $stmt=$server->prepare('INSERT INTO _cp_instalacion_owner(token) VALUES (?)'); $stmt->execute([$token]); $stmt->close();
        if ($alCrear!==null) $alCrear($base,$token);
        foreach ($archivos as $archivo) {
            foreach (\Multinegocio\SqlArchivo::sentencias((string)file_get_contents($root.'/'.$archivo)) as $sql) {
                $r=$server->query($sql); if ($r instanceof \mysqli_result) $r->free();
            }
        }
        $hash=hash('sha256',implode('',array_map(static fn($a)=>hash_file('sha256',$root.'/'.$a),$archivos)));
        $stmt=$server->prepare('INSERT INTO instalacion_desarrollo(id,entorno,propietario_token,dml_sha256) VALUES (1,?,?,?)');
        $stmt->execute([$entorno,$token,$hash]); $stmt->close();
        $cuenta=BootstrapDesarrollo::ejecutar($server,$entorno,$password);
        $server->query('UPDATE instalacion_desarrollo SET completada=1 WHERE id=1');
        $server->query('DROP TABLE _cp_instalacion_owner');
        return ['ok'=>true,'base'=>$base,'entorno'=>$entorno,'archivos'=>$archivos,'dml_sha256'=>$hash,'administrador'=>$cuenta['usuario'],'propietario_token'=>$token];
    }
}
