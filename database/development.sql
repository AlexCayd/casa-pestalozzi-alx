-- Fixtures reproducibles 3E, exclusivamente development/test.
-- Requiere ddl.sql, deploy.sql y menu-restaurante.sql en una base nueva.
-- Se ejecuta una sola vez: las PK y el marcador rechazan una segunda carga.
-- Las cuentas de referencia permanecen inactivas, sin credenciales conocidas.
-- admin_demo se aprovisiona aparte con el bootstrap local seguro.
SET NAMES utf8mb4;
START TRANSACTION;
SET @dev_ayer=DATE_SUB(CURDATE(), INTERVAL 1 DAY);
SET @dev_manana=DATE_ADD(CURDATE(), INTERVAL 3 DAY);
SET @dev_cierre=TIMESTAMP(@dev_ayer,'14:00:00');
SET @dev_apertura=DATE_SUB(NOW(), INTERVAL 30 MINUTE);
INSERT INTO usuarios(id,username,nombre,password_hash,activo,es_superadmin) VALUES
 ('1001','dev_global_inactivo','[DEV] Referencia Global','$2y$10$hatCLgX3ods3zFMmdoNrDuzv4MTcxJ7UaElssfBkHxAsP3NciI4dO','0','0'),
 ('1002','dev_restaurante_inactivo','[DEV] Referencia Restaurante','$2y$10$I5QgTcI8B19Fzp5NR.LkJuTQa.JX27E8vc0dVOWHf5008qAxRJFR.','0','0'),
 ('1003','dev_panaderia_inactivo','[DEV] Referencia Panadería','$2y$10$ilOA3qCX9GoQL0wQPxnLruKlkE.qlyOUa.n/8zOD4ZdeftVzEEW4O','0','0');
INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES
 ('1001','1',NULL,'1'),
 ('1002','2',NULL,'1'),
 ('1003','3',NULL,'1');
INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES
 ('1001','1','users','editar'),
 ('1001','1','inventario','editar'),
 ('1002','2','users','editar'),
 ('1002','2','inventario','editar'),
 ('1002','2','menu','editar'),
 ('1002','2','productos','editar'),
 ('1002','2','analytics','editar'),
 ('1002','2','finanzas','editar'),
 ('1002','2','tickets','editar'),
 ('1002','2','reservations','editar'),
 ('1002','2','configuration','editar'),
 ('1002','2','printers','editar'),
 ('1002','2','pdv','operar'),
 ('1002','2','area','operar'),
 ('1002','2','reservations_map','operar'),
 ('1003','3','users','editar'),
 ('1003','3','inventario','editar');
INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES
 ('1001','1','usuarios.gestionar'),
 ('1001','1','permisos.delegar'),
 ('1002','2','usuarios.gestionar'),
 ('1002','2','permisos.delegar'),
 ('1003','3','usuarios.gestionar'),
 ('1003','3','permisos.delegar'),
 ('1001','1','almacenes.crear'),
 ('1001','1','catalogo.editar');
INSERT INTO ingredientes(id,nombre,unidad,costo,activo) VALUES
 ('1001','[DEV] Harina compartida','g','0.0200','1'),
 ('1002','[DEV] Leche compartida','ml','0.0150','1'),
 ('1003','[DEV] Café compartido','g','0.2500','1');
INSERT INTO almacenes(id,unidad_id,nombre,permitir_negativos,activo) VALUES
 ('1001','1','[DEV] Almacén central','0','1'),
 ('1002','2','[DEV] Almacén Restaurante','1','1'),
 ('1003','3','[DEV] Almacén Panadería','0','1');
INSERT INTO almacen_articulos(almacen_id,articulo_id,unidad_id,saldo,minimo,activo,version) VALUES
 ('1001','1001','1','0.000','100.000','1','0'),
 ('1001','1002','1','0.000','100.000','1','0'),
 ('1001','1003','1','0.000','100.000','1','0'),
 ('1002','1001','2','0.000','100.000','1','0'),
 ('1002','1002','2','0.000','100.000','1','0'),
 ('1002','1003','2','0.000','980.000','1','0'),
 ('1003','1001','3','0.000','100.000','1','0'),
 ('1003','1002','3','0.000','100.000','1','0'),
 ('1003','1003','3','0.000','100.000','1','0');
