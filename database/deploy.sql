-- Casa Pestalozzi — DML operativo
--
-- Datos mínimos para que una instalación nueva pueda operar: horario,
-- mesas, áreas de producción, categorías y catálogo del menú.
-- Ejecutar después de database/ddl.sql.
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
-- mueve pos_x: la estructura por filas es la misma. Caja y Llevar pasan de 17
-- a 24 puntos de separación porque a 768px (la tablet del mesero) los dos pines
-- "especial" quedaban a 15px y se leían como uno solo.
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
-- Áreas de producción
-- -------------------------------------------------------

-- El color de un área es dato de negocio, no token de diseño (es la excepción
-- declarada en CLAUDE.md), pero aun así sale de la paleta funcional: son los
-- mismos cuatro hex que Controllers\AdminAreaController::AREAS y que CP_AREAS
-- en el POS. Si cambias uno, cambia los tres o el tablero y el panel dejarán
-- de pintar la misma área del mismo color.
INSERT INTO areas_produccion (id, nombre, slug, color) VALUES
(1, 'Barra de Café',    'cafe',   '#46bdc6'),  -- --c-turquesa
(2, 'Barra de Jugos',   'jugos',  '#f5b400'),  -- --c-ambar
(3, 'Cocina',           'cocina', '#e51022'),  -- --c-rojo
(4, 'Horno Napolitano', 'horno',  '#4267ac');  -- --c-indigo

-- -------------------------------------------------------
-- Estaciones de impresión (ESC/POS)
-- -------------------------------------------------------
--
-- Una estación de comanda por área de producción más la de cuenta en la caja.
-- Va aquí, pegado a areas_produccion, porque `impresoras.area_id` es llave
-- foránea contra esa tabla: sembrarlas antes falla.
INSERT INTO impresoras (id, nombre, area_id, rol, conexion, host, puerto, dispositivo, ancho, activo) VALUES
(1, 'Comanda Café',   1, 'comanda', 'red', '192.168.1.51', 9100, NULL, 32, 1),
(2, 'Comanda Jugos',  2, 'comanda', 'red', '192.168.1.52', 9100, NULL, 32, 1),
(3, 'Comanda Cocina', 3, 'comanda', 'red', '192.168.1.53', 9100, NULL, 48, 1),
(4, 'Comanda Horno',  4, 'comanda', 'red', '192.168.1.54', 9100, NULL, 48, 1),
(5, 'Cuenta Caja', NULL, 'cuenta',  'red', '192.168.1.50', 9100, NULL, 48, 1);

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
(8, 'Para Picar',    'build/images/comida-6.webp'),
(9, 'Café & Bebidas',    'build/images/comida-1.webp'),
(10, 'Jugos & Smoothies', 'build/images/comida-2.webp');

-- -------------------------------------------------------
-- Productos — catálogo único (carta pública + POS)
-- -------------------------------------------------------

CREATE TEMPORARY TABLE productos_semilla (
  nombre VARCHAR(120) NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  precio DECIMAL(8,2) NOT NULL,
  area_id TINYINT UNSIGNED NOT NULL
);

INSERT INTO productos_semilla (nombre, categoria, precio, area_id) VALUES
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

INSERT INTO productos (nombre, categoria_id, precio, area_id)
SELECT ps.nombre, c.id, ps.precio, ps.area_id
FROM productos_semilla ps
INNER JOIN categorias c ON c.nombre = ps.categoria;

DROP TEMPORARY TABLE productos_semilla;

-- -------------------------------------------------------
-- Menú completo
-- -------------------------------------------------------

