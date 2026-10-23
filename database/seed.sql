-- Casa Pestalozzi — Datos mínimos de arranque
--
-- Lo necesario para que una instalación nueva opere: usuario administrador de
-- desarrollo, horario, mesas, categorías y catálogo del menú, más un registro
-- de ejemplo por módulo (cata, excepción de horario, reservación y anuncio).
-- Ejecutar después de database/schema.sql.
--
SET NAMES utf8mb4;

-- -------------------------------------------------------
-- Horario efectivo semanal usado por el flujo público. Se mantiene alineado
INSERT INTO horarios_operacion (dia_semana, abierto, hora_apertura, hora_cierre) VALUES
(0, 1, '08:30', '19:00'),
(1, 1, '13:00', '22:00'),
(2, 1, '08:30', '22:00'),
(3, 1, '08:30', '22:00'),
(4, 1, '08:30', '22:00'),
(5, 1, '08:30', '22:00'),
(6, 1, '08:30', '22:00');

-- -------------------------------------------------------
-- Mesas — pos_x / pos_y = % del centro del pin sobre el canvas
-- -------------------------------------------------------

INSERT INTO mesas (numero, nombre, tipo, capacidad, pos_x, pos_y, reservable) VALUES
-- Al quitar la botonera del mapa, el salón recuperó ~200px de ancho. Solo se
-- mueve pos_x: la estructura por filas es la misma.
(1,  'Mesa 1',       'mesa',     4, 30.0, 88.0, 1),
(2,  'Mesa 2',       'mesa',     4,  7.0, 70.0, 1),
(3,  'Mesa 3',       'mesa',     4, 30.0, 51.0, 1),
(4,  'Mesa 4',       'mesa',     4,  7.0, 51.0, 1),
(5,  'Mesa 5',       'mesa',     4,  7.0, 29.0, 1),
(6,  'Mesa 6',       'mesa',     4, 44.0, 29.0, 1),
(7,  'Mesa 7',       'mesa',     4, 88.0, 29.0, 1),
(8,  'Mesa 8',       'mesa',     4, 88.0,  8.0, 1),
(9,  'Mesa 9',       'mesa',     4, 56.0,  8.0, 1),
(10, 'Mesa 10',      'mesa',     4, 30.0,  8.0, 1),
(11, 'Mesa 11',      'mesa',     4,  7.0,  8.0, 1),
(12, 'Barra Blanca', 'barra',    8, 62.0, 51.0, 0),
(13, 'Caja',         'especial', 0, 33.0, 70.0, 0),
(14, 'Llevar',       'especial', 0, 57.0, 70.0, 0),
(15, 'Barra Roja',   'barra',    6, 88.0, 70.0, 0),
(16, 'Barra Roja 2', 'barra',    6, 88.0, 88.0, 0);

-- -------------------------------------------------------
-- Categorías del menú
-- -------------------------------------------------------

-- `carta` dice en cual de las DOS piezas impresas entra la categoria. Estas
-- diez son la carta de comida, que es ademas lo unico que pinta la seccion
-- de menu de la landing; las de barra llegan mas abajo marcadas 'maridaje'.
INSERT INTO categorias (id, nombre, carta, img) VALUES
(1, 'Desayunos',          'comida', 'build/images/comida-4.webp'),
(2, 'Entradas',           'comida', 'build/images/comida-9.webp'),
(3, 'Sopas & Cremas',     'comida', 'build/images/comida-7.webp'),
(4, 'Pastas',             'comida', 'build/images/mejor-2.webp'),
(5, 'Platos Fuertes',     'comida', 'build/images/mejor-6.webp'),
(6, 'Ensaladas',          'comida', 'build/images/comida-2.webp'),
(7, 'Pizzas',             'comida', 'build/images/pizza-3.webp'),
(8, 'Para Picar',         'comida', 'build/images/comida-6.webp'),
(9, 'Café & Bebidas',     'comida', 'build/images/comida-1.webp'),
(10, 'Jugos & Smoothies', 'comida', 'build/images/comida-2.webp');

-- -------------------------------------------------------
-- Productos — catálogo único de la carta
-- -------------------------------------------------------

CREATE TEMPORARY TABLE productos_semilla (
  nombre VARCHAR(120) NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  precio DECIMAL(8,2) NOT NULL
);

