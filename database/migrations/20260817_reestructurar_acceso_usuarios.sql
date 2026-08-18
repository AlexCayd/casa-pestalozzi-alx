-- Migración única para instalaciones existentes.
--
-- Ejecutar con respaldo previo. Los usuarios waiter/cook conservan su hash
-- anterior, pero quedan temporalmente sin nip_lookup porque bcrypt no permite
-- recuperar el NIP original. Antes de habilitar el nuevo login, ejecutar
-- scripts/migrar-credenciales-piso.php con NIP_LOOKUP_SECRET configurado.

START TRANSACTION;

ALTER TABLE usuarios
  ADD COLUMN nip_lookup CHAR(64) NULL AFTER nip_hash;

-- Un administrador nunca conserva una credencial de piso.
UPDATE usuarios
SET nip_hash = NULL,
    nip_lookup = NULL
WHERE rol = 'admin';

ALTER TABLE usuarios
  DROP COLUMN fecha_nacimiento;

ALTER TABLE usuarios
  ADD UNIQUE KEY uq_usuarios_nip_lookup (nip_lookup),
  ADD CONSTRAINT chk_usuarios_admin_sin_nip
    CHECK (rol <> 'admin' OR (nip_hash IS NULL AND nip_lookup IS NULL));

COMMIT;
