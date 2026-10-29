<?php
declare(strict_types=1);
/** Cliente HTTP compartido; no abre conexiones SQL. */
final class ClienteInstalacion3E
{
 private CurlHandle $curl;private string $jar;
 public function __construct(){ $this->jar=(string)tempnam(sys_get_temp_dir(),'cp3e-http-');if($this->jar==='')throw new RuntimeException('No se creó cookie jar temporal.');$this->curl=curl_init();curl_setopt_array($this->curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$this->jar,CURLOPT_COOKIEJAR=>$this->jar,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>45,CURLOPT_USERAGENT=>'Casa-Pestalozzi-3E-Isolated-HTTP/1.0']); }
 public function request(string $method,string $path,?array $data=null): array
 {
  if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Ruta fuera del harness.');
  $multipart=$data!==null&&count(array_filter($data,static fn($v)=>$v instanceof CURLFile))>0;
  curl_setopt_array($this->curl,[CURLOPT_URL=>'http://127.0.0.1:8094'.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_POSTFIELDS=>$method==='POST'?($multipart?$data:http_build_query($data??[])):null,CURLOPT_HTTPHEADER=>$multipart?['Accept: text/html,application/json,application/pdf']:['Accept: text/html,application/json,application/pdf','Content-Type: application/x-www-form-urlencoded']]);
  $raw=curl_exec($this->curl);if(!is_string($raw))throw new RuntimeException('No respondió el servidor HTTP de pruebas.');$size=(int)curl_getinfo($this->curl,CURLINFO_HEADER_SIZE);$head=substr($raw,0,$size);$body=substr($raw,$size);preg_match('/^Location:\s*([^\r\n]+)/mi',$head,$loc);
  return ['status'=>(int)curl_getinfo($this->curl,CURLINFO_RESPONSE_CODE),'body'=>$body,'location'=>trim($loc[1]??''),'json'=>json_decode($body,true)];
 }
 public function login(array $account,bool $nip=false): array {
  $page=$this->request('GET','/login');$csrf=self::token($page['body'],'login_csrf');
  $result=$this->request('POST',$nip?'/login':'/admin/login',$nip?['login_csrf'=>$csrf,'nip'=>$account['nip']]:['login_csrf'=>$csrf,'username'=>$account['username'],'password'=>$account['password']]);
  if(!$nip){
   if($result['location']!=='/login/verificacion')throw new RuntimeException('La contraseña administrativa debe abrir el reto 2FA.');
   if($this->request('GET','/admin/global/almacenes')['status']!==401)throw new RuntimeException('El reto pendiente permite entrar al panel.');
   $challenge=$this->request('GET','/login/verificacion');
   if(!preg_match('/data-otp-usar="(\d{6})"/',$challenge['body'],$code))throw new RuntimeException('Falta código del entorno test explícito.');
   $result=$this->request('POST','/login/verificacion',['codigo'=>$code[1]]);
  }
  return $result;
 }
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