INSERT INTO categorias(id,unidad_id,carta,nombre,activo,orden) VALUES
 ('1001','2','comida','[DEV] Pruebas Restaurante','1','9999'),
 ('1002','3','comida','[DEV] Pruebas Panadería','1','9999');
INSERT INTO areas_produccion(id,unidad_id,nombre,slug,color,orden) VALUES
 ('10','3','[DEV] Panadería','dev-pan','#4267ac','1');
INSERT INTO productos(id,nombre,descripcion,img) VALUES
 ('1001','[DEV] Café con leche','Oferta sintética independiente del menú preservado.',NULL),
 ('1002','[DEV] Tostada de prueba','Oferta sintética para receta con subreceta.',NULL),
 ('1003','[DEV] Hogaza Panadería','Identidad sintética de Panadería; no copia el menú de Restaurante.',NULL);
INSERT INTO ofertas_producto(id,producto_id,unidad_id,categoria_id,precio,area_id,activo,orden) VALUES
 ('1001','1001','2','1001','35.00','1','1','10000'),
 ('1002','1002','2','1001','45.00','3','1','10001'),
 ('1003','1003','3','1002','55.00','10','1','1');
INSERT INTO subrecetas(id,unidad_id,nombre,version,rendimiento,unidad,activo) VALUES
 ('1001','2','[DEV] Porción de leche','1','100.000','ml','1');
INSERT INTO subreceta_ingredientes(id,subreceta_id,unidad_id,articulo_id,cantidad) VALUES
 ('1001','1001','2','1002','100.000');
INSERT INTO recetas(id,oferta_id,unidad_id,version,activo) VALUES
 ('1001','1001','2','1','1'),
 ('1002','1002','2','1','1'),
 ('1003','1003','3','1','1');
INSERT INTO receta_componentes(id,receta_id,unidad_id,articulo_id,subreceta_id,cantidad) VALUES
 ('1001','1001','2','1003',NULL,'10.000'),
 ('1002','1001','2','1002',NULL,'100.000'),
 ('1003','1002','2','1001',NULL,'80.000'),
 ('1004','1002','2',NULL,'1001','20.000'),
 ('1005','1003','3','1001',NULL,'200.000');
INSERT INTO producto_variantes(id,oferta_id,unidad_id,grupo,nombre,precio_delta,predeterminada,orden_grupo,orden,activo) VALUES
 ('1001','1001','2','Leche','Normal','0.00','1','0','0','1'),
 ('1002','1001','2','Leche','Extra','3.00','0','0','1','1');
INSERT INTO variante_ajustes(id,variante_id,oferta_id,unidad_id,articulo_id,cantidad,accion) VALUES
 ('1001','1002','1001','2','1002','50.000','agregar');
INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES
 ('1001','1002','2','1002'),
 ('1001','1003','2','1002'),
 ('1002','1001','2','1002'),
 ('1002','1002','2','1002'),
 ('1003','1001','3','1003');
INSERT INTO mesas(id,unidad_id,numero,nombre,tipo,zona,capacidad,pos_x,pos_y,activo,reservable) VALUES
 ('1001','2','1','[DEV] Mesa 1','mesa','interior','4','15','20','1','1'),
 ('1002','2','2','[DEV] Mesa 2','mesa','interior','4','35','20','1','1'),
 ('1003','2','3','[DEV] Mesa 3','mesa','interior','4','55','20','1','1'),
 ('1004','2','4','[DEV] Mesa 4','mesa','interior','4','75','20','1','1'),
 ('1005','2','5','[DEV] Mesa 5','mesa','exterior','4','15','60','1','1'),
 ('1006','2','6','[DEV] Mesa 6','mesa','exterior','4','35','60','1','1'),
 ('1007','2','7','[DEV] Mesa 7','mesa','exterior','4','55','60','1','1'),
 ('1008','2','8','[DEV] Mesa 8','mesa','exterior','4','75','60','1','1');
INSERT INTO reservaciones(id,unidad_id,nombre,contacto_tipo,contacto,fecha,hora,comensales,nota,origen,estado) VALUES
 ('1001','2','[DEV] Reserva futura','ninguno',NULL,@dev_manana,'13:00:00','2','Reserva sintética sin comunicación externa.','admin','confirmada'),
 ('1002','2','[DEV] Reserva completada','ninguno',NULL,@dev_ayer,'12:00:00','2','Registro sintético de atención.','admin','completada');
