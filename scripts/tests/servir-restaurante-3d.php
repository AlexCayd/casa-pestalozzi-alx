<?php
declare(strict_types=1);

/** Harness HTTP interactivo 3D.4: DB y sesión desechables, solo loopback. */
if (PHP_SAPI !== 'cli') { exit(1); }

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
require_once $root.'/scripts/menu/bootstrap.php';
require_once $root.'/scripts/multinegocio/SqlArchivo.php';
require_once $root.'/scripts/tests/soporte-3d.php';

use MenuPreservacion\Comun;
use MenuPreservacion\ImportadorMenu;
use MenuPreservacion\VerificadorMenu;
use Model\ActiveRecord;
use Multinegocio\SqlArchivo;
use Services\Security\PermisosUsuario;
use Services\Users\NipService;

function servir3d4Ident(string $name): string
{
    if(!preg_match('/^[A-Za-z0-9_]+$/D',$name)) throw new RuntimeException('Identificador SQL inesperado.');
    return '`'.$name.'`';
}

function servir3d4Insert(mysqli $db,string $sql,array $values): int
{
    $stmt=$db->prepare($sql); $stmt->execute($values); $id=(int)$db->insert_id; $stmt->close(); return $id;
}

/** Crea usuario por membresía y permisos explícitos; nunca usa admin_demo. */
function servir3d4Usuario(mysqli $db,string $alias,string $unit,array $perms,bool $super=false,?string $nip=null): array
{
    $username='qa3d4_'.preg_replace('/[^a-z0-9_]/i','_',$alias).'_'.bin2hex(random_bytes(5));
    $password='QA4_'.bin2hex(random_bytes(10)); $hash=password_hash($password,PASSWORD_DEFAULT);
    $nipHash=$nipLookup=null;
    if($nip!==null){$credential=NipService::credencial($nip);$nipHash=$credential['hash'];$nipLookup=$credential['lookup'];}
    $stmt=$db->prepare('INSERT INTO usuarios(username,nombre,password_hash,nip_hash,nip_lookup,activo,es_superadmin) VALUES (?,?,?,?,?,1,?)');
    $name='Validación 3D.4 '.$alias; $isSuper=(int)$super; $stmt->bind_param('sssssi',$username,$name,$hash,$nipHash,$nipLookup,$isSuper); $stmt->execute(); $id=(int)$db->insert_id; $stmt->close();
    $stmt=$db->prepare('SELECT id FROM unidades WHERE slug=?'); $stmt->bind_param('s',$unit); $stmt->execute(); $unitId=(int)$stmt->get_result()->fetch_row()[0]; $stmt->close();
    if($unitId<1) throw new RuntimeException('No existe el ámbito fixture '.$unit.'.');
    servir3d4Insert($db,'INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,1)',[$id,$unitId]);
    foreach($perms as $module=>$level) servir3d4Insert($db,'INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,?,?)',[$id,$unitId,$module,$level]);
    return ['id'=>$id,'username'=>$username,'password'=>$password,'nip'=>$nip,'unidad_id'=>$unitId];
}

function servir3d4LimpiarSesion(string $path,string $owner): void
{
    if(!file_exists($path)&&!is_link($path)) return;
    $parent=realpath(sys_get_temp_dir()); $real=realpath($path); $ownerFile=$path.DIRECTORY_SEPARATOR.'owner';
    if(!$parent||!$real||is_link($path)||dirname($real)!==$parent
        || !preg_match('/^cp-restaurante-3d4-session-[a-f0-9]{24}$/D',basename($real))
        || !is_file($ownerFile)||is_link($ownerFile)||!hash_equals($owner,trim((string)file_get_contents($ownerFile)))) {
        throw new RuntimeException('La carpeta temporal de sesión no pasó la guarda de limpieza.');
    }
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry){if($entry->isLink())throw new RuntimeException('No se limpian enlaces inesperados.');if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}
    rmdir($real);
}

function servir3d4CuentaOrigen(array $args,array $config,string $root): array
{
    $options=restaurante3dOpciones($args);
    Comun::origen($config,$options,$root);
    $verified=VerificadorMenu::verificar($options['paquete']);
    if(($verified['ok']??false)!==true) throw new RuntimeException('El paquete --paquete no pasó la verificación.');
    return $options;
}

