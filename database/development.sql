-- Fixtures reproducibles 3E, exclusivamente development/test.
-- Requiere ddl.sql, deploy.sql y menu-restaurante.sql en una base nueva.
-- Se ejecuta una sola vez: las PK y el marcador rechazan una segunda carga.
-- Las cuentas de referencia permanecen inactivas, sin credenciales conocidas.
-- admin_demo solo se inserta si el instalador CLI identificó development/test.
SET NAMES utf8mb4;
START TRANSACTION;
SET @dev_ayer=DATE_SUB(CURDATE(), INTERVAL 1 DAY);
SET @dev_manana=DATE_ADD(CURDATE(), INTERVAL 3 DAY);
SET @dev_cierre=TIMESTAMP(@dev_ayer,'14:00:00');
SET @dev_apertura=DATE_SUB(NOW(), INTERVAL 30 MINUTE);
INSERT INTO usuarios(id,username,nombre,password_hash,activo,es_superadmin) VALUES
 ('1001','dev_global_inactivo','[DEV] Referencia Global','$2y$10$hatCLgX3ods3zFMmdoNrDuzv4MTcxJ7UaElssfBkHxAsP3NciI4dO','0','0'),
 ('1002','dev_restaurante_inactivo','[DEV] Referencia Restaurante','$2y$10$I5QgTcI8B19Fzp5NR.LkJuTQa.JX27E8vc0dVOWHf5008qAxRJFR.','0','0'),
 ('1003','dev_panaderia_inactivo','[DEV] Referencia Panadería','$2y$10$ilOA3qCX9GoQL0wQPxnLruKlkE.qlyOUa.n/8zOD4ZdeftVzEEW4O','0','0'),
 ('1004','dev_mesero_1','[DEV] Mesero 1','$2y$10$I5QgTcI8B19Fzp5NR.LkJuTQa.JX27E8vc0dVOWHf5008qAxRJFR.','0','0'),
 ('1005','dev_mesero_2','[DEV] Mesero 2','$2y$10$I5QgTcI8B19Fzp5NR.LkJuTQa.JX27E8vc0dVOWHf5008qAxRJFR.','0','0'),
 ('1006','dev_mesero_3','[DEV] Mesero 3','$2y$10$I5QgTcI8B19Fzp5NR.LkJuTQa.JX27E8vc0dVOWHf5008qAxRJFR.','0','0'),
 ('1007','dev_mesero_inactivo','[DEV] Mesero anterior','$2y$10$I5QgTcI8B19Fzp5NR.LkJuTQa.JX27E8vc0dVOWHf5008qAxRJFR.','0','0');
-- Hash bcrypt de la credencial acordada para pruebas locales; nunca guardar el texto claro.
-- Instalacion::ejecutar define la variable solo en su flujo CLI development/test.
INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin)
SELECT 'admin_demo','Administrador de desarrollo','$2y$10$DQ66kBvQDeSLRs14KmPan.KzDcy770MUnjK4WqSY84ca/ffhjYx3W',1,1
FROM DUAL WHERE COALESCE(@cp_dev_admin_demo_seed,0)=1;
INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo)
SELECT u.id,un.id,NULL,1 FROM usuarios u JOIN unidades un ON un.slug IN ('global','restaurante','panaderia')
WHERE u.username='admin_demo' AND COALESCE(@cp_dev_admin_demo_seed,0)=1;
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
 ('1003','[DEV] Café compartido','g','0.2500','1'),
 ('1004','[DEV] Bebida de avena','ml','0.0300','1'),
 ('1005','[DEV] Aguacate','g','0.0600','1');
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
 ('1003','1003','3','0.000','100.000','1','0'),
 ('1002','1004','2','0.000','100.000','1','0'),
 ('1002','1005','2','0.000','100.000','1','0');
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
 ('1002','1001','2','Leche','Extra','3.00','0','0','1','1'),
 ('1003','1001','2','Leche','Sin leche','0.00','0','0','2','1'),
 ('1004','1001','2','Leche','Avena','8.00','0','0','3','1'),
 ('1005','1002','2','Acompañamiento','Aguacate extra','20.00','0','0','0','1');
INSERT INTO variante_ajustes(id,variante_id,oferta_id,unidad_id,articulo_id,cantidad,accion,articulo_nuevo_id,subreceta_nueva_id,nueva_cantidad) VALUES
 ('1001','1002','1001','2','1002','50.000','agregar',NULL,NULL,NULL),
 ('1002','1003','1001','2','1002','100.000','quitar',NULL,NULL,NULL),
 ('1003','1004','1001','2','1002','100.000','sustituir',1004,NULL,'100.000'),
 ('1004','1005','1002','2','1005','50.000','agregar',NULL,NULL,NULL);
INSERT INTO oferta_consumos(oferta_id,articulo_id,unidad_id,almacen_id) VALUES
 ('1001','1002','2','1002'),
 ('1001','1003','2','1002'),
 ('1002','1001','2','1002'),
 ('1002','1002','2','1002'),
 ('1003','1001','3','1003'),
 ('1001','1004','2','1002'),
 ('1002','1005','2','1002');
INSERT INTO reservaciones(id,unidad_id,nombre,contacto_tipo,contacto,fecha,hora,comensales,nota,origen,estado) VALUES
 ('1001','2','[DEV] Reserva futura','ninguno',NULL,@dev_manana,'13:00:00','2','Reserva sintética sin comunicación externa.','admin','confirmada'),
 ('1002','2','[DEV] Reserva completada','ninguno',NULL,@dev_ayer,'12:00:00','2','Registro sintético de atención.','admin','completada');
INSERT INTO reservacion_mesas(id,unidad_id,reservacion_id,mesa_id,orden) VALUES
 ('1001','2','1001','1','1'),
 ('1002','2','1002','1','1');
