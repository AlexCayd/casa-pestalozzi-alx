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
if (($argv[1]??'')==='--worker') {
    $p=json_decode((string)fgets(STDIN),true,512,JSON_THROW_ON_ERROR);
    if (!in_array($_ENV['APP_ENV']??'', ['development','test'],true) || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$p['base']) || $p['base']===($_ENV['DB_NAME']??'')) exit(2);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $w=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$p['base'],(int)($_ENV['DB_PORT']??3306));$w->set_charset('utf8mb4');
    if (!hash_equals($p['token'],(string)($w->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0]??''))) exit(3);
    ActiveRecord::setDB($w);session_start();$_SESSION=['id'=>$p['actor'],'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
    echo "READY\n";fflush(STDOUT);if(trim((string)fgets(STDIN))!=='GO')exit(4);
    $r=$p['accion']==='asociar' ? Ofertas::crear($p['producto'],'panaderia',$p['datos'])
        : ContextoNegocio::en('global',static fn()=>CatalogoComercial::actualizar($p['producto'],$p['datos'],$p['huella'],true));
    echo json_encode($r,JSON_THROW_ON_ERROR);session_destroy();$w->close();exit;
}
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
    $_SESSION=['id'=>$super['id'],'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
    if (isset($extensionM11) && is_callable($extensionM11)) $extensionM11($db,$root,$assert);
    $before=$db->query('SELECT id,producto_id,unidad_id,categoria_id,precio,area_id,activo,orden FROM ofertas_producto WHERE unidad_id=2 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $rest=ContextoNegocio::en('restaurante',static fn()=>Producto::todos());
    $original=$rest[0]; $pid=(int)$original->producto_id;
    $panCat=(int)$db->query('SELECT id FROM categorias WHERE unidad_id=3 LIMIT 1')->fetch_row()[0];
    $panArea=(int)$db->query('SELECT id FROM areas_produccion WHERE unidad_id=3 LIMIT 1')->fetch_row()[0];
    $datos=['categoria_id'=>$panCat,'area_id'=>$panArea,'precio'=>'123.45','activo'=>1];
    $r=Ofertas::crear($pid,'panaderia',$datos); $assert(!empty($r['ok'])&&!empty($r['confirmado']),'Asociación confirmada'); $offer=(int)$r['id'];
    $assert($db->execute_query('SELECT precio_nuevo FROM historial_precios WHERE oferta_id=?',[$offer])->fetch_row()[0]==='123.4500','Alta e histórico guardan importe exacto juntos');
    $assert((int)$db->execute_query('SELECT COUNT(*) FROM productos WHERE id=?',[$pid])->fetch_row()[0]===1,'Una identidad permanente');
    $fails(static fn()=>Ofertas::crear($pid,'panaderia',$datos),'Duplicado rechazado');
    $fails(static fn()=>Ofertas::crear($pid,'global',$datos),'Global no vendedor');
    foreach (['1.001','1e2','-1','1000000','NaN'] as $precio) $fails(static fn()=>Ofertas::crear($pid,'panaderia',array_replace($datos,['precio'=>$precio])),'Precisión inválida rechazada');
    $fails(static fn()=>Ofertas::crear((int)$rest[1]->producto_id,'panaderia',array_replace($datos,['categoria_id'=>$original->categoria_id])),'Categoría ajena rechazada');
    $fails(static fn()=>Ofertas::crear((int)$rest[1]->producto_id,'panaderia',array_replace($datos,['area_id'=>$original->area_id])),'Área ajena rechazada');
    $local=FixtureAutorizacion3B::usuario($db,'m1_pan',['panaderia'=>['permisos'=>['menu'=>'editar']]]);
    $_SESSION['id']=$local['id'];
    $assert(Classes\Auth::destinoPorRol()==='/admin/panaderia/menu','Cuenta con solo Menú aterriza en su negocio');
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
    // Fallo de la segunda escritura: la identidad recién insertada debe revertirse.
    $db->query("CREATE TRIGGER m1_fallo_alta BEFORE INSERT ON ofertas_producto FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo M1 deliberado'");
    $total=(int)$db->query('SELECT COUNT(*) FROM productos')->fetch_row()[0];
    ContextoNegocio::en('panaderia',static function() use($panCat,$panArea,$fails):void {
        $p=new Producto();$p->sincronizar(['nombre'=>'Alta atómica','descripcion'=>'Fallo deliberado','precio'=>'1.25','categoria_id'=>$panCat,'area_id'=>$panArea]);
        $fails(static fn()=>$p->crear(),'Fallo segunda escritura rechazado');
    });
    $assert((int)$db->query('SELECT COUNT(*) FROM productos')->fetch_row()[0]===$total,'Rollback de identidad tras fallo en oferta');
    $db->query('DROP TRIGGER m1_fallo_alta');
    $db->query("CREATE TRIGGER m1_fallo_cambio BEFORE UPDATE ON ofertas_producto FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo M1 deliberado'");
    $master=ContextoNegocio::en('global',static fn()=>CatalogoComercial::identidad($pid));
    ContextoNegocio::en('panaderia',static function() use($offer,$fails):void {
        $p=Producto::find($offer);$p->nombre='Identidad que debe revertirse';$p->confirmar_identidad=true;
        $fails(static fn()=>$p->actualizar(),'Fallo local después de cambio compartido');
    });
    $assert(ContextoNegocio::en('global',static fn()=>CatalogoComercial::identidad($pid))===$master,'Rollback conserva identidad y oferta juntas');
    $db->query('DROP TRIGGER m1_fallo_cambio');
    $precioActual=$db->execute_query('SELECT precio FROM ofertas_producto WHERE id=?',[$offer])->fetch_row()[0];
    $db->query("CREATE TRIGGER m1_fallo_historial BEFORE INSERT ON historial_precios FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fallo M1 deliberado'");
    ContextoNegocio::en('panaderia',static function()use($offer,$fails):void {
        $p=Producto::find($offer);$p->precio='20.01';$fails(static fn()=>$p->actualizar(),'Un fallo de histórico revierte la edición');
    });
    $assert($db->execute_query('SELECT precio FROM ofertas_producto WHERE id=?',[$offer])->fetch_row()[0]===$precioActual,'Precio anterior intacto si no se puede guardar histórico');
    $db->query('DROP TRIGGER m1_fallo_historial');
    // Dos procesos tienen la misma intención antes de liberar la barrera.
    $race=static function(array $payloads)use($name,$token,$super,$assert):array {
        $workers=[];
        try {
            foreach($payloads as $payload){
                $proc=proc_open([PHP_BINARY,__FILE__,'--worker'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                if(!is_resource($proc))throw new RuntimeException('No se inició worker M1.');
                $workers[]=[$proc,$pipes];
                fwrite($pipes[0],json_encode($payload+['base'=>$name,'token'=>$token,'actor'=>$super['id']],JSON_THROW_ON_ERROR)."\n");fflush($pipes[0]);
                stream_set_timeout($pipes[1],20);$assert(trim((string)fgets($pipes[1]))==='READY','Worker preparado');
            }
            foreach($workers as [$proc,$pipes]){fwrite($pipes[0],"GO\n");fclose($pipes[0]);}
            $results=[];
            foreach($workers as [$proc,$pipes]){$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($proc);$assert($exit===0&&$err==='','Worker completado sin error');$results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR);}
            $workers=[];return $results;
        }finally{foreach($workers as [$proc,$pipes]){foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}}}
    };
    $racePid=(int)$rest[2]->producto_id;
    $results=$race([['accion'=>'asociar','producto'=>$racePid,'datos'=>$datos],['accion'=>'asociar','producto'=>$racePid,'datos'=>$datos]]);
    $assert(count(array_filter($results,static fn($r)=>!empty($r['confirmado'])))===1,'Asociación concurrente tiene un solo ganador');
    $assert((int)$db->execute_query('SELECT COUNT(*) FROM ofertas_producto WHERE producto_id=? AND unidad_id=3',[$racePid])->fetch_row()[0]===1,'Una sola oferta tras carrera');
    $h=CatalogoComercial::huella($master);
    $results=$race([['accion'=>'identidad','producto'=>$pid,'huella'=>$h,'datos'=>array_replace($master,['nombre'=>'Ganador A'])],['accion'=>'identidad','producto'=>$pid,'huella'=>$h,'datos'=>array_replace($master,['nombre'=>'Ganador B'])]]);
    $assert(count(array_filter($results,static fn($r)=>!empty($r['confirmado'])))===1,'Cambio maestro concurrente rechaza al escritor obsoleto');
    // Usar solo un archivo propio del ensayo; nunca intentar borrar imágenes originales.
    $image='build/images/m1-'.bin2hex(random_bytes(16)).'.webp';$absolute=$root.'/public/'.$image;
    file_put_contents($absolute,'fixture de referencias M1');
    try {
        $db->execute_query('UPDATE productos SET img=? WHERE id IN (?,?)',[$image,$pid,$racePid]);
        Classes\ImagenUploader::eliminar($image);$assert(is_file($absolute),'Imagen con dos identidades referenciada se conserva');
        $db->execute_query('UPDATE productos SET img=NULL WHERE id=?',[$pid]);
        Classes\ImagenUploader::eliminar($image);$assert(is_file($absolute),'Imagen con referencia restante se conserva');
        $db->execute_query('UPDATE productos SET img=NULL WHERE id=?',[$racePid]);
        Classes\ImagenUploader::eliminar($image);$assert(!is_file($absolute),'Archivo propio sin referencias puede retirarse');
    } finally {if(is_file($absolute))unlink($absolute);}
    $snapshots=$db->query('SELECT * FROM ticket_items WHERE oferta_id=1001 ORDER BY id')->fetch_all(MYSQLI_ASSOC);
    $assert(count($snapshots)>0,'Retiro ensaya una oferta con ventas reales del fixture');
    ContextoNegocio::en('restaurante',static function()use($assert):void {
        $p=Producto::find(1001);$assert($p!==null&&$p->retirar(),'Retirar oferta con ventas conserva la fila');
    });
    $assert($snapshots===$db->query('SELECT * FROM ticket_items WHERE oferta_id=1001 ORDER BY id')->fetch_all(MYSQLI_ASSOC),'Snapshots completos idénticos tras retiro con ventas');
    echo "Menú M1: {$count} comprobaciones OK.\n";
} finally {
    if ($created && $name!==$op['base'] && preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$name)) {
        $mark=$db->query('SELECT token FROM `'.$name.'`._cp_multinegocio_owner')->fetch_row()[0]??'';
        if (!hash_equals($token,(string)$mark)) throw new RuntimeException('Propiedad no verificada.');
        $db->query('DROP DATABASE `'.$name.'`'); echo "Base M1 propia eliminada.\n";
    }
    if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
    $db->close();
}