--
-- Sustituye al par 'productos' + 'menu', que guardaban lo mismo por duplicado:
-- borrar un platillo de la carta no lo quitaba del punto de venta.
--
-- Todo lo que está activo se vende Y se publica en la carta. Las bebidas de las
-- categorías 9 y 10 van sin descripción: en la carta se imprimen solo con
-- nombre y precio.
--
-- El INSERT de arriba ya dio de alta todos los platillos con su precio y área;
-- este solo aporta la descripción, así que choca a propósito contra el UNIQUE
-- de nombre y resuelve con ON DUPLICATE KEY. Sin eso el script moría aquí con
-- "Duplicate entry" en cualquier base creada desde cero.
--
-- categoria_id: 1 Desayunos · 2 Entradas · 3 Sopas & Cremas · 4 Pastas · 5 Platos Fuertes
--               6 Ensaladas · 7 Pizzas · 8 Para Picar · 9 Café & Bebidas · 10 Jugos & Smoothies
-- area_id:      1 Barra de Café · 2 Barra de Jugos · 3 Cocina · 4 Horno Napolitano
INSERT INTO productos (nombre, descripcion, categoria_id, precio, area_id) VALUES
-- Desayunos (categoria_id = 1)
('Enmoladas',
 'Rellenas de pollo (70 gr.) con láminas de plátano macho, crema, queso y aros de cebolla bañadas en mole negro de Oaxaca.',
 1, 240.00, 3),
('Enchiladas Suizas',
 'Enchiladas verdes rellenas de pollo (70 gr.), gratinadas con queso gouda, crema y aros de cebolla.',
 1, 220.00, 3),
('Cecina y Huevo con Chorizo',
 'Cecina (130 gr.), huevos revueltos (2 pzas) con chorizo, acompañados de frijoles refritos con queso.',
 1, 220.00, 3),
('Cazuela Cascabel',
 '3 huevos estrellados o revueltos en salsa de chile cascabel, queso oaxaca gratinado, aguacate y una rebanada de pan hogaza.',
 1, 220.00, 3),
('Sopes con Cecina o Arrachera',
 '3 sopes hechos a mano con frijoles, lechuga, crema, queso y cecina (130 gr.). Cambio de proteína con arrachera (150 gr.) +$40.',
 1, 220.00, 3),
('Enfrijoladas',
 'Rellenas de huevo revuelto, bañadas con salsa de frijol, chorizo, crema y queso.',
 1, 220.00, 3),
('Huevos al Parmesano',
 '2 huevos estrellados acompañados con espárragos blanqueados, arúgula, tocino y parmesano rallado.',
 1, 210.00, 3),
('Omelette Fitness',
 'Claras de huevo (2 pzas), espinaca, queso de cabra y láminas de aguacate.',
 1, 190.00, 3),
('Toast de Salmón Ahumado',
 'Pan brioche, crema ácida, salmón ahumado (70 gr.), ajonjolí, 1 huevo estrellado, espárragos y aguacate.',
 1, 230.00, 3),
('Pan Francés Estilo C.P.',
 'Base de pan brioche con crema dulce, frutos rojos y miel de maple.',
 1, 210.00, 3),
('Huevos Módena',
 '2 huevos revueltos o estrellados con tocino, queso parmesano y arúgula.',
 1, 190.00, 3),
('Huevos Italianos',
 '2 huevos en omelette, jamón serrano, láminas de queso parmesano y arúgula.',
 1, 190.00, 3),
('Huevos Pamplona',
 '2 huevos en omelette con chorizo español de pamplona, arúgula y queso mozarella fresco.',
 1, 190.00, 3),
('Huevos al Sano',
 '2 huevos en omelette con jamón de pavo, arúgula, queso mozarella fresco y jitomate cherry.',
 1, 190.00, 3),
('Huevos al Gusto',
 'Rancheros, a la mexicana, divorciados, al albañil, con tocino, con chorizo o con jamón.',
 1, 180.00, 3),
('Molletes',
 '4 piezas de pan baguette con frijoles y queso manchego, acompañado de pico de gallo.',
 1, 100.00, 3),
('Casa Pestalozzi',
 '½ orden de chilaquiles (40 gr.) con salsa al gusto, crema, queso y 2 huevos revueltos.',
 1, 180.00, 3),
('Chilaquiles',
 'Verdes, rojos o salsa de la casa, con pollo (30 gr.) o huevo (1 pza), queso, crema y cebolla morada. Con arrachera +$90 · con cecina +$65.',
 1, 180.00, 3),
