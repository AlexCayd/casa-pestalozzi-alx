<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/cliente-http-3e.php';
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
if(count($argv)!==3||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ABS_PRIVADO');
$f=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$f['base_temporal']??''))throw new RuntimeException('Harness no identificado.');
$n=0;$clients=[];$a=static function(bool $v,string $m)use(&$n){if(!$v)throw new RuntimeException($m);$n++;};
$valid=static fn($r)=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
try {
    $admin=new ClienteInstalacion3E();$clients[]=$admin;$admin->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD')]);
    $home=$admin->request('GET','/admin');$a($valid($home)&&str_contains($home['body'],'Inicio · Restaurante'),'Home real en /admin');
    $x=ClienteInstalacion3E::dom($home['body']);
    $a($x->query('//a[contains(@class,"admin-access")]')->length===15,'Quince tarjetas autorizadas');
    $a($x->query('//nav[contains(@class,"admin-sidebar__nav")]/a[1][@href="/admin" and @aria-current="page"]')->length===1,'Inicio primero y activo en sidebar');
    $r=$admin->request('GET','/admin/contexto?contexto=restaurante');$a($r['status']===302&&$r['location']==='/admin','Selector apunta al Home');
    $r=$admin->request('GET','/admin/analytics');$a($valid($r)&&str_contains($r['body'],'window.AdminAnalyticsMock')&&str_contains($r['body'],'data-admin-analytics'),'Dashboard conservado en ruta propia');
    $reader=new ClienteInstalacion3E();$clients[]=$reader;$reader->login($f['cuentas']['lectura_restaurante']);
    $r=$reader->request('GET','/admin');$a($valid($r)&&$r['location']==='','Home sin Analíticas no redirige');
    $x=ClienteInstalacion3E::dom($r['body']);$a($x->query('//a[contains(@class,"admin-access") and @href="/admin/analytics"]')->length===0,'No enlaza Analíticas sin permiso');
    $a($reader->request('GET','/admin/analytics')['status']===403,'Analíticas sigue protegida');
    $r=$reader->request('GET','/admin/contexto?contexto=restaurante');$a($r['location']==='/admin'&&$valid($reader->request('GET',$r['location'])),'Cambio de contexto sin bucles');
    $floor=new ClienteInstalacion3E();$clients[]=$floor;$floor->login($f['cuentas']['piso_nip'],true);
    $a($floor->request('GET','/admin')['status']===403,'NIP no abre Home administrativo');
    foreach(['/admin/almacenes'=>3,'/admin/global/almacenes'=>7,'/admin/panaderia/almacenes'=>2] as $path=>$total) {
        $r=$admin->request('GET',$path);$a($valid($r),'Vista válida '.$path);$x=ClienteInstalacion3E::dom($r['body']);
        $a($x->query('//label[contains(@class,"almacen-choice")]/input[@type="checkbox" and @name="almacen_ids[]"]')->length===$total,'Controles nativos etiquetados '.$path);
        $a($x->query('//form[@data-warehouse-selection]//button[@type="submit"]')->length===1,'Fallback GET sin JavaScript '.$path);
    }
    $r=$admin->request('GET','/admin/almacenes?q=Harina&solo_alertas=1&almacen_ids[]=1002&almacen_ids[]=1005');$a($valid($r),'Alcance múltiple con filtros');
    $x=ClienteInstalacion3E::dom($r['body']);
    $a($x->query('//form[@data-warehouse-selection]//input[@checked]')->length===2,'Selección reflejada desde URL');
    foreach(['q','solo_alertas'] as $name)$a($x->query('//form[@data-warehouse-selection]//input[@type="hidden" and @name="'.$name.'"]')->length===1,'Conserva filtro '.$name);
    $a($x->query('//form[@data-warehouse-selection]//input[@name="pagina"]')->length===0,'Cambio de alcance reinicia paginación');
    $a($admin->request('GET','/admin/almacenes?almacen_ids[]=1003')['status']===403,'No se acepta almacén extranjero');
    echo "HTTP M1.1-E: {$n} comprobaciones OK.\n";
} finally {foreach($clients as $client)$client->close();}
