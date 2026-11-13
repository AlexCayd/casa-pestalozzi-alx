-- Etapa 1: precios de referencia ofrecidos por proveedores.
-- Aditiva y repetible. No reconstruye fechas ni actores históricos desde costos
-- de inventario; las asociaciones antiguas se observan cuando se guarden después.
CREATE TABLE IF NOT EXISTS historial_precios_proveedores (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL,
 proveedor_id INT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL,
 proveedor_nombre_snapshot VARCHAR(120) NOT NULL, articulo_nombre_snapshot VARCHAR(120) NOT NULL,
 unidad_base_snapshot VARCHAR(40) NOT NULL,
 precio_anterior DECIMAL(10,4) NULL, precio_nuevo DECIMAL(10,4) NOT NULL,
 tipo ENUM('inicial','base_observada','cambio') NOT NULL,
 usuario_id INT NULL, usuario_nombre_snapshot VARCHAR(120) NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_hist_prov_art_fecha(unidad_id,proveedor_id,articulo_id,created_at,id),
 FOREIGN KEY(unidad_id) REFERENCES unidades(id) ON DELETE RESTRICT,
 FOREIGN KEY(proveedor_id,unidad_id) REFERENCES proveedores(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(articulo_id) REFERENCES ingredientes(id) ON DELETE RESTRICT,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
 CHECK(precio_nuevo>=0), CHECK(precio_anterior IS NULL OR precio_anterior>=0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO esquema_versiones(version) VALUES ('etapa1-precios-proveedores');
