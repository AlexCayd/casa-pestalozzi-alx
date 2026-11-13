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

$n=0;$clients=[];$restore=[];$checks=[];$sinProductos=[];
// El fixture concede Artículos y preparaciones a sus cuentas: el contraste «sólo Almacenes» lo retira y lo devuelve.
$devolverProductos=static function()use(&$sinProductos,$db){foreach($sinProductos as [$uid,$unit,$nivel])$db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)',[$uid,$unit,'productos',$nivel]);$sinProductos=[];};
$retirarProductos=static function(int $uid,int $unit)use(&$sinProductos,$db){$nivel=$db->execute_query('SELECT nivel FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,'productos'])->fetch_row()[0]??null;if($nivel===null)return;$sinProductos[]=[$uid,$unit,$nivel];$db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,'productos']);};
$a=static function(bool $ok,string $message)use(&$n,&$checks){if(!$ok)throw new RuntimeException($message);$n++;$checks[]=$message;};
$valid=static fn($r)=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
// Filas de la lista: en Restaurante la página también lleva el selector de preparaciones anteriores, que nombra todos los artículos.
$tabla=static fn(string $body):string=>(string)ClienteInstalacion3E::dom($body)->evaluate('string(//table[contains(@class,"m6-tabla")]/tbody)');
$hash=static fn(string $table)=>A::huella($db->query('SELECT * FROM '.$table)->fetch_all(MYSQLI_ASSOC));
try {
    Model\ActiveRecord::setDB($db);session_start();$_SESSION=['id'=>(int)$db->query("SELECT id FROM usuarios WHERE username='admin_demo'")->fetch_row()[0],'auth_nivel'=>'contrasena','_auth3b'=>true,'login'=>true];
    $admin=new ClienteInstalacion3E();$clients[]=$admin;$admin->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']]);
    $balances=$hash('almacen_articulos');$movements=$hash('movimientos_inventario');$versions=$hash('articulo_recetas');$components=$hash('articulo_receta_componentes');
    foreach(['global','restaurante','panaderia'] as $ctx){
        $prefix=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;$base=$prefix.'/almacenes/catalogo';$lista=$prefix.'/recetas/articulos';
        // Una sola lista y una sola ficha (§18): con Artículos y preparaciones, el catálogo de Almacenes lleva a ellas.
        $r=$admin->request('GET',$base.'?q=Leche&estado=activos');parse_str((string)parse_url((string)$r['location'],PHP_URL_QUERY),$destino);$a($r['status']===303&&parse_url($r['location'],PHP_URL_PATH)===$lista&&($destino['q']??'')==='Leche'&&($destino['estado']??'')==='activos','El catálogo lleva a la lista única con sus filtros '.$ctx);
        $r=$admin->request('GET',$lista.'?q=Leche&estado=activos');$a($valid($r)&&str_contains($tabla($r['body']),'Leche compartida')&&!str_contains($tabla($r['body']),'Cacao retirado'),'Buscar y filtrar la lista '.$ctx);
        $r=$admin->request('GET',$lista.'?q=no_existe_ux4');$a($valid($r)&&str_contains($r['body'],'No hay artículos con esta búsqueda'),'Estado vacío '.$ctx);
        $r=$admin->request('GET',$lista.'?estado=inactivos');$a($valid($r)&&str_contains($tabla($r['body']),'Cacao retirado')&&!str_contains($tabla($r['body']),'Leche compartida'),'Filtro inactivos '.$ctx);
        $a($admin->request('GET',$lista.'?tipo=no_existe')['status']===400,'Filtro de tipo fuera de lista rechazado '.$ctx);
        $r=$admin->request('GET',$prefix.'/recetas/articulos');$a($valid($r)&&!str_contains($r['body'],'opcionesVersiones'),'Catálogo M2 reutilizado '.$ctx);
        foreach([1,2] as $page){$list=A::catalogo($ctx,'',$page);foreach($list['articulos'] as $row)$a($row['estadoEfectivo']===A::estadoAdministrativo($ctx,(int)$row['id']),'Paridad lote/lector efectivo '.$ctx.' '.$row['id']);}
        $list=A::catalogo($ctx,'',1);$rows=count($list['articulos']);$a($rows>0&&$rows<=A::POR_PAGINA&&A::POR_PAGINA<=15&&$list['total']>=$rows,'Paginación acotada a quince '.$ctx);
        $xp=ClienteInstalacion3E::dom($admin->request('GET',$lista)['body']);$a($xp->query('//table[contains(@class,"m6-tabla")]/tbody/tr')->length===$rows,'La lista pinta una sola página '.$ctx);
        $r=$admin->request('GET',$base.'/editar?id=1002');$a($r['status']===303&&str_starts_with((string)$r['location'],$lista.'/editar?id=1002'),'La identidad del catálogo lleva a la ficha única '.$ctx);
        $r=$admin->request('GET',$r['location']);$a($valid($r)&&str_contains($r['body'],'Dónde se usa')&&str_contains($r['body'],'>Datos generales<')&&str_contains($r['body'],'¿Cómo se obtiene?'),'Ficha única: datos, uso y dónde se usa '.$ctx);
        if($ctx!=='global'){$a(!str_contains($r['body'],'name="costo"')&&!str_contains($r['body'],'>Guardar datos generales<'),'Sin edición ni costo maestro local '.$ctx);$token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$a($admin->request('POST',$base.'/editar?id=1002',['m2_csrf'=>$token,'nombre'=>'Intento local','unidad'=>'ml','costo'=>'0','activo'=>1])['status']===403,'Incluso superadmin edita maestro solo desde Global '.$ctx);}
        $r=$admin->request('GET',$prefix.'/almacenes');$a($valid($r)&&!str_contains($r['body'],'id="almacen-catalogo"')&&!str_contains($r['body'],'data-admin-modal-open="almacen-nuevo-articulo"'),'Sin catálogos modales obsoletos '.$ctx);
        $a($admin->request('GET',$lista.'/editar?id=99999999')['status']===404,'ID maestro inexistente '.$ctx);
        $r=$admin->request('GET',$base.'/editar?id[]=1001');$a(in_array($r['status'],[400,403],true),'ID estructurado rechazado '.$ctx);
    }
    $a($balances===$hash('almacen_articulos')&&$movements===$hash('movimientos_inventario'),'Consulta de catálogo/usos no cambia saldos ni movimientos');
    $a($versions===$hash('articulo_recetas')&&$components===$hash('articulo_receta_componentes'),'Consulta no cambia historia M2');
    $base='/admin/global/almacenes/catalogo';$origin='/admin/global/almacenes?seleccion=1&almacen_ids%5B0%5D=1001&q=Harina';
    $create=$base.'/nuevo?'.http_build_query(['volver'=>$origin]);$r=$admin->request('GET',$create);$token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$name='Artículo UX4 '.bin2hex(random_bytes(4));$data=['m2_csrf'=>$token,'nombre'=>$name,'unidad'=>'g','costo'=>'0.0000','activo'=>1];$alta=['m2_csrf'=>$token,'nombre'=>$name,'unidad'=>'g','costo'=>'','tipo'=>'insumo'];
    $a($valid($r)&&str_contains($r['body'],'¿Cómo se obtiene?')&&str_contains($r['body'],'data-m6-alta'),'Alta en un paso también como página de respaldo');
    $a($admin->request('POST',$create,array_replace($alta,['m2_csrf'=>'invalido']))['status']===403,'CSRF inválido no crea identidad');
    $a($admin->request('POST',$create,array_replace($alta,['tipo'=>'otro']))['status']===422&&(int)$db->execute_query('SELECT COUNT(*) FROM ingredientes WHERE nombre=?',[$name])->fetch_row()[0]===0,'Tipo fuera de lista no crea identidad');
    $r=$admin->request('POST',$create,$alta);$a($r['status']===303&&str_starts_with($r['location'],'/admin/global/recetas/articulos/editar?id='),'Alta desde Almacenes continúa en la ficha del artículo');parse_str(parse_url($r['location'],PHP_URL_QUERY),$params);$id=(int)$params['id'];$a(($params['volver']??'')!==''&&str_contains($params['volver'],'almacen_ids'),'Alta conserva selección de origen');
    $page=$admin->request('GET',$r['location']);$a($valid($page)&&str_contains($page['body'],'Artículo creado sin existencias')&&str_contains($page['body'],'>Guardar uso<'),'Alta aterriza en la ficha con su resultado');
    // El alta no escribe configuración: el artículo nace con el estado efectivo por omisión.
    $conf=A::estadoAdministrativo('global',$id)['configuracion'];
    $a((int)$conf['componente']===1&&(int)$conf['almacenable']===1&&(int)$conf['elaborable']===0&&(int)$conf['costo_conocido']===0&&(int)$conf['activo']===1&&(int)$db->execute_query('SELECT COUNT(*) FROM articulo_configuraciones WHERE articulo_id=?',[$id])->fetch_row()[0]===0,'Alta sin costo nace utilizable en recetas y con costo desconocido');
    $a(!str_contains($admin->request('GET',$r['location'])['body'],'Artículo creado sin existencias'),'Mensaje de resultado consumible');
    $duplicate=$admin->request('POST',$create,$alta);$a($duplicate['status']===422&&str_contains($duplicate['body'],'ya existe')&&str_contains($duplicate['body'],$name)&&!str_contains($duplicate['body'],'Artículo creado sin existencias'),'Duplicado rechazado conserva valores y no muestra éxito');
    $a((int)$db->execute_query('SELECT COUNT(*) FROM ingredientes WHERE nombre=?',[$name])->fetch_row()[0]===1,'Una sola identidad por nombre');
    $edit=$base.'/editar?id='.$id;$fichaNueva='/admin/global/recetas/articulos/editar?id='.$id;$r=$admin->request('GET',$fichaNueva);$a($valid($r)&&str_contains($r['body'],'>Guardar datos generales<'),'Global edita los datos generales en la ficha');$data['m2_csrf']=ClienteInstalacion3E::token($r['body'],'m2_csrf');$data['nombre']=$name.' revisado';$r=$admin->request('POST',$edit,$data);$a($r['status']===303&&str_starts_with($r['location'],$fichaNueva),'Editar identidad autorizada vuelve a la ficha');$a(str_contains($admin->request('GET',$r['location'])['body'],'Datos generales guardados.'),'Resultado de los datos generales');
    $a($balances===$hash('almacen_articulos')&&$movements===$hash('movimientos_inventario'),'Crear y editar identidad no toca existencias');
    $a($versions===$hash('articulo_recetas')&&$components===$hash('articulo_receta_componentes'),'Editar identidad no reversiona recetas');
    $r=$admin->request('GET','/admin/global/recetas/articulos/editar?id=1001');$token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$r=$admin->request('POST',$base.'/editar?id=1001',['m2_csrf'=>$token,'nombre'=>'Harina compartida','unidad'=>'ml','costo'=>'0.0400','activo'=>1]);
    // El rechazo vuelve a la ficha (PRG) con lo capturado y el motivo; nada se guarda.
    $a($r['status']===303&&str_ends_with($r['location'],'#identidad')&&str_contains($admin->request('GET',$r['location'])['body'],'unidad no puede cambiar')&&$db->query('SELECT unidad FROM ingredientes WHERE id=1001')->fetch_row()[0]==='g','Unidad con movimientos inmutable');
    // Operaciones físicas siguen pasando exclusivamente por el escritor previo.
    $r=$admin->operation('/admin/almacenes','asociar',['almacen_id'=>1002,'articulo_id'=>$id,'minimo'=>'1.000']);$a($valid($r)&&str_contains($r['body'],'Operación confirmada'),'Asociar desde Almacenes');
    $r=$admin->operation('/admin/almacenes','entrada',['almacen_id'=>1002,'articulo_id'=>$id,'cantidad'=>'5.000','costo_unitario'=>'0.2000','clave'=>bin2hex(random_bytes(16)),'nota'=>'Entrada sintética UX4']);$a($valid($r)&&str_contains($r['body'],'Operación confirmada'),'Entrada después de alta persistente');
    $r=$admin->operation('/admin/almacenes','merma',['almacen_id'=>1002,'articulo_id'=>$id,'cantidad'=>'1.000','motivo'=>'otro','clave'=>bin2hex(random_bytes(16)),'nota'=>'Merma sintética UX4']);$a($valid($r)&&str_contains($r['body'],'Operación confirmada'),'Merma después de alta persistente');
    $a($db->execute_query('SELECT saldo FROM almacen_articulos WHERE articulo_id=? AND almacen_id=1002',[$id])->fetch_row()[0]==='4.000','Saldo físico exacto tras entrada y merma');
    $r=$admin->request('GET','/admin/recetas/articulos/editar?id='.$id);$a($valid($r)&&str_contains($r['body'],htmlspecialchars('almacen_id=1002&articulo_id='.$id)),'La ficha refleja la asociación sin escritura M2');
    $balances=$hash('almacen_articulos');$movements=$hash('movimientos_inventario');$r=$admin->request('POST',$edit,$data);$a($r['status']===303&&$balances===$hash('almacen_articulos')&&$movements===$hash('movimientos_inventario'),'Editar nombre conserva saldo previo y ledger');
    foreach(['global','restaurante','panaderia'] as $ctx){
        $prefix=$ctx==='restaurante'?'/admin':'/admin/'.$ctx;
        foreach(['lectura','operador'] as $type){
            $client=new ClienteInstalacion3E();$clients[]=$client;$client->login($f['cuentas'][$type.'_'.$ctx]);
            // Con Artículos y preparaciones el catálogo lleva a la lista única; sin él es su pantalla propia, en la misma sesión.
            $r=$client->request('GET',$prefix.'/almacenes/catalogo');$a($r['status']===303&&$r['location']===$prefix.'/recetas/articulos','Con productos el catálogo redirige '.$ctx.' '.$type);
            $r=$client->request('GET',$prefix.'/recetas/articulos');$a($valid($r)&&!str_contains($r['body'],'data-m6-abrir-alta')&&!str_contains($r['body'],'data-m6-alta'),'Sin facultad Global la lista no ofrece alta '.$ctx.' '.$type);
            $r=$client->request('GET',$prefix.'/recetas/articulos/editar?id=1001');$a($valid($r)&&!str_contains($r['body'],'>Guardar datos generales<')&&($type==='operador'&&$ctx!=='global'||!str_contains($r['body'],'>Guardar uso<')),'Ficha sin edición maestra '.$ctx.' '.$type);
            // El token es de la sesión: la identidad de solo consulta ya no pinta ningún formulario.
            $token=ClienteInstalacion3E::token($r['body'],'m2_csrf');
            $retirarProductos((int)$f['cuentas'][$type.'_'.$ctx]['id'],C::unidad($ctx));
            $r=$client->request('GET',$prefix.'/almacenes/catalogo');$a($valid($r)&&!str_contains($r['body'],'>Nuevo artículo<')&&!str_contains($r['body'],'data-m6-alta')&&!str_contains($r['body'],'>Se usa en<'),'Inventario sin productos consulta catálogo '.$ctx.' '.$type);
            $a(in_array($client->request('GET',$prefix.'/almacenes/catalogo/nuevo')['status'],[403,303],true),'Sin facultad Global no abre alta '.$ctx.' '.$type);
            $r=$client->request('GET',$prefix.'/almacenes/catalogo/editar?id=1001');$a($valid($r)&&!str_contains($r['body'],'>Guardar datos generales<')&&!str_contains($r['body'],'name="accion" value="clasificar"')&&str_contains($r['body'],'Ver las recetas que lo usan requiere permiso'),'Maestro solo consulta con permisos parciales '.$ctx.' '.$type);
            $a(!str_contains($r['body'],'name="m2_csrf"'),'Identidad de solo consulta sin formularios '.$ctx.' '.$type);$a($client->request('POST',$prefix.'/almacenes/catalogo/editar?id=1001',['m2_csrf'=>$token,'nombre'=>'No autorizado','unidad'=>'g','costo'=>'0','activo'=>1])['status']===403,'POST maestro sin facultad rechazado '.$ctx.' '.$type);
            if($ctx!=='global')$a(in_array($client->request('GET','/admin/global/almacenes/catalogo')['status'],[403,303],true),'Sin acceso Global '.$ctx.' '.$type);
        }
    }
    $global=new ClienteInstalacion3E();$clients[]=$global;$global->login($f['cuentas']['administrador_global']);$retirarProductos((int)$f['cuentas']['administrador_global']['id'],C::unidad('global'));
    $r=$global->request('GET',$base);$xp=ClienteInstalacion3E::dom($r['body']);$a($valid($r)&&$xp->query('//a[@data-m6-abrir-alta]')->length===1&&$xp->query('//form[@data-m6-alta]//input[@name="tipo" and @value="preparacion" and @disabled]')->length===1&&!str_contains($r['body'],'>Se usa en<'),'Sólo Almacenes: catálogo con alta de insumos y sin lectura de recetas');
    $r=$global->request('GET',$base.'/editar?id='.$id);$a($valid($r)&&str_contains($r['body'],'>Guardar datos generales<')&&!str_contains($r['body'],'name="accion" value="clasificar"')&&str_contains($r['body'],'Ver las recetas que lo usan requiere permiso'),'Administrador Global edita identidad sin conceder productos');
    $data['m2_csrf']=ClienteInstalacion3E::token($r['body'],'m2_csrf');$r=$global->request('POST',$base.'/editar?id='.$id,$data);$a($r['status']===303&&str_starts_with($r['location'],$base.'/editar?id='.$id)&&str_contains($global->request('GET',$r['location'])['body'],'Identidad maestra guardada.'),'Sólo Almacenes guarda la identidad en su propia ficha');
    $a(in_array($global->request('GET','/admin/global/recetas/articulos/editar?id='.$id)['status'],[403,303],true),'Sin productos no hay ficha de recetas');
    $devolverProductos();
    // Retornos: filtros, selección y IDs del mismo contexto se validan en servidor.
    $a(R::autorizado('/admin/almacenes?seleccion=1&almacen_ids%5B0%5D=1002&articulo_id='.$id,'restaurante')!==null,'Retorno físico propio autorizado');
    $a(R::autorizado('/admin/almacenes?almacen_ids%5B0%5D=1003','restaurante')===null,'Almacén ajeno rechazado en retorno');
    $a(R::autorizado('/admin/almacenes?almacen_ids%5B0%5D=1002&almacen_ids%5B1%5D=1003','restaurante')===null,'Retorno con selección mixta rechazado');
    foreach(['https://example.com','//example.com','/admin/almacenes?almacen_ids[x]=1002','/admin/almacenes?desde=2026-02-31','/admin/almacenes/catalogo?almacen_id=1002','/admin/almacenes/catalogo/editar?id=99999999'] as $bad)$a(R::autorizado($bad,'restaurante')===null,'Retorno no autorizado '.$bad);
    $r=$admin->request('GET','/admin/global/almacenes?vista=catalogo&q=Leche');$a($r['status']===302&&str_contains($r['location'],'/almacenes/catalogo?q=Leche'),'Acceso anterior al modal redirige al catálogo filtrado');
    $r=$admin->request('GET','/admin/global/catalogo');$a($r['status']===302,'Alias antiguo conserva destino compatible');

    foreach(['Leche',''] as $search){$start=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];A::catalogo('restaurante',$search);$end=(int)$db->query("SHOW SESSION STATUS LIKE 'Questions'")->fetch_row()[1];$questions[$search]=$end-$start-1;}
    $a($questions['']<=$questions['Leche']+1,'Número de consultas constante entre una fila y una página');
    // Un permiso de recetas no concede inventario; modificaciones solo del perfil sintético y restauradas.
    $account=$f['cuentas']['lectura_restaurante'];$uid=(int)$account['id'];$unit=C::unidad('restaurante');
    foreach(['inventario','productos'] as $module){$before=$db->execute_query('SELECT nivel FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,$module])->fetch_row();$restore[]=[$uid,$unit,$module,$before[0]??null];}
    $db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,'inventario']);
    $db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)',[$uid,$unit,'productos','ver']);
    $only=new ClienteInstalacion3E();$clients[]=$only;$only->login($account);
    $r=$only->request('GET','/admin/recetas/articulos/editar?id=1002');$a($valid($r)&&str_contains($r['body'],'Dónde se usa')&&!str_contains($r['body'],'<h3>Almacenes</h3>')&&!str_contains($r['body'],'/admin/almacenes?'),'Productos sin Inventario conserva ficha y oculta enlaces físicos');
    $a(in_array($only->request('GET','/admin/almacenes/catalogo')['status'],[403,303],true),'Productos no concede acceso al maestro de Inventario');
    $u=C::unidad('global');$uid=(int)$f['cuentas']['operador_panaderia']['id'];
    $db->execute_query('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES(?,?,NULL,1)',[$uid,$u]);$globalTemporal=[$uid,$u];
    $db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?)',[$uid,$u,'inventario','editar']);
    $db->execute_query('INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES(?,?,?)',[$uid,$u,'catalogo.editar']);
    $local=new ClienteInstalacion3E();$clients[]=$local;$local->login($f['cuentas']['operador_panaderia']);
    $path='/admin/panaderia/almacenes/catalogo/nuevo';$r=$local->request('GET',$path);$a($valid($r),'Alta local exige y reconoce ambas autorizaciones explícitas');
    $token=ClienteInstalacion3E::token($r['body'],'m2_csrf');$nombreLocal='Alta local UX4 '.bin2hex(random_bytes(4));$r=$local->request('POST',$path,['m2_csrf'=>$token,'nombre'=>$nombreLocal,'unidad'=>'pza','costo'=>'0.0000','tipo'=>'insumo','almacen_ids'=>[1003]]);
    $a($r['status']===303&&$r['location']==='/admin/panaderia/recetas/articulos','Alta autorizada conserva contexto Panadería');
    $r=$local->request('GET',$r['location']);$xp=ClienteInstalacion3E::dom($r['body']);$abrir=$xp->query('//div[contains(@class,"m6-creado")]//a[starts-with(@href,"/admin/panaderia/recetas/articulos/editar?id=")]')->item(0);$a($valid($r)&&$abrir!==null&&str_contains($r['body'],'asociado a 1 almacén'),'La lista confirma el alta, su almacén y enlaza la ficha');
    $a(!str_contains($local->request('GET','/admin/panaderia/recetas/articulos')['body'],'m6-creado'),'Aviso de alta de un solo uso');
    $a($db->execute_query('SELECT a.saldo FROM almacen_articulos a JOIN ingredientes i ON i.id=a.articulo_id WHERE i.nombre=? AND a.almacen_id=1003',[$nombreLocal])->fetch_row()[0]==='0.000','Alta asocia el almacén elegido sin existencias');
    $r=$local->request('GET',$abrir->getAttribute('href'));$a($valid($r)&&!str_contains($r['body'],'>Guardar datos generales<')&&str_contains($r['body'],'>Guardar uso<'),'Alta local no concede edición maestra fuera de Global');
    // Una preparación nace en un paso y aterriza en su receta.
    $nombrePrep='Preparación local UX4 '.bin2hex(random_bytes(4));$r=$local->request('POST',$path,['m2_csrf'=>$token,'nombre'=>$nombrePrep,'unidad'=>'g','costo'=>'','tipo'=>'preparacion']);
    $a($r['status']===303&&str_starts_with($r['location'],'/admin/panaderia/recetas/articulos/editar?id=')&&str_ends_with($r['location'],'#elaboracion'),'Alta de preparación abre su receta');
    $prep=(int)$db->execute_query('SELECT id FROM ingredientes WHERE nombre=?',[$nombrePrep])->fetch_row()[0];$conf=$db->execute_query('SELECT elaborable,componente,almacenable,produccion_previa FROM articulo_configuraciones WHERE articulo_id=? AND unidad_id=?',[$prep,C::unidad('panaderia')])->fetch_assoc();
    $a($conf&&(int)$conf['elaborable']===1&&(int)$conf['componente']===1&&(int)$conf['almacenable']===1&&(int)$conf['produccion_previa']===0,'La preparación queda elaborable sólo en su negocio');
    $r=$local->request('GET',$r['location']);$a($valid($r)&&str_contains($r['body'],'Falta publicar su receta')&&str_contains($r['body'],'data-m2-builder'),'La ficha de la preparación ofrece capturar su receta');
    // Sin Artículos y preparaciones el alta sigue en un paso, en el catálogo y sólo de insumos.
    $retirarProductos($uid,C::unidad('panaderia'));
    $r=$local->request('POST',$path,['m2_csrf'=>$token,'nombre'=>'Alta física UX4 '.bin2hex(random_bytes(4)),'unidad'=>'pza','costo'=>'0.0000','tipo'=>'insumo']);$a($r['status']===303&&$r['location']==='/admin/panaderia/almacenes/catalogo','Alta sólo con Almacenes vuelve a su catálogo');
    $r=$local->request('GET',$r['location']);$xp=ClienteInstalacion3E::dom($r['body']);$abrir=$xp->query('//div[contains(@class,"m6-creado")]//a[starts-with(@href,"/admin/panaderia/almacenes/catalogo/editar?id=")]')->item(0);$a($valid($r)&&$abrir!==null,'El catálogo confirma el alta y enlaza su identidad');
    $nombreNegado='Preparación negada UX4 '.bin2hex(random_bytes(4));$a($local->request('POST',$path,['m2_csrf'=>$token,'nombre'=>$nombreNegado,'unidad'=>'g','tipo'=>'preparacion'])['status']===422&&(int)$db->execute_query('SELECT COUNT(*) FROM ingredientes WHERE nombre=?',[$nombreNegado])->fetch_row()[0]===0,'Sin Artículos y preparaciones no se da de alta una preparación');
    $r=$local->request('GET',$abrir->getAttribute('href'));$a($valid($r)&&!str_contains($r['body'],'>Guardar datos generales<')&&!str_contains($r['body'],'name="accion" value="clasificar"'),'Alta local no concede edición maestra ni productos por implicación');
    $devolverProductos();
    echo json_encode(['ok'=>true,'comprobaciones'=>$n,'resultados'=>$checks,'articulo_creado'=>$id],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {foreach($clients as $client)$client->close();$devolverProductos();foreach($restore as [$uid,$unit,$module,$before]){if($before===null)$db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=?',[$uid,$unit,$module]);else $db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE nivel=VALUES(nivel)',[$uid,$unit,$module,$before]);}if(isset($globalTemporal)){[$uid,$unit]=$globalTemporal;$db->execute_query('DELETE FROM usuario_unidad_facultades WHERE usuario_id=? AND unidad_id=?',[$uid,$unit]);$db->execute_query('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=?',[$uid,$unit]);$db->execute_query('DELETE FROM usuario_unidades WHERE usuario_id=? AND unidad_id=?',[$uid,$unit]);}if(session_status()===PHP_SESSION_ACTIVE)session_destroy();$db->close();}
