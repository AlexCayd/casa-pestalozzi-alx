<?php
declare(strict_types=1);
/** Pruebas HTTP 3E contra servir-instalacion-3e.php; nunca conecta directamente a SQL. */
if(PHP_SAPI!=='cli')exit(1);

final class ClienteInstalacion3E
{
 private CurlHandle $curl;private string $jar;
 public function __construct(){ $this->jar=(string)tempnam(sys_get_temp_dir(),'cp3e-http-');if($this->jar==='')throw new RuntimeException('No se creó cookie jar temporal.');$this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>45,CURLOPT_USERAGENT=>'Casa-Pestalozzi-3E-Isolated-HTTP/1.0']); }
 public function request(string $method,string $path,?array $data=null): array
 {
  if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Ruta fuera del harness.');
  curl_setopt_array($this->curl,[CURLOPT_URL=>'http://127.0.0.1:8094'.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>$method==='POST'?http_build_query($data??[]):null,CURLOPT_HTTPHEADER=>['Accept: text/html,application/json,application/pdf','Content-Type: application/x-www-form-urlencoded']]);
  $raw=curl_exec($this->curl);if(!is_string($raw))throw new RuntimeException('No respondió el servidor HTTP de pruebas.');$size=(int)curl_getinfo($this->curl,CURLINFO_HEADER_SIZE);$head=substr($raw,0,$size);$body=substr($raw,$size);preg_match('/^Location:\s*([^\r\n]+)/mi',$head,$loc);
  return ['status'=>(int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'location'=>trim($loc[1]??''),'json'=>json_decode($body,true)];
 }
 public function login(array $account,bool $nip=false): array { $page=$this->request('GET','/login');$csrf=self::token($page['body'],'login_csrf');return $this->request('POST',$nip?'/login':'/admin/login',$nip?['login_csrf'=>$csrf,'nip'=>$account['nip']]:['login_csrf'=>$csrf,'username'=>$account['username'],'password'=>$account['password']]); }
 public static function token(string $body,string $name='admin_csrf'): string {if(!preg_match('/name="'.preg_quote($name,'/').'"\s+value="([^"]+)"/',$body,$m))throw new RuntimeException('No se encontró el token del formulario.');return html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8');}
 public static function dom(string $body): DOMXPath { $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$body);libxml_clear_errors();libxml_use_internal_errors($previous);return new DOMXPath($doc); }
 public static function form(string $body,string $action,int $warehouse=0,int $article=0): array {
  $xpath=self::dom($body);$query='//form[.//input[@name="accion" and @value="'.$action.'"]]';if($warehouse)$query='//form[.//input[@name="accion" and @value="'.$action.'"] and .//input[@name="almacen_id" and @value="'.$warehouse.'"]'.($article?' and .//input[@name="articulo_id" and @value="'.$article.'"]':'').']';
  $node=$xpath->query($query)->item(0);if(!$node)throw new RuntimeException('Falta el formulario de '.$action.'.');$data=[];foreach($xpath->query('.//input[@name]',$node) as $input){if($input->getAttribute('type')==='checkbox'&&!$input->hasAttribute('checked'))continue;$data[$input->getAttribute('name')]=$input->getAttribute('value');}return $data;
 }
 public function operation(string $base,string $action,array $values,string $return='existencias'): array { $page=$this->request('GET',$base.'?vista='.$return);$data=['admin_csrf'=>self::token($page['body']),'accion'=>$action,'volver_vista'=>$return]+$values;$post=$this->request('POST',$base.'/operacion',$data);if($post['status']!==303)return $post;return $this->request('GET',$post['location']); }
 public static function warehouse(string $body,string $name): int { $xpath=self::dom($body);foreach($xpath->query('//tr') as $row){$strong=$xpath->query('.//strong',$row)->item(0);if($strong&&trim($strong->textContent)===$name){foreach($xpath->query('.//a[@href]',$row) as $link)if(preg_match('/[?&]almacen_id=(\d+)/',html_entity_decode($link->getAttribute('href')),$m))return (int)$m[1];}}return 0; }
 public static function article(string $body,string $name): int { $xpath=self::dom($body);foreach($xpath->query('//tr') as $row){$strong=$xpath->query('.//strong',$row)->item(0);if($strong&&trim($strong->textContent)===$name){$id=$xpath->query('.//input[@name="articulo_id"]',$row)->item(0);if($id)return(int)$id->getAttribute('value');}}return 0; }
 public function close(): void {curl_close($this->curl);unset($this->curl);if(is_file($this->jar))unlink($this->jar);}
}

