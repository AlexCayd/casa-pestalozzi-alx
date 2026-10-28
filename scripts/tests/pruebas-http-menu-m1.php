<?php
declare(strict_types=1);
/** Matriz HTTP contra el harness 3E identificado; sin conexión SQL. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/cliente-http-3e.php';
$root=dirname(__DIR__,2); require $root.'/vendor/autoload.php';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
if (count($argv)!==3 || $argv[1]!=='--fixture') throw new RuntimeException('Uso: --fixture ABS_PRIVADO');
$f=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if (($f['url']??'')!=='http://127.0.0.1:8094/' || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$f['base_temporal']??'')) throw new RuntimeException('Harness inválido.');
$clients=[]; $n=0;
$assert=static function(bool $v,string $m)use(&$n):void { if(!$v)throw new RuntimeException($m);$n++; };
$valid=static fn(array $r):bool=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
$data=static function(string $body):array {
    $x=ClienteInstalacion3E::dom($body);$out=[];
    foreach($x->query('//form[@method="POST"]//input[@name and not(@type="file")]|//form[@method="POST"]//textarea[@name]') as $i){
        if(in_array($i->getAttribute('type'),['checkbox','radio'],true)&&!$i->hasAttribute('checked'))continue;
        $out[$i->getAttribute('name')]=$i->nodeName==='textarea'?$i->textContent:$i->getAttribute('value');
    }
    return $out;
};
try {
    $anon=new ClienteInstalacion3E();$clients[]=$anon;
    foreach(['/admin/menu','/admin/panaderia/menu','/admin/global/productos'] as $path)$assert($anon->request('GET',$path)['status']===401,'Autenticación requerida '.$path);
    $admin=new ClienteInstalacion3E();$clients[]=$admin;
    $admin->login(['username'=>'admin_demo','password'=>$_ENV['DEV_SUPERADMIN_PASSWORD']??getenv('DEV_SUPERADMIN_PASSWORD')]);
    $reader=new ClienteInstalacion3E();$clients[]=$reader;$reader->login($f['cuentas']['lectura_panaderia']);
    $editor=new ClienteInstalacion3E();$clients[]=$editor;$editor->login($f['cuentas']['operador_panaderia']);
    foreach(['/admin/menu','/admin/panaderia/menu','/admin/global/productos','/admin/panaderia/menu/categorias','/admin/global/productos/edit?id=1001'] as $path)$assert($valid($admin->request('GET',$path)),'Superficie completa '.$path);
    $base='/admin/panaderia/menu';$page=$reader->request('GET',$base);
    $assert($valid($page)&&!str_contains($page['body'],'/menu/create'),'Lectura sin alta');
    $assert($reader->request('POST',$base.'/toggle',['id'=>1003])['status']===403,'Lectura no escribe por endpoint directo');
    $assert($reader->request('GET','/admin/menu')['status']===403,'Membresía local no lee Restaurante');
    $assert($editor->request('POST',$base.'/toggle',['id'=>1003])['status']===419,'CSRF obligatorio');
    $rest=$admin->request('GET','/admin/menu/create');$cross=ClienteInstalacion3E::token($rest['body'],'menu_csrf');
    $assert($admin->request('POST',$base.'/toggle',['id'=>1003,'menu_csrf'=>$cross])['status']===419,'CSRF contextual impide cruce');
    $form=$editor->request('GET',$base.'/create');$assert($valid($form),'Formulario nuevo Panadería');
    $x=ClienteInstalacion3E::dom($form['body']);
    $cat=$x->query('//input[@name="categoria_id"]')->item(0)->getAttribute('value');
    $area=$x->query('//input[@name="area_id"]')->item(0)->getAttribute('value');
    $csrf=ClienteInstalacion3E::token($form['body'],'menu_csrf');
    $assert($editor->request('POST',$base.'/create',['menu_csrf'=>$csrf,'nombre'=>['dato']])['status']===422,'Rechazar campos de estructura inválida');
    $invalid=$editor->request('POST',$base.'/categorias/create',['menu_csrf'=>$csrf,'nombre'=>'Carta inválida','carta'=>'inexistente']);
    $assert($valid($invalid)&&str_contains($invalid['body'],'Elige a qué carta pertenece la categoría'),'Carta desconocida muestra validación');
    $name='Pan de aceptación '.bin2hex(random_bytes(3));
    $post=['menu_csrf'=>$csrf,'nombre'=>$name,'descripcion'=>'Producto temporal para aceptación M1','categoria_id'=>$cat,'area_id'=>$area,'precio'=>'19.95','activo'=>1,'id'=>1001,'oferta_id'=>1001,'producto_id'=>1001,'unidad_id'=>2];
    $r=$editor->request('POST',$base.'/create',$post);$assert($valid($r)&&str_contains($r['body'],'Platillo creado correctamente'),'Alta ignora IDs inyectados');
    $list=$editor->request('GET',$base.'?q='.urlencode($name));$x=ClienteInstalacion3E::dom($list['body']);
    $link=$x->query('//a[contains(@href,"/menu/edit?id=")]')->item(0);$assert($link!==null,'Oferta creada consultable');
    $edit=html_entity_decode($link->getAttribute('href'));$r=$editor->request('GET',$edit);$values=$data($r['body']);
    $values['precio']='27.35';$r=$editor->request('POST',$edit,$values);$assert($valid($r)&&str_contains($r['body'],'Platillo actualizado correctamente'),'Editar precio local');
    $assert(str_contains($editor->request('GET',$edit)['body'],'27.35'),'Precio exacto persistido');
    $upload=tempnam(sys_get_temp_dir(),'cp-m1-image-');
    if($upload===false)throw new RuntimeException('No se creó imagen temporal.');
    try {
        $img=imagecreatetruecolor(8,8);imagepng($img,$upload);
        $photo=$data($editor->request('GET',$edit)['body']);$photo['precio']='incorrecto';
        $photo['imagen']=new CURLFile($upload,'image/png','m1-evidencia.png');
        $r=$editor->request('POST',$edit,$photo);
        $assert($valid($r)&&str_contains($r['body'],'name="imagen_pendiente"'),'Foto pendiente sobrevive a validación fallida');
        $pending=$data($r['body']);$pending['precio']='27.35';
        $r=$editor->request('POST',$edit,$pending);$assert(str_contains($r['body'],'Platillo actualizado correctamente'),'Confirmar foto pendiente sin reenviar archivo');
        $r=$editor->request('GET',$edit);$px=ClienteInstalacion3E::dom($r['body']);$imageUrl=$px->query('//*[@data-foto-platillo]')->item(0)->getAttribute('data-actual');
        $assert(str_starts_with($imageUrl,'/build/images/'),'Imagen propia guardada con ruta restringida');
        $assert($anon->request('GET',$imageUrl)['status']===200,'Archivo de imagen confirmado existe');
        $remove=$data($r['body']);$remove['quitar_imagen']='1';
        $r=$editor->request('POST',$edit,$remove);$assert(str_contains($r['body'],'Platillo actualizado correctamente'),'Quitar foto mediante servicio compartido');
        $assert($anon->request('GET',$imageUrl)['status']===404,'Archivo sin referencias retirado tras confirmar');
    }finally{if(is_file($upload))unlink($upload);}
    $catName='Categoría de aceptación '.bin2hex(random_bytes(3));
    $r=$editor->request('POST',$base.'/categorias/create',['menu_csrf'=>$csrf,'nombre'=>$catName,'carta'=>'comida','id'=>1,'unidad_id'=>2]);
    $assert($valid($r)&&str_contains($r['body'],'Categoría creada correctamente'),'Alta categoría ignora IDs inyectados');
    $assert(!str_contains($admin->request('GET','/admin/menu/categorias')['body'],$catName),'Categoría nueva no aparece en Restaurante');
    $assert(str_contains($editor->request('GET',$base.'/categorias')['body'],$catName),'Categoría nueva solo aparece en Panadería');

    $values['categoria_id']=1;$r=$editor->request('POST',$edit,$values);$assert(!str_contains($r['body'],'Platillo actualizado correctamente'),'Categoría extranjera rechazada');
    $rf=$admin->request('GET','/admin/menu/create');$rx=ClienteInstalacion3E::dom($rf['body']);
    $restNombre='Identidad compartida '.bin2hex(random_bytes(4));
    $rd=['menu_csrf'=>ClienteInstalacion3E::token($rf['body'],'menu_csrf'),'nombre'=>$restNombre,'descripcion'=>'Identidad temporal compartida','precio'=>'99.95','categoria_id'=>$rx->query('//input[@name="categoria_id"]')->item(0)->getAttribute('value'),'area_id'=>$rx->query('//input[@name="area_id"]')->item(0)->getAttribute('value'),'activo'=>1];
    $r=$admin->request('POST','/admin/menu/create',$rd);$assert(str_contains($r['body'],'Platillo creado correctamente'),'Alta Restaurante conserva recorrido');
    $gp=$admin->request('GET','/admin/global/productos?q='.urlencode($restNombre));$gx=ClienteInstalacion3E::dom($gp['body']);$gl=$gx->query('//a[contains(@href,"/productos/edit?id=")]')->item(0);$assert($gl!==null,'Identidad nueva en catálogo Global');
    parse_str(parse_url($gl->getAttribute('href'),PHP_URL_QUERY),$ids);$sharedPid=(int)$ids['id'];
    // La oferta compartida se crea desde identidad explícita, nunca desde un nombre.
    $r=$editor->request('POST',$base.'/asociar',['menu_csrf'=>$csrf,'producto_id'=>$sharedPid,'categoria_id'=>$cat,'area_id'=>$area,'precio'=>'10.25','activo'=>1]);
    $assert($valid($r)&&str_contains($r['body'],'Oferta asociada correctamente'),'Asociar identidad existente');
    $r=$editor->request('GET',$base.'?q='.urlencode($restNombre));$x=ClienteInstalacion3E::dom($r['body']);$link=$x->query('//a[contains(@href,"/menu/edit?id=")]')->item(0);$assert($link!==null,'Oferta compartida listada');
    $shared=html_entity_decode($link->getAttribute('href'));$r=$editor->request('GET',$shared);$values=$data($r['body']);
    $assert(str_contains($r['body'],'readonly')&&str_contains($r['body'],'identidad compartida'),'Identidad compartida explica restricción');
    $values['precio']='11.35';$r=$editor->request('POST',$shared,$values);$assert(str_contains($r['body'],'Platillo actualizado correctamente'),'Editar oferta compartida sin tocar identidad');
    $values['nombre']='Cambio no autorizado';$r=$editor->request('POST',$shared,$values);$assert(!str_contains($r['body'],'Platillo actualizado correctamente'),'Datos maestros requieren facultad');
    $master=$admin->request('GET','/admin/global/productos/edit?id='.$sharedPid);$m=$data($master['body']);$m['nombre']='Café compartido de aceptación';
    $r=$admin->request('POST','/admin/global/productos/edit?id='.$sharedPid,$m);$assert(!str_contains($r['body'],'Identidad comercial actualizada'),'Global requiere confirmación expresa');
    $m['confirmar_identidad']='1';$r=$admin->request('POST','/admin/global/productos/edit?id='.$sharedPid,$m);$assert(str_contains($r['body'],'Identidad comercial actualizada'),'Global cambia solo identidad autorizada');
    $r=$admin->request('POST','/admin/global/productos/edit?id='.$sharedPid,$m);$assert(!str_contains($r['body'],'Identidad comercial actualizada'),'Edición concurrente obsoleta rechazada');
    $inv=new ClienteInstalacion3E();$clients[]=$inv;$inv->login($f['cuentas']['administrador_global']);
    $r=$inv->request('GET','/admin/global/productos/edit?id='.$sharedPid);$assert($valid($r)&&!str_contains($r['body'],'name="confirmar_identidad"'),'Facultad inventariable no abre edición comercial');
    $r=$inv->request('POST','/admin/global/productos/edit?id='.$sharedPid,['menu_csrf'=>ClienteInstalacion3E::token($r['body'],'menu_csrf')]);$assert($r['status']===403,'Servidor deniega facultad comercial ausente');
    foreach(['/admin/panaderia/recetas','/admin/panaderia/menu/variantes','/admin/panaderia/menu/pdf'] as $path)$assert($admin->request('GET',$path)['status']!==200,'No habilitar fases futuras '.$path);
    foreach(['/menu/pdf','/maridaje/pdf'] as $path){$r=$anon->request('GET',$path);$assert($r['status']===200&&str_starts_with($r['body'],'%PDF-'),'PDF Restaurante '.$path);}
    echo "HTTP Menú M1: {$n} comprobaciones OK.\n";
} finally {foreach($clients as $c)$c->close();}
