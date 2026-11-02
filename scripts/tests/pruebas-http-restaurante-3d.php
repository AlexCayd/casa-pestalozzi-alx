<?php
declare(strict_types=1);

/** Smoke HTTP del servidor 3D.4 ya iniciado; no crea ni modifica bases de datos. */
if(PHP_SAPI!=='cli'){exit(1);}

$url='http://127.0.0.1:8093';
for($i=1;$i<count($argv);$i++) {
    if($argv[$i]!=='--url'||!isset($argv[$i+1])||isset($seen)) {fwrite(STDERR,"Uso: php scripts/tests/pruebas-http-restaurante-3d.php [--url http://127.0.0.1:8093]\n");exit(2);}
    $seen=true;$url=$argv[++$i];
}
$parts=parse_url($url);
if(!is_array($parts)||($parts['scheme']??'')!=='http'||($parts['host']??'')!=='127.0.0.1'||(int)($parts['port']??0)!==8093||isset($parts['user'])||isset($parts['pass'])) {
    fwrite(STDERR,"El smoke test solo acepta http://127.0.0.1:8093.\n");exit(2);
}

function smoke3d4(string $url,string $path): array
{
    $context=stream_context_create(['http'=>['method'=>'GET','timeout'=>5,'ignore_errors'=>true,'follow_location'=>0,'header'=>"Accept: text/html,application/json\r\n"]]);
    $body=@file_get_contents(rtrim($url,'/').$path,false,$context);
    $headers=$http_response_header??[]; $status=0; $location='';
    if(isset($headers[0])&&preg_match('/\s([0-9]{3})\s/',$headers[0],$m))$status=(int)$m[1];
    foreach($headers as $header)if(str_starts_with(strtolower($header),'location:'))$location=trim(substr($header,9));
    return ['status'=>$status,'location'=>$location,'body'=>$body===false?'':$body];
}

$checks=[];$fail=[];
$root=smoke3d4($url,'/');$checks['home']=$root['status']===200;
$login=smoke3d4($url,'/login');$checks['login']=$login['status']===200;
$private=smoke3d4($url,'/scripts/tests/router-restaurante-3d.php');$checks['php_no_publico']=$private['status']===403;
$dot=smoke3d4($url,'/.env');$checks['dotfile_no_publico']=$dot['status']!==200&&!str_contains($dot['body'],'DB_PASS=');
$assets=smoke3d4($url,'/__fixture_assets/invalid-token/private.svg');$checks['asset_token_requerido']=$assets['status']===404;
$admin=smoke3d4($url,'/admin/inventario');$checks['admin_requiere_sesion']=in_array($admin['status'],[302,303,401,403],true)
    && ($admin['status']!==302&&$admin['status']!==303 || str_contains($admin['location'],'/login'));
foreach($checks as $name=>$ok)if(!$ok)$fail[]=$name;
$result=['ok'=>$fail===[],'checks'=>$checks,'error_count'=>count($fail),'fallos'=>$fail];
fwrite(($fail===[]?STDOUT:STDERR),json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
exit($fail===[]?0:1);
