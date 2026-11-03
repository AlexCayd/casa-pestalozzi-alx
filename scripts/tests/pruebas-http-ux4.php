<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';require __DIR__.'/cliente-http-3e.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2).'/includes')->safeLoad();
if(PHP_SAPI!=='cli'||count($argv)!==3||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ARCHIVO_PRIVADO');
$f=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);$base=$f['base_temporal']??'';
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$base)||$base===($_ENV['DB_NAME']??''))throw new RuntimeException('Harness no identificado.');
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=new mysqli($_ENV['DB_HOST'],$_ENV['DB_USER'],$_ENV['DB_PASS'],$base,(int)($_ENV['DB_PORT']??3306));$db->set_charset('utf8mb4');
$m=$db->query('SELECT entorno,propietario_token FROM instalacion_desarrollo WHERE id=1')->fetch_assoc();$owner=$db->query('SELECT token FROM _cp_multinegocio_owner')->fetch_row()[0];if($m['entorno']!=='test'||!hash_equals($owner,$m['propietario_token']))throw new RuntimeException('Base ajena al harness.');
use Services\Inventory\Articulos as A;
use Services\Security\ContextoNegocio as C;
use Services\Security\RetornoAdministrativo as R;

$n=0;$clients=[];$restore=[];$checks=[];
$a=static function(bool $ok,string $message)use(&$n,&$checks){if(!$ok)throw new RuntimeException($message);$n++;$checks[]=$message;};
$valid=static fn($r)=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
$hash=static fn(string $table)=>A::huella($db->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_ASSOC));
try {
    Model\ActiveRecord::setDB($db);session_start();$_SESSION=['id'=>(int)$db->query("SELECT id FROM usuarios WHERE username='admin_demo'")->fetch_row()[0],'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
    $admin=new ClienteInstalacion3E();$clients[]=$admin;$admin->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']]);
    $balances=$hash('almacen_articulos');$movements=$hash('movimientos_inventario');$versions=$hash('articulo_recetas');$components=$hash('articulo_receta_componentes');
    foreach(['global','restaurante','panaderia'] as $ctx){
        $prefix=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;$base=$prefix.'/almacenes/catalogo';
        $r=$admin->request('GET',$base.'?q=Leche&estado=activos');$a($valid($r)&&str_contains($r['body'],'Leche compartida')&&!str_contains($r['body'],'Cacao retirado'),'Buscar y filtrar maestro '.$ctx);
        $r=$admin->request('GET',$base.'?q=no_existe_ux4');$a($valid($r)&&str_contains($r['body'],'No hay artículos con esta búsqueda'),'Estado vacío '.$ctx);
        $r=$admin->request('GET',$base.'?estado=inactivos');$a($valid($r)&&str_contains($r['body'],'Cacao retirado'),'Filtro inactivos '.$ctx);
        $r=$admin->request('GET',$prefix.'/recetas/articulos');$a($valid($r)&&!str_contains($r['body'],'opcionesVersiones'),'Catálogo M2 reutilizado '.$ctx);
        foreach([1,2] as $page){$list=A::catalogo($ctx,'',$page);foreach($list['articulos'] as $row)$a($row['estadoEfectivo']===A::estadoAdministrativo($ctx,(int)$row['id']),'Paridad lote/lector efectivo '.$ctx.' '.$row['id']);}
        $list=A::catalogo($ctx,'',1);$rows=count($list['articulos']);$a($rows<=30,'Paginación acotada '.$ctx);
        $r=$admin->request('GET',$base.'/editar?id=1002');$a($valid($r)&&str_contains($r['body'],'Dónde se utiliza'),'Identidad y usos '.$ctx);
        if($ctx!=='global'){$a(!str_contains($r['body'],'name="costo"')&&!str_contains($r['body'],'>Guardar identidad<'),'Sin edición ni costo maestro local '.$ctx);$token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$a($admin->request('POST',$base.'/editar?id=1002',['m2_csrf'=>$token,'nombre'=>'Intento local','unidad'=>'ml','costo'=>'0','activo'=>1])['status']===403,'Incluso superadmin edita maestro solo desde Global '.$ctx);}
        $r=$admin->request('GET',$prefix.'/almacenes');$a($valid($r)&&!str_contains($r['body'],'id="almacen-catalogo"')&&!str_contains($r['body'],'data-admin-modal-open="almacen-nuevo-articulo"'),'Sin catálogos modales obsoletos '.$ctx);
        $r=$admin->request('GET',$base.'/editar?id=99999999');$a($r['status']===404,'ID maestro inexistente '.$ctx);
        $r=$admin->request('GET',$base.'/editar?id[]=1001');$a(in_array($r['status'],[400,403],true),'ID estructurado rechazado '.$ctx);
    }
    $a($balances===$hash('almacen_articulos')&&$movements===$hash('movimientos_inventario'),'Consulta de catálogo/usos no cambia saldos ni movimientos');
    $a($versions===$hash('articulo_recetas')&&$components===$hash('articulo_receta_componentes'),'Consulta no cambia historia M2');
    $base='/admin/global/almacenes/catalogo';$origin='/admin/global/almacenes?seleccion=1&almacen_ids%5B0%5D=1001&q=Harina';
    $create=$base.'/nuevo?'.http_build_query(['volver'=>$origin]);$r=$admin->request('GET',$create);$token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$name='Artículo UX4 '.bin2hex(random_bytes(4));$data=['m2_csrf'=>$token,'nombre'=>$name,'unidad'=>'g','costo'=>'0.0000','activo'=>1];
    $a($admin->request('POST',$create,array_replace($data,['m2_csrf'=>'invalido']))['status']===403,'CSRF inválido no crea identidad');
    $r=$admin->request('POST',$create,$data);$a($r['status']===303&&str_contains($r['location'],'/catalogo/editar?id='),'Alta continúa en identidad exacta');parse_str(parse_url($r['location'],PHP_URL_QUERY),$params);$id=(int)$params['id'];$a(($params['volver']??'')!==''&&str_contains($params['volver'],'almacen_ids'),'Alta conserva selección de origen');
    $page=$admin->request('GET',$r['location']);$a($valid($page)&&str_contains($page['body'],'Artículo creado sin existencias')&&str_contains($page['body'],'/recetas/articulos/editar?'),'Alta ofrece capacidades autorizadas');
    $a(!str_contains($admin->request('GET',$r['location'])['body'],'Artículo creado sin existencias'),'Mensaje de resultado consumible');
    $duplicate=$admin->request('POST',$create,$data);$a($duplicate['status']===422&&str_contains($duplicate['body'],'ya existe')&&str_contains($duplicate['body'],$name)&&!str_contains($duplicate['body'],'Artículo creado sin existencias'),'Duplicado rechazado conserva valores y no muestra éxito');
    $a((int)$db->execute_query('SELECT COUNT(*) FROM ingredientes WHERE nombre=?',[$name])->fetch_row()[0]===1,'Una sola identidad por nombre');
    $edit=$base.'/editar?id='.$id;$r=$admin->request('GET',$edit);$data['m2_csrf']=ClienteInstalacion3E::token($r['body'],'m2_csrf');$data['nombre']=$name.' revisado';$r=$admin->request('POST',$edit,$data);$a($r['status']===303,'Editar identidad autorizada');
    $a($balances===$hash('almacen_articulos')&&$movements===$hash('movimientos_inventario'),'Crear y editar identidad no toca existencias');
    $a($versions===$hash('articulo_recetas')&&$components===$hash('articulo_receta_componentes'),'Editar identidad no reversiona recetas');
    $r=$admin->request('GET',$base.'/editar?id=1001');$token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$r=$admin->request('POST',$base.'/editar?id=1001',['m2_csrf'=>$token,'nombre'=>'Harina compartida','unidad'=>'ml','costo'=>'0.0400','activo'=>1]);$a($r['status']===422&&str_contains($r['body'],'unidad no puede cambiar'),'Unidad con movimientos inmutable');
    // Operaciones físicas siguen pasando exclusivamente por el escritor previo.
    $r=$admin->operation('/admin/almacenes','asociar',['almacen_id'=>1002,'articulo_id'=>$id,'minimo'=>'1.000']);$a($valid($r)&&str_contains($r['body'],'Operación confirmada'),'Asociar desde Almacenes');
    $r=$admin->operation('/admin/almacenes','entrada',['almacen_id'=>1002,'articulo_id'=>$id,'cantidad'=>'5.000','costo_unitario'=>'0.2000','clave'=>bin2hex(random_bytes(16)),'nota'=>'Entrada sintética UX4']);$a($valid($r)&&str_contains($r['body'],'Operación confirmada'),'Entrada después de alta persistente');
    $r=$admin->operation('/admin/almacenes','merma',['almacen_id'=>1002,'articulo_id'=>$id,'cantidad'=>'1.000','motivo'=>'otro','clave'=>bin2hex(random_bytes(16)),'nota'=>'Merma sintética UX4']);$a($valid($r)&&str_contains($r['body'],'Operación confirmada'),'Merma después de alta persistente');
    $a($db->execute_query('SELECT saldo FROM almacen_articulos WHERE articulo_id=? AND almacen_id=1002',[$id])->fetch_row()[0]==='4.000','Saldo físico exacto tras entrada y merma');
    $r=$admin->request('GET','/admin/recetas/articulos/editar?id='.$id);$a($valid($r)&&str_contains($r['body'],'Con asociación y existencias registradas'),'Ficha heredada refleja asociación sin escritura M2');
    $balances=$hash('almacen_articulos');$movements=$hash('movimientos_inventario');$r=$admin->request('POST',$edit,$data);$a($r['status']===303&&$balances===$hash('almacen_articulos')&&$movements===$hash('movimientos_inventario'),'Editar nombre conserva saldo previo y ledger');
    foreach(['global','restaurante','panaderia'] as $ctx){
        $prefix=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;
        foreach(['lectura','operador'] as $type){
            $client=new ClienteInstalacion3E();$clients[]=$client;$client->login($f['cuentas'][$type.'_'.$ctx]);
            $r=$client->request('GET',$prefix.'/almacenes/catalogo');$a($valid($r)&&!str_contains($r['body'],'>Nuevo artículo<'),'Inventario sin productos consulta catálogo '.$ctx.' '.$type);
            $a(in_array($client->request('GET',$prefix.'/almacenes/catalogo/nuevo')['status'],[403,303],true),'Sin facultad Global no abre alta '.$ctx.' '.$type);
            $r=$client->request('GET',$prefix.'/almacenes/catalogo/editar?id=1001');$a($valid($r)&&!str_contains($r['body'],'>Guardar identidad<')&&!str_contains($r['body'],'>Capacidades y elaboración<'),'Maestro solo consulta con permisos parciales '.$ctx.' '.$type);
            $token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$a($client->request('POST',$prefix.'/almacenes/catalogo/editar?id=1001',['m2_csrf'=>$token,'nombre'=>'No autorizado','unidad'=>'g','costo'=>'0','activo'=>1])['status']===403,'POST maestro sin facultad rechazado '.$ctx.' '.$type);
            if($ctx!=='global')$a(in_array($client->request('GET','/admin/global/almacenes/catalogo')['status'],[403,303],true),'Sin acceso Global '.$ctx.' '.$type);
        }
    }
    $global=new ClienteInstalacion3E();$clients[]=$global;$global->login($f['cuentas']['administrador_global']);$r=$global->request('GET',$base.'/editar?id='.$id);$a($valid($r)&&str_contains($r['body'],'>Guardar identidad<')&&!str_contains($r['body'],'>Capacidades y elaboración<'),'Administrador Global edita identidad sin conceder productos');
    // Retornos: filtros, selección y IDs del mismo contexto se validan en servidor.
    $a(R::autorizado('/admin/almacenes?seleccion=1&almacen_ids%5B0%5D=1002&articulo_id='.$id,'restaurante')!==null,'Retorno físico propio autorizado');
    $a(R::autorizado('/admin/almacenes?almacen_ids%5B0%5D=1003','restaurante')===null,'Almacén ajeno rechazado en retorno');
    $a(R::autorizado('/admin/almacenes?almacen_ids%5B0%5D=1002&almacen_ids%5B1%5D=1003','restaurante')===null,'Retorno con selección mixta rechazado');
    foreach(['https://example.com','//example.com','/admin/almacenes?almacen_ids[x]=1002','/admin/almacenes?desde=2026-02-31','/admin/almacenes/catalogo?almacen_id=1002','/admin/almacenes/catalogo/editar?id=99999999'] as $bad)$a(R::autorizado($bad,'restaurante')===null,'Retorno no autorizado '.$bad);
    $r=$admin->request('GET','/admin/global/almacenes?vista=catalogo&q=Leche');$a($r['status']===302&&str_contains($r['location'],'/almacenes/catalogo?q=Leche'),'Acceso anterior al modal redirige al catálogo filtrado');
    $r=$admin->request('GET','/admin/global/catalogo');$a($r['status']===302,'Alias antiguo conserva destino compatible');

    foreach(['Leche',''] as $search){$start=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];A::catalogo('restaurante',$search);$end=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];$questions[$search]=$end-$start-1;}
    $a($questions['']<=$questions['Leche']+1,'Número de consultas constante entre una y treinta filas');
    // Un permiso de recetas no concede inventario; modificaciones solo del perfil sintético y restauradas.
    $account=$f['cuentas']['lectura_restaurante'];$uid=(int)$account['id'];$unit=C::unidad('restaurante');
    foreach(['inventario','productos'] as $module){$before=$db->execute_query('SELECT nivel FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,$module])->fetch_row();$restore[]=[$uid,$unit,$module,$before[0]??null];}
    $db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,'inventario']);
    $db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)',[$uid,$unit,'productos','ver']);
    $only=new ClienteInstalacion3E();$clients[]=$only;$only->login($account);
    $r=$only->request('GET','/admin/recetas/articulos/editar?id=1002');$a($valid($r)&&!str_contains($r['body'],'>Identidad y usos<')&&!str_contains($r['body'],'>Asociaciones y existencias<'),'Productos sin Inventario conserva ficha y oculta enlaces físicos');
    $a(in_array($only->request('GET','/admin/almacenes/catalogo')['status'],[403,303],true),'Productos no concede acceso al maestro de Inventario');
    $u=C::unidad('global');$uid=(int)$f['cuentas']['operador_panaderia']['id'];
    $db->execute_query('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES(?,?,NULL,1)',[$uid,$u]);$globalTemporal=[$uid,$u];
    $db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?)',[$uid,$u,'inventario','editar']);
    $db->execute_query('INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES(?,?,?)',[$uid,$u,'catalogo.editar']);
    $local=new ClienteInstalacion3E();$clients[]=$local;$local->login($f['cuentas']['operador_panaderia']);
    $path='/admin/panaderia/almacenes/catalogo/nuevo';$r=$local->request('GET',$path);$a($valid($r),'Alta local exige y reconoce ambas autorizaciones explícitas');
    $token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$r=$local->request('POST',$path,['m2_csrf'=>$token,'nombre'=>'Alta local UX4 '.bin2hex(random_bytes(4)),'unidad'=>'pza','costo'=>'0.0000','activo'=>1]);
    $a($r['status']===303&&str_starts_with($r['location'],'/admin/panaderia/almacenes/catalogo/editar?id='),'Alta autorizada conserva contexto Panadería');
    $r=$local->request('GET',$r['location']);$a($valid($r)&&!str_contains($r['body'],'>Guardar identidad<')&&!str_contains($r['body'],'>Capacidades y elaboración<'),'Alta local no concede edición maestra ni productos por implicación');
    echo json_encode(['ok'=>true,'comprobaciones'=>$n,'resultados'=>$checks,'articulo_creado'=>$id],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {foreach($clients as $client)$client->close();foreach($restore as [$uid,$unit,$module,$before]){if($before===null)$db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,$module]);else $db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)',[$uid,$unit,$module,$before]);}if(isset($globalTemporal)){[$uid,$unit]=$globalTemporal;$db->execute_query('DELETE FROM usuario_unidad_facultades WHERE usuario_id=? AND unidad_id=?',[$uid,$unit]);$db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=?',[$uid,$unit]);$db->execute_query('DELETE FROM usuario_unidades WHERE usuario_id=? AND unidad_id=?',[$uid,$unit]);}if(session_status()===PHP_SESSION_ACTIVE)session_destroy();$db->close();}
