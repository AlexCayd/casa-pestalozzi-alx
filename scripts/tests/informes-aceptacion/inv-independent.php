<?php
require __DIR__.'/informes-db-bootstrap.php';
use Services\Inventory\ConsultasAlmacen as C;
use Services\Inventory\AlcanceInventario as A;
use Services\Shared\RangoPeriodo as P;
use Services\Shared\GeneradorPdf as G;
$db=pdfTestDb();$ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$u=(int)$ids['units']['restaurante'];$db->begin_transaction();
try{
    $warehouse=static function(string$name,int$unit,bool$active=true)use($db):int{$db->execute_query('INSERT INTO almacenes(unidad_id,nombre,permitir_negativos,activo)VALUES(?,?,1,?)',[$unit,$name,(int)$active]);return(int)$db->insert_id;};
    $w2=$warehouse('Almacén segundo & <b>físico</b>',$u);$inactive=$warehouse('Almacén inactivo',$u,false);$foreign=$warehouse('Almacén AJENO',(int)$ids['units']['panaderia']);
    $art=static function(string$name,string$unit='kg',bool$active=true)use($db):int{$db->execute_query('INSERT INTO ingredientes(nombre,unidad,costo,activo)VALUES(?,?,9,?)',[$name,$unit,(int)$active]);return(int)$db->insert_id;};
    $associate=static function(int$w,int$a,float$stock,float$min=10,bool$active=true,?int$owner=null)use($db,$u):void{$db->execute_query('INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo)VALUES(?,?,?,?,?,?)',[$w,$a,$owner??$u,$stock,$min,(int)$active]);};
    $articles=[];for($i=1;$i<=26;$i++){$a=$art('Inventario fixture '.$i,$i%2?'kg':'pza');$articles[]=$a;$associate($w2,$a,$i%3-1.125);}
    $associate($w2,(int)$ids['articulo'],4,1);$associate($w2,$art('Asociación inactiva'),1,10,false);$associate($w2,$art('Artículo inactivo','kg',false),1);$associate($inactive,$art('Almacén inactivo artículo'),1);$associate($foreign,(int)$ids['articulo'],999,1,true,(int)$ids['units']['panaderia']);
    $move=static function(string$type,float$delta,?float$cost,string$date='2001-01-16 11:00:00',?int$w=null,?int$owner=null)use($db,$u,$w2,$articles):void{$owner??=$u;$w??=$w2;$key=bin2hex(random_bytes(8));$db->execute_query('INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,origen,clave_idempotencia,hash_solicitud,referencia,fecha)VALUES(?,?,\'automatizado\',?,?,?,?,?)',[$owner,$type,'fixture',$key,str_repeat('a',64),$key,$date]);$op=(int)$db->insert_id;$db->execute_query('INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,fecha)VALUES(?,?,?,?,?,?,?,?)',[$op,$owner,$w,$w===$w2?$articles[0]:$GLOBALS['ids']['articulo'],$type,$delta,$cost,$date]);};
    foreach(['entrada'=>10,'venta'=>-2,'salida'=>-1,'merma'=>-3,'ajuste'=>-.125,'cancelacion'=>2,'transferencia_salida'=>-1,'transferencia_entrada'=>1]as$type=>$delta)$move($type,$delta,$type==='merma'?0:($type==='ajuste'?null:2));
    $move('entrada',50,2,'2001-01-18 00:00:00');$move('merma',-100,999,'2001-01-16 12:00:00',$foreign,(int)$ids['units']['panaderia']);
    // Movimiento histórico de un almacén desactivado: no desaparece del periodo.
    $key='inactive-history';$db->execute_query('INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,origen,clave_idempotencia,hash_solicitud,referencia,fecha)VALUES(?,?,\'automatizado\',?,?,?,?,?)',[$u,'entrada','fixture',$key,str_repeat('b',64),$key,'2001-01-16 12:00:00']);$op=(int)$db->insert_id;
    $ia=(int)$db->execute_query('SELECT articulo_id FROM almacen_articulos WHERE almacen_id=?',[$inactive])->fetch_row()[0];$db->execute_query('INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,fecha)VALUES(?,?,?,?,?,?,?,?)',[$op,$u,$inactive,$ia,'entrada',1,2,'2001-01-16 12:00:00']);
    $r=P::resolverInforme(['desde'=>'2001-01-15','hasta'=>'2001-01-17']);$before=pdfTestSnapshot($db);$svc=new C($db,new A($db,(int)$ids['users']['reader'],'contrasena'));$doc=$svc->informe('restaurante',$r,'2026-11-06 12:00:00');
    file_put_contents(pdfTestTemp().'/inv-documento.json',json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    $k=array_column($doc['kpis'],'valor','etiqueta');foreach(['Relaciones físicas activas'=>28,'Relaciones bajo mínimo'=>27,'Almacenes activos'=>2,'Movimientos del periodo'=>11,'Artículos distintos activos'=>27,'Merma con costo conocido'=>10]as$key=>$expected)pdfTestNear($k[$key],$expected,'INV '.$key);
    pdfTestAssert(count($doc['secciones'][2]['filas'])===10,'Top alertas no acotado a10');$allBalances=array_column($doc['secciones'][2]['filas'],3);pdfTestAssert(min($allBalances)<0&&count(array_filter($allBalances,static fn($v)=>abs($v-round($v,2))>.0001))>0,'Negativos/milésimas perdidos');
    $types=array_column($doc['secciones'][0]['filas'],1,0);ksort($types);pdfTestAssert($types===['ajuste'=>1,'cancelacion'=>1,'entrada'=>2,'merma'=>3,'salida'=>1,'transferencia_entrada'=>1,'transferencia_salida'=>1,'venta'=>1],'Resumen tipos');pdfTestAssert(str_contains(implode(' ',$doc['notas']),'1 movimientos de merma sin costo conocido'),'Costo desconocido no informado');
    $json=json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);pdfTestAssert(!str_contains($json,'AJENO')&&str_contains(mb_strtolower($json),'fotograf'),'Ámbito/foto');
    $pdf=G::documento($doc);file_put_contents(pdfTestTemp().'/inv-independent.pdf',$pdf->output());pdfTestAssert($before===pdfTestSnapshot($db),'INV muta saldos/movimientos');
    echo 'INV independiente PASS: 28 relaciones activas,27 bajo mínimo,2 almacenes activos,11 movimientos de periodo incluye inactivo,unidades kg/pza,negativos/milésimas,sololectura; páginas='.$pdf->getCanvas()->get_page_count().PHP_EOL;
}finally{$db->rollback();$db->close();}
