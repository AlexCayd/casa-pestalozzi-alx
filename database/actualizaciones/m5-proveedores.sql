-- M5: proveedores por ámbito e histórico de lo recibido de cada uno.
-- Aditivo y repetible. Aplicar después de m4-faltantes.sql con respaldo verificado.
-- Contrato: docs/arquitectura.md §17. No toca saldos, movimientos ni operaciones:
-- sólo liga una entrada ya registrada con el proveedor que la surtió.

CREATE TABLE IF NOT EXISTS entradas_proveedor (
 operacion_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, unidad_id TINYINT UNSIGNED NOT NULL, proveedor_id INT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_entrada_proveedor(unidad_id,proveedor_id),
 FOREIGN KEY(operacion_id,unidad_id) REFERENCES operaciones_inventario(id,unidad_id) ON DELETE RESTRICT,
 FOREIGN KEY(proveedor_id,unidad_id) REFERENCES proveedores(id,unidad_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
