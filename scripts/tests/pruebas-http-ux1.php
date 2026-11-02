<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
require __DIR__.'/cliente-http-3e.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__,2).'/includes')->safeLoad();
if(count($argv)!==3||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ARCHIVO_PRIVADO');
$f=json_decode(file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$f['base_temporal']??'')||$f['base_temporal']===($_ENV['DB_NAME']??''))throw new RuntimeException('Harness no identificado.');
$n=0;$a=static function(bool $ok,string $m)use(&$n){if(!$ok)throw new RuntimeException($m);$n++;};
$c=new ClienteInstalacion3E();
try {
 $c->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']]);
 $r=$c->request('GET','/admin/global/productos/edit?id=226');
 $a(!str_contains($r['body'],'Presentaciones y consumo de artículos'),'No ofrecer presentación oficial a producto local');
 $r=$c->request('GET','/admin/global/productos/presentaciones?id=226');
 $a($r['status']===403&&str_contains($r['body'],'admin-page')&&str_contains($r['body'],'Volver al catálogo'),'Error contextual sin cambiar autorización');
 $path='/admin/panaderia/recetas/articulos/editar?id=1001&guardado=1';
 $r=$c->request('GET',$path);$a(!str_contains($r['body'],'Cambios confirmados correctamente.'),'URL no fabrica éxito');
 $data=ClienteInstalacion3E::form($r['body'],'configurar');
 $data['elaborable']='1';unset($data['produccion_previa']);
 $r=$c->request('POST',$path,$data);$a($r['status']===303,'Guardar capacidades con PRG');
 $r=$c->request('GET',$path);$a(str_contains($r['body'],'Cambios confirmados correctamente.'),'Éxito real una vez');
 $data=ClienteInstalacion3E::form($r['body'],'configurar');
 $r=$c->request('GET',$path);$a(!str_contains($r['body'],'Cambios confirmados correctamente.'),'Éxito consumido');
 $data['produccion_previa']='1';unset($data['almacenable']);
 $r=$c->request('POST',$path,$data);
 $a($r['status']===422&&str_contains($r['body'],'La producción previa requiere elaboración y almacenamiento.'),'Error específico de capacidades');
 $a(!str_contains($r['body'],'Cambios confirmados correctamente.'),'Rechazo sin éxito simultáneo');
 $a(str_contains($r['body'],'Versiones publicadas'),'Error conserva ficha');
 $lector=new ClienteInstalacion3E();
 try {
  $lector->login($f['cuentas']['lectura_global']);
  $r=$lector->request('GET','/admin/global/productos/edit?id=226');
  $a(!str_contains($r['body'],'Presentaciones y consumo de artículos'),'Sin Recetas no ofrece acceso a presentaciones');
  $r=$lector->request('GET','/admin/global/recetas/articulos/editar?id=1001');
  $a(in_array($r['status'],[403,303],true),'Permiso parcial no obtiene ficha de Recetas');
 } finally {$lector->close();}
 echo "UX1 HTTP: {$n} comprobaciones OK.\n";
}finally{$c->close();}
