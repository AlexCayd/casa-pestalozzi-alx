<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);exit;}
require __DIR__.'/informes-aceptacion/informes-db-bootstrap.php';
$path=parse_url((string)($_SERVER['REQUEST_URI']??'/'),PHP_URL_PATH);
if($path==='/__fixture/session'){
    pdfTestLoadApp();
    $token=(string)getenv('PROVEEDORES_HTTP_TOKEN');
    if($token===''||!hash_equals($token,(string)($_SERVER['HTTP_X_PROVEEDORES_TEST_TOKEN']??''))){http_response_code(403);exit;}
    $ids=json_decode((string)file_get_contents(pdfTestTemp().'/fixture-ids.json'),true,512,JSON_THROW_ON_ERROR);
    $actor=$_GET['actor']??'';
    if(!isset($ids['users'][$actor])){http_response_code(400);exit;}
    Classes\Auth::start();
    $_SESSION=['id'=>(int)$ids['users'][$actor],'login'=>true,'auth_nivel'=>'contrasena','_auth3b'=>true];
    http_response_code(204);exit;
}
$result=require dirname(__DIR__).'/dev-router.php';
if($result===false)return false;
