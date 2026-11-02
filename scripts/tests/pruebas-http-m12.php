<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/cliente-http-3e.php';
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
if(count($argv)!==3||$argv[1]!=='--fixture')throw new RuntimeException('Uso: --fixture ABS_PRIVADO');
$f=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if(($f['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$f['base_temporal']??''))throw new RuntimeException('Harness no identificado.');
$n=0;$clients=[];$a=static function(bool $ok,string $m)use(&$n){if(!$ok)throw new RuntimeException($m);$n++;};
$valid=static fn($r)=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
try{
 $admin=new ClienteInstalacion3E();$clients[]=$admin;$admin->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD')]);
 foreach(['/admin/global/productos?tipo=estandar','/admin/global/productos?tipo=compartido','/admin/global/productos?tipo=exclusivo','/admin/global/productos?tipo=sin_receta','/admin/global/productos/recetario','/admin/global/productos/casa?id=1201','/admin/global/productos/receta?id=1201','/admin/panaderia/menu/casa-receta?id=1201','/admin/menu/casa-receta?id=1402'] as $path)$a($valid($admin->request('GET',$path)),'Lectura funcional '.$path);
 $a($admin->request('GET','/admin/global/productos/casa?id[]=1201')['status']===400,'Identificador estructurado rechazado');
 $page=$admin->request('GET','/admin/global/productos/create');$data=ClienteInstalacion3E::form($page['body'],'guardar');
 $data=array_replace($data,['nombre'=>'Producto HTTP M1.2','descripcion'=>'Identidad temporal compartida','precio'=>'45.25','tipo'=>'compartido','destinos'=>['restaurante','panaderia'],'negocios'=>['restaurante'=>['categoria_id'=>9,'area_id'=>1,'precio'=>'46.25'],'panaderia'=>['categoria_id'=>1201,'area_id'=>10,'precio'=>'44.25']],'reglas'=>['precio'=>1,'descripcion'=>1,'img'=>1]]);
 // Las referencias se toman de los controles del dueño, nunca de otro negocio.
 $xp=ClienteInstalacion3E::dom($page['body']);foreach(['restaurante','panaderia'] as $ctx)foreach(['categoria_id','area_id'] as $field){$options=$xp->query('//select[@name="negocios['.$ctx.']['.$field.']"]/option[@value!="0"]');$data['negocios'][$ctx][$field]=$options->item(0)->getAttribute('value');}
 $bad=$data;$bad['menu_csrf']='invalido';$a($admin->request('POST','/admin/global/productos/create',$bad)['status']===419,'CSRF inválido');
 $post=$admin->request('POST','/admin/global/productos/create',$data);$a($post['status']===303,'Crear borrador usa PRG');$path=$post['location'];$page=$admin->request('GET',$path);$a($valid($page)&&str_contains($page['body'],'Valores que recibirá cada negocio'),'Vista previa por negocio');
 $pub=ClienteInstalacion3E::form($page['body'],'publicar');$pub['confirmar']='1';$post=$admin->request('POST',$path,$pub);$a($post['status']===303,'Publicación compartida HTTP');
 $a($admin->request('POST',$path,$pub)['status']===303,'Doble envío idempotente HTTP');
 $recipe='/admin/global/productos/receta?id=1201';$r=$admin->request('GET',$recipe);$save=ClienteInstalacion3E::form($r['body'],'guardar');$save['rendimiento']='10.000';$save['unidad']='pza';$save['componentes']=[['articulo_id'=>1001,'cantidad'=>'800.000'],['articulo_id'=>1002,'cantidad'=>'250.000']];
 $a($admin->request('POST',$recipe,$save)['status']===303,'Borrador de receta por HTTP');$r=$admin->request('GET',$recipe);$pubRecipe=ClienteInstalacion3E::form($r['body'],'publicar');$pubRecipe['confirmar']='1';$a($admin->request('POST',$recipe,$pubRecipe)['status']===303,'Publicación de receta con dos ofertas HTTP');
 $r=$admin->request('GET',$recipe);$a(str_contains($r['body'],'Versión 2'),'Segunda versión visible');$save=ClienteInstalacion3E::form($r['body'],'guardar');$save['rendimiento']='0';$save['componentes']=[['articulo_id'=>999999,'cantidad'=>'1.000']];$a($admin->request('POST',$recipe,$save)['status']===422,'Receta inválida devuelve validación');
 $localRecipe='/admin/panaderia/menu/casa-receta?id=1209';$r=$admin->request('GET',$localRecipe);$adapt=['menu_csrf'=>ClienteInstalacion3E::token($r['body'],'menu_csrf'),'componentes'=>[['articulo_id'=>1001,'cantidad'=>'18.000'],['articulo_id'=>1002,'cantidad'=>'4.000']]];
 $a($admin->request('POST',$localRecipe,$adapt)['status']===303,'Adaptación autorizada HTTP');$r=$admin->request('GET',$localRecipe);$a(str_contains($r['body'],'Receta adaptada'),'Modo adaptado visible');$volver=ClienteInstalacion3E::form($r['body'],'volver');$a($admin->request('POST',$localRecipe,$volver)['status']===303,'Volver a receta estándar HTTP');
 $r=$admin->request('GET','/admin/panaderia/menu/casa-receta?id=1204');$adapt['menu_csrf']=ClienteInstalacion3E::token($r['body'],'menu_csrf');$a($admin->request('POST','/admin/panaderia/menu/casa-receta?id=1204',$adapt)['status']===422,'Adaptación protegida rechazada HTTP');
 foreach(['lectura_global','administrador_global','operador_panaderia','lectura_panaderia'] as $alias){$c=new ClienteInstalacion3E();$clients[]=$c;$c->login($f['cuentas'][$alias]);
   if(str_contains($alias,'global')){$r=$c->request('GET','/admin/global/productos/create');$a($valid($r),'Lectura Global sin facultad '.$alias);$payload=$data;$payload['menu_csrf']=ClienteInstalacion3E::token($r['body'],'menu_csrf');$a($c->request('POST','/admin/global/productos/create',$payload)['status']===403,'No crear sin facultad '.$alias);$a($c->request('GET','/admin/global/productos/casa?id=1201')['status']===403,'No revelar ofertas ajenas '.$alias);}
   else {$a($c->request('GET','/admin/global/productos')['status']===403,'Local sin acceso Global '.$alias);$r=$c->request('GET','/admin/panaderia/menu/edit?id=1204');$a($valid($r)&&str_contains($r['body'],'Definido por Global'),'Campos protegidos legibles '.$alias);$payload=[];$xp=ClienteInstalacion3E::dom($r['body']);foreach($xp->query('//form[.//input[@name="nombre"]]//input[@name]') as $input)$payload[$input->getAttribute('name')]=$input->getAttribute('value');$payload['menu_csrf']=ClienteInstalacion3E::token($r['body'],'menu_csrf');$payload['nombre']='Nombre manipulado';$payload['precio']='0.01';$payload['id']=1204;$post=$c->request('POST','/admin/panaderia/menu/edit?id=1204',$payload);$a(in_array($post['status'],[403,422],true),'No modificar campos protegidos '.$alias);
     $payload['reglas']=['precio'=>1];$a($c->request('POST','/admin/panaderia/menu/edit?id=1204',$payload)['status']===403,'No modificar reglas locales '.$alias);
     $a(in_array($c->request('GET','/admin/panaderia/menu/edit?id=1402')['status'],[403,404],true),'ID de otro negocio '.$alias);
   }
 }
 foreach(['/menu/pdf','/admin/panaderia/menu/pdf','/admin/global/menus/restaurante','/admin/global/menus/panaderia'] as $pdf){$r=$admin->request('GET',$pdf);$a($r['status']===200&&str_starts_with($r['body'],'%PDF-'),'PDF real compatible '.$pdf);}
 echo "HTTP M1.2: {$n} comprobaciones OK.\n";
}finally{foreach($clients as $c)$c->close();}
