-- Casa Pestalozzi — Datos de siembra (DML)
-- Poblado inicial de catálogos, menú, reservaciones de ejemplo y usuarios demo.
-- Ejecutar DESPUÉS de ddl.sql. El orden respeta las llaves foráneas.

-- -------------------------------------------------------
-- Días y horarios de reservación
-- -------------------------------------------------------

INSERT INTO dias_reservacion (id, dia_semana, nombre, hora_apertura, hora_cierre) VALUES
(1, 0, 'Domingo',   '08:30', '19:00'),
(2, 1, 'Lunes',     '08:30', '15:00'),
(3, 2, 'Martes',    '08:30', '22:00'),
(4, 3, 'Miércoles', '08:30', '22:00'),
(5, 4, 'Jueves',    '08:30', '22:00'),
(6, 5, 'Viernes',   '08:30', '22:00'),
(7, 6, 'Sábado',    '08:30', '22:00');

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

-- -------------------------------------------------------
-- Mesas — pos_x / pos_y = % del centro del pin sobre el canvas
-- -------------------------------------------------------

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

-- -------------------------------------------------------
-- Áreas de producción
-- -------------------------------------------------------

INSERT INTO areas_produccion (id, nombre, slug, color) VALUES
(1, 'Barra de Café',    'cafe',   '#7b5e3a'),
(2, 'Barra de Jugos',   'jugos',  '#e8a920'),
(3, 'Cocina',           'cocina', '#b03a2e'),
(4, 'Horno Napolitano', 'horno',  '#1a5276');

-- -------------------------------------------------------
-- Categorías del menú
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
-- Productos (para comanda por área)
-- -------------------------------------------------------

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

-- -------------------------------------------------------
-- Menú completo
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
-- Reservaciones de ejemplo — escenario viernes 2026-06-19
-- Actualizar la fecha al día actual antes de usar en producción.
-- Al llegar el cliente: abrir ticket a su nombre y eliminar la reserva.
-- -------------------------------------------------------

INSERT INTO reservaciones
(nombre, email, fecha, hora, comensales, nota, estado)
VALUES
('Camila Estrada',   'cestrada@ejemplo.com',  '2026-06-19', '09:00:00', 2, '',                    'pendiente'),
('Javier Montiel',   'jmontiel@ejemplo.com',  '2026-06-19', '12:00:00', 4, 'Alergia: mariscos',   'pendiente'),
('Familia Guerrero', 'guerrero@ejemplo.com',  '2026-06-19', '13:00:00', 6, 'Cumpleanos - pastel', 'pendiente'),
('Sofia Pedraza',    'spedraza@ejemplo.com',  '2026-06-19', '15:00:00', 2, '',                    'pendiente'),
('Nicolas Andrade',  'nandrade@ejemplo.com',  '2026-06-19', '19:00:00', 4, 'Reunion de trabajo',  'pendiente'),
('Fernanda & Roque', 'fernroque@ejemplo.com', '2026-06-19', '20:00:00', 5, 'Aniversario',          'pendiente'),
('Grupo Morales',    'morales@ejemplo.com',   '2026-06-19', '18:00:00', 9, 'Grupo grande',         'pendiente');

-- Asignaciones de ejemplo. reservacion_mesas es la unica fuente de mesas.
INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
SELECT id, 5, 1 FROM reservaciones WHERE nombre = 'Camila Estrada'
UNION ALL SELECT id, 3, 1 FROM reservaciones WHERE nombre = 'Javier Montiel'
UNION ALL SELECT id, 6, 1 FROM reservaciones WHERE nombre = 'Familia Guerrero'
UNION ALL SELECT id, 7, 2 FROM reservaciones WHERE nombre = 'Familia Guerrero'
UNION ALL SELECT id, 8, 1 FROM reservaciones WHERE nombre = 'Sofia Pedraza'
UNION ALL SELECT id, 2, 1 FROM reservaciones WHERE nombre = 'Nicolas Andrade'
UNION ALL SELECT id, 11, 1 FROM reservaciones WHERE nombre = 'Fernanda & Roque'
UNION ALL SELECT id, 10, 2 FROM reservaciones WHERE nombre = 'Fernanda & Roque'
UNION ALL SELECT id, 2, 1 FROM reservaciones WHERE nombre = 'Grupo Morales'
UNION ALL SELECT id, 4, 2 FROM reservaciones WHERE nombre = 'Grupo Morales'
UNION ALL SELECT id, 5, 3 FROM reservaciones WHERE nombre = 'Grupo Morales';

