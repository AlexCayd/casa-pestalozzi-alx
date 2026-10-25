<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/datos/verificar-menu.php';

/** Helper de solo lectura para los ensayos de instalación independiente 3E. */
function probarDatos3E(mysqli $db,string $root,int &$count): array
{
    $menu=MenuVersionado3E::base($db,$root);$count+=(int)$menu['comprobaciones'];
    $assert=static function(bool $condition,string $message) use(&$count): void {if(!$condition)throw new RuntimeException($message);$count++;};
    $assert((int)$db->query("SELECT COUNT(*) FROM esquema_versiones WHERE version='datos-desarrollo-3e-v1'")->fetch_row()[0]===1,'Falta el marcador del DML de desarrollo.');
    $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos aa LEFT JOIN (SELECT almacen_id,articulo_id,unidad_id,SUM(delta) saldo FROM movimientos_inventario GROUP BY almacen_id,articulo_id,unidad_id) m ON m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id AND m.unidad_id=aa.unidad_id WHERE aa.saldo<>COALESCE(m.saldo,0)')->fetch_row()[0]===0,'Los saldos no coinciden con la bitácora.');
    $assert((int)$db->query('SELECT COUNT(DISTINCT unidad_id) FROM almacenes WHERE id IN(1001,1002,1003)')->fetch_row()[0]===3,'Falta un almacén físico de prueba por ámbito.');
    $assert((int)$db->query('SELECT COUNT(*) FROM almacen_articulos WHERE almacen_id IN(1001,1002,1003)')->fetch_row()[0]===9,'Faltan asociaciones físicas de prueba.');
    $assert((int)$db->query('SELECT COUNT(*) FROM movimientos_inventario WHERE id>=1001 AND id<=1022')->fetch_row()[0]===22,'Faltan movimientos trazables del fixture.');
    $assert((int)$db->query('SELECT COUNT(*) FROM movimientos_inventario m JOIN almacenes a ON a.id=m.almacen_id WHERE m.unidad_id<>a.unidad_id')->fetch_row()[0]===0,'Hay movimientos con almacén de otro ámbito.');
    $assert((int)$db->query("SELECT COUNT(*) FROM usuarios WHERE username IN('dev_global_inactivo','dev_restaurante_inactivo','dev_panaderia_inactivo') AND activo=0 AND es_superadmin=0 AND nip_hash IS NULL")->fetch_row()[0]===3,'Las cuentas de referencia no deben estar activas ni ser superadministradoras.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ofertas_producto WHERE id IN(1001,1002) AND unidad_id=(SELECT id FROM unidades WHERE slug=\'restaurante\')')->fetch_row()[0]===2,'Faltan ofertas sintéticas separadas de Restaurante.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ofertas_producto WHERE id=1003 AND producto_id=1003 AND unidad_id=(SELECT id FROM unidades WHERE slug=\'panaderia\')')->fetch_row()[0]===1,'Falta una oferta sintética independiente de Panadería.');
    $assert((int)$db->query('SELECT COUNT(*) FROM ofertas_producto WHERE unidad_id=(SELECT id FROM unidades WHERE slug=\'global\')')->fetch_row()[0]===0,'Global no puede ser vendedor.');
    $assert((int)$db->query("SELECT COUNT(*) FROM tickets t JOIN unidades u ON u.id=t.unidad_id WHERE u.tipo<>'negocio'")->fetch_row()[0]===0,'Hay un ticket sin unidad vendedora.');
    $assert((string)$db->query('SELECT SUM(monto) FROM ticket_pagos WHERE ticket_id=1001')->fetch_row()[0]==='70.00','El pago sintético no coincide con la cuenta.');
    $assert((string)$db->query("SELECT SUM(precio*cantidad) FROM ticket_items WHERE ticket_id=1001 AND estado<>'cancelado'")->fetch_row()[0]==='70.00','La venta sintética no coincide con el pago.');
    $assert((string)$db->query('SELECT SUM(delta) FROM movimientos_inventario WHERE ticket_item_id=1002')->fetch_row()[0]==='0.000','La línea cancelada no está compensada.');
    $assert((int)$db->query("SELECT COUNT(DISTINCT estado) FROM ticket_items WHERE ticket_id IN(1002,1003,1004)")->fetch_row()[0]===3,'El fixture debe cubrir enviados, preparación y listos del KDS.');
    return ['ok'=>true,'menu'=>$menu['conteos'],'comprobaciones_acumuladas'=>$count];
}
