-- Comunicaciones operativas de reservaciones y recordatorio del día anterior.
-- Forward-only: ejecutar después de 2026_08_19_seguimiento_buzon_final.sql.

CREATE TABLE IF NOT EXISTS configuracion_reservaciones (
  id                                  TINYINT UNSIGNED NOT NULL,
  recordatorio_dia_anterior_activo    TINYINT(1) NOT NULL DEFAULT 0,
  hora_recordatorio                   TIME NOT NULL DEFAULT '18:00:00',
  updated_by                          INT NULL,
  updated_at                          TIMESTAMP NOT NULL
                                        DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_configuracion_reservaciones_usuario
    FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
);

INSERT INTO configuracion_reservaciones
  (id, recordatorio_dia_anterior_activo, hora_recordatorio, updated_by)
VALUES (1, 0, '18:00:00', NULL)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS reservacion_recordatorios (
  id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservacion_id                  INT NOT NULL,
  reservacion_raiz_id             INT NOT NULL,
  tipo                            ENUM('dia_anterior') NOT NULL DEFAULT 'dia_anterior',
  dedup_key                       VARCHAR(191) NOT NULL,
  access_token_hash               CHAR(64) NULL,
  access_expires_at               DATETIME NULL,
  access_invalidated_at           DATETIME NULL,
  notification_delivery_status    ENUM('pending', 'accepted', 'delivered', 'failed')
                                    NOT NULL DEFAULT 'pending',
  notification_delivery_updated_at DATETIME NULL,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservacion_recordatorios_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservacion_recordatorios_raiz
    FOREIGN KEY (reservacion_raiz_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_reservacion_recordatorios_dedup (dedup_key),
  UNIQUE KEY uq_reservacion_recordatorios_access (access_token_hash),
  INDEX idx_reservacion_recordatorios_reservacion (reservacion_id),
  INDEX idx_reservacion_recordatorios_raiz (reservacion_raiz_id),
  INDEX idx_reservacion_recordatorios_delivery (notification_delivery_status)
);

ALTER TABLE horario_impacto_reservaciones
  ADD COLUMN notification_delivery_status
    ENUM('pending', 'accepted', 'delivered', 'failed')
    NOT NULL DEFAULT 'pending' AFTER last_notification_at,
  ADD COLUMN notification_delivery_updated_at DATETIME NULL
    AFTER notification_delivery_status,
  ADD INDEX idx_horario_impacto_reservaciones_delivery (notification_delivery_status);