('Baguette de Jamón Serrano',
 'Jamón serrano, láminas de parmesano, casse de jitomate y arúgula.',
 1, 220.00, 3),
('Baguette de Magret de Pollo',
 'Pollo a la plancha con queso gouda, rodajas de jitomate, mix de lechuga y aderezo cipriani.',
 1, 220.00, 3),
('Baguette con Arrachera',
 'Arrachera (150 gr.), cremoso de aguacate con un toque de chipotle y mix de lechugas.',
 1, 230.00, 3),
('Croissant con Jamón de Pavo',
 'Pechuga de pavo (120 gr.), queso gouda, aderezo cipriani, jitomate y mix de lechugas.',
 1, 165.00, 3),
('Croissant con Huevo y Estragón',
 '2 pzas de huevo revuelto con estragón y mix de lechugas.',
 1, 140.00, 3),
('Baguette de Cochinita',
 'Cochinita (150 gr.), cebolla encurtida y habanero.',
 1, 210.00, 3),
('Plato de Fruta Mixta',
 'Fruta de temporada.',
 1, 110.00, 2),
('Copa Antioxidante',
 'Fresa, frambuesa, mora y zarzamora con yogurt y granola hecha en casa.',
 1, 130.00, 2),

-- Entradas (categoria_id = 2)
('Aros de Calamar',
 'Empanizados, aderezo cipriani, chiles cuaresmeños y limón eureka.',
 2, 210.00, 3),
('Tostadas de Atún',
 '3 tostaditas con cubos de atún marinado en salsa oriental, cremoso de aguacate y poro.',
 2, 195.00, 3),
('Torreta de Salmón',
 'Salmón ahumado, queso cabra, aguacate, jitomate con aderezo de pesto de albahaca.',
 2, 220.00, 3),
('Tiradito de Atún',
 'Láminas de atún, aceite de chile, mayonesa spicy, toronja y eneldo.',
 2, 210.00, 3),
('Carpaccio de Salmón',
 'Finas láminas de salmón ahumado, arúgula, queso parmesano, alcaparras, limón eureka y jitomate cherry.',
 2, 180.00, 3),
('Camarones al Ajillo',
 'Salteados al olivo, ajo, peperoncino con pan de baguette.',
 2, 210.00, 3),
('Espárragos al Horno',
 'Queso gouda, tocino con reducción de balsámico.',
 2, 180.00, 4),
('Queso Burrata con Jitomates Cherrys',
 'Queso burrata con jitomates cherrys al horno, aceite de oliva, poro y hojas de albahaca.',
 2, 210.00, 4),

-- Sopas & Cremas (categoria_id = 3)
('Crema del Día',
 'Nuestras cremas y sopas son elaboradas por temporada y en nuestros especiales de fin de semana. Pregunta al mesero por la opción del día.',
 3, 180.00, 3),
('Sopa Especial de Fin de Semana',
 'Receta de la casa, elaborada con ingredientes frescos de temporada. Disponible sábados y domingos.',
 3, 180.00, 3),

-- Pastas (categoria_id = 4)
('Fetuccini a los Cuatro Quesos y Camarones',
 'Queso brie, parmesano, queso crema y queso gouda.',
 4, 280.00, 3),
('Lasagna de Filete de Res',
 'Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',
 4, 280.00, 3),
('Rigatoni al Limón con Camarones y Parmesano',
 'Camarones salteados con vino blanco, mantequilla, ralladura de limón eureka y toque de albahaca.',
 4, 280.00, 3),
('Spaguetti a l''Arrabbiata con Camarones y Parmesano',
 'Salsa de pomodoro con peperoncino.',
 4, 280.00, 3),
('Spaguetti a la Boloñesa',
 'Cocción a baja temperatura por 3 horas con ingredientes 100% italianos.',
 4, 280.00, 3),
('Spaguetti al Pomodoro y Parmesano',
 'Pasta, salsa de jitomate y parmesano.',
 4, 190.00, 3),

