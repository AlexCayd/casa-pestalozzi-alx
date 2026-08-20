-- Casa Pestalozzi — Esquema (DDL)
-- Estructura de la base de datos: DROP + CREATE TABLE.
-- Los datos de siembra viven en dml_operativo.sql y dml_pruebas.sql;
-- ambos se ejecutan después de este archivo.
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
-- Inventario / finanzas (hijos primero).
-- historial_precios apunta a usuarios y a proveedores; ingrediente_proveedores
-- a ingredientes y a proveedores. Los tres van antes que proveedores, y
-- proveedores antes que ingredientes.
DROP TABLE IF EXISTS historial_precios;
DROP TABLE IF EXISTS ingrediente_proveedores;
DROP TABLE IF EXISTS proveedores;
DROP TABLE IF EXISTS gastos_fijos;
DROP TABLE IF EXISTS movimientos_inventario;
DROP TABLE IF EXISTS producto_componentes;
DROP TABLE IF EXISTS subreceta_ingredientes;
DROP TABLE IF EXISTS subrecetas;
DROP TABLE IF EXISTS ingredientes;
DROP TABLE IF EXISTS reportes_sistema;
DROP TABLE IF EXISTS configuracion_pos;
DROP TABLE IF EXISTS configuracion_anuncio;
DROP TABLE IF EXISTS buzon_notificaciones;
DROP TABLE IF EXISTS horario_impacto_reservaciones;
DROP TABLE IF EXISTS horario_impactos;
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
-- `menu` ya no se crea: su contenido vive en `productos`. El DROP se conserva
-- para que este script purgue la tabla en instalaciones anteriores que aún la
-- tengan; sin él quedaría huérfana para siempre.
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

-- Accesos: los administradores usan usuario + password alfanumerica
-- (password_hash); el personal de piso usa un NIP numerico de 4 digitos,
-- guardado hasheado con bcrypt (nip_hash). nip_lookup es un HMAC determinista
-- que permite localizar el candidato y respaldar la unicidad en la BD.
--
-- El rol decide a que vista se entra y que rutas se permiten: waiter al punto
-- de venta, cook a los tableros de area, admin a todo.
CREATE TABLE IF NOT EXISTS usuarios (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50) NOT NULL UNIQUE,
  nombre        VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  nip_hash      VARCHAR(255) NULL,
  nip_lookup    CHAR(64) NULL,
  rol           ENUM('admin','waiter','cook') NOT NULL DEFAULT 'waiter',
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_nip_lookup (nip_lookup),
  CONSTRAINT chk_usuarios_admin_sin_nip
    CHECK (rol <> 'admin' OR (nip_hash IS NULL AND nip_lookup IS NULL))
);

-- -------------------------------------------------------
-- RESERVACIONES
-- -------------------------------------------------------

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
                         'en_curso',
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
  INDEX idx_rm_mesa (mesa_id),
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
  comensales         INT NOT NULL DEFAULT 1,
  nombre             VARCHAR(120) DEFAULT NULL,
  hora_apertura      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  closed_at          DATETIME NULL,
  -- Momento del cobro usado por analítica y finanzas.
  hora_cierre        DATETIME NULL,
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


-- PRODUCTOS — fuente única de los platillos.
--
-- De aquí salen la carta pública, el PDF, el punto de venta, el inventario, el
-- COGS y el ruteo por área: un platillo se da de alta una sola vez.
--
-- El UNIQUE sobre `nombre` es lo que sostiene el enlace por nombre que usan
-- Inventario::aplicarVenta, el COGS de Finanzas y las sugerencias: un nombre
-- duplicado rompía el descuento de stock sin dar ningún error.
CREATE TABLE IF NOT EXISTS productos (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(120) NOT NULL,
  -- La validación de Producto la exige en altas nuevas; permanece nullable
  -- para aceptar catálogos históricos durante la transición.
  descripcion  TEXT NULL,
  categoria_id INT NOT NULL,
  precio       DECIMAL(8,2) NOT NULL,
  area_id      TINYINT UNSIGNED NOT NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  -- El UNIQUE es dependencia funcional, no higiene: el descuento de
  -- inventario, el COGS y el motor de sugerencias unen platillos por nombre.
  -- Estaba declarado dos veces, y eso hacia fallar el CREATE TABLE entero
  -- ("Duplicate key name") en cualquier base creada desde cero.
  UNIQUE KEY uq_productos_nombre (nombre),
  KEY idx_productos_cat_activo (categoria_id, activo),
  INDEX idx_productos_carta (activo, categoria_id),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id),
  FOREIGN KEY (area_id) REFERENCES areas_produccion(id)
);

