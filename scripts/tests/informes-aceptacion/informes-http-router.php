<?php
if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') { http_response_code(403); exit; }
require __DIR__.'/informes-db-bootstrap.php';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/__fixture/session'){
    pdfTestLoadApp();
    if (!hash_equals((string)getenv('PDF_TEST_SESSION_TOKEN'), (string)($_SERVER['HTTP_X_PDF_TEST_TOKEN'] ?? '')) || !getenv('PDF_TEST_SESSION_TOKEN')) { http_response_code(403); exit; }
    $ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$actor=$_GET['actor']??'';
    if(!in_array($actor,['reader','wrong','bakery','global','nip'],true)){http_response_code(400);exit;}
    Classes\Auth::start();
    $_SESSION=['id'=>$ids['users'][$actor==='nip'?'reader':$actor],'login'=>true,'auth_nivel'=>$actor==='nip'?'nip':'contrasena','_auth3b'=>true];
    http_response_code(204);exit;
}
$resultado=require PDF_TEST_ROOT.'/scripts/dev-router.php';
if($resultado===false)return false;
