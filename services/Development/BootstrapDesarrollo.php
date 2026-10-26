<?php
declare(strict_types=1);
namespace Services\Development;

/** Aprovisionamiento CLI explícito, ligado a una instalación identificada. */
final class BootstrapDesarrollo
{
    public static function ejecutar(\mysqli $db,string $entorno,string $password,bool $restablecer=false): array
    {
        if (PHP_SAPI!=='cli' || !in_array($entorno,['development','test'],true)
            || ($entorno!==(string)($_ENV['APP_ENV']??getenv('APP_ENV')))) {
            throw new \RuntimeException('El bootstrap solo admite un entorno local de desarrollo o pruebas identificado.');
        }
        if (strlen($password)<12 || strlen($password)>1024) throw new \RuntimeException('Configura una contraseña local de al menos doce caracteres.');
        $nombre=(string)$db->query('SELECT DATABASE()')->fetch_row()[0];
        if ($nombre==='' || $nombre!==(string)($_ENV['DB_NAME']??'')) throw new \RuntimeException('La base seleccionada no coincide con la configuración explícita.');
        try { $db->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED'); }
        catch (\mysqli_sql_exception $e) { if ($e->getCode()===1568) throw new \RuntimeException('El bootstrap requiere su propia transacción.'); throw $e; }
        $db->begin_transaction();
        try {
            $marca=$db->query('SELECT * FROM instalacion_desarrollo WHERE id=1 FOR UPDATE')->fetch_assoc();
            if (!$marca || $marca['entorno']!==$entorno || !preg_match('/^[a-f0-9]{64}$/D',(string)$marca['propietario_token'])) throw new \RuntimeException('No hay una instalación de desarrollo identificada.');
            $stmt=$db->prepare('SELECT id,password_hash,activo,es_superadmin FROM usuarios WHERE username=? FOR UPDATE');
            $stmt->execute(['admin_demo']); $cuenta=$stmt->get_result()->fetch_assoc(); $stmt->close();
            $creado=false;
            if ($cuenta) {
                if ((int)$marca['administrador_id']!==(int)$cuenta['id']) throw new \RuntimeException('Existe una cuenta homónima que este bootstrap no creó. No se promueve.');
                if (!(int)$cuenta['activo'] || !(int)$cuenta['es_superadmin']) throw new \RuntimeException('La cuenta aprovisionada cambió de estado; requiere revisión explícita.');
                if (!password_verify($password,$cuenta['password_hash'])) {
                    if (!$restablecer) throw new \RuntimeException('La contraseña local no coincide. No se restablece implícitamente.');
                    $hash=password_hash($password,PASSWORD_DEFAULT);
                    $stmt=$db->prepare('UPDATE usuarios SET password_hash=? WHERE id=?'); $stmt->execute([$hash,(int)$cuenta['id']]); $stmt->close();
                }
                $id=(int)$cuenta['id'];
            } else {
                if ($marca['administrador_id']!==null) throw new \RuntimeException('Falta la identidad que pertenece a esta instalación.');
                $hash=password_hash($password,PASSWORD_DEFAULT);
                $stmt=$db->prepare('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin) VALUES (?,?,?,1,1)');
                $stmt->execute(['admin_demo','Administrador de desarrollo',$hash]); $stmt->close(); $id=(int)$db->insert_id; $creado=true;
                $stmt=$db->prepare('UPDATE instalacion_desarrollo SET administrador_id=? WHERE id=1'); $stmt->execute([$id]); $stmt->close();
                $stmt=$db->prepare("INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) SELECT ?,id,NULL,1 FROM unidades WHERE slug IN ('global','restaurante','panaderia')");
                $stmt->execute([$id]); if ($stmt->affected_rows!==3) throw new \RuntimeException('La instalación no contiene los tres ámbitos.'); $stmt->close();
            }
            $db->commit();
            return ['ok'=>true,'usuario'=>'admin_demo','usuario_id'=>$id,'creado'=>$creado,'restablecido'=>$restablecer&&!$creado];
        } catch (\Throwable $e) { $db->rollback(); throw $e; }
    }
}
