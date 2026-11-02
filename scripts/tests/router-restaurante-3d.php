<?php
declare(strict_types=1);

/** Router HTTP 3D.4: valida base efímera, bloquea PHP directo y assets no autorizados. */
$root=dirname(__DIR__,2); $public=realpath($root.'/public');
$uri=(string)($_SERVER['REQUEST_URI']??'/'); $path=parse_url($uri,PHP_URL_PATH); $path=is_string($path)&&$path!==''?$path:'/';
$normalized=$path==='/index.php'?'/':$path;

function router3d4Error(int $status,string $body): bool
{
    http_response_code($status); header('Content-Type: text/plain; charset=utf-8'); echo $body; return true;
}

function router3d4Asset(string $root,string $relative): ?string
{
    if($relative===''||str_contains($relative,"\0")||str_contains($relative,'\\')||str_starts_with($relative,'/'))return null;
    $segments=explode('/',$relative); foreach($segments as $segment) if($segment===''||$segment==='.'||$segment==='..')return null;
    $base=realpath($root); if(!$base||is_link($root))return null;
    $candidate=$base.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR,$segments);
    foreach($segments as $index=>$_){$partial=$base;foreach(array_slice($segments,0,$index+1) as $piece)$partial.=DIRECTORY_SEPARATOR.$piece;if(is_link($partial))return null;}
    $real=realpath($candidate); if(!$real||!is_file($real)||dirname($real)!==$base&&!str_starts_with(strtolower($real),strtolower(rtrim($base,'\\/').DIRECTORY_SEPARATOR)))return null;
    return $real;
}

function router3d4SendImage(string $file): bool
{
    $types=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','svg'=>'image/svg+xml'];
    $ext=strtolower(pathinfo($file,PATHINFO_EXTENSION)); if(!isset($types[$ext]))return false;
    header('Content-Type: '.$types[$ext]); header('Content-Length: '.(string)filesize($file));
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=300'); readfile($file); return true;
}

$ext=strtolower(pathinfo($normalized,PATHINFO_EXTENSION));
$static=['css','js','png','jpg','jpeg','gif','webp','svg','ico','woff','woff2','ttf','otf','eot','map','webmanifest','txt'];
$segments=explode('/',trim($normalized,'/'));
foreach($segments as $segment) if($segment==='.'||$segment==='..'||str_starts_with($segment,'.')||str_contains($segment,'\\')) return router3d4Error(404,'No encontrado.');
$candidate=$public?realpath($public.DIRECTORY_SEPARATOR.ltrim(str_replace(['/','\\'],DIRECTORY_SEPARATOR,$normalized),DIRECTORY_SEPARATOR)):false;
if($public&&$candidate&&is_file($candidate)&&str_starts_with(strtolower($candidate),strtolower(rtrim($public,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))&&in_array($ext,$static,true))return false;

$assetRoot=getenv('CP_3D4_ASSET_ROOT'); $assetToken=getenv('CP_3D4_ASSET_TOKEN');
if(is_string($assetRoot)&&$assetRoot!==''&&is_string($assetToken)&&preg_match('/^[a-f0-9]{48}$/D',$assetToken)) {
    $tempRoot=getenv('CP_3D4_TEMP_ROOT');
    $assetReal=realpath($assetRoot); $assetParent=is_string($tempRoot)?realpath($tempRoot.DIRECTORY_SEPARATOR.'casa-pestalozzi-3d4-assets'):false;
    if(!$assetReal||!$assetParent||is_link($assetRoot)||dirname($assetReal)!==$assetParent
        ||!preg_match('/^[a-f0-9]{24}$/D',basename($assetReal))) return router3d4Error(503,'Assets de fixture no verificados.');
    $privatePrefix='/__fixture_assets/'.$assetToken.'/';
    if(str_starts_with($normalized,'/__fixture_assets/')) {
        if(!str_starts_with($normalized,$privatePrefix)) return router3d4Error(404,'Asset no encontrado.');
    }
    if(str_starts_with($normalized,$privatePrefix)) {
        $relative=substr($normalized,strlen($privatePrefix)); $file=router3d4Asset($assetRoot,$relative);
        if($file&&router3d4SendImage($file))return true;
        return router3d4Error(404,'Asset de fixture no encontrado.');
    }
    // El catálogo preservado conserva sus URLs originales. Solo se sirven los
    // nombres únicos que aparecen en los archivos verificados del paquete.
    if(in_array($ext,['jpg','jpeg','png','webp','gif','svg'],true)&&str_starts_with($normalized,'/build/images/')) {
        $manifest=getenv('CP_3D4_ASSET_FILES'); $files=is_string($manifest)?json_decode($manifest,true):null;
        if(is_array($files)) {
            $wanted=basename($normalized); $matches=[];
            foreach($files as $relative) if(is_string($relative)&&basename($relative)===$wanted)$matches[]=$relative;
            if(count($matches)===1) {$file=router3d4Asset($assetRoot,$matches[0]);if($file&&router3d4SendImage($file))return true;}
        }
    }
}

// No se despacha ningún PHP pedido por URL. Solo la ruta de app '/' cae al front controller.
if(in_array($ext,['php','phtml','phar'],true)&&$normalized!=='/')return router3d4Error(403,'Acceso denegado.');

$testDb=getenv('CP_3D4_TEST_DB'); $host=getenv('CP_3D4_DB_HOST'); $user=getenv('CP_3D4_DB_USER');
$pass=getenv('CP_3D4_DB_PASS'); $port=(int)(getenv('CP_3D4_DB_PORT')?:3306); $owner=getenv('CP_3D4_OWNER_TOKEN'); $origin=getenv('CP_3D4_BASE_ORIGEN');
$sessionPath=getenv('CP_3D4_SESSION_PATH'); $sessionOwner=getenv('CP_3D4_SESSION_OWNER');
$externalSecret=getenv('CP_3D4_EXTERNAL_SECRET'); $callbackSecret=getenv('CP_3D4_CALLBACK_SECRET');
if(!is_string($testDb)||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$testDb)||$testDb===$origin
    ||!is_string($host)||$host===''||!is_string($user)||!is_string($pass)||$port<1||$port>65535
    ||!is_string($owner)||!preg_match('/^[a-f0-9]{64}$/D',$owner)
    ||!is_string($externalSecret)||!preg_match('/^[a-f0-9]{48}$/D',$externalSecret)
    ||!is_string($callbackSecret)||!preg_match('/^[a-f0-9]{48}$/D',$callbackSecret)
    ||hash_equals($externalSecret,$callbackSecret)
    ||!is_string($sessionPath)||!is_string($sessionOwner)||!preg_match('/^[a-f0-9]{48}$/D',$sessionOwner))return router3d4Error(503,'Harness temporal no configurado.');
