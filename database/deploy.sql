-- Seed estructural mínimo para el esquema multinegocio 3A.
-- No siembra usuarios, credenciales, impresoras, áreas, categorías ni catálogo.
SET NAMES utf8mb4;
INSERT INTO unidades(id,nombre,slug,tipo) VALUES
 (1,'Global','global','global'),(2,'Restaurante','restaurante','negocio'),(3,'Panadería','panaderia','negocio')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),tipo=VALUES(tipo);
INSERT INTO roles(id,slug,nombre,descripcion,tipo,unidad_id) VALUES
 (1,'super_admin','Super admin','Administración organizacional Global.','sistema',NULL),
 (2,'admin','Administrador','Administración limitada a sus membresías.','sistema',NULL),
 (3,'waiter','Mesero','Punto de venta, mesas y reservas.','predefinido',NULL),
 (4,'cook','Cocinero','Tableros de producción por área.','predefinido',NULL)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),descripcion=VALUES(descripcion),tipo=VALUES(tipo);
INSERT INTO rol_permisos(rol_id,modulo,nivel) VALUES (3,'pdv','editar'),(4,'area','editar')
ON DUPLICATE KEY UPDATE nivel=VALUES(nivel);
INSERT INTO facultades(clave,solo_global,descripcion) VALUES
 ('usuarios.gestionar',0,'Gestionar cuentas dentro del alcance autorizado.'),
 ('permisos.delegar',0,'Delegar permisos dentro del alcance autorizado.'),
 ('almacenes.crear',1,'Crear almacenes para cualquier unidad desde Global.'),
 ('catalogo.editar',1,'Editar el catálogo maestro compartido.'),
 ('transferencias.solicitar',0,'Solicitar transferencias autorizadas.'),
 ('transferencias.enviar',0,'Enviar transferencias desde almacenes autorizados.'),
 ('transferencias.recibir',0,'Recibir transferencias en almacenes autorizados.'),
 ('transferencias.supervisar',0,'Supervisar transferencias autorizadas.')
ON DUPLICATE KEY UPDATE solo_global=VALUES(solo_global),descripcion=VALUES(descripcion);
INSERT INTO cartas(unidad_id,slug,nombre,orden,activo) VALUES
 (2,'comida','Comida',1,1),(2,'maridaje','Maridaje',2,1),(2,'temporada','Temporada',3,1),
 (3,'comida','Comida',1,1),(3,'maridaje','Maridaje',2,1),(3,'temporada','Temporada',3,1)
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre),orden=VALUES(orden),activo=VALUES(activo);
INSERT INTO configuracion_pos(unidad_id,id) VALUES (2,1),(3,1) ON DUPLICATE KEY UPDATE id=VALUES(id);
INSERT INTO configuracion_paleta(unidad_id,id) VALUES (2,1),(3,1) ON DUPLICATE KEY UPDATE id=VALUES(id);
INSERT INTO configuracion_reservaciones(unidad_id,id,recordatorio_dia_anterior_activo,hora_recordatorio) VALUES (2,1,0,'18:00:00'),(3,1,0,'18:00:00') ON DUPLICATE KEY UPDATE id=VALUES(id);
INSERT INTO horarios_operacion(unidad_id,dia_semana,abierto,hora_apertura,hora_cierre) VALUES
 (2,0,1,'08:30','19:00'),(2,1,1,'13:00','22:00'),(2,2,1,'08:30','22:00'),(2,3,1,'08:30','22:00'),(2,4,1,'08:30','22:00'),(2,5,1,'08:30','22:00'),(2,6,1,'08:30','22:00'),
 (3,0,1,'08:30','19:00'),(3,1,1,'08:30','22:00'),(3,2,1,'08:30','22:00'),(3,3,1,'08:30','22:00'),(3,4,1,'08:30','22:00'),(3,5,1,'08:30','22:00'),(3,6,1,'08:30','22:00')
ON DUPLICATE KEY UPDATE abierto=VALUES(abierto),hora_apertura=VALUES(hora_apertura),hora_cierre=VALUES(hora_cierre);

INSERT INTO esquema_versiones(version) VALUES ('multinegocio-3a-v1') ON DUPLICATE KEY UPDATE version=VALUES(version);
INSERT INTO esquema_versiones(version) VALUES ('multinegocio-3b-v1') ON DUPLICATE KEY UPDATE version=VALUES(version);

-- Mapa operativo original de Restaurante, conservado en el despliegue.
-- Los IDs 1..16 son el contrato del POS, reservaciones y fixtures locales.
INSERT INTO mesas(id,unidad_id,numero,nombre,tipo,zona,capacidad,pos_x,pos_y,activo,reservable) VALUES
 (1,2,1,'Mesa 1','mesa','interior',4,30.00,88.00,1,1),
 (2,2,2,'Mesa 2','mesa','interior',4,7.00,70.00,1,1),
 (3,2,3,'Mesa 3','mesa','interior',4,30.00,51.00,1,1),
 (4,2,4,'Mesa 4','mesa','interior',4,7.00,51.00,1,1),
 (5,2,5,'Mesa 5','mesa','interior',4,7.00,29.00,1,1),
 (6,2,6,'Mesa 6','mesa','interior',4,44.00,29.00,1,1),
 (7,2,7,'Mesa 7','mesa','interior',4,88.00,29.00,1,1),
 (8,2,8,'Mesa 8','mesa','exterior',4,88.00,8.00,1,1),
 (9,2,9,'Mesa 9','mesa','exterior',4,56.00,8.00,1,1),
 (10,2,10,'Mesa 10','mesa','exterior',4,30.00,8.00,1,1),
 (11,2,11,'Mesa 11','mesa','exterior',4,7.00,8.00,1,1),
 (12,2,12,'Barra Blanca','barra',NULL,8,62.00,51.00,1,0),
 (13,2,13,'Caja','especial',NULL,0,33.00,70.00,1,0),
 (14,2,14,'Llevar','especial',NULL,0,57.00,70.00,1,0),
 (15,2,15,'Barra Roja','barra',NULL,6,88.00,70.00,1,0),
 (16,2,16,'Barra Roja 2','barra',NULL,6,88.00,88.00,1,0)
ON DUPLICATE KEY UPDATE numero=VALUES(numero),nombre=VALUES(nombre),tipo=VALUES(tipo),zona=VALUES(zona),capacidad=VALUES(capacidad),pos_x=VALUES(pos_x),pos_y=VALUES(pos_y),activo=VALUES(activo),reservable=VALUES(reservable);