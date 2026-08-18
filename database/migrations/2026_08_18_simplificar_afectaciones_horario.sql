-- Refactor forward de la primera implementación de afectaciones por horario.
-- Ejecutar después de 2026_08_18_reservaciones_afectaciones_horario.sql cuando
-- esa migración ya haya sido aplicada. El DDL base ya contiene el esquema final.

-- El aviso preparado y el acceso temporal viven en el mismo registro de la
-- afectación; no se conserva una cola ni una tabla de magic links.
ALTER TABLE horario_impacto_reservaciones
  ADD COLUMN notification_prepared_at DATETIME NULL AFTER estado,
  ADD COLUMN access_token_hash CHAR(64) NULL AFTER notification_prepared_at,
  ADD COLUMN access_expires_at DATETIME NULL AFTER access_token_hash,
  ADD COLUMN access_invalidated_at DATETIME NULL AFTER access_expires_at;

-- La primera versión llamó "encolado" al estado que ahora representa un
-- aviso preparado. Se conserva temporalmente en el ENUM para poder traducirlo.
ALTER TABLE horario_impacto_reservaciones
  MODIFY COLUMN estado ENUM(
    'pendiente_notificacion',
    'notificacion_encolada',
    'notificacion_preparada',
    'sin_contacto',
    'atendida_manual',
    'resuelta_por_cliente'
  ) NOT NULL;

-- La primera versión llamó "encolado" al estado que ahora representa un
-- aviso preparado. Se traduce antes de cerrar el ENUM.
UPDATE horario_impacto_reservaciones
SET estado = 'notificacion_preparada'
WHERE estado = 'notificacion_encolada';

ALTER TABLE horario_impacto_reservaciones
  MODIFY COLUMN estado ENUM(
    'pendiente_notificacion',
    'notificacion_preparada',
    'sin_contacto',
    'atendida_manual',
    'resuelta_por_cliente'
  ) NOT NULL;

-- Conserva el hecho de que el aviso fue preparado. El token plano no se
-- puede reconstruir y cualquier link anterior se invalida abajo.
UPDATE horario_impacto_reservaciones ir
JOIN (
  SELECT impacto_reservacion_id,
         MAX(COALESCE(sent_at, created_at, NOW())) AS prepared_at
  FROM reservacion_notificaciones
  GROUP BY impacto_reservacion_id
) n ON n.impacto_reservacion_id = ir.id
SET ir.notification_prepared_at = n.prepared_at,
    ir.estado = 'notificacion_preparada'
WHERE ir.estado IN ('pendiente_notificacion', 'notificacion_preparada');

-- Los enlaces de la arquitectura anterior no se pueden reutilizar con el
-- contexto temporal nuevo. Se invalidan explícitamente antes de eliminar la
-- tabla histórica y sus referencias.
UPDATE reservacion_magic_links
SET invalidated_at = COALESCE(invalidated_at, NOW())
WHERE invalidated_at IS NULL;

DROP TABLE IF EXISTS reservacion_magic_links;
DROP TABLE IF EXISTS reservacion_notificaciones;