INSERT INTO tickets(id,unidad_id,comensales,nombre,hora_apertura,closed_at,hora_cierre,estado,metodo_pago,propina,reservacion_id,mesero_id) VALUES
 ('1001','2','2','[DEV] Venta cerrada',TIMESTAMP(@dev_ayer,'12:00:00'),@dev_cierre,@dev_cierre,'cerrado','efectivo','7.00','1002','1002'),
 ('1002','2','2','[DEV] Comanda enviada',@dev_apertura,NULL,NULL,'abierto',NULL,'0.00',NULL,'1002'),
 ('1003','2','2','[DEV] En preparación',@dev_apertura,NULL,NULL,'abierto',NULL,'0.00',NULL,'1002'),
 ('1004','2','2','[DEV] Lista para entregar',@dev_apertura,NULL,NULL,'abierto',NULL,'0.00',NULL,'1002');
INSERT INTO ticket_mesas(id,unidad_id,ticket_id,mesa_id,orden) VALUES
 ('1001','2','1001','1','1'),
 ('1002','2','1002','2','1'),
 ('1003','2','1003','3','1'),
 ('1004','2','1004','4','1');
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
 ('1022','1010','2','1002','1002','merma','-10.000','fixture_3e','Dato simulado reproducible.','0.0150',NULL,NULL,@dev_apertura),
 ('1023','1002','2','1002','1004','entrada','1000.000','fixture_3e','Existencia para opción de avena.','0.0300',NULL,NULL,@dev_cierre),
 ('1024','1002','2','1002','1005','entrada','1000.000','fixture_3e','Existencia para opción de aguacate.','0.0600',NULL,NULL,@dev_cierre);
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
-- Referencias locales de impresión para probar gestión y estados sin activar hardware.
INSERT INTO impresoras(id,unidad_id,nombre,area_id,rol,conexion,host,puerto,dispositivo,ancho,activo) VALUES
 (101,2,'[DEV] Comanda Café',1,'comanda','red','127.0.0.1',9100,NULL,32,0),
 (102,2,'[DEV] Comanda Jugos',2,'comanda','red','127.0.0.1',9100,NULL,32,0),
 (103,2,'[DEV] Comanda Cocina',3,'comanda','red','127.0.0.1',9100,NULL,48,0),
 (104,2,'[DEV] Comanda Horno',4,'comanda','red','127.0.0.1',9100,NULL,48,0),
 (105,2,'[DEV] Cuenta Caja',NULL,'cuenta','red','127.0.0.1',9100,NULL,48,0);
INSERT INTO historial_precios(id,unidad_id,oferta_id,articulo_id,precio_anterior,precio_nuevo,motivo,proveedor_id,created_at) VALUES
 (1001,2,1001,NULL,NULL,35.0000,'alta',NULL,@dev_cierre),
 (1002,2,1002,NULL,NULL,45.0000,'alta',NULL,@dev_cierre),
 (1003,2,NULL,1002,NULL,0.0150,'proveedor',1001,@dev_cierre),
 (1004,2,NULL,1004,NULL,0.0300,'proveedor',1001,@dev_cierre),
 (1005,2,NULL,1005,NULL,0.0600,'proveedor',1001,@dev_cierre);
-- =====================================================================
-- BLOQUE DE COBERTURA MULTIPERIODO — arquitectura 3E
--
-- El DML anterior de main usaba IDs/rangos 200–499 para analíticas/RevPASH,
-- IDs 900–999 para variantes y 10000–19999 para el asistente, sobre tablas y
-- catálogo mononegocio retirados. Esta reconstrucción genera hechos nuevos en
-- tickets, ticket_items, ticket_mesas y ticket_pagos; no copia aquellos INSERT,
-- no altera el menú original y no reintroduce ingredientes.stock.
-- Una venta de carta sin receta congelada mantiene el costo como desconocido;
-- las operaciones POS de prueba existentes sí conservan su ledger 3C completo.
-- =====================================================================
SET @dev_ahora_cobertura=NOW();
CREATE TEMPORARY TABLE cp_dev_serie AS
SELECT n,
       DATE_SUB(LAST_DAY(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)),INTERVAL n-1 DAY) dia,
       CASE DAYOFWEEK(DATE_SUB(LAST_DAY(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)),INTERVAL n-1 DAY))
         WHEN 1 THEN 9+MOD(n,8)
         WHEN 2 THEN 13+MOD(n,7)
         ELSE 9+MOD(n,11)
       END hora,
       MOD(n*37,51) minuto
FROM (
 SELECT unidades.d+decenas.d*10+centenas.d*100+1 n
 FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) unidades
 CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) decenas
 CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) centenas
) numeros
WHERE n<=365;
ALTER TABLE cp_dev_serie ADD PRIMARY KEY(n);
INSERT INTO productos(id,nombre,descripcion,img) VALUES
 (1004,'[DEV] Cacao de temporada','Oferta sintética para ensayar variantes y analíticas; no forma parte del menú original.',NULL),
 (1005,'[DEV] Pan dulce de temporada','Oferta sintética para ensayar categoría y área de producción.',NULL);
INSERT INTO ofertas_producto(id,producto_id,unidad_id,categoria_id,precio,area_id,activo,orden) VALUES
 (1004,1004,2,15,85.00,1,1,10002),
 (1005,1005,2,15,70.00,4,0,10003);
