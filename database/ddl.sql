-- Casa Pestalozzi — Esquema (DDL)
-- Estructura de la base de datos: DROP + CREATE TABLE.
-- Los datos de siembra viven en dml.sql (ejecutar este archivo primero).
-- Ejecutar contra la BD configurada en includes/.env (DB_NAME).

-- -------------------------------------------------------
-- RESET (orden inverso de dependencias)
-- -------------------------------------------------------

DROP TABLE IF EXISTS reservacion_mesas;
DROP TABLE IF EXISTS impresoras;
DROP TABLE IF EXISTS feedback;
DROP TABLE IF EXISTS feedback_tokens;
DROP TABLE IF EXISTS ticket_items;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS menu;
DROP TABLE IF EXISTS categorias;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS reservaciones;
DROP TABLE IF EXISTS areas_produccion;
DROP TABLE IF EXISTS mesas;
DROP TABLE IF EXISTS horarios_reservacion;
DROP TABLE IF EXISTS dias_reservacion;

-- -------------------------------------------------------
-- CATÁLOGOS BASE
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS dias_reservacion (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  dia_semana    TINYINT NOT NULL COMMENT '0=Dom 1=Lun 2=Mar 3=Mie 4=Jue 5=Vie 6=Sab',
  nombre        VARCHAR(20) NOT NULL,
  hora_apertura TIME NOT NULL,
  hora_cierre   TIME NOT NULL,
  activo        TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS horarios_reservacion (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  dia_id INT NOT NULL,
  hora   TIME NOT NULL,
  FOREIGN KEY (dia_id) REFERENCES dias_reservacion(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS mesas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  numero     INT NOT NULL UNIQUE,
  nombre     VARCHAR(60) NOT NULL,
  tipo       ENUM('mesa','barra','especial') NOT NULL DEFAULT 'mesa',
  capacidad  INT NOT NULL DEFAULT 4,
  pos_x      DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Posición % horizontal (centro del pin)',
  pos_y      DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT 'Posición % vertical (centro del pin)',
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  reservable TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = zona estática (barras, caja, llevar)'
);

CREATE TABLE IF NOT EXISTS areas_produccion (
  id     TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(60) NOT NULL,
  slug   VARCHAR(20) NOT NULL UNIQUE,
  color  VARCHAR(10) NOT NULL
);

CREATE TABLE IF NOT EXISTS categorias (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(40) NOT NULL,
  img    VARCHAR(200),
  activo TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL UNIQUE,
  nombre        VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol           ENUM('admin','observer','waiter','cashier') NOT NULL DEFAULT 'observer',
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- RESERVACIONES
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS reservaciones (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  nombre             VARCHAR(100) NOT NULL,
  email              VARCHAR(150) NOT NULL,
  fecha              DATE NOT NULL,
  hora               TIME NOT NULL,
  comensales         INT NOT NULL DEFAULT 2,
  nota               TEXT,
  comentario_admin   TEXT NULL COMMENT 'Comentario interno de operación',
  estado             ENUM('pendiente','confirmada','completada','cancelada','no_show') NOT NULL DEFAULT 'pendiente',
  mesa_id            INT NULL,
  mesa_secundaria_id INT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reserva_mesa  FOREIGN KEY (mesa_id)            REFERENCES mesas(id) ON DELETE SET NULL,
  CONSTRAINT fk_reserva_mesa2 FOREIGN KEY (mesa_secundaria_id) REFERENCES mesas(id) ON DELETE SET NULL,
  INDEX idx_reservaciones_fecha_estado_hora (fecha, estado, hora),
  INDEX idx_reservaciones_fecha_hora        (fecha, hora),
  INDEX idx_reservaciones_estado            (estado)
);

CREATE TABLE IF NOT EXISTS reservacion_mesas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  reservacion_id INT NOT NULL,
  mesa_id        INT NOT NULL,
  orden          TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservacion_mesas_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_reservacion_mesas_mesa
    FOREIGN KEY (mesa_id) REFERENCES mesas(id) ON DELETE CASCADE,
  UNIQUE KEY uq_reservacion_mesa  (reservacion_id, mesa_id),
  UNIQUE KEY uq_reservacion_orden (reservacion_id, orden),
  INDEX idx_rm_mesa        (mesa_id),
  INDEX idx_rm_reservacion (reservacion_id)
);

-- -------------------------------------------------------
-- TICKETS / COMANDA
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS tickets (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  mesa_id            INT NOT NULL,
  mesa_secundaria_id INT NULL,
  comensales         INT NOT NULL DEFAULT 1,
  nombre             VARCHAR(120) DEFAULT NULL,
  hora_apertura      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado             ENUM('abierto','cerrado','cancelado') NOT NULL DEFAULT 'abierto',
  metodo_pago        ENUM('efectivo','tarjeta') NULL,
  reservacion_id     INT NULL,
  mesero_id          INT NULL,
  FOREIGN KEY (mesa_id)            REFERENCES mesas(id),
  FOREIGN KEY (mesa_secundaria_id) REFERENCES mesas(id) ON DELETE SET NULL,
  FOREIGN KEY (reservacion_id)     REFERENCES reservaciones(id) ON DELETE SET NULL,
  FOREIGN KEY (mesero_id)          REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_estado_mesa        (estado, mesa_id),
  INDEX idx_ticket_reservacion (reservacion_id)
);
-- Debido a la implementacion de asociar un mesero por mesa, modificamos la DB para poder imprimir al mesero en el ticket de la mesa.
ALTER TABLE tickets
  ADD COLUMN mesero_id INT NULL AFTER reservacion_id,
  ADD CONSTRAINT fk_ticket_mesero FOREIGN KEY (mesero_id) REFERENCES usuarios(id) ON DELETE SET NULL;


CREATE TABLE IF NOT EXISTS productos (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre    VARCHAR(120) NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  precio    DECIMAL(8,2) NOT NULL,
  area_id   TINYINT UNSIGNED NOT NULL,
  activo    TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (area_id) REFERENCES areas_produccion(id)
);

CREATE TABLE IF NOT EXISTS ticket_items (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT NOT NULL,
  nombre     VARCHAR(120) NOT NULL,
  precio     DECIMAL(8,2) NOT NULL,
  categoria  VARCHAR(60) NOT NULL,
  area_id    TINYINT UNSIGNED NOT NULL,
  comensal   TINYINT UNSIGNED NULL COMMENT 'NULL = General',
  cantidad   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  nota       VARCHAR(280) NULL DEFAULT NULL,
  estado     ENUM('enviado','en_preparacion','listo','entregado','cancelado') NOT NULL DEFAULT 'enviado',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (area_id)   REFERENCES areas_produccion(id),
  INDEX idx_area_estado (area_id, estado),
  INDEX idx_ti_ticket   (ticket_id)
);

-- -------------------------------------------------------
-- MENÚ
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS menu (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(100) NOT NULL,
  descripcion  TEXT NOT NULL,
  precio       DECIMAL(10,2) NOT NULL,
  tag          VARCHAR(60),
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  categoria_id INT NOT NULL,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT
);

-- -------------------------------------------------------
-- FEEDBACK
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS feedback_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT NOT NULL,
  token      VARCHAR(64) NOT NULL UNIQUE,
  usado      TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS feedback (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  token_id           INT UNSIGNED NULL,
  ticket_id          INT NULL,
  calidad_sabor      TINYINT UNSIGNED NOT NULL,
  atencion_mesero    TINYINT UNSIGNED NOT NULL,
  tiempo_espera      TINYINT UNSIGNED NOT NULL,
  experiencia_global TINYINT UNSIGNED NOT NULL,
  comentario         TEXT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (token_id) REFERENCES feedback_tokens(id) ON DELETE SET NULL
);

-- -------------------------------------------------------
-- IMPRESIÓN (ESC/POS)
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS impresoras (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(60) NOT NULL,
  area_id     TINYINT UNSIGNED NULL COMMENT 'NULL = impresora de cuenta/caja',
  rol         ENUM('comanda','cuenta') NOT NULL DEFAULT 'comanda',
  conexion    ENUM('red','windows') NOT NULL DEFAULT 'red',
  host        VARCHAR(64) NOT NULL COMMENT 'IP · sólo aplica a conexion=red',
  puerto      INT NOT NULL DEFAULT 9100 COMMENT 'sólo aplica a conexion=red',
  dispositivo VARCHAR(120) NULL DEFAULT NULL COMMENT 'windows: nombre de impresora o smb://host/recurso',
  ancho       TINYINT NOT NULL DEFAULT 48 COMMENT 'caracteres (48=80mm, 32=58mm)',
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (area_id) REFERENCES areas_produccion(id)
);