INSERT INTO reservacion_mesas(id,unidad_id,reservacion_id,mesa_id,orden) VALUES
 ('1001','2','1001','1001','1'),
 ('1002','2','1002','1001','1');
INSERT INTO tickets(id,unidad_id,comensales,nombre,hora_apertura,closed_at,hora_cierre,estado,metodo_pago,propina,reservacion_id,mesero_id) VALUES
 ('1001','2','2','[DEV] Venta cerrada',TIMESTAMP(@dev_ayer,'12:00:00'),@dev_cierre,@dev_cierre,'cerrado','efectivo','7.00','1002','1002'),
 ('1002','2','2','[DEV] Comanda enviada',@dev_apertura,NULL,NULL,'abierto',NULL,'0.00',NULL,'1002'),
 ('1003','2','2','[DEV] En preparación',@dev_apertura,NULL,NULL,'abierto',NULL,'0.00',NULL,'1002'),
 ('1004','2','2','[DEV] Lista para entregar',@dev_apertura,NULL,NULL,'abierto',NULL,'0.00',NULL,'1002');
INSERT INTO ticket_mesas(id,unidad_id,ticket_id,mesa_id,orden) VALUES
 ('1001','2','1001','1001','1'),
 ('1002','2','1002','1002','1'),
 ('1003','2','1003','1003','1'),
 ('1004','2','1004','1004','1');
INSERT INTO ticket_items(id,unidad_id,ticket_id,oferta_id,producto_id,receta_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,comensal,cantidad,nota,variante_ids,variante,costo_unitario,estado,created_at) VALUES
 ('1001','2','1001','1001','1001','1001','[DEV] Café con leche','35.00','[DEV] Pruebas Restaurante','1001','1','Barra de Café','1','2',NULL,'1001','Normal','4.0000','entregado',TIMESTAMP(@dev_ayer,'12:05:00')),
 ('1002','2','1001','1002','1002','1002','[DEV] Tostada de prueba','45.00','[DEV] Pruebas Restaurante','1001','3','Cocina','1','1','Cancelación sintética compensada.',NULL,NULL,'1.9000','cancelado',TIMESTAMP(@dev_ayer,'12:06:00')),
 ('1003','2','1002','1001','1001','1001','[DEV] Café con leche','38.00','[DEV] Pruebas Restaurante','1001','1','Barra de Café',NULL,'1',NULL,'1002','Extra','4.7500','enviado',@dev_apertura),
 ('1004','2','1003','1002','1002','1002','[DEV] Tostada de prueba','45.00','[DEV] Pruebas Restaurante','1001','3','Cocina',NULL,'1',NULL,NULL,NULL,'1.9000','en_preparacion',@dev_apertura),
 ('1005','2','1004','1001','1001','1001','[DEV] Café con leche','35.00','[DEV] Pruebas Restaurante','1001','1','Barra de Café',NULL,'1',NULL,'1001','Normal','4.0000','listo',@dev_apertura);
INSERT INTO ticket_pagos(id,unidad_id,ticket_id,comensal,metodo_pago,monto,created_at) VALUES
 ('1001','2','1001','1','efectivo','70.00',@dev_cierre);