INSERT INTO productos_semilla (nombre, categoria, precio) VALUES
('Enmoladas',                                           'Desayunos',        240.00),
('Enchiladas Suizas',                                   'Desayunos',        220.00),
('Cecina y Huevo con Chorizo',                          'Desayunos',        220.00),
('Cazuela Cascabel',                                    'Desayunos',        220.00),
('Sopes con Cecina o Arrachera',                        'Desayunos',        220.00),
('Enfrijoladas',                                        'Desayunos',        220.00),
('Huevos al Parmesano',                                 'Desayunos',        210.00),
('Omelette Fitness',                                    'Desayunos',        190.00),
('Toast de Salmón Ahumado',                             'Desayunos',        230.00),
('Pan Francés Estilo C.P.',                             'Desayunos',        210.00),
('Huevos Módena',                                       'Desayunos',        190.00),
('Huevos Italianos',                                    'Desayunos',        190.00),
('Huevos Pamplona',                                     'Desayunos',        190.00),
('Huevos al Sano',                                      'Desayunos',        190.00),
('Huevos al Gusto',                                     'Desayunos',        180.00),
('Molletes',                                            'Desayunos',        100.00),
('Casa Pestalozzi',                                     'Desayunos',        180.00),
('Chilaquiles',                                         'Desayunos',        180.00),
('Baguette de Jamón Serrano',                           'Desayunos',        220.00),
('Baguette de Magret de Pollo',                         'Desayunos',        220.00),
('Baguette con Arrachera',                              'Desayunos',        230.00),
('Croissant con Jamón de Pavo',                         'Desayunos',        165.00),
('Croissant con Huevo y Estragón',                      'Desayunos',        140.00),
('Baguette de Cochinita',                               'Desayunos',        210.00),
('Plato de Fruta Mixta',                                'Desayunos',        110.00),
('Copa Antioxidante',                                   'Desayunos',        130.00),
('Aros de Calamar',                                     'Entradas',         210.00),
('Tostadas de Atún',                                    'Entradas',         195.00),
('Torreta de Salmón',                                   'Entradas',         220.00),
('Tiradito de Atún',                                    'Entradas',         210.00),
('Carpaccio de Salmón',                                 'Entradas',         180.00),
('Camarones al Ajillo',                                 'Entradas',         210.00),
('Espárragos al Horno',                                 'Entradas',         180.00),
('Queso Burrata con Jitomates Cherrys',                 'Entradas',         210.00),
('Crema del Día',                                       'Sopas & Cremas',   180.00),
('Sopa Especial de Fin de Semana',                      'Sopas & Cremas',   180.00),
('Fetuccini a los Cuatro Quesos y Camarones',           'Pastas',           280.00),
('Lasagna de Filete de Res',                            'Pastas',           280.00),
('Rigatoni al Limón con Camarones y Parmesano',         'Pastas',           280.00),
('Spaguetti a l''Arrabbiata con Camarones y Parmesano', 'Pastas',           280.00),
('Spaguetti a la Boloñesa',                             'Pastas',           280.00),
('Spaguetti al Pomodoro y Parmesano',                   'Pastas',           190.00),
('Filete de Res en su Jugo',                            'Platos Fuertes',   320.00),
('Salmón al Horno',                                     'Platos Fuertes',   295.00),
('Hamburguesa de la Casa',                              'Platos Fuertes',   260.00),
('Atún Sellado',                                        'Platos Fuertes',   285.00),
('Tacos de Cochinita',                                  'Platos Fuertes',   210.00),
('Tacos de Vacío',                                      'Platos Fuertes',   210.00),
('Tacos de Camarón Rebozados',                          'Platos Fuertes',   240.00),
('Vacío en Escalopas',                                  'Platos Fuertes',   280.00),
('New York (450 grs.)',                                 'Platos Fuertes',   785.00),
('Rib Eye (450 grs.)',                                  'Platos Fuertes',   785.00),
('Frutos Rojos',                                        'Ensaladas',        210.00),
('Ciruela Betabel',                                     'Ensaladas',        210.00),
('Magret de Pollo',                                     'Ensaladas',        210.00),
('Jamón Serrano con Perlas de Melón',                   'Ensaladas',        210.00),
('Pasta Corta con Pollo',                               'Ensaladas',        210.00),
('Margarita',                                           'Pizzas',           190.00),
('Burrata',                                             'Pizzas',           260.00),
('Milano',                                             'Pizzas',           260.00),
('Camarones a los 4 Quesos',                            'Pizzas',           260.00),
('Mix de 3 Brusquetas',                                 'Para Picar',       160.00),
('Aceitunas Temperadas con Aceite de Chile',            'Para Picar',       160.00),
('Tabla Mixta',                                         'Para Picar',       320.00),
('Papas a la Francesa con Parmesano',                   'Para Picar',       160.00),
('Café Americano',                                      'Café & Bebidas',    65.00),
('Cappuccino',                                          'Café & Bebidas',    75.00),
('Latte',                                               'Café & Bebidas',    80.00),
('Café de Olla',                                        'Café & Bebidas',    65.00),
('Té / Infusión',                                       'Café & Bebidas',    65.00),
('Chocolate Caliente',                                  'Café & Bebidas',    80.00),
('Agua Fresca',                                         'Café & Bebidas',    60.00),
('Refresco',                                            'Café & Bebidas',    55.00),
('Jugo de Naranja',                                     'Jugos & Smoothies', 85.00),
('Jugo Verde',                                          'Jugos & Smoothies', 95.00),
('Limonada Natural',                                    'Jugos & Smoothies', 75.00),
('Smoothie de Fresa',                                   'Jugos & Smoothies',100.00),
('Agua de Coco',                                        'Jugos & Smoothies', 90.00);

