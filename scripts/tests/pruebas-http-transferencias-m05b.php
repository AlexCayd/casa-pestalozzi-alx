<?php
declare(strict_types=1);
/** Flujos HTTP reales sobre el harness nuevo identificado, sin acceso SQL. */
if(PHP_SAPI!=='cli'||($argv[1]??'')!=='--fixture'||empty($argv[2]))exit(1);
$root=dirname(__DIR__,2);require $root.'/vendor/autoload.php';require __DIR__.'/cliente-http-3e.php';
$fixture=json_decode((string)file_get_contents($argv[2]),true,512,JSON_THROW_ON_ERROR);
if(($fixture['url']??'')!=='http://127.0.0.1:8094/'||!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D',$fixture['base_temporal']??''))throw new RuntimeException('Harness aislado identificado requerido.');
$count=0;$clients=[];$assert=static function(bool $ok,string $msg)use(&$count):void{if(!$ok)throw new RuntimeException($msg);$count++;};
$valid=static fn(array $r):bool=>$r['status']===200&&!preg_match('/Fatal error|Warning:|Uncaught /',$r['body']);
$confirmed=static fn(array $r):bool=>$valid($r)&&str_contains($r['body'],'confirmada correctamente');
try{
 $bases=['global'=>'/admin/global/almacenes','restaurante'=>'/admin/almacenes','panaderia'=>'/admin/panaderia/almacenes'];$ops=[];
 foreach($bases as $ctx=>$base){$c=new ClienteInstalacion3E();$clients[]=$c;$ops[$ctx]=$c;$assert(in_array($c->login($fixture['cuentas']['operador_'.$ctx])['status'],[302,303],true),'Login con contraseña y segundo factor '.$ctx);$r=$c->request('GET',$base.'?vista=transferencias');$assert($valid($r)&&str_contains($r['body'],'Solicitar transferencia'),'Formulario compartido '.$ctx);}
 $suffix=bin2hex(random_bytes(8));
 $lineas=[['articulo_id'=>'1001','cantidad'=>'12.125'],['articulo_id'=>'1002','cantidad'=>'10.250']];
 $request=['clave'=>'http-solicitud-'.$suffix,'origen_id'=>'1001','destino_id'=>'1003','lineas'=>$lineas,'motivo'=>'Reposición HTTP '.$suffix];
 $r=$ops['panaderia']->operation($bases['panaderia'],'transferencia_solicitar',$request,'transferencias');$assert($confirmed($r),'Destino solicita sin mover stock.');
 $x=ClienteInstalacion3E::dom($r['body']);$node=$x->query('//details[contains(@class,"almacen-transfer-document")][.//li[contains(.,"'.$suffix.'")]]')->item(0);$assert($node!==null,'Documento nuevo conserva motivo e historial.');
 $id=$x->query('.//input[@name="transferencia_id"]',$node)->item(0)->getAttribute('value');
 $getDoc=static function(string $ctx)use($ops,$bases,$id,$assert):array{$r=$ops[$ctx]->request('GET',$bases[$ctx].'?vista=transferencias');$x=ClienteInstalacion3E::dom($r['body']);$node=$x->query('//details[contains(@class,"almacen-transfer-document")][.//strong[normalize-space(.)="Transferencia #'.$id.'"]]')->item(0);$assert($node!==null,'Documento visible en '.$ctx);return [$r,$x,$node];};
 [$r,$x,$node]=$getDoc('global');$f=$x->query('.//form[.//input[@name="accion" and @value="transferencia_autorizar"]]',$node)->item(0);$assert($f!==null,'Origen ofrece autorización.');
 $version='0';$step=0;
 $act=static function(string $ctx,string $accion,array $extra=[])use($ops,$bases,$id,&$version,&$step,$suffix):array{return $ops[$ctx]->operation($bases[$ctx],'transferencia_'.$accion,['clave'=>'http-'.$accion.'-'.$suffix.'-'.++$step,'transferencia_id'=>$id,'version'=>$version,'motivo'=>'Confirmación HTTP de '.$accion]+$extra,'transferencias');};
 $assert(!$confirmed($act('panaderia','autorizar')),'Destino no autoriza origen extranjero.');
 $assert($confirmed($act('global','autorizar')),'Origen autoriza salida.');$version='1';
 $assert($confirmed($act('global','enviar')),'Origen confirma envío.');$version='2';
 [$r,$x,$node]=$getDoc('panaderia');$assert(str_contains($node->textContent,'En tránsito'),'Solicitud enviada muestra tránsito.');
 $assert($x->query('.//form[.//input[@value="transferencia_enviar"]]',$node)->length===0,'Destino no tiene acción de envío.');
 $assert($x->query('.//form[.//input[@value="transferencia_recibir"]]',$node)->length===1,'Destino tiene recepción.');
 $assert(!$confirmed($act('global','recibir',['lineas'=>$lineas])),'Global no recibe por Panadería.');
 $partial=[['articulo_id'=>'1001','cantidad'=>'7.001'],['articulo_id'=>'1002','cantidad'=>'10.250']];
 $assert($confirmed($act('panaderia','recibir',['lineas'=>$partial])),'Recepción parcial confirmada.');$version='3';
 [$r,$x,$node]=$getDoc('panaderia');$assert(str_contains($node->textContent,'Incidencia pendiente')&&str_contains($node->textContent,'5.124'),'Diferencia conserva tránsito exacto.');
 $version='2';$assert(!$confirmed($act('panaderia','recibir',['lineas'=>$partial])),'Versión anterior no duplica recepción.');$version='3';
 $assert($confirmed($act('global','retornar',['lineas'=>[['articulo_id'=>'1001','cantidad'=>'5.124']]])),'Origen recibe retorno físico.');$version='4';
 [$r,$x,$node]=$getDoc('global');$assert(str_contains($node->textContent,'Cerrada con retorno'),'Cierre distingue retorno y recepción total.');$assert($x->query('.//form',$node)->length===0,'Documento cerrado carece de acciones físicas.');
 // Replay de solicitud conserva un documento y el estado actual, sin volver a enviarlo.
 $r=$ops['panaderia']->operation($bases['panaderia'],'transferencia_solicitar',$request,'transferencias');$assert($confirmed($r),'Solicitud repetida acepta replay.');$x=ClienteInstalacion3E::dom($r['body']);$assert($x->query('//strong[normalize-space(.)="Transferencia #'.$id.'"]')->length===1,'Replay conserva una sola cabecera.');
 $bad=$request;$bad['clave']='http-ajeno-'.$suffix;$bad['origen_id']='999999';$assert(!$confirmed($ops['panaderia']->operation($bases['panaderia'],'transferencia_solicitar',$bad,'transferencias')),'ID inexistente rechazado.');
 $bad=$request;$bad['clave']='http-mismo-'.$suffix;$bad['destino_id']='1001';$assert(!$confirmed($ops['global']->operation($bases['global'],'transferencia_solicitar',$bad,'transferencias')),'Mismo almacén rechazado por servidor.');
 $raw=$ops['global']->request('POST',$bases['global'].'/operacion',['accion'=>'transferencia_solicitar']+$request);$assert($raw['status']===403,'CSRF requerido en transferencia.');
 $ro=new ClienteInstalacion3E();$clients[]=$ro;$ro->login($fixture['cuentas']['lectura_panaderia']);$r=$ro->request('GET',$bases['panaderia'].'?vista=transferencias');$x=ClienteInstalacion3E::dom($r['body']);$assert($valid($r)&&$x->query('//input[@name="accion" and starts-with(@value,"transferencia_")]')->length===0,'Solo lectura conserva historial sin formularios de transferencia.');$assert($ro->request('POST',$bases['panaderia'].'/operacion',['accion'=>'transferencia_solicitar']+$request)['status']===403,'POST manipulado no concede edición.');
 $assert($ops['restaurante']->request('GET',$bases['panaderia'].'?vista=transferencias')['status']===403,'Contexto ajeno rechazado por HTTP.');
 $assert($ops['global']->request('GET',$bases['global'].'?vista=transferencias&transferencia_pagina[]=1')['status']===422,'Paginación malformada rechazada.');
 echo "Transferencias HTTP M0.5-B: {$count} comprobaciones OK.\n";
}finally{foreach($clients as $c)$c->close();}
