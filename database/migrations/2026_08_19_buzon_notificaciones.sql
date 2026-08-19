-- Buzón administrativo reutilizable para acciones pendientes.
-- Ejecutar después de las migraciones de afectaciones por horario.

CREATE TABLE IF NOT EXISTS buzon_notificaciones (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo           VARCHAR(80) NOT NULL,
  modulo         VARCHAR(60) NOT NULL,
  entidad_tipo   VARCHAR(80) NOT NULL,
  entidad_id     INT UNSIGNED NOT NULL,
  prioridad      ENUM('normal', 'alta') NOT NULL DEFAULT 'normal',
  visible_from   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  leida_at       DATETIME NULL,
  cerrada_at     DATETIME NULL,
  cerrada_por    INT NULL,
  cierre_motivo  VARCHAR(120) NULL,
  dedup_key      VARCHAR(191) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_buzon_notificaciones_usuario
    FOREIGN KEY (cerrada_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_buzon_notificaciones_dedup (dedup_key),
  INDEX idx_buzon_notificaciones_visibles (cerrada_at, visible_from),
  INDEX idx_buzon_notificaciones_tipo (tipo, cerrada_at),
  INDEX idx_buzon_notificaciones_entidad (entidad_tipo, entidad_id)
);

-- Las filas antiguas ya viven en horario_impacto_reservaciones; los restos de
-- la primera arquitectura no deben seguir existiendo después del forward.
DROP TABLE IF EXISTS reservacion_magic_links;
DROP TABLE IF EXISTS reservacion_notificaciones;