INSERT INTO productos (nombre, categoria_id, precio)
SELECT ps.nombre, c.id, ps.precio
FROM productos_semilla ps
INNER JOIN categorias c ON c.nombre = ps.categoria;

DROP TEMPORARY TABLE productos_semilla;

-- -------------------------------------------------------
-- Menú completo
-- -------------------------------------------------------

--
-- Todo lo que está activo se publica en la carta, y todo lleva
-- descripción: las bebidas de las categorías 9 y 10 también. Antes se sembraban
-- sin ella y la carta las imprimía con nombre y precio a secas, lo que dejaba
-- las dos últimas secciones del menú visiblemente más pobres que el resto —en
-- el PDF, media hoja de renglones sueltos— justo donde el comensal decide el
-- acompañamiento.
--
-- El INSERT de arriba ya dio de alta todos los platillos con su precio;
-- este solo aporta la descripción, así que choca a propósito contra el UNIQUE
-- de nombre y resuelve con ON DUPLICATE KEY. Sin eso el script moría aquí con
-- "Duplicate entry" en cualquier base creada desde cero.
--
-- categoria_id: 1 Desayunos · 2 Entradas · 3 Sopas & Cremas · 4 Pastas · 5 Platos Fuertes
--               6 Ensaladas · 7 Pizzas · 8 Para Picar · 9 Café & Bebidas · 10 Jugos & Smoothies
INSERT INTO productos (nombre, descripcion, categoria_id, precio) VALUES
-- Desayunos (categoria_id = 1)
('Enmoladas',
 'Rellenas de pollo (70 gr.) con láminas de plátano macho, crema, queso y aros de cebolla bañadas en mole negro de Oaxaca.',
 1, 240.00),
('Enchiladas Suizas',
 'Enchiladas verdes rellenas de pollo (70 gr.), gratinadas con queso gouda, crema y aros de cebolla.',
 1, 220.00),
('Cecina y Huevo con Chorizo',
 'Cecina (130 gr.), huevos revueltos (2 pzas) con chorizo, acompañados de frijoles refritos con queso.',
 1, 220.00),
('Cazuela Cascabel',
 '3 huevos estrellados o revueltos en salsa de chile cascabel, queso oaxaca gratinado, aguacate y una rebanada de pan hogaza.',
 1, 220.00),
('Sopes con Cecina o Arrachera',
 '3 sopes hechos a mano con frijoles, lechuga, crema, queso y cecina (130 gr.). Cambio de proteína con arrachera (150 gr.) +$40.',
 1, 220.00),
('Enfrijoladas',
 'Rellenas de huevo revuelto, bañadas con salsa de frijol, chorizo, crema y queso.',
 1, 220.00),
('Huevos al Parmesano',
 '2 huevos estrellados acompañados con espárragos blanqueados, arúgula, tocino y parmesano rallado.',
 1, 210.00),
('Omelette Fitness',
 'Claras de huevo (2 pzas), espinaca, queso de cabra y láminas de aguacate.',
 1, 190.00),
('Toast de Salmón Ahumado',
 'Pan brioche, crema ácida, salmón ahumado (70 gr.), ajonjolí, 1 huevo estrellado, espárragos y aguacate.',
 1, 230.00),
