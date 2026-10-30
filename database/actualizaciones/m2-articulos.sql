-- M2 aditivo. Aplicar después de M1.2 con respaldo verificado; nunca development.sql.
-- ingredientes conserva identidad y FK físicas. No se modifica ningún saldo/histórico.
CREATE TABLE IF NOT EXISTS articulo_configuraciones (
 articulo_id INT UNSIGNED NOT NULL, unidad_id TINYINT UNSIGNED NOT NULL,
 comprable TINYINT(1) NOT NULL DEFAULT 0, componente TINYINT(1) NOT NULL DEFAULT 1,
 elaborable TINYINT(1) NOT NULL DEFAULT 0, almacenable TINYINT(1) NOT NULL DEFAULT 0,
 costo_conocido TINYINT(1) NOT NULL DEFAULT 0, activo TINYINT(1) NOT NULL DEFAULT 1,
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
BEGIN IF EXISTS(SELECT 1 FROM articulo_recetas WHERE id=NEW.receta_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición publicada inmutable'; END IF; END//
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
BEGIN IF EXISTS(SELECT 1 FROM presentacion_versiones WHERE id=NEW.version_id AND publicada=1) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Composición comercial inmutable'; END IF; END//
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
