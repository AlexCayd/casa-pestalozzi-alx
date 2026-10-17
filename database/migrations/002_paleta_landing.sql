-- Casa Pestalozzi — Migración 002: paleta de la landing editable
--
-- Crea configuracion_paleta, la tabla de Configuración → Paleta de la landing.
-- Es aditiva y re-ejecutable (CREATE TABLE IF NOT EXISTS): no toca datos.
-- Una base que venga del sistema completo puede tenerla ya, con la misma
-- estructura; entonces no hace nada.
--
-- Sin la tabla el sitio funciona igual: PaletaLanding la lee de forma
-- tolerante y pinta la marca del SCSS. Lo único que falla es GUARDAR desde el
-- panel.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS configuracion_paleta (
  id          TINYINT UNSIGNED NOT NULL,
  verde       CHAR(7) NULL,
  cafe        CHAR(7) NULL,
  beige       CHAR(7) NULL,
  crema       CHAR(7) NULL,
  updated_by  INT NULL,
  updated_at  TIMESTAMP NOT NULL
                DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_configuracion_paleta_usuario
    FOREIGN KEY (updated_by)
    REFERENCES usuarios(id)
    ON DELETE SET NULL
);
