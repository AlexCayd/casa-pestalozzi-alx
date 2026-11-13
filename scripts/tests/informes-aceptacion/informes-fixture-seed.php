<?php
require __DIR__.'/informes-db-bootstrap.php';
$db=pdfTestDb();
pdfTestAssert((int)$db->query('SELECT COUNT(*) FROM tickets')->fetch_row()[0]===0,'Fixture ya sembrada');
$u=array_column($db->query('SELECT id,slug FROM unidades')->fetch_all(MYSQLI_ASSOC),'id','slug');
$o=$db->execute_query('SELECT o.*,c.nombre categoria FROM ofertas_producto o JOIN categorias c ON c.id=o.categoria_id WHERE o.unidad_id=? ORDER BY o.id LIMIT 1',[$u['restaurante']])->fetch_assoc();
$meta=json_decode(file_get_contents(pdfTestTemp().'/fixture.json'),true);
$users=[];
foreach(['reader'=>['restaurante',['finanzas','analytics','reservations','inventario','tickets']],'wrong'=>['restaurante',['menu']],'bakery'=>['panaderia',['finanzas','analytics','inventario','tickets']],'global'=>['global',['finanzas','analytics','inventario']]]as$name=>[$ctx,$modules]){
    $db->execute_query('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin)VALUES(?,?,?,1,0)',['pdf_'.$name,'Empleado privado '.$name,password_hash($meta['password'],PASSWORD_DEFAULT)]);$id=(int)$db->insert_id;$users[$name]=$id;
    $db->execute_query('INSERT INTO usuario_unidades(usuario_id,unidad_id,activo)VALUES(?,?,1)',[$id,$u[$ctx]]);
    foreach($modules as$m)$db->execute_query('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel)VALUES(?,?,?,?)',[$id,$u[$ctx],$m,'ver']);
}
$ticket=static function(string $estado,string $date,int $comensales,float $propina=0,string $pago='efectivo',?int $unit=null,bool $fallback=false)use($db,$u):int{
    $db->execute_query('INSERT INTO tickets(unidad_id,estado,nombre,comensales,hora_apertura,hora_cierre,propina,metodo_pago)VALUES(?,?,?,?,?,?,?,?)',[$unit??$u['restaurante'],$estado,'CLIENTE_PRIVADO_900', $comensales,$date,$fallback?null:$date,$propina,$pago]);return(int)$db->insert_id;
};
$item=static function(int $tid,string $name,float $price,int $qty,?float $cost,string $state='entregado')use($db,$u,$o):void{
    $db->execute_query('INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,nombre,precio,categoria,categoria_id,area_id,cantidad,costo_unitario,estado,created_at)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)',[$u['restaurante'],$tid,$o['id'],$o['producto_id'],$name,$price,$o['categoria'],$o['categoria_id'],$o['area_id'],$qty,$cost,$state,'2001-01-15 12:00:00']);
};
$t1=$ticket('cerrado','2001-01-15 00:00:00',2);$item($t1,'Café snapshot <b>histórico</b> & A',40,2,12);$item($t1,'Cancelado privado',999,1,10,'cancelado');
$t2=$ticket('cerrado','2001-01-15 23:59:59',3,15,'dividido');$item($t2,'Sin costo',25,1,null);$item($t2,'Costo cero conocido',10,1,0);
$db->execute_query('INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto)VALUES(?,?,1,?,?)',[$u['restaurante'],$t2,'efectivo',20]);$db->execute_query('INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto)VALUES(?,?,2,?,?)',[$u['restaurante'],$t2,'tarjeta',30]);
$t3=$ticket('cerrado','2001-01-16 12:00:00',4,5,'efectivo',null,true);$item($t3,'Costo cero conocido',10,1,0);
$tp=$ticket('cerrado','2001-01-14 12:00:00',1);$item($tp,'Venta anterior',10,1,2);
$tc=$ticket('cancelado','2001-01-16 12:00:00',9);$item($tc,'Ticket cancelado',900,1,2);
$ta=$ticket('abierto','1999-01-01 12:00:00',9);$item($ta,'Abierto actual',50,1,2);
$ticket('cerrado','2001-01-16 12:00:00',99,999,'efectivo',(int)$u['panaderia']);
$ticket('cerrado','2001-01-18 00:00:00',10);
$db->execute_query('INSERT INTO gastos_fijos(unidad_id,nombre,monto,categoria)VALUES(?,?,?,?)',[$u['restaurante'],'Mensual sin fecha',300,'renta']);$db->execute_query('INSERT INTO gastos_fijos(unidad_id,nombre,monto,categoria)VALUES(?,?,?,?)',[$u['panaderia'],'Ajeno',900,'renta']);
$db->execute_query('INSERT INTO ingredientes(nombre,unidad,costo)VALUES(?,?,?)',['PDF artículo de prueba','kg',9]);$art=(int)$db->insert_id;
$db->execute_query('INSERT INTO almacenes(unidad_id,nombre,permitir_negativos)VALUES(?,?,1)',[$u['restaurante'],'PDF Almacén1']);$warehouse=(int)$db->insert_id;
$db->execute_query('INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo)VALUES(?,?,?,?,?)',[$warehouse,$art,$u['restaurante'],-2,1]);
$db->execute_query('INSERT INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia,fecha)VALUES(?,?,?,?,?,?,?,?,?)',[$u['restaurante'],'merma','automatizado',null,'fixture','pdf_seed',str_repeat('a',64),'pdf_seed','2001-01-16 11:00:00']);$op=(int)$db->insert_id;
foreach([[-2,5],[-1,null]]as[$delta,$cost])$db->execute_query('INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,costo_unitario,fecha)VALUES(?,?,?,?,?,?,?,?)',[$op,$u['restaurante'],$warehouse,$art,'merma',$delta,$cost,'2001-01-16 11:00:00']);
file_put_contents(pdfTestTemp().'/fixture-ids.json',json_encode(['units'=>$u,'users'=>$users,'articulo'=>$art,'warehouse'=>$warehouse,'op'=>$op],JSON_THROW_ON_ERROR));
echo 'PASS fixture FIN/ANA/TIC y actores sembrados en base propia.'.PHP_EOL;