-- Compatibilidad de lectura para instalaciones y datos de siembra anteriores.
-- La aplicación usa `productos` como fuente funcional; esta tabla permite
-- conservar datos descriptivos históricos durante la transición.
CREATE TABLE IF NOT EXISTS menu (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(120) NOT NULL,
  descripcion  TEXT NOT NULL,
  precio       DECIMAL(10,2) NOT NULL,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  categoria_id INT NOT NULL,
  UNIQUE KEY uq_menu_nombre (nombre),
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT
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
-- INVENTARIO / RECETAS
-- -------------------------------------------------------

-- Ingredientes: unidad de inventario con existencias. El stock puede quedar
-- negativo (se permite pedir aunque no haya existencias).
CREATE TABLE IF NOT EXISTS ingredientes (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(120) NOT NULL,
  unidad       ENUM('g','kg','ml','l','pza') NOT NULL DEFAULT 'g',
  stock        DECIMAL(12,3) NOT NULL DEFAULT 0,
  stock_minimo DECIMAL(12,3) NOT NULL DEFAULT 0,
  -- Costo por unidad de inventario (g, ml o pieza).
  costo        DECIMAL(10,4) NOT NULL DEFAULT 0,
  activo       TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Subrecetas: preparaciones intermedias reutilizables (p. ej. una salsa). Su
-- composición vive en subreceta_ingredientes.
CREATE TABLE IF NOT EXISTS subrecetas (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(120) NOT NULL,
  unidad      ENUM('g','kg','ml','l','pza') NOT NULL DEFAULT 'g',
  -- Cantidad producida por la receta base.
  rendimiento DECIMAL(12,3) NOT NULL DEFAULT 1,
  activo      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subreceta_ingredientes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subreceta_id   INT UNSIGNED NOT NULL,
  ingrediente_id INT UNSIGNED NOT NULL,
  cantidad       DECIMAL(12,3) NOT NULL DEFAULT 0,
  FOREIGN KEY (subreceta_id)   REFERENCES subrecetas(id) ON DELETE CASCADE,
  FOREIGN KEY (ingrediente_id) REFERENCES ingredientes(id) ON DELETE CASCADE,
  INDEX idx_si_sub (subreceta_id)
);

-- Receta principal de un producto: lista de componentes (ingredientes y/o
-- subrecetas) con su cantidad. ref_id apunta a ingredientes.id o subrecetas.id
-- según 'tipo' (relación polimórfica, sin FK sobre ref_id).
CREATE TABLE IF NOT EXISTS producto_componentes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  producto_id INT UNSIGNED NOT NULL,
  tipo        ENUM('ingrediente','subreceta') NOT NULL,
  -- Apunta a ingredientes.id o subrecetas.id según tipo.
  ref_id      INT UNSIGNED NOT NULL,
  cantidad    DECIMAL(12,3) NOT NULL DEFAULT 0,
  FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE,
  INDEX idx_pc_producto (producto_id)
);

-- Bitácora de movimientos de inventario (trazabilidad del descuento por venta).
--
-- `entrada` y `merma` se separan de `ajuste` a propósito: recibir mercancía,
-- corregir un conteo y tirar producto echado a perder son tres hechos distintos
-- con consecuencias contables distintas, y mientras los tres eran `ajuste` el
-- panel no podía valorizar la merma ni distinguir una compra de una corrección.
CREATE TABLE IF NOT EXISTS movimientos_inventario (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingrediente_id INT UNSIGNED NOT NULL,
  tipo           ENUM('venta','cancelacion','ajuste','entrada','merma') NOT NULL,
  -- Un valor negativo descuenta; uno positivo repone.
  cantidad       DECIMAL(12,3) NOT NULL,
  -- Por qué se perdió el producto: clave del catálogo de Services\Inventario.
  -- Solo lo llenan las mermas.
  motivo         VARCHAR(40) NULL,
  -- Detalle libre de quien registra la merma.
  nota           VARCHAR(255) NULL,
  -- Costo unitario del ingrediente en el momento del movimiento. Sin él, la
  -- merma de hace tres meses se valorizaba al costo de hoy.
  costo_unitario DECIMAL(10,4) NULL,
  -- Quién lo registró. Las salidas por venta no lo llevan: las genera el POS.
  usuario_id     INT NULL,
  ticket_item_id INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ingrediente_id) REFERENCES ingredientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_mi_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_mi_ing (ingrediente_id),
  INDEX idx_mi_ti (ticket_item_id),
  -- Los tableros agregan por tipo dentro de un rango de fechas.
  INDEX idx_mi_tipo_fecha (tipo, created_at)
);

-- Proveedores que surten los insumos.
--
-- Cuelgan de `ingredientes` y no de `productos`: lo que se compra son los
-- insumos; un platillo se produce aquí. Un mismo ingrediente puede tener varios
-- proveedores a precios distintos, y esa comparación es justo el motivo de la
-- tabla — antes la entrada de mercancía era anónima y no había forma de saber a
-- quién se le compró más caro.
CREATE TABLE IF NOT EXISTS proveedores (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(120) NOT NULL,
  -- Persona de contacto; el resto son datos para volver a marcarles.
  contacto   VARCHAR(120) NULL,
  telefono   VARCHAR(30) NULL,
  correo     VARCHAR(120) NULL,
  notas      TEXT NULL,
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_proveedores_nombre (nombre)
);