INSERT INTO productos(id,nombre,descripcion,img) VALUES
 (1010,'[DEV] Asistente sin venta 01','Oferta activa sin tickets para probar el asistente local.',NULL),
 (1011,'[DEV] Asistente sin venta 02','Oferta activa sin tickets para probar el asistente local.',NULL),
 (1012,'[DEV] Asistente sin venta 03','Oferta activa sin tickets para probar el asistente local.',NULL),
 (1013,'[DEV] Asistente sin venta 04','Oferta activa sin tickets para probar el asistente local.',NULL),
 (1014,'[DEV] Asistente sin venta 05','Oferta activa sin tickets para probar el asistente local.',NULL),
 (1015,'[DEV] Asistente sin venta 06','Oferta activa sin tickets para probar el asistente local.',NULL),
 (1016,'[DEV] Asistente sin venta 07','Oferta activa sin tickets para probar el asistente local.',NULL),
 (1017,'[DEV] Asistente sin venta 08','Oferta activa sin tickets para probar el asistente local.',NULL);
INSERT INTO ofertas_producto(id,producto_id,unidad_id,categoria_id,precio,area_id,activo,orden) VALUES
 (1010,1010,2,1001,45.00,1,1,10100),(1011,1011,2,1001,50.00,1,1,10101),
 (1012,1012,2,1001,55.00,1,1,10102),(1013,1013,2,1001,60.00,1,1,10103),
 (1014,1014,2,1001,65.00,1,1,10104),(1015,1015,2,1001,70.00,1,1,10105),
 (1016,1016,2,1001,75.00,1,1,10106),(1017,1017,2,1001,80.00,1,1,10107);
INSERT INTO producto_variantes(id,oferta_id,unidad_id,grupo,nombre,precio_delta,predeterminada,orden_grupo,orden,activo) VALUES
 (1010,1004,2,'Tamaño','Regular',0.00,1,0,0,1),
 (1011,1004,2,'Tamaño','Grande',15.00,0,0,1,1),
 (1012,1005,2,'Presentación','Individual',0.00,1,0,0,1),
 (1013,1005,2,'Presentación','Para compartir',35.00,0,0,1,1);
INSERT INTO excepciones_operacion(unidad_id,fecha,tipo,motivo,hora_apertura,hora_cierre,activo) VALUES
 (2,DATE_SUB(CURDATE(),INTERVAL 14 DAY),'cerrado','[DEV] Cierre simulado de mantenimiento',NULL,NULL,1),
 (2,DATE_ADD(CURDATE(),INTERVAL 12 DAY),'horario_especial','[DEV] Servicio especial de prueba','10:00:00','18:00:00',1),
 (2,DATE_ADD(CURDATE(),INTERVAL 20 DAY),'cerrado','[DEV] Día festivo simulado',NULL,NULL,1);
INSERT INTO configuracion_anuncio(unidad_id,id,mensaje,tipo,activo,fecha_inicio,fecha_fin,texto_enlace,url_enlace) VALUES
 (2,1,'[DEV] Especiales de temporada disponibles','novedad_menu',1,@dev_ahora_cobertura,DATE_ADD(@dev_ahora_cobertura,INTERVAL 30 DAY),'Ver menú',NULL),
 (3,1,'[DEV] Vitrina de prueba','aviso_operativo',0,NULL,NULL,NULL,NULL);
INSERT INTO catas(id,unidad_id,titulo,descripcion,fecha,hora,duracion_min,precio,disponible) VALUES
 (1002,2,'[DEV] Cata primavera','Sesión histórica de muestra.','2026-03-21','18:00:00',90,250.00,0),
 (1003,2,'[DEV] Cata verano','Sesión histórica de muestra.','2026-06-20','18:30:00',90,280.00,0),
 (1004,2,'[DEV] Cata próxima','Evento futuro sin proveedor externo.',DATE_ADD(CURDATE(),INTERVAL 14 DAY),'18:00:00',90,300.00,1),
 (1005,2,'[DEV] Cata fin de temporada','Evento sintético de agenda.',DATE_ADD(CURDATE(),INTERVAL 45 DAY),'17:30:00',120,320.00,1),
 (1006,2,'[DEV] Cata de invierno','Evento sintético de agenda.',DATE_ADD(CURDATE(),INTERVAL 100 DAY),'18:00:00',120,350.00,1);
-- Estados históricos separados; sin contactos personales ni envíos externos.
INSERT INTO reservaciones(id,unidad_id,nombre,contacto_tipo,contacto,fecha,hora,comensales,nota,origen,estado,hold_expires_at,reemplaza_reservacion_id) VALUES
 (1003,2,'[DEV] Reserva cancelada','ninguno',NULL,DATE_SUB(CURDATE(),INTERVAL 2 DAY),'13:30:00',3,'[DEV] Cancelación anterior al servicio.','admin','cancelada',NULL,NULL),
 (1004,2,'[DEV] Reserva no presentada','ninguno',NULL,DATE_SUB(CURDATE(),INTERVAL 3 DAY),'14:00:00',2,'[DEV] Seguimiento de no-show.','admin','no_show',NULL,NULL),
 (1005,2,'[DEV] Retención expirada','ninguno',NULL,DATE_SUB(CURDATE(),INTERVAL 4 DAY),'15:00:00',2,'[DEV] Retención vencida.','landing','expirada',DATE_SUB(NOW(),INTERVAL 4 DAY),NULL),
 (1006,2,'[DEV] Solicitud pendiente','ninguno',NULL,DATE_ADD(CURDATE(),INTERVAL 4 DAY),'13:30:00',2,'[DEV] Verificación sin mensajería.','landing','pendiente_verificacion',DATE_ADD(NOW(),INTERVAL 30 MINUTE),NULL),
 (1007,2,'[DEV] Reserva confirmada próxima','ninguno',NULL,DATE_ADD(CURDATE(),INTERVAL 5 DAY),'14:00:00',4,'[DEV] Reserva confirmada para el salón.','admin','confirmada',NULL,NULL),
 (1008,2,'[DEV] Reserva anterior reubicada','ninguno',NULL,DATE_ADD(CURDATE(),INTERVAL 5 DAY),'14:00:00',4,'[DEV] Registro antecedente de reubicación.','admin','reemplazada',NULL,1007),
 (1009,2,'[DEV] Reserva en curso','ninguno',NULL,CURDATE(),TIME(DATE_ADD(NOW(),INTERVAL 30 MINUTE)),2,'[DEV] Servicio operativo de muestra.','admin','en_curso',NULL,NULL);