INSERT INTO operaciones_inventario(id,unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia,resultado,fecha) VALUES
 ('1001','1','entrada','automatizado',NULL,'fixture_desarrollo','dev-3e-apertura-global','3a2e406a1af5de836ae3a017c177af14a9500b45f2535f5774ee82a255b0caf8','dev-3e-apertura-global',JSON_OBJECT('fixture',true),@dev_cierre),
 ('1002','2','entrada','automatizado',NULL,'fixture_desarrollo','dev-3e-apertura-restaurante','db18d811b284b482f07d2b1a1fb46d36d20c5b06c52c9456f20b926915740ac0','dev-3e-apertura-restaurante',JSON_OBJECT('fixture',true),@dev_cierre),
 ('1003','3','entrada','automatizado',NULL,'fixture_desarrollo','dev-3e-apertura-panaderia','92c01ed0c20417dc651df29598717690dcb56aa02ee3ab6a24742fa11091d3be','dev-3e-apertura-panaderia',JSON_OBJECT('fixture',true),@dev_cierre),
 ('1004','2','venta','automatizado',NULL,'fixture_desarrollo','dev-3e-venta-cerrada','6586733af7b7c499f2f668154460cb8e6e7331709f1f2acfb128b310788d56b2','dev-3e-venta-cerrada',JSON_OBJECT('fixture',true),@dev_cierre),
 ('1005','2','venta','automatizado',NULL,'fixture_desarrollo','dev-3e-venta-cancelada','1e1e7fbb99827fa414844b4ff2cebed2d77f01459acd39f259dddbd0d559a7a5','dev-3e-venta-cancelada',JSON_OBJECT('fixture',true),@dev_cierre),
 ('1006','2','cancelacion','automatizado',NULL,'fixture_desarrollo','dev-3e-compensacion','b7c8cd520e1191b7be19b7f0d460d9474a3310610514a566cf407cd9081a1c82','dev-3e-compensacion',JSON_OBJECT('fixture',true),@dev_cierre),
 ('1007','2','venta','automatizado',NULL,'fixture_desarrollo','dev-3e-comanda-enviada','248c7a5eb231e93103c841de08102f4b86a62a2785f439c612cb65b5aa023d27','dev-3e-comanda-enviada',JSON_OBJECT('fixture',true),@dev_apertura),
 ('1008','2','venta','automatizado',NULL,'fixture_desarrollo','dev-3e-comanda-preparacion','8759f64c6490a68c9af7457d5baf04e697c53c6139e723fc51685a59eaa0d882','dev-3e-comanda-preparacion',JSON_OBJECT('fixture',true),@dev_apertura),
 ('1009','2','venta','automatizado',NULL,'fixture_desarrollo','dev-3e-comanda-lista','0fb66eb4730d68f4d65060edea59660d7c9c9d2aa0649ee6ccb4006746da8bbf','dev-3e-comanda-lista',JSON_OBJECT('fixture',true),@dev_apertura),
 ('1010','2','merma','automatizado',NULL,'fixture_desarrollo','dev-3e-merma-leche','a3df284979e88db0610451a8b2038120cd4025b0c3725d658faba1df857cf94d','dev-3e-merma-leche',JSON_OBJECT('fixture',true),@dev_apertura);
INSERT INTO movimientos_inventario(id,operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,motivo,nota,costo_unitario,ticket_item_id,movimiento_origen_id,fecha) VALUES
 ('1001','1001','1','1001','1001','entrada','10000.000','fixture_3e','Dato simulado reproducible.','0.0200',NULL,NULL,@dev_cierre),
 ('1002','1001','1','1001','1002','entrada','5000.000','fixture_3e','Dato simulado reproducible.','0.0150',NULL,NULL,@dev_cierre),
 ('1003','1001','1','1001','1003','entrada','1000.000','fixture_3e','Dato simulado reproducible.','0.2500',NULL,NULL,@dev_cierre),
 ('1004','1002','2','1002','1001','entrada','5000.000','fixture_3e','Dato simulado reproducible.','0.0200',NULL,NULL,@dev_cierre),
 ('1005','1002','2','1002','1002','entrada','5000.000','fixture_3e','Dato simulado reproducible.','0.0150',NULL,NULL,@dev_cierre),
 ('1006','1002','2','1002','1003','entrada','1000.000','fixture_3e','Dato simulado reproducible.','0.2500',NULL,NULL,@dev_cierre),
 ('1007','1003','3','1003','1001','entrada','8000.000','fixture_3e','Dato simulado reproducible.','0.0200',NULL,NULL,@dev_cierre),
 ('1008','1003','3','1003','1002','entrada','2000.000','fixture_3e','Dato simulado reproducible.','0.0150',NULL,NULL,@dev_cierre),
 ('1009','1003','3','1003','1003','entrada','500.000','fixture_3e','Dato simulado reproducible.','0.2500',NULL,NULL,@dev_cierre),
 ('1010','1004','2','1002','1003','venta','-20.000','fixture_3e','Dato simulado reproducible.','0.2500','1001',NULL,@dev_cierre),
 ('1011','1004','2','1002','1002','venta','-200.000','fixture_3e','Dato simulado reproducible.','0.0150','1001',NULL,@dev_cierre),
 ('1012','1005','2','1002','1001','venta','-80.000','fixture_3e','Dato simulado reproducible.','0.0200','1002',NULL,@dev_cierre),
 ('1013','1005','2','1002','1002','venta','-20.000','fixture_3e','Dato simulado reproducible.','0.0150','1002',NULL,@dev_cierre),
 ('1014','1006','2','1002','1001','cancelacion','80.000','fixture_3e','Dato simulado reproducible.','0.0200','1002','1012',@dev_cierre),
 ('1015','1006','2','1002','1002','cancelacion','20.000','fixture_3e','Dato simulado reproducible.','0.0150','1002','1013',@dev_cierre),
 ('1016','1007','2','1002','1003','venta','-10.000','fixture_3e','Dato simulado reproducible.','0.2500','1003',NULL,@dev_apertura),
 ('1017','1007','2','1002','1002','venta','-150.000','fixture_3e','Dato simulado reproducible.','0.0150','1003',NULL,@dev_apertura),
 ('1018','1008','2','1002','1001','venta','-80.000','fixture_3e','Dato simulado reproducible.','0.0200','1004',NULL,@dev_apertura),
 ('1019','1008','2','1002','1002','venta','-20.000','fixture_3e','Dato simulado reproducible.','0.0150','1004',NULL,@dev_apertura),
 ('1020','1009','2','1002','1003','venta','-10.000','fixture_3e','Dato simulado reproducible.','0.2500','1005',NULL,@dev_apertura),
 ('1021','1009','2','1002','1002','venta','-100.000','fixture_3e','Dato simulado reproducible.','0.0150','1005',NULL,@dev_apertura),
 ('1022','1010','2','1002','1002','merma','-10.000','fixture_3e','Dato simulado reproducible.','0.0150',NULL,NULL,@dev_apertura);
