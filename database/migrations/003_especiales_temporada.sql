-- Casa Pestalozzi — Migración 003: especiales de temporada desde la base
--
-- La vitrina «Especiales de temporada» de la landing deja de salir de un
-- arreglo fijo en src/js/modules/gallery.js y pasa a leer los platillos de
-- las categorías con carta = 'temporada'. Tres cambios:
--
--   1. categorias.carta admite 'temporada'.
--   2. productos.img: foto opcional del platillo (la vitrina sólo pinta
--      tarjetas de foto; sin la propia, el especial toma la de su categoría).
--   3. Se siembra la categoría «Especiales de temporada», SIN platillos: los
--      da de alta la cocina desde Menú.
--
-- Aditiva y re-ejecutable. Ejecutar después de 001 y 002.

SET NAMES utf8mb4;

ALTER TABLE categorias
  MODIFY carta ENUM('comida','maridaje','temporada') NOT NULL DEFAULT 'comida';

DROP PROCEDURE IF EXISTS cp_add_columna;
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
DELIMITER ;

CALL cp_add_columna('productos', 'img', 'VARCHAR(200) NULL AFTER descripcion');

DROP PROCEDURE IF EXISTS cp_add_columna;

-- Por nombre y no por id: en una base en uso el 15 puede estar ocupado.
INSERT INTO categorias (nombre, carta, img)
SELECT 'Especiales de temporada', 'temporada', 'build/images/especiales-temporada.webp'
  FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM categorias WHERE carta = 'temporada');
