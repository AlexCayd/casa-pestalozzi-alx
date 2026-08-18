-- Afectaciones de reservaciones por cambios de horario.
-- Ejecutar después de que existan usuarios y reservaciones.

CREATE TABLE IF NOT EXISTS horario_impactos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo_origen  VARCHAR(40) NOT NULL,
  origen_id    INT UNSIGNED NULL,
  estado       ENUM('pendiente', 'resuelto') NOT NULL DEFAULT 'pendiente',
  dedup_key    CHAR(64) NOT NULL,
  created_by   INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME NULL,
  CONSTRAINT fk_horario_impactos_usuario
    FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_horario_impactos_dedup (dedup_key),
  INDEX idx_horario_impactos_estado_fecha (estado, created_at)
);

CREATE TABLE IF NOT EXISTS horario_impacto_reservaciones (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  impacto_id            INT UNSIGNED NOT NULL,
  reservacion_id        INT NOT NULL,
  estado                ENUM(
                           'pendiente_notificacion',
                           'notificacion_encolada',
                           'sin_contacto',
                           'atendida_manual',
                           'resuelta_por_cliente'
                         ) NOT NULL,
  resolved_by           INT NULL,
  resolved_at           DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_horario_impacto_reservaciones_impacto
    FOREIGN KEY (impacto_id) REFERENCES horario_impactos(id) ON DELETE CASCADE,
  CONSTRAINT fk_horario_impacto_reservaciones_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_horario_impacto_reservaciones_usuario
    FOREIGN KEY (resolved_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_horario_impacto_reservacion (impacto_id, reservacion_id),
  INDEX idx_horario_impacto_reservaciones_estado (impacto_id, estado)
);

CREATE TABLE IF NOT EXISTS reservacion_notificaciones (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  impacto_reservacion_id   INT UNSIGNED NOT NULL,
  reservacion_id           INT NOT NULL,
  evento                   VARCHAR(80) NOT NULL,
  estado                   ENUM('pendiente', 'enviada', 'fallida') NOT NULL DEFAULT 'pendiente',
  dedup_key                VARCHAR(191) NOT NULL,
  intentos                 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  available_at             DATETIME NOT NULL,
  sent_at                  DATETIME NULL,
  failed_at                DATETIME NULL,
  last_error               VARCHAR(500) NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservacion_notificaciones_impacto_reservacion
    FOREIGN KEY (impacto_reservacion_id)
    REFERENCES horario_impacto_reservaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_reservacion_notificaciones_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_reservacion_notificaciones_dedup (dedup_key),
  INDEX idx_reservacion_notificaciones_dispatch (estado, available_at)
);

CREATE TABLE IF NOT EXISTS reservacion_magic_links (
  id                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id                CHAR(32) NOT NULL,
  reservacion_id           INT NOT NULL,
  impacto_reservacion_id   INT UNSIGNED NOT NULL,
  purpose                  VARCHAR(40) NOT NULL,
  token_hash               CHAR(64) NOT NULL,
  expires_at               DATETIME NOT NULL,
  used_at                  DATETIME NULL,
  invalidated_at           DATETIME NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservacion_magic_links_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservacion_magic_links_impacto_reservacion
    FOREIGN KEY (impacto_reservacion_id)
    REFERENCES horario_impacto_reservaciones(id) ON DELETE CASCADE,
  UNIQUE KEY uq_reservacion_magic_links_public_id (public_id),
  UNIQUE KEY uq_reservacion_magic_links_token_hash (token_hash),
  INDEX idx_reservacion_magic_links_active (public_id, expires_at, used_at, invalidated_at)
);