$admin=null; $server=null; $pipes=[]; $dbName=''; $dbOwner=''; $bases=[];
$assetRoot=null; $assetParent=null; $assetParentCreated=false; $sessionPath=''; $sessionOwner=''; $logPath=''; $failure=null; $phase='argumentos/configuración'; $exitCode=0;
try {
    Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
    $config=$_ENV; $options=servir3d4CuentaOrigen(array_slice($argv,1),$config,$root);
    if(!extension_loaded('mysqli')||!function_exists('proc_open')) throw new RuntimeException('Se requieren mysqli y proc_open.');
    $socket=@fsockopen('127.0.0.1',8093,$errno,$errstr,0.2); if(is_resource($socket)){fclose($socket);throw new RuntimeException('127.0.0.1:8093 ya está ocupado.');}
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $phase='conexión sin seleccionar DB_NAME de origen';
    $admin=new mysqli((string)$config['DB_HOST'],(string)$config['DB_USER'],(string)$config['DB_PASS'],'',(int)($config['DB_PORT']??3306)); $admin->set_charset('utf8mb4');
    $phase='base temporal marcada, DDL, deploy y paquete preservado';
    [$dbName,$dbOwner]=restaurante3dCrearBase($admin,$root,$bases); // never selects the configured origin
    $admin->select_db($dbName); ActiveRecord::setDB($admin);
    if($dbName===(string)$config['DB_NAME']) throw new RuntimeException('La base temporal coincide con la base configurada de origen.');
    $importParent=sys_get_temp_dir().DIRECTORY_SEPARATOR.'casa-pestalozzi-3d4-assets';
    if(!is_dir($importParent)){if(!mkdir($importParent,0700,true))throw new RuntimeException('No se creó la raíz temporal de assets.');$assetParentCreated=true;}
    if(is_link($importParent)||!is_dir($importParent)) throw new RuntimeException('Padre de assets inseguro.');
    $assetRoot=$importParent.DIRECTORY_SEPARATOR.bin2hex(random_bytes(12)); $assetParent=$importParent;
    $import=ImportadorMenu::importar($admin,$options['paquete'],$assetRoot,$dbOwner);
    if(($import['ok']??false)!==true||($import['conteos']['productos']??null)!==112||($import['conteos']['ofertas']??null)!==112) throw new RuntimeException('El menú preservado no importó exactamente 112 productos y ofertas.');
    // deploy.sql incluye por compatibilidad un usuario conocido. El harness no conserva esa cuenta.
    $admin->query("DELETE FROM usuario_unidades WHERE usuario_id=(SELECT id FROM usuarios WHERE username='admin_demo')");
    $admin->query("DELETE FROM usuarios WHERE username='admin_demo'");

    $rest=(int)$admin->query("SELECT id FROM unidades WHERE slug='restaurante'")->fetch_row()[0];
    $pan=(int)$admin->query("SELECT id FROM unidades WHERE slug='panaderia'")->fetch_row()[0];
    $admin->query('UPDATE configuracion_pos SET impresion_activa=0 WHERE unidad_id IN ('.$rest.','.$pan.')');

    $all=[]; foreach(PermisosUsuario::catalogo('restaurante') as $module=>$def) $all[$module]=$def['operativo']?'operar':'editar';
    $adminSuper=servir3d4Usuario($admin,'super','restaurante',[],true);
    $adminRest=servir3d4Usuario($admin,'admin-rest','restaurante',$all);
    $readerModules=['menu'=>'ver','productos'=>'ver','inventario'=>'ver','analytics'=>'ver','finanzas'=>'ver','tickets'=>'ver','reservations'=>'ver','configuration'=>'ver','printers'=>'ver'];
    $reader=servir3d4Usuario($admin,'solo-lectura','restaurante',$readerModules);
    $nipSecret=bin2hex(random_bytes(32)); putenv('NIP_LOOKUP_SECRET='.$nipSecret); $_ENV['NIP_LOOKUP_SECRET']=$nipSecret;
    $nip=''.str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT);
    $floor=servir3d4Usuario($admin,'piso','restaurante',['pdv'=>'operar','area'=>'operar','reservations_map'=>'operar'],false,$nip);
    $panMenu=servir3d4Usuario($admin,'pan-menu','panaderia',['menu'=>'editar','productos'=>'editar']);

    // Catálogo preservado 112 queda intacto; las recetas y variantes usan una oferta fixture adicional.
    $category=(int)$admin->query("SELECT id FROM categorias WHERE unidad_id=$rest AND activo=1 ORDER BY orden,id LIMIT 1")->fetch_row()[0];
    $area=(int)$admin->query("SELECT id FROM areas_produccion WHERE unidad_id=$rest ORDER BY orden,id LIMIT 1")->fetch_row()[0];
    $producto=servir3d4Insert($admin,'INSERT INTO productos(nombre,descripcion,img) VALUES (?,?,NULL)',['QA 3D4 Restaurante '.bin2hex(random_bytes(4)),'Oferta fixture adicional de validación 3D.4']);
    $oferta=servir3d4Insert($admin,'INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo,orden) VALUES (?,?,?,?,?,1,9000)',[$producto,$rest,$category,'31.50',$area]);
    $panCategory=servir3d4Insert($admin,"INSERT INTO categorias(unidad_id,carta,nombre,activo,orden) VALUES ($pan,'comida',?,1,9000)",['QA 3D4 Panadería '.bin2hex(random_bytes(3))]);
    $panAreaSlug='qa3d4_'.bin2hex(random_bytes(4));
    $panArea=servir3d4Insert($admin,'INSERT INTO areas_produccion(unidad_id,nombre,slug,color,orden) VALUES (?,?,?,\'#ad7e44\',9000)',[$pan,'Área QA 3D4',$panAreaSlug]);
    $panProduct=servir3d4Insert($admin,'INSERT INTO productos(nombre,descripcion,img) VALUES (?,?,NULL)',['QA 3D4 Panadería '.bin2hex(random_bytes(4)),'Oferta fixture Panadería']);
    $panOffer=servir3d4Insert($admin,'INSERT INTO ofertas_producto(producto_id,unidad_id,categoria_id,precio,area_id,activo,orden) VALUES (?,?,?,?,?,1,9001)',[$panProduct,$pan,$panCategory,'24.00',$panArea]);
    $panArticle=servir3d4Insert($admin,'INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?,\'pza\',4.0000,1)',['QA 3D4 ingrediente Pan '.bin2hex(random_bytes(4))]);
    $panRecipe=servir3d4Insert($admin,'INSERT INTO recetas(oferta_id,unidad_id,version,activo) VALUES (?,?,1,1)',[$panOffer,$pan]);
    servir3d4Insert($admin,'INSERT INTO receta_componentes(receta_id,unidad_id,articulo_id,cantidad) VALUES (?,?,?,\'1.000\')',[$panRecipe,$pan,$panArticle]);
    $article1=servir3d4Insert($admin,'INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?,\'g\',2.5000,1)',['QA 3D4 artículo '.bin2hex(random_bytes(5))]);
    $article2=servir3d4Insert($admin,'INSERT INTO ingredientes(nombre,unidad,costo,activo) VALUES (?,\'ml\',1.2500,1)',['QA 3D4 variante '.bin2hex(random_bytes(5))]);
    $warehouse=servir3d4Insert($admin,'INSERT INTO almacenes(unidad_id,nombre,permitir_negativos,activo) VALUES (?,\'QA 3D4 almacén\',0,1)',[$rest]);
    servir3d4Insert($admin,'INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo) VALUES (?,?,?,\'30.000\',\'2.000\',1)',[$warehouse,$article1,$rest]);
    servir3d4Insert($admin,'INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo) VALUES (?,?,?,\'30.000\',\'2.000\',1)',[$warehouse,$article2,$rest]);
    $recipe=servir3d4Insert($admin,'INSERT INTO recetas(oferta_id,unidad_id,version,activo) VALUES (?,?,1,1)',[$oferta,$rest]);
    servir3d4Insert($admin,'INSERT INTO receta_componentes(receta_id,unidad_id,articulo_id,cantidad) VALUES (?,?,?,\'10.000\')',[$recipe,$rest,$article1]);
    servir3d4Insert($admin,'INSERT INTO producto_variantes(oferta_id,unidad_id,grupo,nombre,precio_delta,predeterminada,orden_grupo,orden,activo) VALUES (?,?,\'QA\',\'Normal\',\'0.00\',1,0,0,1)',[$oferta,$rest]);
    $variant=servir3d4Insert($admin,'INSERT INTO producto_variantes(oferta_id,unidad_id,grupo,nombre,precio_delta,predeterminada,orden_grupo,orden,activo) VALUES (?,?,\'QA\',\'Extra\',\'3.00\',0,0,1,1)',[$oferta,$rest]);
    servir3d4Insert($admin,'INSERT INTO variante_ajustes(variante_id,oferta_id,unidad_id,articulo_id,cantidad,accion) VALUES (?,?,?, ?,\'5.000\',\'agregar\')',[$variant,$oferta,$rest,$article2]);
    servir3d4Insert($admin,'INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES (?,?,?,?)',[$oferta,$article1,$rest,$warehouse]);
    servir3d4Insert($admin,'INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES (?,?,?,?)',[$oferta,$article2,$rest,$warehouse]);
    $mesa=servir3d4Insert($admin,"INSERT INTO mesas(unidad_id,unidad_tipo,numero,nombre,tipo,zona,capacidad,activo,reservable) VALUES ($rest,'negocio',9904,'QA 3D4 Restaurante','mesa','interior',4,1,1)",[]);
    $panMesa=servir3d4Insert($admin,"INSERT INTO mesas(unidad_id,unidad_tipo,numero,nombre,tipo,zona,capacidad,activo,reservable) VALUES ($pan,'negocio',9905,'QA 3D4 Panadería','mesa','interior',4,1,1)",[]);

    $externalSecret=bin2hex(random_bytes(24)); $callbackSecret=bin2hex(random_bytes(24));
    $assetRoute=bin2hex(random_bytes(24)); $sessionOwner=bin2hex(random_bytes(24));
    $sessionPath=sys_get_temp_dir().DIRECTORY_SEPARATOR.'cp-restaurante-3d4-session-'.bin2hex(random_bytes(12));
    if(!mkdir($sessionPath,0700)||is_link($sessionPath)) throw new RuntimeException('No se creó carpeta privada de sesión.');
    file_put_contents($sessionPath.DIRECTORY_SEPARATOR.'owner',$sessionOwner,LOCK_EX);
    $logPath=$sessionPath.DIRECTORY_SEPARATOR.'php-server.log';
    $parentEnv=getenv(); if(!is_array($parentEnv))$parentEnv=[]; $env=[];
    // Hereda solo variables necesarias para iniciar PHP/Windows; no propaga otros secretos del shell.
    $safeNames=['path','systemroot','windir','comspec','pathext','lang'];
    foreach($parentEnv as $key=>$value) if(in_array(strtolower((string)$key),$safeNames,true))$env[$key]=$value;
    $env=array_merge($env,[
        'CP_3D4_TEST_DB'=>$dbName,'CP_3D4_DB_HOST'=>(string)$config['DB_HOST'],'CP_3D4_DB_USER'=>(string)$config['DB_USER'],
        'CP_3D4_DB_PASS'=>(string)$config['DB_PASS'],'CP_3D4_DB_PORT'=>(string)($config['DB_PORT']??3306),
        'CP_3D4_OWNER_TOKEN'=>$dbOwner,'CP_3D4_BASE_ORIGEN'=>(string)$config['DB_NAME'],'CP_3D4_APP_ENV'=>$options['entorno'],
        'CP_3D4_TEMP_ROOT'=>sys_get_temp_dir(),
        'CP_3D4_SESSION_PATH'=>$sessionPath,'CP_3D4_NIP_SECRET'=>$nipSecret,'CP_3D4_EXTERNAL_URL'=>'http://127.0.0.1:9',
        'CP_3D4_EXTERNAL_SECRET'=>$externalSecret,'CP_3D4_CALLBACK_SECRET'=>$callbackSecret,'CP_3D4_ASSET_ROOT'=>$assetRoot,
        'CP_3D4_ASSET_FILES'=>json_encode(array_keys((array)($import['correspondencias']['archivos']??[])),JSON_THROW_ON_ERROR),
        'CP_3D4_ASSET_TOKEN'=>$assetRoute,'CP_3D4_SESSION_OWNER'=>$sessionOwner,
        'SESSION_SAVE_PATH'=>$sessionPath,'NIP_LOOKUP_SECRET'=>$nipSecret,
        'APP_ENV'=>$options['entorno'],'DB_HOST'=>(string)$config['DB_HOST'],'DB_USER'=>(string)$config['DB_USER'],
        'DB_PASS'=>(string)$config['DB_PASS'],'DB_NAME'=>$dbName,'TMP'=>$sessionPath,'TEMP'=>$sessionPath,'TMPDIR'=>$sessionPath,
        'N8N_BASE_URL'=>'http://127.0.0.1:9','N8N_WEBHOOK_SUGERENCIAS_URL'=>'http://127.0.0.1:9/sugerencias',
        'N8N_WEBHOOK_AREAS_MEJORA_URL'=>'http://127.0.0.1:9/areas','IA_N8N_WEBHOOK_URL'=>'http://127.0.0.1:9/ia',
        'N8N_SECRET'=>$externalSecret,'N8N_RESERVATIONS_WEBHOOK_SECRET'=>$externalSecret,'N8N_RESERVATIONS_CALLBACK_SECRET'=>$callbackSecret,
        'SMTP_HOST'=>'','SMTP_USER'=>'','SMTP_PASS'=>'','MAIL_HOST'=>'','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'',
        'META_ACCESS_TOKEN'=>'','WHATSAPP_ACCESS_TOKEN'=>'','IMPRESION_ACTIVA'=>'0'
    ]);
    $router=$root.'/scripts/tests/router-restaurante-3d.php'; $public=$root.'/public';
    $phase='servidor local 127.0.0.1:8093';
    $server=proc_open([PHP_BINARY,'-S','127.0.0.1:8093','-t',$public,$router],[0=>['pipe','r'],1=>['file',$logPath,'a'],2=>['file',$logPath,'a']],$pipes,$root,$env,['create_process_group'=>true]);
    if(!is_resource($server)) throw new RuntimeException('No inició PHP built-in server.');
    $ready=false; $deadline=microtime(true)+15;
    while(microtime(true)<$deadline){$probe=@fsockopen('127.0.0.1',8093,$e,$es,.15);if(is_resource($probe)){fclose($probe);$ready=true;break;}$status=proc_get_status($server);if(!$status['running'])break;usleep(100000);}
    if(!$ready) throw new RuntimeException('Servidor 3D4 no escuchó en loopback.');
    $nonce=bin2hex(random_bytes(20));
    $out=['ok'=>true,'fase'=>'3D.4 HTTP interactivo','url'=>'http://127.0.0.1:8093/','base_temporal'=>$dbName,
        'nonce_limpieza'=>$nonce,'instruccion'=>'El servidor queda esperando STDIN. Pulsa Enter para detenerlo y limpiar DB/sesiones/assets; también acepta LIMPIAR '.$nonce,
        'cuentas'=>['superadmin'=>$adminSuper,'admin_restaurante'=>$adminRest,'solo_lectura_restaurante'=>$reader,'piso_nip'=>$floor,'admin_menu_panaderia'=>$panMenu],
        'fixture'=>['productos_preservados'=>112,'oferta_restaurante'=>$oferta,'oferta_panaderia'=>$panOffer,'producto_restaurante'=>$producto,
            'mesa_restaurante'=>$mesa,'mesa_panaderia'=>$panMesa,'almacen_restaurante'=>$warehouse,'articulo_receta'=>$article1,
            'articulo_variante'=>$article2,'receta_id'=>$recipe,'variante_id'=>$variant,'categoria_panaderia'=>$panCategory,'area_panaderia'=>$panArea,
            'receta_panaderia'=>$panRecipe,'articulo_receta_panaderia'=>$panArticle],
        'impresion'=>'desactivada','integraciones'=>'forzadas a 127.0.0.1:9 con secretos sintéticos'];
    fwrite(STDOUT,Comun::json($out)); fflush(STDOUT);
    $expected='LIMPIAR '.$nonce;
    while(($line=fgets(STDIN))!==false) if(trim($line)===''||hash_equals($expected,trim($line))) break;
} catch(Throwable $e){$exitCode=1;$failure=$e instanceof mysqli_sql_exception?'Error MySQL; código '.$e->getCode().'.':$e->getMessage();}
finally {
    if(is_resource($server)){proc_terminate($server);if(isset($pipes[0])&&is_resource($pipes[0]))fclose($pipes[0]);proc_close($server);}
    $cleanupFailed=false;
    if($admin instanceof mysqli){
        foreach(array_reverse($bases) as [$name,$token]){
            try{if(restaurante3dBasePropia($admin,$name,$token,(string)($config['DB_NAME']??''))){$admin->query('DROP DATABASE '.servir3d4Ident($name));fwrite(STDOUT,"Base temporal verificada y eliminada: {$name}\n");}else $cleanupFailed=true;}catch(Throwable){$cleanupFailed=true;}
        }
        $admin->close();
    }
    if($assetRoot!==null&&$assetParent!==null){try{restaurante3dLimpiarRaizImagenes($assetRoot,$assetParent);if($assetParentCreated){$parent=realpath($assetParent);$temp=realpath(sys_get_temp_dir());if(!$parent||!$temp||dirname($parent)!==$temp||basename($parent)!=='casa-pestalozzi-3d4-assets'||is_link($assetParent))throw new RuntimeException('Padre temporal de assets no verificado.');if(count(scandir($parent)?:[])===2)rmdir($parent);}}catch(Throwable){$cleanupFailed=true;}}
    if($sessionPath!==''&&$sessionOwner!==''){try{servir3d4LimpiarSesion($sessionPath,$sessionOwner);}catch(Throwable){$cleanupFailed=true;}}
    if($cleanupFailed){$exitCode=1;if($failure===null)$failure='Falló la limpieza tras verificar propiedad de los recursos temporales.';}
}
if($failure!==null) fwrite(STDERR,Comun::json(['ok'=>false,'fase'=>$phase,'error'=>$failure]));
exit($exitCode);
