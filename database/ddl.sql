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
DROP TABLE IF EXISTS reservacion_eventos;
DROP TABLE IF EXISTS ticket_mesas;
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
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_dias_reservacion_dia_semana (dia_semana)
);

CREATE TABLE IF NOT EXISTS horarios_reservacion (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  dia_id INT NOT NULL,
  hora   TIME NOT NULL,
  FOREIGN KEY (dia_id) REFERENCES dias_reservacion(id) ON DELETE CASCADE,
  UNIQUE KEY uq_horarios_reservacion_dia_hora (dia_id, hora)
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
  email              VARCHAR(150) NOT NULL COMMENT 'Campo legacy conservado para formularios y reportes existentes',
  telefono           VARCHAR(30) NULL COMMENT 'Valor de teléfono presentado por el cliente; no implica vinculación con email',
  contacto_tipo      ENUM('email','telefono') NULL COMMENT 'Canal que identifica esta reservación en el portal público',
  contacto_valor     VARCHAR(150) NULL COMMENT 'Valor de contacto sincronizado al crear o editar la reservación',
  contacto_normalizado VARCHAR(150) NULL COMMENT 'Única autoridad de comparación para el acceso público',
  fecha              DATE NOT NULL,
  hora               TIME NOT NULL,
  comensales         INT NOT NULL DEFAULT 2,
  nota               TEXT,
  comentario_admin   TEXT NULL COMMENT 'Comentario interno de operación',
  request_token      VARCHAR(64) NULL COMMENT 'Clave de idempotencia; nunca es OTP ni secreto de autenticación',
  request_fingerprint CHAR(64) NULL COMMENT 'SHA-256 del payload público canónico para detectar reutilización conflictiva',
  verification_expires_at DATETIME NULL COMMENT 'Vencimiento absoluto de una retención pendiente_verificacion',
  confirmed_at       DATETIME NULL COMMENT 'Momento en que el contacto verificado confirmó la reservación',
  expired_at         DATETIME NULL COMMENT 'Materialización del vencimiento de la retención',
  cancelled_at       DATETIME NULL COMMENT 'Cancelación lógica solicitada por cliente o personal',
  arrived_at         DATETIME NULL COMMENT 'Llegada registrada por personal; no abre ticket',
  seated_at          DATETIME NULL COMMENT 'Inicio fisico del servicio al crear el ticket',
  completed_at       DATETIME NULL COMMENT 'Finalizacion al cerrar el ticket asociado',
  no_show_at         DATETIME NULL COMMENT 'Marca operativa de inasistencia',
  cancelled_by       INT NULL COMMENT 'Usuario que realizo la cancelacion administrativa',
  no_show_by         INT NULL COMMENT 'Usuario que registro la inasistencia',
  estado             ENUM(
                       'pendiente',
                       'pendiente_verificacion',
                       'confirmada',
                       'llego',
                       'en_curso',
                       'expirada',
                       'completada',
                       'cancelada',
                       'no_show'
                     ) NOT NULL DEFAULT 'pendiente'
                     COMMENT 'pendiente es legacy administrativo; pendiente_verificacion es retención pública temporal',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_reservaciones_fecha_estado_hora (fecha, estado, hora),
  INDEX idx_reservaciones_fecha_hora        (fecha, hora),
  INDEX idx_reservaciones_estado            (estado),
  INDEX idx_reservaciones_contacto_activo    (contacto_tipo, contacto_normalizado, estado, fecha, hora),
  INDEX idx_reservaciones_retenciones_vencidas (estado, verification_expires_at),
  INDEX idx_reservaciones_contacto_estado_fecha (contacto_tipo, contacto_normalizado, estado, fecha),
  CONSTRAINT chk_reservaciones_fingerprint
    CHECK (request_fingerprint IS NULL OR CHAR_LENGTH(request_fingerprint) = 64),
  CONSTRAINT chk_reservaciones_retencion_vencimiento
    CHECK (estado <> 'pendiente_verificacion' OR verification_expires_at IS NOT NULL),
  CONSTRAINT fk_reservaciones_cancelled_by
    FOREIGN KEY (cancelled_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_reservaciones_no_show_by
    FOREIGN KEY (no_show_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_reservaciones_request_token (request_token)
);

-- Desafíos OTP de un solo uso. Nunca se guarda el código original: codigo_hash
-- contiene únicamente el resultado de password_hash() y se valida en PHP con
-- password_verify(). No existe FK porque un contacto puede no tener reservas.
CREATE TABLE IF NOT EXISTS verificaciones_contacto (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservacion_id        INT NULL COMMENT 'Retención pública a la que pertenece el OTP; NULL para acceso de gestión',
  request_token         VARCHAR(64) NULL COMMENT 'Correlación idempotente; no contiene ni reemplaza el OTP',
  contacto_tipo         ENUM('email','telefono') NOT NULL COMMENT 'Identidad independiente: email o teléfono',
  contacto_normalizado  VARCHAR(150) NOT NULL COMMENT 'Resultado canónico de ContactoService',
  codigo_hash           VARCHAR(255) NOT NULL COMMENT 'Hash password_hash; nunca OTP en texto plano',
  expires_at            DATETIME NOT NULL COMMENT 'Vencimiento absoluto del desafío, cinco minutos por defecto',
  attempts              TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Intentos fallidos consumidos',
  max_attempts          TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT 'Límite copiado desde configuración al emitir',
  used_at               DATETIME NULL COMMENT 'Marca de consumo exitoso; impide reutilización',
  invalidated_at        DATETIME NULL COMMENT 'Invalida reenvíos y desafíos bloqueados',
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_verificaciones_attempts
    CHECK (attempts <= max_attempts),
  CONSTRAINT chk_verificaciones_max_attempts
    CHECK (max_attempts BETWEEN 1 AND 20),
  CONSTRAINT fk_verificaciones_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  INDEX idx_verificaciones_contacto (contacto_tipo, contacto_normalizado, created_at),
  INDEX idx_verificaciones_retencion (reservacion_id, created_at),
  INDEX idx_verificaciones_request_token (request_token),
  INDEX idx_verificaciones_expiracion (expires_at),
  INDEX idx_verificaciones_uso (used_at),
  INDEX idx_verificaciones_invalida (invalidated_at)
) COMMENT='Códigos temporales para verificar el contacto de clientes de reservaciones';

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
  closed_at          DATETIME NULL COMMENT 'Cierre real que libera la ocupacion fisica',
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
  INDEX idx_ticket_reservacion (reservacion_id),
  UNIQUE KEY uq_ticket_reservacion (reservacion_id)
);
-- Nota: mesero_id (para imprimir el mesero en el ticket) ya viene declarado con
-- su FK en el CREATE TABLE de arriba. El ALTER que lo añadía por separado se
-- eliminó: sobre una BD limpia fallaba con "Duplicate column name 'mesero_id'".

-- Pago dividido por comensal: cuando la cuenta se separa, cada comensal puede
-- pagar con un metodo distinto. El ticket registra 'dividido' si se mezclan metodos.
ALTER TABLE tickets
  MODIFY COLUMN metodo_pago ENUM('efectivo','tarjeta','dividido') NULL;

-- Fuente canonica de ocupacion fisica. Las columnas mesa_id y
-- mesa_secundaria_id se conservan temporalmente para tickets legacy.
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
  INDEX idx_ticket_mesas_mesa (mesa_id),
  INDEX idx_ticket_mesas_ticket (ticket_id)
) COMMENT='Relacion canonica N:M entre tickets y mesas ocupadas';