('Pan Francés Estilo C.P.',
 'Base de pan brioche con crema dulce, frutos rojos y miel de maple.',
 1, 210.00),
('Huevos Módena',
 '2 huevos revueltos o estrellados con tocino, queso parmesano y arúgula.',
 1, 190.00),
('Huevos Italianos',
 '2 huevos en omelette, jamón serrano, láminas de queso parmesano y arúgula.',
 1, 190.00),
('Huevos Pamplona',
 '2 huevos en omelette con chorizo español de pamplona, arúgula y queso mozarella fresco.',
 1, 190.00),
('Huevos al Sano',
 '2 huevos en omelette con jamón de pavo, arúgula, queso mozarella fresco y jitomate cherry.',
 1, 190.00),
('Huevos al Gusto',
 'Rancheros, a la mexicana, divorciados, al albañil, con tocino, con chorizo o con jamón.',
 1, 180.00),
('Molletes',
 '4 piezas de pan baguette con frijoles y queso manchego, acompañado de pico de gallo.',
 1, 100.00),
('Casa Pestalozzi',
 '½ orden de chilaquiles (40 gr.) con salsa al gusto, crema, queso y 2 huevos revueltos.',
 1, 180.00),
('Chilaquiles',
 'Verdes, rojos o salsa de la casa, con pollo (30 gr.) o huevo (1 pza), queso, crema y cebolla morada. Con arrachera +$90 · con cecina +$65.',
 1, 180.00),
('Baguette de Jamón Serrano',
 'Jamón serrano, láminas de parmesano, casse de jitomate y arúgula.',
 1, 220.00),
('Baguette de Magret de Pollo',
 'Pollo a la plancha con queso gouda, rodajas de jitomate, mix de lechuga y aderezo cipriani.',
 1, 220.00),
('Baguette con Arrachera',
 'Arrachera (150 gr.), cremoso de aguacate con un toque de chipotle y mix de lechugas.',
 1, 230.00),
('Croissant con Jamón de Pavo',
 'Pechuga de pavo (120 gr.), queso gouda, aderezo cipriani, jitomate y mix de lechugas.',
 1, 165.00),
('Croissant con Huevo y Estragón',
 '2 pzas de huevo revuelto con estragón y mix de lechugas.',
 1, 140.00),
('Baguette de Cochinita',
 'Cochinita (150 gr.), cebolla encurtida y habanero.',
 1, 210.00),
('Plato de Fruta Mixta',
 'Fruta de temporada.',
 1, 110.00),
('Copa Antioxidante',
 'Fresa, frambuesa, mora y zarzamora con yogurt y granola hecha en casa.',
 1, 130.00),

-- Entradas (categoria_id = 2)
('Aros de Calamar',
 'Empanizados, aderezo cipriani, chiles cuaresmeños y limón eureka.',
 2, 210.00),
('Tostadas de Atún',
 '3 tostaditas con cubos de atún marinado en salsa oriental, cremoso de aguacate y poro.',
 2, 195.00),
('Torreta de Salmón',
 'Salmón ahumado, queso cabra, aguacate, jitomate con aderezo de pesto de albahaca.',
 2, 220.00),
('Tiradito de Atún',
 'Láminas de atún, aceite de chile, mayonesa spicy, toronja y eneldo.',
 2, 210.00),
('Carpaccio de Salmón',
 'Finas láminas de salmón ahumado, arúgula, queso parmesano, alcaparras, limón eureka y jitomate cherry.',
 2, 180.00),
('Camarones al Ajillo',
 'Salteados al olivo, ajo, peperoncino con pan de baguette.',
 2, 210.00),
('Espárragos al Horno',
 'Queso gouda, tocino con reducción de balsámico.',
 2, 180.00),
('Queso Burrata con Jitomates Cherrys',
 'Queso burrata con jitomates cherrys al horno, aceite de oliva, poro y hojas de albahaca.',
 2, 210.00),

-- Sopas & Cremas (categoria_id = 3)
('Crema del Día',
 'Nuestras cremas y sopas son elaboradas por temporada y en nuestros especiales de fin de semana. Pregunta al mesero por la opción del día.',
 3, 180.00),
('Sopa Especial de Fin de Semana',
 'Receta de la casa, elaborada con ingredientes frescos de temporada. Disponible sábados y domingos.',
 3, 180.00),

