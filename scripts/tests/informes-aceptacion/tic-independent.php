<?php
require __DIR__.'/informes-db-bootstrap.php';
use Services\Pos\HistorialTickets as T;
use Services\Finance\FinanzasNegocio as F;
use Services\Analytics\AnalisisNegocio as A;
use Services\Shared\RangoPeriodo as R;
use Services\Shared\GeneradorPdf as G;
$db=pdfTestDb();$ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$u=(int)$ids['units']['restaurante'];
$r=R::resolverInforme(['desde'=>'2001-01-15','hasta'=>'2001-01-17']);$before=pdfTestSnapshot($db);
$doc=T::informe($db,$u,$r,'2026-11-07 12:00:00');$k=array_column($doc['kpis'],'valor','etiqueta');
foreach(['Tickets cerrados'=>3,'Tickets cancelados'=>1,'Ventas'=>125,'Ticket promedio'=>125/3]as$key=>$expected)pdfTestNear($k[$key],$expected,'TIC '.$key);
$f=F::agregados($db,$u,$r['start'],$r['end']);$a=A::totales($db,$u,$r['start'],$r['end']);pdfTestNear($k['Ventas'],$f['ingresos'],'TIC FIN paridad');pdfTestNear($k['Ventas'],$a['ventas'],'TIC ANA paridad');
pdfTestAssert($doc['secciones'][1]['filas']===[['2001-01-15',115.0],['2001-01-16',10.0],['2001-01-17',0.0]],'Serie diaria y cero');
$json=json_encode($doc,JSON_THROW_ON_ERROR);pdfTestAssert(!str_contains($json,'CLIENTE_PRIVADO_900')&&!str_contains($json,'Empleado privado')&&!str_contains($json,'comensal'),'PII');
$empty=T::informe($db,$u,R::resolverInforme(['desde'=>'1990-01-01','hasta'=>'1990-01-02']),'2026-11-07 12:00:00');$e=array_column($empty['kpis'],'valor');pdfTestAssert($e===[0,0,0.0,null],'Vacio honesto');
$year=R::resolverInforme(['desde'=>'2000-02-01','hasta'=>'2001-01-31']);$large=T::informe($db,$u,$year,'2026-11-07 12:00:00');pdfTestAssert(count($large['secciones'][1]['filas'])===366,'TIC 366 dias');
$pdf=G::documento($doc);file_put_contents(pdfTestTemp().'/tic-independent.pdf',$pdf->output());file_put_contents(pdfTestTemp().'/tic-documento.json',json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));pdfTestAssert($before===pdfTestSnapshot($db),'TIC muta datos');
echo 'TIC independiente PASS:4KPIs,venta125,cerrados3,cancelados1,promedio41.6667,paridadFINANA0,serie[115,10,0],abiertos/cancelados/propinafuera,empty,366d,PIIfuera,hash12tablasigual; paginas='.$pdf->getCanvas()->get_page_count().PHP_EOL;
$db->close();