$tempRoot=getenv('CP_3D4_TEMP_ROOT');
$sessionReal=realpath($sessionPath); $tempReal=is_string($tempRoot)?realpath($tempRoot):false; $ownerFile=$sessionPath.DIRECTORY_SEPARATOR.'owner';
if(!$sessionReal||!$tempReal||dirname($sessionReal)!==$tempReal||is_link($sessionPath)||!is_file($ownerFile)||is_link($ownerFile)
    ||!hash_equals($sessionOwner,trim((string)file_get_contents($ownerFile))))return router3d4Error(503,'Sesión temporal no verificada.');

try {
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    $check=new mysqli($host,(string)$user,$pass,$testDb,$port); $check->set_charset('utf8mb4');
    $rows=$check->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM); $check->close();
    if(count($rows)!==1||!hash_equals($owner,(string)$rows[0][0]))throw new RuntimeException('Owner mismatch');
} catch(Throwable) {return router3d4Error(503,'No se verificó la base temporal.');}

$envValues=['DB_HOST'=>$host,'DB_USER'=>$user,'DB_PASS'=>$pass,'DB_NAME'=>$testDb,
    'APP_ENV'=>(string)(getenv('CP_3D4_APP_ENV')?:'test'),'NIP_LOOKUP_SECRET'=>(string)(getenv('CP_3D4_NIP_SECRET')?:''),
    'SESSION_SAVE_PATH'=>$sessionPath,'N8N_BASE_URL'=>'http://127.0.0.1:9',
    'N8N_WEBHOOK_SUGERENCIAS_URL'=>'http://127.0.0.1:9/sugerencias','N8N_WEBHOOK_AREAS_MEJORA_URL'=>'http://127.0.0.1:9/areas',
    'IA_N8N_WEBHOOK_URL'=>'http://127.0.0.1:9/ia','N8N_SECRET'=>$externalSecret,
    'N8N_RESERVATIONS_WEBHOOK_SECRET'=>$externalSecret,
    'N8N_RESERVATIONS_CALLBACK_SECRET'=>$callbackSecret,
    'SMTP_HOST'=>'','SMTP_USER'=>'','SMTP_PASS'=>'','MAIL_HOST'=>'','MAIL_USERNAME'=>'','MAIL_PASSWORD'=>'',
    'META_ACCESS_TOKEN'=>'','WHATSAPP_ACCESS_TOKEN'=>'','IMPRESION_ACTIVA'=>'0'];
foreach($envValues as $key=>$value){$_ENV[$key]=$value;$_SERVER[$key]=$value;putenv($key.'='.$value);}
if($port!==3306&&!preg_match('/:\d+$/',$host)){$_ENV['DB_HOST']=$host.':'.$port;$_SERVER['DB_HOST']=$host.':'.$port;putenv('DB_HOST='.$host.':'.$port);}
$_SERVER['SCRIPT_NAME']='/index.php'; $_SERVER['PHP_SELF']='/index.php'; $_SERVER['PATH_INFO']=$normalized;
$_SERVER['DOCUMENT_ROOT']=$public?:$root.'/public';
require $root.'/public/index.php';
