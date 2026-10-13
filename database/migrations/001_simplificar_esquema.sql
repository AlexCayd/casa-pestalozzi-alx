-- Casa Pestalozzi — Migración 001: simplificar el esquema
--
-- Lleva una base del sistema anterior (POS, KDS, inventario, finanzas,
-- analíticas, feedback, impresión, NIP y roles) al esquema de database/schema.sql.
--
-- ⚠️ El DDL de MySQL no es transaccional: un fallo a mitad deja la base a medio
-- migrar. Respaldar ANTES (mysqldump) y probar primero sobre una copia local.
-- Es re-ejecutable: cada paso comprueba si todavía hay algo que hacer.

SET NAMES utf8mb4;
SET @fk_checks_previo = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------
-- Auxiliares: MySQL 8 no admite IF EXISTS en DROP COLUMN, DROP FOREIGN KEY,
-- DROP INDEX ni DROP CHECK, así que cada uno pregunta a information_schema.
-- Se borran al final.
-- -------------------------------------------------------
DROP PROCEDURE IF EXISTS cp_drop_fk;
DROP PROCEDURE IF EXISTS cp_drop_columna;
DROP PROCEDURE IF EXISTS cp_drop_indice;
DROP PROCEDURE IF EXISTS cp_drop_check;

DELIMITER //
CREATE PROCEDURE cp_drop_fk(IN p_tabla VARCHAR(64), IN p_fk VARCHAR(64))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                AND CONSTRAINT_NAME = p_fk AND CONSTRAINT_TYPE = 'FOREIGN KEY') THEN
    SET @cp_sql = CONCAT('ALTER TABLE `', p_tabla, '` DROP FOREIGN KEY `', p_fk, '`');
    PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
  END IF;
END//
CREATE PROCEDURE cp_drop_columna(IN p_tabla VARCHAR(64), IN p_columna VARCHAR(64))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                AND COLUMN_NAME = p_columna) THEN
    SET @cp_sql = CONCAT('ALTER TABLE `', p_tabla, '` DROP COLUMN `', p_columna, '`');
    PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
  END IF;
END//
CREATE PROCEDURE cp_drop_indice(IN p_tabla VARCHAR(64), IN p_indice VARCHAR(64))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                AND INDEX_NAME = p_indice) THEN
    SET @cp_sql = CONCAT('ALTER TABLE `', p_tabla, '` DROP INDEX `', p_indice, '`');
    PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
  END IF;
END//
CREATE PROCEDURE cp_drop_check(IN p_tabla VARCHAR(64), IN p_check VARCHAR(64))
BEGIN
  IF EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                AND CONSTRAINT_NAME = p_check AND CONSTRAINT_TYPE = 'CHECK') THEN
    SET @cp_sql = CONCAT('ALTER TABLE `', p_tabla, '` DROP CHECK `', p_check, '`');
    PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
  END IF;
END//
DELIMITER ;

-- -------------------------------------------------------
-- 1. Llaves foráneas de tablas conservadas hacia tablas eliminadas
-- -------------------------------------------------------

-- Producción (KDS): el platillo ya no se enruta a un área.
CALL cp_drop_fk('productos', 'productos_ibfk_2');

-- -------------------------------------------------------
-- 2. Tablas hijas de módulos eliminados
-- -------------------------------------------------------

-- Sugerencias del POS.
DROP TABLE IF EXISTS logs_sugerencias;
-- Inventario, proveedores, recetas e histórico de precios.
DROP TABLE IF EXISTS historial_precios;
DROP TABLE IF EXISTS ingrediente_proveedores;
DROP TABLE IF EXISTS movimientos_inventario;
DROP TABLE IF EXISTS producto_componentes;
DROP TABLE IF EXISTS subreceta_ingredientes;
-- Finanzas.
DROP TABLE IF EXISTS gastos_fijos;
-- Punto de venta: pagos, mesas del ticket, comandas.
DROP TABLE IF EXISTS ticket_pagos;
DROP TABLE IF EXISTS ticket_mesas;
DROP TABLE IF EXISTS ticket_items;
-- Impresión de comandas.
DROP TABLE IF EXISTS impresion_alertas;
-- Feedback de comensales.
DROP TABLE IF EXISTS feedback;
DROP TABLE IF EXISTS feedback_tokens;
-- Formularios anónimos retirados antes de este refactor.
DROP TABLE IF EXISTS cata_inscripciones;
DROP TABLE IF EXISTS catering_solicitudes;

