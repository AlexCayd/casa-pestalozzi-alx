<?php
declare(strict_types=1);
namespace Services\Security;

use Classes\Auth;
use Model\ActiveRecord;
use Services\Inventory\InventarioError;
use Services\Inventory\TransaccionInventario;

/** Contexto del servidor: la ruta navega; el perfil 3B autoriza cada operación. */
final class ContextoNegocio
{
    private static ?string $interno = null;

    /** Disponibilidad técnica; los permisos se comprueban por separado. */
    public static function modulosHabilitados(string $ctx): array
    {
        return $ctx === 'restaurante' ? ['users','menu','productos'] : ['users'];
    }

    public static function nuevo(): bool
    {
        return ActiveRecord::getDB() instanceof \mysqli && Auth::multinegocio();
    }

    public static function slug(): string
    {
        return self::$interno ?? Contextos::deRuta((string)($_SERVER['PATH_INFO'] ?? '/'));
    }

    public static function db(): \mysqli
    {
        $db=ActiveRecord::getDB();
        if(!$db instanceof \mysqli) throw new InventarioError('CONEXION_NO_DISPONIBLE','No se pudo verificar el contexto de negocio.');
        return $db;
    }

    public static function unidad(?string $ctx=null): int
    {
        $ctx??=self::slug();
        if(!isset(Contextos::NOMBRES[$ctx])) throw new InventarioError('AMBITO_INVALIDO','El ámbito no es válido.');
        $s=self::db()->prepare('SELECT id FROM unidades WHERE slug=?');
        $s->execute([$ctx]);
        $fila=$s->get_result()->fetch_row();
        if(!$fila) throw new InventarioError('AMBITO_INVALIDO','No se encontró el ámbito.');
        return (int)$fila[0];
    }

    public static function vendedora(?string $ctx=null): int
    {
        $ctx??=self::slug();
        if(!in_array($ctx,['restaurante','panaderia'],true)) throw new InventarioError('AMBITO_NO_VENDEDOR','Global no puede generar ventas ni ofertas comerciales.');
        return self::unidad($ctx);
    }

    public static function actorId(): int
    {
        Auth::start();
        return (int)($_SESSION['id']??0);
    }

    public static function nivelAuth(): string
    {
        Auth::start();
        return (string)($_SESSION['auth_nivel']??'');
    }

    public static function exigir(string $modulo,string $nivel='ver',?string $ctx=null,bool $bloquear=false): array
    {
        $ctx??=self::slug();
        $perfil=PermisosUsuario::cargar(self::actorId(),self::db(),$bloquear);
        $decision=PermisosUsuario::decidir($perfil,$ctx,$modulo,$nivel,self::nivelAuth());
        if(!$decision['ok']) throw new InventarioError($decision['codigo'],'No tienes permiso para esta operación en el ámbito solicitado.');
        return $perfil;
    }

    public static function facultad(string $facultad,string $ctx='global',string $modulo='menu',bool $bloquear=false): array
    {
        $perfil=self::exigir($modulo,'editar',$ctx,$bloquear);
        if(!in_array($facultad,$perfil['ambitos'][$ctx]['facultades']??[],true)) throw new InventarioError('FACULTAD_DENEGADA','No tienes la facultad requerida.');
        return $perfil;
    }

    public static function transaccion(callable $accion): array
    {
        return TransaccionInventario::ejecutar(self::db(),static function () use($accion): array {
            $r=$accion();
            if(!is_array($r)) throw new InventarioError('RESULTADO_INVALIDO','La operación no devolvió un resultado verificable.');
            if((array_key_exists('ok',$r) && $r['ok']===false) || (array_key_exists('resultado',$r) && $r['resultado']===false)) {
                throw new InventarioError((string)($r['codigo']??$r['error']??'OPERACION_RECHAZADA'),(string)($r['mensaje']??'No se pudo completar la operación.'));
            }
            return $r;
        });
    }

    /** Solo coordinadores internos usan este alcance temporal; no concede permisos. */
    public static function en(string $ctx,callable $accion): mixed
    {
        if(!isset(Contextos::NOMBRES[$ctx])) throw new InventarioError('AMBITO_INVALIDO','El ámbito no es válido.');
        $anterior=self::$interno;
        self::$interno=$ctx;
        try { return $accion(); }
        finally { self::$interno=$anterior; }
    }
}