-- Pastas (categoria_id = 4)
('Fetuccini a los Cuatro Quesos y Camarones',
 'Queso brie, parmesano, queso crema y queso gouda.',
 4, 280.00),
('Lasagna de Filete de Res',
 'Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',
 4, 280.00),
('Rigatoni al Limón con Camarones y Parmesano',
 'Camarones salteados con vino blanco, mantequilla, ralladura de limón eureka y toque de albahaca.',
 4, 280.00),
('Spaguetti a l''Arrabbiata con Camarones y Parmesano',
 'Salsa de pomodoro con peperoncino.',
 4, 280.00),
('Spaguetti a la Boloñesa',
 'Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',
 4, 280.00),
('Spaguetti al Pomodoro y Parmesano',
 'Pasta, salsa de jitomate y parmesano.',
 4, 190.00),

-- Platos Fuertes (categoria_id = 5)
('Filete de Res en su Jugo',
 'Filete de res importado en su jugo con puré de papa rústico y espárragos al horno.',
 5, 320.00),
('Salmón al Horno',
 'Salmón noruego sazonado con ajo y aceite de oliva. Acompaña con media orden de pasta o ensalada.',
 5, 295.00),
('Hamburguesa de la Casa',
 'Carne wagyu, pan brioche hecho en C.P., cebolla caramelizada, queso cheddar, mayonesa ahumada, pepinillo encurtido. Acompaña con papas gajo.',
 5, 260.00),
('Atún Sellado',
 'Atún importado, sellado en costra de pistache, aderezo cipriani. Acompaña con mix de lechugas.',
 5, 285.00),
('Tacos de Cochinita',
 'Tres tacos de tortilla de maíz hechas a mano, frijol, cebolla y habanero encurtido.',
 5, 210.00),
('Tacos de Vacío',
 'Vacío importado, tortillas hechas a mano, salsa de piña con habanero y aguacate.',
 5, 210.00),
('Tacos de Camarón Rebozados',
 'Tres tortillas de harina, camarones rebozados, col morada y aderezo de chipotle.',
 5, 240.00),
('Vacío en Escalopas',
 'Vacío importado en escalopas, arúgula, láminas de parmesano y reducción de bálsamico.',
 5, 280.00),
('New York (450 grs.)',
 'Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',
 5, 785.00),
('Rib Eye (450 grs.)',
 'Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',
 5, 785.00),

-- Ensaladas (categoria_id = 6)
('Frutos Rojos',
 'Mix de lechugas, frambuesas, zarzamoras, fresas, queso cabra, nuez y reducción de balsámico.',
 6, 210.00),
('Ciruela Betabel',
 'Mix de lechugas, ciruela y betabel sazonado con estragón, queso burrata y almendras horneadas.',
 6, 210.00),
('Magret de Pollo',
 'Pechuga de pollo prensada, lechuga baby asada, almendras horneadas con aderezo de queso.',
 6, 210.00),
('Jamón Serrano con Perlas de Melón',
 'Mix de lechugas, perlas de melón, jamón serrano, nuez y reducción de balsámico.',
 6, 210.00),
('Pasta Corta con Pollo',
 'Mix de lechuga con cremoso de aguacate y almendras horneadas.',
 6, 210.00),

-- Pizzas (categoria_id = 7)
('Margarita',
 'Pomodoro, mozzarella y albahaca.',
 7, 190.00),
('Burrata',
 'Pomodoro, burrata, prosciutto y arúgula.',
 7, 260.00),
('Milano',
 'Pomodoro, mozzarella, jitomates cherrys, salami y láminas de parmesano.',
 7, 260.00),
('Camarones a los 4 Quesos',
 'Salsa de 4 quesos, queso mozzarella y camarones.',
 7, 260.00),

-- Para Picar (categoria_id = 8)
('Mix de 3 Brusquetas',
 'Jamón serrano, queso brie, anchoas.',
 8, 160.00),
('Aceitunas Temperadas con Aceite de Chile',
 'Aceitunas verdes en aceite de chiles.',
 8, 160.00),
('Tabla Mixta',
 'Queso parmesano, brie, manchego, chorizo salamanca, semillas, frutos rojos.',
 8, 320.00),
('Papas a la Francesa con Parmesano',
 'Papas a la francesa con queso parmesano rallado.',
 8, 160.00),

-- Café & Bebidas (categoria_id = 9)
('Café Americano',
 'Espresso doble alargado con agua caliente.',
 9, 65.00),
