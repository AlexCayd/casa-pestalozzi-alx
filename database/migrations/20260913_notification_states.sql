-- Ejecutar una sola vez con workflows pausados y backup previo.
-- El accepted anterior era ACK n8n; delivered anterior era aceptación proveedor.
-- Nunca ejecutar ddl.sql sobre una instalación existente: contiene DROP TABLE.
UPDATE horario_impacto_reservaciones
SET notification_delivery_status = CASE notification_delivery_status
    WHEN 'accepted' THEN 'pending' WHEN 'delivered' THEN 'accepted'
    ELSE notification_delivery_status END;
UPDATE reservacion_recordatorios
SET notification_delivery_status = CASE notification_delivery_status
    WHEN 'accepted' THEN 'pending' WHEN 'delivered' THEN 'accepted'
    ELSE notification_delivery_status END;
ALTER TABLE horario_impacto_reservaciones
    MODIFY notification_delivery_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending';
ALTER TABLE reservacion_recordatorios
    MODIFY notification_delivery_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending';
