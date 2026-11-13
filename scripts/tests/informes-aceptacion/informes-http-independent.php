<?php
require __DIR__.'/informes-db-bootstrap.php';
$db=null; $socket=null; $server=null; $curl=null; $pipes=[];
try {
$db=pdfTestDb();$before=pdfTestSnapshot($db);$mod=$argv[1]??'finanzas';
$paths=['finanzas'=>'/admin/finanzas','analytics'=>'/admin/analytics','reservaciones'=>'/admin/reservaciones','inventario'=>'/admin/almacenes','tickets'=>'/admin/tickets'];
if(!isset($paths[$mod]))throw new RuntimeException('Modulo invalido');$base=$paths[$mod];$endpoint=$base.'/informe.pdf';$qs='?desde=2001-01-15&hasta=2001-01-17';
$socket=stream_socket_server('tcp://127.0.0.1:0', $eno, $estr); if (!$socket) throw new RuntimeException('No hay puerto local disponible');
$port=(int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket); $socket=null;
$sessionToken=bin2hex(random_bytes(32));
$server=proc_open([PHP_BINARY,'-d','variables_order=EGPCS','-S','127.0.0.1:'.$port,'-t',PDF_TEST_ROOT.'/public',__DIR__.'/informes-http-router.php'],[0=>['pipe','r'],1=>['file',pdfTestTemp().'/http-server.log','a'],2=>['file',pdfTestTemp().'/http-server.log','a']],$pipes,PDF_TEST_ROOT,array_merge(getenv(),pdfTestEnv(), ['PDF_TEST_SESSION_TOKEN'=>$sessionToken]));
if (!is_resource($server)) throw new RuntimeException('No se pudo iniciar el servidor HTTP');
if (isset($pipes[0]) && is_resource($pipes[0])) { fclose($pipes[0]); unset($pipes[0]); }
$curl=curl_init(); if ($curl === false) throw new RuntimeException('No se pudo iniciar curl'); curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['X-PDF-Test-Token: '.$sessionToken],CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>30]);
$request=static function(string $url,bool $reset=false)use($curl,$port):array{if($reset)curl_setopt($curl,CURLOPT_COOKIELIST,'ALL');curl_setopt($curl,CURLOPT_URL,'http://127.0.0.1:'.$port.$url);$raw=curl_exec($curl);if(!is_string($raw))throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);return['code'=>curl_getinfo($curl,CURLINFO_RESPONSE_CODE),'header'=>substr($raw,0,$size),'body'=>substr($raw,$size)];};
$assertDenied=static function(array $response,string $label):void{$detail=substr(preg_replace('/\s+/u',' ',strip_tags($response['body']))??'',0,180);pdfTestAssert(in_array($response['code'],[301,302,401,403],true)&&!str_starts_with($response['body'],'%PDF-'),$label.' code='.$response['code'].' body='.$detail);};
$count=0;
    for($i=0;$i<60;$i++){usleep(100000);$ready=@fsockopen('127.0.0.1',$port,$eno,$estr,.1);if($ready){fclose($ready);break;}}
    $assertDenied($request($endpoint.$qs,true),'Anonimo');$count++;
    foreach(['wrong','bakery','global','nip']as$actor){pdfTestAssert($request('/__fixture/session?actor='.$actor,true)['code']===204,'Fixture sesion');$assertDenied($request($endpoint.$qs),$actor);$count++;}
    pdfTestAssert($request('/__fixture/session?actor=reader',true)['code']===204,'Fixture reader');
    $ok=$request($endpoint.$qs);pdfTestAssert($ok['code']===200&&str_starts_with($ok['body'],'%PDF-')&&str_contains($ok['header'],'2001-01-15_2001-01-17'),'PDF autorizado y periodo GET conservado code='.$ok['code']);
    pdfTestAssert(preg_match('/Content-Type:\s*application\/pdf/i',$ok['header'])===1&&preg_match('/Content-Disposition:\s*inline/i',$ok['header'])===1&&str_contains(strtolower($ok['header']),'no-store'),'Cabeceras PDF');file_put_contents(pdfTestTemp().'/'.$mod.'-http.pdf',$ok['body']);$count++;
    foreach(['?desde=2026-02-30&hasta=2026-03-01','?desde=2026-03-02&hasta=2026-03-01','?desde=2024-01-01&hasta=2025-01-01','?desde[]=2001-01-15&hasta=2001-01-17',$qs.'&unidad_id=2',$qs.'&negocio=panaderia',$qs.'&slug=global']as$q){$bad=$request($endpoint.$q);pdfTestAssert($bad['code']===400&&!str_starts_with($bad['body'],'%PDF-'),'Entrada no rechazada '.$q.' code='.$bad['code']);$count++;}
    $empty=$request($endpoint.'?desde=1990-01-01&hasta=1990-01-02');pdfTestAssert($empty['code']===200&&str_starts_with($empty['body'],'%PDF-'),'Vacio PDF');$count++;
    $static=$request('/build/images/logo.svg');pdfTestAssert($static['code']===200&&preg_match('/Content-Type:\s*image\/svg\+xml/i',$static['header'])===1&&str_contains($static['body'],'<svg'),'El router sirve archivos estáticos reales');$count++;
    $hidden=$request('/.htaccess');pdfTestAssert($hidden['code']===404&&!str_contains($hidden['body'],'RewriteEngine'),'El router no expone archivos ocultos');$count++;
    $hoy=new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City'));$default=$request($endpoint);pdfTestAssert($default['code']===200&&str_starts_with($default['body'],'%PDF-')&&str_contains($default['header'],$hoy->modify('-29 days')->format('Y-m-d').'_'.$hoy->format('Y-m-d')),'Predeterminado30dias retrospectivo');$count++;
    $ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$db->execute_query('UPDATE usuario_unidades SET activo=0 WHERE usuario_id=? AND unidad_id=?',[$ids['users']['reader'],$ids['units']['restaurante']]);try{$assertDenied($request($endpoint.$qs),'Revocacion siguiente peticion');}finally{$db->execute_query('UPDATE usuario_unidades SET activo=1 WHERE usuario_id=? AND unidad_id=?',[$ids['users']['reader'],$ids['units']['restaurante']]);}$count++;
    $source=['finanzas'=>'gastos_fijos','analytics'=>'ticket_items','reservaciones'=>'reservaciones','inventario'=>'movimientos_inventario','tickets'=>'ticket_items'][$mod];
    $db->query('RENAME TABLE `'.$source.'` TO `_pdf_test_missing`');try{$fail=$request($endpoint.$qs);pdfTestAssert($fail['code']===500&&!str_starts_with($fail['body'],'%PDF-')&&!str_contains($fail['body'],'SQL'),'Fuente ausente no controlada');}finally{$db->query('RENAME TABLE `_pdf_test_missing` TO `'.$source.'`');}$count++;
    pdfTestAssert($before===pdfTestSnapshot($db),'Lecturas PDF alteraron tablas operativas');$count++;
    $pageQuery=$mod==='reservaciones'?'?fecha_inicio=2001-01-15&fecha_fin=2001-01-17&estado=cancelada&q=SECRETO_PRIVADO':$qs;
    $page=$request($base.$pageQuery);pdfTestAssert($page['code']===200&&str_contains(html_entity_decode($page['body']),$endpoint.$qs),'Boton preserva periodo code='.$page['code']);
    $dom=new DOMDocument();libxml_use_internal_errors(true);$dom->loadHTML($page['body']);$xp=new DOMXPath($dom);$links=$xp->query('//a[starts-with(@href,"'.$endpoint.'")]');pdfTestAssert($links->length===1,'Accion duplicada/ausente');$link=$links->item(0);pdfTestAssert($link->getAttribute('href')===$endpoint.$qs&&$link->getAttribute('target')==='_blank'&&str_contains($link->getAttribute('aria-label'),'PDF'),'Accion periodos/accessibilidad');$count++;
    echo strtoupper($mod).' HTTP independiente PASS '.$count.' casos: autorizado/anonymous/permisos/negocios/NIP/revocacion/rangos/manipulacion/empty/read-only/boton'.PHP_EOL;
} finally {
    if ($curl !== null && $curl !== false) curl_close($curl);
    foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_resource($socket)) fclose($socket);
    if ($db instanceof mysqli) $db->close();
}
