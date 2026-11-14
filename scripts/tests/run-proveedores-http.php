<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require __DIR__.'/informes-aceptacion/informes-db-bootstrap.php';
require_once dirname(__DIR__,2).'/vendor/autoload.php';
$root=dirname(__DIR__,2);$evidence=$root.'/docs/desarrollo/evidencia-etapa1-simplificacion';
Dotenv\Dotenv::createImmutable($root.'/includes')->safeLoad();
foreach(['TEST_DB_HOST'=>'DB_HOST','TEST_DB_PORT'=>'DB_PORT','TEST_DB_USER'=>'DB_USER','TEST_DB_PASS'=>'DB_PASS'] as $testKey=>$appKey){
    $localValue=$_ENV[$appKey]??($testKey==='TEST_DB_PORT'?'3306':null);
    if(getenv($testKey)===false&&is_string($localValue)&&($localValue!==''||$testKey==='TEST_DB_PASS'))putenv($testKey.'='.$localValue);
}
pdfTestConfig();
if(!is_dir($evidence)&&!mkdir($evidence,0775,true))throw new RuntimeException('No se creó la carpeta de evidencia.');
$temp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'cp_prov_http_'.bin2hex(random_bytes(12));
if(!mkdir($temp,0700))throw new RuntimeException('No se creó el temporal HTTP.');
file_put_contents($temp.'/.pdf-test-owner',bin2hex(random_bytes(32)),LOCK_EX);putenv('PDF_TEST_TEMP='.$temp);
$server=null;$pipes=[];$curl=null;$jar=null;$created=false;$count=0;$fail=null;$serverStopped=false;
$a=static function(bool $ok,string $label)use(&$count):void{if(!$ok)throw new RuntimeException($label);$count++;};
$serverDb=null;
try{
    $created=true;pdfTestSetup();$serverDb=pdfTestDb();
    // Instalar el esquema completo en test y cargar los datos sintéticos SQL sin
    // crear ni cambiar la credencial de admin_demo en la base de la aplicación.
    require_once $root.'/scripts/multinegocio/SqlArchivo.php';
    $owner=(string)pdfTestMeta()['owner'];
    $serverDb->query('CREATE TABLE _cp_instalacion_owner(token CHAR(64) PRIMARY KEY) ENGINE=InnoDB');
    $serverDb->execute_query('INSERT INTO _cp_instalacion_owner(token) VALUES (?)',[$owner]);
    $serverDb->query('SET @cp_dev_admin_demo_seed=0');
    foreach(Multinegocio\SqlArchivo::sentencias((string)file_get_contents($root.'/database/development.sql')) as $statement){$result=$serverDb->query($statement);if($result instanceof mysqli_result)$result->free();}
    $units=array_column($serverDb->query('SELECT id,slug FROM unidades')->fetch_all(MYSQLI_ASSOC),'id','slug');$users=[];
    $fixturePassword=(string)pdfTestMeta()['password'];
    foreach(['edit'=>['restaurante','editar'],'read'=>['restaurante','ver'],'global'=>['global','ver'],'bakery'=>['panaderia','ver']] as $actor=>[$slug,$level]){
        $serverDb->execute_query('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin) VALUES (?,?,?,1,0)',['prov_http_'.$actor,'Fixture proveedor '.$actor,password_hash($fixturePassword,PASSWORD_DEFAULT)]);$users[$actor]=(int)$serverDb->insert_id;
        $serverDb->execute_query('INSERT INTO usuario_unidades(usuario_id,unidad_id,activo) VALUES (?,?,1)',[$users[$actor],$units[$slug]]);
        $serverDb->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,? ,\'inventario\',?)',[$users[$actor],$units[$slug],$level]);
    }
    $prices=(int)$serverDb->query('SELECT COUNT(*) FROM historial_precios_proveedores WHERE (unidad_id=2 AND proveedor_id=1001 AND articulo_id=1002)')->fetch_row()[0];
    $a($prices===45,'La base temporal parte de la semilla SQL Restaurante de 45 eventos.');
    file_put_contents($temp.'/fixture-ids.json',json_encode(['users'=>$users,'units'=>$units],JSON_THROW_ON_ERROR),LOCK_EX);

    $socket=stream_socket_server('tcp://127.0.0.1:0',$eno,$estr);if(!$socket)throw new RuntimeException('No hay puerto loopback disponible.');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
    $token=bin2hex(random_bytes(32));$env=array_merge(getenv(),pdfTestEnv(),['PDF_TEST_TEMP'=>$temp,'PROVEEDORES_HTTP_TOKEN'=>$token]);
    $server=proc_open([PHP_BINARY,'-d','variables_order=EGPCS','-S','127.0.0.1:'.$port,'-t',$root.'/public',$root.'/scripts/tests/proveedores-http-router.php'],[0=>['pipe','r'],1=>['file',$temp.'/http-server.log','a'],2=>['file',$temp.'/http-server.log','a']],$pipes,$root,$env);
    if(!is_resource($server))throw new RuntimeException('No inició el servidor HTTP temporal.');if(isset($pipes[0])&&is_resource($pipes[0])){fclose($pipes[0]);unset($pipes[0]);}
    $ready=false;for($i=0;$i<80;$i++){usleep(100000);$s=@fsockopen('127.0.0.1',$port,$eno,$estr,.1);if($s){fclose($s);$ready=true;break;}}if(!$ready)throw new RuntimeException('Servidor HTTP temporal no respondió.');
    $jar=tempnam($temp,'cookies-');$curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>$jar,CURLOPT_COOKIEJAR=>$jar,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['X-Proveedores-Test-Token: '.$token]]);
    $request=static function(string $path)use($curl,$port):array{curl_setopt($curl,CURLOPT_URL,'http://127.0.0.1:'.$port.$path);$raw=curl_exec($curl);if(!is_string($raw))throw new RuntimeException(curl_error($curl));$size=(int)curl_getinfo($curl,CURLINFO_HEADER_SIZE);return['code'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'header'=>substr($raw,0,$size),'body'=>substr($raw,$size)];};
    $requestPost=static function(string $path,array $data)use($curl,$port):array{curl_setopt_array($curl,[CURLOPT_URL=>'http://127.0.0.1:'.$port.$path,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_POSTFIELDS=>http_build_query($data)]);$raw=curl_exec($curl);curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>'GET',CURLOPT_POSTFIELDS=>'',CURLOPT_HTTPGET=>true]);if(!is_string($raw))throw new RuntimeException(curl_error($curl));$size=(int)curl_getinfo($curl,CURLINFO_HEADER_SIZE);return['code'=>(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'header'=>substr($raw,0,$size),'body'=>substr($raw,$size)];};
    $login=static function(string $actor)use($request,$curl,$token):void{curl_setopt($curl,CURLOPT_COOKIELIST,'ALL');$r=$request('/__fixture/session?actor='.rawurlencode($actor));if($r['code']!==204)throw new RuntimeException('No se inició actor HTTP '.$actor.' ('.$r['code'].').');};
    $dom=static function(string $html):array{$d=new DOMDocument();$old=libxml_use_internal_errors(true);$d->loadHTML($html,LIBXML_NOWARNING|LIBXML_NOERROR);libxml_clear_errors();libxml_use_internal_errors($old);return[$d,new DOMXPath($d)];};
    $text=static function(string $html):string{return trim(preg_replace('/\s+/u',' ',strip_tags($html))??'');};
    $base='/admin/almacenes/proveedores';$articlePath=$base.'/ficha?id=1001';$historyPath=$base.'/historial?id=1001&articulo=1002';

    $login('edit');$articles=$request($articlePath);[$doc,$xp]=$dom($articles['body']);
    $a($articles['code']===200&&str_contains($articles['body'],'data-proveedor-vista="articulos"'),'La ficha abre Artículos por defecto vía HTTP.');
    $a($xp->query('//*[@id="proveedor-articulos"]')->length===1&&$xp->query('//*[@id="proveedor-pedidos" or @id="proveedor-datos"]')->length===0,'La vista Artículos pinta solo su contenido.');
    $a($xp->query('//form[contains(@action,"/asignaciones")]')->length===0&&str_contains($articles['body'],'Editar artículos'),'Los controles de edición requieren acción explícita.');
    $historyLink=$xp->query('//a[contains(@class,"proveedor-historial-link")]')->item(0)?->getAttribute('href')??'';
    $a(str_contains($historyLink,'/historial?id=1001&articulo=1002'),'Cada asociación lleva al historial dedicado.');
    $edit=$request($articlePath.'&vista=articulos&editar=1');[$docEdit,$xpEdit]=$dom($edit['body']);
    $a($edit['code']===200&&$xpEdit->query('//form[contains(@action,"/asignaciones")]')->length===1&&$xpEdit->query('//input[contains(@name,"[costo]")]')->length>0,'La edición explícita muestra inputs solo al usuario autorizado.');

    $entries=$request($base.'/ficha?id=1001&vista=entradas');[$docEntries,$xpEntries]=$dom($entries['body']);
    $a($entries['code']===200&&$xpEntries->query('//*[@id="proveedor-pedidos"]')->length===1&&$xpEntries->query('//*[@id="proveedor-articulos" or @id="proveedor-datos"]')->length===0,'Entradas muestra recepciones y costos sin duplicar otras vistas.');
    $data=$request($base.'/ficha?id=1001&vista=datos');[$docData,$xpData]=$dom($data['body']);
    $a($data['code']===200&&$xpData->query('//*[@id="proveedor-datos"]')->length===1&&$xpData->query('//*[@id="proveedor-articulos" or @id="proveedor-pedidos"]')->length===0,'Datos muestra contacto/configuración y retiro sin el historial completo.');
    $a($xpData->query('//form[contains(@action,"/eliminar")]')->length===0,'No ofrece eliminación si tiene movimientos o historia de precios.');

    $hist1=$request($historyPath);[$docHist,$xpHist]=$dom($hist1['body']);$rows1=$xpHist->query('//table[contains(@class,"proveedor-precios__tabla")]//tbody/tr');
    $a($hist1['code']===200&&$rows1->length===25&&str_contains($hist1['body'],'Precio de referencia vigente')&&str_contains($hist1['body'],'Precio anterior comparable'),'El detalle HTTP ofrece contexto y primera página de 25 eventos.');
    $next=$xpHist->query('//nav[contains(@class,"proveedor-precios__paginacion")]//a[contains(.,"Siguiente")]')->item(0)?->getAttribute('href')??'';
    $a(str_contains($next,'/historial?id=1001&articulo=1002&pagina=2'),'El enlace Siguiente conserva proveedor y artículo.');
    $hist2=$request($next);[$docHist2,$xpHist2]=$dom($hist2['body']);$rows2=$xpHist2->query('//table[contains(@class,"proveedor-precios__tabla")]//tbody/tr');
    $a($hist2['code']===200&&$rows2->length===20&&str_contains($hist2['body'],'Página 2 de 2'),'La página 2 es navegación HTTP real y entrega las 20 filas restantes.');
    $first1=trim($xpHist->query('//table[contains(@class,"proveedor-precios__tabla")]//tbody/tr[1]/td[1]')->item(0)?->textContent??'');
    $first2=trim($xpHist2->query('//table[contains(@class,"proveedor-precios__tabla")]//tbody/tr[1]/td[1]')->item(0)?->textContent??'');
    $a($first1!==$first2,'Las dos páginas HTTP contienen filas distintas.');
    $legacy=$request($base.'/ficha?id=1001&historial_articulo=1002&historial_pagina=2');
    $a($legacy['code']===303&&str_contains($legacy['header'],'/historial?id=1001&articulo=1002&pagina=2'),'El enlace histórico anterior redirige a la nueva URL de detalle.');

    $login('read');$readonly=$request($articlePath);[$docRead,$xpRead]=$dom($readonly['body']);
    $a($readonly['code']===200&&$xpRead->query('//a[contains(.,"Editar artículos")]')->length===0&&$xpRead->query('//form[contains(@action,"/asignaciones") or contains(@action,"/guardar")]')->length===0,'El lector ve Artículos sin controles de escritura.');
    $readerHistoryHref=$xpRead->query('//a[contains(@class,"proveedor-historial-link")]')->item(0)?->getAttribute('href')??'';
    $a(str_contains($readerHistoryHref,'/historial?id=1001&articulo=1002'),'El lector conserva acceso al historial individual.');
    $denied=$requestPost($base.'/asignaciones',['id'=>'1001','filas'=>[]]);
    $a($denied['code']===403,'El lector no puede guardar artículos por HTTP.');

    $login('global');$global=$request('/admin/global/almacenes/proveedores/historial?id=1001&articulo=1002');
    $a($global['code']===200&&str_contains($global['body'],'Proveedor de Restaurante')&&str_contains($global['body'],'consulta desde Global')&&str_contains($global['body'],'Precios vigentes en Restaurante'),'Global consulta historia y comparación dentro del ámbito propietario.');
    $a(!preg_match('/<form\b[^>]*(?:guardar|asignaciones|eliminar)/i',$global['body']),'Global consulta sin formularios administrativos.');
    $login('bakery');$foreign=$request('/admin/panaderia/almacenes/proveedores/historial?id=1001&articulo=1002');
    $a($foreign['code']===404||$foreign['code']===403,'Otro ámbito no revela el proveedor ni su historial.');
    $login('edit');$invalid=$request($base.'/historial?id=1001&articulo=1001');
    $a($invalid['code']===404&&!str_contains($invalid['body'],'Proveedor Global'),'Un par proveedor/artículo ajeno responde 404 sin filtrar historia.');

    $capture=proc_open(['node',$root.'/scripts/tests/capture-proveedores-http.cjs',(string)$port,$token,$evidence], [0=>['file','NUL','r'],1=>['pipe','w'],2=>['pipe','w']],$cp,$root,array_merge(getenv(),['PROVEEDORES_HTTP_TOKEN'=>$token]));
    if(!is_resource($capture))throw new RuntimeException('No inició Playwright de captura HTTP.');$captureOut=stream_get_contents($cp[1]);$captureErr=stream_get_contents($cp[2]);fclose($cp[1]);fclose($cp[2]);$captureCode=proc_close($capture);
    if($captureCode!==0)throw new RuntimeException('Captura Playwright falló: '.substr($captureErr.$captureOut,0,500));
    file_put_contents($evidence.'/ui-capture.log',$captureOut,LOCK_EX);

    // Comprueba acceso de retirados en la misma base efímera tras conservar sus 45 eventos.
    $serverDb->execute_query('DELETE FROM ingrediente_proveedores WHERE unidad_id=2 AND proveedor_id=1001 AND ingrediente_id=1002');
    $retirados=$request($articlePath.'&retirados=1');[$docRetirados,$xpRetirados]=$dom($retirados['body']);
    $retiradoHref=$xpRetirados->query('//*[contains(@class,"proveedor-retirados__lista")]//a')->item(0)?->getAttribute('href')??'';
    $a($retirados['code']===200&&str_contains($retirados['body'],'Artículos retirados')&&str_contains($retiradoHref,'/historial?id=1001&articulo=1002'),'La asociación retirada sigue accesible por la vía secundaria.');
    $retiradoHist=$request($historyPath);
    $a($retiradoHist['code']===200&&str_contains($retiradoHist['body'],'asociación retirada')&&str_contains($retiradoHist['body'],'Demostración sintética'),'El historial de asociación retirada conserva contexto y actor snapshots.');

    echo "Proveedores HTTP: {$count} comprobaciones OK.\n".$captureOut;
}catch(Throwable $e){$fail=$e;}
finally{
    if($curl instanceof CurlHandle)curl_close($curl);
    if(is_resource($server)){proc_terminate($server);foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($server);$serverStopped=true;}
    if($serverDb instanceof mysqli)$serverDb->close();
    if($serverStopped)echo "Servidor HTTP temporal detenido.\n";
    try{if($created){pdfTestCleanup();echo "Base temporal HTTP propia eliminada.\n";}}catch(Throwable $e){fwrite(STDERR,'No se pudo limpiar la base temporal: '.$e->getMessage().PHP_EOL);exit(1);}
    foreach(new DirectoryIterator($temp)as$item){if($item->isDot())continue;if($item->isFile()&&!$item->isLink())unlink($item->getPathname());else{$fail??=new RuntimeException('Temporal con contenido inesperado.');}}
    if(!$fail)rmdir($temp);
}
if($fail){fwrite(STDERR,'FAIL proveedores HTTP: '.$fail->getMessage().PHP_EOL);exit(1);}