INSERT INTO reservacion_mesas(id,unidad_id,reservacion_id,mesa_id,orden) VALUES
 (1003,2,1007,2,1),
 (1005,2,1009,5,1);
INSERT INTO reservacion_reubicaciones(id,unidad_id,reservacion_id,causa_reservacion_id,motivo,mesas_antes,mesas_despues,revisada_at) VALUES
 (1001,2,1007,1003,'bloque_exterior','Mesa 5','Mesa 2',NULL);
-- Hechos comerciales de doce meses: se agregan con snapshots del catálogo
-- vigente, dos o tres líneas por ticket, franjas dentro del horario semanal,
-- grupos con coocurrencia para lift y variantes con precio congelado.
INSERT INTO tickets(id,unidad_id,unidad_tipo,comensales,nombre,hora_apertura,closed_at,hora_cierre,estado,metodo_pago,propina,reservacion_id,mesero_id)
SELECT 2000+s.n,2,'negocio',2+MOD(s.n,5),CONCAT('[DEV] Venta histórica ',LPAD(s.n,3,'0')),
       TIMESTAMP(s.dia,MAKETIME(s.hora,s.minuto,0)),
       DATE_ADD(TIMESTAMP(s.dia,MAKETIME(s.hora,s.minuto,0)),INTERVAL 45+MOD(s.n,46) MINUTE),
       DATE_ADD(TIMESTAMP(s.dia,MAKETIME(s.hora,s.minuto,0)),INTERVAL 45+MOD(s.n,46) MINUTE),
       'cerrado',CASE MOD(s.n,3) WHEN 0 THEN 'dividido' WHEN 1 THEN 'efectivo' ELSE 'tarjeta' END,
       MOD(s.n,17)*5.00,NULL,1002
FROM cp_dev_serie s;
CREATE TEMPORARY TABLE cp_dev_lineas AS
SELECT s.n,nums.linea,
       CASE
         WHEN MOD(s.n,8) IN (0,1) THEN IF(nums.linea=1,1,IF(nums.linea=2,40,1+MOD(s.n*11,112)))
         WHEN MOD(s.n,8) IN (2,3) THEN IF(nums.linea=1,18,IF(nums.linea=2,63,1+MOD(s.n*11,112)))
         WHEN MOD(s.n,8) IN (4,5) THEN IF(nums.linea=1,22,IF(nums.linea=2,69,1+MOD(s.n*11,112)))
         WHEN MOD(s.n,8)=6 THEN IF(nums.linea=1,80,IF(nums.linea=2,96,1+MOD(s.n*11,112)))
         ELSE IF(nums.linea=1,1004,IF(nums.linea=2,112,1005))
       END oferta_id,
       IF(MOD(s.n,5)=0,3,2) lineas_ticket
FROM cp_dev_serie s
CROSS JOIN (SELECT 1 linea UNION ALL SELECT 2 UNION ALL SELECT 3) nums
WHERE nums.linea<=IF(MOD(s.n,5)=0,3,2);
ALTER TABLE cp_dev_lineas ADD INDEX idx_cp_dev_lineas(n,oferta_id);
INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,receta_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,comensal,cantidad,nota,variante_ids,variante,costo_unitario,estado,created_at)
SELECT 2,2000+l.n,o.id,o.producto_id,NULL,p.nombre,
       o.precio+COALESCE(pv.precio_delta,0),c.nombre,o.categoria_id,o.area_id,a.nombre,
       1+MOD(l.n+l.linea-1,2+MOD(l.n,5)),1+MOD(l.n+l.linea,3),
       IF(l.linea=3,'[DEV] Renglón cancelado antes de producción.',NULL),
       pv.id,IF(pv.id IS NULL,NULL,CONCAT(pv.grupo,': ',pv.nombre)),NULL,
       IF(l.linea=3 AND MOD(l.n,10)=0,'cancelado','entregado'),
       DATE_ADD(t.hora_apertura,INTERVAL 10+l.linea*3 MINUTE)
FROM cp_dev_lineas l
JOIN tickets t ON t.id=2000+l.n AND t.unidad_id=2
JOIN ofertas_producto o ON o.id=l.oferta_id AND o.unidad_id=2
JOIN productos p ON p.id=o.producto_id
JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
JOIN areas_produccion a ON a.id=o.area_id AND a.unidad_id=o.unidad_id
LEFT JOIN producto_variantes pv ON pv.id=CASE WHEN l.oferta_id=1004 THEN IF(MOD(l.n,2)=0,1010,1011) WHEN l.oferta_id=1005 THEN IF(MOD(l.n,2)=0,1012,1013) ELSE NULL END
  AND pv.oferta_id=o.id AND pv.unidad_id=o.unidad_id AND pv.activo=1;
INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id,orden)
SELECT 2,2000+s.n,
       CASE WHEN MOD(s.n,20)=0 THEN 12 WHEN MOD(s.n,20)=1 THEN 14 ELSE 1+MOD(s.n-1,11) END,1