('Cappuccino',
 'Espresso, leche vaporizada y espuma de leche en partes iguales.',
 9, 75.00),
('Latte',
 'Espresso con leche vaporizada y una capa fina de espuma.',
 9, 80.00),
('Café de Olla',
 'Café de grano infusionado con piloncillo y canela.',
 9, 65.00),
('Té / Infusión',
 'Selección de tés negros, verdes e infusiones de hierbas.',
 9, 65.00),
('Chocolate Caliente',
 'Chocolate de mesa batido con leche caliente.',
 9, 80.00),
('Agua Fresca',
 'Agua fresca del día, preparada con fruta de temporada.',
 9, 60.00),
('Refresco',
 'Refresco embotellado. Consulta los sabores disponibles.',
 9, 55.00),

-- Jugos & Smoothies (categoria_id = 10)
('Jugo de Naranja',
 'Naranja exprimida al momento, sin azúcar añadida.',
 10, 85.00),
('Jugo Verde',
 'Espinaca, apio, pepino, piña y jugo de naranja.',
 10, 95.00),
('Limonada Natural',
 'Limón exprimido al momento, con agua natural o mineral.',
 10, 75.00),
('Smoothie de Fresa',
 'Fresa, yogurt natural y un toque de miel.',
 10, 100.00),
('Agua de Coco',
 'Agua de coco natural, servida bien fría.',
 10, 90.00)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Red de seguridad, ya sin destinatario conocido: con las bebidas descritas
-- arriba, el catálogo sembrado no deja ninguna fila sin descripción. Se queda
-- porque `productos.descripcion` es NULL-able (acepta catálogos históricos) y
-- ni la carta ni el PDF deben publicar una fila muda; si algún día entra un
-- producto sin texto, al menos sale con su nombre y no con un hueco.
UPDATE productos SET descripcion = CONCAT(nombre, '.')
WHERE descripcion IS NULL OR descripcion = '';

-- Casa Pestalozzi — DML operativo: carta de bar "Casa Maridaje"
--
-- Bloque aditivo y re-ejecutable
-- (todos los INSERT resuelven con ON DUPLICATE KEY), así que puede correrse
-- sobre una base ya sembrada sin duplicar filas ni romper el UNIQUE de nombre.
--
-- Aporta: 4 categorías y 29 productos de la carta de bar.
--
SET NAMES utf8mb4;

START TRANSACTION;

-- -------------------------------------------------------
-- Categorías del menú — carta de bar
-- -------------------------------------------------------
--
-- Continúan la numeración de arriba (1..10). Las rutas de img reusan
-- imágenes ya existentes para que la carta no salga con recuadros rotos;
-- sustituirlas por fotos reales de barra cuando las tengan.
-- Las cuatro van en `carta = 'maridaje'`: es lo que las saca de la seccion de
-- menu de la landing y de su PDF, y lo que las mete en el PDF de maridaje que
-- enlaza el segundo boton de esa misma seccion.
INSERT INTO categorias (id, nombre, carta, img) VALUES
(11, 'Tablas de Degustación', 'maridaje', 'build/images/comida-6.webp'),  -- <-- imagen provisional
(12, 'Cocktails',             'maridaje', 'build/images/comida-1.webp'),  -- <-- imagen provisional
(13, 'Cervezas',              'maridaje', 'build/images/comida-9.webp'),  -- <-- imagen provisional
(14, 'Bebidas sin Alcohol',   'maridaje', 'build/images/comida-2.webp')   -- <-- imagen provisional
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), carta = VALUES(carta), img = VALUES(img);

-- -------------------------------------------------------
-- Productos — carta de bar
-- -------------------------------------------------------
--
-- Mismo patrón que el catálogo de arriba: alta directa con descripción,
-- resolviendo contra el UNIQUE de nombre con ON DUPLICATE KEY para que el
-- script sea re-ejecutable.
--
-- categoria_id: 11 Tablas de Degustación · 12 Cocktails · 13 Cervezas
--               14 Bebidas sin Alcohol
INSERT INTO productos (nombre, descripcion, categoria_id, precio) VALUES

-- Tablas de Degustación (categoria_id = 11)
('Regiones de Escocia',
 'Tres whiskies escoceses de 30 ml: Arran 10 (Isle of Arran), GlenAllachie 12 (Speyside) y Ardbeg 10 (Islay).',
 11, 450.00),
