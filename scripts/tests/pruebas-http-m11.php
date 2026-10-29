<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/cliente-http-3e.php';
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
if(count($argv)!==3||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ABS_PRIVADO');
$f=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$f['base_temporal']??''))throw new RuntimeException('Harness no identificado.');
$n=0;$clients=[];
$a=static function(bool $ok,string $m)use(&$n):void{if(!$ok)throw new RuntimeException($m);$n++;};
$valid=static fn($r)=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
try{
 $admin=new ClienteInstalacion3E();$clients[]=$admin;$admin->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD')]);
 $lector=new ClienteInstalacion3E();$clients[]=$lector;$lector->login($f['cuentas']['lectura_global']);
 foreach(['/admin/global','/admin/panaderia'] as $path){$r=$admin->request('GET',$path);$a($valid($r),'Home real '.$path);$a(str_contains($r['body'],'admin-access-grid'),'Cuadrícula de accesos '.$path);}
 $r=$admin->request('GET','/admin/global');foreach(['Negocios','Administración','Menús','/admin/contexto?contexto=restaurante','/admin/global/productos'] as $text)$a(str_contains($r['body'],$text),'Home Global contiene '.$text);
 $r=$admin->request('GET','/admin/panaderia');foreach(['/admin/panaderia/menu','/admin/panaderia/almacenes?vista=transferencias','/admin/panaderia/usuarios'] as $text)$a(str_contains($r['body'],$text),'Acceso Panadería '.$text);
 $a(!str_contains($r['body'],'href="/admin/panaderia/recetas"'),'No enlazar módulo pendiente');
 $r=$lector->request('GET','/admin/global');$a(!str_contains($r['body'],'contexto=restaurante'),'Home no revela acceso a negocio ajeno');
 $a($lector->request('GET','/admin/panaderia')['status']===403,'Home extranjero denegado');
 $r=$admin->request('GET','/admin/global/productos?negocio=panaderia&uso=con');$a($valid($r),'Filtro autorizado de catálogo');
 $a(str_contains($r['body'],'admin-catalogo-table')&&str_contains($r['body'],'Hogaza Panadería'),'Listado compacto con oferta propia');
 $a(!str_contains($r['body'],'Aceitunas Temperadas con Aceite'),'No incluir oferta exclusiva de otro negocio bajo filtro');
 $a($lector->request('GET','/admin/global/productos?negocio=panaderia')['status']===403,'Filtro no concede acceso a negocio ajeno');
 $r=$lector->request('GET','/admin/global/productos?uso=con');$a($valid($r)&&str_contains($r['body'],'No hay identidades que coincidan'),'Recuentos no filtran ofertas ajenas');
 $r=$admin->request('GET','/admin/global/productos?page=2');$a($valid($r)&&str_contains($r['body'],'Página 2 de'),'Paginación real de catálogo');
 $r=$admin->request('GET','/admin/global/productos?q=1003');$a($valid($r)&&str_contains($r['body'],'Hogaza Panadería'),'Buscar por identificador permanente');
 echo "HTTP M1.1: {$n} comprobaciones OK.\n";
}finally{foreach($clients as $c)$c->close();}