FROM cp_dev_serie s;
-- Sólo las cuentas divididas guardan dos métodos; su suma coincide exactamente
-- con las líneas entregadas y nunca se duplica como operación financiera.
INSERT INTO ticket_pagos(id,unidad_id,ticket_id,comensal,metodo_pago,monto,created_at)
SELECT 5000+s.n*2,2,2000+s.n,1,'efectivo',ROUND(totales.total/2,2),tk.hora_cierre
FROM cp_dev_serie s JOIN tickets tk ON tk.id=2000+s.n AND tk.metodo_pago='dividido'
JOIN (SELECT ticket_id,unidad_id,SUM(precio*cantidad) total FROM ticket_items WHERE unidad_id=2 AND estado<>'cancelado' GROUP BY ticket_id,unidad_id) totales ON totales.ticket_id=tk.id AND totales.unidad_id=tk.unidad_id;
INSERT INTO ticket_pagos(id,unidad_id,ticket_id,comensal,metodo_pago,monto,created_at)
SELECT 5001+s.n*2,2,2000+s.n,2,'tarjeta',totales.total-ROUND(totales.total/2,2),tk.hora_cierre
FROM cp_dev_serie s JOIN tickets tk ON tk.id=2000+s.n AND tk.metodo_pago='dividido'
JOIN (SELECT ticket_id,unidad_id,SUM(precio*cantidad) total FROM ticket_items WHERE unidad_id=2 AND estado<>'cancelado' GROUP BY ticket_id,unidad_id) totales ON totales.ticket_id=tk.id AND totales.unidad_id=tk.unidad_id;
INSERT INTO operaciones_financieras(id,unidad_id,tipo,concepto,monto,ticket_id,usuario_id,fecha)
SELECT 3000+s.n,2,'egreso',
       CASE MOD(s.n,5) WHEN 0 THEN '[DEV] Servicio de operación simulado' WHEN 1 THEN '[DEV] Insumos sin artículo inventariable' WHEN 2 THEN '[DEV] Mantenimiento simulado' WHEN 3 THEN '[DEV] Nómina de ejemplo' ELSE '[DEV] Gasto de operación' END,
       ROUND(250.00+MOD(s.n*791,45000)/100,2),NULL,NULL,DATE_SUB(@dev_ahora_cobertura,INTERVAL s.n*10 DAY)
FROM cp_dev_serie s WHERE s.n<=36;
INSERT INTO gastos_fijos(id,unidad_id,nombre,categoria,monto,activo) VALUES
 (1003,2,'[DEV] Nómina mensual','nomina',12000.00,1),
 (1004,2,'[DEV] Insumos mensuales','insumos',3500.00,1),
 (1005,2,'[DEV] Servicios mensuales','servicios',1800.00,1),
 (1006,2,'[DEV] Otros gastos mensuales','otros',750.00,1);
INSERT INTO feedback(id,unidad_id,ticket_id,calidad_sabor,atencion_mesero,tiempo_espera,experiencia_global,comentario,created_at)
SELECT 2000+s.n,2,2000+s.n,3+MOD(s.n,3),3+MOD(s.n*2,3),2+MOD(s.n,4),3+MOD(s.n*3,3),
       '[DEV] Opinión agregada de demostración, sin datos personales.',DATE_ADD(t.closed_at,INTERVAL 5 MINUTE)
FROM cp_dev_serie s JOIN tickets t ON t.id=2000+s.n
WHERE MOD(s.n,5)=0;
DROP TEMPORARY TABLE cp_dev_lineas;
DROP TEMPORARY TABLE cp_dev_serie;
-- BLOQUE 3 · RevPASH explícito: semana completa, horarios válidos y mesas originales.
-- Los IDs 4001..4026 representan 26 servicios de lunes a domingo; el hueco del
-- lunes antes de las 13:00 y del domingo desde las 19:00 refleja el horario real.
CREATE TEMPORARY TABLE cp_dev_revpash AS
SELECT d.dia_idx,h.hora,
       DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 7 DAY),INTERVAL d.dia_idx DAY) fecha,
       CASE h.hora WHEN 9 THEN 0 WHEN 13 THEN 1 WHEN 17 THEN 2 ELSE 3 END slot_idx,
       4001+d.dia_idx*4+CASE h.hora WHEN 9 THEN 0 WHEN 13 THEN 1 WHEN 17 THEN 2 ELSE 3 END ticket_id,
       1+MOD(d.dia_idx*4+CASE h.hora WHEN 9 THEN 0 WHEN 13 THEN 1 WHEN 17 THEN 2 ELSE 3 END,11) mesa_id
FROM (SELECT 0 dia_idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) d
CROSS JOIN (SELECT 9 hora UNION ALL SELECT 13 UNION ALL SELECT 17 UNION ALL SELECT 20) h
WHERE (DAYOFWEEK(DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 7 DAY),INTERVAL d.dia_idx DAY))<>2 OR h.hora>=13) AND (DAYOFWEEK(DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 7 DAY),INTERVAL d.dia_idx DAY))<>1 OR h.hora<19);
ALTER TABLE cp_dev_revpash ADD PRIMARY KEY(ticket_id), ADD UNIQUE KEY uq_cp_dev_revpash_dia_hora(fecha,hora);
INSERT INTO reservaciones(id,unidad_id,nombre,contacto_tipo,contacto,fecha,hora,comensales,nota,origen,estado)
SELECT 4101+r.dia_idx,2,CONCAT('[DEV] Reserva semanal ',DAYNAME(r.fecha)),'ninguno',NULL,r.fecha,'13:00:00',
       CASE WHEN r.dia_idx=4 THEN 6 WHEN r.dia_idx=0 THEN 2 ELSE 2+MOD(r.dia_idx,3) END,
       '[DEV] Caso de ocupación completada; sin contacto ni proveedor externo.','admin','completada'
FROM (SELECT dia_idx,DATE_ADD(DATE_SUB(CURDATE(),INTERVAL 7 DAY),INTERVAL dia_idx DAY) fecha
      FROM (SELECT 0 dia_idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) dias) r;
