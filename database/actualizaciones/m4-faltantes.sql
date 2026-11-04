-- M3 · Fase 2: faltantes de venta, conciliación de origen y retiro de negativos.
-- Aditivo y repetible. Aplicar después de m3-tipo-venta.sql con respaldo verificado.
-- Contrato: docs/arquitectura.md §16. Ningún saldo queda negativo.

CREATE TABLE IF NOT EXISTS faltantes_inventario (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL,
 almacen_id BIGINT UNSIGNED NOT NULL, articulo_id INT UNSIGNED NOT NULL,
 ticket_item_id INT UNSIGNED NULL, operacion_id BIGINT UNSIGNED NOT NULL,
 origen ENUM('venta','migracion') NOT NULL,
 cantidad DECIMAL(12,3) NOT NULL, cantidad_conciliada DECIMAL(12,3) NOT NULL DEFAULT 0,
 cantidad_en_confirmacion DECIMAL(12,3) NOT NULL DEFAULT 0,
 estado ENUM('pendiente','en_confirmacion','conciliado','anulado') NOT NULL DEFAULT 'pendiente',
 version BIGINT UNSIGNED NOT NULL DEFAULT 0,
 creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, actualizado_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_faltante_item(ticket_item_id,almacen_id,articulo_id), UNIQUE KEY uq_faltante_scope(id,unidad_id),
 INDEX idx_faltante_estado(unidad_id,estado,creado_at), INDEX idx_faltante_articulo(articulo_id,estado),
 FOREIGN KEY(almacen_id,articulo_id,unidad_id) REFERENCES almacen_articulos(almacen_id,articulo_id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(ticket_item_id,unidad_id) REFERENCES ticket_items(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(operacion_id,unidad_id) REFERENCES operaciones_inventario(id,unidad_id) ON DELETE RESTRICT,
 CHECK(cantidad>0 AND cantidad_conciliada>=0 AND cantidad_en_confirmacion>=0 AND cantidad_conciliada+cantidad_en_confirmacion<=cantidad),
 CHECK((origen='venta' AND ticket_item_id IS NOT NULL) OR (origen='migracion' AND ticket_item_id IS NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @m4_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='transferencias' AND COLUMN_NAME='tipo');
SET @m4_sql := IF(@m4_col=0,'ALTER TABLE transferencias ADD COLUMN tipo ENUM(''ordinaria'',''regularizacion'') NOT NULL DEFAULT ''ordinaria'' AFTER id','DO 0');
PREPARE m4_stmt FROM @m4_sql; EXECUTE m4_stmt; DEALLOCATE PREPARE m4_stmt;

CREATE TABLE IF NOT EXISTS faltante_asignaciones (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, faltante_id BIGINT UNSIGNED NOT NULL,
 almacen_origen_id BIGINT UNSIGNED NOT NULL, cantidad DECIMAL(12,3) NOT NULL,
 transferencia_id BIGINT UNSIGNED NULL,
 estado ENUM('aplicada','en_confirmacion','rechazada','anulada') NOT NULL,
 motivo VARCHAR(255) NULL, actor_id INT NOT NULL,
 creado_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, resuelto_at DATETIME NULL,
 UNIQUE KEY uq_asignacion_transferencia(transferencia_id), INDEX idx_asignacion_faltante(faltante_id,estado),
 FOREIGN KEY(faltante_id) REFERENCES faltantes_inventario(id) ON DELETE RESTRICT,
 FOREIGN KEY(almacen_origen_id) REFERENCES almacenes(id) ON DELETE RESTRICT,
 FOREIGN KEY(transferencia_id) REFERENCES transferencias(id) ON DELETE RESTRICT,
 FOREIGN KEY(actor_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
 CHECK(cantidad>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @m4_col := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='movimientos_inventario' AND COLUMN_NAME='faltante_id');
SET @m4_sql := IF(@m4_col=0,'ALTER TABLE movimientos_inventario ADD COLUMN faltante_id BIGINT UNSIGNED NULL, ADD CONSTRAINT fk_mov_faltante FOREIGN KEY(faltante_id) REFERENCES faltantes_inventario(id) ON DELETE RESTRICT','DO 0');
PREPARE m4_stmt FROM @m4_sql; EXECUTE m4_stmt; DEALLOCATE PREPARE m4_stmt;

-- Retiro de negativos, en este orden (el trigger trg_almacen_no_desactivar_negativos
-- impide apagar la política con saldos negativos pendientes):
-- 1) una operación automatizada por saldo negativo;
INSERT IGNORE INTO operaciones_inventario(unidad_id,tipo,actor_tipo,usuario_id,origen,clave_idempotencia,hash_solicitud,referencia,resultado)
SELECT aa.unidad_id,'ajuste','automatizado',NULL,'migracion_faltantes',CONCAT('migracion-faltantes-',aa.almacen_id,'-',aa.articulo_id),
 SHA2(CONCAT('migracion-faltantes|',aa.almacen_id,'|',aa.articulo_id,'|',aa.saldo),256),CONCAT('migracion-faltantes-',aa.almacen_id,'-',aa.articulo_id),
 JSON_OBJECT('codigo','MIGRACION_FALTANTES','faltante',CAST(-aa.saldo AS CHAR))
FROM almacen_articulos aa WHERE aa.saldo<0;
-- 2) el faltante pendiente por esa cantidad (sin ticket, origen migración);
INSERT INTO faltantes_inventario(unidad_id,almacen_id,articulo_id,ticket_item_id,operacion_id,origen,cantidad)
SELECT aa.unidad_id,aa.almacen_id,aa.articulo_id,NULL,o.id,'migracion',-aa.saldo
FROM almacen_articulos aa JOIN operaciones_inventario o ON o.unidad_id=aa.unidad_id AND o.origen='migracion_faltantes' AND o.clave_idempotencia=CONCAT('migracion-faltantes-',aa.almacen_id,'-',aa.articulo_id)
WHERE aa.saldo<0 AND NOT EXISTS(SELECT 1 FROM faltantes_inventario f WHERE f.operacion_id=o.id);
-- 3) el ajuste a 0 con motivo migracion_faltantes, trazable en el ledger;
INSERT INTO movimientos_inventario(operacion_id,unidad_id,almacen_id,articulo_id,tipo,delta,motivo,nota,costo_unitario)
SELECT o.id,aa.unidad_id,aa.almacen_id,aa.articulo_id,'ajuste',-aa.saldo,'migracion_faltantes','Saldo negativo convertido en faltante pendiente de origen',i.costo
FROM almacen_articulos aa JOIN ingredientes i ON i.id=aa.articulo_id
JOIN operaciones_inventario o ON o.unidad_id=aa.unidad_id AND o.origen='migracion_faltantes' AND o.clave_idempotencia=CONCAT('migracion-faltantes-',aa.almacen_id,'-',aa.articulo_id)
WHERE aa.saldo<0;
UPDATE almacen_articulos SET saldo=0,version=version+1 WHERE saldo<0;
-- 4) aviso de buzón por almacén + artículo para conciliar;
INSERT INTO buzon_notificaciones(unidad_id,tipo,modulo,entidad_tipo,entidad_id,prioridad,requiere_accion,dedup_key)
SELECT f.unidad_id,'inventario.faltante','inventario','faltante_inventario',f.id,'alta',1,CONCAT('inventario.faltante:',f.almacen_id,':',f.articulo_id,':',DATE_FORMAT(f.creado_at,'%Y-%m-%d'))
FROM faltantes_inventario f WHERE f.origen='migracion' AND f.estado='pendiente'
ON DUPLICATE KEY UPDATE cerrada_at=NULL,cerrada_por=NULL,cierre_motivo=NULL,leida_at=NULL;
-- 5) después, ningún almacén admite negativos y un CHECK lo fija (la columna se
--    conserva por compatibilidad).
UPDATE almacenes SET permitir_negativos=0 WHERE permitir_negativos<>0;
SET @m4_chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='almacenes' AND CONSTRAINT_NAME='chk_almacen_sin_negativos');
SET @m4_sql := IF(@m4_chk=0,'ALTER TABLE almacenes ADD CONSTRAINT chk_almacen_sin_negativos CHECK(permitir_negativos=0)','DO 0');
PREPARE m4_stmt FROM @m4_sql; EXECUTE m4_stmt; DEALLOCATE PREPARE m4_stmt;

INSERT IGNORE INTO esquema_versiones(version) VALUES ('m4-faltantes');
