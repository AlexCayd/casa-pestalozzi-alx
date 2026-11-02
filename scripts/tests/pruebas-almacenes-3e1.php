<?php
declare(strict_types=1);

/** Regresiones de formularios de Almacenes; solo bases temporales marcadas. */
function probarAlmacenes3E1(mysqli $db, int &$count): void
{
    $root=dirname(__DIR__,2);
    require_once $root.'/scripts/tests/fixture-3b.php';
    $database=(string)$db->query('SELECT DATABASE()')->fetch_row()[0];
    if(!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$database)) throw new RuntimeException('La prueba requiere una base temporal identificada.');
    $owner=$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
    if(count($owner)!==1 || !preg_match('/^[a-f0-9]{64}$/D',(string)$owner[0][0])) throw new RuntimeException('La prueba requiere la marca privada de propietario.');
    $assert=static function(bool $ok,string $message) use(&$count): void { if(!$ok) throw new RuntimeException($message); $count++; };
    $confirmado=static function(array $r,string $message) use($assert): array { $assert(($r['ok']??false)===true && ($r['confirmado']??false)===true,$message.' Código: '.($r['codigo']??'sin código')); return $r; };
    $sufijo=bin2hex(random_bytes(5));
    $global=FixtureAutorizacion3B::usuario($db,'alm-global-'.$sufijo,['global'=>['permisos'=>['inventario'=>'editar'],'facultades'=>['almacenes.crear','catalogo.editar']]]);
    $rest=FixtureAutorizacion3B::usuario($db,'alm-rest-'.$sufijo,['restaurante'=>['permisos'=>['inventario'=>'editar']]]);
    $pan=FixtureAutorizacion3B::usuario($db,'alm-pan-'.$sufijo,['panaderia'=>['permisos'=>['inventario'=>'editar']]]);
    $lector=FixtureAutorizacion3B::usuario($db,'alm-lector-'.$sufijo,['restaurante'=>['permisos'=>['inventario'=>'ver']]]);
    $sinFacultad=FixtureAutorizacion3B::usuario($db,'alm-global-ver-'.$sufijo,['global'=>['permisos'=>['inventario'=>'editar']]]);
    $servicios=[];
    foreach(['global'=>$global,'restaurante'=>$rest,'panaderia'=>$pan,'lector'=>$lector,'sinFacultad'=>$sinFacultad] as $clave=>$actor) $servicios[$clave]=new \Services\Inventory\AdministracionAlmacenes($db,(int)$actor['id'],'contrasena');
    $almacenes=[];
    foreach(['global','restaurante','panaderia'] as $slug) {
        $r=$confirmado($servicios['global']->guardar('global','crear_almacen',['propietario'=>$slug,'nombre'=>'QA almacén '.$slug.' '.$sufijo]),'Global autorizado crea almacén '.$slug);
        $almacenes[$slug]=(int)$r['almacen_id'];
    }
    $denegado=$servicios['restaurante']->guardar('restaurante','crear_almacen',['propietario'=>'restaurante','nombre'=>'No debe crearse '.$sufijo]);
    $assert(($denegado['ok']??true)===false,'Un negocio no crea almacenes físicos.');
    $denegado=$servicios['sinFacultad']->guardar('global','crear_almacen',['propietario'=>'global','nombre'=>'Sin facultad '.$sufijo]);
    $assert(($denegado['codigo']??'')==='FACULTAD_DENEGADA','Inventario/editar Global no sustituye la facultad de crear almacenes.');
    $r=$confirmado($servicios['global']->guardar('global','crear_articulo',['nombre'=>'QA artículo <seguro> '.$sufijo,'unidad'=>'g','costo'=>'1.2345','activo'=>'1']),'Catálogo maestro creado desde Global');
    $articulo=(int)$r['articulo_id'];
    $denegado=$servicios['panaderia']->guardar('panaderia','crear_articulo',['nombre'=>'No crear '.$sufijo,'unidad'=>'g','costo'=>'1.0000','activo'=>'1']);
    $assert(($denegado['ok']??true)===false,'Panadería no modifica el catálogo maestro.');
    foreach(['global','restaurante','panaderia'] as $slug) {
        $confirmado($servicios[$slug]->guardar($slug,'asociar',['almacen_id'=>(string)$almacenes[$slug],'articulo_id'=>(string)$articulo,'minimo'=>'1.500']),'Asociación propia '.$slug);
        $confirmado($servicios[$slug]->guardar($slug,'entrada',['almacen_id'=>(string)$almacenes[$slug],'articulo_id'=>(string)$articulo,'cantidad'=>'10.125','costo_unitario'=>'2.3456','clave'=>'entrada-'.$slug.'-'.$sufijo]),'Entrada propia '.$slug);
    }
    $motorRest=new \Services\Inventory\Almacenes($db,(int)$rest['id'],'contrasena');
    $saldo=static fn(string $slug): array=>(new \Services\Inventory\Almacenes($db,(int)($slug==='restaurante'?$rest['id']:($slug==='panaderia'?$pan['id']:$global['id'])),'contrasena'))->existencia($slug,$almacenes[$slug],$articulo)['existencia'];
    $assert($saldo('restaurante')['saldo']==='10.125'&&$saldo('panaderia')['saldo']==='10.125','Los saldos físicos son independientes y exactos.');
    $doc=['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'0.125','clave'=>'replay-'.$sufijo];
    $primero=$confirmado($servicios['restaurante']->guardar('restaurante','entrada',$doc),'Primera entrada idempotente');
    $replay=$confirmado($servicios['restaurante']->guardar('restaurante','entrada',$doc),'Reintento de entrada confirmado');
    $assert(!empty($replay['reintento'])&&$replay['operacion_id']===$primero['operacion_id']&&$saldo('restaurante')['saldo']==='10.250','El reintento conserva una sola entrada.');
    $conflicto=$servicios['restaurante']->guardar('restaurante','entrada',array_replace($doc,['cantidad'=>'1.000']));
    $assert(($conflicto['codigo']??'')==='CONFLICTO_IDEMPOTENCIA','Una clave no puede reutilizarse con otra cantidad.');
    foreach(['entrada','ajuste','merma'] as $accion) {
        $r=$servicios['restaurante']->guardar('restaurante',$accion,['almacen_id'=>(string)$almacenes['panaderia'],'articulo_id'=>(string)$articulo,'cantidad'=>'1.000','version'=>'1','motivo'=>'otro','clave'=>'ajeno-'.$accion.'-'.$sufijo]);
        $assert(($r['ok']??true)===false,'Se rechaza almacén Panadería manipulado en '.$accion.'.');
    }
    $r=$servicios['global']->guardar('global','entrada',['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'1.000','clave'=>'global-ajeno-'.$sufijo]);
    $assert(($r['ok']??true)===false,'El consolidado Global no autoriza operaciones físicas de Restaurante.');
    $r=$servicios['lector']->guardar('restaurante','entrada',['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'1.000','clave'=>'lector-'.$sufijo]);
    $assert(($r['ok']??true)===false,'Solo lectura no registra entradas.');
    $antes=$saldo('restaurante');
    $confirmado($servicios['restaurante']->guardar('restaurante','ajuste',['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'9.001','version'=>(string)$antes['version'],'clave'=>'conteo-'.$sufijo]),'Conteo físico con versión vigente');
    $r=$servicios['restaurante']->guardar('restaurante','ajuste',['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'8.000','version'=>(string)$antes['version'],'clave'=>'conteo-viejo-'.$sufijo]);
    $assert(($r['codigo']??'')==='VERSION_CONFLICTO'&&$saldo('restaurante')['saldo']==='9.001','Un conteo obsoleto no pisa el saldo posterior.');
    $confirmado($servicios['restaurante']->guardar('restaurante','merma',['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'0.001','motivo'=>'derrame','nota'=>'Merma QA','clave'=>'merma-'.$sufijo]),'Merma con motivo autorizado');
    $assert($saldo('restaurante')['saldo']==='9.000','La merma conserva la precisión de tres decimales.');
    $r=$servicios['restaurante']->guardar('restaurante','merma',['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'99.000','motivo'=>'otro','clave'=>'negativo-no-'.$sufijo]);
    $assert(($r['codigo']??'')==='SALDO_INSUFICIENTE'&&$saldo('restaurante')['saldo']==='9.000','Negativos bloqueados revierten toda la operación.');
    $confirmado($servicios['restaurante']->guardar('restaurante','configurar_almacen',['almacen_id'=>(string)$almacenes['restaurante'],'nombre'=>'QA local renombrado '.$sufijo,'activo'=>'1','permitir_negativos'=>'1']),'Configuración propia de negativos');
    $actual=$saldo('restaurante');
    $confirmado($servicios['restaurante']->guardar('restaurante','ajuste',['almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'-0.001','version'=>(string)$actual['version'],'clave'=>'negativo-si-'.$sufijo]),'Conteo negativo explícitamente permitido');
    $r=$servicios['restaurante']->guardar('restaurante','configurar_almacen',['almacen_id'=>(string)$almacenes['restaurante'],'nombre'=>'QA local '.$sufijo,'activo'=>'1']);
    $assert(($r['codigo']??'')==='NEGATIVOS_PENDIENTES','No puede apagarse la política con saldos negativos pendientes.');
    $local=$servicios['restaurante']->consultar('restaurante',['q'=>$sufijo]);
    $assert(count($local['existencias']['articulos'])===1&&count($local['existencias']['articulos'][0]['ambitos'])===1&&$local['existencias']['articulos'][0]['ambitos'][0]['slug']==='restaurante','La consulta local excluye los saldos ajenos.');
    $globalDatos=$servicios['global']->consultar('global',['q'=>$sufijo]);
    $assert(count($globalDatos['existencias']['articulos'][0]['ambitos'])===3,'El consolidado desglosa tres ámbitos sin almacén virtual.');
    $nombres=array_column($globalDatos['almacenes']['almacenes'],'nombre');
    $assert(in_array('QA almacén panaderia '.$sufijo,$nombres,true),'Global consulta almacenes de Panadería sin conceder escritura.');
    try { $servicios['restaurante']->consultar('restaurante',['almacen_id'=>(string)$almacenes['panaderia']]); $filtroDenegado=false; } catch(Throwable) { $filtroDenegado=true; }
    $assert($filtroDenegado,'El filtro de almacén ajeno no revela cantidades.');
    $r=$servicios['restaurante']->guardar('restaurante','entrada',['unidad_id'=>'3','almacen_id'=>(string)$almacenes['restaurante'],'articulo_id'=>(string)$articulo,'cantidad'=>'1.000','clave'=>'ctx-manipulado-'.$sufijo]);
    $assert(($r['codigo']??'')==='DATOS_INVALIDOS','El payload no admite identidad de negocio del cliente.');
    $nip=new \Services\Inventory\AdministracionAlmacenes($db,(int)$rest['id'],'nip');
    try { $nip->consultar('restaurante',[]); $nipDenegado=false; } catch(Throwable) { $nipDenegado=true; }
    $assert($nipDenegado,'Una sesión NIP no abre el módulo administrativo de Almacenes.');
    FixtureAutorizacion3B::permiso($db,(int)$pan['id'],'panaderia','inventario',null);
    $r=$servicios['panaderia']->guardar('panaderia','entrada',['almacen_id'=>(string)$almacenes['panaderia'],'articulo_id'=>(string)$articulo,'cantidad'=>'1.000','clave'=>'revocado-'.$sufijo]);
    $assert(($r['ok']??true)===false,'La revocación se aplica a una instancia de servicio ya construida.');
    $rows=$db->query('SELECT aa.saldo,COALESCE(SUM(m.delta),0) AS bitacora FROM almacen_articulos aa LEFT JOIN movimientos_inventario m ON m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id AND m.unidad_id=aa.unidad_id WHERE aa.articulo_id='.$articulo.' GROUP BY aa.almacen_id,aa.articulo_id,aa.saldo')->fetch_all(MYSQLI_ASSOC);
    $assert(count($rows)===3&&count(array_filter($rows,static fn($r)=>bccomp($r['saldo'],$r['bitacora'],3)!==0))===0,'Todos los saldos de prueba corresponden a movimientos registrados.');
    $movimientos=$servicios['global']->consultar('global',['q'=>$sufijo])['movimientos']['movimientos'];
    $propietarios=array_unique(array_column($movimientos,'slug'));
    $assert(count($propietarios)===3,'El historial consolidado incluye propietario, actor y referencia.');
    $readerData=$servicios['lector']->consultar('restaurante',['q'=>$sufijo]);
    $assert(!array_key_exists('costo',$readerData['catalogo']['articulos'][0]),'El selector local del maestro expone identidad y unidad, sin costos maestros de artículos ajenos.');
    $render=static function(array $datos,bool $editar,string $scope,string $tab) use($root): string {
        extract($datos); $contextoAlmacenes=$scope; $nombreContexto=\Services\Security\Contextos::NOMBRES[$scope];
        $base=\Controllers\AdminAlmacenesController::base($scope); $vista=$tab; $filtros=[]; $csrf='csrf-sintetico'; $alertas=[];
        $puedeEditar=$editar; $puedeCrearAlmacen=$editar&&$scope==='global'; $puedeCatalogo=$editar&&$scope==='global';
        ob_start(); include $root.'/views/admin/almacenes/index.php'; return (string)ob_get_clean();
    };
    $html=$render($readerData,false,'restaurante','existencias');
    $assert(!str_contains($html,'method="POST"'),'La vista solo lectura no invita a mutaciones.');
    $assert(str_contains($html,'&lt;seguro&gt;')&&!str_contains($html,'<seguro>'),'La vista escapa los nombres de artículos compartidos.');
    $html=$render($globalDatos,true,'global','existencias');
    $assert(str_contains($html,'Global')&&str_contains($html,'Panadería')&&str_contains($html,'Restaurante'),'El desglose visual mantiene la identificación de los tres ámbitos.');
    $inactiveData=$readerData;
    $inactiveData['existencias']['articulos']=array_slice($readerData['existencias']['articulos'],0,1);
    $inactiveData['existencias']['articulos'][0]['activo']=0;
    $inactiveHtml=$render($inactiveData,true,'restaurante','existencias');
    $assert(str_contains($inactiveHtml,'Artículo inactivo; Global debe reactivarlo'),'Un artículo maestro inactivo explica por qué no admite operaciones físicas.');
    $assert(!str_contains($inactiveHtml,'name="accion" value="entrada"')&&!str_contains($inactiveHtml,'name="accion" value="ajuste"')&&!str_contains($inactiveHtml,'name="accion" value="merma"'),'La interfaz no invita operaciones físicas que el motor rechaza por artículo inactivo.');
    $html=$render($globalDatos,true,'global','almacenes');
    $propiosGlobal=array_values(array_filter($globalDatos['almacenes']['almacenes'],static fn($a)=>$a['slug']==='global'));
    $assert(substr_count($html,'name="accion" value="configurar_almacen"')===count($propiosGlobal),'Global solo ofrece configuración física de sus almacenes propios.');
    foreach($globalDatos['almacenes']['almacenes'] as $warehouse) {
        $hasForm=str_contains($html,'id="almacen-'.(int)$warehouse['almacen_id'].'-nombre"');
        $assert($hasForm===($warehouse['slug']==='global'),'La edición física se ofrece exclusivamente para el propietario del contexto.');
    }
    $unidadRest=(int)$db->query("SELECT id FROM unidades WHERE slug='restaurante'")->fetch_row()[0];
    $insert=$db->prepare('INSERT INTO almacenes(unidad_id,nombre) VALUES (?,?)');
    $ultimo=0;
    for($n=1;$n<=101;$n++) {
        $nombre='zzzz-qa-'.$sufijo.'-'.str_pad((string)$n,3,'0',STR_PAD_LEFT);
        $insert->execute([$unidadRest,$nombre]); $ultimo=(int)$db->insert_id;
    }
    $insert->close();
    $elegido=$servicios['restaurante']->consultar('restaurante',['almacen_id'=>(string)$ultimo]);
    $assert(in_array($ultimo,array_map(static fn($a)=>(int)$a['almacen_id'],$elegido['opcionesAlmacen']),true),'Un almacén seleccionado fuera de las primeras 100 opciones permanece accesible.');
    $paginados=$servicios['restaurante']->consultar('restaurante',['q'=>'zzzz-qa-'.$sufijo,'pagina'=>'5'],'almacenes');
    $assert($paginados['almacenes']['total']===101&&count($paginados['almacenes']['almacenes'])===1&&$paginados['almacenes']['pagina']===5,'La lista de almacenes pagina todos los resultados sin truncarlos.');
    $html=$render($paginados,true,'restaurante','almacenes');
    $assert(str_contains($html,'almacen_id='.$ultimo)&&str_contains($html,'Ver artículos de este almacén'),'El último almacén tiene enlace a sus asociaciones desde la lista paginada.');
}

if(PHP_SAPI==='cli' && realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__) {
    $root=dirname(__DIR__,2);
    require $root.'/vendor/autoload.php';
    require_once $root.'/scripts/menu/bootstrap.php';
    require_once $root.'/scripts/multinegocio/SqlArchivo.php';
    require_once $root.'/scripts/tests/soporte-3d.php';
    Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
    $options=[]; $db=null; $bases=[]; $count=0; $error=null;
    try {
        for($i=1;$i<count($argv);$i+=2) {
            if(!preg_match('/^--(host|base|entorno)$/D',$argv[$i],$m)||!isset($argv[$i+1])||isset($options[$m[1]])) throw new RuntimeException('Uso: --host HOST --base BASE --entorno development|test');
            $options[$m[1]]=$argv[$i+1];
        }
        \MenuPreservacion\Comun::origen($_ENV,$options,$root);
        mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
        $db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],'',(int)($_ENV['DB_PORT']??3306)); $db->set_charset('utf8mb4');
        restaurante3dCrearBase($db,$root,$bases);
        probarAlmacenes3E1($db,$count);
    } catch(Throwable $e) { $error=$e->getMessage(); }
    finally {
        if($db instanceof mysqli) {
            foreach(array_reverse($bases) as [$name,$token]) {
                if(restaurante3dBasePropia($db,$name,$token,(string)($_ENV['DB_NAME']??''))) { $db->query('DROP DATABASE '.restaurante3dIdent($name)); fwrite(STDOUT,"Base temporal verificada y eliminada: $name\n"); }
                else $error='No se verificó la propiedad para limpiar la base temporal.';
            }
            $db->close();
        }
    }
    echo json_encode(['ok'=>$error===null,'fase'=>'3E.1 Almacenes','comprobaciones'=>$count,'error'=>$error],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
    exit($error===null?0:1);
}
