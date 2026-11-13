<?php
require __DIR__.'/informes-db-bootstrap.php';
use Services\Pos\HistorialTickets as T;
use Services\Finance\FinanzasNegocio as F;
use Services\Analytics\AnalisisNegocio as A;
use Services\Shared\RangoPeriodo as R;
use Services\Shared\GeneradorPdf as G;
$db=pdfTestDb();$ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$u=(int)$ids['units']['restaurante'];
$r=R::resolverInforme(['desde'=>'2001-01-15','hasta'=>'2001-01-17']);$before=pdfTestSnapshot($db);
$source=$db->execute_query("SELECT COUNT(*) tickets_cerrados,COALESCE(SUM(x.venta),0) ventas FROM tickets t LEFT JOIN (SELECT ticket_id,unidad_id,SUM(precio*cantidad) venta FROM ticket_items WHERE estado<>'cancelado' GROUP BY ticket_id,unidad_id) x ON x.ticket_id=t.id AND x.unidad_id=t.unidad_id WHERE t.unidad_id=$u AND t.estado='cerrado' AND COALESCE(t.hora_cierre,t.hora_apertura)>='2001-01-15 00:00:00' AND COALESCE(t.hora_cierre,t.hora_apertura)<='2001-01-17 23:59:59'")->fetch_assoc();
$source['tickets_cancelados']=$db->execute_query("SELECT COUNT(*) FROM tickets WHERE unidad_id=$u AND estado='cancelado' AND COALESCE(hora_cierre,hora_apertura)>='2001-01-15 00:00:00' AND COALESCE(hora_cierre,hora_apertura)<='2001-01-17 23:59:59'")->fetch_row()[0];$source['promedio']=$source['tickets_cerrados']?(float)$source['ventas']/(int)$source['tickets_cerrados']:null;
foreach(['tickets_cerrados'=>3,'tickets_cancelados'=>1,'ventas'=>125]as$key=>$expected)pdfTestNear($source[$key],$expected,'TIC SQL origen '.$key);pdfTestNear($source['promedio'],125/3,'TIC SQL origen ticket promedio');
file_put_contents(pdfTestTemp().'/source-query-tickets.json',json_encode(['metodo'=>'COUNT tickets cerrados y cancelados por fecha de cierre/apertura; suma partidas agrupadas por ticket, excluye partidas canceladas; propinas fuera de ventas','desde'=>'2001-01-15','hasta'=>'2001-01-17','actual'=>$source],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
$doc=T::informe($db,$u,$r,'2026-11-07 12:00:00');$k=array_column($doc['kpis'],'valor','etiqueta');
foreach(['Tickets cerrados'=>3,'Tickets cancelados'=>1,'Ventas'=>125,'Ticket promedio'=>125/3]as$key=>$expected)pdfTestNear($k[$key],$expected,'TIC '.$key);
$f=F::agregados($db,$u,$r['start'],$r['end']);$a=A::totales($db,$u,$r['start'],$r['end']);pdfTestNear($k['Ventas'],$f['ingresos'],'TIC FIN paridad');pdfTestNear($k['Ventas'],$a['ventas'],'TIC ANA paridad');
pdfTestAssert($doc['secciones'][1]['filas']===[['2001-01-15',115.0],['2001-01-16',10.0],['2001-01-17',0.0]],'Serie diaria y cero');
$json=json_encode($doc,JSON_THROW_ON_ERROR);pdfTestAssert(!str_contains($json,'CLIENTE_PRIVADO_900')&&!str_contains($json,'Empleado privado')&&!str_contains($json,'comensal'),'PII');
$empty=T::informe($db,$u,R::resolverInforme(['desde'=>'1990-01-01','hasta'=>'1990-01-02']),'2026-11-07 12:00:00');$e=array_column($empty['kpis'],'valor');pdfTestAssert($e===[0,0,0.0,null,0,0] && $empty['secciones'][2]['filas'][0][3]===null,'Vacio honesto');
$year=R::resolverInforme(['desde'=>'2000-02-01','hasta'=>'2001-01-31']);$large=T::informe($db,$u,$year,'2026-11-07 12:00:00');
// El rango anual incluye los $10 del ticket previo del 14/01, además de los $125 del rango corto.
$totalAnual=array_sum(array_column($large['secciones'][1]['filas'],1));
pdfTestAssert(count($large['secciones'][1]['filas'])===12 && abs($totalAnual-135)<0.01,'TIC agrupación mensual');
pdfTestNear($totalAnual,F::agregados($db,$u,$year['start'],$year['end'])['ingresos'],'Paridad serie anual TIC');
$pdf=G::documento($doc);file_put_contents(pdfTestTemp().'/tic-independent.pdf',$pdf->output());file_put_contents(pdfTestTemp().'/tic-documento.json',json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));pdfTestAssert($before===pdfTestSnapshot($db),'TIC muta datos');
echo 'TIC independiente PASS SQL-origen ventas='.$source['ventas'].' cerrados='.$source['tickets_cerrados'].' cancelados='.$source['tickets_cancelados'].' promedio='.round($source['promedio'],4).'; 4KPIs,paridadFINANA0,serie[115,10,0],abiertos/partidas canceladas/propina fuera,empty,366d,PIIfuera,hash12tablasigual; paginas='.$pdf->getCanvas()->get_page_count().PHP_EOL;
$db->close();
