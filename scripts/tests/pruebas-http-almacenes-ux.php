<?php
declare(strict_types=1);
/** Solo consultas HTTP contra el harness 3E identificado; no conecta a la DB. */
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--fixture'||empty($argv[2]))exit(1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
$fixture=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if(($fixture['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$fixture['base_temporal']??''))throw new RuntimeException('Harness temporal requerido.');
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
$count=0;$assert=static function(bool $ok,string $msg)use(&$count):void{if(!$ok)throw new RuntimeException($msg);$count++;};
$jar=tempnam(sys_get_temp_dir(),'cp-ux-http-');$curl=curl_init();
curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>30]);
$request=static function(string $path,?array $post=null)use($curl):array{
 curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:8094'.$path,CURLOPT_POSTFIELDS=>$post===null?null:http_build_query($post),CURLOPT_CUSTOMREQUEST=>$post===null?'GET':'POST']);
 $raw=curl_exec($curl);if(!is_string($raw))throw new RuntimeException('Sin respuesta del harness.');$size=(int)curl_getinfo($curl,CURLINFO_HEADER_SIZE);return ['status'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'body'=>substr($raw,$size),'head'=>substr($raw,0,$size)];
};
$login=static function(string $user,string $password)use($request,$assert):void{
 $page=$request('/login?tab=admin');preg_match('/name="login_csrf"\s+value="([^"]+)"/',$page['body'],$m);$assert(!empty($m[1]),'CSRF real del login');
 $r=$request('/admin/login',['login_csrf'=>html_entity_decode($m[1],ENT_QUOTES),'username'=>$user,'password'=>$password]);$assert($r['status']===302&&str_contains($r['head'],'/login/verificacion'),'La contraseña abre 2FA');
 $challenge=$request('/login/verificacion');preg_match('/data-otp-usar="(\d{6})"/',$challenge['body'],$m);$assert(!empty($m[1]),'Código solo del entorno test');$r=$request('/login/verificacion',['codigo'=>$m[1]]);$assert($r['status']===302,'Completar 2FA');
};
try{
 $login('admin_demo',(string)($_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD')));
 $g=(int)$fixture['fixture']['almacen_global'];$r=(int)$fixture['fixture']['almacen_restaurante'];$p=(int)$fixture['fixture']['almacen_panaderia'];
 $pair=$request('/admin/global/almacenes?'.http_build_query(['almacen_ids'=>[$g,$r],'q'=>'Harina']));
 $assert($pair['status']===200&&str_contains($pair['body'],'14920.000 g'),'Selección múltiple Global suma solo centrales y Restaurante.');
 $assert(!str_contains($pair['body'],'8000.000 g'),'La distribución no incluye el saldo de Panadería excluido.');
 $zero=$request('/admin/global/almacenes?'.http_build_query(['almacen_ids'=>[$g],'q'=>'Aguacate']));$assert($zero['status']===200&&str_contains($zero['body'],'Sin asociación en la selección'),'Global muestra artículo no asociado a la selección.');
 $assert($request('/admin/almacenes?'.http_build_query(['almacen_ids'=>[$r,$p]]))['status']===403,'Restaurante deniega selección mixta ajena.');
 $assert($request('/admin/panaderia/almacenes?'.http_build_query(['almacen_ids'=>[$p,$g]]))['status']===403,'Panadería deniega selección mixta ajena.');
 $assert($request('/admin/global/almacenes?'.http_build_query(['almacen_ids'=>[$g,999999]]))['status']===403,'Se valida cada ID incluso con otro correcto.');
 $assert($request('/admin/global/almacenes?almacen_ids=1')['status']===422,'Forma de selección inválida denegada.');
 $assert($request('/admin/global/almacenes?seleccion=1')['status']===422,'Servidor rechaza quitar todos los almacenes.');
 $history=$request('/admin/global/almacenes?'.http_build_query(['vista'=>'movimientos','almacen_ids'=>[$r]]));
 $doc=new DOMDocument();libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$history['body']);libxml_clear_errors();$x=new DOMXPath($doc);
 $rows=$x->query('//*[@id="almacen-movimientos"]//tbody/tr');$assert($history['status']===200&&$rows->length>0,'Historial contextual tiene resultados reales.');
 foreach($rows as $row)$assert(str_contains($row->textContent,'Almacén Restaurante')&&!str_contains($row->textContent,'Almacén Panadería'),'Cada movimiento pertenece a la selección.');
 $request('/logout',[]);
 $account=$fixture['cuentas']['lectura_global'];$login($account['username'],$account['password']);$read=$request('/admin/global/almacenes');
 $doc=new DOMDocument();$doc->loadHTML('<?xml encoding="utf-8" ?>'.$read['body']);$x=new DOMXPath($doc);
 $assert($read['status']===200&&$x->query('//form[@action="/admin/global/almacenes/operacion"]')->length===0,'Solo lectura conserva consulta sin formularios ejecutables.');
 echo "Almacenes HTTP UX: {$count} comprobaciones OK.\n";
}finally{curl_close($curl);if(is_file($jar))unlink($jar);}