INSERT INTO reservacion_mesas(unidad_id,reservacion_id,mesa_id,orden)
SELECT 2,4101+r.dia_idx,1+MOD(r.dia_idx*4+1,11),1
FROM (SELECT dia_idx FROM (SELECT 0 dia_idx UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) dias) r;
-- El servicio del cuarto día de la ventana semanal a las 13:00 representa un grupo que usa dos mesas;
-- ambas relaciones de ticket y reservación se mantienen alineadas.
INSERT INTO reservacion_mesas(unidad_id,reservacion_id,mesa_id,orden) VALUES (2,4105,11,2);
-- Tres reservas futuras muestran grupos de 2, 3 y 4 mesas. Fechas distintas
-- y mesas exclusivas evitan solapamientos, sin alterar el mapa físico.
INSERT INTO reservaciones(id,unidad_id,nombre,contacto_tipo,contacto,fecha,hora,comensales,nota,origen,estado) VALUES
 (1010,2,'[DEV] Grupo futuro de dos mesas','ninguno',NULL,DATE_ADD(CURDATE(),INTERVAL 7 DAY),'13:00:00',6,'[DEV] Reserva agrupada; capacidad asignada 8.','admin','confirmada'),
 (1011,2,'[DEV] Grupo futuro de tres mesas','ninguno',NULL,DATE_ADD(CURDATE(),INTERVAL 8 DAY),'14:00:00',10,'[DEV] Reserva agrupada; capacidad asignada 12.','admin','confirmada'),
 (1012,2,'[DEV] Grupo futuro de cuatro mesas','ninguno',NULL,DATE_ADD(CURDATE(),INTERVAL 9 DAY),'15:00:00',14,'[DEV] Reserva agrupada; capacidad asignada 16.','admin','confirmada');
INSERT INTO reservacion_mesas(unidad_id,reservacion_id,mesa_id,orden) VALUES
 (2,1010,1,1),(2,1010,2,2),
 (2,1011,3,1),(2,1011,4,2),(2,1011,5,3),
 (2,1012,6,1),(2,1012,7,2),(2,1012,8,3),(2,1012,9,4);
INSERT INTO tickets(id,unidad_id,unidad_tipo,comensales,nombre,hora_apertura,closed_at,hora_cierre,estado,metodo_pago,propina,reservacion_id,mesero_id)
SELECT r.ticket_id,2,'negocio',
       CASE WHEN r.dia_idx=4 AND r.hora=13 THEN 6 WHEN r.dia_idx=0 THEN 1+MOD(r.ticket_id,2) WHEN r.dia_idx>=4 THEN 3+MOD(r.ticket_id,2) ELSE 2+MOD(r.ticket_id,3) END,
       CONCAT('[DEV] RevPASH ',DAYNAME(r.fecha),' ',LPAD(r.hora,2,'0'),':00'),
       TIMESTAMP(r.fecha,MAKETIME(r.hora,MOD(r.ticket_id*13,35),0)),
       DATE_ADD(TIMESTAMP(r.fecha,MAKETIME(r.hora,MOD(r.ticket_id*13,35),0)),INTERVAL 45+MOD(r.ticket_id*11,40) MINUTE),
       DATE_ADD(TIMESTAMP(r.fecha,MAKETIME(r.hora,MOD(r.ticket_id*13,35),0)),INTERVAL 45+MOD(r.ticket_id*11,40) MINUTE),
       'cerrado',CASE MOD(r.ticket_id,5) WHEN 0 THEN 'dividido' WHEN 1 THEN 'tarjeta' ELSE 'efectivo' END,
       5.00+MOD(r.ticket_id,4)*5.00,IF(r.hora=13,4101+r.dia_idx,NULL),1002
FROM cp_dev_revpash r;
INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,receta_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,comensal,cantidad,nota,variante_ids,variante,costo_unitario,estado,created_at)
SELECT 2,r.ticket_id,o.id,o.producto_id,NULL,p.nombre,o.precio,c.nombre,c.id,o.area_id,a.nombre,
       1+MOD(r.ticket_id+l.linea-1,5),IF(l.linea=1 AND r.dia_idx>=4,2,1),
       IF(l.linea=3,'[DEV] Ítem de cortesía cancelado antes de producción.',NULL),NULL,NULL,NULL,
       IF(l.linea=3 AND MOD(r.ticket_id,3)=0,'cancelado','entregado'),
       DATE_ADD(t.hora_apertura,INTERVAL 5+l.linea*4 MINUTE)
FROM cp_dev_revpash r JOIN tickets t ON t.id=r.ticket_id AND t.unidad_id=2
CROSS JOIN (SELECT 1 linea UNION ALL SELECT 2 UNION ALL SELECT 3) l
JOIN ofertas_producto o ON o.id=CASE
  WHEN l.linea=1 AND r.dia_idx=5 AND r.hora=13 THEN 51
  WHEN l.linea=1 AND r.hora=9 THEN 80
  WHEN l.linea=1 AND r.hora=13 THEN 27
  WHEN l.linea=1 AND r.hora=17 THEN 80
  WHEN l.linea=1 AND r.hora=20 THEN 50
  WHEN l.linea=2 AND r.dia_idx=5 AND r.hora=13 THEN 52
  WHEN l.linea=2 AND r.hora=9 THEN 92
  WHEN l.linea=2 AND r.hora=13 THEN 63
  WHEN l.linea=2 AND r.hora=17 THEN 80
  WHEN l.linea=2 AND r.hora=20 THEN 62
  WHEN l.linea=3 AND r.hora=13 THEN 25 ELSE 80 END AND o.unidad_id=2