-- -------------------------------------------------------
-- 3. Tablas padre de módulos eliminados
-- -------------------------------------------------------

-- Inventario y recetas.
DROP TABLE IF EXISTS proveedores;
DROP TABLE IF EXISTS subrecetas;
DROP TABLE IF EXISTS ingredientes;
-- Punto de venta.
DROP TABLE IF EXISTS tickets;
-- Impresión y su configuración.
DROP TABLE IF EXISTS impresoras;
DROP TABLE IF EXISTS configuracion_pos;
-- Producción (KDS).
DROP TABLE IF EXISTS areas_produccion;
-- Reportes internos del panel.
DROP TABLE IF EXISTS reportes_sistema;
-- Visibilidad de módulos del panel: con cinco módulos ya no hay qué ocultar.
DROP TABLE IF EXISTS configuracion_modulos;
-- Legado: `menu` se fundió en `productos`; los catálogos de horario de
-- reservación se sustituyeron por horarios_operacion.
DROP TABLE IF EXISTS menu;
DROP TABLE IF EXISTS dias_reservacion;
DROP TABLE IF EXISTS horarios_reservacion;

-- -------------------------------------------------------
-- 4. Columnas sobrantes de tablas conservadas
-- -------------------------------------------------------

-- Producción (KDS): `productos.area_id`.
CALL cp_drop_indice('productos', 'area_id');
CALL cp_drop_columna('productos', 'area_id');

-- Acceso de piso (NIP) y roles: queda un solo tipo de usuario, administrador.
-- Meseros y cocineros se DESACTIVAN en vez de borrarse: sin rol ni NIP ya no
-- tienen cómo entrar, y desactivarlos es reversible desde el panel.
SET @hay_rol = (SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
                   AND COLUMN_NAME = 'rol');
SET @cp_sql = IF(@hay_rol > 0,
  'UPDATE usuarios SET activo = 0 WHERE rol <> ''admin''',
  'DO 0');
PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
CALL cp_drop_check('usuarios', 'chk_usuarios_admin_sin_nip');
CALL cp_drop_indice('usuarios', 'uq_usuarios_nip_lookup');
CALL cp_drop_columna('usuarios', 'nip_hash');
CALL cp_drop_columna('usuarios', 'nip_lookup');
CALL cp_drop_columna('usuarios', 'rol');

-- Reservaciones: sin punto de venta no hay ticket que marque la llegada, así
-- que el estado `en_curso` desaparece. Las que estaban en curso ya habían
-- llegado: se cierran como completadas antes de quitar el valor del ENUM.
UPDATE reservaciones
   SET estado = 'completada', estado_changed_at = NOW()
 WHERE estado = 'en_curso';
ALTER TABLE reservaciones
  MODIFY estado ENUM(
    'pendiente_verificacion',
    'confirmada',
    'completada',
    'cancelada',
    'no_show',
    'expirada',
    'reemplazada'
  ) NOT NULL DEFAULT 'pendiente_verificacion';

-- -------------------------------------------------------
-- 5. Puesta al día del motor de reservaciones
-- -------------------------------------------------------
--
-- No es parte de la poda: son las tres migraciones de notificaciones del
-- 2026-09-13 (otp_resends, notification_states, reminder_recovery), que se
-- retiraron del repositorio cuando su contenido pasó al DDL. Una base creada
-- antes de esa fecha no las tiene, y sin ellas el motor de reservaciones que
-- se conserva falla al reenviar OTP o reclamar recordatorios. Se incluyen aquí
-- para que esta migración deje cualquier base anterior igual a schema.sql.
DROP PROCEDURE IF EXISTS cp_add_columna;
DROP PROCEDURE IF EXISTS cp_add_indice;
DELIMITER //
CREATE PROCEDURE cp_add_columna(IN p_tabla VARCHAR(64), IN p_columna VARCHAR(64), IN p_definicion TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                    AND COLUMN_NAME = p_columna) THEN
    SET @cp_sql = CONCAT('ALTER TABLE `', p_tabla, '` ADD COLUMN `', p_columna, '` ', p_definicion);
    PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
  END IF;
END//
CREATE PROCEDURE cp_add_indice(IN p_tabla VARCHAR(64), IN p_indice VARCHAR(64), IN p_columnas TEXT)
BEGIN
  IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_tabla
                    AND INDEX_NAME = p_indice) THEN
    SET @cp_sql = CONCAT('ALTER TABLE `', p_tabla, '` ADD INDEX `', p_indice, '` (', p_columnas, ')');
    PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
  END IF;
