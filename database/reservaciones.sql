-- Casa Pestalozzi — Tablas de reservaciones
-- Ejecutar contra la BD configurada en includes/.env (DB_NAME)

CREATE TABLE IF NOT EXISTS categorias (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(40) NOT NULL,
  img           VARCHAR(200),
  activo        TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS menu (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(100) NOT NULL,
  descripcion   TEXT NOT NULL,
  precio        DECIMAL(10,2) NOT NULL,
  tag           VARCHAR(60),
  activo        TINYINT(1) NOT NULL DEFAULT 1,
  categoria_id  INT NOT NULL,
  FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE RESTRICT
);

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

CREATE TABLE IF NOT EXISTS reservaciones (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(100) NOT NULL,
  email      VARCHAR(150) NOT NULL,
  fecha      DATE NOT NULL,
  hora       TIME NOT NULL,
  comensales INT NOT NULL DEFAULT 2,
  nota       TEXT,
  estado     ENUM('pendiente','confirmada','cancelada') NOT NULL DEFAULT 'pendiente',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- -------------------------------------------------------
-- Seed: categorías del menú
-- -------------------------------------------------------
INSERT INTO categorias (id, nombre, img) VALUES
(1, 'Desayunos',     'build/images/comida-4.webp'),
(2, 'Entradas',      'build/images/comida-9.webp'),
(3, 'Sopas & Cremas','build/images/comida-7.webp'),
(4, 'Pastas',        'build/images/mejor-2.webp'),
(5, 'Platos Fuertes','build/images/mejor-6.webp'),
(6, 'Ensaladas',     'build/images/comida-2.webp'),
(7, 'Pizzas',        'build/images/pizza-3.webp'),
(8, 'Para Picar',    'build/images/comida-6.webp');

-- -------------------------------------------------------
-- Seed: platillos del menú
-- -------------------------------------------------------
INSERT INTO menu (nombre, descripcion, precio, tag, categoria_id) VALUES

-- Desayunos (categoria_id = 1)
('Enmoladas',
 'Rellenas de pollo (70 gr.) con láminas de plátano macho, crema, queso y aros de cebolla bañadas en mole negro de Oaxaca.',
 240.00, 'Especialidad C.P.', 1),
('Enchiladas Suizas',
 'Enchiladas verdes rellenas de pollo (70 gr.), gratinadas con queso gouda, crema y aros de cebolla.',
 220.00, NULL, 1),
('Cecina y Huevo con Chorizo',
 'Cecina (130 gr.), huevos revueltos (2 pzas) con chorizo, acompañados de frijoles refritos con queso.',
 220.00, NULL, 1),
('Cazuela Cascabel',
 '3 huevos estrellados o revueltos en salsa de chile cascabel, queso oaxaca gratinado, aguacate y una rebanada de pan hogaza.',
 220.00, NULL, 1),
('Sopes con Cecina o Arrachera',
 '3 sopes hechos a mano con frijoles, lechuga, crema, queso y cecina (130 gr.). Cambio de proteína con arrachera (150 gr.) +$40.',
 220.00, NULL, 1),
('Enfrijoladas',
 'Rellenas de huevo revuelto, bañadas con salsa de frijol, chorizo, crema y queso.',
 220.00, NULL, 1),
('Huevos al Parmesano',
 '2 huevos estrellados acompañados con espárragos blanqueados, arúgula, tocino y parmesano rallado.',
 210.00, 'Brunch', 1),
('Omelette Fitness',
 'Claras de huevo (2 pzas), espinaca, queso de cabra y láminas de aguacate.',
 190.00, NULL, 1),
('Toast de Salmón Ahumado',
 'Pan brioche, crema ácida, salmón ahumado (70 gr.), ajonjolí, 1 huevo estrellado, espárragos y aguacate.',
 230.00, 'Estrella', 1),
('Pan Francés Estilo C.P.',
 'Base de pan brioche con crema dulce, frutos rojos y miel de maple.',
 210.00, 'Dulce', 1),
('Huevos Módena',
 '2 huevos revueltos o estrellados con tocino, queso parmesano y arúgula.',
 190.00, NULL, 1),
('Huevos Italianos',
 '2 huevos en omelette, jamón serrano, láminas de queso parmesano y arúgula.',
 190.00, NULL, 1),
('Huevos Pamplona',
 '2 huevos en omelette con chorizo español de pamplona, arúgula y queso mozarella fresco.',
 190.00, NULL, 1),
('Huevos al Sano',
 '2 huevos en omelette con jamón de pavo, arúgula, queso mozarella fresco y jitomate cherry.',
 190.00, NULL, 1),
('Huevos al Gusto',
 'Rancheros, a la mexicana, divorciados, al albañil, con tocino, con chorizo o con jamón.',
 180.00, NULL, 1),
('Molletes',
 '4 piezas de pan baguette con frijoles y queso manchego, acompañado de pico de gallo.',
 100.00, NULL, 1),
('Casa Pestalozzi',
 '½ orden de chilaquiles (40 gr.) con salsa al gusto, crema, queso y 2 huevos revueltos.',
 180.00, NULL, 1),
('Chilaquiles',
 'Verdes, rojos o salsa de la casa, con pollo (30 gr.) o huevo (1 pza), queso, crema y cebolla morada. Con arrachera +$90 · con cecina +$65.',
 180.00, NULL, 1),
('Baguette de Jamón Serrano',
 'Jamón serrano, láminas de parmesano, casse de jitomate y arúgula.',
 220.00, NULL, 1),
('Baguette de Magret de Pollo',
 'Pollo a la plancha con queso gouda, rodajas de jitomate, mix de lechuga y aderezo cipriani.',
 220.00, NULL, 1),
('Baguette con Arrachera',
 'Arrachera (150 gr.), cremoso de aguacate con un toque de chipotle y mix de lechugas.',
 230.00, NULL, 1),
('Croissant con Jamón de Pavo',
 'Pechuga de pavo (120 gr.), queso gouda, aderezo cipriani, jitomate y mix de lechugas.',
 165.00, NULL, 1),
('Croissant con Huevo y Estragón',
 '2 pzas de huevo revuelto con estragón y mix de lechugas.',
 140.00, NULL, 1),
('Baguette de Cochinita',
 'Cochinita (150 gr.), cebolla encurtida y habanero.',
 210.00, NULL, 1),
('Plato de Fruta Mixta',
 'Fruta de temporada.',
 110.00, NULL, 1),
('Copa Antioxidante',
 'Fresa, frambuesa, mora y zarzamora con yogurt y granola hecha en casa.',
 130.00, NULL, 1),

-- Entradas (categoria_id = 2)
('Aros de Calamar',
 'Empanizados, aderezo cipriani, chiles cuaresmeños y limón eureka.',
 210.00, 'Especialidad C.P.', 2),
('Tostadas de Atún',
 '3 tostaditas con cubos de atún marinado en salsa oriental, cremoso de aguacate y poro.',
 195.00, 'Especialidad C.P.', 2),
('Torreta de Salmón',
 'Salmón ahumado, queso cabra, aguacate, jitomate con aderezo de pesto de albahaca.',
 220.00, 'Especialidad C.P.', 2),
('Tiradito de Atún',
 'Láminas de atún, aceite de chile, mayonesa spicy, toronja y eneldo.',
 210.00, NULL, 2),
('Carpaccio de Salmón',
 'Finas láminas de salmón ahumado, arúgula, queso parmesano, alcaparras, limón eureka y jitomate cherry.',
 180.00, NULL, 2),
('Camarones al Ajillo',
 'Salteados al olivo, ajo, peperoncino con pan de baguette.',
 210.00, NULL, 2),
('Espárragos al Horno',
 'Queso gouda, tocino con reducción de balsámico.',
 180.00, NULL, 2),
('Queso Burrata con Jitomates Cherrys',
 'Queso burrata con jitomates cherrys al horno, aceite de oliva, poro y hojas de albahaca.',
 210.00, NULL, 2),

-- Sopas & Cremas (categoria_id = 3)
('Crema del Día',
 'Nuestras cremas y sopas son elaboradas por temporada y en nuestros especiales de fin de semana. Pregunta al mesero por la opción del día.',
 180.00, 'Temporada', 3),
('Sopa Especial de Fin de Semana',
 'Receta de la casa, elaborada con ingredientes frescos de temporada. Disponible sábados y domingos.',
 180.00, 'Fin de semana', 3),

-- Pastas (categoria_id = 4)
('Fetuccini a los Cuatro Quesos y Camarones',
 'Queso brie, parmesano, queso crema y queso gouda.',
 280.00, 'Especialidad C.P.', 4),
('Lasagna de Filete de Res',
 'Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',
 280.00, 'Especialidad C.P.', 4),
('Rigatoni al Limón con Camarones y Parmesano',
 'Camarones salteados con vino blanco, mantequilla, ralladura de limón eureka y toque de albahaca.',
 280.00, 'Estrella', 4),
('Spaguetti a l''Arrabbiata con Camarones y Parmesano',
 'Salsa de pomodoro con peperoncino.',
 280.00, NULL, 4),
('Spaguetti a la Boloñesa',
 'Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',
 280.00, NULL, 4),
('Spaguetti al Pomodoro y Parmesano',
 'Pasta, salsa de jitomate y parmesano.',
 190.00, NULL, 4),

-- Platos Fuertes (categoria_id = 5)
('Filete de Res en su Jugo',
 'Filete de res importado en su jugo con puré de papa rústico y espárragos al horno.',
 320.00, 'Especialidad C.P.', 5),
('Salmón al Horno',
 'Salmón noruego sazonado con ajo y aceite de oliva. Acompaña con media orden de pasta o ensalada.',
 295.00, NULL, 5),
('Hamburguesa de la Casa',
 'Carne wagyu, pan brioche hecho en C.P., cebolla caramelizada, queso cheddar, mayonesa ahumada, pepinillo encurtido. Acompaña con papas gajo.',
 260.00, 'Especialidad C.P.', 5),
('Atún Sellado',
 'Atún importado, sellado en costra de pistache, aderezo cipriani. Acompaña con mix de lechugas.',
 285.00, NULL, 5),
('Tacos de Cochinita',
 'Tres tacos de tortilla de maíz hechas a mano, frijol, cebolla y habanero encurtido.',
 210.00, 'Especialidad C.P.', 5),
('Tacos de Vacío',
 'Vacío importado, tortillas hechas a mano, salsa de piña con habanero y aguacate.',
 210.00, NULL, 5),
('Tacos de Camarón Rebozados',
 'Tres tortillas de harina, camarones rebozados, col morada y aderezo de chipotle.',
 240.00, NULL, 5),
('Vacío en Escalopas',
 'Vacío importado en escalopas, arúgula, láminas de parmesano y reducción de bálsamico.',
 280.00, 'Especialidad C.P.', 5),
('New York (450 grs.)',
 'Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',
 785.00, 'Premium', 5),
('Rib Eye (450 grs.)',
 'Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',
 785.00, 'Premium', 5),

-- Ensaladas (categoria_id = 6)
('Frutos Rojos',
 'Mix de lechugas, frambuesas, zarzamoras, fresas, queso cabra, nuez y reducción de balsámico.',
 210.00, NULL, 6),
('Ciruela Betabel',
 'Mix de lechugas, ciruela y betabel sazonado con estragón, queso burrata y almendras horneadas.',
 210.00, 'Especialidad C.P.', 6),
('Magret de Pollo',
 'Pechuga de pollo prensada, lechuga baby asada, almendras horneadas con aderezo de queso.',
 210.00, 'Especialidad C.P.', 6),
('Jamón Serrano con Perlas de Melón',
 'Mix de lechugas, perlas de melón, jamón serrano, nuez y reducción de balsámico.',
 210.00, NULL, 6),
('Pasta Corta con Pollo',
 'Mix de lechuga con cremoso de aguacate y almendras horneadas.',
 210.00, NULL, 6),

-- Pizzas (categoria_id = 7)
('Margarita',
 'Pomodoro, mozzarella y albahaca.',
 190.00, NULL, 7),
('Burrata',
 'Pomodoro, burrata, prosciutto y arúgula.',
 260.00, 'Favorita', 7),
('Milano',
 'Pomodoro, mozzarella, jitomates cherrys, salami y láminas de parmesano.',
 260.00, NULL, 7),
('Camarones a los 4 Quesos',
 'Salsa de 4 quesos, queso mozzarella y camarones.',
 260.00, NULL, 7),

-- Para Picar (categoria_id = 8)
('Mix de 3 Brusquetas',
 'Jamón serrano, queso brie, anchoas.',
 160.00, '3 piezas', 8),
('Aceitunas Temperadas con Aceite de Chile',
 'Aceitunas verdes en aceite de chiles.',
 160.00, NULL, 8),
('Tabla Mixta',
 'Queso parmesano, brie, manchego, chorizo salamanca, semillas, frutos rojos.',
 320.00, NULL, 8),
('Papas a la Francesa con Parmesano',
 'Papas a la francesa con queso parmesano rallado.',
 160.00, NULL, 8);

-- -------------------------------------------------------
-- Seed: días disponibles
-- -------------------------------------------------------
INSERT INTO dias_reservacion (id, dia_semana, nombre, hora_apertura, hora_cierre) VALUES
(1, 0, 'Domingo',    '08:30', '19:00'),
(2, 1, 'Lunes',      '08:30', '15:00'),
(3, 2, 'Martes',     '08:30', '22:00'),
(4, 3, 'Miércoles',  '08:30', '22:00'),
(5, 4, 'Jueves',     '08:30', '22:00'),
(6, 5, 'Viernes',    '08:30', '22:00'),
(7, 6, 'Sábado',     '08:30', '22:00');

-- -------------------------------------------------------
-- Seed: horarios por día
-- Domingo (dia_id=1) — cierra 19:00, último slot 18:00
-- -------------------------------------------------------
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