('Glenmorangie',
 'Tabla Glenmorangie, 30 ml c/u: 14 (Ex-Bourbon & Ex-Port Casks), 15 (Ex-Bourbon & Ex-Sherry Casks) y 16 (Ex-Bourbon & Sweet White Wine Casks).',
 11, 380.00),
('Los Grandes Clásicos',
 'Tres clásicos de 30 ml: Cutty Sark (Blended Scotch), Glenfiddich 12 "Original" y Macallan 12 "Double Cask".',
 11, 370.00),
('Clásicos del Tequila',
 'Tres tequilas blancos de 30 ml: Tequileño Blanco, Cascahuín Blanco y Siete Leguas Blanco.',
 11, 240.00),
('Cata Tequileño',
 'Cata vertical de la casa Tequileño, 30 ml c/u: Blanco, Platinum y Reposado.',
 11, 180.00),

-- Cocktails (categoria_id = 12)
('Gin Cocktails',
 'Sabores: frutos rojos, lychee y pepino.',
 12, 190.00),
('Gin Tonic',
 'Ginebra, romero y limón eureka.',
 12, 190.00),
('Margaritas',
 'Sabores: maracuyá, clásica y frutos rojos.',
 12, 190.00),
('Mezcalitas',
 'Sabores: maracuyá, sandía y frutos rojos.',
 12, 190.00),
('Aperol Spritz',
 'Aperol, prosecco y naranja.',
 12, 190.00),
('St. Germain Spritz',
 'Licor de saúco, limón eureka y prosecco.',
 12, 210.00),
('Pestalozzi Martini',
 'Tito''s Vodka, ginebra y vermouth blanco.',
 12, 140.00),
('Pestalozzi Negroni',
 'Campari, vermouth rosso y ginebra.',
 12, 210.00),
('Tequila Negroni',
 'Campari, vermouth rosso y Tequileño Blanco.',
 12, 210.00),
('Batanga Tequileño',
 'Receta auténtica: Tequileño Blanco, Coca-Cola, jugo de limón fresco y sal de mar.',
 12, 120.00),
('Espresso Martini',
 'Espresso, Tito''s Vodka y Kahlúa.',
 12, 210.00),
('Coctelería Clásica',
 'Cuba Libre, Old Fashioned, Manhattan, Cosmopolitan, Mojito, Bloody Mary, entre otros. Pregunta a nuestro equipo.',
 12, 190.00),

-- Cervezas (categoria_id = 13)
('Modelo Especial',
 'México, 4.5% ABV, 355 ml.',
 13, 80.00),
('Victoria',
 'México, 4.0% ABV, 355 ml.',
 13, 70.00),
('Estrella Galicia',
 'España, 5.5% ABV, 355 ml.',
 13, 70.00),
('1906',
 'España, 6.5% ABV, 355 ml.',
 13, 80.00),
('Estrella Galicia de Barril',
 'España, 6.5% ABV, 355 ml.',
 13, 90.00),

-- Bebidas sin Alcohol (categoria_id = 14)
('Mocktails',
 'Frutos rojos, maracuyá, sandía, entre otros sabores de temporada.',
 14, 140.00),
('Piñada',
 'Jugo de piña, leche evaporada y coco.',
 14, 140.00),
('Naranjada/Limonada',
 'Preparada con agua mineral o natural.',
 14, 85.00),
('Suero de Limón',
 'Jugo de limón, sal y agua mineral Topo Chico. Cambio a Perrier (330 ml) o Agua de Piedra (355 ml) +$15 · Agua de Piedra (650 ml) +$35.',
 14, 70.00),
('Agua Mineral',
 'Topo Chico (355 ml). Cambio a Perrier (330 ml) o Agua de Piedra (355 ml) +$15 · Agua de Piedra (650 ml) +$35.',
 14, 50.00),
('Agua Natural',
 'Agua de Piedra (355 ml). Presentación de 650 ml +$25.',
 14, 45.00),
('Refrescos de Barra',
 'Botella de 355 ml: Coca-Cola, Coca-Cola sin azúcar, Coca-Cola Light, Sprite y Mundet.',
 14, 65.00)
ON DUPLICATE KEY UPDATE
  descripcion  = VALUES(descripcion),
  precio       = VALUES(precio),
  categoria_id = VALUES(categoria_id);

