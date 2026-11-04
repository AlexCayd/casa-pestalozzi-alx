-- M3 · Fase 1: tipo de venta por oferta y contenido por pieza del artículo.
-- Aditivo y repetible. Aplicar después de M2 con respaldo verificado.
-- No toca precio, receta, presentación, disponibilidad, saldos ni ledger.
-- Contrato: docs/arquitectura.md §16.

-- El estado por omisión es «sugerido»: toda oferta existente queda pendiente de
-- revisión humana. El panel guarda «confirmado» al crear o editar una oferta.
SET @m3_tipo := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ofertas_producto' AND COLUMN_NAME='tipo_venta');
SET @m3_sql := IF(@m3_tipo=0,
  'ALTER TABLE ofertas_producto ADD COLUMN tipo_venta ENUM(''preparado'',''reventa'') NOT NULL DEFAULT ''preparado'', ADD COLUMN tipo_venta_estado ENUM(''sugerido'',''confirmado'') NOT NULL DEFAULT ''sugerido'', ADD INDEX idx_oferta_tipo_venta(unidad_id,tipo_venta_estado,tipo_venta)',
  'DO 0');
PREPARE m3_stmt FROM @m3_sql; EXECUTE m3_stmt; DEALLOCATE PREPARE m3_stmt;

-- Una botella de 750 ml: el saldo vive en ml y la pieza es sólo captura/lectura.
SET @m3_pieza := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ingredientes' AND COLUMN_NAME='contenido_pieza');
SET @m3_sql := IF(@m3_pieza=0,
  'ALTER TABLE ingredientes ADD COLUMN contenido_pieza DECIMAL(12,3) NULL AFTER unidad, ADD CONSTRAINT chk_ingrediente_contenido_pieza CHECK(contenido_pieza IS NULL OR (contenido_pieza>0 AND unidad<>''pza''))',
  'DO 0');
PREPARE m3_stmt FROM @m3_sql; EXECUTE m3_stmt; DEALLOCATE PREPARE m3_stmt;

-- Sugeridor (misma sentencia que Services\Menu\TipoVenta::SQL_SUGERIR).
-- Sólo toca ofertas en «sugerido»; repetirlo no cambia una decisión confirmada.
UPDATE ofertas_producto o SET o.tipo_venta=IF(NOT EXISTS(SELECT 1 FROM variante_ajustes va WHERE va.oferta_id=o.id AND va.unidad_id=o.unidad_id) AND (CASE WHEN EXISTS(SELECT 1 FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 WHERE p.oferta_id=o.id AND p.unidad_id=o.unidad_id) THEN NOT EXISTS(SELECT 1 FROM oferta_presentaciones p JOIN presentacion_versiones v ON v.presentacion_id=p.id AND v.publicada=1 AND v.version=(SELECT MAX(v2.version) FROM presentacion_versiones v2 WHERE v2.presentacion_id=p.id AND v2.publicada=1) WHERE p.oferta_id=o.id AND p.unidad_id=o.unidad_id AND ((SELECT COUNT(*) FROM presentacion_componentes c WHERE c.version_id=v.id)<>1 OR NOT EXISTS(SELECT 1 FROM presentacion_componentes c WHERE c.version_id=v.id AND c.modalidad='almacenado' AND c.etapa='servicio' AND c.unidad='pza' AND c.cantidad=1))) WHEN EXISTS(SELECT 1 FROM recetas r WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1) THEN (SELECT COUNT(*) FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1)=1 AND EXISTS(SELECT 1 FROM recetas r JOIN receta_componentes rc ON rc.receta_id=r.id AND rc.unidad_id=r.unidad_id JOIN ingredientes i ON i.id=rc.articulo_id LEFT JOIN receta_componentes_articulos ca ON ca.componente_id=rc.id WHERE r.oferta_id=o.id AND r.unidad_id=o.unidad_id AND r.activo=1 AND rc.subreceta_id IS NULL AND ca.componente_id IS NULL AND i.unidad='pza' AND rc.cantidad=1) ELSE 0 END),'reventa','preparado') WHERE o.tipo_venta_estado='sugerido';

INSERT IGNORE INTO esquema_versiones(version) VALUES ('m3-tipo-venta');
