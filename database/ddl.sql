-- Esquema definitivo multiunidad 3A. MySQL 8.0.16+ / InnoDB.
-- RESET DESTRUCTIVO: ejecutar únicamente sobre una BD desechable recién
-- identificada como desarrollo/pruebas y tras respaldar/exportar el menú.
-- No ejecutar contra DB_NAME configurada. FK_CHECKS solo permite vaciar el
-- esquema completo; se restaura siempre al final.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS usuario_unidad_permisos;
DROP TABLE IF EXISTS importaciones_menu, esquema_versiones, logs_sugerencias, cata_inscripciones, catering_solicitudes, producto_componentes, menu, reportes_sistema, configuracion_reservaciones, configuracion_paleta, configuracion_pos, configuracion_anuncio, buzon_notificaciones, reservacion_recordatorios, horario_impacto_reservaciones, horario_impactos, excepciones_operacion, horarios_operacion, impresion_alertas, impresoras, feedback, feedback_tokens, reservacion_reubicaciones, reservacion_mesas, verificaciones_contacto, catas, ticket_pagos, ticket_mesas, ticket_items, operaciones_financieras, gastos_fijos, historial_precios, ingrediente_proveedores, proveedores, movimientos_inventario, transferencia_historial, transferencia_lineas, transferencias, oferta_consumos, variante_ajustes, producto_variantes, receta_componentes, recetas, subreceta_ingredientes, subrecetas, almacen_articulos, almacenes, operaciones_inventario, oferta_producto, ofertas_producto, productos, categorias, cartas, areas_produccion, mesas, tickets, reservaciones, usuario_unidad_facultades, usuario_unidades, rol_permisos, facultades, usuarios, roles, unidades, ingredientes;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE unidades (
 id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(60) NOT NULL,
 slug VARCHAR(30) NOT NULL UNIQUE, tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 UNIQUE KEY uq_unidades_id_tipo(id,tipo), CONSTRAINT chk_unidad_slug_tipo CHECK ((slug='global' AND tipo='global') OR (slug<>'global' AND tipo='negocio'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE roles (
 id INT AUTO_INCREMENT PRIMARY KEY, slug VARCHAR(40) NOT NULL UNIQUE, nombre VARCHAR(60) NOT NULL,
 descripcion VARCHAR(255), tipo ENUM('sistema','predefinido','personalizado') NOT NULL DEFAULT 'personalizado',
 unidad_id TINYINT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_roles_id_unidad(id,unidad_id), UNIQUE KEY uq_roles_nombre_unidad(nombre,unidad_id),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
 CHECK(tipo='personalizado' OR unidad_id IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE usuarios (
 id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL UNIQUE, nombre VARCHAR(120) NOT NULL,
 password_hash VARCHAR(255) NOT NULL, nip_hash VARCHAR(255), nip_lookup CHAR(64), activo TINYINT(1) NOT NULL DEFAULT 1,
 es_superadmin TINYINT(1) NOT NULL DEFAULT 0 CHECK(es_superadmin IN (0,1)),
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_usuarios_nip_lookup(nip_lookup),
 CONSTRAINT chk_usuarios_nip_par CHECK ((nip_hash IS NULL) = (nip_lookup IS NULL)),
 CONSTRAINT chk_superadmin_sin_nip CHECK (es_superadmin=0 OR nip_hash IS NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE facultades (
 clave VARCHAR(60) PRIMARY KEY, solo_global TINYINT(1) NOT NULL DEFAULT 0, descripcion VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE rol_permisos (
 rol_id INT NOT NULL, modulo VARCHAR(40) NOT NULL, nivel ENUM('ver','editar') NOT NULL,
 PRIMARY KEY(rol_id,modulo), FOREIGN KEY(rol_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE usuario_unidades (
 usuario_id INT NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL, rol_id INT NULL DEFAULT NULL, activo TINYINT(1) NOT NULL DEFAULT 1,
 PRIMARY KEY(usuario_id,unidad_id), KEY idx_uu_rol(rol_id,unidad_id),
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
 FOREIGN KEY(rol_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE usuario_unidad_facultades (
 usuario_id INT NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL, facultad VARCHAR(60) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(usuario_id,unidad_id,facultad),
 FOREIGN KEY(usuario_id,unidad_id) REFERENCES usuario_unidades(usuario_id,unidad_id) ON DELETE CASCADE,
 FOREIGN KEY(facultad) REFERENCES facultades(clave) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Fuente efectiva única para cuentas 3B. rol_id es metadata sin permisos implícitos.
CREATE TABLE usuario_unidad_permisos (
 usuario_id INT NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL, modulo VARCHAR(40) NOT NULL,
 nivel ENUM('ver','editar','operar') NOT NULL,
 PRIMARY KEY(usuario_id,unidad_id,modulo),
 FOREIGN KEY(usuario_id,unidad_id) REFERENCES usuario_unidades(usuario_id,unidad_id) ON DELETE CASCADE,
 CHECK((modulo IN ('pdv','area','reservations_map') AND nivel='operar')
    OR (modulo NOT IN ('pdv','area','reservations_map','roles') AND nivel IN ('ver','editar')))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER //
CREATE TRIGGER trg_membresia_rol_insert BEFORE INSERT ON usuario_unidades FOR EACH ROW
BEGIN DECLARE v_unidad TINYINT UNSIGNED; SELECT unidad_id INTO v_unidad FROM roles WHERE id=NEW.rol_id;
 IF v_unidad IS NOT NULL AND v_unidad<>NEW.unidad_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rol local fuera de su unidad'; END IF;
END//
CREATE TRIGGER trg_membresia_rol_update BEFORE UPDATE ON usuario_unidades FOR EACH ROW
BEGIN DECLARE v_unidad TINYINT UNSIGNED; SELECT unidad_id INTO v_unidad FROM roles WHERE id=NEW.rol_id;
 IF v_unidad IS NOT NULL AND v_unidad<>NEW.unidad_id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Rol local fuera de su unidad'; END IF;
END//
CREATE TRIGGER trg_usuarios_admin_sin_nip_update BEFORE UPDATE ON usuarios FOR EACH ROW
BEGIN
 IF NEW.nip_hash IS NOT NULL AND (NEW.es_superadmin=1 OR EXISTS(SELECT 1 FROM usuario_unidades uu JOIN usuario_unidad_permisos p ON p.usuario_id=uu.usuario_id AND p.unidad_id=uu.unidad_id WHERE uu.usuario_id=OLD.id AND uu.activo=1 AND p.nivel IN ('ver','editar'))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Usuario administrativo no admite NIP'; END IF;
END//
CREATE TRIGGER trg_roles_unidad_update BEFORE UPDATE ON roles FOR EACH ROW
BEGIN
 IF NOT (OLD.unidad_id <=> NEW.unidad_id) AND EXISTS (
   SELECT 1 FROM usuario_unidades uu WHERE uu.rol_id=OLD.id AND NEW.unidad_id IS NOT NULL AND uu.unidad_id<>NEW.unidad_id
 ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='El rol tiene membresias incompatibles con el nuevo propietario'; END IF;
END//
CREATE TRIGGER trg_permiso_directo_insert BEFORE INSERT ON usuario_unidad_permisos FOR EACH ROW
BEGIN
 IF NEW.nivel IN ('ver','editar') AND EXISTS(SELECT 1 FROM usuarios WHERE id=NEW.usuario_id AND nip_hash IS NOT NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Permiso administrativo requiere retirar PIN'; END IF;
 IF NEW.nivel='operar' AND NOT EXISTS(SELECT 1 FROM unidades WHERE id=NEW.unidad_id AND slug='restaurante') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Operación no habilitada en ese ámbito'; END IF;
END//
CREATE TRIGGER trg_permiso_directo_update BEFORE UPDATE ON usuario_unidad_permisos FOR EACH ROW
BEGIN
 IF NEW.nivel IN ('ver','editar') AND EXISTS(SELECT 1 FROM usuarios WHERE id=NEW.usuario_id AND nip_hash IS NOT NULL) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Permiso administrativo requiere retirar PIN'; END IF;
 IF NEW.nivel='operar' AND NOT EXISTS(SELECT 1 FROM unidades WHERE id=NEW.unidad_id AND slug='restaurante') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Operación no habilitada en ese ámbito'; END IF;
END//
CREATE TRIGGER trg_facultad_global_insert BEFORE INSERT ON usuario_unidad_facultades FOR EACH ROW
BEGIN DECLARE v_global TINYINT; DECLARE v_tipo VARCHAR(16); SELECT solo_global INTO v_global FROM facultades WHERE clave=NEW.facultad; SELECT tipo INTO v_tipo FROM unidades WHERE id=NEW.unidad_id;
 IF v_global=1 AND v_tipo<>'global' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Facultad reservada a Global'; END IF;
END//
CREATE TRIGGER trg_facultades_solo_global_update BEFORE UPDATE ON facultades FOR EACH ROW
BEGIN
 IF NEW.solo_global=1 AND EXISTS (
   SELECT 1 FROM usuario_unidad_facultades uf JOIN unidades u ON u.id=uf.unidad_id
   WHERE uf.facultad=OLD.clave AND u.tipo<>'global'
 ) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La facultad sigue asignada a membresias de negocio'; END IF;
END//
CREATE TRIGGER trg_facultad_global_update BEFORE UPDATE ON usuario_unidad_facultades FOR EACH ROW
BEGIN DECLARE v_global TINYINT; DECLARE v_tipo VARCHAR(16); SELECT solo_global INTO v_global FROM facultades WHERE clave=NEW.facultad; SELECT tipo INTO v_tipo FROM unidades WHERE id=NEW.unidad_id;
 IF v_global=1 AND v_tipo<>'global' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Facultad reservada a Global'; END IF;
END//
DELIMITER ;

CREATE TABLE mesas (
 id INT AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 numero INT NOT NULL, nombre VARCHAR(60) NOT NULL, tipo ENUM('mesa','barra','especial') NOT NULL DEFAULT 'mesa', zona ENUM('interior','exterior') NULL,
 capacidad INT NOT NULL DEFAULT 4, pos_x DECIMAL(5,2) NOT NULL DEFAULT 0, pos_y DECIMAL(5,2) NOT NULL DEFAULT 0,
 activo TINYINT(1) NOT NULL DEFAULT 1, reservable TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_mesas_numero(unidad_id,numero), UNIQUE KEY uq_mesas_id_unidad(id,unidad_id),
 CHECK(unidad_tipo='negocio'), CHECK(zona IS NULL OR tipo='mesa'), FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE areas_produccion (
 id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 nombre VARCHAR(60) NOT NULL, slug VARCHAR(20) NOT NULL, color VARCHAR(10) NOT NULL, orden INT NOT NULL DEFAULT 0,
 UNIQUE KEY uq_area_slug(unidad_id,slug), UNIQUE KEY uq_area_id_unidad(id,unidad_id), CHECK(unidad_tipo='negocio'),
 FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE cartas (
 unidad_id TINYINT UNSIGNED NOT NULL, slug VARCHAR(20) NOT NULL, nombre VARCHAR(60) NOT NULL, orden INT NOT NULL DEFAULT 0,
 activo TINYINT(1) NOT NULL DEFAULT 1, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 PRIMARY KEY(unidad_id,slug), KEY idx_carta_unidad_tipo(unidad_id,unidad_tipo), CHECK(unidad_tipo='negocio'), CHECK(slug IN ('comida','maridaje','temporada')),
 FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE categorias (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, carta VARCHAR(20) NOT NULL, nombre VARCHAR(60) NOT NULL,
 img VARCHAR(200) NULL, activo TINYINT(1) NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 UNIQUE KEY uq_categoria_id_unidad(id,unidad_id),
 CHECK(unidad_tipo='negocio'), FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT, FOREIGN KEY(unidad_id,carta) REFERENCES cartas(unidad_id,slug) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE productos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(120) NOT NULL, descripcion TEXT NULL, img VARCHAR(255) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ofertas_producto (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, producto_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio', categoria_id INT UNSIGNED NOT NULL, precio DECIMAL(8,2) NOT NULL,
 area_id TINYINT UNSIGNED NOT NULL, activo TINYINT(1) NOT NULL DEFAULT 1, orden INT NOT NULL DEFAULT 0,
 UNIQUE KEY uq_oferta_producto_unidad(producto_id,unidad_id), UNIQUE KEY uq_oferta_id_unidad(id,unidad_id), UNIQUE KEY uq_oferta_producto_scope(id,producto_id,unidad_id),
 FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE RESTRICT,
 FOREIGN KEY(categoria_id,unidad_id) REFERENCES categorias(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(area_id,unidad_id) REFERENCES areas_produccion(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT, CHECK(unidad_tipo='negocio'), CHECK(precio>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE importaciones_menu (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, paquete_sha256 CHAR(64) NOT NULL UNIQUE, unidad_id TINYINT UNSIGNED NOT NULL,
 fecha TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, conteos JSON NOT NULL, correspondencias JSON NOT NULL,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE esquema_versiones(version VARCHAR(64) PRIMARY KEY, applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ingredientes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(120) NOT NULL, unidad ENUM('g','kg','ml','l','pza') NOT NULL DEFAULT 'g',
 UNIQUE KEY uq_articulo_nombre(nombre),
 costo DECIMAL(10,4) NOT NULL DEFAULT 0, activo TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, CHECK(costo>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE almacenes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, nombre VARCHAR(100) NOT NULL,
 permitir_negativos TINYINT(1) NOT NULL DEFAULT 0 CHECK(permitir_negativos IN (0,1)), activo TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_almacen_id_unidad(id,unidad_id), FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE almacen_articulos (
 almacen_id BIGINT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 saldo DECIMAL(12,3) NOT NULL DEFAULT 0, minimo DECIMAL(12,3) NOT NULL DEFAULT 0, activo TINYINT(1) NOT NULL DEFAULT 1, version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(almacen_id,articulo_id), UNIQUE KEY uq_aa_scope(almacen_id,articulo_id,unidad_id), CHECK(minimo>=0),
 FOREIGN KEY(almacen_id,unidad_id) REFERENCES almacenes(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER //
CREATE TRIGGER trg_almacen_articulos_negativo_insert BEFORE INSERT ON almacen_articulos FOR EACH ROW
BEGIN DECLARE v_permite TINYINT; SELECT permitir_negativos INTO v_permite FROM almacenes WHERE id=NEW.almacen_id AND unidad_id=NEW.unidad_id; IF NEW.saldo<0 AND v_permite=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Saldo negativo no permitido'; END IF; END//
CREATE TRIGGER trg_almacen_articulos_negativo_update BEFORE UPDATE ON almacen_articulos FOR EACH ROW
BEGIN DECLARE v_permite TINYINT; SELECT permitir_negativos INTO v_permite FROM almacenes WHERE id=NEW.almacen_id AND unidad_id=NEW.unidad_id; IF NEW.saldo<0 AND v_permite=0 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Saldo negativo no permitido'; END IF; END//
CREATE TRIGGER trg_almacen_no_desactivar_negativos BEFORE UPDATE ON almacenes FOR EACH ROW
BEGIN IF OLD.permitir_negativos=1 AND NEW.permitir_negativos=0 AND EXISTS(SELECT 1 FROM almacen_articulos WHERE almacen_id=OLD.id AND unidad_id=OLD.unidad_id AND saldo<0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='No se permiten negativos pendientes'; END IF; END//
DELIMITER ;
CREATE TABLE subrecetas (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 nombre VARCHAR(120) NOT NULL, version INT UNSIGNED NOT NULL DEFAULT 1, rendimiento DECIMAL(12,3) NOT NULL,
 unidad ENUM('g','kg','ml','l','pza') NOT NULL DEFAULT 'g', activo TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_subreceta_id_unidad(id,unidad_id), FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT,
 CHECK(unidad_tipo='negocio'), CHECK(version>0 AND rendimiento>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE subreceta_ingredientes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, subreceta_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL, cantidad DECIMAL(12,3) NOT NULL,
 FOREIGN KEY(subreceta_id,unidad_id) REFERENCES subrecetas(id,unidad_id) ON DELETE CASCADE, FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT, CHECK(cantidad>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE recetas (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, oferta_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio', version INT UNSIGNED NOT NULL DEFAULT 1, activo TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_receta_oferta_version(oferta_id,version), UNIQUE KEY uq_receta_id_unidad(id,unidad_id), UNIQUE KEY uq_receta_oferta_scope(id,oferta_id,unidad_id),
 FOREIGN KEY(oferta_id,unidad_id) REFERENCES ofertas_producto(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT, CHECK(unidad_tipo='negocio'), CHECK(version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE receta_componentes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, receta_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 articulo_id INT UNSIGNED NULL, subreceta_id INT UNSIGNED NULL, cantidad DECIMAL(12,3) NOT NULL,
 FOREIGN KEY(receta_id,unidad_id) REFERENCES recetas(id,unidad_id) ON DELETE CASCADE,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(subreceta_id,unidad_id) REFERENCES subrecetas(id,unidad_id) ON DELETE RESTRICT,
 CHECK((articulo_id IS NULL) <> (subreceta_id IS NULL)), CHECK(cantidad>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE producto_variantes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, oferta_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 grupo VARCHAR(40) NOT NULL, nombre VARCHAR(60) NOT NULL, precio_delta DECIMAL(8,2) NOT NULL DEFAULT 0,
 predeterminada TINYINT(1) NOT NULL DEFAULT 0, orden_grupo SMALLINT UNSIGNED NOT NULL DEFAULT 0, orden INT NOT NULL DEFAULT 0, activo TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_variante_grupo_nombre(oferta_id,grupo,nombre), UNIQUE KEY uq_variante_scope(id,oferta_id,unidad_id),
 FOREIGN KEY(oferta_id,unidad_id) REFERENCES ofertas_producto(id,unidad_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE variante_ajustes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, variante_id INT UNSIGNED NOT NULL, oferta_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 articulo_id INT UNSIGNED NULL, subreceta_id INT UNSIGNED NULL, cantidad DECIMAL(12,3) NOT NULL,
 accion ENUM('agregar','quitar','sustituir') NOT NULL, articulo_nuevo_id INT UNSIGNED NULL, subreceta_nueva_id INT UNSIGNED NULL, nueva_cantidad DECIMAL(12,3) NULL,
 FOREIGN KEY(variante_id,oferta_id,unidad_id) REFERENCES producto_variantes(id,oferta_id,unidad_id) ON DELETE CASCADE,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT, FOREIGN KEY(subreceta_id,unidad_id) REFERENCES subrecetas(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_nuevo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT, FOREIGN KEY(subreceta_nueva_id,unidad_id) REFERENCES subrecetas(id,unidad_id) ON DELETE RESTRICT,
 CHECK((articulo_id IS NULL) <> (subreceta_id IS NULL)), CHECK(cantidad>0),
 CHECK((accion='sustituir' AND ((articulo_nuevo_id IS NULL) <> (subreceta_nueva_id IS NULL)) AND nueva_cantidad IS NOT NULL AND nueva_cantidad>0) OR (accion<>'sustituir' AND articulo_nuevo_id IS NULL AND subreceta_nueva_id IS NULL AND nueva_cantidad IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE oferta_consumos (
 oferta_id INT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL, almacen_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(oferta_id,articulo_id), FOREIGN KEY(oferta_id,unidad_id) REFERENCES ofertas_producto(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(almacen_id,articulo_id,unidad_id) REFERENCES almacen_articulos(almacen_id,articulo_id,unidad_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Operación inventario: operaciones son idempotencia; movimientos son bitácora inmutable.
CREATE TABLE operaciones_inventario (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, tipo VARCHAR(40) NOT NULL,
 actor_tipo ENUM('usuario','automatizado') NOT NULL, usuario_id INT NULL, origen VARCHAR(40) NOT NULL,
 clave_idempotencia VARCHAR(120) NOT NULL, hash_solicitud CHAR(64) NOT NULL, referencia VARCHAR(120) NOT NULL,
 resultado JSON NULL, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_op_id_unidad(id,unidad_id), UNIQUE KEY uq_op_idempotencia(unidad_id,origen,clave_idempotencia), UNIQUE KEY uq_op_referencia(unidad_id,tipo,referencia),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
 CHECK((actor_tipo='usuario' AND usuario_id IS NOT NULL) OR (actor_tipo='automatizado' AND usuario_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE tickets (
 id INT AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 comensales INT NOT NULL DEFAULT 1, nombre VARCHAR(120) NULL, hora_apertura DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL, hora_cierre DATETIME NULL,
 estado ENUM('abierto','cerrado','cancelado') NOT NULL DEFAULT 'abierto', metodo_pago ENUM('efectivo','tarjeta','dividido') NULL, propina DECIMAL(8,2) NOT NULL DEFAULT 0,
 reservacion_id INT NULL, mesero_id INT NULL,
 UNIQUE KEY uq_ticket_id_unidad(id,unidad_id), UNIQUE KEY uq_ticket_reservacion(reservacion_id,unidad_id),
 FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT, FOREIGN KEY(mesero_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 CHECK(unidad_tipo='negocio'), CHECK(propina>=0), INDEX idx_ticket_estado(estado), INDEX idx_ticket_cierre(hora_cierre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ticket_items (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, ticket_id INT NOT NULL,
 oferta_id INT UNSIGNED NOT NULL, producto_id INT UNSIGNED NOT NULL, receta_id INT UNSIGNED NULL,
 nombre VARCHAR(120) NOT NULL, precio DECIMAL(8,2) NOT NULL, categoria VARCHAR(60) NOT NULL, categoria_id INT UNSIGNED NULL,
 area_id TINYINT UNSIGNED NOT NULL, area_nombre VARCHAR(60) NULL, comensal TINYINT UNSIGNED NULL, cantidad TINYINT UNSIGNED NOT NULL DEFAULT 1, nota VARCHAR(280) NULL,
 variante_ids VARCHAR(120) NULL, variante VARCHAR(160) NULL, costo_unitario DECIMAL(10,4) NULL,
 estado ENUM('enviado','en_preparacion','listo','entregado','cancelado') NOT NULL DEFAULT 'enviado', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_ticket_item_id_unidad(id,unidad_id),
 FOREIGN KEY(ticket_id,unidad_id) REFERENCES tickets(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(oferta_id,producto_id,unidad_id) REFERENCES ofertas_producto(id,producto_id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(receta_id,oferta_id,unidad_id) REFERENCES recetas(id,oferta_id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(categoria_id,unidad_id) REFERENCES categorias(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(area_id,unidad_id) REFERENCES areas_produccion(id,unidad_id) ON DELETE RESTRICT,
 INDEX idx_area_estado(unidad_id,area_id,estado), INDEX idx_ti_ticket(unidad_id,ticket_id), CHECK(precio>=0), CHECK(cantidad>0), CHECK(costo_unitario IS NULL OR costo_unitario>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ticket_pagos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, ticket_id INT NOT NULL,
 comensal TINYINT UNSIGNED NOT NULL, metodo_pago ENUM('efectivo','tarjeta') NOT NULL, monto DECIMAL(8,2) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(ticket_id,unidad_id) REFERENCES tickets(id,unidad_id) ON DELETE RESTRICT,
 INDEX idx_tp_ticket(unidad_id,ticket_id), CHECK(monto>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ticket_mesas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, ticket_id INT NOT NULL, mesa_id INT NOT NULL,
 orden TINYINT UNSIGNED NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ticket_id,unidad_id) REFERENCES tickets(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(mesa_id,unidad_id) REFERENCES mesas(id,unidad_id) ON DELETE RESTRICT,
 UNIQUE KEY uq_ticket_mesa(ticket_id,mesa_id), UNIQUE KEY uq_ticket_orden(ticket_id,orden), INDEX idx_ticket_mesas_mesa(unidad_id,mesa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE movimientos_inventario (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, operacion_id BIGINT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 almacen_id BIGINT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL, tipo ENUM('entrada','venta','salida','merma','ajuste','cancelacion','transferencia_salida','transferencia_entrada') NOT NULL,
 delta DECIMAL(12,3) NOT NULL, motivo VARCHAR(80) NULL, nota VARCHAR(255) NULL, costo_unitario DECIMAL(10,4) NULL,
 ticket_item_id INT UNSIGNED NULL, movimiento_origen_id BIGINT UNSIGNED NULL, transferencia_linea_id BIGINT UNSIGNED NULL,
 fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(operacion_id,unidad_id) REFERENCES operaciones_inventario(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(almacen_id,articulo_id,unidad_id) REFERENCES almacen_articulos(almacen_id,articulo_id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(ticket_item_id,unidad_id) REFERENCES ticket_items(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(movimiento_origen_id,almacen_id,articulo_id,unidad_id) REFERENCES movimientos_inventario(id,almacen_id,articulo_id,unidad_id) ON DELETE RESTRICT,
 UNIQUE KEY uq_mov_id_almacen_articulo_unidad(id,almacen_id,articulo_id,unidad_id), UNIQUE KEY uq_mov_operacion_origen(operacion_id,movimiento_origen_id), INDEX idx_mov_articulo(almacen_id,articulo_id,fecha),
 INDEX idx_mov_unidad_fecha(unidad_id,fecha,id), INDEX idx_mov_almacen_fecha(almacen_id,fecha,id),
 CHECK(delta<>0), CHECK((tipo IN('entrada','cancelacion','transferencia_entrada') AND delta>0) OR (tipo IN('venta','salida','merma','transferencia_salida') AND delta<0) OR tipo='ajuste'), CHECK(costo_unitario IS NULL OR costo_unitario>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER //
CREATE TRIGGER trg_movimientos_inmutables_update BEFORE UPDATE ON movimientos_inventario FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Movimiento inmutable: registrar compensacion'; END//
CREATE TRIGGER trg_movimientos_inmutables_delete BEFORE DELETE ON movimientos_inventario FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Movimiento inmutable: registrar compensacion'; END//
DELIMITER ;

CREATE TABLE transferencias (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, almacen_origen_id BIGINT UNSIGNED NOT NULL, origen_unidad_id TINYINT UNSIGNED NOT NULL,
 almacen_destino_id BIGINT UNSIGNED NOT NULL, destino_unidad_id TINYINT UNSIGNED NOT NULL, solicitante_id INT NOT NULL,
 emisor_id INT NULL, receptor_id INT NULL, estado ENUM('solicitada','enviada','recibida','cancelada','rechazada','incidencia') NOT NULL DEFAULT 'solicitada',
 version BIGINT UNSIGNED NOT NULL DEFAULT 0, creada_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, enviada_at DATETIME NULL, recibida_at DATETIME NULL,
 FOREIGN KEY(almacen_origen_id,origen_unidad_id) REFERENCES almacenes(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(almacen_destino_id,destino_unidad_id) REFERENCES almacenes(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(solicitante_id) REFERENCES usuarios(id) ON DELETE RESTRICT, FOREIGN KEY(emisor_id) REFERENCES usuarios(id) ON DELETE RESTRICT, FOREIGN KEY(receptor_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
 CHECK(almacen_origen_id<>almacen_destino_id), INDEX idx_transferencia_estado(estado,creada_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE transferencia_lineas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, transferencia_id BIGINT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL,
 cantidad_solicitada DECIMAL(12,3) NOT NULL, cantidad_enviada DECIMAL(12,3) NOT NULL DEFAULT 0, cantidad_recibida DECIMAL(12,3) NOT NULL DEFAULT 0,
 cantidad_retornada DECIMAL(12,3) NOT NULL DEFAULT 0, costo_unitario DECIMAL(10,4) NULL,
 UNIQUE KEY uq_transferencia_articulo(transferencia_id,articulo_id), FOREIGN KEY(transferencia_id) REFERENCES transferencias(id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 CHECK(cantidad_solicitada>0 AND cantidad_enviada>=0 AND cantidad_recibida>=0 AND cantidad_retornada>=0),
 CHECK(cantidad_recibida+cantidad_retornada<=cantidad_enviada AND cantidad_enviada<=cantidad_solicitada)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE movimientos_inventario ADD CONSTRAINT fk_mov_transferencia_linea FOREIGN KEY(transferencia_linea_id) REFERENCES transferencia_lineas(id) ON DELETE RESTRICT;
CREATE TABLE transferencia_historial (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, transferencia_id BIGINT UNSIGNED NOT NULL, estado_anterior VARCHAR(20) NULL, estado_nuevo VARCHAR(20) NOT NULL,
 actor_id INT NULL, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, motivo VARCHAR(255) NULL,
 FOREIGN KEY(transferencia_id) REFERENCES transferencias(id) ON DELETE RESTRICT, FOREIGN KEY(actor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE operaciones_financieras (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, tipo ENUM('ingreso','egreso') NOT NULL,
 concepto VARCHAR(160) NOT NULL, monto DECIMAL(12,2) NOT NULL, ticket_id INT NULL, usuario_id INT NULL, fecha DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(ticket_id,unidad_id) REFERENCES tickets(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL, CHECK(monto>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE gastos_fijos (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, nombre VARCHAR(120) NOT NULL,
 categoria ENUM('renta','servicios','nomina','insumos','otros') NOT NULL DEFAULT 'otros', monto DECIMAL(12,2) NOT NULL DEFAULT 0,
 activo TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, CHECK(monto>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE proveedores (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, nombre VARCHAR(120) NOT NULL,
 contacto VARCHAR(120) NULL, telefono VARCHAR(30) NULL, correo VARCHAR(120) NULL, notas TEXT NULL, activo TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_proveedores_nombre(unidad_id,nombre), UNIQUE KEY uq_proveedores_id_unidad(id,unidad_id),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE ingrediente_proveedores (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, ingrediente_id INT UNSIGNED NOT NULL, proveedor_id INT UNSIGNED NOT NULL,
 costo DECIMAL(10,4) NOT NULL DEFAULT 0, codigo VARCHAR(60) NULL, preferente TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ing_prov(unidad_id,ingrediente_id,proveedor_id), INDEX idx_ing_prov_proveedor(unidad_id,proveedor_id),
 FOREIGN KEY(ingrediente_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(proveedor_id,unidad_id) REFERENCES proveedores(id,unidad_id) ON DELETE RESTRICT, CHECK(costo>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE historial_precios (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, oferta_id INT UNSIGNED NULL, articulo_id INT UNSIGNED NULL,
 precio_anterior DECIMAL(10,4) NULL, precio_nuevo DECIMAL(10,4) NOT NULL, motivo ENUM('alta','edicion','proveedor') NOT NULL DEFAULT 'edicion', proveedor_id INT UNSIGNED NULL, usuario_id INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(oferta_id,unidad_id) REFERENCES ofertas_producto(id,unidad_id) ON DELETE RESTRICT, FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL, FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 CHECK((oferta_id IS NULL) <> (articulo_id IS NULL)), CHECK(precio_nuevo>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE reservaciones (
 id INT AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 nombre VARCHAR(100) NOT NULL, contacto_tipo ENUM('email','telefono','ninguno') NOT NULL DEFAULT 'ninguno', contacto VARCHAR(150) NULL,
 fecha DATE NOT NULL, hora TIME NOT NULL, comensales INT UNSIGNED NOT NULL DEFAULT 2, nota TEXT NULL, comentario_admin TEXT NULL, motivo_cancelacion VARCHAR(500) NULL,
 origen ENUM('landing','admin') NOT NULL, request_token VARCHAR(64) NULL, hold_expires_at DATETIME NULL,
 estado ENUM('pendiente_verificacion','confirmada','en_curso','completada','cancelada','no_show','expirada','reemplazada') NOT NULL DEFAULT 'pendiente_verificacion',
 reemplaza_reservacion_id INT NULL, estado_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_reservacion_id_unidad(id,unidad_id), UNIQUE KEY uq_reservacion_request_token(unidad_id,request_token),
 FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT,
 FOREIGN KEY(reemplaza_reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 CHECK(unidad_tipo='negocio'), CHECK(comensales>0),
 CHECK((contacto_tipo='ninguno' AND contacto IS NULL) OR (contacto_tipo IN('email','telefono') AND contacto IS NOT NULL AND TRIM(contacto)<>'')),
 CHECK(estado<>'pendiente_verificacion' OR hold_expires_at IS NOT NULL),
 INDEX idx_reservaciones_fecha_estado_hora(unidad_id,fecha,estado,hora), INDEX idx_reservaciones_contacto_horario(unidad_id,contacto_tipo,contacto,fecha,hora,estado), INDEX idx_reservaciones_retenciones_vencidas(unidad_id,estado,hold_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE tickets ADD CONSTRAINT fk_ticket_reservacion FOREIGN KEY(reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT;
DELIMITER //
CREATE TRIGGER trg_reservaciones_no_auto_reemplazo_insert BEFORE INSERT ON reservaciones FOR EACH ROW
BEGIN IF NEW.reemplaza_reservacion_id IS NOT NULL AND NEW.id IS NOT NULL AND NEW.reemplaza_reservacion_id=NEW.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una reservacion no puede reemplazarse a si misma'; END IF; END//
CREATE TRIGGER trg_reservaciones_no_auto_reemplazo_update BEFORE UPDATE ON reservaciones FOR EACH ROW
BEGIN IF NEW.reemplaza_reservacion_id=NEW.id THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Una reservacion no puede reemplazarse a si misma'; END IF; END//
DELIMITER ;
CREATE TABLE verificaciones_contacto (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, reservacion_id INT NULL,
 contacto_tipo ENUM('email','telefono') NOT NULL, contacto VARCHAR(150) NOT NULL, codigo_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, delivery_status ENUM('legacy','pending','accepted','failed') NOT NULL DEFAULT 'pending',
 accepted_at DATETIME NULL, finalized_at DATETIME NULL, cycle_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, cycle_expires_at DATETIME NULL,
 used_at DATETIME NULL, invalidated_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 INDEX idx_verificacion_contacto(unidad_id,contacto_tipo,contacto,created_at), INDEX idx_verificacion_expiracion(expires_at), INDEX idx_verificacion_ciclo(unidad_id,contacto_tipo,contacto,reservacion_id,cycle_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE reservacion_mesas (
 id INT AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, reservacion_id INT NOT NULL, mesa_id INT NOT NULL,
 orden TINYINT UNSIGNED NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(mesa_id,unidad_id) REFERENCES mesas(id,unidad_id) ON DELETE RESTRICT,
 UNIQUE KEY uq_reservacion_mesa(reservacion_id,mesa_id), UNIQUE KEY uq_reservacion_orden(reservacion_id,orden), INDEX idx_rm_mesa(unidad_id,mesa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE reservacion_reubicaciones (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, reservacion_id INT NOT NULL, causa_reservacion_id INT NULL,
 motivo VARCHAR(40) NOT NULL DEFAULT 'bloque_exterior', mesas_antes VARCHAR(60) NOT NULL, mesas_despues VARCHAR(60) NOT NULL,
 revisada_at DATETIME NULL, revisada_por INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(causa_reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(revisada_por) REFERENCES usuarios(id) ON DELETE SET NULL, INDEX idx_reubicacion_reservacion(reservacion_id,created_at), INDEX idx_reubicacion_pendiente(revisada_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE catas (
 id INT AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, unidad_tipo ENUM('global','negocio') NOT NULL DEFAULT 'negocio',
 titulo VARCHAR(120) NOT NULL, descripcion TEXT NULL, fecha DATE NOT NULL, hora TIME NOT NULL, duracion_min SMALLINT UNSIGNED NOT NULL DEFAULT 90,
 precio DECIMAL(10,2) NOT NULL DEFAULT 0, disponible TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uq_cata_id_unidad(id,unidad_id),
 FOREIGN KEY(unidad_id,unidad_tipo) REFERENCES unidades(id,tipo) ON DELETE RESTRICT, CHECK(unidad_tipo='negocio'), CHECK(precio>=0 AND duracion_min>0), INDEX idx_catas_agenda(unidad_id,fecha,hora)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE feedback_tokens (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, ticket_id INT NOT NULL, token VARCHAR(64) NOT NULL,
 usado TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_feedback_token(token), UNIQUE KEY uq_feedback_ticket(ticket_id),
 FOREIGN KEY(ticket_id,unidad_id) REFERENCES tickets(id,unidad_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE feedback (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, token_id INT UNSIGNED NULL, ticket_id INT NULL,
 calidad_sabor TINYINT UNSIGNED NOT NULL, atencion_mesero TINYINT UNSIGNED NOT NULL, tiempo_espera TINYINT UNSIGNED NOT NULL, experiencia_global TINYINT UNSIGNED NOT NULL,
 comentario TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(token_id) REFERENCES feedback_tokens(id) ON DELETE SET NULL, FOREIGN KEY(ticket_id,unidad_id) REFERENCES tickets(id,unidad_id) ON DELETE RESTRICT,
 CHECK(calidad_sabor BETWEEN 1 AND 5 AND atencion_mesero BETWEEN 1 AND 5 AND tiempo_espera BETWEEN 1 AND 5 AND experiencia_global BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE impresoras (
 id INT AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, nombre VARCHAR(60) NOT NULL, area_id TINYINT UNSIGNED NULL,
 rol ENUM('comanda','cuenta') NOT NULL DEFAULT 'comanda', conexion ENUM('red','windows') NOT NULL DEFAULT 'red', host VARCHAR(64) NOT NULL,
 puerto INT NOT NULL DEFAULT 9100, dispositivo VARCHAR(120) NULL, ancho TINYINT NOT NULL DEFAULT 48, activo TINYINT(1) NOT NULL DEFAULT 1,
 UNIQUE KEY uq_impresora_id_unidad(id,unidad_id), FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
 FOREIGN KEY(area_id,unidad_id) REFERENCES areas_produccion(id,unidad_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE impresion_alertas (
 id INT AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, documento ENUM('comanda','cuenta') NOT NULL,
 motivo ENUM('sin_impresora','sin_conexion','fallo_envio') NOT NULL, ticket_id INT NULL, mesa_nombre VARCHAR(60) NULL,
 area_id TINYINT UNSIGNED NULL, area_nombre VARCHAR(60) NULL, impresora_nombre VARCHAR(60) NULL, detalle VARCHAR(255) NULL,
 usuario_id INT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, atendida_at DATETIME NULL, atendida_por INT NULL,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(ticket_id,unidad_id) REFERENCES tickets(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL, FOREIGN KEY(atendida_por) REFERENCES usuarios(id) ON DELETE SET NULL,
 INDEX idx_impresion_alertas_pendientes(unidad_id,atendida_at,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE horarios_operacion (
 id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, dia_semana TINYINT UNSIGNED NOT NULL, abierto TINYINT(1) NOT NULL DEFAULT 1,
 hora_apertura TIME NULL, hora_cierre TIME NULL, updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_horario_unidad_dia(unidad_id,dia_semana), FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by) REFERENCES usuarios(id) ON DELETE SET NULL, CHECK(dia_semana BETWEEN 0 AND 6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE excepciones_operacion (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, fecha DATE NOT NULL, tipo ENUM('cerrado','horario_especial') NOT NULL,
 motivo VARCHAR(160) NULL, hora_apertura TIME NULL, hora_cierre TIME NULL, activo TINYINT(1) NOT NULL DEFAULT 1, updated_by INT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_excepcion_fecha(unidad_id,fecha), FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
 FOREIGN KEY(updated_by) REFERENCES usuarios(id) ON DELETE SET NULL, INDEX idx_excepciones_fecha_activo(unidad_id,fecha,activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE horario_impactos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, tipo_origen VARCHAR(40) NOT NULL, origen_id INT UNSIGNED NULL,
 estado ENUM('pendiente','resuelto') NOT NULL DEFAULT 'pendiente', dedup_key CHAR(64) NOT NULL, created_by INT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME NULL, UNIQUE KEY uq_horario_impacto_id_unidad(id,unidad_id), UNIQUE KEY uq_horario_impacto_dedup(unidad_id,dedup_key),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(created_by) REFERENCES usuarios(id) ON DELETE SET NULL,
 INDEX idx_horario_impactos_estado_fecha(unidad_id,estado,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE horario_impacto_reservaciones (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, impacto_id INT UNSIGNED NOT NULL, reservacion_id INT NOT NULL,
 estado ENUM('pendiente_notificacion','notificacion_preparada','sin_contacto','atendida_manual','resuelta_por_cliente') NOT NULL,
 notification_prepared_at DATETIME NULL, access_token_hash CHAR(64) NULL, access_expires_at DATETIME NULL, access_invalidated_at DATETIME NULL,
 notification_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0, last_notification_at DATETIME NULL,
 notification_delivery_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending', notification_delivery_updated_at DATETIME NULL,
 resolved_by INT NULL, resolved_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(impacto_id,unidad_id) REFERENCES horario_impactos(id,unidad_id) ON DELETE RESTRICT, FOREIGN KEY(reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(resolved_by) REFERENCES usuarios(id) ON DELETE SET NULL, UNIQUE KEY uq_horario_impacto_reservacion(impacto_id,reservacion_id),
 INDEX idx_horario_impacto_res_estado(impacto_id,estado), INDEX idx_horario_impacto_access(access_token_hash,access_expires_at), INDEX idx_horario_impacto_delivery(notification_delivery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE reservacion_recordatorios (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, reservacion_id INT NOT NULL, reservacion_raiz_id INT NOT NULL,
 tipo ENUM('dia_anterior') NOT NULL DEFAULT 'dia_anterior', dedup_key VARCHAR(191) NOT NULL, access_token_hash CHAR(64) NULL,
 access_expires_at DATETIME NULL, access_invalidated_at DATETIME NULL, notification_delivery_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending',
 notification_delivery_updated_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, notification_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
 transport_claimed_at DATETIME NULL, retryable TINYINT(1) NOT NULL DEFAULT 0, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(reservacion_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(reservacion_raiz_id,unidad_id) REFERENCES reservaciones(id,unidad_id) ON DELETE RESTRICT,
 UNIQUE KEY uq_recordatorio_dedup(unidad_id,dedup_key), UNIQUE KEY uq_recordatorio_access(access_token_hash), INDEX idx_recordatorio_reservacion(reservacion_id), INDEX idx_recordatorio_raiz(reservacion_raiz_id), INDEX idx_recordatorio_delivery(notification_delivery_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE buzon_notificaciones (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, tipo VARCHAR(80) NOT NULL, modulo VARCHAR(60) NOT NULL,
 entidad_tipo VARCHAR(80) NOT NULL, entidad_id INT UNSIGNED NOT NULL, prioridad ENUM('normal','alta') NOT NULL DEFAULT 'normal',
 requiere_accion TINYINT(1) NOT NULL DEFAULT 1, visible_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, leida_at DATETIME NULL,
 cerrada_at DATETIME NULL, cerrada_por INT NULL, cierre_motivo VARCHAR(120) NULL, dedup_key VARCHAR(191) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(cerrada_por) REFERENCES usuarios(id) ON DELETE SET NULL,
 UNIQUE KEY uq_buzon_dedup(unidad_id,dedup_key), INDEX idx_buzon_visibles(unidad_id,cerrada_at,visible_from), INDEX idx_buzon_tipo(unidad_id,tipo,cerrada_at), INDEX idx_buzon_accion(unidad_id,cerrada_at,visible_from,requiere_accion), INDEX idx_buzon_entidad(unidad_id,entidad_tipo,entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE configuracion_anuncio (
 unidad_id TINYINT UNSIGNED NOT NULL, id TINYINT UNSIGNED NOT NULL, mensaje VARCHAR(255) NOT NULL DEFAULT '',
 tipo ENUM('evento','promocion','novedad_menu','aviso_operativo') NOT NULL DEFAULT 'evento', activo TINYINT(1) NOT NULL DEFAULT 0,
 fecha_inicio DATETIME NULL, fecha_fin DATETIME NULL, texto_enlace VARCHAR(80) NULL, url_enlace VARCHAR(500) NULL,
 updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(unidad_id,id), FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE configuracion_pos (
 unidad_id TINYINT UNSIGNED NOT NULL, id TINYINT UNSIGNED NOT NULL, mesero_editable TINYINT(1) NOT NULL DEFAULT 1,
 impresion_activa TINYINT(1) NOT NULL DEFAULT 1, max_tickets_barra TINYINT UNSIGNED NOT NULL DEFAULT 4, updated_by INT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(unidad_id,id),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(updated_by) REFERENCES usuarios(id) ON DELETE SET NULL,
 CHECK(max_tickets_barra BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE configuracion_paleta (
 unidad_id TINYINT UNSIGNED NOT NULL, id TINYINT UNSIGNED NOT NULL, verde CHAR(7) NULL, oro CHAR(7) NULL, beige CHAR(7) NULL, crema CHAR(7) NULL,
 updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(unidad_id,id),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE configuracion_reservaciones (
 unidad_id TINYINT UNSIGNED NOT NULL, id TINYINT UNSIGNED NOT NULL, recordatorio_dia_anterior_activo TINYINT(1) NOT NULL DEFAULT 0,
 hora_recordatorio TIME NOT NULL DEFAULT '18:00:00', max_comensales_publico TINYINT UNSIGNED NOT NULL DEFAULT 12, whatsapp_reservas VARCHAR(20) NULL,
 updated_by INT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY(unidad_id,id),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(updated_by) REFERENCES usuarios(id) ON DELETE SET NULL,
 CHECK(max_comensales_publico BETWEEN 7 AND 16)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE reportes_sistema (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, usuario_id INT NULL, modulo VARCHAR(60) NULL,
 titulo VARCHAR(120) NOT NULL, descripcion TEXT NOT NULL, ruta_origen VARCHAR(255) NULL,
 navegador ENUM('chrome','edge','firefox','safari','otro') NULL, navegador_otro VARCHAR(80) NULL,
 estado ENUM('nuevo','en_revision','resuelto','descartado') NOT NULL DEFAULT 'nuevo', resuelto_at DATETIME NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT, FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 INDEX idx_reportes_estado_fecha(unidad_id,estado,created_at), INDEX idx_reportes_modulo(unidad_id,modulo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cada configuración semanal/servicio crea su propia fila por unidad.
-- Marca de estructura terminada; la autorización 3B se completa con deploy.sql.
INSERT INTO esquema_versiones(version) VALUES ('multinegocio-3c-v1');