CREATE TABLE IF NOT EXISTS reservacion_eventos (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservacion_id  INT NOT NULL,
  ticket_id       INT NULL,
  usuario_id      INT NULL,
  evento          ENUM(
                    'llegada',
                    'inicio_servicio',
                    'ticket_cerrado',
                    'cancelacion',
                    'no_show',
                    'override_no_show',
                    'warning_reservacion',
                    'override_ocupacion',
                    'cambio_horario_con_conflictos'
                  ) NOT NULL,
  estado_anterior VARCHAR(32) NULL,
  estado_nuevo    VARCHAR(32) NULL,
  motivo          VARCHAR(500) NULL,
  metadata_json   JSON NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservacion_eventos_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservacion_eventos_ticket
    FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE SET NULL,
  CONSTRAINT fk_reservacion_eventos_usuario
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_eventos_reservacion_fecha (reservacion_id, created_at),
  INDEX idx_eventos_ticket (ticket_id),
  INDEX idx_eventos_evento_fecha (evento, created_at)
) COMMENT='Auditoria de llegada, servicio y excepciones operativas';


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
-- CAMBIOS MODULO DE AJUSTES
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS horarios_operacion (
  id            TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  dia_semana    TINYINT UNSIGNED NOT NULL
                  COMMENT '0=Dom 1=Lun 2=Mar 3=Mie 4=Jue 5=Vie 6=Sab',
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
