-- Contrato final del seguimiento de cambios de horario.
-- Ejecutar después de las migraciones de afectaciones y del buzón genérico.

ALTER TABLE buzon_notificaciones
  ADD COLUMN requiere_accion TINYINT(1) NOT NULL DEFAULT 1 AFTER prioridad,
  ADD INDEX idx_buzon_notificaciones_accion (cerrada_at, visible_from, requiere_accion);

ALTER TABLE horario_impacto_reservaciones
  ADD COLUMN notification_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER access_invalidated_at,
  ADD COLUMN last_notification_at DATETIME NULL AFTER notification_attempts;

UPDATE buzon_notificaciones bn
JOIN horario_impacto_reservaciones ir
  ON bn.tipo = 'reservacion_horario_afectado'
 AND bn.entidad_tipo = 'horario_impacto_reservacion'
 AND bn.entidad_id = ir.id
SET bn.requiere_accion = CASE
  WHEN ir.estado = 'notificacion_preparada'
   AND ir.access_expires_at IS NOT NULL
   AND ir.access_expires_at > NOW() THEN 0
  ELSE 1
END;

-- El seguimiento es visible desde que se prepara; la expiración sólo cambia
-- requiere_accion y nunca debe ocultar el caso durante el acceso vigente.
UPDATE buzon_notificaciones
SET visible_from = NOW()
WHERE tipo = 'reservacion_horario_afectado'
  AND entidad_tipo = 'horario_impacto_reservacion'
  AND cerrada_at IS NULL;
