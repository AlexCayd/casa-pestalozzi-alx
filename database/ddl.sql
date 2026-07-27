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
DROP TABLE IF EXISTS reportes_sistema;
DROP TABLE IF EXISTS configuracion_anuncio;
DROP TABLE IF EXISTS excepciones_operacion;
DROP TABLE IF EXISTS horarios_operacion;
DROP TABLE IF EXISTS verificaciones_contacto;
DROP TABLE IF EXISTS ticket_mesas;
DROP TABLE IF EXISTS ticket_pagos;
DROP TABLE IF EXISTS reservacion_mesas;
DROP TABLE IF EXISTS impresoras;
DROP TABLE IF EXISTS feedback;
DROP TABLE IF EXISTS feedback_tokens;
DROP TABLE IF EXISTS ticket_items;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS menu;
DROP TABLE IF EXISTS categorias;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS reservaciones;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS areas_produccion;
DROP TABLE IF EXISTS mesas;

-- -------------------------------------------------------
-- CATÁLOGOS BASE
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS mesas (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  numero     INT NOT NULL UNIQUE,
  nombre     VARCHAR(60) NOT NULL,
  tipo       ENUM('mesa','barra','especial') NOT NULL DEFAULT 'mesa',
  capacidad  INT NOT NULL DEFAULT 4,
  pos_x      DECIMAL(5,2) NOT NULL DEFAULT 0,
  pos_y      DECIMAL(5,2) NOT NULL DEFAULT 0,
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  reservable TINYINT(1) NOT NULL DEFAULT 1
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
  nip_hash      VARCHAR(255) NULL,
  rol           ENUM('admin','observer','waiter','cashier') NOT NULL DEFAULT 'observer',
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- RESERVACIONES
-- -------------------------------------------------------

CREATE TABLE IF NOT EXISTS reservaciones (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  nombre               VARCHAR(100) NOT NULL,
  contacto_tipo        ENUM('email','telefono') NOT NULL,
  -- El contacto se persiste en su formato canónico, normalizado en PHP.
  contacto             VARCHAR(150) NOT NULL,
  fecha                DATE NOT NULL,
  hora                 TIME NOT NULL,
  comensales           INT NOT NULL DEFAULT 2,
  nota                 TEXT,
  comentario_admin     TEXT NULL,
  request_token        VARCHAR(64) NULL,
  request_fingerprint  CHAR(64) NULL,
  -- Una retención vencida deja de ocupar mesas aun antes del proceso de limpieza.
  hold_expires_at      DATETIME NULL,
  confirmed_at         DATETIME NULL,
  arrived_at           DATETIME NULL,
  completed_at         DATETIME NULL,
  status_changed_at    DATETIME NULL,
  last_modified_by     INT NULL,
  last_modified_source ENUM('cliente','personal','sistema') NOT NULL DEFAULT 'sistema',
  last_change_reason   VARCHAR(500) NULL,
  estado               ENUM(
                         'pendiente_verificacion',
                         'confirmada',
                         'llego',
                         'en_curso',
                         'completada',
                         'cancelada',
                         'no_show',
                         'expirada'
                       ) NOT NULL DEFAULT 'pendiente_verificacion',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_reservaciones_fecha_estado_hora (fecha, estado, hora),
  INDEX idx_reservaciones_fecha_hora        (fecha, hora),
  INDEX idx_reservaciones_estado            (estado),
  INDEX idx_reservaciones_contacto (contacto_tipo, contacto, estado, fecha, hora),
  INDEX idx_reservaciones_retenciones_vencidas (estado, hold_expires_at),
  CONSTRAINT chk_reservaciones_fingerprint
    CHECK (request_fingerprint IS NULL OR CHAR_LENGTH(request_fingerprint) = 64),
  CONSTRAINT chk_reservaciones_retencion_vencimiento
    CHECK (estado <> 'pendiente_verificacion' OR hold_expires_at IS NOT NULL),
  CONSTRAINT fk_reservaciones_last_modified_by
    FOREIGN KEY (last_modified_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_reservaciones_request_token (request_token)
);

-- Desafíos OTP de un solo uso. Nunca se guarda el código original: codigo_hash
-- contiene únicamente el resultado de password_hash() y se valida en PHP con
-- password_verify(). reservacion_id puede ser NULL para acceso sin reserva.
CREATE TABLE IF NOT EXISTS verificaciones_contacto (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservacion_id INT NULL,
  contacto_tipo  ENUM('email','telefono') NOT NULL,
  contacto       VARCHAR(150) NOT NULL,
  -- Solamente se persiste el resultado de password_hash().
  codigo_hash    VARCHAR(255) NOT NULL,
  expires_at     DATETIME NOT NULL,
  attempts       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  used_at        DATETIME NULL,
  invalidated_at DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_verificacion_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE CASCADE,
  INDEX idx_verificacion_contacto (contacto_tipo, contacto, created_at),
  INDEX idx_verificacion_reservacion (reservacion_id),
  INDEX idx_verificacion_expiracion (expires_at)
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
  INDEX idx_rm_mesa (mesa_id)
);

-- -------------------------------------------------------
-- TICKETS / COMANDA
-- -------------------------------------------------------

-- mesero_id asocia el mesero a la mesa para imprimirlo en el ticket.
-- metodo_pago registra 'dividido' cuando la cuenta se separa por comensal y
-- se mezclan metodos de pago (el detalle por comensal vive en ticket_pagos).
CREATE TABLE IF NOT EXISTS tickets (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  comensales         INT NOT NULL DEFAULT 1,
  nombre             VARCHAR(120) DEFAULT NULL,
  hora_apertura      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at          DATETIME NULL,
  estado             ENUM('abierto','cerrado','cancelado') NOT NULL DEFAULT 'abierto',
  metodo_pago        ENUM('efectivo','tarjeta','dividido') NULL,
  propina            DECIMAL(8,2) NOT NULL DEFAULT 0,
  reservacion_id     INT NULL,
  mesero_id          INT NULL,
  FOREIGN KEY (reservacion_id)     REFERENCES reservaciones(id) ON DELETE SET NULL,
  FOREIGN KEY (mesero_id)          REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_ticket_estado      (estado),
  INDEX idx_ticket_reservacion (reservacion_id),
  UNIQUE KEY uq_ticket_reservacion (reservacion_id)
);
-- Pago dividido por comensal: cuando la cuenta se separa, cada comensal puede
-- pagar con un metodo distinto. El ticket registra 'dividido' si se mezclan metodos.
-- Fuente canónica exclusiva de ocupación física.
CREATE TABLE IF NOT EXISTS ticket_mesas (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT NOT NULL,
  mesa_id    INT NOT NULL,
  orden      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_mesas_ticket
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_mesas_mesa
    FOREIGN KEY (mesa_id) REFERENCES mesas(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_ticket_mesa (ticket_id, mesa_id),
  UNIQUE KEY uq_ticket_orden (ticket_id, orden),
  INDEX idx_ticket_mesas_mesa (mesa_id)
);


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
  comensal   TINYINT UNSIGNED NULL,
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
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  UNIQUE KEY uq_feedback_token_ticket (ticket_id)
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
  area_id     TINYINT UNSIGNED NULL,
  rol         ENUM('comanda','cuenta') NOT NULL DEFAULT 'comanda',
  conexion    ENUM('red','windows') NOT NULL DEFAULT 'red',
  host        VARCHAR(64) NOT NULL,
  puerto      INT NOT NULL DEFAULT 9100,
  dispositivo VARCHAR(120) NULL DEFAULT NULL,
  ancho       TINYINT NOT NULL DEFAULT 48,
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
-- reservaciones.contacto — y los tickets de otras mesas que pidieron platillos
-- parecidos a los de ticket_items. Nada de eso necesita un log propio.
--
-- Para no repetir lo ya ofrecido, el POS excluye lo que la mesa ya pidió
-- (ticket_items) más lo que lleva visto en la sesión del modal, que manda en
-- cada llamada (ver Services\Sugerencias). Consecuencia asumida: al reabrir
-- la mesa vuelve a salir la misma sugerencia, y un rechazo no deja rastro —
-- no hay dónde medir la conversión por producto.
-- CAMBIOS MODULO DE AJUSTES
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS horarios_operacion (
  id            TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dia_semana    TINYINT UNSIGNED NOT NULL,
  abierto       TINYINT(1) NOT NULL DEFAULT 1,
  hora_apertura TIME NULL,
  hora_cierre   TIME NULL,
  updated_by    INT NULL,
  updated_at    TIMESTAMP NOT NULL
                  DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_horarios_operacion_usuario
    FOREIGN KEY (updated_by)
    REFERENCES usuarios(id)
    ON DELETE SET NULL,

  UNIQUE KEY uq_horarios_operacion_dia (dia_semana)
);

CREATE TABLE IF NOT EXISTS excepciones_operacion (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fecha         DATE NOT NULL,
  tipo          ENUM('cerrado', 'horario_especial') NOT NULL,
  motivo        VARCHAR(160) NULL,
  hora_apertura TIME NULL,
  hora_cierre   TIME NULL,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  updated_by    INT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT NULL
                  ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_excepciones_operacion_usuario
    FOREIGN KEY (updated_by)
    REFERENCES usuarios(id)
    ON DELETE SET NULL,

  UNIQUE KEY uq_excepciones_operacion_fecha (fecha),
  INDEX idx_excepciones_fecha_activo (fecha, activo)
);

CREATE TABLE IF NOT EXISTS configuracion_anuncio (
  id            TINYINT UNSIGNED NOT NULL,
  mensaje       VARCHAR(255) NOT NULL DEFAULT '',
  tipo          ENUM('evento', 'promocion', 'novedad_menu', 'aviso_operativo')
                  NOT NULL DEFAULT 'evento',
  activo        TINYINT(1) NOT NULL DEFAULT 0,
  fecha_inicio  DATETIME NULL,
  fecha_fin     DATETIME NULL,
  texto_enlace  VARCHAR(80) NULL,
  url_enlace    VARCHAR(500) NULL,
  updated_by    INT NULL,
  updated_at    TIMESTAMP NOT NULL
                  DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_configuracion_anuncio_usuario
    FOREIGN KEY (updated_by)
    REFERENCES usuarios(id)
    ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS reportes_sistema (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id       INT NULL,
  modulo           VARCHAR(60) NULL,
  titulo           VARCHAR(120) NOT NULL,
  descripcion      TEXT NOT NULL,
  ruta_origen VARCHAR(255) NULL,
  navegador        ENUM(
                       'chrome',
                       'edge',
                       'firefox',
                       'safari',
                       'otro'
                     ) NULL,
  navegador_otro   VARCHAR(80) NULL,
  estado           ENUM(
                       'nuevo',
                       'en_revision',
                       'resuelto',
                       'descartado'
                     ) NOT NULL DEFAULT 'nuevo',
  resuelto_at      DATETIME NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NULL DEFAULT NULL
                     ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_reportes_sistema_usuario
    FOREIGN KEY (usuario_id)
    REFERENCES usuarios(id)
    ON DELETE SET NULL,

  INDEX idx_reportes_estado_fecha (estado, created_at),
  INDEX idx_reportes_modulo (modulo)
);
