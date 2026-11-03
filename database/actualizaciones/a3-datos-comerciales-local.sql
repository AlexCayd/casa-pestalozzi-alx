-- A3: cambia productos_oferta para que lea datos propios de cada oferta.
-- Requiere A1, A2 y el código compatible A3 desplegado antes del corte.
-- Si hay diferencias, la rutina aborta salvo que la persona operadora haya
-- revisado el preflight y ejecute SET @cp_a3_preservar_locales=1.

-- Completa solo ofertas faltantes desde la proyección M1.2. Nunca reemplaza
-- una fila comercial existente, incluso cuando sus valores sean NULL.
INSERT INTO oferta_datos_comerciales(oferta_id,nombre,descripcion,img)
SELECT o.id,
 CASE WHEN pn.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.nombre'))='true' THEN JSON_UNQUOTE(pn.valor) ELSE p.nombre END,
 CASE WHEN pd.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.descripcion'))='true' THEN JSON_UNQUOTE(pd.valor) ELSE p.descripcion END,
 CASE WHEN pi.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.img'))='true' THEN CASE WHEN JSON_TYPE(pi.valor)='NULL' THEN NULL ELSE JSON_UNQUOTE(pi.valor) END ELSE p.img END
FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id
LEFT JOIN producto_estandares pe ON pe.producto_id=p.id
LEFT JOIN producto_publicaciones pp ON pp.id=pe.publicacion_id
LEFT JOIN oferta_personalizaciones pn ON pn.oferta_id=o.id AND pn.campo='nombre'
LEFT JOIN oferta_personalizaciones pd ON pd.oferta_id=o.id AND pd.campo='descripcion'
LEFT JOIN oferta_personalizaciones pi ON pi.oferta_id=o.id AND pi.campo='img'
LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=o.id
WHERE d.oferta_id IS NULL;

DELIMITER //
DROP PROCEDURE IF EXISTS cp_a3_validar_corte//
CREATE PROCEDURE cp_a3_validar_corte()
BEGIN
 DECLARE v_activa INT DEFAULT 0;
 DECLARE v_faltantes INT DEFAULT 0;
 DECLARE v_diferencias INT DEFAULT 0;
 SELECT COUNT(*) INTO v_activa FROM esquema_versiones WHERE version='a3-datos-comerciales-local-v1';
 SELECT COUNT(*) INTO v_faltantes FROM ofertas_producto o
  LEFT JOIN oferta_datos_comerciales d ON d.oferta_id=o.id WHERE d.oferta_id IS NULL;
 IF v_faltantes>0 THEN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A3 abortado: quedan ofertas sin datos comerciales locales.';
 END IF;
 IF v_activa=0 THEN
  SELECT COUNT(*) INTO v_diferencias FROM (
   SELECT o.id,
    CASE WHEN pn.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.nombre'))='true' THEN JSON_UNQUOTE(pn.valor) ELSE p.nombre END AS nombre_legacy,
    CASE WHEN pd.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.descripcion'))='true' THEN JSON_UNQUOTE(pd.valor) ELSE p.descripcion END AS descripcion_legacy,
    CASE WHEN pi.oferta_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(pp.datos,'$.reglas.img'))='true' THEN CASE WHEN JSON_TYPE(pi.valor)='NULL' THEN NULL ELSE JSON_UNQUOTE(pi.valor) END ELSE p.img END AS img_legacy,
    d.nombre,d.descripcion,d.img
   FROM ofertas_producto o JOIN productos p ON p.id=o.producto_id
   JOIN oferta_datos_comerciales d ON d.oferta_id=o.id
   LEFT JOIN producto_estandares pe ON pe.producto_id=p.id
   LEFT JOIN producto_publicaciones pp ON pp.id=pe.publicacion_id
   LEFT JOIN oferta_personalizaciones pn ON pn.oferta_id=o.id AND pn.campo='nombre'
   LEFT JOIN oferta_personalizaciones pd ON pd.oferta_id=o.id AND pd.campo='descripcion'
   LEFT JOIN oferta_personalizaciones pi ON pi.oferta_id=o.id AND pi.campo='img'
  ) legacy
  WHERE NOT (CAST(nombre AS BINARY) <=> CAST(nombre_legacy AS BINARY))
     OR NOT (CAST(descripcion AS BINARY) <=> CAST(descripcion_legacy AS BINARY))
     OR NOT (CAST(img AS BINARY) <=> CAST(img_legacy AS BINARY));
  IF v_diferencias>0 AND COALESCE(@cp_a3_preservar_locales,0)<>1 THEN
   SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='A3 abortado: hay diferencias locales. Revisa el preflight; usa @cp_a3_preservar_locales=1 para conservarlas explícitamente.';
  END IF;
 END IF;
END//
CALL cp_a3_validar_corte()//
DROP PROCEDURE cp_a3_validar_corte//
DELIMITER ;

-- Mismos nombres, orden y tipos LONGTEXT que M1.2. JSON_EXTRACT/UNQUOTE
-- conserva el tipo expuesto por el CASE anterior; CASE también preserva NULL.
-- La fuente es una única fila por oferta, nunca publicaciones globales.
CREATE OR REPLACE VIEW productos_oferta AS
 SELECT p.id,o.id AS oferta_id,p.created_at,p.updated_at,
  JSON_UNQUOTE(JSON_EXTRACT(JSON_OBJECT('valor',d.nombre),'$.valor')) AS nombre,
  CASE WHEN d.descripcion IS NULL THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(JSON_OBJECT('valor',d.descripcion),'$.valor')) END AS descripcion,
  CASE WHEN d.img IS NULL THEN NULL ELSE JSON_UNQUOTE(JSON_EXTRACT(JSON_OBJECT('valor',d.img),'$.valor')) END AS img
 FROM ofertas_producto o
 JOIN productos p ON p.id=o.producto_id
 JOIN oferta_datos_comerciales d ON d.oferta_id=o.id;

INSERT IGNORE INTO esquema_versiones(version) VALUES ('a3-datos-comerciales-local-v1');