$root=dirname(__DIR__,2);$clients=[];$checks=[];$phase='argumentos';$error=null;$createdUser=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks[]=$message;};
$valid=static fn(array $r):bool=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught \w+Exception/',$r['body']);
$confirmed=static fn(array $r):bool=>$valid($r)&&str_contains($r['body'],'confirmada correctamente');
try {
 if(count($argv)!==3||$argv[1]!=='--fixture'||!is_file($argv[2]))throw new RuntimeException('Uso: --fixture ABS_JSON_PRIVADO.');
 $fixture=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
 if(($fixture['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',(string)($fixture['base_temporal']??'')))throw new RuntimeException('El archivo no identifica el harness 3E.');
 require $root.'/vendor/autoload.php';Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();$password=(string)($_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD'));if(strlen($password)<12)throw new RuntimeException('Configura localmente la contraseña de bootstrap.');
 $phase='instalación y autenticación';$admin=new ClienteInstalacion3E();$clients[]=$admin;$anon=new ClienteInstalacion3E();$clients[]=$anon;
 $login=$admin->login(['username'=>'admin_demo','password'=>$password]);unset($password);$assert(in_array($login['status'],[302,303],true),'admin_demo inicia sesión con la contraseña local segura');
 foreach(['/','/menu/pdf','/maridaje/pdf'] as $path){$r=$anon->request('GET',$path);$assert($valid($r),'Público funcional '.$path);if(str_ends_with($path,'/pdf'))$assert(str_starts_with($r['body'],'%PDF-'),'PDF válido '.$path);}
 $assert($anon->request('GET','/admin/global/almacenes')['status']===401,'Almacenes rechaza una sesión ausente');
 $assert($anon->request('GET','/scripts/tests/servir-instalacion-3e.php')['status']===403,'No se ejecutan scripts PHP por URL');
 $assert($anon->request('GET','/.env')['status']===404,'No se sirve configuración privada');
 $bases=['global'=>'/admin/global/almacenes','restaurante'=>'/admin/almacenes','panaderia'=>'/admin/panaderia/almacenes'];$readers=[];$operators=[];
 foreach($bases as $ctx=>$base){
  $reader=new ClienteInstalacion3E();$clients[]=$reader;$readers[$ctx]=$reader;$login=$reader->login($fixture['cuentas']['lectura_'.$ctx]);$assert(in_array($login['status'],[302,303],true),'Lectura '.$ctx.' inicia sesión');
  foreach(['existencias','almacenes','movimientos'] as $view){$r=$reader->request('GET',$base.'?vista='.$view);$assert($valid($r),'Lectura '.$ctx.' '.$view);}
  $assert($reader->request('POST',$base.'/operacion',['accion'=>'entrada','admin_csrf'=>'invalido'])['status']===403,'Solo lectura '.$ctx.' no puede escribir');
  $operator=new ClienteInstalacion3E();$clients[]=$operator;$operators[$ctx]=$operator;$login=$operator->login($fixture['cuentas']['operador_'.$ctx]);$assert(in_array($login['status'],[302,303],true),'Operador '.$ctx.' inicia sesión');
  $users=$ctx==='restaurante'?'/admin/usuarios':'/admin/'.$ctx.'/usuarios';$assert($valid($reader->request('GET',$users)),'Usuarios compartido '.$ctx.' permite consulta autorizada');
 }
 $floor=new ClienteInstalacion3E();$clients[]=$floor;$assert(in_array($floor->login($fixture['cuentas']['piso_nip'],true)['status'],[302,303],true),'NIP abre acceso operativo');$assert($valid($floor->request('GET','/punto-de-venta')),'NIP abre POS Restaurante');$assert($floor->request('GET','/admin/almacenes')['status']===403,'NIP no abre Almacenes administrativos');
 $assert($readers['restaurante']->request('GET',$bases['panaderia'])['status']===403,'La membresía Restaurante no abre Panadería');
 $assert($admin->request('GET','/admin/panaderia/menu')['status']===503,'Menú Panadería continúa pendiente sin escrituras parciales');
 $assert($valid($admin->request('GET','/admin/menu'))&&$valid($admin->request('GET','/admin/recetas')),'Restaurante conserva menú y recetas');

 $phase='catálogo y almacenes físicos';$suffix=bin2hex(random_bytes(4));$articleName='HTTP3E-articulo-'.$suffix;
 $r=$admin->operation($bases['global'],'crear_articulo',['nombre'=>$articleName,'unidad'=>'g','costo'=>'0.1250','activo'=>'1'],'catalogo');$assert($confirmed($r),'Global crea identidad maestra compartida');
 $catalog=$admin->request('GET',$bases['global'].'?vista=catalogo&q='.$articleName);$article=ClienteInstalacion3E::article($catalog['body'],$articleName);$assert($article>0,'El artículo creado tiene identidad visible');
 $warehouses=[];
 foreach($bases as $ctx=>$base){$name='HTTP3E-'.$ctx.'-'.$suffix;$r=$admin->operation($bases['global'],'crear_almacen',['propietario'=>$ctx,'nombre'=>$name],'almacenes');$assert($confirmed($r),'Global crea almacén físico para '.$ctx);$list=$admin->request('GET',$bases['global'].'?vista=almacenes&q='.$name);$wh=ClienteInstalacion3E::warehouse($list['body'],$name);$assert($wh>0,'Almacén '.$ctx.' tiene identidad visible');$warehouses[$ctx]=$wh;
  $r=$operators[$ctx]->operation($base,'asociar',['almacen_id'=>(string)$wh,'articulo_id'=>(string)$article,'minimo'=>'1.000']);$assert($confirmed($r),'Asociación local '.$ctx.' confirmada');
  $page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$entry=ClienteInstalacion3E::form($page['body'],'entrada',$wh,$article);$entry['cantidad']='5.125';$entry['costo_unitario']='0.1250';$entry['nota']='Prueba HTTP independiente.';$entry['volver_vista']='existencias';$entry['clave']='http3e-entrada-'.$ctx.'-'.$suffix;
  $post=$operators[$ctx]->request('POST',$base.'/operacion',$entry);$assert($post['status']===303,'Entrada '.$ctx.' devuelve PRG');$page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$adjust=ClienteInstalacion3E::form($page['body'],'ajuste',$wh,$article);$assert($adjust['cantidad']==='5.125','Entrada exacta de tres decimales '.$ctx);
  $operators[$ctx]->request('POST',$base.'/operacion',$entry);$page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$adjust=ClienteInstalacion3E::form($page['body'],'ajuste',$wh,$article);$assert($adjust['cantidad']==='5.125','Replay de entrada '.$ctx.' no duplica saldo');
  $merma=ClienteInstalacion3E::form($page['body'],'merma',$wh,$article);$merma['cantidad']='3.000';$merma['motivo']='caducidad';$merma['nota']='Merma sintética.';$merma['clave']='http3e-merma-'.$ctx.'-'.$suffix;$operators[$ctx]->request('POST',$base.'/operacion',$merma);
  $page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$current=ClienteInstalacion3E::form($page['body'],'ajuste',$wh,$article);$assert($current['cantidad']==='2.125','Merma '.$ctx.' descuenta físicamente');
  $adjust['cantidad']='9.000';$adjust['nota']='Versión antigua.';$adjust['clave']='http3e-obsoleto-'.$ctx.'-'.$suffix;$operators[$ctx]->request('POST',$base.'/operacion',$adjust);$page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$current=ClienteInstalacion3E::form($page['body'],'ajuste',$wh,$article);$assert($current['cantidad']==='2.125','Conteo obsoleto '.$ctx.' no pisa operaciones concurrentes');
  $current['cantidad']='1.001';$current['nota']='Conteo sintético.';$current['clave']='http3e-ajuste-'.$ctx.'-'.$suffix;$operators[$ctx]->request('POST',$base.'/operacion',$current);$page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$current=ClienteInstalacion3E::form($page['body'],'ajuste',$wh,$article);$assert($current['cantidad']==='1.001','Ajuste '.$ctx.' conserva cantidad exacta');
  $configured=$operators[$ctx]->operation($base,'configurar_almacen',['almacen_id'=>(string)$wh,'nombre'=>$name,'activo'=>'1','permitir_negativos'=>'1'],'almacenes');$assert($confirmed($configured),'Configurar negativos en almacén propio '.$ctx);
  $page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$negative=ClienteInstalacion3E::form($page['body'],'ajuste',$wh,$article);$negative['cantidad']='-0.001';$negative['nota']='Conteo negativo autorizado.';$negative['clave']='http3e-negativo-'.$ctx.'-'.$suffix;$operators[$ctx]->request('POST',$base.'/operacion',$negative);
  $page=$operators[$ctx]->request('GET',$base.'?q='.$articleName.'&almacen_id='.$wh);$restore=ClienteInstalacion3E::form($page['body'],'ajuste',$wh,$article);$assert($restore['cantidad']==='-0.001','Política negativa es física y efectiva '.$ctx);
  $blocked=$operators[$ctx]->operation($base,'configurar_almacen',['almacen_id'=>(string)$wh,'nombre'=>$name,'activo'=>'1'],'almacenes');$assert(!$confirmed($blocked),'No se desactiva política con saldo negativo '.$ctx);
  $restore['cantidad']='1.001';$restore['nota']='Reposición del conteo sintético.';$restore['clave']='http3e-restaurar-'.$ctx.'-'.$suffix;$operators[$ctx]->request('POST',$base.'/operacion',$restore);$configured=$operators[$ctx]->operation($base,'configurar_almacen',['almacen_id'=>(string)$wh,'nombre'=>$name,'activo'=>'1'],'almacenes');$assert($confirmed($configured),'Se retira política negativa tras recuperar saldo '.$ctx);
  $emptyName='HTTP3E-vacio-'.$ctx.'-'.$suffix;$admin->operation($bases['global'],'crear_almacen',['propietario'=>$ctx,'nombre'=>$emptyName],'almacenes');$emptyList=$admin->request('GET',$bases['global'].'?vista=almacenes&q='.$emptyName);$emptyId=ClienteInstalacion3E::warehouse($emptyList['body'],$emptyName);$assert($emptyId>0,'Almacén vacío creado para baja '.$ctx);$deleted=$operators[$ctx]->operation($base,'eliminar_almacen',['almacen_id'=>(string)$emptyId],'almacenes');$assert($confirmed($deleted),'Baja de almacén vacío propio '.$ctx);$emptyList=$admin->request('GET',$bases['global'].'?vista=almacenes&q='.$emptyName);$assert(ClienteInstalacion3E::warehouse($emptyList['body'],$emptyName)===0,'Almacén eliminado no reaparece '.$ctx);
  $history=$operators[$ctx]->request('GET',$base.'?vista=movimientos&q='.$articleName.'&almacen_id='.$wh);$assert($valid($history)&&str_contains($history['body'],'http3e-entrada-'.$ctx.'-'.$suffix)&&str_contains($history['body'],'http3e-merma-'.$ctx.'-'.$suffix),'Historial '.$ctx.' conserva referencia y movimientos');
 }
 $all=$admin->request('GET',$bases['global'].'?q='.$articleName);$assert($valid($all)&&str_contains($all['body'],'3.003 g'),'Consolidado suma el mismo artículo sin almacén virtual');
 $foreign=$operators['restaurante']->request('GET',$bases['restaurante'].'?almacen_id='.$warehouses['panaderia']);$assert($foreign['status']===403&&!str_contains($foreign['body'],'HTTP3E-panaderia-'.$suffix),'ID ajeno no revela existencias Panadería');
 $r=$operators['restaurante']->operation($bases['restaurante'],'entrada',['almacen_id'=>(string)$warehouses['panaderia'],'articulo_id'=>(string)$article,'cantidad'=>'99.000','clave'=>'http3e-cruzado-'.$suffix,'nota'=>'Intento cruzado.']);$assert(!$confirmed($r),'Entrada rechaza almacén de otro ámbito');
 $page=$operators['panaderia']->request('GET',$bases['panaderia'].'?q='.$articleName.'&almacen_id='.$warehouses['panaderia']);$assert(ClienteInstalacion3E::form($page['body'],'ajuste',$warehouses['panaderia'],$article)['cantidad']==='1.001','Intento cruzado conserva saldo Panadería');
 $r=$operators['global']->operation($bases['global'],'crear_almacen',['propietario'=>'global','nombre'=>'HTTP3E-denegado-'.$suffix],'almacenes');$assert(!$confirmed($r),'Inventario editar sin facultad Global no crea almacenes');
 $r=$operators['restaurante']->operation($bases['restaurante'],'crear_articulo',['nombre'=>'HTTP3E-denegado-'.$suffix,'unidad'=>'g','costo'=>'1.0000','activo'=>'1']);$assert(!$confirmed($r),'Restaurante no crea identidades maestras');
 $csrfRest=ClienteInstalacion3E::token($admin->request('GET',$bases['restaurante'])['body']);$crossCsrf=$admin->request('POST',$bases['panaderia'].'/operacion',['admin_csrf'=>$csrfRest,'accion'=>'asociar','almacen_id'=>(string)$warehouses['panaderia'],'articulo_id'=>(string)$article,'minimo'=>'0.000']);$assert($crossCsrf['status']===403,'CSRF de un ámbito no autoriza otro');

 $phase='CRUD de usuarios y revocación';$userBase='/admin/global/usuarios';$form=$admin->request('GET',$userBase.'/create');$csrf=ClienteInstalacion3E::token($form['body']);$userPassword='Http3E_'.bin2hex(random_bytes(10));$username='http3e_'.bin2hex(random_bytes(4));$scope=static fn(bool $active):array=>['activo'=>$active?'1':'0','permisos'=>$active?['users'=>'ver','inventario'=>'ver']:[],'facultades'=>$active?['usuarios.gestionar']:[]];
 $payload=['admin_csrf'=>$csrf,'username'=>$username,'nombre'=>'Usuario HTTP 3E','activo'=>'1','password'=>$userPassword,'password_confirm'=>$userPassword,'ambitos'=>['global'=>$scope(true),'restaurante'=>$scope(false),'panaderia'=>$scope(false)]];
 $created=$admin->request('POST',$userBase.'/create',$payload);$assert($created['status']===303&&preg_match('/\/edit\?id=(\d+)/',$created['location'],$m)===1,'CRUD Usuarios crea una identidad autorizada');$createdUser=(int)$m[1];$newUser=new ClienteInstalacion3E();$clients[]=$newUser;$assert(in_array($newUser->login(['username'=>$username,'password'=>$userPassword])['status'],[302,303],true),'La cuenta creada inicia sesión con contraseña');
 $assert($newUser->request('GET',$bases['panaderia'])['status']===403,'La cuenta creada no hereda ámbitos ausentes');
 $payload['id']=(string)$createdUser;$payload['nombre']='Usuario HTTP 3E editado';$payload['password']='';$payload['password_confirm']='';$payload['ambitos']=['global'=>$scope(true),'restaurante'=>$scope(true),'panaderia'=>$scope(true)];$edited=$admin->request('POST',$userBase.'/edit',$payload);$assert($edited['status']===303,'CRUD Usuarios edita y asigna múltiples ámbitos');
 foreach($bases as $ctx=>$base)$assert($valid($newUser->request('GET',$base)),'Permisos actualizados habilitan '.$ctx.' sin conceder edición');
 $payload['ambitos']['panaderia']=$scope(false);$admin->request('POST',$userBase.'/edit',$payload);$assert($newUser->request('GET',$bases['panaderia'])['status']===403,'La revocación de membresía afecta la sesión existente');
 $admin->request('POST',$userBase.'/deactivate',['admin_csrf'=>$csrf,'id'=>(string)$createdUser]);$assert(in_array($newUser->request('GET',$bases['global'])['status'],[401,403],true),'La cuenta desactivada pierde su sesión');
 $admin->request('POST',$userBase.'/delete',['admin_csrf'=>$csrf,'id'=>(string)$createdUser]);$list=$admin->request('GET',$userBase.'?q='.$username);$assert($valid($list)&&!str_contains($list['body'],'Usuario HTTP 3E editado'),'CRUD Usuarios elimina una identidad sin historial');
} catch(Throwable $e){$error=$e->getMessage();}
finally{foreach($clients as $client)$client->close();}
$out=['ok'=>$error===null,'fase'=>$phase,'comprobaciones'=>count($checks),'comprobaciones_aprobadas'=>$checks];if($error!==null)$out['error']=$error;fwrite($error===null?STDOUT:STDERR,json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL);exit($error===null?0:1);
