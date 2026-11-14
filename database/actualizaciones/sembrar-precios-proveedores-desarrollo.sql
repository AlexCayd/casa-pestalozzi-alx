-- Carga controlada sólo para una instalación ya existente development/test.
-- Ejecutar en conexión dedicada y cliente abort-on-error (no --force):
-- SET @cp_confirmar_demo_precios='CONFIRMAR_DATOS_SINTETICOS_PRECIOS_DESARROLLO';
-- Si una guardia falla, no continúe; ROLLBACK y cierre la conexión.
DROP TEMPORARY TABLE IF EXISTS _cp_guard_demo_precios;
SET @cp_demo_precios_aprobada=0;
START TRANSACTION;
CREATE TEMPORARY TABLE _cp_guard_demo_precios(ok TINYINT NOT NULL, CONSTRAINT chk_cp_guard_demo_precios CHECK(ok=1)) ENGINE=InnoDB;
-- Los bloqueos serializan la validación con los guardados que usan el mutex por proveedor.
SELECT p.id FROM proveedores p
 JOIN ingrediente_proveedores ip ON ip.proveedor_id=p.id AND ip.unidad_id=p.unidad_id
 WHERE (p.unidad_id=2 AND p.id=1001 AND ip.ingrediente_id=1002)
    OR (p.unidad_id=3 AND p.id=1002 AND ip.ingrediente_id=1001)
    OR (p.unidad_id=1 AND p.id=1003 AND ip.ingrediente_id=1001)
 ORDER BY p.id FOR UPDATE;
