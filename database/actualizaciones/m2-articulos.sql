-- M2 aditivo. Aplicar después de M1.2 con respaldo verificado; nunca development.sql.
-- ingredientes conserva identidad y FK físicas. No se modifica ningún saldo/histórico.
CREATE TABLE IF NOT EXISTS articulo_configuraciones (
 articulo_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 comprable TINYINT(1) NOT NULL DEFAULT 0, componente TINYINT(1) NOT NULL DEFAULT 1,
 elaborable TINYINT(1) NOT NULL DEFAULT 0, almacenable TINYINT(1) NOT NULL DEFAULT 0,
 produccion_previa TINYINT(1) NOT NULL DEFAULT 0, costo_conocido TINYINT(1) NOT NULL DEFAULT 0, activo TINYINT(1) NOT NULL DEFAULT 1,
 revision INT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(articulo_id,unidad_id),
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
 CHECK(comprable IN(0,1) AND componente IN(0,1) AND elaborable IN(0,1) AND almacenable IN(0,1) AND costo_conocido IN(0,1) AND activo IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS articulo_recetas (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, articulo_id INT UNSIGNED NOT NULL,
 unidad_id TINYINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 rendimiento DECIMAL(12,3) NOT NULL, unidad ENUM('g','kg','ml','l','pza') NOT NULL,
 procedencia VARCHAR(160) NOT NULL, publicada TINYINT(1) NOT NULL DEFAULT 0,
 usuario_id INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_ar_version(articulo_id,unidad_id,version), UNIQUE KEY uq_ar_articulo(id,articulo_id),
 UNIQUE KEY uq_ar_unidad(id,unidad_id),
 FOREIGN KEY(articulo_id,unidad_id) REFERENCES articulo_configuraciones(articulo_id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
 CHECK(rendimiento>0 AND version>0 AND publicada IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS articulo_receta_componentes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, receta_id BIGINT UNSIGNED NOT NULL,
 articulo_id INT UNSIGNED NOT NULL, cantidad DECIMAL(12,3) NOT NULL,
 unidad ENUM('g','kg','ml','l','pza') NOT NULL,
 modalidad ENUM('almacenado','desglosar') NOT NULL, version_receta_id BIGINT UNSIGNED NULL,
 merma DECIMAL(6,3) NOT NULL DEFAULT 0,
 FOREIGN KEY(receta_id) REFERENCES articulo_recetas(id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(version_receta_id,articulo_id) REFERENCES articulo_recetas(id,articulo_id) ON DELETE RESTRICT,
 CHECK(cantidad>0 AND merma>=0 AND merma<100),
 CHECK((modalidad='desglosar' AND version_receta_id IS NOT NULL) OR (modalidad='almacenado' AND version_receta_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS oferta_presentaciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, oferta_id INT UNSIGNED NOT NULL,
 unidad_id TINYINT UNSIGNED NOT NULL, clave VARCHAR(40) NOT NULL, nombre VARCHAR(120) NOT NULL,
 activo TINYINT(1) NOT NULL DEFAULT 0, revision INT UNSIGNED NOT NULL DEFAULT 0,
 UNIQUE KEY uq_presentacion_clave(oferta_id,clave), UNIQUE KEY uq_presentacion_scope(id,oferta_id,unidad_id),
 FOREIGN KEY(oferta_id,unidad_id) REFERENCES ofertas_producto(id,unidad_id) ON DELETE RESTRICT,
 CHECK(activo IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS presentacion_versiones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, presentacion_id BIGINT UNSIGNED NOT NULL,
 oferta_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 precio DECIMAL(8,2) NOT NULL, publicada TINYINT(1) NOT NULL DEFAULT 0,
 usuario_id INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_pv_version(presentacion_id,version), UNIQUE KEY uq_pv_scope(id,oferta_id,unidad_id),
 FOREIGN KEY(presentacion_id,oferta_id,unidad_id) REFERENCES oferta_presentaciones(id,oferta_id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
 CHECK(precio>=0 AND version>0 AND publicada IN(0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS presentacion_componentes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, version_id BIGINT UNSIGNED NOT NULL,
 articulo_id INT UNSIGNED NOT NULL, cantidad DECIMAL(12,3) NOT NULL,
 unidad ENUM('g','kg','ml','l','pza') NOT NULL, modalidad ENUM('almacenado','desglosar') NOT NULL,
 version_receta_id BIGINT UNSIGNED NULL,
 etapa ENUM('servicio','produccion') NOT NULL DEFAULT 'servicio',
 FOREIGN KEY(version_id) REFERENCES presentacion_versiones(id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(version_receta_id,articulo_id) REFERENCES articulo_recetas(id,articulo_id) ON DELETE RESTRICT,
 CHECK(cantidad>0),
 CHECK((modalidad='desglosar' AND version_receta_id IS NOT NULL) OR (modalidad='almacenado' AND version_receta_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS articulo_correspondencias (
 subreceta_id INT UNSIGNED PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL,
 articulo_id INT UNSIGNED NOT NULL, UNIQUE KEY uq_ac_articulo(articulo_id,unidad_id),
 FOREIGN KEY(subreceta_id,unidad_id) REFERENCES subrecetas(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ticket_item_resoluciones (
 ticket_item_id INT UNSIGNED PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL,
 oferta_id INT UNSIGNED NOT NULL, presentacion_version_id BIGINT UNSIGNED NULL,
 snapshot JSON NOT NULL, huella CHAR(64) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(ticket_item_id) REFERENCES ticket_items(id) ON DELETE RESTRICT,
 FOREIGN KEY(oferta_id,unidad_id) REFERENCES ofertas_producto(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(presentacion_version_id,oferta_id,unidad_id) REFERENCES presentacion_versiones(id,oferta_id,unidad_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS articulo_comandos (
 unidad_id TINYINT UNSIGNED NOT NULL, clave VARCHAR(80) NOT NULL, huella CHAR(64) NOT NULL,
 resultado JSON NOT NULL, PRIMARY KEY(unidad_id,clave),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER //
DROP TRIGGER IF EXISTS m2_ar_update//
CREATE TRIGGER m2_ar_update BEFORE UPDATE ON articulo_recetas FOR EACH ROW
BEGIN IF OLD.publicada=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La receta publicada es inmutable'; END IF; END//
DROP TRIGGER IF EXISTS m2_ar_delete//
CREATE TRIGGER m2_ar_delete BEFORE DELETE ON articulo_recetas FOR EACH ROW
BEGIN IF OLD.publicada=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La receta publicada es histórica'; END IF; END//
DROP TRIGGER IF EXISTS m2_arc_insert//
CREATE TRIGGER m2_arc_insert BEFORE INSERT ON articulo_receta_componentes FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM articulo_recetas WHERE id=NEW.receta_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición publicada inmutable'; END IF;
 IF NEW.version_receta_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM articulo_recetas h JOIN unidades u ON u.id=h.unidad_id JOIN articulo_recetas p ON p.id=NEW.receta_id WHERE h.id=NEW.version_receta_id AND h.publicada=1 AND (h.unidad_id=p.unidad_id OR u.slug='global')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Versión de preparación fuera de ámbito'; END IF;
END//
DROP TRIGGER IF EXISTS m2_arc_update//
CREATE TRIGGER m2_arc_update BEFORE UPDATE ON articulo_receta_componentes FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM articulo_recetas WHERE id IN(OLD.receta_id,NEW.receta_id) AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición publicada inmutable'; END IF; END//
DROP TRIGGER IF EXISTS m2_arc_delete//
CREATE TRIGGER m2_arc_delete BEFORE DELETE ON articulo_receta_componentes FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM articulo_recetas WHERE id=OLD.receta_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición publicada histórica'; END IF; END//
DROP TRIGGER IF EXISTS m2_pv_update//
CREATE TRIGGER m2_pv_update BEFORE UPDATE ON presentacion_versiones FOR EACH ROW
BEGIN IF OLD.publicada=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Presentación publicada inmutable'; END IF; END//
DROP TRIGGER IF EXISTS m2_pv_delete//
CREATE TRIGGER m2_pv_delete BEFORE DELETE ON presentacion_versiones FOR EACH ROW
BEGIN IF OLD.publicada=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Presentación publicada histórica'; END IF; END//
DROP TRIGGER IF EXISTS m2_pc_insert//
CREATE TRIGGER m2_pc_insert BEFORE INSERT ON presentacion_componentes FOR EACH ROW
BEGIN
 IF EXISTS(SELECT 1 FROM presentacion_versiones WHERE id=NEW.version_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición comercial inmutable'; END IF;
 IF NEW.version_receta_id IS NOT NULL AND NOT EXISTS(SELECT 1 FROM articulo_recetas h JOIN unidades u ON u.id=h.unidad_id JOIN presentacion_versiones p ON p.id=NEW.version_id WHERE h.id=NEW.version_receta_id AND h.publicada=1 AND (h.unidad_id=p.unidad_id OR u.slug='global')) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Elaboración fuera del negocio de la presentación'; END IF;
END//
DROP TRIGGER IF EXISTS m2_pc_update//
CREATE TRIGGER m2_pc_update BEFORE UPDATE ON presentacion_componentes FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM presentacion_versiones WHERE id IN(OLD.version_id,NEW.version_id) AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición comercial inmutable'; END IF; END//
DROP TRIGGER IF EXISTS m2_pc_delete//
CREATE TRIGGER m2_pc_delete BEFORE DELETE ON presentacion_componentes FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM presentacion_versiones WHERE id=OLD.version_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición comercial histórica'; END IF; END//
DROP TRIGGER IF EXISTS m2_snapshot_update//
CREATE TRIGGER m2_snapshot_update BEFORE UPDATE ON ticket_item_resoluciones FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Resolución histórica inmutable'; END//
DROP TRIGGER IF EXISTS m2_snapshot_delete//
CREATE TRIGGER m2_snapshot_delete BEFORE DELETE ON ticket_item_resoluciones FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Resolución histórica inmutable'; END//
DELIMITER ;
INSERT IGNORE INTO esquema_versiones(version) VALUES('m2-articulos');
-- La modalidad del artículo que entra por una variante es explícita y versionada.
DELIMITER //
DROP PROCEDURE IF EXISTS m2_ampliar_variantes//
CREATE PROCEDURE m2_ampliar_variantes()
BEGIN
 IF NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='variante_ajustes' AND COLUMN_NAME='modalidad_destino') THEN
  ALTER TABLE variante_ajustes ADD modalidad_destino ENUM('almacenado','desglosar') NOT NULL DEFAULT 'almacenado', ADD receta_destino_id BIGINT UNSIGNED NULL,
   ADD CONSTRAINT fk_va_receta_destino FOREIGN KEY(receta_destino_id) REFERENCES articulo_recetas(id) ON DELETE RESTRICT,
   ADD CONSTRAINT ck_va_modalidad_destino CHECK((modalidad_destino='almacenado' AND receta_destino_id IS NULL) OR (modalidad_destino='desglosar' AND receta_destino_id IS NOT NULL));
 END IF;
END//
CALL m2_ampliar_variantes()//
DROP PROCEDURE m2_ampliar_variantes//
DELIMITER ;
CREATE TABLE IF NOT EXISTS receta_componentes_articulos (
 componente_id INT UNSIGNED PRIMARY KEY, articulo_id INT UNSIGNED NOT NULL, version_receta_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(componente_id) REFERENCES receta_componentes(id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(version_receta_id,articulo_id) REFERENCES articulo_recetas(id,articulo_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER //
DROP TRIGGER IF EXISTS m2_sub_update//
CREATE TRIGGER m2_sub_update BEFORE UPDATE ON subrecetas FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM articulo_correspondencias WHERE subreceta_id=OLD.id AND unidad_id=OLD.unidad_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='La preparación se edita desde su artículo'; END IF; END//
DROP TRIGGER IF EXISTS m2_si_insert//
CREATE TRIGGER m2_si_insert BEFORE INSERT ON subreceta_ingredientes FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM articulo_correspondencias WHERE subreceta_id=NEW.subreceta_id AND unidad_id=NEW.unidad_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición migrada a artículos'; END IF; END//
DROP TRIGGER IF EXISTS m2_si_update//
CREATE TRIGGER m2_si_update BEFORE UPDATE ON subreceta_ingredientes FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM articulo_correspondencias WHERE subreceta_id IN(OLD.subreceta_id,NEW.subreceta_id) AND unidad_id IN(OLD.unidad_id,NEW.unidad_id)) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición migrada a artículos'; END IF; END//
DROP TRIGGER IF EXISTS m2_si_delete//
CREATE TRIGGER m2_si_delete BEFORE DELETE ON subreceta_ingredientes FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM articulo_correspondencias WHERE subreceta_id=OLD.subreceta_id AND unidad_id=OLD.unidad_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición migrada histórica'; END IF; END//
DELIMITER ;

DELIMITER //
DROP PROCEDURE IF EXISTS m2_configurar_produccion_previa//
CREATE PROCEDURE m2_configurar_produccion_previa()
BEGIN
 IF NOT EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='articulo_configuraciones' AND COLUMN_NAME='produccion_previa') THEN
  ALTER TABLE articulo_configuraciones ADD produccion_previa TINYINT(1) NOT NULL DEFAULT 0;
 END IF;
END//
CALL m2_configurar_produccion_previa()//
DROP PROCEDURE m2_configurar_produccion_previa//
DROP TRIGGER IF EXISTS m2_snapshot_insert//
CREATE TRIGGER m2_snapshot_insert BEFORE INSERT ON ticket_item_resoluciones FOR EACH ROW
BEGIN IF NOT EXISTS(SELECT 1 FROM ticket_items WHERE id=NEW.ticket_item_id AND unidad_id=NEW.unidad_id AND oferta_id=NEW.oferta_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Resolución fuera del negocio de la partida'; END IF; END//
DELIMITER ;
CREATE TABLE IF NOT EXISTS receta_casa_composiciones (
 version_id BIGINT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL, version_receta_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(version_id,articulo_id),
 FOREIGN KEY(version_id,articulo_id) REFERENCES receta_casa_componentes(version_id,articulo_id) ON DELETE RESTRICT,
 FOREIGN KEY(version_receta_id,articulo_id) REFERENCES articulo_recetas(id,articulo_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER //
DROP TRIGGER IF EXISTS m2_casa_composicion_insert//
CREATE TRIGGER m2_casa_composicion_insert BEFORE INSERT ON receta_casa_composiciones FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM receta_casa_versiones WHERE id=NEW.version_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición estándar publicada inmutable'; END IF; END//
DROP TRIGGER IF EXISTS m2_casa_composicion_update//
CREATE TRIGGER m2_casa_composicion_update BEFORE UPDATE ON receta_casa_composiciones FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición estándar inmutable'; END//
DROP TRIGGER IF EXISTS m2_casa_composicion_delete//
CREATE TRIGGER m2_casa_composicion_delete BEFORE DELETE ON receta_casa_composiciones FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM receta_casa_versiones WHERE id=OLD.version_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición estándar histórica'; END IF; END//
DELIMITER ;
CREATE TABLE IF NOT EXISTS presentacion_estandares (
 version_id BIGINT UNSIGNED PRIMARY KEY, publicacion_producto_id BIGINT UNSIGNED NOT NULL, configuracion JSON NOT NULL,
 FOREIGN KEY(version_id) REFERENCES presentacion_versiones(id) ON DELETE RESTRICT,
 FOREIGN KEY(publicacion_producto_id) REFERENCES producto_publicaciones(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
DELIMITER //
DROP TRIGGER IF EXISTS m2_presentacion_estandar_insert//
CREATE TRIGGER m2_presentacion_estandar_insert BEFORE INSERT ON presentacion_estandares FOR EACH ROW
BEGIN IF EXISTS(SELECT 1 FROM presentacion_versiones WHERE id=NEW.version_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Presentación estándar cerrada'; END IF; END//
DROP TRIGGER IF EXISTS m2_presentacion_estandar_update//
CREATE TRIGGER m2_presentacion_estandar_update BEFORE UPDATE ON presentacion_estandares FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Origen estándar inmutable'; END//
DROP TRIGGER IF EXISTS m2_presentacion_estandar_delete//
CREATE TRIGGER m2_presentacion_estandar_delete BEFORE DELETE ON presentacion_estandares FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Origen estándar histórico'; END//
DELIMITER ;

DELIMITER //
DROP TRIGGER IF EXISTS m2_componente_adoptado_insert//
CREATE TRIGGER m2_componente_adoptado_insert BEFORE INSERT ON receta_componentes_articulos FOR EACH ROW
BEGIN
 IF NOT EXISTS(SELECT 1 FROM receta_componentes rc JOIN articulo_recetas r ON r.id=NEW.version_receta_id JOIN unidades u ON u.id=r.unidad_id WHERE rc.id=NEW.componente_id AND r.publicada=1 AND (r.unidad_id=rc.unidad_id OR u.slug='global') AND (rc.articulo_id=NEW.articulo_id OR EXISTS(SELECT 1 FROM articulo_correspondencias c WHERE c.subreceta_id=rc.subreceta_id AND c.unidad_id=rc.unidad_id AND c.articulo_id=NEW.articulo_id))) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correspondencia de composición no autorizada'; END IF;
END//
DROP TRIGGER IF EXISTS m2_componente_adoptado_update//
CREATE TRIGGER m2_componente_adoptado_update BEFORE UPDATE ON receta_componentes_articulos FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Versión adoptada inmutable'; END//
DROP TRIGGER IF EXISTS m2_componente_adoptado_delete//
CREATE TRIGGER m2_componente_adoptado_delete BEFORE DELETE ON receta_componentes_articulos FOR EACH ROW
BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Versión adoptada histórica'; END//
DELIMITER ;