JOIN productos p ON p.id=o.producto_id
JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
JOIN areas_produccion a ON a.id=o.area_id AND a.unidad_id=o.unidad_id
WHERE l.linea<=CASE WHEN r.dia_idx>=4 AND r.hora IN(13,20) THEN 3 ELSE 2 END;
INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id,orden)
SELECT 2,r.ticket_id,r.mesa_id,1 FROM cp_dev_revpash r;
INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id,orden) VALUES (2,4018,11,2);
-- La suma de pagos es exactamente la de partidas entregadas por ticket.
INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto,created_at)
SELECT 2,t.id,1,IF(t.metodo_pago='tarjeta','tarjeta','efectivo'),
       IF(t.metodo_pago='dividido',ROUND(SUM(i.precio*i.cantidad)/2,2),SUM(i.precio*i.cantidad)),t.hora_cierre
FROM tickets t JOIN ticket_items i ON i.ticket_id=t.id AND i.unidad_id=t.unidad_id AND i.estado<>'cancelado'
WHERE t.unidad_id=2 AND t.id BETWEEN 4001 AND 4026 GROUP BY t.id,t.metodo_pago,t.hora_cierre;
INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto,created_at)
SELECT 2,t.id,2,'tarjeta',SUM(i.precio*i.cantidad)-ROUND(SUM(i.precio*i.cantidad)/2,2),t.hora_cierre
FROM tickets t JOIN ticket_items i ON i.ticket_id=t.id AND i.unidad_id=t.unidad_id AND i.estado<>'cancelado'
WHERE t.unidad_id=2 AND t.id BETWEEN 4001 AND 4026 AND t.metodo_pago='dividido' GROUP BY t.id,t.hora_cierre;
DROP TEMPORARY TABLE cp_dev_revpash;
-- BLOQUE 6 · Hechos móviles para consultas rápidas locales (sin LLM/proveedor).
-- 92 fechas posibles: ritmo semanal desigual, lunes débil, sábado fuerte,
-- ventas repartidas por jornada, 2..6 partidas, propinas y pagos exactos.
-- Los últimos siete días usan Caja/Llevar, dejando la semana RevPASH aislada
-- en las 11 mesas reservables. Los días cerrados no generan tickets.
CREATE TEMPORARY TABLE cp_dev_asistente_dias AS
SELECT n.dia_no,DATE_SUB(CURDATE(),INTERVAL n.dia_no DAY) fecha,
       IF(DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n.dia_no DAY))=2,'13:00:00','08:30:00') hora_inicio,
       CASE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n.dia_no DAY)) WHEN 2 THEN 540 WHEN 1 THEN 630 ELSE 810 END minutos_abierto,
       GREATEST(15,CASE DAYOFWEEK(DATE_SUB(CURDATE(),INTERVAL n.dia_no DAY))
         WHEN 2 THEN 20+MOD(n.dia_no,5) WHEN 7 THEN 42+MOD(n.dia_no,7)
         WHEN 1 THEN 27+MOD(n.dia_no,8) ELSE 30+MOD(n.dia_no,13) END-IF(MOD(n.dia_no,19)=0,5,0)) total_ventas
FROM (SELECT decenas.d*10+unidades.d dia_no
      FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) unidades
      CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) decenas) n
WHERE n.dia_no BETWEEN 0 AND 91
  AND NOT EXISTS (SELECT 1 FROM excepciones_operacion e WHERE e.unidad_id=2 AND e.fecha=DATE_SUB(CURDATE(),INTERVAL n.dia_no DAY) AND e.tipo='cerrado' AND e.activo=1);
ALTER TABLE cp_dev_asistente_dias ADD PRIMARY KEY(dia_no);
CREATE TEMPORARY TABLE cp_dev_asistente AS
SELECT x.*,
       CASE WHEN x.dia_no<=7 THEN IF(MOD(x.venta_no,2)=0,14,13)
            WHEN x.comensales>=5 THEN 12 WHEN MOD(x.venta_no,21)=0 THEN 13
            WHEN MOD(x.venta_no,17)=0 THEN 14 ELSE 1+MOD(x.venta_no-1,11) END mesa_id
FROM (SELECT d.dia_no,d.fecha,d.total_ventas,d.minutos_abierto,d.hora_inicio,n.venta_no,
             10000+d.dia_no*50+n.venta_no ticket_id,
             1+MOD(10000+d.dia_no*50+n.venta_no,6) comensales,
             CASE MOD(10000+d.dia_no*50+n.venta_no,7) WHEN 0 THEN 'dividido' WHEN 1 THEN 'tarjeta' WHEN 2 THEN 'tarjeta' ELSE 'efectivo' END metodo_pago
      FROM cp_dev_asistente_dias d
      JOIN (SELECT decenas.d*10+unidades.d+1 venta_no
            FROM (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) unidades
            CROSS JOIN (SELECT 0 d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4) decenas) n
        ON n.venta_no<=d.total_ventas) x
WHERE x.dia_no>0 OR DATE_ADD(DATE_ADD(TIMESTAMP(x.fecha,x.hora_inicio),INTERVAL FLOOR((x.venta_no-1)*GREATEST(x.minutos_abierto-60,0)/x.total_ventas) MINUTE),INTERVAL 25+MOD(x.ticket_id,31) MINUTE)<=NOW();
ALTER TABLE cp_dev_asistente ADD PRIMARY KEY(ticket_id), ADD KEY idx_cp_dev_asistente_fecha(fecha);
INSERT INTO tickets(id,unidad_id,unidad_tipo,comensales,nombre,hora_apertura,closed_at,hora_cierre,estado,metodo_pago,propina,reservacion_id,mesero_id)
SELECT a.ticket_id,2,'negocio',a.comensales,CONCAT('[DEV] Asistente ',DATE_FORMAT(a.fecha,'%Y-%m-%d'),' #',a.venta_no),
       DATE_ADD(TIMESTAMP(a.fecha,a.hora_inicio),INTERVAL FLOOR((a.venta_no-1)*GREATEST(a.minutos_abierto-60,0)/a.total_ventas) MINUTE),
       DATE_ADD(DATE_ADD(TIMESTAMP(a.fecha,a.hora_inicio),INTERVAL FLOOR((a.venta_no-1)*GREATEST(a.minutos_abierto-60,0)/a.total_ventas) MINUTE),INTERVAL 25+MOD(a.ticket_id,31) MINUTE),
       DATE_ADD(DATE_ADD(TIMESTAMP(a.fecha,a.hora_inicio),INTERVAL FLOOR((a.venta_no-1)*GREATEST(a.minutos_abierto-60,0)/a.total_ventas) MINUTE),INTERVAL 25+MOD(a.ticket_id,31) MINUTE),
       'cerrado',a.metodo_pago,0,NULL,IF(a.mesa_id=13,NULL,IF(a.dia_no>35,1007,1004+MOD(a.venta_no+a.dia_no,3)))
