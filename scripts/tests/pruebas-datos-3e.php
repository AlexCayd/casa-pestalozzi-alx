<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/datos/verificar-menu.php';

/** Helper de solo lectura para los ensayos de instalación independiente 3E. */
function probarDatos3E(mysqli $db,string $root,int &$count): array
{
    $menu=MenuVersionado3E::base($db,$root);$count+=(int)$menu['comprobaciones'];
    $assert=static function(bool $condition,string $message) use(&$count): void {if(!$condition)throw new RuntimeException($message);$count++;};
    $assert((int)$db->query("SELECT COUNT(*) FROM esquema_versiones WHERE version='datos-desarrollo-m05b-v1'")->fetch_row()[0]===1,'Falta el marcador actualizado del DML de desarrollo.');
    $assert((string)$db->query("SELECT CONCAT(COUNT(*),':',SUM(reservable=1),':',SUM(CASE WHEN reservable=1 THEN capacidad ELSE 0 END)) FROM mesas WHERE unidad_id=2 AND id BETWEEN 1 AND 16")->fetch_row()[0]==='16:11:44','El mapa debe conservar 16 superficies, 11 mesas y 44 asientos reservables.');
    $assert((int)$db->query("SELECT COUNT(*) FROM impresoras WHERE unidad_id=2 AND id BETWEEN 101 AND 105 AND activo=0 AND host='127.0.0.1'")->fetch_row()[0]===5,'Las estaciones de desarrollo deben quedar locales e inactivas.');
    $assert((int)$db->query('SELECT COUNT(*) FROM historial_precios WHERE id BETWEEN 1001 AND 1005')->fetch_row()[0]===5,'El fixture base debe conservar historial de precios de ofertas y artículos.');
    $assert((string)$db->query("SELECT CONCAT(COUNT(DISTINCT v.id),':',SUM(a.accion='agregar'),':',SUM(a.accion='quitar'),':',SUM(a.accion='sustituir')) FROM producto_variantes v LEFT JOIN variante_ajustes a ON a.variante_id=v.id WHERE v.oferta_id IN(1001,1002)")->fetch_row()[0]==='5:2:1:1','Las variantes deben cubrir agregación, retiro, sustitución y precios.');
    $assert((string)$db->query("SELECT CONCAT(SUM(id IN(300,301,302) AND img IS NOT NULL),':',SUM(id IN(303,304) AND img IS NULL)) FROM productos")->fetch_row()[0]==='3:2','Los cinco especiales originales deben conservar tres fotos propias y dos fallbacks.');
    $assert((int)$db->query("SELECT COUNT(*) FROM ofertas_producto WHERE id=1004 AND activo=1 AND unidad_id=2")->fetch_row()[0]===1 && (int)$db->query("SELECT COUNT(*) FROM ofertas_producto WHERE id=1005 AND activo=0 AND unidad_id=2")->fetch_row()[0]===1,'Los escenarios temporales sintéticos deben cubrir oferta activa e inactiva.');
    $assert((int)$db->query("SELECT COUNT(*) FROM ofertas_producto o WHERE o.id BETWEEN 1010 AND 1017 AND o.unidad_id=2 AND o.activo=1 AND NOT EXISTS(SELECT 1 FROM ticket_items ti WHERE ti.oferta_id=o.id AND ti.unidad_id=o.unidad_id)")->fetch_row()[0]===8,'Deben existir ocho ofertas activas sin ventas para el Asistente local.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND id BETWEEN 10000 AND 19999 AND estado='cerrado'")->fetch_row()[0]>=2000,'El Asistente requiere diversidad de ventas en su periodo móvil.');
    $assert((int)$db->query("SELECT COUNT(DISTINCT DATE(hora_apertura)) FROM tickets WHERE unidad_id=2 AND id BETWEEN 10000 AND 19999")->fetch_row()[0]>=85 && (int)$db->query("SELECT COUNT(DISTINCT DAYOFWEEK(hora_apertura)) FROM tickets WHERE unidad_id=2 AND id BETWEEN 10000 AND 19999")->fetch_row()[0]===7,'El fixture del Asistente debe cubrir varios meses y los siete días.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t JOIN (SELECT ticket_id,unidad_id,SUM(precio*cantidad) total FROM ticket_items WHERE estado<>'cancelado' GROUP BY ticket_id,unidad_id) i ON i.ticket_id=t.id AND i.unidad_id=t.unidad_id JOIN (SELECT ticket_id,unidad_id,SUM(monto) total FROM ticket_pagos GROUP BY ticket_id,unidad_id) p ON p.ticket_id=t.id AND p.unidad_id=t.unidad_id WHERE t.unidad_id=2 AND t.id BETWEEN 10000 AND 19999 AND ABS(i.total-p.total)>0.001")->fetch_row()[0]===0,'Los pagos del Asistente deben coincidir con las partidas no canceladas.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t LEFT JOIN usuarios u ON u.id=t.mesero_id WHERE t.unidad_id=2 AND t.id BETWEEN 10000 AND 19999 AND t.mesero_id IS NOT NULL AND u.id IS NULL")->fetch_row()[0]===0,'Las ventas de reportes deben conservar sus referencias de mesero.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND id BETWEEN 4001 AND 4028 AND estado='cerrado'")->fetch_row()[0]===26,'RevPASH debe contener 26 servicios en la semana completa.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ticket_mesas WHERE unidad_id=2 AND ticket_id BETWEEN 4001 AND 4028 AND mesa_id BETWEEN 1 AND 11')->fetch_row()[0]===27,'Los 26 servicios semanales deben usar las mesas originales, con una ocupación que agrupa dos mesas.');
    $assert((int)$db->query('SELECT COUNT(*) FROM reservacion_mesas WHERE unidad_id=2 AND reservacion_id=4105')->fetch_row()[0]===2 && (int)$db->query('SELECT COUNT(*) FROM ticket_mesas WHERE unidad_id=2 AND ticket_id=4018')->fetch_row()[0]===2,'La reservación y el servicio semanal de grupo deben compartir dos mesas.');
    $assert((int)$db->query("SELECT COUNT(*) FROM reservaciones r JOIN tickets t ON t.reservacion_id=r.id AND t.unidad_id=r.unidad_id JOIN (SELECT rm.reservacion_id,COUNT(*) cantidad,SUM(m.capacidad) capacidad FROM reservacion_mesas rm JOIN mesas m ON m.id=rm.mesa_id AND m.unidad_id=rm.unidad_id GROUP BY rm.reservacion_id) x ON x.reservacion_id=r.id WHERE r.id=4105 AND t.id=4018 AND r.comensales=6 AND t.comensales=6 AND x.cantidad=2 AND x.capacidad=8 AND NOT EXISTS(SELECT 1 FROM reservacion_mesas rm WHERE rm.reservacion_id=r.id AND rm.unidad_id=r.unidad_id AND NOT EXISTS(SELECT 1 FROM ticket_mesas tm WHERE tm.ticket_id=t.id AND tm.unidad_id=t.unidad_id AND tm.mesa_id=rm.mesa_id)) AND NOT EXISTS(SELECT 1 FROM ticket_mesas tm WHERE tm.ticket_id=t.id AND tm.unidad_id=t.unidad_id AND NOT EXISTS(SELECT 1 FROM reservacion_mesas rm WHERE rm.reservacion_id=r.id AND rm.unidad_id=r.unidad_id AND rm.mesa_id=tm.mesa_id))")->fetch_row()[0]===1,'La ocupación de grupo debe coincidir exactamente con la reserva y su capacidad.');
    $assert((int)$db->query("SELECT COUNT(*) FROM (SELECT r.id,COUNT(rm.id) mesas,SUM(m.capacidad) capacidad,MAX(r.comensales) comensales FROM reservaciones r JOIN reservacion_mesas rm ON rm.reservacion_id=r.id AND rm.unidad_id=r.unidad_id JOIN mesas m ON m.id=rm.mesa_id AND m.unidad_id=rm.unidad_id WHERE r.id BETWEEN 1010 AND 1012 GROUP BY r.id HAVING (r.id=1010 AND mesas=2 AND capacidad=8 AND comensales=6) OR (r.id=1011 AND mesas=3 AND capacidad=12 AND comensales=10) OR (r.id=1012 AND mesas=4 AND capacidad=16 AND comensales=14)) grupos")->fetch_row()[0]===3,'Las reservas futuras deben cubrir grupos coherentes de dos, tres y cuatro mesas.');
    $assert((int)$db->query('SELECT COUNT(*) FROM reservaciones WHERE unidad_id=2 AND id BETWEEN 4101 AND 4107 AND estado=\'completada\'')->fetch_row()[0]===7,'La semana debe relacionar una reserva atendida por día.');
    $reservacionesEstado=[];
    foreach($db->query('SELECT estado,COUNT(*) cantidad FROM reservaciones WHERE unidad_id=2 GROUP BY estado ORDER BY estado')->fetch_all(MYSQLI_ASSOC) as $fila)$reservacionesEstado[$fila['estado']]=(int)$fila['cantidad'];
    foreach(['confirmada','completada','cancelada','no_show','expirada','pendiente_verificacion','reemplazada','en_curso'] as $estado)$assert(($reservacionesEstado[$estado]??0)>0,"El fixture debe cubrir el estado de reservación {$estado}.");
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t WHERE t.unidad_id=2 AND t.id BETWEEN 4001 AND 4028 AND ((DAYOFWEEK(t.hora_apertura)=2 AND TIME(t.hora_apertura)<'13:00:00') OR (DAYOFWEEK(t.hora_apertura)=1 AND (TIME(t.hora_apertura)<'08:30:00' OR TIME(t.hora_apertura)>='19:00:00')) OR (DAYOFWEEK(t.hora_apertura) BETWEEN 3 AND 7 AND (TIME(t.hora_apertura)<'08:30:00' OR TIME(t.hora_apertura)>='22:00:00')))")->fetch_row()[0]===0,'Los servicios semanales deben respetar horarios de operación.');
    $assert((int)$db->query("SELECT COUNT(*) FROM ticket_mesas a JOIN tickets ta ON ta.id=a.ticket_id AND ta.unidad_id=a.unidad_id JOIN ticket_mesas b ON b.unidad_id=a.unidad_id AND b.mesa_id=a.mesa_id AND b.ticket_id>a.ticket_id JOIN tickets tb ON tb.id=b.ticket_id AND tb.unidad_id=b.unidad_id WHERE a.unidad_id=2 AND a.ticket_id BETWEEN 4001 AND 4028 AND b.ticket_id BETWEEN 4001 AND 4028 AND ta.hora_apertura<tb.hora_cierre AND tb.hora_apertura<ta.hora_cierre")->fetch_row()[0]===0,'No debe haber dos ocupaciones semanales simultáneas de la misma mesa.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t JOIN (SELECT ticket_id,unidad_id,SUM(precio*cantidad) total FROM ticket_items WHERE estado<>'cancelado' GROUP BY ticket_id,unidad_id) i ON i.ticket_id=t.id AND i.unidad_id=t.unidad_id JOIN (SELECT ticket_id,unidad_id,SUM(monto) total FROM ticket_pagos GROUP BY ticket_id,unidad_id) p ON p.ticket_id=t.id AND p.unidad_id=t.unidad_id WHERE t.unidad_id=2 AND t.id BETWEEN 4001 AND 4028 AND ABS(i.total-p.total)>0.001")->fetch_row()[0]===0,'Los pagos de RevPASH deben cuadrar con las partidas entregadas.');
    $assert((int)$db->query("SELECT IF(AVG(CASE WHEN dow=7 THEN ventas END)>AVG(CASE WHEN dow=2 THEN ventas END),1,0) FROM (SELECT DATE(hora_apertura) dia,DAYOFWEEK(hora_apertura) dow,COUNT(*) ventas FROM tickets WHERE unidad_id=2 AND id BETWEEN 4001 AND 4028 GROUP BY DATE(hora_apertura),DAYOFWEEK(hora_apertura)) dias")->fetch_row()[0]===1,'El fixture semanal debe reflejar sábado fuerte y lunes débil.');
    $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos aa LEFT JOIN (SELECT almacen_id,articulo_id,unidad_id,SUM(delta) saldo FROM movimientos_inventario GROUP BY almacen_id,articulo_id,unidad_id) m ON m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id AND m.unidad_id=aa.unidad_id WHERE aa.saldo<>COALESCE(m.saldo,0)')->fetch_row()[0]===0,'Los saldos no coinciden con la bitácora.');
    $assert((int)$db->query('SELECT COUNT(DISTINCT unidad_id) FROM almacenes WHERE id IN(1001,1002,1003)')->fetch_row()[0]===3,'Falta un almacén físico de prueba por ámbito.');
    $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos WHERE almacen_id BETWEEN 1001 AND 1007')->fetch_row()[0]===47,'Faltan asociaciones físicas de prueba.');
    $assert((string)$db->query('SELECT GROUP_CONCAT(CONCAT(unidad_id,\':\',cantidad) ORDER BY unidad_id) FROM (SELECT unidad_id,COUNT(*) cantidad FROM almacenes GROUP BY unidad_id) x')->fetch_row()[0]==='1:2,2:3,3:2','Los siete almacenes deben distribuirse 2/3/2.');
    $esperados=[1001=>'Almacén Central',1002=>'Cocina',1003=>'Producción',1004=>'Almacén de Suministros',1005=>'Barra y Maridaje',1006=>'Refrigeración',1007=>'Producto Terminado'];
    foreach($db->query('SELECT id,nombre FROM almacenes ORDER BY id')->fetch_all(MYSQLI_ASSOC) as $a)$assert(($esperados[(int)$a['id']]??null)===$a['nombre'],'Identidad física estable de almacén.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ingredientes')->fetch_row()[0]===30,'El catálogo ampliado contiene treinta artículos.');
    foreach([1001=>'23320.750',1002=>'11920.750',1003=>'2860.750'] as $articulo=>$saldo)$assert((string)$db->query('SELECT SUM(saldo) FROM almacen_articulos WHERE articulo_id='.$articulo)->fetch_row()[0]===$saldo,'Consolidado exacto de apertura del artículo '.$articulo);
    $assert((string)$db->query('SELECT SUM(saldo) FROM almacen_articulos WHERE articulo_id=1001 AND almacen_id IN(1001,1002)')->fetch_row()[0]==='14920.000','La selección histórica Central/Cocina conserva su total.');
    $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos aa WHERE version<>(SELECT COUNT(*) FROM movimientos_inventario m WHERE m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id)')->fetch_row()[0]===0,'Las versiones de apertura cuentan movimientos reales.');
    $assert((int)$db->query("SELECT COUNT(*) FROM operaciones_inventario WHERE referencia REGEXP '^(\\\\[DEV\\\\]|DEV[ _-])'")->fetch_row()[0]===0,'Las referencias documentales no muestran prefijos DEV.');
    $assert((int)$db->query('SELECT COUNT(*) FROM movimientos_inventario')->fetch_row()[0]===51,'La apertura ampliada añade 27 movimientos trazables.');
    $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id WHERE aa.saldo<0 AND a.permitir_negativos=1')->fetch_row()[0]===1,'Un saldo negativo explícitamente autorizado.');
    $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos WHERE activo=0 AND saldo=0')->fetch_row()[0]===1,'Asociación inactiva sin existencias.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ingredientes i WHERE activo=0 AND NOT EXISTS(SELECT 1 FROM almacen_articulos aa WHERE aa.articulo_id=i.id AND aa.saldo<>0)')->fetch_row()[0]===1,'Artículo retirado compatible con saldos cero.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ingredientes i WHERE NOT EXISTS(SELECT 1 FROM almacen_articulos aa WHERE aa.articulo_id=i.id)')->fetch_row()[0]===1,'Identidad de catálogo sin asociación física.');
    foreach(['usuarios'=>'nombre','ingredientes'=>'nombre','almacenes'=>'nombre','productos'=>'nombre','categorias'=>'nombre','reservaciones'=>'nombre','tickets'=>'nombre','proveedores'=>'nombre','gastos_fijos'=>'nombre','catas'=>'titulo','impresoras'=>'nombre'] as $tabla=>$columna){
        // Identificadores SQL fijos del contrato, nunca derivados de entrada HTTP.
        $assert((int)$db->query("SELECT COUNT(*) FROM `$tabla` WHERE `$columna` REGEXP '^(\\\\[DEV\\\\]|DEV[ _-])'")->fetch_row()[0]===0,'Sin prefijos visibles en '.$tabla);
    }
    $assert((int)$db->query('SELECT COUNT(*) FROM movimientos_inventario WHERE id>=1001 AND id<=1024')->fetch_row()[0]===24,'Faltan movimientos trazables del fixture.');
    $assert((int)$db->query('SELECT COUNT(*) FROM movimientos_inventario m JOIN almacenes a ON a.id=m.almacen_id WHERE m.unidad_id<>a.unidad_id')->fetch_row()[0]===0,'Hay movimientos con almacén de otro ámbito.');
    $assert((int)$db->query("SELECT COUNT(*) FROM usuarios WHERE username IN('referencia_global','referencia_restaurante','referencia_panaderia') AND activo=0 AND es_superadmin=0 AND nip_hash IS NULL")->fetch_row()[0]===3,'Las cuentas de referencia no deben estar activas ni ser superadministradoras.');
    $assert((int)$db->query("SELECT COUNT(*) FROM usuarios u WHERE u.id BETWEEN 1004 AND 1007 AND u.username IN('mesero_1','mesero_2','mesero_3','mesero_inactivo') AND u.activo=0 AND u.es_superadmin=0 AND u.nip_hash IS NULL AND NOT EXISTS(SELECT 1 FROM usuario_unidades uu WHERE uu.usuario_id=u.id) AND NOT EXISTS(SELECT 1 FROM usuario_unidad_permisos up WHERE up.usuario_id=u.id) AND NOT EXISTS(SELECT 1 FROM usuario_unidad_facultades uf WHERE uf.usuario_id=u.id)")->fetch_row()[0]===4,'Las referencias históricas de meseros no reciben acceso ni permisos.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t JOIN usuarios u ON u.id=t.mesero_id WHERE t.unidad_id=2 AND t.id BETWEEN 10000 AND 19999 AND u.activo=0 AND NOT EXISTS(SELECT 1 FROM usuario_unidades uu WHERE uu.usuario_id=u.id)")->fetch_row()[0]>0,'La analítica puede conservar meseros históricos que ya no tienen acceso.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ofertas_producto WHERE id IN(1001,1002) AND unidad_id=(SELECT id FROM unidades WHERE slug=\'restaurante\')')->fetch_row()[0]===2,'Faltan ofertas sintéticas separadas de Restaurante.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ofertas_producto WHERE id=1003 AND producto_id=1003 AND unidad_id=(SELECT id FROM unidades WHERE slug=\'panaderia\')')->fetch_row()[0]===1,'Falta una oferta sintética independiente de Panadería.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=(SELECT id FROM unidades WHERE slug=\'global\')')->fetch_row()[0]===0,'Global no puede ser vendedor.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t JOIN unidades u ON u.id=t.unidad_id WHERE u.tipo<>'negocio'")->fetch_row()[0]===0,'Hay un ticket sin unidad vendedora.');
    $assert((string)$db->query('SELECT SUM(monto) FROM ticket_pagos WHERE ticket_id=1001')->fetch_row()[0]==='70.00','El pago sintético no coincide con la cuenta.');
    $assert((string)$db->query("SELECT SUM(precio*cantidad) FROM ticket_items WHERE ticket_id=1001 AND estado<>'cancelado'")->fetch_row()[0]==='70.00','La venta sintética no coincide con el pago.');
    $assert((string)$db->query('SELECT SUM(delta) FROM movimientos_inventario WHERE ticket_item_id=1002')->fetch_row()[0]==='0.000','La línea cancelada no está compensada.');
    $assert((int)$db->query("SELECT COUNT(DISTINCT estado) FROM ticket_items WHERE ticket_id IN(1002,1003,1004)")->fetch_row()[0]===3,'El fixture debe cubrir enviados, preparación y listos del KDS.');
    $assert((int)$db->query('SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND id BETWEEN 2001 AND 2365 AND estado=\'cerrado\'')->fetch_row()[0]===365,'El fixture debe reconstruir un año de ventas diarias de Restaurante.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ticket_items WHERE unidad_id=2 AND ticket_id BETWEEN 2001 AND 2365')->fetch_row()[0]===803,'El fixture anual debe producir las 803 líneas históricas previstas.');
    $assert((int)$db->query('SELECT COUNT(DISTINCT DATE_FORMAT(hora_apertura,\'%Y-%m\')) FROM tickets WHERE unidad_id=2 AND id BETWEEN 2001 AND 2365')->fetch_row()[0]===12,'Las ventas deben cubrir los doce meses para tendencias y estacionalidad.');
    $assert((int)$db->query('SELECT COUNT(DISTINCT DAYOFWEEK(hora_apertura)) FROM tickets WHERE unidad_id=2 AND id BETWEEN 2001 AND 2365')->fetch_row()[0]===7,'Las ventas deben cubrir todos los días de la semana.');
    $assert((int)$db->query('SELECT COUNT(DISTINCT HOUR(hora_apertura)) FROM tickets WHERE unidad_id=2 AND id BETWEEN 2001 AND 2365')->fetch_row()[0]>=8,'Las ventas deben cubrir varias franjas para RevPASH y analítica horaria.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ticket_mesas WHERE unidad_id=2 AND ticket_id BETWEEN 2001 AND 2365')->fetch_row()[0]===365,'Cada venta histórica debe tener una mesa o superficie operativa asignada.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t JOIN (SELECT ticket_id,unidad_id,SUM(precio*cantidad) monto FROM ticket_items WHERE estado<>'cancelado' GROUP BY ticket_id,unidad_id) i ON i.ticket_id=t.id AND i.unidad_id=t.unidad_id JOIN (SELECT ticket_id,unidad_id,SUM(monto) monto FROM ticket_pagos GROUP BY ticket_id,unidad_id) p ON p.ticket_id=t.id AND p.unidad_id=t.unidad_id WHERE t.unidad_id=2 AND t.id BETWEEN 2001 AND 2365 AND t.metodo_pago='dividido' AND ABS(i.monto-p.monto)>0.001")->fetch_row()[0]===0,'Los pagos divididos deben cuadrar con sus líneas entregadas.');
    $assert((int)$db->query('SELECT COUNT(*) FROM operaciones_financieras WHERE unidad_id=2 AND ticket_id BETWEEN 2001 AND 2365')->fetch_row()[0]===0,'El DML no debe duplicar en finanzas ventas que ya provienen de tickets.');
    $assert((int)$db->query("SELECT COUNT(*) FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id WHERE o.id IN(1004,1005) AND o.unidad_id=2 AND p.id IN(1004,1005) ")->fetch_row()[0]===2,'Las ofertas de temporada deben ser sintéticas y permanecer fuera del menú preservado.');
    $assert((int)$db->query("SELECT COUNT(*) FROM ticket_items WHERE ticket_id BETWEEN 2001 AND 2365 AND estado='cancelado'")->fetch_row()[0]===36,'El histórico debe cubrir cancelaciones anteriores a producción.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ticket_pagos WHERE ticket_id BETWEEN 2001 AND 2365')->fetch_row()[0]===242,'Las cuentas divididas deben contener dos pagos por ticket.');
    $metric=static fn(string $sql): int => (int)$db->query($sql)->fetch_row()[0];
    $conteos=[
        'usuarios_total'=>$metric('SELECT COUNT(*) FROM usuarios'),
        'usuarios_fixture'=>$metric('SELECT COUNT(*) FROM usuarios WHERE id BETWEEN 1001 AND 1007'),
        'membresias'=>$metric('SELECT COUNT(*) FROM usuario_unidades'),
        'permisos_directos'=>$metric('SELECT COUNT(*) FROM usuario_unidad_permisos'),
        'facultades'=>$metric('SELECT COUNT(*) FROM usuario_unidad_facultades'),
        'superficies'=>$metric('SELECT COUNT(*) FROM mesas WHERE unidad_id=2 AND id BETWEEN 1 AND 16'),
        'mesas_reservables'=>$metric('SELECT COUNT(*) FROM mesas WHERE unidad_id=2 AND id BETWEEN 1 AND 16 AND reservable=1'),
        'asientos_reservables'=>$metric('SELECT COALESCE(SUM(capacidad),0) FROM mesas WHERE unidad_id=2 AND id BETWEEN 1 AND 16 AND reservable=1'),
        'productos_totales'=>$metric('SELECT COUNT(*) FROM productos'),
        'productos_dev'=>$metric('SELECT COUNT(*) FROM productos WHERE id>=1000'),
        'ofertas_totales'=>$metric('SELECT COUNT(*) FROM ofertas_producto'),
        'ofertas_dev'=>$metric('SELECT COUNT(*) FROM ofertas_producto WHERE id>=1000'),
        'categorias_totales'=>$metric('SELECT COUNT(*) FROM categorias'),
        'cartas'=>$metric('SELECT COUNT(*) FROM cartas'),
        'areas_produccion'=>$metric('SELECT COUNT(*) FROM areas_produccion'),
        'articulos_maestros'=>$metric('SELECT COUNT(*) FROM ingredientes'),
        'almacenes'=>$metric('SELECT COUNT(*) FROM almacenes'),
        'articulos_en_almacen'=>$metric('SELECT COUNT(*) FROM almacen_articulos'),
        'recetas'=>$metric('SELECT COUNT(*) FROM recetas'),
        'subrecetas'=>$metric('SELECT COUNT(*) FROM subrecetas'),
        'variantes'=>$metric('SELECT COUNT(*) FROM producto_variantes'),
        'ajustes_variante'=>$metric('SELECT COUNT(*) FROM variante_ajustes'),
        'consumos_oferta'=>$metric('SELECT COUNT(*) FROM oferta_consumos'),
        'operaciones_inventario'=>$metric('SELECT COUNT(*) FROM operaciones_inventario'),
        'movimientos_inventario'=>$metric('SELECT COUNT(*) FROM movimientos_inventario'),
        'reservaciones'=>$metric('SELECT COUNT(*) FROM reservaciones WHERE unidad_id=2'),
        'relaciones_reservacion_mesa'=>$metric('SELECT COUNT(*) FROM reservacion_mesas WHERE unidad_id=2'),
        'reservaciones_por_estado'=>$reservacionesEstado,
        'reubicaciones'=>$metric('SELECT COUNT(*) FROM reservacion_reubicaciones WHERE unidad_id=2'),
        'tickets_total'=>$metric('SELECT COUNT(*) FROM tickets WHERE unidad_id=2'),
        'tickets_abiertos'=>$metric("SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND estado='abierto'"),
        'tickets_cerrados'=>$metric("SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND estado='cerrado'"),
        'tickets_analitica_365d'=>$metric('SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND id BETWEEN 2001 AND 2365'),
        'tickets_revpash_semana'=>$metric('SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND id BETWEEN 4001 AND 4028'),
        'tickets_asistente'=>$metric('SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND id BETWEEN 10000 AND 19999'),
        'dias_asistente'=>$metric('SELECT COUNT(DISTINCT DATE(hora_apertura)) FROM tickets WHERE unidad_id=2 AND id BETWEEN 10000 AND 19999'),
        'meses_analitica'=>$metric("SELECT COUNT(DISTINCT DATE_FORMAT(hora_apertura,'%Y-%m')) FROM tickets WHERE unidad_id=2 AND id BETWEEN 2001 AND 2365"),
        'ticket_items'=>$metric('SELECT COUNT(*) FROM ticket_items WHERE unidad_id=2'),
        'relaciones_ticket_mesa'=>$metric('SELECT COUNT(*) FROM ticket_mesas WHERE unidad_id=2'),
        'items_cancelados'=>$metric("SELECT COUNT(*) FROM ticket_items WHERE unidad_id=2 AND estado='cancelado'"),
        'items_enviados'=>$metric("SELECT COUNT(*) FROM ticket_items WHERE unidad_id=2 AND estado='enviado'"),
        'items_preparacion'=>$metric("SELECT COUNT(*) FROM ticket_items WHERE unidad_id=2 AND estado='en_preparacion'"),
        'items_listos'=>$metric("SELECT COUNT(*) FROM ticket_items WHERE unidad_id=2 AND estado='listo'"),
        'items_entregados'=>$metric("SELECT COUNT(*) FROM ticket_items WHERE unidad_id=2 AND estado='entregado'"),
        'pagos'=>$metric('SELECT COUNT(*) FROM ticket_pagos WHERE unidad_id=2'),
        'tickets_divididos'=>$metric("SELECT COUNT(*) FROM tickets WHERE unidad_id=2 AND metodo_pago='dividido'"),
        'gastos_fijos'=>$metric('SELECT COUNT(*) FROM gastos_fijos'),
        'operaciones_financieras'=>$metric('SELECT COUNT(*) FROM operaciones_financieras'),
        'feedback'=>$metric('SELECT COUNT(*) FROM feedback'),
        'catas'=>$metric('SELECT COUNT(*) FROM catas'),
        'historial_precios'=>$metric('SELECT COUNT(*) FROM historial_precios'),
    ];
    $periodo=$db->query("SELECT MIN(DATE(hora_apertura)),MAX(DATE(hora_apertura)),COUNT(DISTINCT DATE(hora_apertura)),COUNT(DISTINCT HOUR(hora_apertura)),COUNT(DISTINCT DAYOFWEEK(hora_apertura)) FROM tickets WHERE unidad_id=2")->fetch_row();
    return ['ok'=>true,'menu'=>$menu['conteos'],'conteos'=>$conteos,'periodo_tickets'=>['desde'=>$periodo[0],'hasta'=>$periodo[1],'dias'=>$periodo[2],'horas'=>$periodo[3],'dias_semana'=>$periodo[4]],'comprobaciones_acumuladas'=>$count];
}
