<?php
declare(strict_types=1);
const PDF_TEST_ROOT = __DIR__ . '/../../..';
require_once PDF_TEST_ROOT . '/vendor/autoload.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
function pdfTestConfig(): array {
    $v=[];
    foreach (['TEST_DB_HOST','TEST_DB_PORT','TEST_DB_USER','TEST_DB_PASS'] as $key) {
        $value=getenv($key);
        if ($value === false || ($key !== 'TEST_DB_PASS' && $value === '')) throw new RuntimeException('Falta configuración explícita de prueba: '.$key);
        $v[$key]=$value;
    }
    if (!filter_var($v['TEST_DB_PORT'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]])) throw new RuntimeException('Puerto de prueba inválido');
    return $v;
}
function pdfTestTemp(): string {
    $dir=getenv('PDF_TEST_TEMP');
    if (!$dir || !is_dir($dir) || !is_file($dir.'/.pdf-test-owner')) throw new RuntimeException('Directorio temporal de prueba no autorizado');
    return $dir;
}
function pdfTestServer(): mysqli {
    $c=pdfTestConfig();
    $db=new mysqli($c['TEST_DB_HOST'],$c['TEST_DB_USER'],$c['TEST_DB_PASS'],'',(int)$c['TEST_DB_PORT']);
    $db->set_charset('utf8mb4'); $db->query("SET time_zone='-06:00'");
    return $db;
}
function pdfTestMeta(): array {
    $meta=json_decode((string)file_get_contents(pdfTestTemp().'/fixture.json'),true,512,JSON_THROW_ON_ERROR);
    if (!preg_match('/\Acp_pdf_test_[a-f0-9]{20}\z/', $meta['base'] ?? '') || !preg_match('/\A[a-f0-9]{64}\z/', $meta['owner'] ?? '')) throw new RuntimeException('Identidad de fixture inválida');
    return $meta;
}
function pdfTestSetup(): array {
    $db=pdfTestServer(); $base='cp_pdf_test_'.bin2hex(random_bytes(10)); $password=bin2hex(random_bytes(24));
    $_ENV['APP_ENV']='test'; $_ENV['DB_NAME']=$base;
    try {
        Services\Development\Instalacion::ejecutar($db,$base,'test',$password,false,static function(string $name,string $token)use($password):void {
            if (file_put_contents(pdfTestTemp().'/fixture.json',json_encode(['base'=>$name,'owner'=>$token,'password'=>$password],JSON_THROW_ON_ERROR),LOCK_EX) === false) throw new RuntimeException('No se pudo guardar propiedad de fixture');
        });
        return pdfTestMeta();
    } finally { $db->close(); }
}
function pdfTestDb(): mysqli {
    $m=pdfTestMeta(); $db=pdfTestServer(); $db->select_db($m['base']);
    $owner=$db->query('SELECT propietario_token FROM instalacion_desarrollo WHERE id=1')->fetch_row()[0]??null;
    if (!is_string($owner) || !hash_equals($m['owner'],$owner)) throw new RuntimeException('Propiedad de base no verificada');
    return $db;
}
function pdfTestCleanup(): void {
    if (!is_file(pdfTestTemp().'/fixture.json')) return;
    $m=pdfTestMeta(); $db=pdfTestServer();
    try {
        $db->select_db($m['base']);
        $exists=$db->execute_query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[$m['base'],'_cp_instalacion_owner'])->fetch_row();
        $owner=$exists ? ($db->query('SELECT token FROM _cp_instalacion_owner')->fetch_row()[0]??'') : ($db->query('SELECT propietario_token FROM instalacion_desarrollo WHERE id=1')->fetch_row()[0]??'');
        if ($db->query('SELECT DATABASE()')->fetch_row()[0] !== $m['base'] || !hash_equals($m['owner'],(string)$owner)) throw new RuntimeException('Se rehúsa limpiar base sin propiedad verificada');
        $db->query('DROP DATABASE `'.$m['base'].'`');
    } finally { $db->close(); }
}
function pdfTestEnv(): array {
    $m=pdfTestMeta(); $c=pdfTestConfig();
    return ['APP_ENV'=>'test','DB_HOST'=>$c['TEST_DB_HOST'],'DB_PORT'=>$c['TEST_DB_PORT'],'DB_NAME'=>$m['base'],'DB_USER'=>$c['TEST_DB_USER'],'DB_PASS'=>$c['TEST_DB_PASS'],'NIP_LOOKUP_SECRET'=>str_repeat('a',64),'DEV_SUPERADMIN_PASSWORD'=>$m['password']];
}
function pdfTestLoadApp(): void {
    foreach(pdfTestEnv() as $key=>$value){$_ENV[$key]=$value;putenv($key.'='.$value);}
    require_once PDF_TEST_ROOT.'/includes/app.php';
    if(Model\ActiveRecord::getDB()->query('SELECT DATABASE()')->fetch_row()[0]!==$_ENV['DB_NAME'])throw new RuntimeException('Base incorrecta');
}
function pdfTestAssert(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label); }
function pdfTestNear(float|int|null $actual,float|int|null $expected,string $label): void { pdfTestAssert($actual===null?$expected===null:($expected!==null&&abs($actual-$expected)<=0.01),$label); }
function pdfTestSnapshot(mysqli $db): string {
    $data=[];foreach(['tickets','ticket_items','ticket_pagos','reservaciones','reservacion_mesas','reservacion_reubicaciones','reservacion_recordatorios','almacen_articulos','movimientos_inventario','operaciones_inventario','operaciones_financieras','gastos_fijos'] as $t){$data[$t]=$db->query('SELECT * FROM `'.$t.'` ORDER BY 1')->fetch_all(MYSQLI_ASSOC);}return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
