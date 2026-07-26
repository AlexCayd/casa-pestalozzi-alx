-- Alinea instalaciones existentes con el esquema usado por el portal público.
--
-- Esta migración preserva `tipo` y `origen` cuando existen porque todavía son
-- útiles para instalaciones que distinguen reservaciones de walk-ins.
-- Debe aplicarse una sola vez sobre una base que aún use:
--   confirmacion_expires_at
--   pendiente_asignacion / pendiente_confirmacion / esperando_llegada

ALTER TABLE reservaciones
  ADD COLUMN telefono VARCHAR(30) NULL
    COMMENT 'Valor de teléfono presentado por el cliente; no vincula identidades'
    AFTER email,
  ADD COLUMN contacto_tipo ENUM('email','telefono') NULL
    COMMENT 'Canal que identifica la reservación en el portal público'
    AFTER telefono,
  ADD COLUMN contacto_valor VARCHAR(150) NULL
    COMMENT 'Valor de contacto presentado por el cliente'
    AFTER contacto_tipo,
  ADD COLUMN contacto_normalizado VARCHAR(150) NULL
    COMMENT 'Autoridad canónica de comparación para el acceso público'
    AFTER contacto_valor;

UPDATE reservaciones
SET contacto_tipo = 'email',
    contacto_valor = TRIM(email),
    contacto_normalizado = LOWER(TRIM(email))
WHERE email IS NOT NULL
  AND TRIM(email) <> ''
  AND contacto_normalizado IS NULL;

ALTER TABLE reservaciones
  CHANGE COLUMN confirmacion_expires_at verification_expires_at DATETIME NULL
    COMMENT 'Vencimiento absoluto de una retención pendiente_verificacion',
  ADD COLUMN no_show_at DATETIME NULL
    COMMENT 'Marca operativa de inasistencia'
    AFTER completed_at,
  ADD COLUMN cancelled_by INT NULL
    COMMENT 'Usuario que realizó la cancelación administrativa'
    AFTER no_show_at,
  ADD COLUMN no_show_by INT NULL
    COMMENT 'Usuario que registró la inasistencia'
    AFTER cancelled_by;

-- Se usa temporalmente la unión de estados para que la conversión no pierda
-- valores al cambiar el ENUM.
ALTER TABLE reservaciones
  MODIFY COLUMN estado ENUM(
    'pendiente_asignacion',
    'pendiente_confirmacion',
    'esperando_llegada',
    'pendiente',
    'pendiente_verificacion',
    'confirmada',
    'llego',
    'en_curso',
    'expirada',
    'completada',
    'cancelada',
    'no_show'
  ) NOT NULL DEFAULT 'pendiente';

UPDATE reservaciones
SET estado = CASE estado
  WHEN 'pendiente_asignacion' THEN 'pendiente'
  WHEN 'pendiente_confirmacion' THEN 'pendiente_verificacion'
  WHEN 'esperando_llegada' THEN 'confirmada'
  ELSE estado
END;

ALTER TABLE reservaciones
  MODIFY COLUMN estado ENUM(
    'pendiente',
    'pendiente_verificacion',
    'confirmada',
    'llego',
    'en_curso',
    'expirada',
    'completada',
    'cancelada',
    'no_show'
  ) NOT NULL DEFAULT 'pendiente'
    COMMENT 'pendiente es administrativa; pendiente_verificacion es una retención pública';

ALTER TABLE reservaciones
  DROP INDEX idx_reservaciones_estado_expira,
  ADD INDEX idx_reservaciones_contacto_activo
    (contacto_tipo, contacto_normalizado, estado, fecha, hora),
  ADD INDEX idx_reservaciones_retenciones_vencidas
    (estado, verification_expires_at),
  ADD INDEX idx_reservaciones_contacto_estado_fecha
    (contacto_tipo, contacto_normalizado, estado, fecha),
  ADD CONSTRAINT fk_reservaciones_cancelled_by
    FOREIGN KEY (cancelled_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_reservaciones_no_show_by
    FOREIGN KEY (no_show_by) REFERENCES usuarios(id) ON DELETE SET NULL;
