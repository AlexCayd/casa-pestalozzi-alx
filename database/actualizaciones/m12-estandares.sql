-- M1.2: ampliación aditiva. Ejecutar antes del código; nunca convierte productos anteriores.
CREATE TABLE IF NOT EXISTS producto_publicaciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, producto_id INT UNSIGNED NOT NULL,
 version INT UNSIGNED NOT NULL, datos JSON NOT NULL, impacto JSON NOT NULL, usuario_id INT NOT NULL,
 clave VARCHAR(100) NOT NULL UNIQUE, solicitud_hash CHAR(64) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_publicacion_producto_version(producto_id,version), UNIQUE KEY uq_publicacion_producto(id,producto_id),
 FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS producto_estandares (
 producto_id INT UNSIGNED PRIMARY KEY, borrador JSON NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
 publicacion_id BIGINT UNSIGNED NULL, clave_creacion VARCHAR(100) NOT NULL UNIQUE,
 creacion_hash CHAR(64) NOT NULL,
 FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE RESTRICT,
 FOREIGN KEY(publicacion_id,producto_id) REFERENCES producto_publicaciones(id,producto_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS oferta_personalizaciones (
 oferta_id INT UNSIGNED NOT NULL, campo ENUM('nombre','descripcion','img','precio','activo','receta') NOT NULL,
 valor JSON NOT NULL, usuario_id INT NOT NULL, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(oferta_id,campo), FOREIGN KEY(oferta_id) REFERENCES ofertas_producto(id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS recetas_casa (
 producto_id INT UNSIGNED PRIMARY KEY, borrador JSON NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(producto_id) REFERENCES productos(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS receta_casa_versiones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, producto_id INT UNSIGNED NOT NULL, version INT UNSIGNED NOT NULL,
 rendimiento DECIMAL(12,3) NOT NULL, unidad ENUM('pza','g','kg','ml','l') NOT NULL DEFAULT 'pza', publicada TINYINT(1) NOT NULL DEFAULT 0,
 usuario_id INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_receta_casa_version(producto_id,version), UNIQUE KEY uq_receta_casa_producto(id,producto_id),
 FOREIGN KEY(producto_id) REFERENCES recetas_casa(producto_id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT, CHECK(rendimiento>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS receta_casa_componentes (
 version_id BIGINT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL, cantidad DECIMAL(12,3) NOT NULL,
 PRIMARY KEY(version_id,articulo_id), FOREIGN KEY(version_id) REFERENCES receta_casa_versiones(id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT, CHECK(cantidad>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS receta_origen_casa (
 receta_id INT UNSIGNED PRIMARY KEY, version_id BIGINT UNSIGNED NOT NULL,
 FOREIGN KEY(receta_id) REFERENCES recetas(id) ON DELETE RESTRICT,
 FOREIGN KEY(version_id) REFERENCES receta_casa_versiones(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS producto_cambios_locales (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, oferta_id INT UNSIGNED NOT NULL, datos JSON NOT NULL,
 usuario_id INT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(oferta_id) REFERENCES ofertas_producto(id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE OR REPLACE VIEW productos_oferta AS
 SELECT p.id,o.id AS oferta_id,p.created_at,p.updated_at,
 CASE WHEN pn.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.nombre'))='true' THEN JSON_UNQUOTE(pn.valor) ELSE p.nombre END AS nombre,
 CASE WHEN pd.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.descripcion'))='true' THEN JSON_UNQUOTE(pd.valor) ELSE p.descripcion END AS descripcion,
 CASE WHEN pi.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.img'))='true' THEN CASE WHEN JSON_TYPE(pi.valor)='NULL' THEN NULL ELSE JSON_UNQUOTE(pi.valor) END ELSE p.img END AS img
 FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id
 LEFT JOIN producto_estandares pe ON pe.producto_id=p.id
 LEFT JOIN producto_publicaciones pp ON pp.id=pe.publicacion_id
 LEFT JOIN oferta_personalizaciones pn ON pn.oferta_id=o.id AND pn.campo='nombre'
 LEFT JOIN oferta_personalizaciones pd ON pd.oferta_id=o.id AND pd.campo='descripcion'
 LEFT JOIN oferta_personalizaciones pi ON pi.oferta_id=o.id AND pi.campo='img';
INSERT IGNORE INTO esquema_versiones(version) VALUES ('m12-estandares-v1');

-- Publicaciones inmutables, también frente a escrituras fuera de los servicios.
CREATE TRIGGER IF NOT EXISTS trg_casa_producto_update BEFORE UPDATE ON producto_publicaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Publicacion inmutable';
CREATE TRIGGER IF NOT EXISTS trg_casa_producto_delete BEFORE DELETE ON producto_publicaciones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Publicacion inmutable';
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_casa_receta_update BEFORE UPDATE ON receta_casa_versiones FOR EACH ROW BEGIN IF OLD.publicada=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Version de receta inmutable'; END IF; END//
DELIMITER ;
CREATE TRIGGER IF NOT EXISTS trg_casa_receta_delete BEFORE DELETE ON receta_casa_versiones FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Version de receta inmutable';
DELIMITER //
CREATE TRIGGER IF NOT EXISTS trg_casa_componente_insert BEFORE INSERT ON receta_casa_componentes FOR EACH ROW BEGIN IF (SELECT publicada FROM receta_casa_versiones WHERE id=NEW.version_id)=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Componentes publicados inmutables'; END IF; END//
DELIMITER ;
CREATE TRIGGER IF NOT EXISTS trg_casa_componente_update BEFORE UPDATE ON receta_casa_componentes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Componentes publicados inmutables';
CREATE TRIGGER IF NOT EXISTS trg_casa_componente_delete BEFORE DELETE ON receta_casa_componentes FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Componentes publicados inmutables';
CREATE TRIGGER IF NOT EXISTS trg_casa_cambio_update BEFORE UPDATE ON producto_cambios_locales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historial inmutable';
CREATE TRIGGER IF NOT EXISTS trg_casa_cambio_delete BEFORE DELETE ON producto_cambios_locales FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Historial inmutable';