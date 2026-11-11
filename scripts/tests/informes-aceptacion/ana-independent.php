<?php
require __DIR__.'/informes-db-bootstrap.php';
use Services\Analytics\AnalisisNegocio as A;
use Services\Finance\FinanzasNegocio as F;
use Services\Shared\RangoPeriodo as R;
use Services\Shared\GeneradorPdf as G;
$db=pdfTestDb();$ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$u=(int)$ids['units']['restaurante'];$db->begin_transaction();
try{
    $o=$db->execute_query('SELECT o.*,c.nombre categoria FROM ofertas_producto o JOIN categorias c ON c.id=o.categoria_id WHERE o.unidad_id=? ORDER BY o.id LIMIT 1',[$u])->fetch_assoc();
    $db->execute_query('INSERT INTO tickets(unidad_id,estado,nombre,comensales,hora_apertura,hora_cierre,propina)VALUES(?,?,?,?,?,?,?)',[$u,'cerrado','CLIENTE_PRIVADO_ANA',5,'2002-01-15 00:00:00','2002-01-15 23:59:59',50]);$tid=(int)$db->insert_id;
    $add=static function(int$t,string$name,int$qty,float$price=10,string$state='entregado')use($db,$u,$o):void{$db->execute_query('INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,cantidad,costo_unitario,estado,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$u,$t,$o['id'],$o['producto_id'],$name,$price,$o['categoria'],$o['categoria_id'],$o['area_id'],$qty,2,$state,'2002-01-15 11:00:00']);};
    for($i=1;$i<=9;$i++)$add($tid,'Snapshot '.$i.' café & <b>histórico</b>',$i);
    $add($tid,'Cancelado ANA',100,99,'cancelado');
    $db->execute_query('INSERT INTO tickets(unidad_id,estado,comensales,hora_apertura,hora_cierre)VALUES(?,?,?,?,?)',[$u,'cerrado',2,'2002-01-16 12:00:00','2002-01-16 12:00:00']);
    $db->execute_query('INSERT INTO tickets(unidad_id,estado,comensales,hora_apertura,hora_cierre)VALUES(?,?,?,?,?)',[$u,'cerrado',1,'2002-01-14 12:00:00','2002-01-14 12:00:00']);$add((int)$db->insert_id,'Previo',1,100);
    $db->execute_query('INSERT INTO tickets(unidad_id,estado,comensales,hora_apertura,hora_cierre)VALUES(?,?,?,?,?)',[$ids['units']['panaderia'],'cerrado',99,'2002-01-15 12:00:00','2002-01-15 12:00:00']);
    $r=R::resolverInforme(['desde'=>'2002-01-15','hasta'=>'2002-01-17']);$before=pdfTestSnapshot($db);$t=A::totales($db,$u,$r['start'],$r['end']);
    foreach(['ventas'=>450,'tickets'=>2,'comensales'=>7,'piezas'=>45]as$key=>$expected)pdfTestNear($t[$key],$expected,'Autoridad ANA '.$key);
    $doc=A::informe($db,$u,$r,'2026-11-06 12:00:00');$k=array_column($doc['kpis'],'valor','etiqueta');
    file_put_contents(pdfTestTemp().'/ana-documento.json',json_encode($doc,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $values=array_column($doc['kpis'],'valor');foreach([450,2,225,45,7]as$i=>$v)pdfTestNear($values[$i],$v,'ANA KPI'.$i);
    pdfTestAssert($k['Con consumo']===1 && $k['Sin consumo']===1,'ANA consumo');
    pdfTestNear(F::agregados($db,$u,$r['start'],$r['end'])['ingresos'],$t['ventas'],'Paridad FIN/ANA');
    $series=A::ventasPorDia($db,$u,$r['start'],$r['end']);pdfTestAssert($series===['2002-01-15'=>450.0,'2002-01-16'=>0.0,'2002-01-17'=>0.0],'Serie ceros/orden');
    $top=A::masVendidos($db,$u,$r['start'],$r['end'],7);pdfTestAssert(count($top)===7&&$top[0]['piezas']===9&&$top[6]['piezas']===3&&str_contains($top[0]['nombre'],'<b>'),'Top7 snapshot');
    pdfTestAssert($doc['secciones'][1]['filas'][0][0]===$top[0]['nombre']&&count($doc['secciones'][1]['filas'])===7,'Documento Top7');
    $empty=A::informe($db,$u,R::resolverInforme(['desde'=>'1990-01-01','hasta'=>'1990-01-02']),'2026-11-06 12:00:00');pdfTestAssert($empty['kpis'][0]['valor']===0.0&&$empty['kpis'][0]['comparacion']===null&&$empty['kpis'][2]['valor']===null,'ANA empty/null');
    $year=R::resolverInforme(['desde'=>'2002-01-01','hasta'=>'2003-01-01']);$start=microtime(true);$large=A::informe($db,$u,$year,'2026-11-06 12:00:00');$p=G::documento($large);file_put_contents(pdfTestTemp().'/ana-366.pdf',$p->output());
    pdfTestAssert(count(A::ventasPorDia($db,$u,$year['start'],$year['end']))===366,'Serie366 incompleta');
    pdfTestAssert(count($large['secciones'][0]['filas'])===13 && abs(array_sum(array_column($large['secciones'][0]['filas'],1))-450)<0.01,'Agrupación mensual ANA');
    $pdf=G::documento($doc);file_put_contents(pdfTestTemp().'/ana-independent.pdf',$pdf->output());pdfTestAssert($before===pdfTestSnapshot($db),'ANA mutaBD');
    echo 'ANA independiente PASS5KPIs/top7de9/snapshot/cancelados/ceros/prev/FINDIF0/empty/sololectura; 366dias pages='.$p->getCanvas()->get_page_count().' elapsed='.round(microtime(true)-$start,3).' peakMB='.round(memory_get_peak_usage(true)/1048576,1).PHP_EOL;
}finally{$db->rollback();$db->close();}