INSERT INTO _cp_guard_demo_precios(ok)
SELECT (
 COALESCE(@cp_confirmar_demo_precios,'')='CONFIRMAR_DATOS_SINTETICOS_PRECIOS_DESARROLLO'
 AND EXISTS(SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='historial_precios_proveedores')
 AND EXISTS(SELECT 1 FROM esquema_versiones WHERE version='etapa1-precios-proveedores')
 AND EXISTS(SELECT 1 FROM instalacion_desarrollo WHERE id=1 AND completada=1 AND entorno IN ('development','test'))
 AND NOT EXISTS(SELECT 1 FROM esquema_versiones WHERE version='demo-historial-precios-proveedores-v1')
 AND NOT EXISTS(SELECT 1 FROM historial_precios_proveedores WHERE (unidad_id=2 AND proveedor_id=1001 AND articulo_id=1002) OR (unidad_id=3 AND proveedor_id=1002 AND articulo_id=1001) OR (unidad_id=1 AND proveedor_id=1003 AND articulo_id=1001))
 AND EXISTS(SELECT 1 FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN ingredientes i ON i.id=ip.ingrediente_id WHERE ip.unidad_id=2 AND ip.proveedor_id=1001 AND ip.ingrediente_id=1002 AND ip.costo=0.0150 AND p.nombre='Proveedor Restaurante' AND i.nombre='Leche compartida' AND i.unidad='ml')
 AND EXISTS(SELECT 1 FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN ingredientes i ON i.id=ip.ingrediente_id WHERE ip.unidad_id=3 AND ip.proveedor_id=1002 AND ip.ingrediente_id=1001 AND ip.costo=0.0200 AND p.nombre='Proveedor Panadería' AND i.nombre='Harina compartida' AND i.unidad='g')
 AND EXISTS(SELECT 1 FROM ingrediente_proveedores ip JOIN proveedores p ON p.id=ip.proveedor_id AND p.unidad_id=ip.unidad_id JOIN ingredientes i ON i.id=ip.ingrediente_id WHERE ip.unidad_id=1 AND ip.proveedor_id=1003 AND ip.ingrediente_id=1001 AND ip.costo=0.0200 AND p.nombre='Proveedor Global' AND i.nombre='Harina compartida' AND i.unidad='g')
 AND (SELECT COUNT(*) FROM ingrediente_proveedores WHERE (unidad_id=2 AND proveedor_id=1001 AND ingrediente_id=1002) OR (unidad_id=3 AND proveedor_id=1002 AND ingrediente_id=1001) OR (unidad_id=1 AND proveedor_id=1003 AND ingrediente_id=1001))=3
);
SET @cp_demo_precios_aprobada=COALESCE((SELECT MAX(ok) FROM _cp_guard_demo_precios),0);
-- INICIO FUENTE SQL COMPARTIDA: demo-historial-precios-proveedores-v1
INSERT INTO historial_precios_proveedores (
 unidad_id,proveedor_id,articulo_id,proveedor_nombre_snapshot,articulo_nombre_snapshot,
 unidad_base_snapshot,precio_anterior,precio_nuevo,tipo,usuario_id,usuario_nombre_snapshot,created_at
)
SELECT serie.* FROM (
    SELECT 2 AS unidad_id, 1001 AS proveedor_id, 1002 AS articulo_id, 'Proveedor Restaurante' AS proveedor_snapshot, 'Leche compartida' AS articulo_snapshot, 'ml' AS unidad_snapshot, NULL AS precio_anterior, '0.0142' AS precio_nuevo, 'inicial' AS tipo, NULL AS usuario_id, 'Demostración sintética' AS actor_snapshot, DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 176 DAY) AS created_at
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0142', '0.0145', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 172 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0145', '0.0148', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 168 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0148', '0.0146', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 164 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0146', '0.0140', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 160 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0140', '0.0144', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 156 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0144', '0.0149', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 152 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0149', '0.0152', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 148 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0152', '0.0148', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 144 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0148', '0.0156', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 140 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0156', '0.0154', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 136 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0154', '0.0150', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 132 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0150', '0.0147', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 128 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0147', '0.0143', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 124 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0143', '0.0146', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 120 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0146', '0.0151', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 116 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0151', '0.0158', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 112 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0158', '0.0155', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 108 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0155', '0.0160', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 104 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0160', '0.0157', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 100 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0157', '0.0153', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 96 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0153', '0.0159', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 92 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0159', '0.0163', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 88 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0163', '0.0160', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 84 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0160', '0.0165', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 80 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0165', '0.0161', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 76 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0161', '0.0158', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 72 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0158', '0.0162', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 68 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0162', '0.0167', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 64 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0167', '0.0164', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 60 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0164', '0.0160', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 56 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0160', '0.0157', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 52 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0157', '0.0161', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 48 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0161', '0.0156', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 44 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0156', '0.0153', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 40 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0153', '0.0158', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 36 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0158', '0.0155', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 32 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0155', '0.0159', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 28 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0159', '0.0154', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 24 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0154', '0.0151', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 20 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0151', '0.0156', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 16 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0156', '0.0152', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 12 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0152', '0.0148', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 8 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0148', '0.0153', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 4 DAY)
    UNION ALL
    SELECT 2, 1001, 1002, 'Proveedor Restaurante', 'Leche compartida', 'ml', '0.0153', '0.0150', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 0 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', NULL, '0.0185', 'inicial', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 180 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0185', '0.0190', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 160 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0190', '0.0188', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 140 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0188', '0.0194', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 120 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0194', '0.0200', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 100 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0200', '0.0196', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 80 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0196', '0.0204', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 60 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0204', '0.0202', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 40 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0202', '0.0198', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 20 DAY)
    UNION ALL
    SELECT 3, 1002, 1001, 'Proveedor Panadería', 'Harina compartida', 'g', '0.0198', '0.0200', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 0 DAY)
    UNION ALL
    SELECT 1, 1003, 1001, 'Proveedor Global', 'Harina compartida', 'g', NULL, '0.0192', 'inicial', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 180 DAY)
    UNION ALL
    SELECT 1, 1003, 1001, 'Proveedor Global', 'Harina compartida', 'g', '0.0192', '0.0195', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 135 DAY)
    UNION ALL
    SELECT 1, 1003, 1001, 'Proveedor Global', 'Harina compartida', 'g', '0.0195', '0.0201', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 90 DAY)
    UNION ALL
    SELECT 1, 1003, 1001, 'Proveedor Global', 'Harina compartida', 'g', '0.0201', '0.0198', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 45 DAY)
    UNION ALL
    SELECT 1, 1003, 1001, 'Proveedor Global', 'Harina compartida', 'g', '0.0198', '0.0200', 'cambio', NULL, 'Demostración sintética', DATE_SUB(TIMESTAMP(DATE_SUB(CURDATE(), INTERVAL 1 DAY),'12:00:00'), INTERVAL 0 DAY)
) AS serie
WHERE COALESCE(@cp_demo_precios_aprobada,0)=1;
-- FIN FUENTE SQL COMPARTIDA: demo-historial-precios-proveedores-v1
INSERT INTO esquema_versiones(version)
SELECT 'demo-historial-precios-proveedores-v1' WHERE COALESCE(@cp_demo_precios_aprobada,0)=1;
COMMIT;
DROP TEMPORARY TABLE IF EXISTS _cp_guard_demo_precios;
SET @cp_demo_precios_aprobada=NULL;