END//
DELIMITER ;

-- otp_resends: ciclos de reenvío del código de verificación. Las filas
-- anteriores quedan como 'legacy': no se inventa una aceptación histórica.
CALL cp_add_columna('verificaciones_contacto', 'delivery_status',
  "ENUM('legacy','pending','accepted','failed') NOT NULL DEFAULT 'legacy'");
CALL cp_add_columna('verificaciones_contacto', 'accepted_at', 'DATETIME NULL');
CALL cp_add_columna('verificaciones_contacto', 'finalized_at', 'DATETIME NULL');
CALL cp_add_columna('verificaciones_contacto', 'cycle_id',
  'CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL');
CALL cp_add_columna('verificaciones_contacto', 'cycle_expires_at', 'DATETIME NULL');
CALL cp_add_indice('verificaciones_contacto', 'idx_verificacion_ciclo',
  'contacto_tipo, contacto, reservacion_id, cycle_id, id');
ALTER TABLE verificaciones_contacto ALTER COLUMN delivery_status SET DEFAULT 'pending';

-- notification_states: el 'accepted' anterior era el acuse de n8n y el
-- 'delivered' la aceptación del proveedor. Sólo se traduce si el ENUM todavía
-- tiene 'delivered'; en una segunda pasada ya no lo tiene y no se toca nada.
SET @estados_viejos = (SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'horario_impacto_reservaciones'
                          AND COLUMN_NAME = 'notification_delivery_status'
                          AND COLUMN_TYPE LIKE '%delivered%');
SET @cp_sql = IF(@estados_viejos > 0,
  "UPDATE horario_impacto_reservaciones SET notification_delivery_status = CASE notification_delivery_status WHEN 'accepted' THEN 'pending' WHEN 'delivered' THEN 'accepted' ELSE notification_delivery_status END",
  'DO 0');
PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
SET @estados_viejos = (SELECT COUNT(*) FROM information_schema.COLUMNS
                        WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'reservacion_recordatorios'
                          AND COLUMN_NAME = 'notification_delivery_status'
                          AND COLUMN_TYPE LIKE '%delivered%');
SET @cp_sql = IF(@estados_viejos > 0,
  "UPDATE reservacion_recordatorios SET notification_delivery_status = CASE notification_delivery_status WHEN 'accepted' THEN 'pending' WHEN 'delivered' THEN 'accepted' ELSE notification_delivery_status END",
  'DO 0');
PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;
ALTER TABLE horario_impacto_reservaciones
  MODIFY notification_delivery_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending';
ALTER TABLE reservacion_recordatorios
  MODIFY notification_delivery_status ENUM('pending','accepted','failed') NOT NULL DEFAULT 'pending';

-- reminder_recovery: reclamo atómico y reintentos de recordatorios. Las filas
-- previas pudieron enviarse ya; se marcan reclamadas para no duplicar avisos.
SET @hay_reclamo = (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservacion_recordatorios'
                       AND COLUMN_NAME = 'transport_claimed_at');
CALL cp_add_columna('reservacion_recordatorios', 'notification_attempts',
  'SMALLINT UNSIGNED NOT NULL DEFAULT 1');
CALL cp_add_columna('reservacion_recordatorios', 'transport_claimed_at', 'DATETIME NULL');
CALL cp_add_columna('reservacion_recordatorios', 'retryable', 'TINYINT(1) NOT NULL DEFAULT 0');
SET @cp_sql = IF(@hay_reclamo = 0,
  'UPDATE reservacion_recordatorios SET transport_claimed_at = COALESCE(notification_delivery_updated_at, created_at)',
  'DO 0');
PREPARE cp_stmt FROM @cp_sql; EXECUTE cp_stmt; DEALLOCATE PREPARE cp_stmt;

-- -------------------------------------------------------
-- Limpieza
-- -------------------------------------------------------
DROP PROCEDURE IF EXISTS cp_drop_fk;
DROP PROCEDURE IF EXISTS cp_drop_columna;
DROP PROCEDURE IF EXISTS cp_drop_indice;
DROP PROCEDURE IF EXISTS cp_drop_check;
DROP PROCEDURE IF EXISTS cp_add_columna;
DROP PROCEDURE IF EXISTS cp_add_indice;

SET FOREIGN_KEY_CHECKS = @fk_checks_previo;
