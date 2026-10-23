-- Casa Pestalozzi — Esquema completo (instalación desde cero)
-- Estructura de la base de datos: DROP + CREATE TABLE.
-- Los datos mínimos de arranque viven en seed.sql, que se ejecuta después.
-- Para llevar una base anterior a este esquema: migrations/001_simplificar_esquema.sql
-- y, después, migrations/002_paleta_landing.sql.
-- Ejecutar contra la BD configurada en includes/.env (DB_NAME).

-- -------------------------------------------------------
-- RESET (orden inverso de dependencias)
-- -------------------------------------------------------
DROP TABLE IF EXISTS configuracion_paleta;
DROP TABLE IF EXISTS configuracion_anuncio;
DROP TABLE IF EXISTS buzon_notificaciones;
DROP TABLE IF EXISTS horario_impacto_reservaciones;
DROP TABLE IF EXISTS horario_impactos;
DROP TABLE IF EXISTS excepciones_operacion;
DROP TABLE IF EXISTS horarios_operacion;
DROP TABLE IF EXISTS catas;
DROP TABLE IF EXISTS configuracion_reservaciones;
DROP TABLE IF EXISTS reservacion_recordatorios;
DROP TABLE IF EXISTS reservacion_mesas;
DROP TABLE IF EXISTS verificaciones_contacto;
DROP TABLE IF EXISTS reservaciones;
DROP TABLE IF EXISTS mesas;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS categorias;
DROP TABLE IF EXISTS usuarios;

-- -------------------------------------------------------
-- ACCESO AL PANEL
-- -------------------------------------------------------

-- Un solo tipo de usuario: administrador, con usuario y contraseña.
CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL UNIQUE,
  nombre        VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- MENÚ
-- -------------------------------------------------------

-- `carta` parte el catálogo en las dos piezas impresas: la carta de comida y
-- la de maridaje (barra). Vive en la categoría porque la carta es una
-- decisión de SECCIÓN: "Cocktails" entero es maridaje.
--
-- 'temporada' no es papel: es la vitrina «Especiales de temporada» de la
-- landing. Sus platillos cambian cada semana y se dan de alta en Menú como
-- cualquier otro; no salen en la sección de menú ni en ningún PDF. seed.sql
-- siembra la categoría «Especiales de temporada».
CREATE TABLE IF NOT EXISTS categorias (
  id     INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(40) NOT NULL,
  carta  ENUM('comida','maridaje','temporada') NOT NULL DEFAULT 'comida',
  img    VARCHAR(200),
  activo TINYINT(1) NOT NULL DEFAULT 1,
  KEY idx_categorias_carta (carta, id)
);

CREATE TABLE IF NOT EXISTS productos (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(120) NOT NULL,
  -- La validación de Producto la exige en altas nuevas; permanece nullable
  -- para aceptar catálogos históricos durante la transición.
  descripcion  TEXT NULL,
  -- Foto opcional del platillo. Hoy sólo la pinta la vitrina de especiales de
  -- temporada; sin ella, el especial toma la de su categoría.
  img          VARCHAR(200) NULL,
  categoria_id INT NOT NULL,
  precio       DECIMAL(8,2) NOT NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  -- Dos platillos homonimos en la carta no se distinguirian.
  UNIQUE KEY uq_productos_nombre (nombre),
  KEY idx_productos_cat_activo (categoria_id, activo),
  INDEX idx_productos_carta (activo, categoria_id),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id)
);

-- -------------------------------------------------------
-- RESERVACIONES
-- -------------------------------------------------------

-- Plano del salón: las mesas que el motor de reservaciones asigna.
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

