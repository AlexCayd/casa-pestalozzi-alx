-- Casa Pestalozzi — Esquema (DDL)
-- Estructura de la base de datos: DROP + CREATE TABLE.
-- Los datos de siembra viven en dml.sql (ejecutar este archivo primero).
-- Ejecutar contra la BD configurada en includes/.env (DB_NAME).

-- -------------------------------------------------------
-- RESET (orden inverso de dependencias)
-- -------------------------------------------------------

-- ticket_pagos va primero: apunta a tickets. Si falta aquí, el DROP de
-- tickets falla por llave foránea y el reset completo se cae sobre una BD ya
-- existente.
-- logs_sugerencias se conserva en el DROP para limpiar instalaciones previas:
-- la tabla ya no existe en este esquema (ver nota en SUGERENCIAS).
DROP TABLE IF EXISTS logs_sugerencias;
DROP TABLE IF EXISTS ticket_pagos;
DROP TABLE IF EXISTS reservacion_mesas;
DROP TABLE IF EXISTS impresoras;
DROP TABLE IF EXISTS feedback;
DROP TABLE IF EXISTS feedback_tokens;
DROP TABLE IF EXISTS ticket_pagos;
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

-- Accesos: los administradores entran en /admin/login con usuario + password
-- alfanumerica (password_hash). El personal de piso (meseros/cajeros) entra
-- en /login con un NIP numerico de 4-6 digitos, unico por usuario y guardado
-- hasheado con bcrypt (nip_hash), que lo lleva a /mapa.
CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL UNIQUE,
  nombre        VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nip_hash      VARCHAR(255) NULL COMMENT 'NIP de acceso del personal de piso (bcrypt)',
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
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
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

-- mesero_id asocia el mesero a la mesa para imprimirlo en el ticket.
-- metodo_pago registra 'dividido' cuando la cuenta se separa por comensal y
-- se mezclan metodos de pago (el detalle por comensal vive en ticket_pagos).
CREATE TABLE IF NOT EXISTS tickets (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  mesa_id            INT NOT NULL,
  mesa_secundaria_id INT NULL,
  comensales         INT NOT NULL DEFAULT 1,
  nombre             VARCHAR(120) DEFAULT NULL,
  hora_apertura      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado             ENUM('abierto','cerrado','cancelado') NOT NULL DEFAULT 'abierto',
  metodo_pago        ENUM('efectivo','tarjeta','dividido') NULL,
  propina            DECIMAL(8,2) NOT NULL DEFAULT 0 COMMENT 'Propina al cerrar = pagado − total de la cuenta',
  reservacion_id     INT NULL,
  mesero_id          INT NULL,
  FOREIGN KEY (mesa_id)            REFERENCES mesas(id),
  FOREIGN KEY (mesa_secundaria_id) REFERENCES mesas(id) ON DELETE SET NULL,
  FOREIGN KEY (reservacion_id)     REFERENCES reservaciones(id) ON DELETE SET NULL,
  FOREIGN KEY (mesero_id)          REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_estado_mesa        (estado, mesa_id),
  INDEX idx_ticket_reservacion (reservacion_id)
);
-- Nota: mesero_id (para imprimir el mesero en el ticket) ya viene declarado con
-- su FK en el CREATE TABLE de arriba. El ALTER que lo añadía por separado se
-- eliminó: sobre una BD limpia fallaba con "Duplicate column name 'mesero_id'".

-- Pago dividido por comensal: cuando la cuenta se separa, cada comensal puede
-- pagar con un metodo distinto. El ticket registra 'dividido' si se mezclan metodos.
ALTER TABLE tickets
  MODIFY COLUMN metodo_pago ENUM('efectivo','tarjeta','dividido') NULL;


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

-- Registro del pago de cada comensal cuando la cuenta se divide.
-- La suma de 'monto' de un ticket debe ser >= al total de sus ticket_items no
-- cancelados; el excedente es propina y se guarda en tickets.propina (validado
-- en MapaController::cerrarTicket). Solo se llena en cuentas divididas.
CREATE TABLE IF NOT EXISTS ticket_pagos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT NOT NULL,
  comensal    TINYINT UNSIGNED NOT NULL,
  metodo_pago ENUM('efectivo','tarjeta') NOT NULL,
  monto       DECIMAL(8,2) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  INDEX idx_tp_ticket (ticket_id)
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


-- -------------------------------------------------------
-- SUGERENCIAS (venta sugerida del POS)
-- -------------------------------------------------------
--
-- No hay tabla: la sugerencia se calcula al vuelo y no se persiste.
--
-- El motor (flujo de n8n) deduce qué ofrecer a partir de datos que ya existen:
-- los tickets cerrados del mismo cliente — vía tickets.reservacion_id ->
-- reservaciones.email — y los tickets de otras mesas que pidieron platillos
-- parecidos a los de ticket_items. Nada de eso necesita un log propio.
--
-- Para no repetir lo ya ofrecido, el POS excluye lo que la mesa ya pidió
-- (ticket_items) más lo que lleva visto en la sesión del modal, que manda en
-- cada llamada (ver Services\Sugerencias). Consecuencia asumida: al reabrir
-- la mesa vuelve a salir la misma sugerencia, y un rechazo no deja rastro —
-- no hay dónde medir la conversión por producto.