-- Precio de compra de un ingrediente por proveedor.
CREATE TABLE IF NOT EXISTS ingrediente_proveedores (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ingrediente_id INT UNSIGNED NOT NULL,
  proveedor_id   INT UNSIGNED NOT NULL,
  -- En la unidad de inventario del ingrediente (g, ml o pieza), la misma que
  -- ingredientes.costo, para poder compararlos sin convertir nada.
  costo          DECIMAL(10,4) NOT NULL DEFAULT 0,
  -- Clave con la que el proveedor identifica el producto en su catálogo.
  codigo         VARCHAR(60) NULL,
  -- El preferente es el que se propone al recibir mercancía. No se fuerza a uno
  -- solo por ingrediente desde el esquema: durante un cambio de proveedor
  -- conviven dos marcados mientras se decide, y el servicio ya deja uno.
  preferente     TINYINT(1) NOT NULL DEFAULT 0,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ing_prov_ingrediente FOREIGN KEY (ingrediente_id) REFERENCES ingredientes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ing_prov_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE CASCADE,
  -- Un proveedor no puede tener dos precios para el mismo ingrediente.
  UNIQUE KEY uq_ing_prov (ingrediente_id, proveedor_id),
  INDEX idx_ing_prov_proveedor (proveedor_id)
);

-- Histórico de precios de platillos y de costos de insumos.
--
-- El precio vigente vive en productos.precio y en ingredientes.costo, que se
-- pisan en cada edición: sin esta bitácora no había manera de contestar "¿desde
-- cuándo subió el café?" ni de explicar por qué cayó el margen de un mes.
--
-- `entidad` + `ref_id` es la misma relación polimórfica que usa
-- producto_componentes, y por lo mismo ref_id no lleva FK: apunta a
-- productos.id o a ingredientes.id según entidad.
CREATE TABLE IF NOT EXISTS historial_precios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entidad         ENUM('producto','ingrediente') NOT NULL,
  ref_id          INT UNSIGNED NOT NULL,
  -- NULL sólo en el alta: antes no había precio del que venir.
  precio_anterior DECIMAL(10,4) NULL,
  precio_nuevo    DECIMAL(10,4) NOT NULL,
  -- DECIMAL(10,4) cubre las dos escalas: productos.precio es (8,2) e
  -- ingredientes.costo (10,4).
  motivo          ENUM('alta','edicion','proveedor') NOT NULL DEFAULT 'edicion',
  -- Sólo en los cambios que vienen de recibir mercancía a otro precio.
  proveedor_id    INT UNSIGNED NULL,
  -- usuarios.id es INT con signo, no UNSIGNED.
  usuario_id      INT NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_hp_proveedor FOREIGN KEY (proveedor_id) REFERENCES proveedores(id) ON DELETE SET NULL,
  CONSTRAINT fk_hp_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  -- La consulta que sirve la ficha: los últimos cambios de una entidad.
  INDEX idx_hp_entidad (entidad, ref_id, created_at)
);

-- Gastos fijos mensuales del negocio (renta, luz, agua, nómina, etc.). Se usan
-- para calcular la utilidad neta y dar transparencia financiera.
CREATE TABLE IF NOT EXISTS gastos_fijos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(120) NOT NULL,
  categoria  ENUM('renta','servicios','nomina','insumos','otros') NOT NULL DEFAULT 'otros',
  -- Monto mensual.
  monto      DECIMAL(12,2) NOT NULL DEFAULT 0,
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- MENÚ
-- -------------------------------------------------------
--
-- No hay tabla propia: la carta se sirve desde 'productos' (definida arriba,
-- ver TICKETS / COMANDA). La carta pública y el POS son la misma consulta,
-- 'productos WHERE activo = 1', así que un platillo dado de baja desaparece de
-- ambos a la vez.

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
  INDEX idx_horario_impacto_reservaciones_access (access_token_hash, access_expires_at)
);

-- Buzón administrativo genérico. No almacena PII ni sustituye la autoridad
-- del módulo fuente; entidad_tipo + entidad_id apunta al caso operativo.
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

-- Ajustes del punto de venta. Fila única (id = 1), como configuracion_anuncio:
-- son preferencias del sistema, no un catálogo.
CREATE TABLE IF NOT EXISTS configuracion_pos (
  id               TINYINT UNSIGNED NOT NULL,
  -- 1 = el mesero se elige a mano al abrir la mesa (comportamiento histórico).
  -- 0 = el campo queda bloqueado con el usuario de la sesión, para turnos en
  --     los que cada quien usa su propia tablet y la selección manual solo
  --     abre la puerta a asignar tickets a otro por error.
  mesero_editable  TINYINT(1) NOT NULL DEFAULT 1,
  updated_by       INT NULL,
  updated_at       TIMESTAMP NOT NULL
                     DEFAULT CURRENT_TIMESTAMP
                     ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),

  CONSTRAINT fk_configuracion_pos_usuario
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
