-- Pausar scheduler y drenar ejecuciones antes de aplicar una vez.
-- Filas previas pudieron ser enviadas: se marcan reclamadas para evitar duplicados.
ALTER TABLE reservacion_recordatorios
    ADD COLUMN notification_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    ADD COLUMN transport_claimed_at DATETIME NULL,
    ADD COLUMN retryable TINYINT(1) NOT NULL DEFAULT 0;
UPDATE reservacion_recordatorios
SET transport_claimed_at = COALESCE(notification_delivery_updated_at, created_at);