CREATE TABLE IF NOT EXISTS reservaciones (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  nombre               VARCHAR(100) NOT NULL,
  contacto_tipo        ENUM('email','telefono','ninguno') NOT NULL DEFAULT 'ninguno',
  -- El contacto se persiste en su formato canónico, normalizado en PHP.
  contacto             VARCHAR(150) NULL,
  fecha                DATE NOT NULL,
  hora                 TIME NOT NULL,
  comensales           INT UNSIGNED NOT NULL DEFAULT 2,
  nota                 TEXT,
  comentario_admin     TEXT NULL,
  motivo_cancelacion   VARCHAR(500) NULL,
  origen               ENUM('landing','admin') NOT NULL,
  request_token        VARCHAR(64) NULL,
  -- Una retención vencida deja de ocupar mesas aun antes del proceso de limpieza.
  hold_expires_at      DATETIME NULL,
  estado               ENUM(
                         'pendiente_verificacion',
                         'confirmada',
                         'completada',
                         'cancelada',
                         'no_show',
                         'expirada',
                         'reemplazada'
                       ) NOT NULL DEFAULT 'pendiente_verificacion',
  reemplaza_reservacion_id INT NULL,
  estado_changed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_reservaciones_fecha_estado_hora (fecha, estado, hora),
  INDEX idx_reservaciones_contacto_horario (contacto_tipo, contacto, fecha, hora, estado),
  INDEX idx_reservaciones_retenciones_vencidas (estado, hold_expires_at),
  INDEX idx_reservaciones_reemplazo (reemplaza_reservacion_id),
  CONSTRAINT chk_reservaciones_comensales
    CHECK (comensales > 0),
  CONSTRAINT chk_reservaciones_contacto
    CHECK (
      (contacto_tipo = 'ninguno' AND contacto IS NULL)
      OR
      (contacto_tipo IN ('email','telefono') AND contacto IS NOT NULL AND TRIM(contacto) <> '')
    ),
  CONSTRAINT chk_reservaciones_retencion_vencimiento
    CHECK (estado <> 'pendiente_verificacion' OR hold_expires_at IS NOT NULL),
  CONSTRAINT fk_reservacion_reemplazada
    FOREIGN KEY (reemplaza_reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_reservaciones_request_token (request_token)
);

-- MySQL no permite referenciar una columna AUTO_INCREMENT desde un CHECK.
-- La regla de no auto-reemplazo se mantiene en la frontera de persistencia.
DELIMITER //
DROP TRIGGER IF EXISTS trg_reservaciones_no_auto_reemplazo_insert//
CREATE TRIGGER trg_reservaciones_no_auto_reemplazo_insert
BEFORE INSERT ON reservaciones
FOR EACH ROW
BEGIN
  IF NEW.reemplaza_reservacion_id IS NOT NULL
     AND NEW.id IS NOT NULL
     AND NEW.reemplaza_reservacion_id = NEW.id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una reservacion no puede reemplazarse a si misma';
  END IF;
END//
DROP TRIGGER IF EXISTS trg_reservaciones_no_auto_reemplazo_update//
CREATE TRIGGER trg_reservaciones_no_auto_reemplazo_update
BEFORE UPDATE ON reservaciones
FOR EACH ROW
BEGIN
  IF NEW.reemplaza_reservacion_id IS NOT NULL
     AND NEW.reemplaza_reservacion_id = NEW.id THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una reservacion no puede reemplazarse a si misma';
  END IF;
END//
DELIMITER ;

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
  delivery_status ENUM('legacy','pending','accepted','failed') NOT NULL DEFAULT 'pending',
  accepted_at DATETIME NULL,
  finalized_at DATETIME NULL,
  cycle_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  cycle_expires_at DATETIME NULL,
  used_at        DATETIME NULL,
  invalidated_at DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_verificacion_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE CASCADE,
  INDEX idx_verificacion_contacto (contacto_tipo, contacto, created_at),
  INDEX idx_verificacion_reservacion (reservacion_id),
  INDEX idx_verificacion_expiracion (expires_at),
  INDEX idx_verificacion_ciclo (contacto_tipo, contacto, reservacion_id, cycle_id, id)
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
  INDEX idx_rm_mesa (mesa_id),
  INDEX idx_rm_reservacion (reservacion_id)
);