-- -------------------------------------------------------
-- Especiales de temporada
-- -------------------------------------------------------
--
-- La vitrina de la landing (views/home/_firma.php). La img de la categoría es
-- la que toma un especial sin foto propia (Carta::especiales()); es un archivo
-- propio y no una foto compartida porque reemplazarla desde Categorías borra
-- el archivo anterior.
INSERT INTO categorias (id, nombre, carta, img) VALUES
(15, 'Especiales de temporada', 'temporada', 'build/images/especiales-temporada.webp')
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), carta = VALUES(carta), img = VALUES(img);

-- Platillos de muestra: cambian cada semana y los da de alta la cocina desde
-- Menú. Tres llevan foto propia y dos no, para ver el respaldo de la
-- categoría. Las fotos son las que antes estaban fijas en gallery.js y ningún
-- otro registro usa: ImagenUploader::eliminar() borra el archivo anterior al
-- reemplazar la foto de un platillo, y una foto compartida con una categoría
-- la dejaría sin imagen.
INSERT INTO productos (nombre, descripcion, img, categoria_id, precio) VALUES
('Camarones a la Brasa',
 'Camarón U15 a las brasas con mantequilla de ajo ahumado, limón amarillo y perejil.',
 'build/images/mejor-5.webp', 15, 345.00),
('Tostas de Otoño',
 'Pan de masa madre con calabaza asada, ricotta batida, nuez tostada y miel de agave.',
 'build/images/mejor-3.webp', 15, 210.00),
('Aceitunas Tibias con Naranja',
 'Aceitunas mixtas tibias con cáscara de naranja, romero y aceite de oliva.',
 'build/images/mejor-1.webp', 15, 120.00),
('Crema de Calabaza Asada',
 'Calabaza de Castilla asada, pepita tostada y un hilo de crema de rancho.',
 NULL, 15, 165.00),
('Pan de Muerto con Nata',
 'Pan de muerto de la casa relleno de nata y azahar. Sólo durante la temporada.',
 NULL, 15, 95.00)
ON DUPLICATE KEY UPDATE
  descripcion  = VALUES(descripcion),
  img          = VALUES(img),
  precio       = VALUES(precio),
  categoria_id = VALUES(categoria_id);

COMMIT;

-- -------------------------------------------------------
-- Usuario administrador de DESARROLLO
-- -------------------------------------------------------
--
-- Usuario: admin · Contraseña: CasaPestalozzi-2026 (documentada en README.md).
-- Sólo para desarrollo y QA: en producción, crear el administrador real desde
-- el panel y borrar o desactivar éste.
INSERT INTO usuarios (username, nombre, password_hash, activo) VALUES
('admin', 'Administrador', '$2y$12$hinzWNw734kBdqSnbYSO2uEv9pISz.ZFQ5QW689/nKgPMkCn0CU12', 1);

-- -------------------------------------------------------
-- Registros de ejemplo por módulo
-- -------------------------------------------------------
--
-- Fechas relativas a CURDATE() para que el ejemplo no caduque: un dump cargado
-- meses después sigue mostrando algo en la agenda, el horario y el mapa.

-- Cata: queda en la agenda pública hasta que pase su fecha.
INSERT INTO catas (titulo, descripcion, fecha, hora, duracion_min, precio, disponible) VALUES
('Cata de vinos italianos',
 'Cuatro etiquetas del Piamonte y la Toscana maridadas con quesos de la casa.',
 CURDATE() + INTERVAL 14 DAY, '19:00:00', 90, 650.00, 1);

-- Excepción de horario: la landing la señala sobre la fila del día.
INSERT INTO excepciones_operacion (fecha, tipo, motivo, hora_apertura, hora_cierre, activo) VALUES
(CURDATE() + INTERVAL 5 DAY, 'horario_especial', 'Evento privado', '13:00:00', '18:00:00', 1);

-- Reservación confirmada con mesa asignada, para que el mapa operativo y el
-- listado del panel no arranquen vacíos.
INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado) VALUES
('Reservación de ejemplo', 'ninguno', NULL, CURDATE() + INTERVAL 3 DAY, '14:00:00', 2, 'Registro de ejemplo del seed.', 'admin', 'confirmada');
INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
SELECT r.id, m.id, 1
FROM reservaciones r
INNER JOIN mesas m ON m.numero = 1
WHERE r.nombre = 'Reservación de ejemplo';

-- Anuncio: sembrado apagado; se enciende desde Configuración › Anuncio.
INSERT INTO configuracion_anuncio (id, mensaje, tipo, activo) VALUES
(1, 'Nueva carta de temporada disponible.', 'novedad_menu', 0);
