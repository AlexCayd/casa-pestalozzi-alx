<?php
declare(strict_types=1);
/** DDL/DML versionados en destino aleatorio propio, sin seleccionar la base activa. */
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
require $root.'/scripts/multinegocio/SqlArchivo.php';
require __DIR__.'/fixture-3b.php';
use Model\ActiveRecord;
use Model\Producto;
use Services\Menu\CatalogoComercial;
use Services\Menu\Ofertas;
use Services\Security\ContextoNegocio;
use Multinegocio\SqlArchivo;
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
$op = getopt('', ['host:','base:','entorno:']);
foreach (['host'=>'DB_HOST','base'=>'DB_NAME','entorno'=>'APP_ENV'] as $k=>$env) if (($op[$k]??null) !== ($_ENV[$env]??null)) throw new RuntimeException('Identifica host/base/entorno según configuración local.');
if (!in_array($op['entorno'], ['development','test'], true)) throw new RuntimeException('Solo desarrollo/pruebas.');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], '', (int)($_ENV['DB_PORT']??3306));
$db->set_charset('utf8mb4');
$name = 'cp_multinegocio_test_'.bin2hex(random_bytes(10)); $token = bin2hex(random_bytes(32)); $created = false; $count = 0;
$assert = static function(bool $v, string $m) use (&$count): void { if (!$v) throw new RuntimeException($m); $count++; };
$fails = static function(callable $f, string $m) use ($assert): void { try { $r=$f(); $denied=is_array($r) ? empty($r['ok']) && empty($r['resultado']) : !$r; } catch (Throwable $e) { $denied=true; } $assert($denied,$m); };
try {
    $db->query('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $created=true; $db->select_db($name);
    $db->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) PRIMARY KEY)'); $db->execute_query('INSERT INTO _cp_multinegocio_owner VALUES (?)',[$token]);
    foreach (['ddl','deploy','menu-restaurante','development'] as $file) SqlArchivo::ejecutar($db,$root.'/database/'.$file.'.sql');
    ActiveRecord::setDB($db);
    if (session_status()!==PHP_SESSION_ACTIVE) session_start();
    $super=FixtureAutorizacion3B::usuario($db,'m1_super',[],true);
    $_SESSION=['id'=>$super['id'],'auth_nivel'=>'contrasena','_auth3b'=>true];
    $before=$db->query('SELECT id,producto_id,unidad_id,categoria_id,precio,area_id,activo,orden FROM ofertas_producto WHERE unidad_id=2 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $rest=ContextoNegocio::en('restaurante',static fn()=>Producto::todos());
    $original=$rest[0]; $pid=(int)$original->producto_id;
    $panCat=(int)$db->query('SELECT id FROM categorias WHERE unidad_id=3 LIMIT 1')->fetch_row()[0];
    $panArea=(int)$db->query('SELECT id FROM areas_produccion WHERE unidad_id=3 LIMIT 1')->fetch_row()[0];
    $datos=['categoria_id'=>$panCat,'area_id'=>$panArea,'precio'=>'123.45','activo'=>1];
    $r=Ofertas::crear($pid,'panaderia',$datos); $assert(!empty($r['ok'])&&!empty($r['confirmado']),'Asociación confirmada'); $offer=(int)$r['id'];
    $assert((int)$db->execute_query('SELECT COUNT(*) FROM productos WHERE id=?',[$pid])->fetch_row()[0]===1,'Una identidad permanente');
    $fails(static fn()=>Ofertas::crear($pid,'panaderia',$datos),'Duplicado rechazado');
    $fails(static fn()=>Ofertas::crear($pid,'global',$datos),'Global no vendedor');
    foreach (['1.001','1e2','-1','1000000','NaN'] as $precio) $fails(static fn()=>Ofertas::crear($pid,'panaderia',array_replace($datos,['precio'=>$precio])),'Precisión inválida rechazada');
    $fails(static fn()=>Ofertas::crear((int)$rest[1]->producto_id,'panaderia',array_replace($datos,['categoria_id'=>$original->categoria_id])),'Categoría ajena rechazada');
    $fails(static fn()=>Ofertas::crear((int)$rest[1]->producto_id,'panaderia',array_replace($datos,['area_id'=>$original->area_id])),'Área ajena rechazada');
    $local=FixtureAutorizacion3B::usuario($db,'m1_pan',['panaderia'=>['permisos'=>['menu'=>'editar']]]);
    $_SESSION['id']=$local['id'];
    ContextoNegocio::en('panaderia',static function() use($offer,$assert,$fails,$original):void {
        $p=Producto::find($offer); $p->precio='87.65'; $assert((bool)$p->actualizar(),'Precio local editable');
        $p->nombre='Cambio compartido no autorizado'; $fails(static fn()=>$p->actualizar(),'Identidad compartida protegida');
        $assert(Producto::find((int)$original->id)===null,'ID extranjero no visible');
        $p=Producto::find($offer); $assert($p->retirar(),'Retiro sin borrado');
    });
    $after=$db->query('SELECT id,producto_id,unidad_id,categoria_id,precio,area_id,activo,orden FROM ofertas_producto WHERE unidad_id=2 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $assert($before===$after,'Restaurante intacto tras precio y retiro Panadería');
    $assert((int)$db->execute_query('SELECT COUNT(*) FROM ofertas_producto WHERE id=?',[$offer])->fetch_row()[0]===1,'Retiro conserva FK e historial');
    FixtureAutorizacion3B::permiso($db,$local['id'],'panaderia','menu','ver');
    ContextoNegocio::en('panaderia',static function() use($offer,$fails):void { $p=Producto::find($offer); $fails(static fn()=>$p->alternarVisibilidad(),'Solo lectura no escribe'); });
    FixtureAutorizacion3B::membresiaActiva($db,$local['id'],'panaderia',false);
    $fails(static fn()=>ContextoNegocio::en('panaderia',static fn()=>Producto::todos()),'Revocación inmediata');
    $inventario=FixtureAutorizacion3B::usuario($db,'m1_inventario',['global'=>['permisos'=>['menu'=>'editar','inventario'=>'editar'],'facultades'=>['catalogo.editar']]]);
    $_SESSION['id']=$inventario['id']; $assert(!CatalogoComercial::puedeEditar(),'Inventario no concede edición comercial');
    $_SESSION['id']=$super['id'];
    ContextoNegocio::en('global',static function() use($pid,$assert,$fails):void {
        $p=CatalogoComercial::identidad($pid); $h=CatalogoComercial::huella($p); $p['nombre']='Identidad confirmada';
        $fails(static fn()=>CatalogoComercial::actualizar($pid,$p,$h,false),'Consentimiento obligatorio');
        $r=CatalogoComercial::actualizar($pid,$p,$h,true); $assert(!empty($r['confirmado']),'Maestro autorizado');
        $fails(static fn()=>CatalogoComercial::actualizar($pid,$p,$h,true),'Concurrencia: huella obsoleta rechazada');
    });
    // Alta inválida no debe dejar una identidad huérfana, aunque falle la segunda tabla.
    $total=(int)$db->query('SELECT COUNT(*) FROM productos')->fetch_row()[0];
    ContextoNegocio::en('panaderia',static function() use($panArea,$fails):void { $p=new Producto();$p->sincronizar(['nombre'=>'Alta rollback','descripcion'=>'Comprobación','precio'=>'5.00','categoria_id'=>999999,'area_id'=>$panArea]);$fails(static fn()=>$p->crear(),'Alta inválida revierte'); });
    $assert((int)$db->query('SELECT COUNT(*) FROM productos')->fetch_row()[0]===$total,'Sin identidad huérfana');
    foreach(['restaurante','panaderia'] as $ctx) ContextoNegocio::en($ctx,static function() use($ctx,$panCat,$panArea,$original,$assert):void {
        $p=new Producto(); $p->sincronizar(['nombre'=>'Nombre coincidente','descripcion'=>'Identidad independiente','precio'=>'12.30','categoria_id'=>$ctx==='panaderia'?$panCat:$original->categoria_id,'area_id'=>$ctx==='panaderia'?$panArea:$original->area_id]);
        $assert(!empty($p->crear()['resultado']),'Alta nueva contextual');
    });
    echo "Menú M1: {$count} comprobaciones OK.\n";
} finally {
    if ($created && $name!==$op['base'] && preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name)) {
        $mark=$db->query('SELECT token FROM `'.$name.'`._cp_multinegocio_owner')->fetch_row()[0]??'';
        if (!hash_equals($token,(string)$mark)) throw new RuntimeException('Propiedad no verificada.');
        $db->query('DROP DATABASE `'.$name.'`'); echo "Base M1 propia eliminada.\n";
    }
    $db->close();
}