-- Recordatorios ordinarios: una fila por raíz/tipo/fecha, sin duplicar PII.
CREATE TABLE IF NOT EXISTS reservacion_recordatorios (
  id                              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reservacion_id                  INT NOT NULL,
  reservacion_raiz_id             INT NOT NULL,
  tipo                            ENUM('dia_anterior') NOT NULL DEFAULT 'dia_anterior',
  dedup_key                       VARCHAR(191) NOT NULL,
  access_token_hash               CHAR(64) NULL,
  access_expires_at               DATETIME NULL,
  access_invalidated_at           DATETIME NULL,
  notification_delivery_status    ENUM('pending', 'accepted', 'failed')
                                    NOT NULL DEFAULT 'pending',
  notification_delivery_updated_at DATETIME NULL,
  created_at                      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notification_attempts           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  transport_claimed_at             DATETIME NULL,
  retryable                       TINYINT(1) NOT NULL DEFAULT 0,
  updated_at                      TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_reservacion_recordatorios_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reservacion_recordatorios_raiz
    FOREIGN KEY (reservacion_raiz_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  UNIQUE KEY uq_reservacion_recordatorios_dedup (dedup_key),
  UNIQUE KEY uq_reservacion_recordatorios_access (access_token_hash),
  INDEX idx_reservacion_recordatorios_reservacion (reservacion_id),
  INDEX idx_reservacion_recordatorios_raiz (reservacion_raiz_id),
  INDEX idx_reservacion_recordatorios_delivery (notification_delivery_status)
);

-- Comunicaciones de reservaciones. Fila única y desactivada por omisión.
CREATE TABLE IF NOT EXISTS configuracion_reservaciones (
  id                                  TINYINT UNSIGNED NOT NULL,
  recordatorio_dia_anterior_activo    TINYINT(1) NOT NULL DEFAULT 0,
  hora_recordatorio                   TIME NOT NULL DEFAULT '18:00:00',
  updated_by                          INT NULL,
  updated_at                          TIMESTAMP NOT NULL
                                        DEFAULT CURRENT_TIMESTAMP
                                        ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_configuracion_reservaciones_usuario
    FOREIGN KEY (updated_by) REFERENCES usuarios(id) ON DELETE SET NULL
);

INSERT INTO configuracion_reservaciones
  (id, recordatorio_dia_anterior_activo, hora_recordatorio, updated_by)
VALUES (1, 0, '18:00:00', NULL);

-- -------------------------------------------------------
-- CATAS
-- -------------------------------------------------------

-- La agenda que el panel programa y la landing publica. Es una tabla de
-- anuncio, no de reservas: `disponible` sólo dice si quedan lugares; la
-- visibilidad la decide el reloj.
CREATE TABLE IF NOT EXISTS catas (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  titulo        VARCHAR(120) NOT NULL,
  descripcion   TEXT NULL,
  fecha         DATE NOT NULL,
  hora          TIME NOT NULL,
  duracion_min  SMALLINT UNSIGNED NOT NULL DEFAULT 90,
  precio        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  -- ¿Quedan lugares? Eso y nada más. NO decide la visibilidad: la agenda
  -- pública muestra toda cata que no haya ocurrido todavía, y una sin cupo
  -- sigue anunciada, sólo que marcada. Nace en 1 porque una cata recién
  -- programada admite gente; se cierra el día que se llena.
  disponible    TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  -- El índice va por fecha: la consulta pública filtra por el reloj y ya no
  -- toca `disponible`, así que encabezar por el booleano no servía de nada.
  INDEX idx_catas_agenda (fecha, hora),
  CONSTRAINT chk_catas_precio CHECK (precio >= 0),
  CONSTRAINT chk_catas_duracion CHECK (duracion_min > 0)
);

-- -------------------------------------------------------
-- HORARIOS
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

-- Seguimiento durable de reservaciones que quedan fuera del horario efectivo.
-- Estas tablas no cambian el estado canónico de `reservaciones` ni duplican PII.
CREATE TABLE IF NOT EXISTS horario_impactos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo_origen  VARCHAR(40) NOT NULL,
  origen_id    INT UNSIGNED NULL,
  estado       ENUM('pendiente', 'resuelto') NOT NULL DEFAULT 'pendiente',
  dedup_key    CHAR(64) NOT NULL,
  created_by   INT NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at  DATETIME NULL,
  CONSTRAINT fk_horario_impactos_usuario
    FOREIGN KEY (created_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_horario_impactos_dedup (dedup_key),
  INDEX idx_horario_impactos_estado_fecha (estado, created_at)
);

CREATE TABLE IF NOT EXISTS horario_impacto_reservaciones (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  impacto_id            INT UNSIGNED NOT NULL,
  reservacion_id        INT NOT NULL,
  estado                ENUM(
                           'pendiente_notificacion',
                           'notificacion_preparada',
                           'sin_contacto',
                           'atendida_manual',
                           'resuelta_por_cliente'
                         ) NOT NULL,
  notification_prepared_at DATETIME NULL,
  access_token_hash      CHAR(64) NULL,
  access_expires_at      DATETIME NULL,
  access_invalidated_at  DATETIME NULL,
  notification_attempts  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_notification_at   DATETIME NULL,
  notification_delivery_status ENUM('pending', 'accepted', 'failed')
                           NOT NULL DEFAULT 'pending',
  notification_delivery_updated_at DATETIME NULL,
  resolved_by           INT NULL,
  resolved_at           DATETIME NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_horario_impacto_reservaciones_impacto
    FOREIGN KEY (impacto_id) REFERENCES horario_impactos(id) ON DELETE CASCADE,
  CONSTRAINT fk_horario_impacto_reservaciones_reservacion
    FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_horario_impacto_reservaciones_usuario
    FOREIGN KEY (resolved_by) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_horario_impacto_reservacion (impacto_id, reservacion_id),
  INDEX idx_horario_impacto_reservaciones_estado (impacto_id, estado),
  INDEX idx_horario_impacto_reservaciones_access (access_token_hash, access_expires_at),
  INDEX idx_horario_impacto_reservaciones_delivery (notification_delivery_status)
);

-- -------------------------------------------------------
-- PANEL
-- -------------------------------------------------------

-- Buzón administrativo. No almacena PII ni sustituye la autoridad del módulo
-- fuente; entidad_tipo + entidad_id apunta al caso operativo.
CREATE TABLE IF NOT EXISTS buzon_notificaciones (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo           VARCHAR(80) NOT NULL,
  modulo         VARCHAR(60) NOT NULL,
  entidad_tipo   VARCHAR(80) NOT NULL,
  entidad_id     INT UNSIGNED NOT NULL,
  prioridad      ENUM('normal', 'alta') NOT NULL DEFAULT 'normal',
  requiere_accion TINYINT(1) NOT NULL DEFAULT 1,
  visible_from   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  leida_at       DATETIME NULL,
  cerrada_at     DATETIME NULL,
  cerrada_por    INT NULL,
  cierre_motivo  VARCHAR(120) NULL,
  dedup_key      VARCHAR(191) NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_buzon_notificaciones_usuario
    FOREIGN KEY (cerrada_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_buzon_notificaciones_dedup (dedup_key),
  INDEX idx_buzon_notificaciones_visibles (cerrada_at, visible_from),
  INDEX idx_buzon_notificaciones_tipo (tipo, cerrada_at),
  INDEX idx_buzon_notificaciones_accion (cerrada_at, visible_from, requiere_accion),
  INDEX idx_buzon_notificaciones_entidad (entidad_tipo, entidad_id)
);

-- Aviso de la landing. Fila única (id = 1).
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

-- Paleta de la landing. Fila única (id = 1), como configuracion_anuncio.
-- Cada columna sobrescribe UNO de los cuatro colores de la capa 1 de
-- src/scss/layout/_reset.scss (--brand-verde, --brand-cafe, --brand-beige,
-- --brand-crema); escalas y roles se derivan solos con color-mix.
-- NULL = el valor del manual de marca, que es el del SCSS. Se guarda NULL y no
-- el hex de marca para que un color que nadie tocó siga al manual si algún día
-- cambia la capa 1. Sin fila, o sin tabla, la landing se pinta como siempre.
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
