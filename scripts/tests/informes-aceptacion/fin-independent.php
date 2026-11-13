<?php
require __DIR__.'/informes-db-bootstrap.php';
$db=pdfTestDb();$ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$u=(int)$ids['units']['restaurante'];
$r=Services\Shared\RangoPeriodo::resolverInforme(['desde'=>'2001-01-15','hasta'=>'2001-01-17']);
$before=pdfTestSnapshot($db);$s=Services\Finance\FinanzasNegocio::resumen($db,'restaurante',$u,$r);$doc=Services\Finance\FinanzasNegocio::informe($db,$u,$r,'2026-11-06 12:00:00');$k=array_column($doc['kpis'],'valor','etiqueta');
foreach(['Ingresos por ventas'=>125,'Tickets cerrados'=>3,'Ticket promedio'=>125/3,'Costo de insumos'=>24,'Utilidad bruta'=>91,'Utilidad neta'=>61]as$label=>$expected)pdfTestNear($k[$label],$expected,$label);
foreach(['ingresos'=>125,'cogs'=>24,'sinCosto'=>25,'propinas'=>20,'merma'=>10,'gastos'=>30]as$key=>$expected)pdfTestNear($s[$key],$expected,$key);
pdfTestNear($s['previo']['ingresos'],10,'Anterior inmediato');
foreach(['Ingresos por ventas'=>'ingresos','Tickets cerrados'=>'tickets','Ticket promedio'=>'ticketPromedio','Costo de insumos'=>'cogs','Utilidad bruta'=>'utilidadBruta','Utilidad neta'=>'utilidadNeta']as$label=>$key)pdfTestNear($k[$label],$s[$key],'Paridad '.$key);
$empty=Services\Finance\FinanzasNegocio::informe($db,$u,Services\Shared\RangoPeriodo::resolverInforme(['desde'=>'1990-01-01','hasta'=>'1990-01-02']),'2026-11-06 12:00:00');pdfTestNear($empty['kpis'][0]['valor'],0,'Vacio ingresos');pdfTestAssert($empty['kpis'][0]['comparacion']===null&&$empty['kpis'][2]['valor']===null,'Vacio no comparable');
$start=microtime(true);$pdf=Services\Shared\GeneradorPdf::documento($doc);file_put_contents(pdfTestTemp().'/fin-independent.pdf',$pdf->output());pdfTestAssert(str_starts_with($pdf->output(),'%PDF-'),'FIN PDF invalido');pdfTestAssert(!str_contains(json_encode($doc),'CLIENTE_PRIVADO_900'),'PII filtrada');pdfTestAssert($before===pdfTestSnapshot($db),'Generacion muta datos');
echo 'FIN independiente PASS paridad6KPIs <=0.01, costoNULL/cero/cancelados/propinas/dividido/unidad/fallback/empty/readonly; paginas='.$pdf->getCanvas()->get_page_count().', segundos='.round(microtime(true)-$start,3).PHP_EOL;
