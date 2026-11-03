-- A1: materializa por oferta la proyección comercial vigente de productos_oferta.
-- Ejecutar después de que exista productos_oferta (esquema M1.2).
CREATE TABLE IF NOT EXISTS oferta_datos_comerciales (
 oferta_id INT UNSIGNED NOT NULL,
 nombre VARCHAR(120) NOT NULL,
 descripcion TEXT NULL,
 img VARCHAR(255) NULL,
 PRIMARY KEY (oferta_id),
 CONSTRAINT fk_oferta_datos_comerciales_oferta
  FOREIGN KEY (oferta_id) REFERENCES ofertas_producto(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- El no-op ante conflicto deja intacta toda fila ya materializada, incluso sus NULL.
INSERT INTO oferta_datos_comerciales (oferta_id,nombre,descripcion,img)
SELECT v.oferta_id,v.nombre,v.descripcion,v.img
FROM productos_oferta v
ON DUPLICATE KEY UPDATE oferta_id=oferta_datos_comerciales.oferta_id;

INSERT IGNORE INTO esquema_versiones(version) VALUES ('a1-datos-comerciales-oferta-v1');
