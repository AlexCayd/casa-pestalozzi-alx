<?php
require __DIR__.'/informes-db-bootstrap.php';
use Services\Reservations\InformeReservaciones as R;
use Services\Shared\RangoPeriodo as P;
use Services\Shared\GeneradorPdf as G;
$db=pdfTestDb();$ids=json_decode(file_get_contents(pdfTestTemp().'/fixture-ids.json'),true);$u=(int)$ids['units']['restaurante'];$db->begin_transaction();
try{
    $add=static function(string$status,int$people,string$date='2001-01-15',?int$replaces=null,?int$unit=null)use($db,$u):int{$db->execute_query('INSERT INTO reservaciones(unidad_id,nombre,contacto_tipo,contacto,fecha,hora,comensales,nota,comentario_admin,motivo_cancelacion,origen,request_token,hold_expires_at,estado,reemplaza_reservacion_id,created_at,estado_changed_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$unit??$u,'CLIENTE_PRIVADO_RES_123','email','privado@example.test',$date,'13:00:00',$people,'NOTA_PRIVADA_RES','COMENTARIO_PRIVADO_RES','MOTIVO_PRIVADO_RES','admin',bin2hex(random_bytes(16)),'2001-01-15 12:00:00',$status,$replaces,'2026-11-06 12:00:00','2026-11-06 12:00:00']);return(int)$db->insert_id;};
    foreach(['confirmada'=>2,'en_curso'=>3,'completada'=>4,'no_show'=>5,'cancelada'=>6]as$status=>$people)$add($status,$people);
    $add('completada',2,'2001-01-16');foreach(['pendiente_verificacion','expirada']as$status)$add($status,99);
    $old=$add('reemplazada',99);$add('confirmada',7,'2001-01-16',$old);$add('completada',99,'2001-01-15',null,(int)$ids['units']['panaderia']);
    $add('completada',9,'2001-01-14');$add('completada',9,'2001-01-18');
    $now=new DateTimeImmutable('2026-11-06 12:00:00',new DateTimeZone('America/Mexico_City'));$p=P::resolverInforme(['desde'=>'2001-01-15','hasta'=>'2001-01-17']);$before=pdfTestSnapshot($db);$doc=R::documento($db,$u,$p,$now);$k=array_column($doc['kpis'],'valor','etiqueta');
    foreach(['Reservaciones de negocio'=>7,'Comensales programados'=>29,'Atendidas'=>2,'No llegaron registrados'=>1,'Canceladas'=>1,'Pendientes de cierre'=>3]as$key=>$expected)pdfTestNear($k[$key],$expected,$key);
    pdfTestAssert($doc['secciones'][1]['filas']===[['2001-01-15',5],['2001-01-16',2],['2001-01-17',0]],'Serie diaria/ceros/exclusiones');pdfTestAssert($doc['secciones'][2]['filas'][0]===['2001-01-15',5],'Demanda');
    pdfTestAssert(str_contains($doc['resumen'],'pendientes de cierre')&&!str_contains($doc['titulo'],'Provisional'),'Historico pendiente sin inferencia');
    $today=R::documento($db,$u,P::resolverInforme(['desde'=>'2026-11-06','hasta'=>'2026-11-06']),$now);$future=R::documento($db,$u,P::resolverInforme(['desde'=>'2099-01-01','hasta'=>'2099-01-03']),$now);
    foreach([$today,$future]as$d){pdfTestAssert(str_contains($d['titulo'],'Provisional'),'Provisional hoy/futuro');pdfTestAssert(!in_array('porcentaje',array_column($d['kpis'],'formato'),true),'Tasa final provisional');}
    $json=json_encode($doc,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);foreach(['CLIENTE_PRIVADO','NOTA_PRIVADA','COMENTARIO_PRIVADO','MOTIVO_PRIVADO','privado@example']as$marker)pdfTestAssert(!str_contains($json,$marker),'PII '.$marker);
    $pdf=G::documento($doc);file_put_contents(pdfTestTemp().'/res-independent.pdf',$pdf->output());$empty=R::documento($db,$u,P::resolverInforme(['desde'=>'1990-01-01','hasta'=>'1990-01-02']),$now);pdfTestNear($empty['kpis'][0]['valor'],0,'RES vacio');
    pdfTestAssert($before===pdfTestSnapshot($db),'RES muta datos/historia/notificaciones');echo 'RES independiente PASS 6KPIs,7reservas29programados,3pendientes,no_show1,tecnicos3/reemplazo/unidad excluidos,fecha_programada,ceros,provisional,historia/PII/sololectura; pages='.$pdf->getCanvas()->get_page_count().PHP_EOL;
}finally{$db->rollback();$db->close();}
