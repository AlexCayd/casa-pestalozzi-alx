-- Aplicar una vez, antes del código etapa 3. No ejecutada sobre la DB existente.
-- created_at ya existe: fecha del intento, incluso cuando el proveedor falla.
-- accepted_at acredita aceptación, finalized_at registra también fallos.
-- No se almacena código plano ni contador redundante: COUNT(accepted_at) por ciclo.
-- Reserva: un ciclo durante todo el hold. Acceso: 15 minutos fijos desde el
-- primer intento; consumo o vencimiento permiten otro ciclo, nunca un reenvío.
-- Filas anteriores quedan legacy: no se inventa aceptación histórica. La política
-- bloquea reenvío de su ciclo vivo (hasta fin de hold / created_at + 15 minutos).
-- No aplicar de nuevo si main ya integró estas columnas e índices en ddl.sql.
ALTER TABLE verificaciones_contacto
    ADD COLUMN delivery_status ENUM('legacy', 'pending', 'accepted', 'failed') NOT NULL DEFAULT 'legacy',
    ADD COLUMN accepted_at DATETIME NULL,
    ADD COLUMN finalized_at DATETIME NULL,
    ADD COLUMN cycle_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD COLUMN cycle_expires_at DATETIME NULL,
    ADD INDEX idx_verificacion_ciclo (contacto_tipo, contacto, reservacion_id, cycle_id, id);

ALTER TABLE verificaciones_contacto
    ALTER COLUMN delivery_status SET DEFAULT 'pending';