-- Platos Fuertes (categoria_id = 5)
('Filete de Res en su Jugo',
 'Filete de res importado en su jugo con puré de papa rústico y espárragos al horno.',
 5, 320.00, 3),
('Salmón al Horno',
 'Salmón noruego sazonado con ajo y aceite de oliva. Acompaña con media orden de pasta o ensalada.',
 5, 295.00, 3),
('Hamburguesa de la Casa',
 'Carne wagyu, pan brioche hecho en C.P., cebolla caramelizada, queso cheddar, mayonesa ahumada, pepinillo encurtido. Acompaña con papas gajo.',
 5, 260.00, 3),
('Atún Sellado',
 'Atún importado, sellado en costra de pistache, aderezo cipriani. Acompaña con mix de lechugas.',
 5, 285.00, 3),
('Tacos de Cochinita',
 'Tres tacos de tortilla de maíz hechas a mano, frijol, cebolla y habanero encurtido.',
 5, 210.00, 3),
('Tacos de Vacío',
 'Vacío importado, tortillas hechas a mano, salsa de piña con habanero y aguacate.',
 5, 210.00, 3),
('Tacos de Camarón Rebozados',
 'Tres tortillas de harina, camarones rebozados, col morada y aderezo de chipotle.',
 5, 240.00, 3),
('Vacío en Escalopas',
 'Vacío importado en escalopas, arúgula, láminas de parmesano y reducción de bálsamico.',
 5, 280.00, 3),
('New York (450 grs.)',
 'Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',
 5, 785.00, 3),
('Rib Eye (450 grs.)',
 'Carne calidad choice angus, cebollitas asadas, chiles toreados y papas a la francesa.',
 5, 785.00, 3),

-- Ensaladas (categoria_id = 6)
('Frutos Rojos',
 'Mix de lechugas, frambuesas, zarzamoras, fresas, queso cabra, nuez y reducción de balsámico.',
 6, 210.00, 3),
('Ciruela Betabel',
 'Mix de lechugas, ciruela y betabel sazonado con estragón, queso burrata y almendras horneadas.',
 6, 210.00, 3),
('Magret de Pollo',
 'Pechuga de pollo prensada, lechuga baby asada, almendras horneadas con aderezo de queso.',
 6, 210.00, 3),
('Jamón Serrano con Perlas de Melón',
 'Mix de lechugas, perlas de melón, jamón serrano, nuez y reducción de balsámico.',
 6, 210.00, 3),
('Pasta Corta con Pollo',
 'Mix de lechuga con cremoso de aguacate y almendras horneadas.',
 6, 210.00, 3),

-- Pizzas (categoria_id = 7)
('Margarita',
 'Pomodoro, mozzarella y albahaca.',
 7, 190.00, 4),
('Burrata',
 'Pomodoro, burrata, prosciutto y arúgula.',
 7, 260.00, 4),
('Milano',
 'Pomodoro, mozzarella, jitomates cherrys, salami y láminas de parmesano.',
 7, 260.00, 4),
('Camarones a los 4 Quesos',
 'Salsa de 4 quesos, queso mozzarella y camarones.',
 7, 260.00, 4),

-- Para Picar (categoria_id = 8)
('Mix de 3 Brusquetas',
 'Jamón serrano, queso brie, anchoas.',
 8, 160.00, 3),
('Aceitunas Temperadas con Aceite de Chile',
 'Aceitunas verdes en aceite de chiles.',
 8, 160.00, 3),
('Tabla Mixta',
 'Queso parmesano, brie, manchego, chorizo salamanca, semillas, frutos rojos.',
 8, 320.00, 3),
('Papas a la Francesa con Parmesano',
 'Papas a la francesa con queso parmesano rallado.',
 8, 160.00, 3)
ON DUPLICATE KEY UPDATE descripcion = VALUES(descripcion);

-- Las bebidas se sembraron sin descripción a propósito, pero `descripcion` no
-- puede quedar vacía para la carta ni el PDF: se rellena con el nombre.
UPDATE productos SET descripcion = CONCAT(nombre, '.')
WHERE descripcion IS NULL OR descripcion = '';
