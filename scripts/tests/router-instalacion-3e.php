<?php
declare(strict_types=1);

/** Front controller de pruebas 3E: solo archivos públicos y una instalación efímera marcada. */
$root=dirname(__DIR__,2);$public=realpath($root.'/public');
function router3eError(int $status,string $message): bool {http_response_code($status);header('Content-Type: text/plain; charset=utf-8');echo $message;return true;}
$path=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);$path=is_string($path)?rawurldecode($path):'/';
if(str_contains($path,"\0")||str_contains($path,'\\'))return router3eError(404,'No encontrado.');
$path=$path==='/index.php'?'/':$path;
foreach(explode('/',trim($path,'/')) as $segment) if($segment==='.'||$segment==='..'||str_starts_with($segment,'.'))return router3eError(404,'No encontrado.');
$extension=strtolower(pathinfo($path,PATHINFO_EXTENSION));
$static=['css','js','png','jpg','jpeg','gif','webp','svg','ico','woff','woff2','ttf','otf','eot','map','webmanifest','txt'];
$file=$public?realpath($public.DIRECTORY_SEPARATOR.ltrim(str_replace('/',DIRECTORY_SEPARATOR,$path),DIRECTORY_SEPARATOR)):false;
if($file&&$public&&is_file($file)&&str_starts_with(strtolower($file),strtolower(rtrim($public,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))&&in_array($extension,$static,true))return false;
if(in_array($extension,['php','phtml','phar'],true)&&$path!=='/')return router3eError(403,'Acceso denegado.');

$dbName=getenv('CP_3E_TEST_DB');$origin=getenv('CP_3E_BASE_ORIGEN');$owner=getenv('CP_3E_OWNER_TOKEN');
$host=getenv('CP_3E_DB_HOST');$user=getenv('CP_3E_DB_USER');$pass=getenv('CP_3E_DB_PASS');$port=(int)(getenv('CP_3E_DB_PORT')?:3306);
$sessionPath=getenv('CP_3E_SESSION_PATH');$sessionOwner=getenv('CP_3E_SESSION_OWNER');$tempRoot=getenv('CP_3E_TEMP_ROOT');
$external=getenv('CP_3E_EXTERNAL_SECRET');$callback=getenv('CP_3E_CALLBACK_SECRET');$nipSecret=getenv('CP_3E_NIP_SECRET');
if(!is_string($dbName)||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$dbName)||$dbName===$origin
 ||!is_string($owner)||!preg_match('/^[a-f0-9]{64}$/D',$owner)||!is_string($host)||$host===''||!is_string($user)||!is_string($pass)||$port<1||$port>65535
 ||!is_string($sessionPath)||!is_string($sessionOwner)||!preg_match('/^[a-f0-9]{48}$/D',$sessionOwner)||!is_string($tempRoot)
 ||!is_string($external)||!preg_match('/^[a-f0-9]{48}$/D',$external)||!is_string($callback)||!preg_match('/^[a-f0-9]{48}$/D',$callback)||hash_equals($external,$callback)
 ||!is_string($nipSecret)||!preg_match('/^[a-f0-9]{64}$/D',$nipSecret))return router3eError(503,'Harness temporal no configurado.');
$realSession=realpath($sessionPath);$realTemp=realpath($tempRoot);$ownerFile=$sessionPath.DIRECTORY_SEPARATOR.'owner';
if(!$realSession||!$realTemp||dirname($realSession)!==$realTemp||is_link($sessionPath)||!is_file($ownerFile)||is_link($ownerFile)
 ||!preg_match('/^cp-instalacion-3e-session-[a-f0-9]{24}$/D',basename($realSession))
 ||!hash_equals($sessionOwner,trim((string)file_get_contents($ownerFile))))return router3eError(503,'Sesión temporal no verificada.');
try {
 mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
 $check=new mysqli($host,$user,$pass,$dbName,$port);$check->set_charset('utf8mb4');
 $mark=$check->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
 $installation=$check->query('SELECT propietario_token,entorno,completada FROM instalacion_desarrollo WHERE id=1')->fetch_assoc();$check->close();
 if(count($mark)!==1||!hash_equals($owner,(string)$mark[0][0])||!$installation||!hash_equals($owner,(string)$installation['propietario_token'])||$installation['entorno']!=='test'||(int)$installation['completada']!==1)throw new RuntimeException('Propietario inválido.');
} catch(Throwable){return router3eError(503,'No se verificó la instalación temporal.');}
$values=['DB_HOST'=>$host,'DB_USER'=>$user,'DB_PASS'=>$pass,'DB_NAME'=>$dbName,'DB_PORT'=>(string)$port,'APP_ENV'=>'test',
 'NIP_LOOKUP_SECRET'=>$nipSecret,'SESSION_SAVE_PATH'=>$sessionPath,'N8N_BASE_URL'=>'http://127.0.0.1:9',
 'N8N_WEBHOOK_SUGERENCIAS_URL'=>'http://127.0.0.1:9/sugerencias','N8N_WEBHOOK_AREAS_MEJORA_URL'=>'http://127.0.0.1:9/areas',
 'IA_N8N_WEBHOOK_URL'=>'http://127.0.0.1:9/ia','N8N_SECRET'=>$external,'N8N_RESERVATIONS_WEBHOOK_SECRET'=>$external,'N8N_RESERVATIONS_CALLBACK_SECRET'=>$callback,
 'SMTP_HOST'=>'','SMTP_USER'=>'','SMTP_PASS'=>'','MAIL_HOST'=>'','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'','META_ACCESS_TOKEN'=>'','WHATSAPP_ACCESS_TOKEN'=>'','IMPRESION_ACTIVA'=>'0'];
foreach($values as $key=>$value){$_ENV[$key]=$value;$_SERVER[$key]=$value;putenv($key.'='.$value);}
$_SERVER['SCRIPT_NAME']='/index.php';$_SERVER['PHP_SELF']='/index.php';$_SERVER['PATH_INFO']=$path;$_SERVER['DOCUMENT_ROOT']=$public?:$root.'/public';
require $root.'/public/index.php';
