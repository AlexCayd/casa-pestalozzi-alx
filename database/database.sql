-- Casa Pestalozzi — Base de datos completa
-- Ejecutar contra la BD configurada en includes/.env (DB_NAME)

-- -------------------------------------------------------
-- RESET (orden inverso de dependencias)
-- -------------------------------------------------------

DROP TABLE IF EXISTS ticket_items;
DROP TABLE IF EXISTS productos;
DROP TABLE IF EXISTS tickets;
DROP TABLE IF EXISTS reservaciones;
DROP TABLE IF EXISTS areas_produccion;
DROP TABLE IF EXISTS mesas;
DROP TABLE IF EXISTS horarios_reservacion;
DROP TABLE IF EXISTS dias_reservacion;

-- -------------------------------------------------------
-- TABLAS
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

CREATE TABLE IF NOT EXISTS reservaciones (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  nombre             VARCHAR(100) NOT NULL,
  email              VARCHAR(150) NOT NULL,
  fecha              DATE NOT NULL,
  hora               TIME NOT NULL,
  comensales         INT NOT NULL DEFAULT 2,
  nota               TEXT,
  estado             ENUM('pendiente','cancelada') NOT NULL DEFAULT 'pendiente',
  mesa_id            INT NULL,
  mesa_secundaria_id INT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reserva_mesa  FOREIGN KEY (mesa_id)            REFERENCES mesas(id) ON DELETE SET NULL,
  CONSTRAINT fk_reserva_mesa2 FOREIGN KEY (mesa_secundaria_id) REFERENCES mesas(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS tickets (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  mesa_id            INT NOT NULL,
  mesa_secundaria_id INT NULL,
  comensales         INT NOT NULL DEFAULT 1,
  nombre             VARCHAR(120) DEFAULT NULL,
  hora_apertura      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado             ENUM('abierto','cerrado','cancelado') NOT NULL DEFAULT 'abierto',
  reservacion_id     INT NULL,
  FOREIGN KEY (mesa_id)            REFERENCES mesas(id),
  FOREIGN KEY (mesa_secundaria_id) REFERENCES mesas(id) ON DELETE SET NULL,
  FOREIGN KEY (reservacion_id)     REFERENCES reservaciones(id) ON DELETE SET NULL,
  INDEX idx_estado_mesa (estado, mesa_id)
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
  comensal   TINYINT UNSIGNED NULL COMMENT 'NULL = General',
  cantidad   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  estado     ENUM('enviado','en_preparacion','listo','entregado') NOT NULL DEFAULT 'enviado',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (area_id)   REFERENCES areas_produccion(id),
  INDEX idx_area_estado (area_id, estado),
  INDEX idx_ti_ticket   (ticket_id)
);

-- -------------------------------------------------------
-- DATOS
-- -------------------------------------------------------

-- Días disponibles
INSERT INTO dias_reservacion (id, dia_semana, nombre, hora_apertura, hora_cierre) VALUES
(1, 0, 'Domingo',   '08:30', '19:00'),
(2, 1, 'Lunes',     '08:30', '15:00'),
(3, 2, 'Martes',    '08:30', '22:00'),
(4, 3, 'Miércoles', '08:30', '22:00'),
(5, 4, 'Jueves',    '08:30', '22:00'),
(6, 5, 'Viernes',   '08:30', '22:00'),
(7, 6, 'Sábado',    '08:30', '22:00');

-- Horarios por día
-- Domingo (dia_id=1) — cierra 19:00, último slot 18:00
INSERT INTO horarios_reservacion (dia_id, hora) VALUES
(1, '09:00'), (1, '10:00'), (1, '11:00'), (1, '12:00'),
(1, '13:00'), (1, '14:00'), (1, '15:00'), (1, '16:00'),
(1, '17:00'), (1, '18:00');

-- Lunes (dia_id=2) — cierra 15:00, último slot 14:00
INSERT INTO horarios_reservacion (dia_id, hora) VALUES
(2, '09:00'), (2, '10:00'), (2, '11:00'), (2, '12:00'),
(2, '13:00'), (2, '14:00');

-- Martes–Sábado (dia_id=3–7) — cierran 22:00, último slot 21:00
INSERT INTO horarios_reservacion (dia_id, hora) VALUES
(3, '09:00'), (3, '10:00'), (3, '11:00'), (3, '12:00'), (3, '13:00'),
(3, '14:00'), (3, '15:00'), (3, '16:00'), (3, '17:00'), (3, '18:00'),
(3, '19:00'), (3, '20:00'), (3, '21:00'),

(4, '09:00'), (4, '10:00'), (4, '11:00'), (4, '12:00'), (4, '13:00'),
(4, '14:00'), (4, '15:00'), (4, '16:00'), (4, '17:00'), (4, '18:00'),
(4, '19:00'), (4, '20:00'), (4, '21:00'),

(5, '09:00'), (5, '10:00'), (5, '11:00'), (5, '12:00'), (5, '13:00'),
(5, '14:00'), (5, '15:00'), (5, '16:00'), (5, '17:00'), (5, '18:00'),
(5, '19:00'), (5, '20:00'), (5, '21:00'),

(6, '09:00'), (6, '10:00'), (6, '11:00'), (6, '12:00'), (6, '13:00'),
(6, '14:00'), (6, '15:00'), (6, '16:00'), (6, '17:00'), (6, '18:00'),
(6, '19:00'), (6, '20:00'), (6, '21:00'),

(7, '09:00'), (7, '10:00'), (7, '11:00'), (7, '12:00'), (7, '13:00'),
(7, '14:00'), (7, '15:00'), (7, '16:00'), (7, '17:00'), (7, '18:00'),
(7, '19:00'), (7, '20:00'), (7, '21:00');

-- Mesas — pos_x / pos_y = % del centro del pin sobre el canvas
INSERT INTO mesas (numero, nombre, tipo, capacidad, pos_x, pos_y, reservable) VALUES
(1,  'Mesa 1',       'mesa',     4, 29.0, 88.0, 1),
(2,  'Mesa 2',       'mesa',     4,  8.0, 70.0, 1),
(3,  'Mesa 3',       'mesa',     4, 29.0, 51.0, 1),
(4,  'Mesa 4',       'mesa',     4,  8.0, 51.0, 1),
(5,  'Mesa 5',       'mesa',     4,  8.0, 29.0, 1),
(6,  'Mesa 6',       'mesa',     4, 45.0, 29.0, 1),
(7,  'Mesa 7',       'mesa',     4, 83.0, 29.0, 1),
(8,  'Mesa 8',       'mesa',     4, 83.0,  8.0, 1),
(9,  'Mesa 9',       'mesa',     4, 54.0,  8.0, 1),
(10, 'Mesa 10',      'mesa',     4, 29.0,  8.0, 1),
(11, 'Mesa 11',      'mesa',     4,  8.0,  8.0, 1),
(12, 'Barra Blanca', 'barra',    8, 62.0, 51.0, 0),
(13, 'Caja',         'especial', 0, 41.0, 70.0, 0),
(14, 'Llevar',       'especial', 0, 58.0, 70.0, 0),
(15, 'Barra Roja',   'barra',    6, 83.0, 70.0, 0),
(16, 'Barra Roja 2', 'barra',    6, 83.0, 88.0, 0);

-- Áreas de producción
INSERT INTO areas_produccion (id, nombre, slug, color) VALUES
(1, 'Barra de Café',    'cafe',   '#7b5e3a'),
(2, 'Barra de Jugos',   'jugos',  '#e8a920'),
(3, 'Cocina',           'cocina', '#b03a2e'),
(4, 'Horno Napolitano', 'horno',  '#1a5276');

-- Productos
INSERT INTO productos (nombre, categoria, precio, area_id) VALUES
('Enmoladas',                                           'Desayunos',        240.00, 3),
('Enchiladas Suizas',                                   'Desayunos',        220.00, 3),
('Cecina y Huevo con Chorizo',                          'Desayunos',        220.00, 3),
('Cazuela Cascabel',                                    'Desayunos',        220.00, 3),
('Sopes con Cecina o Arrachera',                        'Desayunos',        220.00, 3),
('Enfrijoladas',                                        'Desayunos',        220.00, 3),
('Huevos al Parmesano',                                 'Desayunos',        210.00, 3),
('Omelette Fitness',                                    'Desayunos',        190.00, 3),
('Toast de Salmón Ahumado',                             'Desayunos',        230.00, 3),
('Pan Francés Estilo C.P.',                             'Desayunos',        210.00, 3),
('Huevos Módena',                                       'Desayunos',        190.00, 3),
('Huevos Italianos',                                    'Desayunos',        190.00, 3),
('Huevos Pamplona',                                     'Desayunos',        190.00, 3),
('Huevos al Sano',                                      'Desayunos',        190.00, 3),
('Huevos al Gusto',                                     'Desayunos',        180.00, 3),
('Molletes',                                            'Desayunos',        100.00, 3),
('Casa Pestalozzi',                                     'Desayunos',        180.00, 3),
('Chilaquiles',                                         'Desayunos',        180.00, 3),
('Baguette de Jamón Serrano',                           'Desayunos',        220.00, 3),
('Baguette de Magret de Pollo',                         'Desayunos',        220.00, 3),
('Baguette con Arrachera',                              'Desayunos',        230.00, 3),
('Croissant con Jamón de Pavo',                         'Desayunos',        165.00, 3),
('Croissant con Huevo y Estragón',                      'Desayunos',        140.00, 3),
('Baguette de Cochinita',                               'Desayunos',        210.00, 3),
('Plato de Fruta Mixta',                                'Desayunos',        110.00, 2),
('Copa Antioxidante',                                   'Desayunos',        130.00, 2),
('Aros de Calamar',                                     'Entradas',         210.00, 3),
('Tostadas de Atún',                                    'Entradas',         195.00, 3),
('Torreta de Salmón',                                   'Entradas',         220.00, 3),
('Tiradito de Atún',                                    'Entradas',         210.00, 3),
('Carpaccio de Salmón',                                 'Entradas',         180.00, 3),
('Camarones al Ajillo',                                 'Entradas',         210.00, 3),
('Espárragos al Horno',                                 'Entradas',         180.00, 4),
('Queso Burrata con Jitomates Cherrys',                 'Entradas',         210.00, 4),
('Crema del Día',                                       'Sopas & Cremas',   180.00, 3),
('Sopa Especial de Fin de Semana',                      'Sopas & Cremas',   180.00, 3),
('Fetuccini a los Cuatro Quesos y Camarones',           'Pastas',           280.00, 3),
('Lasagna de Filete de Res',                            'Pastas',           280.00, 3),
('Rigatoni al Limón con Camarones y Parmesano',         'Pastas',           280.00, 3),
('Spaguetti a l''Arrabbiata con Camarones y Parmesano', 'Pastas',           280.00, 3),
('Spaguetti a la Boloñesa',                             'Pastas',           280.00, 3),
('Spaguetti al Pomodoro y Parmesano',                   'Pastas',           190.00, 3),
('Filete de Res en su Jugo',                            'Platos Fuertes',   320.00, 3),
('Salmón al Horno',                                     'Platos Fuertes',   295.00, 3),
('Hamburguesa de la Casa',                              'Platos Fuertes',   260.00, 3),
('Atún Sellado',                                        'Platos Fuertes',   285.00, 3),
('Tacos de Cochinita',                                  'Platos Fuertes',   210.00, 3),
('Tacos de Vacío',                                      'Platos Fuertes',   210.00, 3),
('Tacos de Camarón Rebozados',                          'Platos Fuertes',   240.00, 3),
('Vacío en Escalopas',                                  'Platos Fuertes',   280.00, 3),
('New York (450 grs.)',                                 'Platos Fuertes',   785.00, 3),
('Rib Eye (450 grs.)',                                  'Platos Fuertes',   785.00, 3),
('Frutos Rojos',                                        'Ensaladas',        210.00, 3),
('Ciruela Betabel',                                     'Ensaladas',        210.00, 3),
('Magret de Pollo',                                     'Ensaladas',        210.00, 3),
('Jamón Serrano con Perlas de Melón',                   'Ensaladas',        210.00, 3),
('Pasta Corta con Pollo',                               'Ensaladas',        210.00, 3),
('Margarita',                                           'Pizzas',           190.00, 4),
('Burrata',                                             'Pizzas',           260.00, 4),
('Milano',                                             'Pizzas',           260.00, 4),
('Camarones a los 4 Quesos',                            'Pizzas',           260.00, 4),
('Mix de 3 Brusquetas',                                 'Para Picar',       160.00, 3),
('Aceitunas Temperadas con Aceite de Chile',            'Para Picar',       160.00, 3),
('Tabla Mixta',                                         'Para Picar',       320.00, 3),
('Papas a la Francesa con Parmesano',                   'Para Picar',       160.00, 3),
('Café Americano',                                      'Café & Bebidas',    65.00, 1),
('Cappuccino',                                          'Café & Bebidas',    75.00, 1),
('Latte',                                               'Café & Bebidas',    80.00, 1),
('Café de Olla',                                        'Café & Bebidas',    65.00, 1),
('Té / Infusión',                                       'Café & Bebidas',    65.00, 1),
('Chocolate Caliente',                                  'Café & Bebidas',    80.00, 1),
('Agua Fresca',                                         'Café & Bebidas',    60.00, 1),
('Refresco',                                            'Café & Bebidas',    55.00, 1),
('Jugo de Naranja',                                     'Jugos & Smoothies', 85.00, 2),
('Jugo Verde',                                          'Jugos & Smoothies', 95.00, 2),
('Limonada Natural',                                    'Jugos & Smoothies', 75.00, 2),
('Smoothie de Fresa',                                   'Jugos & Smoothies',100.00, 2),
('Agua de Coco',                                        'Jugos & Smoothies', 90.00, 2);

-- Reservaciones de ejemplo — escenario viernes 2026-06-19
-- Actualizar la fecha al día actual antes de usar en producción
-- Al llegar el cliente: abrir ticket a su nombre y eliminar la reserva
INSERT INTO reservaciones (nombre, email, fecha, hora, comensales, nota, estado, mesa_id, mesa_secundaria_id) VALUES
('Camila Estrada',   'cestrada@ejemplo.com',  '2026-06-19', '09:00:00', 2, '',                          'pendiente', 5,  NULL),
('Javier Montiel',   'jmontiel@ejemplo.com',  '2026-06-19', '12:00:00', 4, 'Alergia: mariscos',          'pendiente', 3,  NULL),
('Familia Guerrero', 'guerrero@ejemplo.com',  '2026-06-19', '13:00:00', 6, 'Cumpleaños — pedir pastel', 'pendiente', 6,  7),
('Sofía Pedraza',    'spedraza@ejemplo.com',  '2026-06-19', '15:00:00', 2, '',                          'pendiente', 8,  NULL),
('Nicolás Andrade',  'nandrade@ejemplo.com',  '2026-06-19', '19:00:00', 4, 'Reunión de trabajo',         'pendiente', 2,  NULL),
('Fernanda & Roque', 'fernroque@ejemplo.com', '2026-06-19', '20:00:00', 5, 'Aniversario',                'pendiente', 11, 10);