-- -------------------------------------------------------
-- Usuarios demo (password_hash = mismo bcrypt de prueba)
-- -------------------------------------------------------

INSERT INTO usuarios (username, nombre, password_hash, rol, activo) VALUES
('admin_demo',      'Administrador Demo',  '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'admin',    1),
('observador1',     'Observador General',  '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'observer', 1),
('mesero1',         'Carlos Hernández',    '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'waiter',   1),
('cajero1',         'Mariana López',       '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'cashier',  1),
('mesero_inactivo', 'Daniel Torres',       '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'waiter',   0);

-- -------------------------------------------------------
-- Tickets de ejemplo (para /admin/tickets)
-- id explícito para poder referenciarlos en items y feedback.
-- -------------------------------------------------------

INSERT INTO tickets (id, mesa_id, comensales, nombre, hora_apertura, estado, metodo_pago) VALUES
(1,  1,  2, 'Camila Estrada',   '2026-06-18 14:05:00', 'cerrado',   'tarjeta'),
(2,  3,  4, 'Javier Montiel',   '2026-06-18 14:40:00', 'cerrado',   'efectivo'),
(3,  6,  6, 'Familia Guerrero', '2026-06-18 20:10:00', 'cerrado',   'tarjeta'),
(4,  8,  2, 'Sofía Pedraza',    '2026-06-19 13:20:00', 'abierto',   'efectivo'),
(5,  2,  4, 'Nicolás Andrade',  '2026-06-18 21:05:00', 'cerrado',   'tarjeta'),
(6, 11,  5, 'Fernanda & Roque', '2026-06-19 13:35:00', 'abierto',   'tarjeta'),
(7,  5,  3, 'Mesa 5',           '2026-06-18 16:00:00', 'cancelado', 'efectivo'),
(8,  7,  4, 'Grupo Torres',     '2026-06-18 15:15:00', 'cerrado',   'efectivo');

-- Items por ticket (definen el total mostrado en /admin/tickets)
INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado) VALUES
-- T1 (cerrado)
(1, 'Toast de Salmón Ahumado', 230.00, 'Desayunos',        3, 1, 'entregado'),
(1, 'Cappuccino',               75.00, 'Café & Bebidas',    1, 2, 'entregado'),
-- T2 (cerrado)
(2, 'Enchiladas Suizas',       220.00, 'Desayunos',        3, 2, 'entregado'),
(2, 'Jugo Verde',               95.00, 'Jugos & Smoothies', 2, 2, 'entregado'),
(2, 'Café Americano',           65.00, 'Café & Bebidas',    1, 2, 'entregado'),
-- T3 (cerrado, grupo)
(3, 'Rib Eye (450 grs.)',      785.00, 'Platos Fuertes',   3, 1, 'entregado'),
(3, 'Pizza Milano',            260.00, 'Pizzas',           4, 2, 'entregado'),
(3, 'Camarones al Ajillo',     210.00, 'Entradas',         3, 1, 'entregado'),
(3, 'Limonada Natural',         75.00, 'Jugos & Smoothies', 2, 4, 'entregado'),
-- T4 (abierto)
(4, 'Chilaquiles',             180.00, 'Desayunos',        3, 2, 'en_preparacion'),
(4, 'Café de Olla',             65.00, 'Café & Bebidas',    1, 2, 'enviado'),
-- T5 (cerrado)
(5, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes',  3, 2, 'entregado'),
(5, 'Queso Burrata con Jitomates Cherrys', 210.00, 'Entradas', 4, 1, 'entregado'),
-- T6 (abierto, grupo)
(6, 'Pizza Burrata',           260.00, 'Pizzas',           4, 2, 'en_preparacion'),
(6, 'Aros de Calamar',         210.00, 'Entradas',         3, 1, 'enviado'),
(6, 'Agua de Coco',             90.00, 'Jugos & Smoothies', 2, 5, 'enviado'),
-- T8 (cerrado)
(8, 'Hamburguesa de la Casa',  260.00, 'Platos Fuertes',   3, 2, 'entregado'),
(8, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado'),
(8, 'Refresco',                 55.00, 'Café & Bebidas',    1, 4, 'entregado');

-- -------------------------------------------------------
-- Feedback de clientes (para /admin/feedback)
-- Escala 1–5. Referencia tickets cerrados; token_id NULL.
-- -------------------------------------------------------

INSERT INTO feedback (token_id, ticket_id, calidad_sabor, atencion_mesero, tiempo_espera, experiencia_global, comentario, created_at) VALUES
(NULL, 1, 5, 5, 4, 5, 'Todo excelente, el salmón estaba delicioso y el servicio muy atento.', '2026-06-18 14:50:00'),
(NULL, 2, 4, 5, 3, 4, 'Muy rica la comida, aunque tardó un poco en llegar.',                  '2026-06-18 15:30:00'),
(NULL, 3, 5, 4, 4, 5, 'Celebramos un cumpleaños y quedamos encantados. Volveremos.',          '2026-06-18 22:00:00'),
(NULL, 5, 5, 5, 5, 5, 'Experiencia impecable de principio a fin. El filete, espectacular.',   '2026-06-18 22:15:00'),
(NULL, 8, 3, 4, 2, 3, 'La hamburguesa buena, pero esperamos demasiado por la cuenta.',        '2026-06-18 16:10:00'),
(NULL, 2, 4, 4, 4, 4, 'Buen ambiente y sazón. Repetiría el jugo verde.',                      '2026-06-18 15:35:00'),
(NULL, 1, 5, 5, 5, 5, 'El mejor desayuno de la Del Valle, sin duda.',                         '2026-06-18 14:55:00'),
(NULL, 3, 2, 3, 2, 2, 'La pizza llegó fría y tardaron en atendernos.',                        '2026-06-18 22:05:00'),
-- Temperatura y consistencia de cocina
(NULL, 3, 2, 4, 3, 3, 'La Pizza Milano llegó tibia; de sabor bien, pero fría le resta mucho.',              '2026-06-19 21:40:00'),
(NULL, 5, 3, 4, 3, 3, 'El filete pedido término medio llegó casi bien cocido. La próxima lo cuidaré.',      '2026-06-19 21:55:00'),
(NULL, NULL, 2, 3, 3, 2, 'Los chilaquiles llegaron aguados y el huevo frío. Esperábamos más.',              '2026-06-20 10:15:00'),
(NULL, 3, 5, 5, 4, 5, 'El Rib Eye estaba en su punto perfecto, jugoso y caliente. Excelente cocina.',       '2026-06-20 21:10:00'),
-- Tiempo de espera al pagar la cuenta (dolor recurrente)
(NULL, 8, 4, 4, 2, 3, 'Comida muy buena, pero esperamos casi 20 minutos para que trajeran la cuenta.',     '2026-06-19 16:20:00'),
(NULL, 2, 4, 5, 2, 3, 'Todo rico, aunque cobrar con tarjeta tomó mucho tiempo. La terminal fallaba.',      '2026-06-20 15:05:00'),
(NULL, NULL, 5, 5, 2, 4, 'Amamos el lugar, pero el cierre de cuenta en hora pico es eterno.',               '2026-06-20 15:40:00'),
-- Espera de alimentos en horas pico
(NULL, NULL, 4, 3, 2, 3, 'Sábado lleno: la comida tardó más de 40 minutos en salir.',                       '2026-06-20 21:30:00'),
(NULL, NULL, 4, 4, 2, 3, 'Rico todo, pero tardaron mucho en tomarnos la orden al inicio.',                  '2026-06-21 14:10:00'),
-- Atención en piso (inconsistente y positiva)
(NULL, 2, 3, 2, 3, 3, 'Tuvimos que pedir el agua y los cubiertos dos veces. Faltó seguimiento a la mesa.', '2026-06-21 14:25:00'),
(NULL, 5, 5, 5, 5, 5, 'Ricardo, nuestro mesero, fue atentísimo y nos recomendó de maravilla. ¡Un lujo!',   '2026-06-21 21:15:00'),
(NULL, NULL, 4, 2, 4, 3, 'La comida bien, pero el mesero se veía saturado y algo cortante.',                 '2026-06-22 15:30:00'),
(NULL, 1, 5, 5, 5, 5, 'Nos reconocieron como clientes frecuentes y hasta una cortesía nos dieron. ¡Gracias!','2026-06-22 09:40:00'),
-- Ambiente, ruido y confort
(NULL, NULL, 5, 5, 4, 4, 'Comida deliciosa, pero la música estaba tan alta que costaba conversar.',          '2026-06-22 21:50:00'),
(NULL, NULL, 4, 4, 4, 3, 'Buen lugar, aunque el aire acondicionado estaba muy frío en la zona del ventanal.','2026-06-23 14:20:00'),
(NULL, 3, 4, 5, 4, 5, 'La terraza es hermosa y muy tranquila para comer en familia.',                      '2026-06-23 15:10:00'),
-- Limpieza
(NULL, NULL, 4, 4, 4, 3, 'La comida bien, pero los baños necesitaban atención a media tarde.',               '2026-06-23 17:05:00'),
-- Relación calidad-precio y porciones
(NULL, NULL, 4, 4, 4, 3, 'Sabor bueno, pero las porciones se me hicieron chicas para el precio.',            '2026-06-24 14:45:00'),
(NULL, 8, 5, 5, 4, 5, 'La Hamburguesa de la Casa vale cada peso. Enorme y sabrosa.',                        '2026-06-24 15:20:00'),
-- Café y bebidas
(NULL, 1, 3, 4, 4, 3, 'El cappuccino llegó tibio y sin mucha espuma. El desayuno sí muy rico.',             '2026-06-24 09:30:00'),
(NULL, 2, 5, 4, 4, 5, 'El jugo verde y el café de olla, espectaculares. Mi desayuno favorito.',             '2026-06-25 09:15:00'),
-- Opciones de menú (vegetariano/postres)
(NULL, NULL, 4, 5, 4, 4, 'Todo muy rico, pero me gustaría ver más opciones vegetarianas y sin gluten.',     '2026-06-25 14:35:00'),
(NULL, NULL, 5, 5, 4, 4, 'La comida excelente; ojalá tuvieran más variedad de postres.',                    '2026-06-25 21:25:00'),
-- Errores en la comanda
(NULL, NULL, 3, 3, 3, 2, 'Nos trajeron un platillo equivocado y tuvimos que esperar a que lo corrigieran.', '2026-06-26 15:00:00'),
-- Reservaciones y familias
(NULL, NULL, 5, 4, 3, 4, 'Reservamos pero la mesa no estaba lista a la hora acordada. Lo demás, muy bien.',  '2026-06-26 21:10:00'),
(NULL, 3, 5, 5, 5, 5, 'Fuimos con niños y los atendieron increíble. Menú infantil por favor.',             '2026-06-27 14:15:00'),
-- Celebraciones y experiencia global alta
(NULL, 5, 5, 5, 4, 5, 'Festejamos un aniversario y todo fue perfecto. El postre de cortesía, un detallazo.','2026-06-27 21:40:00'),
(NULL, NULL, 5, 5, 5, 5, 'De lo mejor de la Del Valle. Volveremos muy pronto, todo impecable.',              '2026-06-28 15:30:00');