-- Los saldos se derivan de la bitácora creada dentro de esta misma transacción.
UPDATE almacen_articulos aa
SET saldo=(SELECT COALESCE(SUM(m.delta),0) FROM movimientos_inventario m WHERE m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id AND m.unidad_id=aa.unidad_id),
 version=(SELECT COUNT(*) FROM movimientos_inventario m WHERE m.almacen_id=aa.almacen_id AND m.articulo_id=aa.articulo_id AND m.unidad_id=aa.unidad_id)
WHERE aa.almacen_id IN (1001,1002,1003);
INSERT INTO proveedores(id,unidad_id,nombre,activo) VALUES
 ('1001','2','[DEV] Proveedor Restaurante','1'),
 ('1002','3','[DEV] Proveedor Panadería','1');
INSERT INTO ingrediente_proveedores(id,unidad_id,ingrediente_id,proveedor_id,costo,preferente) VALUES
 ('1001','2','1002','1001','0.0150','1'),
 ('1002','3','1001','1002','0.0200','1');
INSERT INTO gastos_fijos(id,unidad_id,nombre,categoria,monto,activo) VALUES
 ('1001','2','[DEV] Renta simulada','renta','2000.00','1'),
 ('1002','3','[DEV] Servicios simulados','servicios','500.00','1');
INSERT INTO operaciones_financieras(id,unidad_id,tipo,concepto,monto,ticket_id,usuario_id,fecha) VALUES
 ('1001','2','ingreso','[DEV] Cobro de venta sintética','70.00','1001',NULL,@dev_cierre),
 ('1002','2','egreso','[DEV] Compra menor simulada','50.00',NULL,NULL,@dev_cierre);
INSERT INTO feedback(id,unidad_id,ticket_id,calidad_sabor,atencion_mesero,tiempo_espera,experiencia_global,comentario) VALUES
 ('1001','2','1001','5','4','4','5','[DEV] Opinión sintética sin datos personales.');
INSERT INTO catas(id,unidad_id,titulo,descripcion,fecha,hora,duracion_min,precio,disponible) VALUES
 ('1001','2','[DEV] Cata de prueba','Evento sintético para verificar la agenda.',@dev_manana,'18:00:00','90','200.00','1');
UPDATE configuracion_pos SET impresion_activa=0 WHERE unidad_id IN (2,3);
INSERT INTO esquema_versiones(version) VALUES ('datos-desarrollo-3e-v1');
COMMIT;