FROM cp_dev_asistente a;
INSERT INTO ticket_items(unidad_id,ticket_id,oferta_id,producto_id,receta_id,nombre,precio,categoria,categoria_id,area_id,area_nombre,comensal,cantidad,nota,variante_ids,variante,costo_unitario,estado,created_at)
SELECT 2,a.ticket_id,o.id,o.producto_id,NULL,p.nombre,o.precio,c.nombre,c.id,o.area_id,ar.nombre,
       1+MOD(a.ticket_id+l.linea-1,a.comensales),1+MOD(a.ticket_id+l.linea,3),
       IF(l.linea>1 AND MOD(a.ticket_id+l.linea,41)=0,'[DEV] Cancelación anterior a producción.',NULL),NULL,NULL,NULL,
       IF(l.linea>1 AND MOD(a.ticket_id+l.linea,41)=0,'cancelado','entregado'),
       DATE_ADD(t.hora_apertura,INTERVAL 4+l.linea*2 MINUTE)
FROM cp_dev_asistente a JOIN tickets t ON t.id=a.ticket_id AND t.unidad_id=2
CROSS JOIN (SELECT 1 linea UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6) l
JOIN ofertas_producto o ON o.unidad_id=2 AND o.id=CASE
  WHEN l.linea=1 AND MOD(a.ticket_id,10)<4 THEN 40
  WHEN l.linea=1 AND MOD(a.ticket_id,10) IN(4,5) THEN 18
  WHEN l.linea=2 AND MOD(a.ticket_id,10)<4 THEN 80
  WHEN l.linea=2 AND MOD(a.ticket_id,10) IN(4,5) THEN 63
  ELSE 1+MOD(a.ticket_id*7+l.linea*13,112) END
JOIN productos p ON p.id=o.producto_id
JOIN categorias c ON c.id=o.categoria_id AND c.unidad_id=o.unidad_id
JOIN areas_produccion ar ON ar.id=o.area_id AND ar.unidad_id=o.unidad_id
WHERE l.linea<=2+MOD(a.ticket_id,5);
INSERT INTO ticket_mesas(unidad_id,ticket_id,mesa_id,orden)
SELECT 2,a.ticket_id,a.mesa_id,1 FROM cp_dev_asistente a;
UPDATE tickets t
JOIN (SELECT ticket_id,SUM(precio*cantidad) importe FROM ticket_items WHERE unidad_id=2 AND ticket_id BETWEEN 10000 AND 19999 AND estado<>'cancelado' GROUP BY ticket_id) v ON v.ticket_id=t.id
SET t.propina=IF(MOD(t.id,13)=0,0,ROUND(v.importe*(8+MOD(t.id,9))/100,2));
INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto,created_at)
SELECT 2,t.id,1,IF(t.metodo_pago='dividido','efectivo',t.metodo_pago),
       IF(t.metodo_pago='dividido',ROUND(SUM(i.precio*i.cantidad)/2,2),SUM(i.precio*i.cantidad)),t.hora_cierre
FROM tickets t JOIN ticket_items i ON i.ticket_id=t.id AND i.unidad_id=t.unidad_id AND i.estado<>'cancelado'
WHERE t.unidad_id=2 AND t.id BETWEEN 10000 AND 19999 GROUP BY t.id,t.metodo_pago,t.hora_cierre;
INSERT INTO ticket_pagos(unidad_id,ticket_id,comensal,metodo_pago,monto,created_at)
SELECT 2,t.id,2,'tarjeta',SUM(i.precio*i.cantidad)-ROUND(SUM(i.precio*i.cantidad)/2,2),t.hora_cierre
FROM tickets t JOIN ticket_items i ON i.ticket_id=t.id AND i.unidad_id=t.unidad_id AND i.estado<>'cancelado'
WHERE t.unidad_id=2 AND t.id BETWEEN 10000 AND 19999 AND t.metodo_pago='dividido' GROUP BY t.id,t.hora_cierre;
INSERT INTO feedback(id,unidad_id,ticket_id,calidad_sabor,atencion_mesero,tiempo_espera,experiencia_global,comentario,created_at)
SELECT 30000+(a.ticket_id-10000),2,a.ticket_id,3+MOD(a.ticket_id,3),3+MOD(a.ticket_id*2,3),2+MOD(a.ticket_id,4),3+MOD(a.ticket_id*3,3),
       '[DEV] Comentario agregado del fixture de desarrollo, sin datos personales.',DATE_ADD(t.closed_at,INTERVAL 5 MINUTE)
FROM cp_dev_asistente a JOIN tickets t ON t.id=a.ticket_id AND t.unidad_id=2 WHERE MOD(a.venta_no,5)=0;
DROP TEMPORARY TABLE cp_dev_asistente;
DROP TEMPORARY TABLE cp_dev_asistente_dias;
INSERT INTO esquema_versiones(version) VALUES ('datos-desarrollo-3e-v6');
COMMIT;
