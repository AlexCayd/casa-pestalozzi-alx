-- Motivo administrativo de cancelación. Forward-only: no modifica migraciones previas.
ALTER TABLE reservaciones
  ADD COLUMN motivo_cancelacion VARCHAR(500) NULL AFTER comentario_admin;
