-- =====================================================================
-- development.sql — datos de DESARROLLO y QA.
--
-- Opcional: no cargar nunca en producción. El orden de instalación es
--
--     1. database/ddl.sql          estructura (DROP + CREATE completo)
--     2. database/deploy.sql       datos mínimos de operación
--     3. database/development.sql  esto, sólo en desarrollo o QA
--
-- Funde los tres fixtures que antes vivían sueltos —dml_pruebas.sql,
-- analiticas-datos-ex.sql y REVPash-pruebas.sql— porque cargarlos por separado
-- obligaba a recordar el orden y ninguno de los tres lo decía igual.
--
-- Los tres bloques conviven sin pisarse porque cada uno se acota a su propio
-- rango: las analíticas trabajan sobre los tickets 200-299 y los tokens
-- 'fx-analytics-res-%', y el fixture de RevPASH sobre los 300-499 y
-- 'fx-revpash-res-%'. Sus DELETE de arranque respetan ese reparto.
--
-- SE CARGA UNA VEZ, sobre una base recién creada. Los bloques 2 y 3 sí son
-- reidempotentes por su cuenta —borran su rango antes de sembrarlo—, pero el
-- bloque 1 no lo fue nunca: usa ids explícitos sin ON DUPLICATE KEY y una
-- segunda pasada choca contra tickets.PRIMARY. Para rehacer el entorno, volver
-- a crear la base desde ddl.sql.
--
-- Dos cosas se arreglaron al fundirlos, y las dos estaban rotas antes:
--
--   · Los INSERT de `reservaciones` de los dos fixtures escribían cinco
--     columnas que ddl.sql no declara (confirmed_at, completed_at,
--     last_change_reason, status_changed_at, last_modified_source). Sobre una
--     base creada de cero fallaban con "Unknown column" — el propio
--     REVPash-pruebas.sql lo avisaba en un comentario. Ninguna de las cinco la
--     leía una línea de PHP: tres se retiraron y dos se renombraron a la
--     columna real (estado_changed_at y origen, con sus valores de ENUM).
--   · La siembra de ingredientes y recetas estaba duplicada palabra por
--     palabra en los dos fixtures. Ahora se siembra una sola vez.
-- =====================================================================


-- ═════════════════════════════════════════════════════════════════════
-- BLOQUE 1 · Datos base de desarrollo   (antes database/dml_pruebas.sql)
-- ═════════════════════════════════════════════════════════════════════

-- Casa Pestalozzi — DML de pruebas y demostración
--
-- Datos ficticios para desarrollo y QA: usuarios demo, ventas, feedback,
-- reservaciones, tickets, inventario y escenarios operativos.
-- (Cabecera original de dml_pruebas.sql; el orden real lo fija la de arriba.)
-- No usar este archivo como semilla de producción.
--
SET NAMES utf8mb4;
SET @HOY := CURDATE();

-- El catálogo base —áreas, categorías, mesas, productos, productos_semilla y
-- el horario semanal— NO se siembra aquí: es de deploy.sql, que se carga
-- antes. dml_pruebas.sql traía una copia de las seis, cinco de ellas idénticas
-- palabra por palabra, porque también quería poder cargarse solo; al fundir
-- los fixtures eso chocaba contra uq_horarios_operacion_dia y contra
-- uq_productos_nombre. Dos semillas compitiendo por la misma tabla dejan que
-- el orden de carga decida los datos del negocio, que es justo lo que
-- analiticas-datos-ex.sql ya había aprendido con el horario.

-- -------------------------------------------------------
-- Productos — catálogo único (carta pública + POS)
-- -------------------------------------------------------

CREATE TEMPORARY TABLE productos_semilla (
  nombre VARCHAR(120) NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  precio DECIMAL(8,2) NOT NULL,
  area_id TINYINT UNSIGNED NOT NULL
);

DROP TEMPORARY TABLE productos_semilla;

-- -------------------------------------------------------
-- Menú completo
-- -------------------------------------------------------

--
-- Todo lo que está activo se vende Y se publica en la carta. Las bebidas de las
-- categorías 9 y 10 van sin descripción: en la carta se imprimen solo con
-- nombre y precio.
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
-- 'productos_semilla' (arriba) ya dio de alta el catálogo completo, incluidas
-- las bebidas. Este bloque es el que aporta la descripción, así que
-- enriquece la fila existente en vez de chocar contra uq_productos_nombre.
ON DUPLICATE KEY UPDATE
  descripcion  = VALUES(descripcion),
  categoria_id = VALUES(categoria_id),
  precio       = VALUES(precio),
  area_id      = VALUES(area_id);

-- `productos` es la fuente funcional consumida por carta, PDF y POS: la
-- descripción ya quedó puesta por el bloque de arriba.

-- -------------------------------------------------------
-- Usuarios de desarrollo.
--
-- admin_demo entra en /login (pestaña Contraseña) con la contraseña
-- Pestalozzi2026.
--
-- Los cinco de piso se declaran aquí SIN credencial utilizable —password_hash
-- imposible de acertar, NIP en NULL— y con id explícito. No es una comodidad:
-- los tickets de los fixtures de abajo apuntan a mesero_id 2..6 con una clave
-- foránea, así que sin estas filas el archivo entero fallaba con
-- "Cannot add or update a child row". Antes no se notaba porque los tres
-- fixtures no se cargaban seguidos.
--
-- Para que ADEMÁS puedan entrar, hay que correr después:
--
--     php scripts/seed-usuarios-prueba.php
--
-- que calcula el HMAC de cada NIP con NIP_LOOKUP_SECRET y los enseña sólo en la
-- salida de esa ejecución. Casa por `username`, que es UNIQUE, así que actualiza
-- estas mismas filas en vez de duplicarlas y el orden de los ids se conserva.
--
-- El ON DUPLICATE KEY deja el bloque idempotente y, sobre todo, no pisa las
-- credenciales que ya haya sembrado esa rutina: sólo mantiene nombre, rol y
-- estado.
-- -------------------------------------------------------

INSERT INTO usuarios (id, username, nombre, password_hash, nip_hash, nip_lookup, rol, activo) VALUES
(1, 'admin_demo',      'Administrador Demo', '$2y$12$qH/BVO2OPCYRbt7rUfYtIecXWTXOSk8hxWavaadrcfbwEnIHsXXd.', NULL, NULL, 'admin',  1),
(2, 'mesero1',         'Carlos Hernández',   '!sin-credencial', NULL, NULL, 'waiter', 1),
(3, 'mesero2',         'Valeria Ríos',       '!sin-credencial', NULL, NULL, 'waiter', 1),
(4, 'cocinero1',       'Mariana López',      '!sin-credencial', NULL, NULL, 'cook',   1),
(5, 'mesero3',         'Emilio Cárdenas',    '!sin-credencial', NULL, NULL, 'waiter', 1),
(6, 'mesero_inactivo', 'Daniel Torres',      '!sin-credencial', NULL, NULL, 'waiter', 0)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  rol    = VALUES(rol),
  activo = VALUES(activo);

-- -------------------------------------------------------
-- Tickets de ejemplo (para /admin/tickets)
-- id explícito para poder referenciarlos en items y feedback.
--
-- Históricos (cerrados/cancelados): fecha fija, los referencia feedback.
-- Abiertos: uno por cada mesa, con hora_apertura relativa a NOW() para que
-- siempre lleven entre 1 y 45 minutos activos sin importar cuándo se siembre.
-- metodo_pago va NULL: se asigna al cerrar el ticket.
--
-- IMPORTANTE: ticket_items.nombre debe coincidir EXACTO con productos.nombre,
-- de lo contrario el JOIN por nombre descarta la fila silenciosamente.
-- -------------------------------------------------------

INSERT INTO tickets (id, comensales, nombre, hora_apertura, estado, metodo_pago) VALUES
-- Históricos
(1, 2, 'Camila Estrada',   '2026-06-18 14:05:00', 'cerrado',   'tarjeta'),
(2, 4, 'Javier Montiel',   '2026-06-18 14:40:00', 'cerrado',   'efectivo'),
(3, 6, 'Familia Guerrero', '2026-06-18 20:10:00', 'cerrado',   'tarjeta'),
(5, 4, 'Nicolás Andrade',  '2026-06-18 21:05:00', 'cerrado',   'tarjeta'),
(7, 3, 'Mesa 5',           '2026-06-18 16:00:00', 'cancelado', 'efectivo'),
(8, 4, 'Grupo Torres',     '2026-06-18 15:15:00', 'cerrado',   'efectivo'),
-- Abiertos ahora — una mesa por ticket, 1 a 45 minutos de antigüedad.
-- Todos llevan el nombre de quien está sentado: el POS lo muestra en el
-- encabezado del modal y sin él la mesa sale anónima. Caja y Llevar son la
-- excepción: no son comensales, son puntos de despacho.
(9, 2, 'Ana Villalobos',    NOW() - INTERVAL  3 MINUTE, 'abierto', NULL),
(10, 4, 'Renata Ibáñez',     NOW() - INTERVAL 41 MINUTE, 'abierto', NULL),
(11, 3, 'Javier Montiel',    NOW() - INTERVAL 18 MINUTE, 'abierto', NULL),
(12, 2, 'Diego Lozano',      NOW() - INTERVAL  7 MINUTE, 'abierto', NULL),
(13, 4, 'Familia Cuevas',    NOW() - INTERVAL 33 MINUTE, 'abierto', NULL),
(14, 4, 'Familia Guerrero',  NOW() - INTERVAL 22 MINUTE, 'abierto', NULL),
(15, 3, 'Grupo Salinas',     NOW() - INTERVAL 45 MINUTE, 'abierto', NULL),
(4, 2, 'Sofía Pedraza',     NOW() - INTERVAL 12 MINUTE, 'abierto', NULL),
(16, 2, 'Mauricio Trejo',    NOW() - INTERVAL 29 MINUTE, 'abierto', NULL),
(17, 4, 'Lucía Bermúdez',    NOW() - INTERVAL  5 MINUTE, 'abierto', NULL),
(6, 4, 'Fernanda & Roque',  NOW() - INTERVAL 27 MINUTE, 'abierto', NULL),
(18, 5, 'Grupo Peralta',     NOW() - INTERVAL 15 MINUTE, 'abierto', NULL),
(19, 1, 'Caja',              NOW() - INTERVAL  1 MINUTE, 'abierto', NULL),
(20, 1, 'Llevar',            NOW() - INTERVAL  9 MINUTE, 'abierto', NULL),
(21, 4, 'Tomás Iriarte',     NOW() - INTERVAL 36 MINUTE, 'abierto', NULL),
(22, 3, 'Paulina Cortés',    NOW() - INTERVAL 24 MINUTE, 'abierto', NULL);


INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
  (1, 1, 1),
  (2, 3, 1),
  (3, 6, 1),
  (5, 2, 1),
  (7, 5, 1),
  (8, 7, 1),
  (9, 1, 1),
  (10, 2, 1),
  (11, 3, 1),
  (12, 4, 1),
  (13, 5, 1),
  (14, 6, 1),
  (15, 7, 1),
  (4, 8, 1),
  (16, 9, 1),
  (17, 10, 1),
  (6, 11, 1),
  (18, 12, 1),
  (19, 13, 1),
  (20, 14, 1),
  (21, 15, 1),
  (22, 16, 1);

-- Items por ticket (definen el total mostrado en /admin/tickets)
-- created_at de los abiertos va 1-2 min después de su hora_apertura.
INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
-- T1 (cerrado)
(1, 'Toast de Salmón Ahumado', 230.00, 'Desayunos',        3, 1, 'entregado', '2026-06-18 14:10:00'),
(1, 'Cappuccino',               75.00, 'Café & Bebidas',    1, 2, 'entregado', '2026-06-18 14:10:00'),
-- T2 (cerrado)
(2, 'Enchiladas Suizas',       220.00, 'Desayunos',        3, 2, 'entregado', '2026-06-18 14:45:00'),
(2, 'Jugo Verde',               95.00, 'Jugos & Smoothies', 2, 2, 'entregado', '2026-06-18 14:45:00'),
(2, 'Café Americano',           65.00, 'Café & Bebidas',    1, 2, 'entregado', '2026-06-18 14:52:00'),
-- T3 (cerrado, grupo)
(3, 'Rib Eye (450 grs.)',      785.00, 'Platos Fuertes',   3, 1, 'entregado', '2026-06-18 20:15:00'),
(3, 'Milano',                  260.00, 'Pizzas',           4, 2, 'entregado', '2026-06-18 20:15:00'),
(3, 'Camarones al Ajillo',     210.00, 'Entradas',         3, 1, 'entregado', '2026-06-18 20:15:00'),
(3, 'Limonada Natural',         75.00, 'Jugos & Smoothies', 2, 4, 'entregado', '2026-06-18 20:16:00'),
-- T5 (cerrado)
(5, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes',  3, 2, 'entregado', '2026-06-18 21:10:00'),
(5, 'Queso Burrata con Jitomates Cherrys', 210.00, 'Entradas', 4, 1, 'entregado', '2026-06-18 21:10:00'),
-- T8 (cerrado)
(8, 'Hamburguesa de la Casa',  260.00, 'Platos Fuertes',   3, 2, 'entregado', '2026-06-18 15:20:00'),
(8, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', '2026-06-18 15:20:00'),
(8, 'Refresco',                 55.00, 'Café & Bebidas',    1, 4, 'entregado', '2026-06-18 15:21:00'),

-- T9 — Mesa 1 (3 min): recién ordenado
( 9, 'Café Americano',          65.00, 'Café & Bebidas',    1, 2, 'enviado',        NOW() - INTERVAL  2 MINUTE),
( 9, 'Molletes',               100.00, 'Desayunos',         3, 1, 'enviado',        NOW() - INTERVAL  2 MINUTE),
-- T10 — Mesa 2 (41 min): ya comiendo
(10, 'Rigatoni al Limón con Camarones y Parmesano', 280.00, 'Pastas', 3, 2, 'entregado', NOW() - INTERVAL 39 MINUTE),
(10, 'Milano',                 260.00, 'Pizzas',            4, 1, 'listo',          NOW() - INTERVAL 38 MINUTE),
(10, 'Limonada Natural',        75.00, 'Jugos & Smoothies', 2, 4, 'entregado',      NOW() - INTERVAL 39 MINUTE),
-- T11 — Mesa 3 (18 min)
(11, 'Chilaquiles',            180.00, 'Desayunos',         3, 2, 'en_preparacion', NOW() - INTERVAL 16 MINUTE),
(11, 'Jugo Verde',              95.00, 'Jugos & Smoothies', 2, 3, 'entregado',      NOW() - INTERVAL 16 MINUTE),
(11, 'Cappuccino',              75.00, 'Café & Bebidas',    1, 1, 'listo',          NOW() - INTERVAL 15 MINUTE),
-- T12 — Mesa 4 (7 min)
(12, 'Toast de Salmón Ahumado',230.00, 'Desayunos',         3, 2, 'enviado',        NOW() - INTERVAL  5 MINUTE),
(12, 'Jugo de Naranja',         85.00, 'Jugos & Smoothies', 2, 2, 'enviado',        NOW() - INTERVAL  5 MINUTE),
-- T13 — Mesa 5 (33 min)
(13, 'Filete de Res en su Jugo',320.00,'Platos Fuertes',    3, 2, 'en_preparacion', NOW() - INTERVAL 31 MINUTE),
(13, 'Aros de Calamar',        210.00, 'Entradas',          3, 1, 'entregado',      NOW() - INTERVAL 31 MINUTE),
(13, 'Refresco',                55.00, 'Café & Bebidas',    1, 4, 'entregado',      NOW() - INTERVAL 30 MINUTE),
-- T14 — Mesa 6 (22 min)
(14, 'Burrata',                260.00, 'Pizzas',            4, 2, 'en_preparacion', NOW() - INTERVAL 20 MINUTE),
(14, 'Tabla Mixta',            320.00, 'Para Picar',        3, 1, 'entregado',      NOW() - INTERVAL 20 MINUTE),
(14, 'Agua Fresca',             60.00, 'Café & Bebidas',    1, 4, 'entregado',      NOW() - INTERVAL 19 MINUTE),
-- T15 — Mesa 7 (45 min): el más antiguo
(15, 'Rib Eye (450 grs.)',     785.00, 'Platos Fuertes',    3, 1, 'entregado',      NOW() - INTERVAL 43 MINUTE),
(15, 'Vacío en Escalopas',     280.00, 'Platos Fuertes',    3, 1, 'entregado',      NOW() - INTERVAL 43 MINUTE),
(15, 'Espárragos al Horno',    180.00, 'Entradas',          4, 1, 'listo',          NOW() - INTERVAL 42 MINUTE),
(15, 'Café de Olla',            65.00, 'Café & Bebidas',    1, 3, 'entregado',      NOW() - INTERVAL 40 MINUTE),
-- T4 — Mesa 8 (12 min)
( 4, 'Chilaquiles',            180.00, 'Desayunos',         3, 2, 'en_preparacion', NOW() - INTERVAL 10 MINUTE),
( 4, 'Café de Olla',            65.00, 'Café & Bebidas',    1, 2, 'enviado',        NOW() - INTERVAL 10 MINUTE),
-- T16 — Mesa 9 (29 min)
(16, 'Salmón al Horno',        295.00, 'Platos Fuertes',    3, 1, 'listo',          NOW() - INTERVAL 27 MINUTE),
(16, 'Frutos Rojos',           210.00, 'Ensaladas',         3, 1, 'entregado',      NOW() - INTERVAL 27 MINUTE),
(16, 'Té / Infusión',           65.00, 'Café & Bebidas',    1, 2, 'entregado',      NOW() - INTERVAL 26 MINUTE),
-- T17 — Mesa 10 (5 min)
(17, 'Mix de 3 Brusquetas',    160.00, 'Para Picar',        3, 2, 'enviado',        NOW() - INTERVAL  4 MINUTE),
(17, 'Smoothie de Fresa',      100.00, 'Jugos & Smoothies', 2, 2, 'enviado',        NOW() - INTERVAL  4 MINUTE),
(17, 'Latte',                   80.00, 'Café & Bebidas',    1, 2, 'enviado',        NOW() - INTERVAL  3 MINUTE),
-- T6 — Mesa 11 (27 min)
( 6, 'Burrata',                260.00, 'Pizzas',            4, 2, 'en_preparacion', NOW() - INTERVAL 25 MINUTE),
( 6, 'Aros de Calamar',        210.00, 'Entradas',          3, 1, 'listo',          NOW() - INTERVAL 25 MINUTE),
( 6, 'Agua de Coco',            90.00, 'Jugos & Smoothies', 2, 4, 'entregado',      NOW() - INTERVAL 24 MINUTE),
-- T18 — Barra Blanca (15 min)
(18, 'Camarones a los 4 Quesos',260.00,'Pizzas',            4, 2, 'en_preparacion', NOW() - INTERVAL 13 MINUTE),
(18, 'Aceitunas Temperadas con Aceite de Chile', 160.00, 'Para Picar', 3, 2, 'entregado', NOW() - INTERVAL 13 MINUTE),
(18, 'Cappuccino',              75.00, 'Café & Bebidas',    1, 5, 'listo',          NOW() - INTERVAL 12 MINUTE),
-- T19 — Caja (1 min): el más reciente
(19, 'Café Americano',          65.00, 'Café & Bebidas',    1, 1, 'enviado',        NOW() - INTERVAL  1 MINUTE),
(19, 'Croissant con Jamón de Pavo', 165.00, 'Desayunos',    3, 1, 'enviado',        NOW() - INTERVAL  1 MINUTE),
-- T20 — Llevar (9 min)
(20, 'Baguette de Cochinita',  210.00, 'Desayunos',         3, 2, 'en_preparacion', NOW() - INTERVAL  8 MINUTE),
(20, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'enviado',    NOW() - INTERVAL  8 MINUTE),
(20, 'Agua de Coco',            90.00, 'Jugos & Smoothies', 2, 1, 'listo',          NOW() - INTERVAL  7 MINUTE),
-- T21 — Barra Roja (36 min)
(21, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes',    3, 2, 'entregado',      NOW() - INTERVAL 34 MINUTE),
(21, 'Tacos de Cochinita',     210.00, 'Platos Fuertes',    3, 1, 'entregado',      NOW() - INTERVAL 34 MINUTE),
(21, 'Chocolate Caliente',      80.00, 'Café & Bebidas',    1, 2, 'entregado',      NOW() - INTERVAL 33 MINUTE),
(21, 'Margarita',              190.00, 'Pizzas',            4, 1, 'listo',          NOW() - INTERVAL 33 MINUTE),
-- T22 — Barra Roja 2 (24 min)
(22, 'Lasagna de Filete de Res',280.00,'Pastas',            3, 2, 'en_preparacion', NOW() - INTERVAL 22 MINUTE),
(22, 'Carpaccio de Salmón',    180.00, 'Entradas',          3, 1, 'entregado',      NOW() - INTERVAL 22 MINUTE),
(22, 'Copa Antioxidante',      130.00, 'Desayunos',         2, 1, 'entregado',      NOW() - INTERVAL 21 MINUTE);

-- -------------------------------------------------------
-- HISTORIAL DE CLIENTES RECURRENTES (afinidad para las sugerencias)
--
-- El flujo de n8n mide la afinidad con esta cadena:
--     ticket_items -> tickets
-- y cuenta UNA VEZ POR VISITA en que el cliente pidio el platillo
-- (COUNT(*) de filas, no la cantidad). De ahi sale veces_cliente, que pesa
-- doble en el puntaje: (veces_cliente * 2) + veces_similares.
--
-- Tres visitas por cliente: el favorito aparece en las tres y el secundario
-- en dos.
--
-- Los nombres deben coincidir EXACTO con productos.nombre: el motor de
-- recomendacion parte de 'productos' y une por nombre.
-- -------------------------------------------------------

-- Tickets cerrados para datos históricos de consumo.
INSERT INTO tickets (id, comensales, nombre, hora_apertura, estado, metodo_pago) VALUES
(101, 2, 'Camila Estrada',   '2026-05-08 09:05:00', 'cerrado', 'tarjeta'),
(102, 2, 'Camila Estrada',   '2026-05-22 09:35:00', 'cerrado', 'tarjeta'),
(103, 2, 'Camila Estrada',   '2026-06-05 09:05:00', 'cerrado', 'tarjeta'),
(104, 4, 'Javier Montiel',   '2026-05-11 14:05:00', 'cerrado', 'efectivo'),
(105, 3, 'Javier Montiel',   '2026-05-27 14:05:00', 'cerrado', 'tarjeta'),
(106, 4, 'Javier Montiel',   '2026-06-10 13:35:00', 'cerrado', 'efectivo'),
(107, 6, 'Familia Guerrero', '2026-05-09 13:05:00', 'cerrado', 'tarjeta'),
(108, 5, 'Familia Guerrero', '2026-05-30 13:35:00', 'cerrado', 'tarjeta'),
(109, 6, 'Familia Guerrero', '2026-06-13 13:05:00', 'cerrado', 'tarjeta'),
(110, 4, 'Nicolas Andrade',  '2026-05-14 19:05:00', 'cerrado', 'tarjeta'),
(111, 2, 'Nicolas Andrade',  '2026-05-28 19:35:00', 'cerrado', 'tarjeta'),
(112, 4, 'Nicolas Andrade',  '2026-06-11 19:05:00', 'cerrado', 'tarjeta'),
(113, 2, 'Sofia Pedraza',    '2026-05-15 15:05:00', 'cerrado', 'efectivo'),
(114, 2, 'Sofia Pedraza',    '2026-05-29 15:35:00', 'cerrado', 'tarjeta'),
(115, 3, 'Sofia Pedraza',    '2026-06-12 15:05:00', 'cerrado', 'tarjeta'),
(116, 2, 'Fernanda & Roque', '2026-05-16 20:05:00', 'cerrado', 'tarjeta'),
(117, 4, 'Fernanda & Roque', '2026-05-31 20:35:00', 'cerrado', 'tarjeta'),
(118, 2, 'Fernanda & Roque', '2026-06-14 20:05:00', 'cerrado', 'tarjeta');


INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
  (101, 5, 1),
  (102, 5, 1),
  (103, 1, 1),
  (104, 3, 1),
  (105, 3, 1),
  (106, 4, 1),
  (107, 6, 1),
  (108, 6, 1),
  (109, 7, 1),
  (110, 2, 1),
  (111, 2, 1),
  (112, 4, 1),
  (113, 8, 1),
  (114, 8, 1),
  (115, 9, 1),
  (116, 11, 1),
  (117, 10, 1),
  (118, 11, 1);

-- Consumo de cada visita. El favorito se repite en las 3 y el secundario en
-- 2; el resto son acompanamientos que no marcan patron.
INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
-- Camila Estrada — favorito: Enmoladas (3/3) · secundario: Enchiladas Suizas (2/3)
(101, 'Enmoladas',                240.00, 'Desayunos',      3, 1, 'entregado', '2026-05-08 09:10:00'),
(101, 'Enchiladas Suizas',        220.00, 'Desayunos',      3, 1, 'entregado', '2026-05-08 09:10:00'),
(101, 'Cappuccino',                75.00, 'Café & Bebidas', 1, 2, 'entregado', '2026-05-08 09:11:00'),
(102, 'Enmoladas',                240.00, 'Desayunos',      3, 1, 'entregado', '2026-05-22 09:40:00'),
(102, 'Enchiladas Suizas',        220.00, 'Desayunos',      3, 1, 'entregado', '2026-05-22 09:40:00'),
(103, 'Enmoladas',                240.00, 'Desayunos',      3, 1, 'entregado', '2026-06-05 09:10:00'),
(103, 'Café Americano',            65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-06-05 09:11:00'),
-- Javier Montiel — favorito: Spaguetti a la Bolonesa (3/3) · secundario: Mix de 3 Brusquetas (2/3)
(104, 'Spaguetti a la Boloñesa',  280.00, 'Pastas',         3, 2, 'entregado', '2026-05-11 14:10:00'),
(104, 'Mix de 3 Brusquetas',      160.00, 'Para Picar',     3, 1, 'entregado', '2026-05-11 14:10:00'),
(104, 'Limonada Natural',          75.00, 'Jugos & Smoothies', 2, 4, 'entregado', '2026-05-11 14:11:00'),
(105, 'Spaguetti a la Boloñesa',  280.00, 'Pastas',         3, 1, 'entregado', '2026-05-27 14:10:00'),
(105, 'Mix de 3 Brusquetas',      160.00, 'Para Picar',     3, 1, 'entregado', '2026-05-27 14:10:00'),
(106, 'Spaguetti a la Boloñesa',  280.00, 'Pastas',         3, 2, 'entregado', '2026-06-10 13:40:00'),
(106, 'Crema del Día',            180.00, 'Sopas & Cremas', 3, 1, 'entregado', '2026-06-10 13:40:00'),
-- Familia Guerrero — favorito: Margarita (3/3) · secundario: Papas a la Francesa (2/3)
(107, 'Margarita',                190.00, 'Pizzas',         4, 3, 'entregado', '2026-05-09 13:10:00'),
(107, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', '2026-05-09 13:10:00'),
(108, 'Margarita',                190.00, 'Pizzas',         4, 2, 'entregado', '2026-05-30 13:40:00'),
(108, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-05-30 13:40:00'),
(109, 'Margarita',                190.00, 'Pizzas',         4, 3, 'entregado', '2026-06-13 13:10:00'),
(109, 'Milano',                   260.00, 'Pizzas',         4, 1, 'entregado', '2026-06-13 13:10:00'),
-- Nicolas Andrade — favorito: Rib Eye (3/3) · secundario: Aceitunas Temperadas (2/3)
(110, 'Rib Eye (450 grs.)',       785.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-05-14 19:10:00'),
(110, 'Aceitunas Temperadas con Aceite de Chile', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-05-14 19:10:00'),
(111, 'Rib Eye (450 grs.)',       785.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-05-28 19:40:00'),
(111, 'Aceitunas Temperadas con Aceite de Chile', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-05-28 19:40:00'),
(112, 'Rib Eye (450 grs.)',       785.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-06-11 19:10:00'),
(112, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-06-11 19:10:00'),
-- Sofia Pedraza — favorito: Frutos Rojos (3/3) · secundario: Crema del Dia (2/3)
(113, 'Frutos Rojos',             210.00, 'Ensaladas',      3, 1, 'entregado', '2026-05-15 15:10:00'),
(113, 'Crema del Día',            180.00, 'Sopas & Cremas', 3, 1, 'entregado', '2026-05-15 15:10:00'),
(114, 'Frutos Rojos',             210.00, 'Ensaladas',      3, 1, 'entregado', '2026-05-29 15:40:00'),
(114, 'Crema del Día',            180.00, 'Sopas & Cremas', 3, 1, 'entregado', '2026-05-29 15:40:00'),
(115, 'Frutos Rojos',             210.00, 'Ensaladas',      3, 2, 'entregado', '2026-06-12 15:10:00'),
(115, 'Jugo Verde',                95.00, 'Jugos & Smoothies', 2, 2, 'entregado', '2026-06-12 15:11:00'),
-- Fernanda & Roque — favorito: Tabla Mixta (3/3) · secundario: Burrata (2/3)
(116, 'Tabla Mixta',              320.00, 'Para Picar',     3, 1, 'entregado', '2026-05-16 20:10:00'),
(116, 'Burrata',                  260.00, 'Pizzas',         4, 1, 'entregado', '2026-05-16 20:10:00'),
(117, 'Tabla Mixta',              320.00, 'Para Picar',     3, 2, 'entregado', '2026-05-31 20:40:00'),
(117, 'Burrata',                  260.00, 'Pizzas',         4, 1, 'entregado', '2026-05-31 20:40:00'),
(118, 'Tabla Mixta',              320.00, 'Para Picar',     3, 1, 'entregado', '2026-06-14 20:10:00'),
(118, 'Aros de Calamar',          210.00, 'Entradas',       3, 1, 'entregado', '2026-06-14 20:10:00');

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

-- -------------------------------------------------------
-- RENDIMIENTO DE MESEROS (para /admin/feedback)
--
-- Enlaza tickets cerrados a los tres meseros activos (Carlos, Valeria y
-- Emilio) y siembra propina para que el % por mesero difiera:
--   Carlos  ~17%   ·  Valeria ~12%   ·  Emilio ~8%
-- La atencion sale del feedback ya sembrado (solo referencia tickets 1,2,3,5,8),
-- por eso cada mesero recibe al menos uno de esos tickets historicos ademas de
-- visitas de clientes recurrentes (101-118) que aportan mas datos de propina.
-- La propina se calcula como % del total real de cada ticket (SUM de items no
-- cancelados) via subconsulta correlacionada, para que sea autoconsistente.
-- -------------------------------------------------------

-- El id se resuelve por username: quitar un usuario de la semilla recorría los
-- ids implícitos y estos UPDATE quedaban apuntando al mesero equivocado.

-- Carlos Hernández — propinero alto (~17%)
UPDATE tickets t SET t.mesero_id = (SELECT id FROM usuarios WHERE username = 'mesero1'),
    t.propina = ROUND(COALESCE((SELECT SUM(precio * cantidad) FROM ticket_items
        WHERE ticket_id = t.id AND estado <> 'cancelado'), 0) * 0.17, 2)
WHERE t.id IN (1, 3, 101, 102, 103, 104, 105, 106);

-- Valeria Ríos — propina media (~12%)
UPDATE tickets t SET t.mesero_id = (SELECT id FROM usuarios WHERE username = 'mesero2'),
    t.propina = ROUND(COALESCE((SELECT SUM(precio * cantidad) FROM ticket_items
        WHERE ticket_id = t.id AND estado <> 'cancelado'), 0) * 0.12, 2)
WHERE t.id IN (2, 5, 107, 108, 109, 110, 111, 112);

-- Emilio Cárdenas — propina baja (~8%)
UPDATE tickets t SET t.mesero_id = (SELECT id FROM usuarios WHERE username = 'mesero3'),
    t.propina = ROUND(COALESCE((SELECT SUM(precio * cantidad) FROM ticket_items
        WHERE ticket_id = t.id AND estado <> 'cancelado'), 0) * 0.08, 2)
WHERE t.id IN (8, 113, 114, 115, 116, 117, 118);

-- Anuncio del restaurante, activo para poder ver el diálogo de la landing en QA.
-- Antes decía 'Test' e iba inactivo: no se veía nada y el mensaje de relleno
-- terminaba apareciendo en las capturas del panel.
INSERT INTO configuracion_anuncio (id, mensaje, tipo, activo, texto_enlace, url_enlace) VALUES
(1,
 'Este sábado tendremos música en vivo a partir de las 19:00 h. Te esperamos.',
 'evento',
 1,
 'Reservar mesa',
 '/reservaciones');

-- Ajustes del POS. Arranca con el mesero editable: es el comportamiento que
-- tenía el sistema antes de que existiera este ajuste, así que una instalación
-- nueva se comporta igual que las que ya estaban en operación.
INSERT INTO configuracion_pos (id, mesero_editable) VALUES (1, 1);

-- -------------------------------------------------------
-- ESCENARIOS DE RESERVACIONES: UNA SEMANA ALREDEDOR DE HOY
--
-- Las seis fechas se anclan a CURDATE() al cargar el dump, conservando entre
-- ellas los mismos saltos que tenían cuando eran literales de noviembre de
-- 2026. Fijas caducaban: cargado el dump unos meses después, los 32 escenarios
-- quedaban a tres meses vista y el módulo de reservaciones abría vacío.
--
-- La jornada principal cae a tres días vista —dentro de la ventana por defecto
-- del panel (hoy → +30) y con margen para que la "histórica" quede detrás—, y
-- el reloj reproducible es su mediodía.
--
-- Para mover el escenario entero basta cambiar el desplazamiento de
-- @fecha_principal: las otras cinco se derivan de ella.
-- No se siembran códigos OTP; las suites generan hashes efímeros.
-- -------------------------------------------------------

SET @fecha_principal = DATE_ADD(CURDATE(), INTERVAL 3 DAY);
SET @fecha_historica = DATE_SUB(@fecha_principal, INTERVAL 3 DAY);
SET @fecha_cerrada = DATE_SUB(@fecha_principal, INTERVAL 1 DAY);
SET @fecha_posterior = DATE_ADD(@fecha_principal, INTERVAL 1 DAY);
SET @fecha_especial = DATE_ADD(@fecha_principal, INTERVAL 2 DAY);
SET @fecha_futura = DATE_ADD(@fecha_principal, INTERVAL 3 DAY);
SET @reloj_prueba = CONCAT(@fecha_principal, ' 12:00:00');

INSERT INTO excepciones_operacion
  (fecha, tipo, motivo, hora_apertura, hora_cierre, activo)
VALUES
  (@fecha_cerrada, 'cerrado', 'Cierre de prueba', NULL, NULL, 1),
  (@fecha_especial, 'horario_especial', 'Horario especial de prueba',
   '14:00:00', '21:00:00', 1);

-- Una sola excepción relativa a la fecha de carga, dentro de la ventana de
-- siete días que la landing marca en la tabla de horario. Antes había seis
-- (a +1, +3, +5, +6, +12 y +20 días) y la semana entera salía llena de
-- avisos: para probar el caso basta con una.
--
-- ON DUPLICATE KEY porque excepciones_operacion.fecha es UNIQUE: si al sembrar
-- coincide con alguna de las fechas fijas de arriba, gana ésta en vez de
-- abortar la carga completa.
SET @excepcion_semana = DATE_ADD(CURDATE(), INTERVAL 2 DAY);

INSERT INTO excepciones_operacion
  (fecha, tipo, motivo, hora_apertura, hora_cierre, activo)
VALUES
  (@excepcion_semana, 'horario_especial', 'Comida privada, abrimos más tarde',
   '16:00:00', '23:00:00', 1)
ON DUPLICATE KEY UPDATE
  tipo = VALUES(tipo),
  motivo = VALUES(motivo),
  hora_apertura = VALUES(hora_apertura),
  hora_cierre = VALUES(hora_cierre),
  activo = VALUES(activo);

-- Cierra los tickets generales abiertos para aislar la jornada controlada.
UPDATE tickets
SET estado = 'cerrado',
    closed_at = COALESCE(closed_at, @reloj_prueba)
WHERE estado = 'abierto';

-- Límites por contacto: cero, una, cuatro y cinco activas.
-- limite.cero@example.test no requiere una fila.
INSERT INTO reservaciones
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado,
   request_token, estado_changed_at)
VALUES
  ('Límite Una', 'email', 'limite.una@example.test',
   @fecha_principal, '13:00:00', 2, 'Una activa', 'admin', 'confirmada',
   'fx-limite-una-0001', @reloj_prueba),

  ('Límite Cuatro 1', 'email', 'limite.cuatro@example.test',
   @fecha_principal, '14:30:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cuatro-01', @reloj_prueba),
  ('Límite Cuatro 2', 'email', 'limite.cuatro@example.test',
   @fecha_posterior, '15:00:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cuatro-02', @reloj_prueba),
  ('Límite Cuatro 3', 'email', 'limite.cuatro@example.test',
   @fecha_especial, '16:00:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cuatro-03', @reloj_prueba),
  ('Límite Cuatro 4', 'email', 'limite.cuatro@example.test',
   @fecha_futura, '17:00:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cuatro-04', @reloj_prueba),

  ('Límite Cinco 1', 'email', 'limite.cinco@example.test',
   @fecha_principal, '13:30:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cinco-01', @reloj_prueba),
  ('Límite Cinco 2', 'email', 'limite.cinco@example.test',
   @fecha_principal, '15:00:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cinco-02', @reloj_prueba),
  ('Límite Cinco 3', 'email', 'limite.cinco@example.test',
   @fecha_posterior, '16:30:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cinco-03', @reloj_prueba),
  ('Límite Cinco 4', 'email', 'limite.cinco@example.test',
   @fecha_especial, '18:00:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cinco-04', @reloj_prueba),
  ('Límite Cinco 5', 'email', 'limite.cinco@example.test',
   @fecha_futura, '19:30:00', 2, '', 'admin', 'confirmada',
   'fx-limite-cinco-05', @reloj_prueba),

  ('Identidad Teléfono', 'telefono', '+525544442026',
   @fecha_futura, '18:30:00', 3, 'Contacto canónico', 'landing', 'confirmada',
   'fx-contacto-tel-001', @reloj_prueba),
  ('Histórica', 'email', 'historial@example.test',
   @fecha_historica, '18:00:00', 2, '', 'admin', 'completada',
   'fx-historica-000001', CONCAT(@fecha_historica, ' 19:30:00'));

-- Retenciones, modificación, cancelación y falta de capacidad.
INSERT INTO reservaciones
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado,
   hold_expires_at, request_token, estado_changed_at)
VALUES
  ('Retención Vigente', 'email', 'hold.vigente@example.test',
   @fecha_principal, '17:30:00', 2, '', 'landing', 'pendiente_verificacion',
   CONCAT(@fecha_principal, ' 12:05:00'),
   'fx-hold-vigente-001', @reloj_prueba),
  ('Retención Vencida', 'email', 'hold.vencida@example.test',
   @fecha_principal, '18:00:00', 2, '', 'landing', 'pendiente_verificacion',
   CONCAT(@fecha_principal, ' 11:59:59'),
   'fx-hold-vencida-001', @reloj_prueba),
  ('Modificable', 'email', 'modificar@example.test',
   @fecha_principal, '18:30:00', 2, 'Mover a otra hora',
   'admin', 'confirmada', NULL, 'fx-modificable-0001', @reloj_prueba),
  ('Cancelable', 'email', 'cancelar@example.test',
   @fecha_principal, '19:00:00', 2, '',
   'admin', 'confirmada', NULL, 'fx-cancelable-0001', @reloj_prueba),
  ('Sin Capacidad', 'email', 'sin.capacidad@example.test',
   @fecha_posterior, '13:00:00', 2, 'Conservar al fallar modificación',
   'admin', 'confirmada', NULL, 'fx-sin-capacidad-01', @reloj_prueba),
  ('Bloqueo Total', 'email', 'bloqueo@example.test',
   @fecha_posterior, '20:00:00', 44, 'Ocupa todas las mesas',
   'admin', 'confirmada', NULL, 'fx-bloqueo-total-01', @reloj_prueba);

SET @hold_vigente = (SELECT id FROM reservaciones WHERE request_token = 'fx-hold-vigente-001');
SET @hold_vencida = (SELECT id FROM reservaciones WHERE request_token = 'fx-hold-vencida-001');
SET @modificable = (SELECT id FROM reservaciones WHERE request_token = 'fx-modificable-0001');
SET @cancelable = (SELECT id FROM reservaciones WHERE request_token = 'fx-cancelable-0001');
SET @sin_capacidad = (SELECT id FROM reservaciones WHERE request_token = 'fx-sin-capacidad-01');
SET @bloqueo_total = (SELECT id FROM reservaciones WHERE request_token = 'fx-bloqueo-total-01');

INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES
  (@hold_vigente, 1, 1),
  (@hold_vencida, 2, 1),
  (@modificable, 3, 1),
  (@cancelable, 4, 1),
  (@sin_capacidad, 1, 1),
  (@bloqueo_total, 1, 1), (@bloqueo_total, 2, 2),
  (@bloqueo_total, 3, 3), (@bloqueo_total, 4, 4),
  (@bloqueo_total, 5, 5), (@bloqueo_total, 6, 6),
  (@bloqueo_total, 7, 7), (@bloqueo_total, 8, 8),
  (@bloqueo_total, 9, 9), (@bloqueo_total, 10, 10),
  (@bloqueo_total, 11, 11);

-- Asignaciones de una, dos y tres mesas, más reservas consecutivas.
INSERT INTO reservaciones
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado,
   request_token, estado_changed_at)
VALUES
  ('Una Mesa', 'email', 'una.mesa@example.test',
   @fecha_principal, '13:00:00', 2, '', 'admin', 'confirmada',
   'fx-una-mesa-000001', @reloj_prueba),
  ('Dos Mesas', 'email', 'dos.mesas@example.test',
   @fecha_principal, '14:30:00', 6, '', 'admin', 'confirmada',
   'fx-dos-mesas-00001', @reloj_prueba),
  ('Tres Mesas', 'email', 'tres.mesas@example.test',
   @fecha_principal, '16:00:00', 10, '', 'admin', 'confirmada',
   'fx-tres-mesas-0001', @reloj_prueba),
  ('Cuatro Mesas Administrativa', 'email', 'cuatro.mesas@example.test',
   @fecha_futura, '20:00:00', 13, 'Supera el límite público', 'admin', 'confirmada',
   'fx-cuatro-mesas-001', @reloj_prueba),
  ('Consecutiva A', 'email', 'consecutiva@example.test',
   @fecha_futura, '13:00:00', 2, '', 'admin', 'confirmada',
   'fx-consecutiva-a-01', @reloj_prueba),
  ('Consecutiva B', 'email', 'consecutiva@example.test',
   @fecha_futura, '15:00:00', 2, '', 'admin', 'confirmada',
   'fx-consecutiva-b-01', @reloj_prueba);

SET @una_mesa = (SELECT id FROM reservaciones WHERE request_token = 'fx-una-mesa-000001');
SET @dos_mesas = (SELECT id FROM reservaciones WHERE request_token = 'fx-dos-mesas-00001');
SET @tres_mesas = (SELECT id FROM reservaciones WHERE request_token = 'fx-tres-mesas-0001');
SET @cuatro_mesas = (SELECT id FROM reservaciones WHERE request_token = 'fx-cuatro-mesas-001');
SET @consecutiva_a = (SELECT id FROM reservaciones WHERE request_token = 'fx-consecutiva-a-01');
SET @consecutiva_b = (SELECT id FROM reservaciones WHERE request_token = 'fx-consecutiva-b-01');

INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES
  (@una_mesa, 1, 1),
  (@dos_mesas, 5, 1), (@dos_mesas, 11, 2),
  (@tres_mesas, 8, 1), (@tres_mesas, 9, 2), (@tres_mesas, 10, 3),
  (@cuatro_mesas, 1, 1), (@cuatro_mesas, 2, 2),
  (@cuatro_mesas, 3, 3), (@cuatro_mesas, 4, 4),
  (@consecutiva_a, 2, 1), (@consecutiva_b, 2, 1);

-- Estados operativos: llegada, tolerancia, no-show, servicio y cierre.
INSERT INTO reservaciones
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado,
   request_token, estado_changed_at)
VALUES
  ('POS Confirmada', 'email', 'pos.confirmada@example.test',
   @fecha_principal, '19:30:00', 2, '', 'admin', 'confirmada',
   'fx-pos-confirmada-01', @reloj_prueba),
  ('POS Convertida', 'email', 'pos.convertida@example.test',
   @fecha_principal, '20:00:00', 2, '', 'admin', 'confirmada',
   'fx-pos-convertida-000001', CONCAT(@fecha_principal, ' 19:50:00')),
  ('POS En Curso', 'email', 'pos.encurso@example.test',
   @fecha_principal, '20:00:00', 6, '', 'admin', 'en_curso',
   'fx-pos-encurso-001', CONCAT(@fecha_principal, ' 20:00:00')),
  ('POS Completada', 'email', 'pos.completa@example.test',
   @fecha_historica, '18:00:00', 2, '', 'admin', 'completada',
   'fx-pos-completa-001', CONCAT(@fecha_historica, ' 19:30:00')),
  ('POS Tolerancia', 'email', 'pos.tolerancia@example.test',
   @fecha_principal, '20:30:00', 2, '', 'admin', 'confirmada',
   'fx-pos-tolerancia-1', @reloj_prueba),
  ('POS No Show', 'email', 'pos.noshow@example.test',
   @fecha_principal, '19:00:00', 2, '', 'admin', 'no_show',
   'fx-pos-noshow-0001', @reloj_prueba);

SET @pos_confirmada = (SELECT id FROM reservaciones WHERE request_token = 'fx-pos-confirmada-01');
SET @pos_convertida = (SELECT id FROM reservaciones WHERE request_token = 'fx-pos-convertida-000001');
SET @pos_en_curso = (SELECT id FROM reservaciones WHERE request_token = 'fx-pos-encurso-001');
SET @pos_completada = (SELECT id FROM reservaciones WHERE request_token = 'fx-pos-completa-001');
SET @pos_tolerancia = (SELECT id FROM reservaciones WHERE request_token = 'fx-pos-tolerancia-1');
SET @pos_noshow = (SELECT id FROM reservaciones WHERE request_token = 'fx-pos-noshow-0001');

INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES
  (@pos_confirmada, 3, 1),
  (@pos_convertida, 4, 1),
  (@pos_en_curso, 5, 1), (@pos_en_curso, 6, 2),
  (@pos_completada, 6, 1),
  (@pos_tolerancia, 7, 1),
  (@pos_noshow, 9, 1);

INSERT INTO tickets
  (comensales, nombre, hora_apertura, closed_at, estado, metodo_pago, reservacion_id)
VALUES
  (6, 'POS En Curso', CONCAT(@fecha_principal, ' 20:00:00'), NULL, 'abierto', NULL, @pos_en_curso),
  (2, 'POS Completada', CONCAT(@fecha_historica, ' 18:00:00'), CONCAT(@fecha_historica, ' 19:30:00'),
   'cerrado', 'efectivo', @pos_completada);

SET @ticket_en_curso = (SELECT id FROM tickets WHERE reservacion_id = @pos_en_curso);
SET @ticket_completado = (SELECT id FROM tickets WHERE reservacion_id = @pos_completada);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
  (@ticket_en_curso, 5, 1), (@ticket_en_curso, 6, 2),
  (@ticket_completado, 6, 1);

-- Walk-in de varias mesas y una reserva futura sobre la misma zona.
INSERT INTO tickets (comensales, nombre, hora_apertura, estado)
VALUES
  (2, 'Walk-in Una Mesa', CONCAT(@fecha_principal, ' 20:10:00'), 'abierto'),
  (6, 'Walk-in Varias Mesas', CONCAT(@fecha_principal, ' 20:15:00'), 'abierto');

SET @walkin_una = (
  SELECT id FROM tickets WHERE nombre = 'Walk-in Una Mesa' ORDER BY id DESC LIMIT 1
);
SET @walkin_varias = (
  SELECT id FROM tickets WHERE nombre = 'Walk-in Varias Mesas' ORDER BY id DESC LIMIT 1
);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
  (@walkin_una, 10, 1),
  (@walkin_varias, 1, 1), (@walkin_varias, 11, 2);

INSERT INTO reservaciones
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado,
   request_token, estado_changed_at)
VALUES
  ('Reserva Futura', 'email', 'pos.futura@example.test',
   @fecha_posterior, '13:00:00', 2, 'Advertencia de reserva próxima',
   'admin', 'confirmada', 'fx-pos-futura-00001', @reloj_prueba),
  ('Horario Afectado', 'email', 'horario@example.test',
   @fecha_principal, '21:00:00', 2, 'Conflicto al adelantar el cierre',
   'admin', 'confirmada', 'fx-horario-afectado', @reloj_prueba);

SET @reserva_futura = (SELECT id FROM reservaciones WHERE request_token = 'fx-pos-futura-00001');
SET @horario_afectado = (SELECT id FROM reservaciones WHERE request_token = 'fx-horario-afectado');

INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES
  (@reserva_futura, 10, 1),
  (@horario_afectado, 11, 1);

-- Mantiene vigentes los históricos independientes usados por analítica y
-- finanzas. Los tickets ligados a fixtures de reservaciones conservan sus
-- fechas exactas para no alterar esos escenarios.
UPDATE tickets
SET hora_apertura = TIMESTAMP(
      DATE_SUB(@HOY, INTERVAL (MOD(id, 58) + 1) DAY),
      TIME(hora_apertura)
    ),
    closed_at = DATE_ADD(
      TIMESTAMP(DATE_SUB(@HOY, INTERVAL (MOD(id, 58) + 1) DAY), TIME(hora_apertura)),
      INTERVAL 90 MINUTE
    ),
    hora_cierre = DATE_ADD(
      TIMESTAMP(DATE_SUB(@HOY, INTERVAL (MOD(id, 58) + 1) DAY), TIME(hora_apertura)),
      INTERVAL 90 MINUTE
    )
WHERE estado = 'cerrado'
  AND reservacion_id IS NULL;

-- -------------------------------------------------------
-- INVENTARIO / RECETAS (datos de prueba)
-- Las cantidades son por una unidad del producto; las subrecetas se explotan
-- hasta ingredientes al descontar inventario.
-- -------------------------------------------------------

INSERT INTO ingredientes (id, nombre, unidad, stock, stock_minimo, costo, activo) VALUES
(1, 'Café molido',          'g',  5000, 500,  0.3000, 1),
(2, 'Agua',                 'ml', 100000, 5000, 0.0001, 1),
(3, 'Leche',                'ml', 2100, 3000, 0.0250, 1),
(4, 'Chocolate en polvo',   'g',  250,  400,  0.2000, 1),
(5, 'Azúcar',               'g',  8000, 800,  0.0300, 1),
(6, 'Canela',               'g',  30,   50,   0.5000, 1),
(7, 'Piloncillo',           'g',  3000, 300,  0.0600, 1),
(8, 'Fruta de temporada',   'g',  420,  600,  0.0400, 1),
(9, 'Hielo',                'g',  20000, 1000, 0.0050, 1);

INSERT INTO gastos_fijos (nombre, categoria, monto, activo) VALUES
('Renta del local',      'renta',     45000.00, 1),
('Luz (CFE)',            'servicios',  8000.00, 1),
('Agua',                 'servicios',  2500.00, 1),
('Gas',                  'servicios',  4000.00, 1),
('Internet y teléfono',  'servicios',  1200.00, 1),
('Nómina',               'nomina',    60000.00, 1);

INSERT INTO subrecetas (id, nombre, unidad, rendimiento, activo) VALUES
(1, 'Shot de espresso', 'ml', 60, 1);

INSERT INTO subreceta_ingredientes (subreceta_id, ingrediente_id, cantidad) VALUES
(1, 1, 18),
(1, 2, 60);

-- Recetas principales por producto.
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'subreceta', 1, 60 FROM productos p WHERE p.nombre = 'Café Americano'
UNION ALL SELECT p.id, 'ingrediente', 2, 90 FROM productos p WHERE p.nombre = 'Café Americano';

INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'subreceta', 1, 60 FROM productos p WHERE p.nombre = 'Cappuccino'
UNION ALL SELECT p.id, 'ingrediente', 3, 120 FROM productos p WHERE p.nombre = 'Cappuccino';

INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 1, 15  FROM productos p WHERE p.nombre = 'Café de Olla'
UNION ALL SELECT p.id, 'ingrediente', 2, 200 FROM productos p WHERE p.nombre = 'Café de Olla'
UNION ALL SELECT p.id, 'ingrediente', 7, 25  FROM productos p WHERE p.nombre = 'Café de Olla'
UNION ALL SELECT p.id, 'ingrediente', 6, 2   FROM productos p WHERE p.nombre = 'Café de Olla';

INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 4, 30  FROM productos p WHERE p.nombre = 'Chocolate Caliente'
UNION ALL SELECT p.id, 'ingrediente', 3, 200 FROM productos p WHERE p.nombre = 'Chocolate Caliente'
UNION ALL SELECT p.id, 'ingrediente', 5, 10  FROM productos p WHERE p.nombre = 'Chocolate Caliente';

INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 8, 120 FROM productos p WHERE p.nombre = 'Agua Fresca'
UNION ALL SELECT p.id, 'ingrediente', 2, 250 FROM productos p WHERE p.nombre = 'Agua Fresca'
UNION ALL SELECT p.id, 'ingrediente', 5, 20  FROM productos p WHERE p.nombre = 'Agua Fresca'
UNION ALL SELECT p.id, 'ingrediente', 9, 100 FROM productos p WHERE p.nombre = 'Agua Fresca';

-- Mermas de ejemplo repartidas en el último mes, con su costo congelado, para
-- que el KPI de inventario y el renglón de Finanzas no salgan en cero en QA.
-- Las fechas son relativas a la carga: así el periodo de 7 y el de 30 días
-- muestran cifras distintas y se ve que el filtro funciona.
INSERT INTO movimientos_inventario
    (ingrediente_id, tipo, cantidad, motivo, nota, costo_unitario, created_at)
VALUES
(3, 'merma', -1200.000, 'caducidad',   'Se cortó el fin de semana largo', 0.0250, DATE_SUB(NOW(), INTERVAL 2 DAY)),
(8, 'merma',  -350.000, 'dano',        'Caja golpeada en la entrega',     0.0400, DATE_SUB(NOW(), INTERVAL 4 DAY)),
(1, 'merma',  -180.000, 'preparacion', 'Molienda mal calibrada',          0.3000, DATE_SUB(NOW(), INTERVAL 9 DAY)),
(4, 'merma',   -60.000, 'derrame',     NULL,                              0.2000, DATE_SUB(NOW(), INTERVAL 15 DAY)),
(7, 'merma',  -400.000, 'faltante',    'Diferencia contra el conteo físico', 0.0600, DATE_SUB(NOW(), INTERVAL 24 DAY));

-- -------------------------------------------------------
-- PROVEEDORES Y PRECIOS
-- -------------------------------------------------------

-- Tres proveedores para que la comparación de precios tenga algo que comparar:
-- el mismo insumo con dos costos distintos es justo el caso que motiva la tabla.
INSERT INTO proveedores (id, nombre, contacto, telefono, correo, notas, activo) VALUES
(1, 'Cafetalera del Sur',  'Rosa Mendez',  '5551234567', 'ventas@cafetaleradelsur.mx', 'Entrega martes y viernes. Pedido minimo 5 kg.', 1),
(2, 'Lacteos La Vaquita',  'Jorge Ibanez', '5559876543', 'pedidos@lavaquita.mx',       'Entrega diaria antes de las 8:00.',            1),
(3, 'Abarrotes El Puente', 'Luis Farias',  '5555550101', NULL,                          'Surte lo que falte, mas caro pero sin minimo.', 1);

-- El preferente es el que se propone al recibir mercancia. El Puente sale mas
-- caro en los dos insumos que comparte: es el proveedor de emergencia.
INSERT INTO ingrediente_proveedores (ingrediente_id, proveedor_id, costo, codigo, preferente) VALUES
(1, 1, 0.2800, 'CAF-MOL-1K', 1),
(1, 3, 0.3400, NULL,         0),
(3, 2, 0.0230, 'LEC-ENT-1L', 1),
(3, 3, 0.0290, NULL,         0),
(5, 3, 0.0300, 'AZU-EST-1K', 1);

-- Historico de precios. Fechas relativas a la carga, como el resto del archivo,
-- para que la ficha muestre una serie con recorrido en vez de un solo punto.
--
-- El autor se resuelve por username y no por un id fijo: usuarios se siembra
-- por auto_increment mas arriba, asi que el 1 depende del orden de este mismo
-- archivo y se rompe en cuanto alguien inserte otra fila antes.
SET @admin_demo = (SELECT id FROM usuarios WHERE username = 'admin_demo');

INSERT INTO historial_precios
    (entidad, ref_id, precio_anterior, precio_nuevo, motivo, proveedor_id, usuario_id, created_at)
VALUES
('ingrediente', 1, NULL,   0.2500, 'alta',      NULL, @admin_demo, DATE_SUB(NOW(), INTERVAL 120 DAY)),
('ingrediente', 1, 0.2500, 0.2800, 'proveedor', 1,    @admin_demo, DATE_SUB(NOW(), INTERVAL 60 DAY)),
('ingrediente', 1, 0.2800, 0.3000, 'edicion',   NULL, @admin_demo, DATE_SUB(NOW(), INTERVAL 14 DAY)),
('ingrediente', 3, NULL,   0.0200, 'alta',      NULL, @admin_demo, DATE_SUB(NOW(), INTERVAL 120 DAY)),
('ingrediente', 3, 0.0200, 0.0250, 'edicion',   NULL, @admin_demo, DATE_SUB(NOW(), INTERVAL 30 DAY)),
('producto',    1, NULL,   45.00,  'alta',      NULL, @admin_demo, DATE_SUB(NOW(), INTERVAL 120 DAY)),
('producto',    1, 45.00,  50.00,  'edicion',   NULL, @admin_demo, DATE_SUB(NOW(), INTERVAL 45 DAY));

-- -------------------------------------------------------
-- Catas
-- -------------------------------------------------------
--
-- Fechas relativas a la carga, como el resto del archivo, para que la agenda
-- pública siempre tenga futuro. Cubre los tres casos que hay que poder ver en
-- pantalla: dos catas disponibles —las que salen en la landing—, una apagada
-- (llena o sin confirmar, que es lo mismo desde fuera: no se publica) y una
-- recién programada que todavía nadie ha encendido.
--
-- Catering ya no siembra nada: su bandeja se retiró con la tabla y todo el
-- flujo pasa por WhatsApp.

INSERT INTO catas (titulo, descripcion, fecha, hora, duracion_min, precio, disponible) VALUES
('Tintos de Guanajuato',
 'Cinco etiquetas de la región con maridaje de quesos curados y pan de masa madre de la casa.',
 DATE_ADD(CURDATE(), INTERVAL 12 DAY), '19:30:00', 90, 850.00, 1),
('Espumosos y postres',
 'Cierre dulce: tres espumosos contra la carta de postres del chef.',
 DATE_ADD(CURDATE(), INTERVAL 40 DAY), '19:00:00', 75, 780.00, 1),
('Blancos y mariscos',
 'Recorrido por blancos frescos del Valle de Guadalupe junto a nuestra barra de mariscos.',
 DATE_ADD(CURDATE(), INTERVAL 26 DAY), '20:00:00', 120, 990.00, 0),
('Cata de aceites de oliva',
 'En preparación: aún sin fecha confirmada con el productor.',
 DATE_ADD(CURDATE(), INTERVAL 60 DAY), '18:30:00', 60, 640.00, 0);


-- Fin de dml.sql


-- ═════════════════════════════════════════════════════════════════════
-- BLOQUE 2 · Fixture de analíticas   (antes database/analiticas-datos-ex.sql)
--
-- Siembra además los ingredientes y las recetas que comparte con el bloque 3.
-- ═════════════════════════════════════════════════════════════════════

-- =====================================================================
-- Casa Pestalozzi — Datos de ejemplo del panel de Analíticas
-- (ver database/ANALITICAS.md). SE CARGA DESPUÉS de dml.sql.
--
-- Este archivo concentra TODO el seed que existe para que el panel
-- /admin/analytics tenga algo que graficar. Antes estaba repartido entre
-- dml.sql y analiticas.sql, así que para entender de dónde salía una barra
-- había que leer los dos y ninguno se podía recargar por separado.
--
-- Qué alimenta cada bloque:
--
--   Gráficas descriptivas (AdminController::construirAnalytics)
--     · Ventas diarias, ingreso por familia, métodos de pago, productos más
--       vendidos, ticket promedio, propinas y tabla de tickets
--                                          ← tickets 200-299 + sus ticket_items
--     · Reservaciones por día y por estado ← reservaciones 'fx-analytics-res-%'
--
--   Analíticas diagnósticas (Services\Analiticas, ANALITICAS.md §3)
--     · §3.1 Ingeniería de menú   ← ingredientes 10-99 + recetas de comida,
--                                   que son los que dan margen real por platillo
--     · §3.2 RevPASH              ← tickets 200-299 repartidos por franja y día
--     · §3.4 Reglas de asociación ← pares recurrentes dentro de esos tickets
--
-- Lo que NO está aquí y el panel también usa: el catálogo base (productos,
-- categorías, mesas, usuarios), los ingredientes 1-9 con sus recetas de bebida
-- y los tickets del POS. Todo eso lo siembra dml.sql porque el sistema no
-- arranca sin ello — la analítica solo los lee de pasada.
--
-- Es idempotente: limpia sus propios rangos antes de insertar, así que puede
-- recargarse cuantas veces haga falta sin duplicar nada.
--
-- Rango temporal: las ventas se reanclan al final del archivo para que la más
-- reciente caiga SIEMPRE en la fecha de hoy; así el seed queda dentro del
-- filtro por defecto del panel ("últimos 30 días") sin importar cuándo se
-- cargue. Las reservaciones se siembran relativas a @HOY por el mismo motivo.
-- =====================================================================

-- El cliente `mysql` negocia latin1 por defecto en esta instalación, y con eso
-- cada acento de este archivo entra doblemente codificado ('Salmón' se guarda
-- como 'SalmÃ³n'). No basta con que la tabla sea utf8mb4: hay que declarar el
-- juego de la CONEXIÓN, y el archivo debe poder cargarse sin recordar la
-- bandera --default-character-set en la línea de comandos.
SET NAMES utf8mb4;

-- El contenedor de MySQL corre en UTC, pero la app abre su sesión en GMT-6
-- (ver includes/database.php). Se replica aquí para que: (a) las columnas
-- TIMESTAMP (ticket_items.created_at) se guarden con la misma referencia con
-- la que luego se leen, y (b) CURDATE() —usado para anclar las fechas— sea la
-- fecha local del restaurante, no la del reloj UTC.
SET time_zone = '-06:00';

-- Ancla de las reservaciones. dml.sql define su propio @HOY, pero este archivo
-- tiene que poder cargarse solo.
SET @HOY := CURDATE();

-- ---------------------------------------------------------------------
-- Limpieza idempotente de los datos que crea ESTE archivo.
-- ---------------------------------------------------------------------
DELETE FROM ticket_items WHERE ticket_id BETWEEN 200 AND 299;
DELETE FROM ticket_mesas WHERE ticket_id BETWEEN 200 AND 299;
DELETE FROM tickets      WHERE id        BETWEEN 200 AND 299;

-- Reservaciones del panel. Se borran por token porque no llevan id fijo:
-- 'fx-analytics-res-%' es el prefijo que las distingue de los fixtures del
-- módulo de reservas, que viven en fechas fijas y los siembra dml.sql.
DELETE FROM reservacion_mesas
 WHERE reservacion_id IN (
   SELECT id FROM reservaciones WHERE request_token LIKE 'fx-analytics-res-%'
 );
DELETE FROM reservaciones WHERE request_token LIKE 'fx-analytics-res-%';

-- Ajustes de merma que sembraban la §3.3 (varianza de inventario). Esa
-- analítica se retiró del panel; el DELETE se conserva para que al recargar
-- este archivo se purguen los registros que dejó la versión anterior.
DELETE FROM movimientos_inventario WHERE id BETWEEN 900 AND 999;
DELETE FROM producto_componentes
 WHERE producto_id IN (SELECT id FROM productos WHERE nombre IN (
   'Chilaquiles','Enmoladas','Enchiladas Suizas','Molletes',
   'Toast de Salmón Ahumado','Hamburguesa de la Casa',
   'Papas a la Francesa con Parmesano','Spaguetti a la Boloñesa',
   'Mix de 3 Brusquetas','Frutos Rojos','Crema del Día','Milano',
   'Margarita','Burrata','Rib Eye (450 grs.)','Salmón al Horno',
   'Tabla Mixta','Filete de Res en su Jugo','Aros de Calamar'));
DELETE FROM ingredientes WHERE id BETWEEN 10 AND 99;

-- ---------------------------------------------------------------------
-- Horario de operación semanal (denominador del RevPASH).
--
-- Este archivo NO siembra 'horarios_operacion'. El horario del negocio lo
-- define dml.sql y es el mismo que usa el flujo público de reservaciones;
-- tener dos semillas compitiendo por la misma tabla hacía que el orden de
-- carga decidiera el horario del restaurante. Antes se insertaba aquí un
-- horario propio (lunes cerrado, cierre a las 23:00) con ON DUPLICATE KEY,
-- que pisaba al de dml.sql y marcaba como "fuera de horario" ventas de lunes
-- perfectamente válidas.
--
-- El RevPASH lee lo que haya en la tabla y se adapta: ver
-- Analiticas::diasOperadosPorDiaHora.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- §3.1 ·Ingredientes de cocina (ids 10+; los 1-9 los pone dml.sql).
-- 'costo' es por unidad (g / ml / pza). Dan costo de receta real a la comida,
-- para que la matriz de ingeniería de menú tenga un margen que discrimine.
-- ---------------------------------------------------------------------
INSERT INTO ingredientes (id, nombre, unidad, stock, stock_minimo, costo, activo) VALUES
(10, 'Tortilla de maíz',      'pza', 2000,  300,  0.8000, 1),
(11, 'Pollo deshebrado',      'g',   20000, 3000, 0.1800, 1),
(12, 'Queso gouda',           'g',   8000,  1500, 0.3500, 1),
(13, 'Crema',                 'ml',  10000, 2000, 0.0600, 1),
(14, 'Salsa verde',           'ml',  8000,  1500, 0.0500, 1),
(15, 'Rib eye',               'g',   15000, 3000, 0.6500, 1),
(16, 'Carne molida de res',   'g',   12000, 2500, 0.2800, 1),
(17, 'Pan de hamburguesa',    'pza', 300,   50,   6.0000, 1),
(18, 'Papa',                  'g',   30000, 5000, 0.0300, 1),
(19, 'Queso parmesano',       'g',   4000,  800,  0.4500, 1),
(20, 'Salmón ahumado',        'g',   3000,  600,  0.9000, 1),
(21, 'Pan brioche',           'pza', 200,   40,   8.0000, 1),
(22, 'Aguacate',              'g',   6000,  1000, 0.0800, 1),
(23, 'Masa de pizza',         'pza', 250,   50,   12.0000, 1),
(24, 'Salsa de tomate',       'ml',  9000,  1500, 0.0400, 1),
(25, 'Queso mozzarella',      'g',   10000, 2000, 0.3000, 1),
(26, 'Mole negro',            'ml',  6000,  1000, 0.1200, 1),
(27, 'Frijol refrito',        'g',   15000, 2500, 0.0200, 1),
(28, 'Pasta seca',            'g',   12000, 2000, 0.0500, 1),
(29, 'Salsa boloñesa',        'ml',  8000,  1500, 0.0900, 1),
(30, 'Frutos rojos',          'g',   4000,  800,  0.1500, 1),
(31, 'Mezcla de lechugas',    'g',   5000,  1000, 0.1000, 1),
(32, 'Salmón fresco',         'g',   4000,  800,  0.8500, 1),
(33, 'Calamar',               'g',   4000,  800,  0.5500, 1),
(34, 'Filete de res',         'g',   12000, 2500, 0.5500, 1);

-- ---------------------------------------------------------------------
-- §3.1 ·Recetas principales de comida (enlace por nombre de platillo,
-- igual que dml.sql). Cantidad por 1 unidad del producto.
-- ---------------------------------------------------------------------
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 10, 10 FROM productos p WHERE p.nombre = 'Chilaquiles'
UNION ALL SELECT p.id, 'ingrediente', 11, 50 FROM productos p WHERE p.nombre = 'Chilaquiles'
UNION ALL SELECT p.id, 'ingrediente', 14, 80 FROM productos p WHERE p.nombre = 'Chilaquiles'
UNION ALL SELECT p.id, 'ingrediente', 13, 40 FROM productos p WHERE p.nombre = 'Chilaquiles'
UNION ALL SELECT p.id, 'ingrediente', 12, 40 FROM productos p WHERE p.nombre = 'Chilaquiles';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 10, 6 FROM productos p WHERE p.nombre = 'Enmoladas'
UNION ALL SELECT p.id, 'ingrediente', 11, 70 FROM productos p WHERE p.nombre = 'Enmoladas'
UNION ALL SELECT p.id, 'ingrediente', 26, 120 FROM productos p WHERE p.nombre = 'Enmoladas'
UNION ALL SELECT p.id, 'ingrediente', 13, 30 FROM productos p WHERE p.nombre = 'Enmoladas'
UNION ALL SELECT p.id, 'ingrediente', 12, 25 FROM productos p WHERE p.nombre = 'Enmoladas';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 10, 6 FROM productos p WHERE p.nombre = 'Enchiladas Suizas'
UNION ALL SELECT p.id, 'ingrediente', 11, 70 FROM productos p WHERE p.nombre = 'Enchiladas Suizas'
UNION ALL SELECT p.id, 'ingrediente', 14, 100 FROM productos p WHERE p.nombre = 'Enchiladas Suizas'
UNION ALL SELECT p.id, 'ingrediente', 12, 60 FROM productos p WHERE p.nombre = 'Enchiladas Suizas'
UNION ALL SELECT p.id, 'ingrediente', 13, 40 FROM productos p WHERE p.nombre = 'Enchiladas Suizas';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 27, 100 FROM productos p WHERE p.nombre = 'Molletes'
UNION ALL SELECT p.id, 'ingrediente', 12, 60 FROM productos p WHERE p.nombre = 'Molletes';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 21, 1 FROM productos p WHERE p.nombre = 'Toast de Salmón Ahumado'
UNION ALL SELECT p.id, 'ingrediente', 20, 70 FROM productos p WHERE p.nombre = 'Toast de Salmón Ahumado'
UNION ALL SELECT p.id, 'ingrediente', 22, 40 FROM productos p WHERE p.nombre = 'Toast de Salmón Ahumado'
UNION ALL SELECT p.id, 'ingrediente', 13, 20 FROM productos p WHERE p.nombre = 'Toast de Salmón Ahumado';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 16, 180 FROM productos p WHERE p.nombre = 'Hamburguesa de la Casa'
UNION ALL SELECT p.id, 'ingrediente', 17, 1 FROM productos p WHERE p.nombre = 'Hamburguesa de la Casa'
UNION ALL SELECT p.id, 'ingrediente', 12, 30 FROM productos p WHERE p.nombre = 'Hamburguesa de la Casa'
UNION ALL SELECT p.id, 'ingrediente', 18, 120 FROM productos p WHERE p.nombre = 'Hamburguesa de la Casa';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 18, 250 FROM productos p WHERE p.nombre = 'Papas a la Francesa con Parmesano'
UNION ALL SELECT p.id, 'ingrediente', 19, 30 FROM productos p WHERE p.nombre = 'Papas a la Francesa con Parmesano';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 28, 150 FROM productos p WHERE p.nombre = 'Spaguetti a la Boloñesa'
UNION ALL SELECT p.id, 'ingrediente', 29, 200 FROM productos p WHERE p.nombre = 'Spaguetti a la Boloñesa'
UNION ALL SELECT p.id, 'ingrediente', 19, 30 FROM productos p WHERE p.nombre = 'Spaguetti a la Boloñesa';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 21, 1 FROM productos p WHERE p.nombre = 'Mix de 3 Brusquetas'
UNION ALL SELECT p.id, 'ingrediente', 19, 20 FROM productos p WHERE p.nombre = 'Mix de 3 Brusquetas'
UNION ALL SELECT p.id, 'ingrediente', 22, 30 FROM productos p WHERE p.nombre = 'Mix de 3 Brusquetas';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 30, 120 FROM productos p WHERE p.nombre = 'Frutos Rojos'
UNION ALL SELECT p.id, 'ingrediente', 31, 80 FROM productos p WHERE p.nombre = 'Frutos Rojos'
UNION ALL SELECT p.id, 'ingrediente', 19, 20 FROM productos p WHERE p.nombre = 'Frutos Rojos';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 13, 150 FROM productos p WHERE p.nombre = 'Crema del Día'
UNION ALL SELECT p.id, 'ingrediente', 18, 60 FROM productos p WHERE p.nombre = 'Crema del Día';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 23, 1 FROM productos p WHERE p.nombre = 'Milano'
UNION ALL SELECT p.id, 'ingrediente', 24, 80 FROM productos p WHERE p.nombre = 'Milano'
UNION ALL SELECT p.id, 'ingrediente', 25, 120 FROM productos p WHERE p.nombre = 'Milano';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 23, 1 FROM productos p WHERE p.nombre = 'Margarita'
UNION ALL SELECT p.id, 'ingrediente', 24, 80 FROM productos p WHERE p.nombre = 'Margarita'
UNION ALL SELECT p.id, 'ingrediente', 25, 100 FROM productos p WHERE p.nombre = 'Margarita';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 23, 1 FROM productos p WHERE p.nombre = 'Burrata'
UNION ALL SELECT p.id, 'ingrediente', 24, 70 FROM productos p WHERE p.nombre = 'Burrata'
UNION ALL SELECT p.id, 'ingrediente', 25, 130 FROM productos p WHERE p.nombre = 'Burrata';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 15, 450 FROM productos p WHERE p.nombre = 'Rib Eye (450 grs.)'
UNION ALL SELECT p.id, 'ingrediente', 18, 150 FROM productos p WHERE p.nombre = 'Rib Eye (450 grs.)';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 32, 200 FROM productos p WHERE p.nombre = 'Salmón al Horno'
UNION ALL SELECT p.id, 'ingrediente', 22, 40 FROM productos p WHERE p.nombre = 'Salmón al Horno';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 12, 100 FROM productos p WHERE p.nombre = 'Tabla Mixta'
UNION ALL SELECT p.id, 'ingrediente', 19, 50 FROM productos p WHERE p.nombre = 'Tabla Mixta'
UNION ALL SELECT p.id, 'ingrediente', 22, 30 FROM productos p WHERE p.nombre = 'Tabla Mixta';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 34, 250 FROM productos p WHERE p.nombre = 'Filete de Res en su Jugo'
UNION ALL SELECT p.id, 'ingrediente', 18, 100 FROM productos p WHERE p.nombre = 'Filete de Res en su Jugo';
INSERT INTO producto_componentes (producto_id, tipo, ref_id, cantidad)
SELECT p.id, 'ingrediente', 33, 150 FROM productos p WHERE p.nombre = 'Aros de Calamar';

-- ---------------------------------------------------------------------
-- §3.2 / §3.4 · Tickets cerrados recientes (ids 200+). Pares recurrentes
-- por servicio para que el lift encuentre afinidades reales, repartidos
-- por franja horaria (desayuno / comida / cena) para el RevPASH.
-- ---------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, estado, metodo_pago, propina, mesero_id) VALUES
(200, 2, 'Ana Ruiz', '2026-06-30 09:15:00', 'cerrado', 'tarjeta', 59, 3),
(201, 3, 'León Vega', '2026-07-02 09:40:00', 'cerrado', 'efectivo', 52, 6),
(202, 2, 'Bruno Salas', '2026-07-05 10:05:00', 'cerrado', 'tarjeta', 32, 3),
(203, 4, 'Familia Nava', '2026-07-09 08:50:00', 'cerrado', 'tarjeta', 92, 6),
(204, 2, 'Ivy Cano', '2026-07-14 09:30:00', 'cerrado', 'efectivo', 0, 3),
(205, 2, 'Sol Marín', '2026-07-21 10:10:00', 'cerrado', 'tarjeta', 51, 6),
(206, 2, 'Rita Peña', '2026-07-01 09:00:00', 'cerrado', 'tarjeta', 87, 3),
(207, 3, 'Tono Gil', '2026-07-07 09:20:00', 'cerrado', 'efectivo', 40, 6),
(208, 2, 'Lía Ordaz', '2026-07-15 10:00:00', 'cerrado', 'tarjeta', 69, 3),
(209, 2, 'Gael Ruan', '2026-07-22 09:45:00', 'cerrado', 'tarjeta', 47, 6),
(210, 2, 'Vera Luna', '2026-07-03 10:30:00', 'cerrado', 'tarjeta', 92, 3),
(211, 2, 'Pau Rivas', '2026-07-08 09:10:00', 'cerrado', 'tarjeta', 37, 6),
(212, 3, 'Cris Mora', '2026-07-16 10:20:00', 'cerrado', 'efectivo', 48, 3),
(213, 2, 'Noa Cid', '2026-07-24 09:35:00', 'cerrado', 'tarjeta', 70, 6),
(214, 2, 'Dani Frey', '2026-07-04 09:25:00', 'cerrado', 'tarjeta', 68, 3),
(215, 3, 'Uri Mena', '2026-07-11 10:15:00', 'cerrado', 'efectivo', 0, 6),
(216, 2, 'Bea Toro', '2026-07-23 09:50:00', 'cerrado', 'tarjeta', 37, 3),
(217, 4, 'Grupo Lara', '2026-06-30 14:10:00', 'cerrado', 'tarjeta', 109, 4),
(218, 3, 'Toña Vela', '2026-07-03 13:40:00', 'cerrado', 'efectivo', 42, 3),
(219, 4, 'Nico Paz', '2026-07-10 15:00:00', 'cerrado', 'tarjeta', 100, 4),
(220, 2, 'Ele Sáenz', '2026-07-17 14:30:00', 'cerrado', 'tarjeta', 59, 3),
(221, 3, 'Rux Dávila', '2026-07-25 13:20:00', 'cerrado', 'tarjeta', 109, 4),
(222, 3, 'Beto Nájera', '2026-07-02 14:00:00', 'cerrado', 'tarjeta', 86, 3),
(223, 2, 'Kena Ríos', '2026-07-13 15:10:00', 'cerrado', 'efectivo', 44, 4),
(224, 4, 'Grupo Sáez', '2026-07-23 13:50:00', 'cerrado', 'tarjeta', 134, 3),
(225, 2, 'Mar Cueto', '2026-07-07 15:20:00', 'cerrado', 'tarjeta', 72, 4),
(226, 2, 'Alma Vidal', '2026-07-15 14:40:00', 'cerrado', 'efectivo', 0, 3),
(227, 3, 'Ori Lozano', '2026-07-26 13:30:00', 'cerrado', 'tarjeta', 101, 4),
(228, 3, 'Fito Reyna', '2026-07-05 14:20:00', 'cerrado', 'tarjeta', 54, 3),
(229, 4, 'Grupo Ibarra', '2026-07-18 15:30:00', 'cerrado', 'tarjeta', 92, 4),
(230, 2, 'Sam Peña', '2026-07-27 14:05:00', 'cerrado', 'efectivo', 45, 3),
(231, 4, 'Grupo Cano', '2026-07-01 20:15:00', 'cerrado', 'tarjeta', 287, 4),
(232, 2, 'Vic Aranda', '2026-07-11 21:00:00', 'cerrado', 'tarjeta', 127, 6),
(233, 3, 'Lalo Bravo', '2026-07-21 20:40:00', 'cerrado', 'tarjeta', 166, 4),
(234, 2, 'Nube Ríos', '2026-07-04 21:20:00', 'cerrado', 'tarjeta', 85, 6),
(235, 2, 'Tavo Cruz', '2026-07-14 20:10:00', 'cerrado', 'efectivo', 36, 4),
(236, 3, 'Yara Sol', '2026-07-24 21:15:00', 'cerrado', 'tarjeta', 85, 6),
(237, 4, 'Grupo Villa', '2026-07-08 20:30:00', 'cerrado', 'tarjeta', 118, 4),
(238, 3, 'Mina Fox', '2026-07-19 21:10:00', 'cerrado', 'tarjeta', 70, 6),
(239, 4, 'Grupo Elías', '2026-07-26 20:50:00', 'cerrado', 'tarjeta', 117, 4),
(240, 3, 'Ceci Robles', '2026-07-06 21:05:00', 'cerrado', 'tarjeta', 111, 6),
(241, 2, 'Gus Prado', '2026-07-16 20:20:00', 'cerrado', 'efectivo', 53, 4),
(242, 3, 'Rey Molina', '2026-07-27 21:30:00', 'cerrado', 'tarjeta', 148, 6);

-- Mesa de cada ticket. El esquema nuevo no guarda mesa_id en 'tickets':
-- la relacion vive en ticket_mesas (orden 1 = mesa principal). De aqui sale
-- la columna Mesa de la tabla de actividad reciente del panel.
INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(200, 1, 1),
(201, 3, 1),
(202, 2, 1),
(203, 4, 1),
(204, 5, 1),
(205, 6, 1),
(206, 7, 1),
(207, 8, 1),
(208, 9, 1),
(209, 10, 1),
(210, 1, 1),
(211, 2, 1),
(212, 3, 1),
(213, 4, 1),
(214, 5, 1),
(215, 6, 1),
(216, 7, 1),
(217, 3, 1),
(218, 6, 1),
(219, 2, 1),
(220, 5, 1),
(221, 1, 1),
(222, 4, 1),
(223, 2, 1),
(224, 6, 1),
(225, 1, 1),
(226, 5, 1),
(227, 3, 1),
(228, 2, 1),
(229, 4, 1),
(230, 6, 1),
(231, 3, 1),
(232, 6, 1),
(233, 2, 1),
(234, 5, 1),
(235, 1, 1),
(236, 4, 1),
(237, 6, 1),
(238, 2, 1),
(239, 3, 1),
(240, 4, 1),
(241, 5, 1),
(242, 1, 1);


INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(200, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', '2026-06-30 09:20:00'),
(200, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', '2026-06-30 09:20:00'),
(201, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', '2026-07-02 09:45:00'),
(201, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-02 09:45:00'),
(201, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', '2026-07-02 09:45:00'),
(202, 'Chilaquiles', 180.00, 'Desayunos', 3, 1, 'entregado', '2026-07-05 10:10:00'),
(202, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-05 10:10:00'),
(203, 'Chilaquiles', 180.00, 'Desayunos', 3, 3, 'entregado', '2026-07-09 08:55:00'),
(203, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', '2026-07-09 08:55:00'),
(203, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', '2026-07-09 08:55:00'),
(204, 'Chilaquiles', 180.00, 'Desayunos', 3, 1, 'entregado', '2026-07-14 09:35:00'),
(204, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-14 09:35:00'),
(205, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', '2026-07-21 10:15:00'),
(205, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-21 10:15:00'),
(206, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', '2026-07-01 09:05:00'),
(206, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 2, 'entregado', '2026-07-01 09:05:00'),
(207, 'Enmoladas', 240.00, 'Desayunos', 3, 1, 'entregado', '2026-07-07 09:25:00'),
(207, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', '2026-07-07 09:25:00'),
(207, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-07 09:25:00'),
(208, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', '2026-07-15 10:05:00'),
(208, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', '2026-07-15 10:05:00'),
(209, 'Enmoladas', 240.00, 'Desayunos', 3, 1, 'entregado', '2026-07-22 09:50:00'),
(209, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', '2026-07-22 09:50:00'),
(210, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 2, 'entregado', '2026-07-03 10:35:00'),
(210, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', '2026-07-03 10:35:00'),
(211, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 1, 'entregado', '2026-07-08 09:15:00'),
(211, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-08 09:15:00'),
(212, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 1, 'entregado', '2026-07-16 10:25:00'),
(212, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', '2026-07-16 10:25:00'),
(212, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', '2026-07-16 10:25:00'),
(213, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 2, 'entregado', '2026-07-24 09:40:00'),
(213, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-24 09:40:00'),
(214, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', '2026-07-04 09:30:00'),
(214, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', '2026-07-04 09:30:00'),
(215, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 1, 'entregado', '2026-07-11 10:20:00'),
(215, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-11 10:20:00'),
(216, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 1, 'entregado', '2026-07-23 09:55:00'),
(216, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-23 09:55:00'),
(217, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-06-30 14:15:00'),
(217, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', '2026-06-30 14:15:00'),
(218, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-07-03 13:45:00'),
(218, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-07-03 13:45:00'),
(219, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-07-10 15:05:00'),
(219, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-07-10 15:05:00'),
(219, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', '2026-07-10 15:05:00'),
(220, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-07-17 14:35:00'),
(220, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-07-17 14:35:00'),
(221, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-07-25 13:25:00'),
(221, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', '2026-07-25 13:25:00'),
(222, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', '2026-07-02 14:05:00'),
(222, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-07-02 14:05:00'),
(223, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 1, 'entregado', '2026-07-13 15:15:00'),
(223, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', '2026-07-13 15:15:00'),
(224, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', '2026-07-23 13:55:00'),
(224, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', '2026-07-23 13:55:00'),
(224, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', '2026-07-23 13:55:00'),
(225, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', '2026-07-07 15:25:00'),
(225, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', '2026-07-07 15:25:00'),
(226, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 1, 'entregado', '2026-07-15 14:45:00'),
(226, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', '2026-07-15 14:45:00'),
(227, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', '2026-07-26 13:35:00'),
(227, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', '2026-07-26 13:35:00'),
(228, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', '2026-07-05 14:25:00'),
(228, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', '2026-07-05 14:25:00'),
(229, 'Milano', 260.00, 'Pizzas', 4, 2, 'entregado', '2026-07-18 15:35:00'),
(229, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', '2026-07-18 15:35:00'),
(230, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', '2026-07-27 14:10:00'),
(230, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', '2026-07-27 14:10:00'),
(231, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-07-01 20:20:00'),
(231, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', '2026-07-01 20:20:00'),
(231, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', '2026-07-01 20:20:00'),
(232, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-07-11 21:05:00'),
(232, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', '2026-07-11 21:05:00'),
(233, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-07-21 20:45:00'),
(233, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', '2026-07-21 20:45:00'),
(233, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', '2026-07-21 20:45:00'),
(234, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-07-04 21:25:00'),
(234, 'Agua Fresca', 60.00, 'Café & Bebidas', 1, 2, 'entregado', '2026-07-04 21:25:00'),
(235, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-07-14 20:15:00'),
(235, 'Agua Fresca', 60.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-14 20:15:00'),
(236, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-07-24 21:20:00'),
(236, 'Agua Fresca', 60.00, 'Café & Bebidas', 1, 1, 'entregado', '2026-07-24 21:20:00'),
(237, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', '2026-07-08 20:35:00'),
(237, 'Burrata', 260.00, 'Pizzas', 4, 2, 'entregado', '2026-07-08 20:35:00'),
(238, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', '2026-07-19 21:15:00'),
(238, 'Burrata', 260.00, 'Pizzas', 4, 1, 'entregado', '2026-07-19 21:15:00'),
(239, 'Tabla Mixta', 320.00, 'Para Picar', 3, 2, 'entregado', '2026-07-26 20:55:00'),
(239, 'Burrata', 260.00, 'Pizzas', 4, 1, 'entregado', '2026-07-26 20:55:00'),
(240, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-07-06 21:10:00'),
(240, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', '2026-07-06 21:10:00'),
(241, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 1, 'entregado', '2026-07-16 20:25:00'),
(241, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', '2026-07-16 20:25:00'),
(242, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 2, 'entregado', '2026-07-27 21:35:00'),
(242, 'Aros de Calamar', 210.00, 'Entradas', 3, 2, 'entregado', '2026-07-27 21:35:00');

-- ---------------------------------------------------------------------
-- Anclaje temporal: las fechas de arriba son literales para que el archivo
-- sea legible y diffeable, pero el panel filtra por "últimos 30 días". Aquí
-- se desplaza todo el bloque en bloque para que el ticket más reciente caiga
-- HOY, conservando intactos los intervalos entre ventas (y por tanto el
-- reparto por franja horaria del RevPASH y los pares del lift).
-- Se recalcula en cada recarga, así que el seed nunca "envejece".
-- ---------------------------------------------------------------------
SET @shift := (
  SELECT DATEDIFF(CURDATE(), MAX(DATE(hora_apertura)))
    FROM tickets WHERE id BETWEEN 200 AND 299
);

UPDATE tickets
   SET hora_apertura = hora_apertura + INTERVAL @shift DAY
 WHERE id BETWEEN 200 AND 299 AND @shift <> 0;

UPDATE ticket_items
   SET created_at = created_at + INTERVAL @shift DAY
 WHERE ticket_id BETWEEN 200 AND 299 AND @shift <> 0;

-- El panel atribuye la venta al dia del COBRO, no al de apertura: sin
-- hora_cierre los tickets no entran en las graficas descriptivas.
-- Servicio de 50-110 min, determinista por id.
UPDATE tickets
   SET hora_cierre = hora_apertura + INTERVAL (50 + (id * 7) % 61) MINUTE,
       closed_at   = hora_apertura + INTERVAL (50 + (id * 7) % 61) MINUTE
 WHERE id BETWEEN 200 AND 299;

-- ---------------------------------------------------------------------
-- Gráficas "Reservaciones por día" y "Reservaciones por estado".
--
-- Los fixtures de reservaciones de dml.sql viven en fechas fijas (@fecha_*),
-- pensadas para los escenarios del módulo de reservas: caen fuera de la
-- ventana del panel ("últimos 30 días") y dejaban ambas gráficas en cero.
--
-- Este bloque siembra demanda relativa a @HOY, con volumen irregular por día
-- (días flojos, picos y un par de días sin reservas) y la mezcla de estados
-- que alimenta la gráfica de estado. Las fechas ya pasadas usan estados
-- terminales (completada / cancelada / no_show) para no ensuciar la vista de
-- operación; solo hoy y ayer quedan como 'confirmada'. Los nombres se reciclan
-- a propósito: representan clientes recurrentes.
-- ---------------------------------------------------------------------
INSERT INTO reservaciones
  (
   nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, estado, estado_changed_at, origen, request_token
  )
VALUES
  ('Adriana Lozano', 'email', 'adriana.000@example.test',
   DATE_SUB(@HOY, INTERVAL 29 DAY), '13:00:00', 2, '', 'cancelada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 29 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-000'),
  ('Bruno Cerván', 'email', 'bruno.001@example.test',
   DATE_SUB(@HOY, INTERVAL 29 DAY), '20:00:00', 4, 'Alergia a nueces', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 29 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-001'),
  ('Carla Ibáñez', 'email', 'carla.002@example.test',
   DATE_SUB(@HOY, INTERVAL 28 DAY), '21:00:00', 2, 'Aniversario', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-002'),
  ('Diego Maldonado', 'email', 'diego.003@example.test',
   DATE_SUB(@HOY, INTERVAL 28 DAY), '19:00:00', 6, 'Celebración de trabajo', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-003'),
  ('Elena Ferrer', 'email', 'elena.004@example.test',
   DATE_SUB(@HOY, INTERVAL 28 DAY), '14:00:00', 3, 'Mesa junto a ventana', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-004'),
  ('Fabián Ortuño', 'email', 'fabian.005@example.test',
   DATE_SUB(@HOY, INTERVAL 27 DAY), '13:30:00', 2, 'Carriola', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 27 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-005'),
  ('Gabriela Rentería', 'email', 'gabriela.006@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00', 8, 'Cliente frecuente', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-006'),
  ('Héctor Salcedo', 'email', 'hector.007@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '14:00:00', 4, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-007'),
  ('Irene Bustos', 'email', 'irene.008@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '21:00:00', 2, 'Cumpleaños', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-008'),
  ('Joaquín Nieto', 'email', 'joaquin.009@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00', 5, 'Silla alta para bebé', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-009'),
  ('Karla Villalobos', 'email', 'karla.010@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00', 2, 'Terraza si hay', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-010'),
  ('Leonardo Prats', 'email', 'leonardo.011@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00', 4, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-011'),
  ('Mariana Escobar', 'email', 'mariana.012@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '19:00:00', 2, 'Alergia a nueces', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-012'),
  ('Néstor Aguilar', 'email', 'nestor.013@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00', 6, 'Aniversario', 'cancelada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-013'),
  ('Olivia Cardona', 'email', 'olivia.014@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00', 3, 'Celebración de trabajo', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-014'),
  ('Patricio Rueda', 'email', 'patricio.015@example.test',
   DATE_SUB(@HOY, INTERVAL 24 DAY), '14:30:00', 2, 'Mesa junto a ventana', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-015'),
  ('Quetzali Moreno', 'email', 'quetzali.016@example.test',
   DATE_SUB(@HOY, INTERVAL 24 DAY), '21:30:00', 8, 'Carriola', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-016'),
  ('Rodrigo Bañuelos', 'email', 'rodrigo.017@example.test',
   DATE_SUB(@HOY, INTERVAL 22 DAY), '13:30:00', 4, 'Cliente frecuente', 'no_show', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 22 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-017'),
  ('Sofía Zamudio', 'email', 'sofia.018@example.test',
   DATE_SUB(@HOY, INTERVAL 21 DAY), '19:00:00', 2, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-018'),
  ('Tomás Iriarte', 'email', 'tomas.019@example.test',
   DATE_SUB(@HOY, INTERVAL 21 DAY), '14:00:00', 5, 'Cumpleaños', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-019'),
  ('Ursula Pineda', 'email', 'ursula.020@example.test',
   DATE_SUB(@HOY, INTERVAL 21 DAY), '21:00:00', 2, 'Silla alta para bebé', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-020'),
  ('Valentín Cortés', 'email', 'valentin.021@example.test',
   DATE_SUB(@HOY, INTERVAL 20 DAY), '20:30:00', 4, 'Terraza si hay', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '20:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-021'),
  ('Ximena Arreola', 'email', 'ximena.022@example.test',
   DATE_SUB(@HOY, INTERVAL 20 DAY), '15:30:00', 2, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-022'),
  ('Yago Benítez', 'email', 'yago.023@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00', 6, 'Alergia a nueces', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-023'),
  ('Zoé Márquez', 'email', 'zoe.024@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '14:30:00', 3, 'Aniversario', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-024'),
  ('Alonso Vergara', 'email', 'alonso.025@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '21:30:00', 2, 'Celebración de trabajo', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-025'),
  ('Brenda Quiroz', 'email', 'brenda.026@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00', 8, 'Mesa junto a ventana', 'cancelada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-026'),
  ('Cristóbal Serna', 'email', 'cristobal.027@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00', 4, 'Carriola', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-027'),
  ('Daniela Rojas', 'email', 'daniela.028@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00', 2, 'Cliente frecuente', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-028'),
  ('Emiliano Tapia', 'email', 'emiliano.029@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00', 5, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-029'),
  ('Fernanda Olmos', 'email', 'fernanda.030@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00', 2, 'Cumpleaños', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-030'),
  ('Gerardo Pantoja', 'email', 'gerardo.031@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00', 4, 'Silla alta para bebé', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-031'),
  ('Helena Muñiz', 'email', 'helena.032@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00', 2, 'Terraza si hay', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-032'),
  ('Ignacio Bravo', 'email', 'ignacio.033@example.test',
   DATE_SUB(@HOY, INTERVAL 17 DAY), '20:30:00', 6, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '20:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-033'),
  ('Julieta Ocampo', 'email', 'julieta.034@example.test',
   DATE_SUB(@HOY, INTERVAL 17 DAY), '15:30:00', 3, 'Alergia a nueces', 'no_show', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-034'),
  ('Kevin Alcaraz', 'email', 'kevin.035@example.test',
   DATE_SUB(@HOY, INTERVAL 17 DAY), '13:30:00', 2, 'Aniversario', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-035'),
  ('Lucía Sandoval', 'email', 'lucia.036@example.test',
   DATE_SUB(@HOY, INTERVAL 16 DAY), '13:00:00', 8, 'Celebración de trabajo', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 16 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-036'),
  ('Matías Grijalva', 'email', 'matias.037@example.test',
   DATE_SUB(@HOY, INTERVAL 14 DAY), '15:30:00', 4, 'Mesa junto a ventana', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-037'),
  ('Natalia Espinoza', 'email', 'natalia.038@example.test',
   DATE_SUB(@HOY, INTERVAL 14 DAY), '13:30:00', 2, 'Carriola', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-038'),
  ('Óscar Valadez', 'email', 'oscar.039@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00', 5, 'Cliente frecuente', 'cancelada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-039'),
  ('Paola Zúñiga', 'email', 'paola.040@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00', 2, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-040'),
  ('Ramiro Cisneros', 'email', 'ramiro.041@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '19:30:00', 4, 'Cumpleaños', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-041'),
  ('Sergio Maldonado', 'email', 'sergio.042@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00', 2, 'Silla alta para bebé', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-042'),
  ('Tania Vergara', 'email', 'tania.043@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00', 6, 'Terraza si hay', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-043'),
  ('Ulises Fonseca', 'email', 'ulises.044@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00', 3, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-044'),
  ('Verónica Nájera', 'email', 'veronica.045@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '13:00:00', 2, 'Alergia a nueces', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-045'),
  ('Wendy Cabrera', 'email', 'wendy.046@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '20:00:00', 8, 'Aniversario', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-046'),
  ('Xavier Robledo', 'email', 'xavier.047@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00', 4, 'Celebración de trabajo', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-047'),
  ('Yolanda Prieto', 'email', 'yolanda.048@example.test',
   DATE_SUB(@HOY, INTERVAL 11 DAY), '13:00:00', 2, 'Mesa junto a ventana', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-048'),
  ('Zacarías Beltrán', 'email', 'zacarias.049@example.test',
   DATE_SUB(@HOY, INTERVAL 11 DAY), '20:00:00', 5, 'Carriola', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-049'),
  ('Ana Sofía Lira', 'email', 'ana.050@example.test',
   DATE_SUB(@HOY, INTERVAL 10 DAY), '21:00:00', 2, 'Cliente frecuente', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-050'),
  ('Braulio Mendieta', 'email', 'braulio.051@example.test',
   DATE_SUB(@HOY, INTERVAL 10 DAY), '19:00:00', 4, '', 'no_show', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-051'),
  ('Cecilia Ynzunza', 'email', 'cecilia.052@example.test',
   DATE_SUB(@HOY, INTERVAL 10 DAY), '14:00:00', 2, 'Cumpleaños', 'cancelada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-052'),
  ('Damián Portillo', 'email', 'damian.053@example.test',
   DATE_SUB(@HOY, INTERVAL 9 DAY), '13:30:00', 6, 'Silla alta para bebé', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 9 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-053'),
  ('Estela Guardado', 'email', 'estela.054@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00', 3, 'Terraza si hay', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-054'),
  ('Fausto Rivadeneira', 'email', 'fausto.055@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '14:00:00', 2, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-055'),
  ('Gina Palomares', 'email', 'gina.056@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '21:00:00', 8, 'Alergia a nueces', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-056'),
  ('Hugo Villaseñor', 'email', 'hugo.057@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00', 4, 'Aniversario', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-057'),
  ('Inés Carbajal', 'email', 'ines.058@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00', 2, 'Celebración de trabajo', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-058'),
  ('Jonás Ledesma', 'email', 'jonas.059@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00', 5, 'Mesa junto a ventana', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-059'),
  ('Katia Berrones', 'email', 'katia.060@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00', 2, 'Carriola', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-060'),
  ('Luis Ángel Toledo', 'email', 'luis.061@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00', 4, 'Cliente frecuente', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-061'),
  ('Miriam Cuéllar', 'email', 'miriam.062@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00', 2, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-062'),
  ('Nicolás Arámbula', 'email', 'nicolas.063@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00', 6, 'Cumpleaños', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-063'),
  ('Odette Fierro', 'email', 'odette.064@example.test',
   DATE_SUB(@HOY, INTERVAL 6 DAY), '20:00:00', 3, 'Silla alta para bebé', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-064'),
  ('Pablo Zepeda', 'email', 'pablo.065@example.test',
   DATE_SUB(@HOY, INTERVAL 6 DAY), '15:00:00', 2, 'Terraza si hay', 'cancelada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-065'),
  ('Regina Alfaro', 'email', 'regina.066@example.test',
   DATE_SUB(@HOY, INTERVAL 6 DAY), '13:00:00', 8, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-066'),
  ('Samuel Íñiguez', 'email', 'samuel.067@example.test',
   DATE_SUB(@HOY, INTERVAL 5 DAY), '21:30:00', 4, 'Alergia a nueces', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 5 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-067'),
  ('Tere Camarillo', 'email', 'tere.068@example.test',
   DATE_SUB(@HOY, INTERVAL 5 DAY), '19:30:00', 2, 'Aniversario', 'no_show', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 5 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-068'),
  ('Uriel Bermúdez', 'email', 'uriel.069@example.test',
   DATE_SUB(@HOY, INTERVAL 3 DAY), '20:30:00', 5, 'Celebración de trabajo', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '20:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-069'),
  ('Vania Loera', 'email', 'vania.070@example.test',
   DATE_SUB(@HOY, INTERVAL 3 DAY), '15:30:00', 2, 'Mesa junto a ventana', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-070'),
  ('Wilfrido Anaya', 'email', 'wilfrido.071@example.test',
   DATE_SUB(@HOY, INTERVAL 3 DAY), '13:30:00', 4, 'Carriola', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-071'),
  ('Adriana Lozano', 'email', 'adriana.072@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00', 2, 'Cliente frecuente', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-072'),
  ('Bruno Cerván', 'email', 'bruno.073@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00', 6, '', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-073'),
  ('Carla Ibáñez', 'email', 'carla.074@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '15:00:00', 3, 'Cumpleaños', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-074'),
  ('Diego Maldonado', 'email', 'diego.075@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00', 2, 'Silla alta para bebé', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-analytics-res-075'),
  ('Elena Ferrer', 'email', 'elena.076@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00', 8, 'Terraza si hay', 'completada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-analytics-res-076'),
  ('Fabián Ortuño', 'email', 'fabian.077@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00', 4, '', 'confirmada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00') + INTERVAL -1440 MINUTE, 'landing', 'fx-analytics-res-077'),
  ('Gabriela Rentería', 'email', 'gabriela.078@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '20:30:00', 2, 'Alergia a nueces', 'confirmada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '20:30:00') + INTERVAL -1440 MINUTE, 'admin', 'fx-analytics-res-078'),
  ('Héctor Salcedo', 'email', 'hector.079@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '15:30:00', 5, 'Aniversario', 'confirmada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '15:30:00') + INTERVAL -1440 MINUTE, 'landing', 'fx-analytics-res-079'),
  ('Irene Bustos', 'email', 'irene.080@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00', 2, 'Celebración de trabajo', 'confirmada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00') + INTERVAL -1440 MINUTE, 'admin', 'fx-analytics-res-080'),
  ('Joaquín Nieto', 'email', 'joaquin.081@example.test',
   DATE_SUB(@HOY, INTERVAL 0 DAY), '20:30:00', 4, 'Mesa junto a ventana', 'confirmada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 0 DAY), '20:30:00') + INTERVAL -1440 MINUTE, 'landing', 'fx-analytics-res-081'),
  ('Karla Villalobos', 'email', 'karla.082@example.test',
   DATE_SUB(@HOY, INTERVAL 0 DAY), '15:30:00', 2, 'Carriola', 'confirmada', TIMESTAMP(DATE_SUB(@HOY, INTERVAL 0 DAY), '15:30:00') + INTERVAL -1440 MINUTE, 'admin', 'fx-analytics-res-082');

-- Fin de analiticas-datos-ex.sql


-- ═════════════════════════════════════════════════════════════════════
-- BLOQUE 3 · Fixture de RevPASH   (antes database/REVPash-pruebas.sql)
--
-- Depende de los ingredientes y recetas del bloque 2.
-- ═════════════════════════════════════════════════════════════════════

-- =====================================================================
-- Casa Pestalozzi — Semana completa de operación para las analíticas
-- diagnósticas (ver database/ANALITICAS.md §3).
--
-- Simula SIETE DÍAS SEGUIDOS de servicio, de lunes a domingo, con el
-- restaurante abierto todos los días de 08:00 a 22:00. Está hecho para que
-- las tres analíticas del panel /admin/analytics se puedan leer y explicar
-- sobre datos con forma reconocible de restaurante:
--
--   §3.1 Ingeniería de menú   — el catálogo vendido cae en los cuatro
--                               cuadrantes (estrella, vaca, incógnita, perro).
--   §3.2 RevPASH              — mapa 14 horas × 7 días con relieve deliberado:
--                               picos de desayuno, comida y cena, una franja
--                               muerta a media tarde y un lunes flojo.
--   §3.4 Reglas de asociación — parejas recurrentes por franja, con lift > 1.
--
-- ORDEN DE CARGA
--   1. database/ddl.sql
--   2. database/deploy.sql            (categorías, productos, mesas, horario)
--   3. database/development.sql       (este archivo, del que esto es el bloque 3)
--
-- El bloque 1 de este mismo archivo aporta dos cosas que este fixture
-- aprovecha si están: los usuarios demo —los meseros a los que se atribuyen
-- estos tickets— y las recetas de bebida (ingredientes 1-9). Sin él el archivo
-- carga igual: mesero_id queda en NULL y los cafés y jugos entran a la
-- ingeniería de menú con costo 0, o sea con margen igual a su precio.
--
-- CÓMO VERLO
--   La semana sembrada es de sábado a viernes —los siete días de la semana
--   exactamente una vez cada uno—, así que el denominador del RevPASH vale 1
--   día por celda y el mapa muestra directamente ingreso ÷ 44 asientos, sin
--   promediar nada.
--
--   Se ancla a CURDATE() al cargar el dump y cae siempre en la semana completa
--   anterior a hoy, así que con el filtro por defecto ("últimos 30 días") el
--   relieve se lee sin tocar nada. Para saber en qué fechas quedó:
--
--     SELECT @SEM_INI, @SEM_FIN;
--
--   Para moverla, cambiar el único SET @REVPASH_ANCLA de abajo (tiene que
--   seguir siendo un sábado): el resto del archivo se cuelga de @D0..@D6.
--
-- HORARIO
--   Este archivo NO toca 'horarios_operacion' ni 'excepciones_operacion': el
--   horario del negocio ya está definido en la base y es el denominador del
--   RevPASH, así que dos semillas compitiendo por esa tabla harían que el
--   orden de carga decidiera el horario del restaurante (deuda D-9 de
--   ANALITICAS.md). Las ventas van de 08:00 a 22:00; mientras el horario
--   configurado cubra esa franja, las 14 filas (08 a 21) salen abiertas. Si
--   algún día cierra antes, sus celdas de más tarde saldrán marcadas como
--   fuera de horario —borde punteado— porque su denominador son los días con
--   venta y no los días abiertos.
--
-- Es idempotente: borra sus propios rangos antes de insertar (tickets
-- 300-499 y reservaciones 'fx-revpash-res-%'), así que puede recargarse
-- cuantas veces haga falta sin duplicar nada.
-- =====================================================================

-- El cliente `mysql` negocia latin1 por defecto en esta instalación, y con eso
-- cada acento de este archivo entra doblemente codificado ('Salmón' se guarda
-- como 'SalmÃ³n'). Hay que declarar el juego de la CONEXIÓN.
SET NAMES utf8mb4;

-- El contenedor de MySQL corre en UTC, pero la app abre su sesión en GMT-6
-- (ver includes/database.php). Se replica aquí para que las columnas TIMESTAMP
-- se guarden con la misma referencia con la que luego se leen y para que
-- CURDATE() sea la fecha local del restaurante, no la del reloj UTC.
SET time_zone = '-06:00';

-- ---------------------------------------------------------------------
-- Las siete fechas de la semana.
--
-- Las variables están nombradas por DÍA DE LA SEMANA, no por orden en el
-- calendario, porque el día de la semana es lo que da forma al mapa de calor:
-- el relieve dice "los lunes son flojos", no "el tercer día es flojo". El ancla
-- (1 de agosto de 2026) cae en sábado, así que la semana corre de sábado a
-- viernes y cada día de la semana ocurre exactamente una vez.
--
-- LA SEMANA SE ANCLA A CURDATE(), no a una fecha fija.
--
-- Antes eran siete literales de agosto de 2026, y eso las condenaba a caducar:
-- cargado el dump unos meses después, la semana entera quedaba fuera de
-- cualquier ventana que el panel mire por defecto y los tableros salían
-- vacíos. El desplazamiento va en MÚLTIPLOS DE SIETE DÍAS justamente para no
-- romper lo único que este fixture necesita —que cada día caiga en su día de
-- la semana— y el "-7" extra garantiza que la semana completa quede detrás de
-- hoy: es una semana de servicio ya ocurrida, y RevPASH analiza el pasado.
-- ---------------------------------------------------------------------
SET @REVPASH_ANCLA := '2026-08-01';   -- sábado
SET @REVPASH_SHIFT := FLOOR((DATEDIFF(CURDATE(), @REVPASH_ANCLA) - 7) / 7) * 7;

SET @D5 := DATE_ADD(@REVPASH_ANCLA, INTERVAL @REVPASH_SHIFT DAY);        -- sábado
SET @D6 := DATE_ADD(@D5, INTERVAL 1 DAY);                                -- domingo
SET @D0 := DATE_ADD(@D5, INTERVAL 2 DAY);                                -- lunes
SET @D1 := DATE_ADD(@D5, INTERVAL 3 DAY);                                -- martes
SET @D2 := DATE_ADD(@D5, INTERVAL 4 DAY);                                -- miércoles
SET @D3 := DATE_ADD(@D5, INTERVAL 5 DAY);                                -- jueves
SET @D4 := DATE_ADD(@D5, INTERVAL 6 DAY);                                -- viernes

-- Extremos del rango, para las consultas de verificación del final.
SET @SEM_INI := @D5;
SET @SEM_FIN := @D4;

-- Meseros por username y no por id: los ids dependen del orden de inserción de
-- el bloque 1. Si ese bloque no se cargó, quedan en NULL y el ticket
-- se inserta igual (mesero_id es nullable).
SET @M1 := (SELECT id FROM usuarios WHERE username = 'mesero1' LIMIT 1);
SET @M2 := (SELECT id FROM usuarios WHERE username = 'mesero2' LIMIT 1);
SET @M3 := (SELECT id FROM usuarios WHERE username = 'mesero3' LIMIT 1);

-- ---------------------------------------------------------------------
-- Limpieza idempotente de lo que crea ESTE archivo.
-- ---------------------------------------------------------------------
DELETE FROM ticket_pagos WHERE ticket_id BETWEEN 300 AND 499;
DELETE FROM ticket_items WHERE ticket_id BETWEEN 300 AND 499;
DELETE FROM ticket_mesas WHERE ticket_id BETWEEN 300 AND 499;
DELETE FROM tickets      WHERE id        BETWEEN 300 AND 499;

DELETE FROM reservacion_mesas
 WHERE reservacion_id IN (
   SELECT id FROM reservaciones WHERE request_token LIKE 'fx-revpash-res-%'
 );
DELETE FROM reservaciones WHERE request_token LIKE 'fx-revpash-res-%';

-- La siembra de ingredientes y de producto_componentes que este fixture
-- traía aquí se retiró al fundir los tres archivos: era exactamente la misma
-- que el bloque de analíticas de arriba, palabra por palabra. Cada archivo la
-- llevaba porque estaba pensado para cargarse solo; en un archivo único,
-- sembrarla dos veces sólo servía para que la segunda pasada borrase y
-- reinsertara lo mismo.
-- ---------------------------------------------------------------------
-- §3.2 / §3.4 · La semana de servicio, día por día.
--
-- Cada ticket lleva hora_apertura Y hora_cierre: el mapa de calor agrupa
-- por HOUR(hora_apertura) —cuándo se sentó la mesa— mientras que las
-- gráficas descriptivas del panel atribuyen la venta al día del COBRO. Un
-- ticket sin hora_cierre simplemente no aparece en esas gráficas.
--
-- El reparto por franja no es decorativo, es el relieve que se quiere leer
-- en el mapa:
--   · picos de desayuno (09-10), comida (13-14) y cena (20-21);
--   · las 16:00 y 17:00 flojas TODOS los días → fila pálida = problema de
--     horario, no de demanda (candidata a menú ejecutivo o a menos
--     personal en esa franja);
--   · el lunes flojo a cualquier hora → columna pálida = problema de
--     demanda, que se arregla de otra manera;
--   · lunes 16:00 y 17:00 y martes 16:00 sin una sola venta con el local
--     abierto → celda en CERO, que el mapa distingue de la celda cerrada
--     (·): la primera es desempeño, la segunda es calendario.
-- ---------------------------------------------------------------------

-- -------------------------------------------------------------------
-- SÁBADO 2026-08-01 (@D5) · 42 tickets
-- -------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago, propina, mesero_id) VALUES
(424, 2, 'Elena Ferrer', TIMESTAMP(@D5,'08:35:00'), TIMESTAMP(@D5,'10:05:00'), TIMESTAMP(@D5,'10:05:00'), 'cerrado', 'tarjeta', 76, @M2),
(425, 4, 'Fabián Ortuño', TIMESTAMP(@D5,'08:42:00'), TIMESTAMP(@D5,'10:19:00'), TIMESTAMP(@D5,'10:19:00'), 'cerrado', 'dividido', 121, @M3),
(426, 2, 'Gina Palomares', TIMESTAMP(@D5,'09:23:00'), TIMESTAMP(@D5,'11:07:00'), TIMESTAMP(@D5,'11:07:00'), 'cerrado', 'efectivo', 59, @M1),
(427, 2, 'Hugo Villaseñor', TIMESTAMP(@D5,'09:40:00'), TIMESTAMP(@D5,'10:30:00'), TIMESTAMP(@D5,'10:30:00'), 'cerrado', 'tarjeta', 58, @M2),
(428, 2, 'Inés Carbajal', TIMESTAMP(@D5,'09:12:00'), TIMESTAMP(@D5,'10:09:00'), TIMESTAMP(@D5,'10:09:00'), 'cerrado', 'tarjeta', 59, @M3),
(429, 3, 'Jonás Ledesma', TIMESTAMP(@D5,'09:29:00'), TIMESTAMP(@D5,'10:33:00'), TIMESTAMP(@D5,'10:33:00'), 'cerrado', 'efectivo', 79, @M1),
(430, 4, 'Katia Berrones', TIMESTAMP(@D5,'10:30:00'), TIMESTAMP(@D5,'11:41:00'), TIMESTAMP(@D5,'11:41:00'), 'cerrado', 'tarjeta', 173, @M2),
(431, 4, 'Luis Toledo', TIMESTAMP(@D5,'10:47:00'), TIMESTAMP(@D5,'12:05:00'), TIMESTAMP(@D5,'12:05:00'), 'cerrado', 'tarjeta', 130, @M3),
(432, 4, 'Miriam Cuéllar', TIMESTAMP(@D5,'10:19:00'), TIMESTAMP(@D5,'11:44:00'), TIMESTAMP(@D5,'11:44:00'), 'cerrado', 'efectivo', 0, @M1),
(433, 3, 'Nicolás Arámbula', TIMESTAMP(@D5,'10:36:00'), TIMESTAMP(@D5,'12:08:00'), TIMESTAMP(@D5,'12:08:00'), 'cerrado', 'tarjeta', 92, @M2),
(434, 2, 'Odette Fierro', TIMESTAMP(@D5,'10:08:00'), TIMESTAMP(@D5,'11:47:00'), TIMESTAMP(@D5,'11:47:00'), 'cerrado', 'tarjeta', 59, @M3),
(435, 3, 'Pablo Zepeda', TIMESTAMP(@D5,'11:37:00'), TIMESTAMP(@D5,'13:23:00'), TIMESTAMP(@D5,'13:23:00'), 'cerrado', 'efectivo', 110, @M1),
(436, 2, 'Regina Alfaro', TIMESTAMP(@D5,'11:09:00'), TIMESTAMP(@D5,'12:01:00'), TIMESTAMP(@D5,'12:01:00'), 'cerrado', 'tarjeta', 78, @M2),
(437, 3, 'Samuel Íñiguez', TIMESTAMP(@D5,'11:26:00'), TIMESTAMP(@D5,'12:25:00'), TIMESTAMP(@D5,'12:25:00'), 'cerrado', 'tarjeta', 109, @M3),
(438, 3, 'Tere Camarillo', TIMESTAMP(@D5,'12:44:00'), TIMESTAMP(@D5,'13:50:00'), TIMESTAMP(@D5,'13:50:00'), 'cerrado', 'efectivo', 132, @M1),
(439, 3, 'Uriel Bermúdez', TIMESTAMP(@D5,'12:16:00'), TIMESTAMP(@D5,'13:29:00'), TIMESTAMP(@D5,'13:29:00'), 'cerrado', 'tarjeta', 116, @M2),
(440, 2, 'Vania Loera', TIMESTAMP(@D5,'13:06:00'), TIMESTAMP(@D5,'14:26:00'), TIMESTAMP(@D5,'14:26:00'), 'cerrado', 'tarjeta', 86, @M3),
(441, 2, 'Wilfrido Anaya', TIMESTAMP(@D5,'13:23:00'), TIMESTAMP(@D5,'14:50:00'), TIMESTAMP(@D5,'14:50:00'), 'cerrado', 'efectivo', 52, @M1),
(442, 2, 'Ximena Duarte', TIMESTAMP(@D5,'13:40:00'), TIMESTAMP(@D5,'15:14:00'), TIMESTAMP(@D5,'15:14:00'), 'cerrado', 'dividido', 119, @M2),
(443, 2, 'Yolanda Prieto', TIMESTAMP(@D5,'13:12:00'), TIMESTAMP(@D5,'14:53:00'), TIMESTAMP(@D5,'14:53:00'), 'cerrado', 'tarjeta', 91, @M3),
(444, 2, 'Zacarías Beltrán', TIMESTAMP(@D5,'14:13:00'), TIMESTAMP(@D5,'16:01:00'), TIMESTAMP(@D5,'16:01:00'), 'cerrado', 'efectivo', 0, @M1),
(445, 2, 'Adriana Lozano', TIMESTAMP(@D5,'14:30:00'), TIMESTAMP(@D5,'15:24:00'), TIMESTAMP(@D5,'15:24:00'), 'cerrado', 'tarjeta', 79, @M2),
(446, 3, 'Beto Nájera', TIMESTAMP(@D5,'14:47:00'), TIMESTAMP(@D5,'15:48:00'), TIMESTAMP(@D5,'15:48:00'), 'cerrado', 'tarjeta', 132, @M3),
(447, 3, 'Cecilia Ynzunza', TIMESTAMP(@D5,'14:19:00'), TIMESTAMP(@D5,'15:27:00'), TIMESTAMP(@D5,'15:27:00'), 'cerrado', 'efectivo', 178, @M1),
(448, 3, 'Damián Portillo', TIMESTAMP(@D5,'15:20:00'), TIMESTAMP(@D5,'16:35:00'), TIMESTAMP(@D5,'16:35:00'), 'cerrado', 'tarjeta', 110, @M2),
(449, 3, 'Estela Guardado', TIMESTAMP(@D5,'15:37:00'), TIMESTAMP(@D5,'16:59:00'), TIMESTAMP(@D5,'16:59:00'), 'cerrado', 'tarjeta', 147, @M3),
(450, 2, 'Fausto Rivas', TIMESTAMP(@D5,'16:27:00'), TIMESTAMP(@D5,'17:56:00'), TIMESTAMP(@D5,'17:56:00'), 'cerrado', 'efectivo', 30, @M1),
(451, 2, 'Gael Ruan', TIMESTAMP(@D5,'17:34:00'), TIMESTAMP(@D5,'19:10:00'), TIMESTAMP(@D5,'19:10:00'), 'cerrado', 'tarjeta', 36, @M2),
(452, 2, 'Héctor Salcedo', TIMESTAMP(@D5,'18:41:00'), TIMESTAMP(@D5,'20:24:00'), TIMESTAMP(@D5,'20:24:00'), 'cerrado', 'tarjeta', 35, @M3),
(453, 2, 'Irene Bustos', TIMESTAMP(@D5,'18:13:00'), TIMESTAMP(@D5,'20:03:00'), TIMESTAMP(@D5,'20:03:00'), 'cerrado', 'efectivo', 37, @M1),
(454, 2, 'Joaquín Nieto', TIMESTAMP(@D5,'19:48:00'), TIMESTAMP(@D5,'20:44:00'), TIMESTAMP(@D5,'20:44:00'), 'cerrado', 'tarjeta', 108, @M2),
(455, 4, 'Karla Villalobos', TIMESTAMP(@D5,'19:20:00'), TIMESTAMP(@D5,'20:23:00'), TIMESTAMP(@D5,'20:23:00'), 'cerrado', 'tarjeta', 173, @M3),
(456, 2, 'Lía Ordaz', TIMESTAMP(@D5,'19:37:00'), TIMESTAMP(@D5,'20:47:00'), TIMESTAMP(@D5,'20:47:00'), 'cerrado', 'efectivo', 0, @M1),
(457, 3, 'Mar Cueto', TIMESTAMP(@D5,'20:10:00'), TIMESTAMP(@D5,'21:27:00'), TIMESTAMP(@D5,'21:27:00'), 'cerrado', 'tarjeta', 116, @M2),
(458, 3, 'Noa Cid', TIMESTAMP(@D5,'20:27:00'), TIMESTAMP(@D5,'21:51:00'), TIMESTAMP(@D5,'21:51:00'), 'cerrado', 'tarjeta', 131, @M3),
(459, 4, 'Ori Lozano', TIMESTAMP(@D5,'20:44:00'), TIMESTAMP(@D5,'22:15:00'), TIMESTAMP(@D5,'22:15:00'), 'cerrado', 'dividido', 260, @M1),
(460, 3, 'Paula Rivas', TIMESTAMP(@D5,'20:16:00'), TIMESTAMP(@D5,'21:54:00'), TIMESTAMP(@D5,'21:54:00'), 'cerrado', 'tarjeta', 199, @M2),
(461, 2, 'Quirino Ávila', TIMESTAMP(@D5,'21:17:00'), TIMESTAMP(@D5,'23:02:00'), TIMESTAMP(@D5,'23:02:00'), 'cerrado', 'tarjeta', 96, @M3),
(462, 2, 'Rita Peña', TIMESTAMP(@D5,'21:34:00'), TIMESTAMP(@D5,'22:25:00'), TIMESTAMP(@D5,'22:25:00'), 'cerrado', 'efectivo', 100, @M1),
(463, 3, 'Sol Marín', TIMESTAMP(@D5,'21:06:00'), TIMESTAMP(@D5,'22:04:00'), TIMESTAMP(@D5,'22:04:00'), 'cerrado', 'tarjeta', 110, @M2),
(464, 4, 'Tono Gil', TIMESTAMP(@D5,'21:23:00'), TIMESTAMP(@D5,'22:28:00'), TIMESTAMP(@D5,'22:28:00'), 'cerrado', 'tarjeta', 197, @M3),
(465, 4, 'Ulises Nava', TIMESTAMP(@D5,'21:40:00'), TIMESTAMP(@D5,'22:52:00'), TIMESTAMP(@D5,'22:52:00'), 'cerrado', 'efectivo', 104, @M1);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(424, 4, 1),
(425, 5, 1),
(426, 7, 1),
(427, 8, 1),
(428, 9, 1),
(429, 10, 1),
(430, 1, 1),
(431, 2, 1),
(432, 3, 1),
(433, 4, 1),
(434, 5, 1),
(435, 7, 1),
(436, 8, 1),
(437, 9, 1),
(438, 11, 1),
(439, 1, 1),
(440, 3, 1),
(441, 4, 1),
(442, 5, 1),
(443, 6, 1),
(444, 8, 1),
(445, 9, 1),
(446, 10, 1),
(447, 11, 1),
(448, 2, 1),
(449, 3, 1),
(450, 5, 1),
(451, 7, 1),
(452, 9, 1),
(453, 10, 1),
(454, 1, 1),
(455, 2, 1),
(456, 3, 1),
(457, 5, 1),
(458, 6, 1),
(459, 7, 1),
(460, 8, 1),
(461, 10, 1),
(462, 11, 1),
(463, 1, 1),
(464, 2, 1),
(465, 3, 1);

INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(424, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D5,'08:41:00')),
(424, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D5,'08:41:00')),
(425, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D5,'08:48:00')),
(425, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'08:48:00')),
(426, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D5,'09:29:00')),
(426, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'09:29:00')),
(427, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D5,'09:46:00')),
(427, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D5,'09:46:00')),
(428, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D5,'09:18:00')),
(428, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'09:18:00')),
(429, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 3, 'entregado', TIMESTAMP(@D5,'09:35:00')),
(429, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'09:35:00')),
(430, 'Enmoladas', 240.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D5,'10:36:00')),
(430, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D5,'10:36:00')),
(431, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D5,'10:53:00')),
(431, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'10:53:00')),
(431, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D5,'10:53:00')),
(432, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D5,'10:25:00')),
(432, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 4, 'entregado', TIMESTAMP(@D5,'10:25:00')),
(433, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 3, 'entregado', TIMESTAMP(@D5,'10:42:00')),
(433, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 3, 'entregado', TIMESTAMP(@D5,'10:42:00')),
(434, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D5,'10:14:00')),
(434, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'10:14:00')),
(435, 'Chilaquiles', 180.00, 'Desayunos', 3, 3, 'entregado', TIMESTAMP(@D5,'11:43:00')),
(435, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 3, 'entregado', TIMESTAMP(@D5,'11:43:00')),
(436, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D5,'11:15:00')),
(436, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D5,'11:15:00')),
(436, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D5,'11:15:00')),
(437, 'Enmoladas', 240.00, 'Desayunos', 3, 3, 'entregado', TIMESTAMP(@D5,'11:32:00')),
(437, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D5,'11:32:00')),
(438, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D5,'12:50:00')),
(438, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'12:50:00')),
(439, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D5,'12:22:00')),
(439, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'12:22:00')),
(440, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D5,'13:12:00')),
(440, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D5,'13:12:00')),
(441, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D5,'13:29:00')),
(441, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D5,'13:29:00')),
(441, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D5,'13:29:00')),
(442, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D5,'13:46:00')),
(442, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D5,'13:46:00')),
(443, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D5,'13:18:00')),
(443, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D5,'13:18:00')),
(443, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D5,'13:18:00')),
(444, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D5,'14:19:00')),
(444, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D5,'14:19:00')),
(445, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D5,'14:36:00')),
(445, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D5,'14:36:00')),
(446, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D5,'14:53:00')),
(446, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'14:53:00')),
(447, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D5,'14:25:00')),
(447, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'14:25:00')),
(447, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D5,'14:25:00')),
(448, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D5,'15:26:00')),
(448, 'Burrata', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D5,'15:26:00')),
(449, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D5,'15:43:00')),
(449, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'15:43:00')),
(449, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D5,'15:43:00')),
(450, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'16:33:00')),
(450, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D5,'16:33:00')),
(451, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'17:40:00')),
(451, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D5,'17:40:00')),
(451, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D5,'17:40:00')),
(451, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'cancelado', TIMESTAMP(@D5,'17:40:00')),
(452, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'18:47:00')),
(452, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D5,'18:47:00')),
(453, 'Latte', 80.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D5,'18:19:00')),
(453, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 1, 'entregado', TIMESTAMP(@D5,'18:19:00')),
(454, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D5,'19:54:00')),
(454, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D5,'19:54:00')),
(455, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 4, 'entregado', TIMESTAMP(@D5,'19:26:00')),
(455, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'19:26:00')),
(456, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D5,'19:43:00')),
(456, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D5,'19:43:00')),
(457, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D5,'20:16:00')),
(457, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'20:16:00')),
(458, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D5,'20:33:00')),
(458, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D5,'20:33:00')),
(459, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 4, 'entregado', TIMESTAMP(@D5,'20:50:00')),
(459, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'20:50:00')),
(459, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 4, 'entregado', TIMESTAMP(@D5,'20:50:00')),
(459, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D5,'20:50:00')),
(460, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D5,'20:22:00')),
(460, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D5,'20:22:00')),
(460, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D5,'20:22:00')),
(461, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D5,'21:23:00')),
(461, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D5,'21:23:00')),
(462, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D5,'21:40:00')),
(462, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D5,'21:40:00')),
(462, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D5,'21:40:00')),
(463, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D5,'21:12:00')),
(463, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D5,'21:12:00')),
(464, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 4, 'entregado', TIMESTAMP(@D5,'21:29:00')),
(464, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', TIMESTAMP(@D5,'21:29:00')),
(465, 'Burrata', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D5,'21:46:00')),
(465, 'Milano', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D5,'21:46:00'));

-- Cuentas divididas del día: la suma de ticket_pagos.monto cubre el
-- consumo más la propina, que es lo que valida MapaController::cerrarTicket.
INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto) VALUES
(425, 1, 'tarjeta', 565.50),
(425, 2, 'efectivo', 565.50),
(442, 1, 'tarjeta', 454.50),
(442, 2, 'efectivo', 454.50),
(459, 1, 'tarjeta', 997.50),
(459, 2, 'efectivo', 997.50);

-- -------------------------------------------------------------------
-- DOMINGO 2026-08-02 (@D6) · 33 tickets
-- -------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago, propina, mesero_id) VALUES
(466, 2, 'Vera Luna', TIMESTAMP(@D6,'08:35:00'), TIMESTAMP(@D6,'09:54:00'), TIMESTAMP(@D6,'09:54:00'), 'cerrado', 'tarjeta', 103, @M2),
(467, 4, 'Wendy Fuentes', TIMESTAMP(@D6,'08:42:00'), TIMESTAMP(@D6,'10:08:00'), TIMESTAMP(@D6,'10:08:00'), 'cerrado', 'tarjeta', 118, @M3),
(468, 3, 'Xavier Peña', TIMESTAMP(@D6,'09:23:00'), TIMESTAMP(@D6,'10:56:00'), TIMESTAMP(@D6,'10:56:00'), 'cerrado', 'efectivo', 0, @M1),
(469, 2, 'Yara Sol', TIMESTAMP(@D6,'09:40:00'), TIMESTAMP(@D6,'11:20:00'), TIMESTAMP(@D6,'11:20:00'), 'cerrado', 'tarjeta', 65, @M2),
(470, 4, 'Grupo Lara', TIMESTAMP(@D6,'09:12:00'), TIMESTAMP(@D6,'10:59:00'), TIMESTAMP(@D6,'10:59:00'), 'cerrado', 'tarjeta', 117, @M3),
(471, 4, 'Grupo Cano', TIMESTAMP(@D6,'09:29:00'), TIMESTAMP(@D6,'10:22:00'), TIMESTAMP(@D6,'10:22:00'), 'cerrado', 'efectivo', 173, @M1),
(472, 4, 'Grupo Ibarra', TIMESTAMP(@D6,'10:30:00'), TIMESTAMP(@D6,'11:30:00'), TIMESTAMP(@D6,'11:30:00'), 'cerrado', 'tarjeta', 134, @M2),
(473, 2, 'Grupo Villa', TIMESTAMP(@D6,'10:47:00'), TIMESTAMP(@D6,'11:54:00'), TIMESTAMP(@D6,'11:54:00'), 'cerrado', 'tarjeta', 69, @M3),
(474, 2, 'Grupo Sáez', TIMESTAMP(@D6,'10:19:00'), TIMESTAMP(@D6,'11:33:00'), TIMESTAMP(@D6,'11:33:00'), 'cerrado', 'efectivo', 59, @M1),
(475, 4, 'Familia Nava', TIMESTAMP(@D6,'10:36:00'), TIMESTAMP(@D6,'11:57:00'), TIMESTAMP(@D6,'11:57:00'), 'cerrado', 'tarjeta', 109, @M2),
(476, 4, 'Familia Robles', TIMESTAMP(@D6,'10:08:00'), TIMESTAMP(@D6,'11:36:00'), TIMESTAMP(@D6,'11:36:00'), 'cerrado', 'dividido', 118, @M3),
(477, 2, 'Familia Prado', TIMESTAMP(@D6,'11:37:00'), TIMESTAMP(@D6,'13:12:00'), TIMESTAMP(@D6,'13:12:00'), 'cerrado', 'efectivo', 52, @M1),
(478, 4, 'Familia Mena', TIMESTAMP(@D6,'11:09:00'), TIMESTAMP(@D6,'12:51:00'), TIMESTAMP(@D6,'12:51:00'), 'cerrado', 'tarjeta', 193, @M2),
(479, 4, 'Familia Cruz', TIMESTAMP(@D6,'11:26:00'), TIMESTAMP(@D6,'13:15:00'), TIMESTAMP(@D6,'13:15:00'), 'cerrado', 'tarjeta', 148, @M3),
(480, 2, 'Ana Rueda', TIMESTAMP(@D6,'12:44:00'), TIMESTAMP(@D6,'13:39:00'), TIMESTAMP(@D6,'13:39:00'), 'cerrado', 'efectivo', 0, @M1),
(481, 3, 'Bruno Salas', TIMESTAMP(@D6,'12:16:00'), TIMESTAMP(@D6,'13:18:00'), TIMESTAMP(@D6,'13:18:00'), 'cerrado', 'tarjeta', 116, @M2),
(482, 3, 'Carla Ibáñez', TIMESTAMP(@D6,'13:06:00'), TIMESTAMP(@D6,'14:15:00'), TIMESTAMP(@D6,'14:15:00'), 'cerrado', 'tarjeta', 149, @M3),
(483, 2, 'Diego Nava', TIMESTAMP(@D6,'13:23:00'), TIMESTAMP(@D6,'14:39:00'), TIMESTAMP(@D6,'14:39:00'), 'cerrado', 'efectivo', 82, @M1),
(484, 3, 'Elena Ferrer', TIMESTAMP(@D6,'13:40:00'), TIMESTAMP(@D6,'15:03:00'), TIMESTAMP(@D6,'15:03:00'), 'cerrado', 'tarjeta', 165, @M2),
(485, 3, 'Fabián Ortuño', TIMESTAMP(@D6,'14:13:00'), TIMESTAMP(@D6,'15:43:00'), TIMESTAMP(@D6,'15:43:00'), 'cerrado', 'tarjeta', 139, @M3),
(486, 3, 'Gina Palomares', TIMESTAMP(@D6,'14:30:00'), TIMESTAMP(@D6,'16:07:00'), TIMESTAMP(@D6,'16:07:00'), 'cerrado', 'efectivo', 142, @M1),
(487, 3, 'Hugo Villaseñor', TIMESTAMP(@D6,'14:47:00'), TIMESTAMP(@D6,'16:31:00'), TIMESTAMP(@D6,'16:31:00'), 'cerrado', 'tarjeta', 73, @M2),
(488, 3, 'Inés Carbajal', TIMESTAMP(@D6,'15:20:00'), TIMESTAMP(@D6,'16:10:00'), TIMESTAMP(@D6,'16:10:00'), 'cerrado', 'tarjeta', 139, @M3),
(489, 2, 'Jonás Ledesma', TIMESTAMP(@D6,'15:37:00'), TIMESTAMP(@D6,'16:34:00'), TIMESTAMP(@D6,'16:34:00'), 'cerrado', 'efectivo', 68, @M1),
(490, 2, 'Katia Berrones', TIMESTAMP(@D6,'16:27:00'), TIMESTAMP(@D6,'17:31:00'), TIMESTAMP(@D6,'17:31:00'), 'cerrado', 'tarjeta', 44, @M2),
(491, 2, 'Luis Toledo', TIMESTAMP(@D6,'17:34:00'), TIMESTAMP(@D6,'18:45:00'), TIMESTAMP(@D6,'18:45:00'), 'cerrado', 'tarjeta', 35, @M3),
(492, 2, 'Miriam Cuéllar', TIMESTAMP(@D6,'18:41:00'), TIMESTAMP(@D6,'19:59:00'), TIMESTAMP(@D6,'19:59:00'), 'cerrado', 'efectivo', 0, @M1),
(493, 4, 'Nicolás Arámbula', TIMESTAMP(@D6,'19:48:00'), TIMESTAMP(@D6,'21:13:00'), TIMESTAMP(@D6,'21:13:00'), 'cerrado', 'dividido', 166, @M2),
(494, 4, 'Odette Fierro', TIMESTAMP(@D6,'19:20:00'), TIMESTAMP(@D6,'20:52:00'), TIMESTAMP(@D6,'20:52:00'), 'cerrado', 'tarjeta', 125, @M3),
(495, 2, 'Pablo Zepeda', TIMESTAMP(@D6,'20:10:00'), TIMESTAMP(@D6,'21:49:00'), TIMESTAMP(@D6,'21:49:00'), 'cerrado', 'efectivo', 137, @M1),
(496, 2, 'Regina Alfaro', TIMESTAMP(@D6,'20:27:00'), TIMESTAMP(@D6,'22:13:00'), TIMESTAMP(@D6,'22:13:00'), 'cerrado', 'tarjeta', 108, @M2),
(497, 3, 'Samuel Íñiguez', TIMESTAMP(@D6,'21:17:00'), TIMESTAMP(@D6,'22:09:00'), TIMESTAMP(@D6,'22:09:00'), 'cerrado', 'tarjeta', 236, @M3),
(498, 4, 'Tere Camarillo', TIMESTAMP(@D6,'21:34:00'), TIMESTAMP(@D6,'22:33:00'), TIMESTAMP(@D6,'22:33:00'), 'cerrado', 'efectivo', 199, @M1);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(466, 2, 1),
(467, 3, 1),
(468, 5, 1),
(469, 6, 1),
(470, 7, 1),
(471, 8, 1),
(472, 10, 1),
(473, 11, 1),
(474, 1, 1),
(475, 2, 1),
(476, 3, 1),
(477, 5, 1),
(478, 6, 1),
(479, 7, 1),
(480, 9, 1),
(481, 10, 1),
(482, 1, 1),
(483, 2, 1),
(484, 3, 1),
(485, 5, 1),
(486, 6, 1),
(487, 7, 1),
(488, 9, 1),
(489, 10, 1),
(490, 1, 1),
(491, 3, 1),
(492, 5, 1),
(493, 7, 1),
(494, 8, 1),
(495, 10, 1),
(496, 11, 1),
(497, 2, 1),
(498, 3, 1);

INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(466, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D6,'08:41:00')),
(466, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D6,'08:41:00')),
(466, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'08:41:00')),
(467, 'Chilaquiles', 180.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'08:48:00')),
(467, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 4, 'entregado', TIMESTAMP(@D6,'08:48:00')),
(468, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 3, 'entregado', TIMESTAMP(@D6,'09:29:00')),
(468, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D6,'09:29:00')),
(469, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D6,'09:46:00')),
(469, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'09:46:00')),
(469, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D6,'09:46:00')),
(470, 'Chilaquiles', 180.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'09:18:00')),
(470, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D6,'09:18:00')),
(470, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'09:18:00')),
(471, 'Enmoladas', 240.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'09:35:00')),
(471, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D6,'09:35:00')),
(472, 'Chilaquiles', 180.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'10:36:00')),
(472, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D6,'10:36:00')),
(473, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D6,'10:53:00')),
(473, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'10:53:00')),
(474, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D6,'10:25:00')),
(474, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D6,'10:25:00')),
(475, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'10:42:00')),
(475, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D6,'10:42:00')),
(475, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'10:42:00')),
(476, 'Chilaquiles', 180.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'10:14:00')),
(476, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 4, 'entregado', TIMESTAMP(@D6,'10:14:00')),
(477, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D6,'11:43:00')),
(477, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'11:43:00')),
(477, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'11:43:00')),
(478, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'11:15:00')),
(478, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 4, 'entregado', TIMESTAMP(@D6,'11:15:00')),
(478, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D6,'11:15:00')),
(479, 'Enmoladas', 240.00, 'Desayunos', 3, 4, 'entregado', TIMESTAMP(@D6,'11:32:00')),
(479, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D6,'11:32:00')),
(479, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'11:32:00')),
(480, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D6,'12:50:00')),
(480, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D6,'12:50:00')),
(481, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D6,'12:22:00')),
(481, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D6,'12:22:00')),
(482, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D6,'13:12:00')),
(482, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D6,'13:12:00')),
(482, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'13:12:00')),
(483, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D6,'13:29:00')),
(483, 'Burrata', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D6,'13:29:00')),
(483, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D6,'13:29:00')),
(484, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D6,'13:46:00')),
(484, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D6,'13:46:00')),
(485, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D6,'14:19:00')),
(485, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D6,'14:19:00')),
(486, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D6,'14:36:00')),
(486, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D6,'14:36:00')),
(487, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D6,'14:53:00')),
(487, 'Burrata', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D6,'14:53:00')),
(488, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D6,'15:26:00')),
(488, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D6,'15:26:00')),
(489, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D6,'15:43:00')),
(489, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D6,'15:43:00')),
(490, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D6,'16:33:00')),
(490, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D6,'16:33:00')),
(491, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D6,'17:40:00')),
(491, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D6,'17:40:00')),
(492, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D6,'18:47:00')),
(492, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D6,'18:47:00')),
(492, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'cancelado', TIMESTAMP(@D6,'18:47:00')),
(493, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 4, 'entregado', TIMESTAMP(@D6,'19:54:00')),
(493, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D6,'19:54:00')),
(493, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 4, 'entregado', TIMESTAMP(@D6,'19:54:00')),
(494, 'Burrata', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D6,'19:26:00')),
(494, 'Milano', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D6,'19:26:00')),
(495, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D6,'20:16:00')),
(495, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D6,'20:16:00')),
(495, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D6,'20:16:00')),
(495, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D6,'20:16:00')),
(496, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D6,'20:33:00')),
(496, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D6,'20:33:00')),
(497, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D6,'21:23:00')),
(497, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D6,'21:23:00')),
(497, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D6,'21:23:00')),
(498, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 4, 'entregado', TIMESTAMP(@D6,'21:40:00')),
(498, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D6,'21:40:00')),
(498, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 4, 'entregado', TIMESTAMP(@D6,'21:40:00'));

-- Cuentas divididas del día: la suma de ticket_pagos.monto cubre el
-- consumo más la propina, que es lo que valida MapaController::cerrarTicket.
INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto) VALUES
(476, 1, 'tarjeta', 549.00),
(476, 2, 'efectivo', 549.00),
(493, 1, 'tarjeta', 913.00),
(493, 2, 'efectivo', 913.00);

-- -------------------------------------------------------------------
-- LUNES 2026-08-03 (@D0) · 15 tickets
-- -------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago, propina, mesero_id) VALUES
(300, 2, 'Ana Rueda', TIMESTAMP(@D0,'08:35:00'), TIMESTAMP(@D0,'09:51:00'), TIMESTAMP(@D0,'09:51:00'), 'cerrado', 'efectivo', 0, @M1),
(301, 2, 'Bruno Salas', TIMESTAMP(@D0,'09:23:00'), TIMESTAMP(@D0,'10:46:00'), TIMESTAMP(@D0,'10:46:00'), 'cerrado', 'tarjeta', 49, @M2),
(302, 2, 'Carla Ibáñez', TIMESTAMP(@D0,'09:40:00'), TIMESTAMP(@D0,'11:10:00'), TIMESTAMP(@D0,'11:10:00'), 'cerrado', 'tarjeta', 69, @M3),
(303, 2, 'Diego Nava', TIMESTAMP(@D0,'10:30:00'), TIMESTAMP(@D0,'12:07:00'), TIMESTAMP(@D0,'12:07:00'), 'cerrado', 'efectivo', 74, @M1),
(304, 2, 'Elena Ferrer', TIMESTAMP(@D0,'10:47:00'), TIMESTAMP(@D0,'12:31:00'), TIMESTAMP(@D0,'12:31:00'), 'cerrado', 'tarjeta', 27, @M2),
(305, 2, 'Fabián Ortuño', TIMESTAMP(@D0,'11:37:00'), TIMESTAMP(@D0,'12:27:00'), TIMESTAMP(@D0,'12:27:00'), 'cerrado', 'tarjeta', 69, @M3),
(306, 2, 'Gina Palomares', TIMESTAMP(@D0,'12:44:00'), TIMESTAMP(@D0,'13:41:00'), TIMESTAMP(@D0,'13:41:00'), 'cerrado', 'dividido', 0, @M1),
(307, 2, 'Hugo Villaseñor', TIMESTAMP(@D0,'13:06:00'), TIMESTAMP(@D0,'14:10:00'), TIMESTAMP(@D0,'14:10:00'), 'cerrado', 'tarjeta', 45, @M2),
(308, 3, 'Inés Carbajal', TIMESTAMP(@D0,'14:13:00'), TIMESTAMP(@D0,'15:24:00'), TIMESTAMP(@D0,'15:24:00'), 'cerrado', 'tarjeta', 108, @M3),
(309, 3, 'Jonás Ledesma', TIMESTAMP(@D0,'14:30:00'), TIMESTAMP(@D0,'15:48:00'), TIMESTAMP(@D0,'15:48:00'), 'cerrado', 'efectivo', 90, @M1),
(310, 2, 'Katia Berrones', TIMESTAMP(@D0,'15:20:00'), TIMESTAMP(@D0,'16:45:00'), TIMESTAMP(@D0,'16:45:00'), 'cerrado', 'tarjeta', 90, @M2),
(311, 2, 'Luis Toledo', TIMESTAMP(@D0,'18:41:00'), TIMESTAMP(@D0,'20:13:00'), TIMESTAMP(@D0,'20:13:00'), 'cerrado', 'tarjeta', 35, @M3),
(312, 2, 'Miriam Cuéllar', TIMESTAMP(@D0,'19:48:00'), TIMESTAMP(@D0,'21:27:00'), TIMESTAMP(@D0,'21:27:00'), 'cerrado', 'efectivo', 0, @M1),
(313, 3, 'Nicolás Arámbula', TIMESTAMP(@D0,'20:10:00'), TIMESTAMP(@D0,'21:56:00'), TIMESTAMP(@D0,'21:56:00'), 'cerrado', 'tarjeta', 116, @M2),
(314, 3, 'Odette Fierro', TIMESTAMP(@D0,'21:17:00'), TIMESTAMP(@D0,'22:09:00'), TIMESTAMP(@D0,'22:09:00'), 'cerrado', 'tarjeta', 169, @M3);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(300, 1, 1),
(301, 3, 1),
(302, 4, 1),
(303, 6, 1),
(304, 7, 1),
(305, 9, 1),
(306, 11, 1),
(307, 2, 1),
(308, 4, 1),
(309, 5, 1),
(310, 7, 1),
(311, 11, 1),
(312, 2, 1),
(313, 4, 1),
(314, 6, 1);

INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(300, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D0,'08:41:00')),
(300, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D0,'08:41:00')),
(300, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D0,'08:41:00')),
(301, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D0,'09:29:00')),
(301, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D0,'09:29:00')),
(302, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D0,'09:46:00')),
(302, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D0,'09:46:00')),
(303, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D0,'10:36:00')),
(303, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D0,'10:36:00')),
(304, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D0,'10:53:00')),
(304, 'Latte', 80.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D0,'10:53:00')),
(305, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D0,'11:43:00')),
(305, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D0,'11:43:00')),
(306, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D0,'12:50:00')),
(306, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D0,'12:50:00')),
(307, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D0,'13:12:00')),
(307, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D0,'13:12:00')),
(308, 'Milano', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D0,'14:19:00')),
(308, 'Margarita', 190.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D0,'14:19:00')),
(309, 'Milano', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D0,'14:36:00')),
(309, 'Margarita', 190.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D0,'14:36:00')),
(310, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', TIMESTAMP(@D0,'15:26:00')),
(310, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', TIMESTAMP(@D0,'15:26:00')),
(311, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D0,'18:47:00')),
(311, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D0,'18:47:00')),
(312, 'Burrata', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D0,'19:54:00')),
(312, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D0,'19:54:00')),
(313, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D0,'20:16:00')),
(313, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D0,'20:16:00')),
(313, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D0,'20:16:00')),
(314, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D0,'21:23:00')),
(314, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', TIMESTAMP(@D0,'21:23:00')),
(314, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D0,'21:23:00'));

-- Cuentas divididas del día: la suma de ticket_pagos.monto cubre el
-- consumo más la propina, que es lo que valida MapaController::cerrarTicket.
INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto) VALUES
(306, 1, 'tarjeta', 340.00),
(306, 2, 'efectivo', 340.00);

-- -------------------------------------------------------------------
-- MARTES 2026-08-04 (@D1) · 21 tickets
-- -------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago, propina, mesero_id) VALUES
(315, 2, 'Pablo Zepeda', TIMESTAMP(@D1,'08:35:00'), TIMESTAMP(@D1,'09:34:00'), TIMESTAMP(@D1,'09:34:00'), 'cerrado', 'efectivo', 76, @M1),
(316, 2, 'Regina Alfaro', TIMESTAMP(@D1,'09:23:00'), TIMESTAMP(@D1,'10:29:00'), TIMESTAMP(@D1,'10:29:00'), 'cerrado', 'tarjeta', 86, @M2),
(317, 2, 'Samuel Íñiguez', TIMESTAMP(@D1,'09:40:00'), TIMESTAMP(@D1,'10:53:00'), TIMESTAMP(@D1,'10:53:00'), 'cerrado', 'tarjeta', 59, @M3),
(318, 2, 'Tere Camarillo', TIMESTAMP(@D1,'10:30:00'), TIMESTAMP(@D1,'11:50:00'), TIMESTAMP(@D1,'11:50:00'), 'cerrado', 'efectivo', 73, @M1),
(319, 2, 'Uriel Bermúdez', TIMESTAMP(@D1,'10:47:00'), TIMESTAMP(@D1,'12:14:00'), TIMESTAMP(@D1,'12:14:00'), 'cerrado', 'tarjeta', 57, @M2),
(320, 2, 'Vania Loera', TIMESTAMP(@D1,'10:19:00'), TIMESTAMP(@D1,'11:53:00'), TIMESTAMP(@D1,'11:53:00'), 'cerrado', 'tarjeta', 61, @M3),
(321, 2, 'Wilfrido Anaya', TIMESTAMP(@D1,'11:37:00'), TIMESTAMP(@D1,'13:18:00'), TIMESTAMP(@D1,'13:18:00'), 'cerrado', 'efectivo', 58, @M1),
(322, 2, 'Ximena Duarte', TIMESTAMP(@D1,'11:09:00'), TIMESTAMP(@D1,'12:57:00'), TIMESTAMP(@D1,'12:57:00'), 'cerrado', 'tarjeta', 76, @M2),
(323, 2, 'Yolanda Prieto', TIMESTAMP(@D1,'12:44:00'), TIMESTAMP(@D1,'13:38:00'), TIMESTAMP(@D1,'13:38:00'), 'cerrado', 'dividido', 72, @M3),
(324, 3, 'Zacarías Beltrán', TIMESTAMP(@D1,'13:06:00'), TIMESTAMP(@D1,'14:07:00'), TIMESTAMP(@D1,'14:07:00'), 'cerrado', 'efectivo', 0, @M1),
(325, 2, 'Adriana Lozano', TIMESTAMP(@D1,'13:23:00'), TIMESTAMP(@D1,'14:31:00'), TIMESTAMP(@D1,'14:31:00'), 'cerrado', 'tarjeta', 76, @M2),
(326, 3, 'Beto Nájera', TIMESTAMP(@D1,'14:13:00'), TIMESTAMP(@D1,'15:28:00'), TIMESTAMP(@D1,'15:28:00'), 'cerrado', 'tarjeta', 132, @M3),
(327, 2, 'Cecilia Ynzunza', TIMESTAMP(@D1,'14:30:00'), TIMESTAMP(@D1,'15:52:00'), TIMESTAMP(@D1,'15:52:00'), 'cerrado', 'efectivo', 68, @M1),
(328, 3, 'Damián Portillo', TIMESTAMP(@D1,'15:20:00'), TIMESTAMP(@D1,'16:49:00'), TIMESTAMP(@D1,'16:49:00'), 'cerrado', 'tarjeta', 135, @M2),
(329, 2, 'Estela Guardado', TIMESTAMP(@D1,'17:34:00'), TIMESTAMP(@D1,'19:10:00'), TIMESTAMP(@D1,'19:10:00'), 'cerrado', 'tarjeta', 44, @M3),
(330, 2, 'Fausto Rivas', TIMESTAMP(@D1,'18:41:00'), TIMESTAMP(@D1,'20:24:00'), TIMESTAMP(@D1,'20:24:00'), 'cerrado', 'efectivo', 30, @M1),
(331, 2, 'Gael Ruan', TIMESTAMP(@D1,'19:48:00'), TIMESTAMP(@D1,'21:38:00'), TIMESTAMP(@D1,'21:38:00'), 'cerrado', 'tarjeta', 82, @M2),
(332, 3, 'Héctor Salcedo', TIMESTAMP(@D1,'20:10:00'), TIMESTAMP(@D1,'21:06:00'), TIMESTAMP(@D1,'21:06:00'), 'cerrado', 'tarjeta', 139, @M3),
(333, 3, 'Irene Bustos', TIMESTAMP(@D1,'20:27:00'), TIMESTAMP(@D1,'21:30:00'), TIMESTAMP(@D1,'21:30:00'), 'cerrado', 'efectivo', 110, @M1),
(334, 2, 'Joaquín Nieto', TIMESTAMP(@D1,'21:17:00'), TIMESTAMP(@D1,'22:27:00'), TIMESTAMP(@D1,'22:27:00'), 'cerrado', 'tarjeta', 179, @M2),
(335, 2, 'Karla Villalobos', TIMESTAMP(@D1,'21:34:00'), TIMESTAMP(@D1,'22:51:00'), TIMESTAMP(@D1,'22:51:00'), 'cerrado', 'tarjeta', 106, @M3);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(315, 5, 1),
(316, 7, 1),
(317, 8, 1),
(318, 10, 1),
(319, 11, 1),
(320, 1, 1),
(321, 3, 1),
(322, 4, 1),
(323, 6, 1),
(324, 8, 1),
(325, 9, 1),
(326, 11, 1),
(327, 1, 1),
(328, 3, 1),
(329, 6, 1),
(330, 8, 1),
(331, 10, 1),
(332, 1, 1),
(333, 2, 1),
(334, 4, 1),
(335, 5, 1);

INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(315, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'08:41:00')),
(315, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D1,'08:41:00')),
(316, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'09:29:00')),
(316, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D1,'09:29:00')),
(316, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D1,'09:29:00')),
(317, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'09:46:00')),
(317, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D1,'09:46:00')),
(318, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'10:36:00')),
(318, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D1,'10:36:00')),
(319, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'10:53:00')),
(319, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D1,'10:53:00')),
(319, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D1,'10:53:00')),
(320, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'10:25:00')),
(320, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D1,'10:25:00')),
(321, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'11:43:00')),
(321, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D1,'11:43:00')),
(322, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D1,'11:15:00')),
(322, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D1,'11:15:00')),
(323, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', TIMESTAMP(@D1,'12:50:00')),
(323, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', TIMESTAMP(@D1,'12:50:00')),
(324, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D1,'13:12:00')),
(324, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D1,'13:12:00')),
(324, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D1,'13:12:00')),
(325, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D1,'13:29:00')),
(325, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D1,'13:29:00')),
(325, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D1,'13:29:00')),
(326, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D1,'14:19:00')),
(326, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D1,'14:19:00')),
(327, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D1,'14:36:00')),
(327, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D1,'14:36:00')),
(328, 'Milano', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D1,'15:26:00')),
(328, 'Margarita', 190.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D1,'15:26:00')),
(328, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'cancelado', TIMESTAMP(@D1,'15:26:00')),
(329, 'Latte', 80.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D1,'17:40:00')),
(329, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 1, 'entregado', TIMESTAMP(@D1,'17:40:00')),
(330, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D1,'18:47:00')),
(330, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D1,'18:47:00')),
(331, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D1,'19:54:00')),
(331, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', TIMESTAMP(@D1,'19:54:00')),
(332, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D1,'20:16:00')),
(332, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D1,'20:16:00')),
(333, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D1,'20:33:00')),
(333, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D1,'20:33:00')),
(334, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 1, 'entregado', TIMESTAMP(@D1,'21:23:00')),
(334, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D1,'21:23:00')),
(334, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D1,'21:23:00')),
(335, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D1,'21:40:00')),
(335, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D1,'21:40:00')),
(335, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D1,'21:40:00'));

-- Cuentas divididas del día: la suma de ticket_pagos.monto cubre el
-- consumo más la propina, que es lo que valida MapaController::cerrarTicket.
INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto) VALUES
(323, 1, 'tarjeta', 336.00),
(323, 2, 'efectivo', 336.00);

-- -------------------------------------------------------------------
-- MIÉRCOLES 2026-08-05 (@D2) · 24 tickets
-- -------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago, propina, mesero_id) VALUES
(336, 2, 'Lía Ordaz', TIMESTAMP(@D2,'08:35:00'), TIMESTAMP(@D2,'09:59:00'), TIMESTAMP(@D2,'09:59:00'), 'cerrado', 'efectivo', 0, @M1),
(337, 2, 'Mar Cueto', TIMESTAMP(@D2,'09:23:00'), TIMESTAMP(@D2,'10:54:00'), TIMESTAMP(@D2,'10:54:00'), 'cerrado', 'tarjeta', 56, @M2),
(338, 2, 'Noa Cid', TIMESTAMP(@D2,'09:40:00'), TIMESTAMP(@D2,'11:18:00'), TIMESTAMP(@D2,'11:18:00'), 'cerrado', 'tarjeta', 61, @M3),
(339, 2, 'Ori Lozano', TIMESTAMP(@D2,'09:12:00'), TIMESTAMP(@D2,'10:57:00'), TIMESTAMP(@D2,'10:57:00'), 'cerrado', 'efectivo', 76, @M1),
(340, 2, 'Paula Rivas', TIMESTAMP(@D2,'10:30:00'), TIMESTAMP(@D2,'11:21:00'), TIMESTAMP(@D2,'11:21:00'), 'cerrado', 'dividido', 27, @M2),
(341, 2, 'Quirino Ávila', TIMESTAMP(@D2,'10:47:00'), TIMESTAMP(@D2,'11:45:00'), TIMESTAMP(@D2,'11:45:00'), 'cerrado', 'tarjeta', 53, @M3),
(342, 2, 'Rita Peña', TIMESTAMP(@D2,'10:19:00'), TIMESTAMP(@D2,'11:24:00'), TIMESTAMP(@D2,'11:24:00'), 'cerrado', 'efectivo', 69, @M1),
(343, 2, 'Sol Marín', TIMESTAMP(@D2,'11:37:00'), TIMESTAMP(@D2,'12:49:00'), TIMESTAMP(@D2,'12:49:00'), 'cerrado', 'tarjeta', 45, @M2),
(344, 2, 'Tono Gil', TIMESTAMP(@D2,'11:09:00'), TIMESTAMP(@D2,'12:28:00'), TIMESTAMP(@D2,'12:28:00'), 'cerrado', 'tarjeta', 59, @M3),
(345, 2, 'Ulises Nava', TIMESTAMP(@D2,'12:44:00'), TIMESTAMP(@D2,'14:10:00'), TIMESTAMP(@D2,'14:10:00'), 'cerrado', 'efectivo', 45, @M1),
(346, 2, 'Vera Luna', TIMESTAMP(@D2,'13:06:00'), TIMESTAMP(@D2,'14:39:00'), TIMESTAMP(@D2,'14:39:00'), 'cerrado', 'tarjeta', 102, @M2),
(347, 3, 'Wendy Fuentes', TIMESTAMP(@D2,'13:23:00'), TIMESTAMP(@D2,'15:03:00'), TIMESTAMP(@D2,'15:03:00'), 'cerrado', 'tarjeta', 151, @M3),
(348, 3, 'Xavier Peña', TIMESTAMP(@D2,'14:13:00'), TIMESTAMP(@D2,'16:00:00'), TIMESTAMP(@D2,'16:00:00'), 'cerrado', 'efectivo', 0, @M1),
(349, 3, 'Yara Sol', TIMESTAMP(@D2,'14:30:00'), TIMESTAMP(@D2,'15:23:00'), TIMESTAMP(@D2,'15:23:00'), 'cerrado', 'tarjeta', 99, @M2),
(350, 2, 'Grupo Lara', TIMESTAMP(@D2,'14:47:00'), TIMESTAMP(@D2,'15:47:00'), TIMESTAMP(@D2,'15:47:00'), 'cerrado', 'tarjeta', 72, @M3),
(351, 2, 'Grupo Cano', TIMESTAMP(@D2,'15:20:00'), TIMESTAMP(@D2,'16:27:00'), TIMESTAMP(@D2,'16:27:00'), 'cerrado', 'efectivo', 108, @M1),
(352, 2, 'Grupo Ibarra', TIMESTAMP(@D2,'17:34:00'), TIMESTAMP(@D2,'18:48:00'), TIMESTAMP(@D2,'18:48:00'), 'cerrado', 'tarjeta', 38, @M2),
(353, 2, 'Grupo Villa', TIMESTAMP(@D2,'18:41:00'), TIMESTAMP(@D2,'20:02:00'), TIMESTAMP(@D2,'20:02:00'), 'cerrado', 'tarjeta', 30, @M3),
(354, 3, 'Grupo Sáez', TIMESTAMP(@D2,'19:48:00'), TIMESTAMP(@D2,'21:16:00'), TIMESTAMP(@D2,'21:16:00'), 'cerrado', 'efectivo', 158, @M1),
(355, 2, 'Familia Nava', TIMESTAMP(@D2,'19:20:00'), TIMESTAMP(@D2,'20:55:00'), TIMESTAMP(@D2,'20:55:00'), 'cerrado', 'tarjeta', 83, @M2),
(356, 3, 'Familia Robles', TIMESTAMP(@D2,'20:10:00'), TIMESTAMP(@D2,'21:52:00'), TIMESTAMP(@D2,'21:52:00'), 'cerrado', 'tarjeta', 168, @M3),
(357, 3, 'Familia Prado', TIMESTAMP(@D2,'20:27:00'), TIMESTAMP(@D2,'22:16:00'), TIMESTAMP(@D2,'22:16:00'), 'cerrado', 'dividido', 198, @M1),
(358, 2, 'Familia Mena', TIMESTAMP(@D2,'21:17:00'), TIMESTAMP(@D2,'22:12:00'), TIMESTAMP(@D2,'22:12:00'), 'cerrado', 'tarjeta', 125, @M2),
(359, 2, 'Familia Cruz', TIMESTAMP(@D2,'21:34:00'), TIMESTAMP(@D2,'22:36:00'), TIMESTAMP(@D2,'22:36:00'), 'cerrado', 'tarjeta', 95, @M3);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(336, 4, 1),
(337, 6, 1),
(338, 7, 1),
(339, 8, 1),
(340, 10, 1),
(341, 11, 1),
(342, 1, 1),
(343, 3, 1),
(344, 4, 1),
(345, 6, 1),
(346, 8, 1),
(347, 9, 1),
(348, 11, 1),
(349, 1, 1),
(350, 2, 1),
(351, 4, 1),
(352, 7, 1),
(353, 9, 1),
(354, 11, 1),
(355, 1, 1),
(356, 3, 1),
(357, 4, 1),
(358, 6, 1),
(359, 7, 1);

INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(336, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'08:41:00')),
(336, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D2,'08:41:00')),
(337, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'09:29:00')),
(337, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D2,'09:29:00')),
(337, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D2,'09:29:00')),
(338, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'09:46:00')),
(338, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D2,'09:46:00')),
(339, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'09:18:00')),
(339, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D2,'09:18:00')),
(340, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D2,'10:36:00')),
(340, 'Latte', 80.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D2,'10:36:00')),
(341, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'10:53:00')),
(341, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D2,'10:53:00')),
(342, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'10:25:00')),
(342, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D2,'10:25:00')),
(343, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'11:43:00')),
(343, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D2,'11:43:00')),
(344, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D2,'11:15:00')),
(344, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D2,'11:15:00')),
(345, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D2,'12:50:00')),
(345, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D2,'12:50:00')),
(346, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D2,'13:12:00')),
(346, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D2,'13:12:00')),
(347, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D2,'13:29:00')),
(347, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D2,'13:29:00')),
(347, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D2,'13:29:00')),
(348, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D2,'14:19:00')),
(348, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D2,'14:19:00')),
(349, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 3, 'entregado', TIMESTAMP(@D2,'14:36:00')),
(349, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', TIMESTAMP(@D2,'14:36:00')),
(350, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', TIMESTAMP(@D2,'14:53:00')),
(350, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', TIMESTAMP(@D2,'14:53:00')),
(351, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D2,'15:26:00')),
(351, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D2,'15:26:00')),
(352, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D2,'17:40:00')),
(352, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D2,'17:40:00')),
(353, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D2,'18:47:00')),
(353, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D2,'18:47:00')),
(354, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D2,'19:54:00')),
(354, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', TIMESTAMP(@D2,'19:54:00')),
(355, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D2,'19:26:00')),
(355, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D2,'19:26:00')),
(355, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D2,'19:26:00')),
(356, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D2,'20:16:00')),
(356, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D2,'20:16:00')),
(356, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D2,'20:16:00')),
(356, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D2,'20:16:00')),
(357, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D2,'20:33:00')),
(357, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', TIMESTAMP(@D2,'20:33:00')),
(358, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D2,'21:23:00')),
(358, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D2,'21:23:00')),
(358, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D2,'21:23:00')),
(359, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D2,'21:40:00')),
(359, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D2,'21:40:00')),
(359, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D2,'21:40:00'));

-- Cuentas divididas del día: la suma de ticket_pagos.monto cubre el
-- consumo más la propina, que es lo que valida MapaController::cerrarTicket.
INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto) VALUES
(340, 1, 'tarjeta', 103.50),
(340, 2, 'efectivo', 103.50),
(357, 1, 'tarjeta', 759.00),
(357, 2, 'efectivo', 759.00);

-- -------------------------------------------------------------------
-- JUEVES 2026-08-06 (@D3) · 29 tickets
-- -------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago, propina, mesero_id) VALUES
(360, 2, 'Ana Rueda', TIMESTAMP(@D3,'08:35:00'), TIMESTAMP(@D3,'09:44:00'), TIMESTAMP(@D3,'09:44:00'), 'cerrado', 'efectivo', 0, @M1),
(361, 2, 'Bruno Salas', TIMESTAMP(@D3,'09:23:00'), TIMESTAMP(@D3,'10:39:00'), TIMESTAMP(@D3,'10:39:00'), 'cerrado', 'tarjeta', 58, @M2),
(362, 2, 'Carla Ibáñez', TIMESTAMP(@D3,'09:40:00'), TIMESTAMP(@D3,'11:03:00'), TIMESTAMP(@D3,'11:03:00'), 'cerrado', 'tarjeta', 53, @M3),
(363, 2, 'Diego Nava', TIMESTAMP(@D3,'09:12:00'), TIMESTAMP(@D3,'10:42:00'), TIMESTAMP(@D3,'10:42:00'), 'cerrado', 'efectivo', 92, @M1),
(364, 2, 'Elena Ferrer', TIMESTAMP(@D3,'10:30:00'), TIMESTAMP(@D3,'12:07:00'), TIMESTAMP(@D3,'12:07:00'), 'cerrado', 'tarjeta', 86, @M2),
(365, 2, 'Fabián Ortuño', TIMESTAMP(@D3,'10:47:00'), TIMESTAMP(@D3,'12:31:00'), TIMESTAMP(@D3,'12:31:00'), 'cerrado', 'tarjeta', 73, @M3),
(366, 2, 'Gina Palomares', TIMESTAMP(@D3,'10:19:00'), TIMESTAMP(@D3,'11:09:00'), TIMESTAMP(@D3,'11:09:00'), 'cerrado', 'efectivo', 69, @M1),
(367, 2, 'Hugo Villaseñor', TIMESTAMP(@D3,'11:37:00'), TIMESTAMP(@D3,'12:34:00'), TIMESTAMP(@D3,'12:34:00'), 'cerrado', 'tarjeta', 45, @M2),
(368, 2, 'Inés Carbajal', TIMESTAMP(@D3,'11:09:00'), TIMESTAMP(@D3,'12:13:00'), TIMESTAMP(@D3,'12:13:00'), 'cerrado', 'tarjeta', 69, @M3),
(369, 2, 'Jonás Ledesma', TIMESTAMP(@D3,'12:44:00'), TIMESTAMP(@D3,'13:55:00'), TIMESTAMP(@D3,'13:55:00'), 'cerrado', 'efectivo', 45, @M1),
(370, 3, 'Katia Berrones', TIMESTAMP(@D3,'13:06:00'), TIMESTAMP(@D3,'14:24:00'), TIMESTAMP(@D3,'14:24:00'), 'cerrado', 'tarjeta', 178, @M2),
(371, 3, 'Luis Toledo', TIMESTAMP(@D3,'13:23:00'), TIMESTAMP(@D3,'14:48:00'), TIMESTAMP(@D3,'14:48:00'), 'cerrado', 'tarjeta', 139, @M3),
(372, 2, 'Miriam Cuéllar', TIMESTAMP(@D3,'13:40:00'), TIMESTAMP(@D3,'15:12:00'), TIMESTAMP(@D3,'15:12:00'), 'cerrado', 'efectivo', 0, @M1),
(373, 2, 'Nicolás Arámbula', TIMESTAMP(@D3,'14:13:00'), TIMESTAMP(@D3,'15:52:00'), TIMESTAMP(@D3,'15:52:00'), 'cerrado', 'tarjeta', 69, @M2),
(374, 3, 'Odette Fierro', TIMESTAMP(@D3,'14:30:00'), TIMESTAMP(@D3,'16:16:00'), TIMESTAMP(@D3,'16:16:00'), 'cerrado', 'dividido', 119, @M3),
(375, 3, 'Pablo Zepeda', TIMESTAMP(@D3,'14:47:00'), TIMESTAMP(@D3,'15:39:00'), TIMESTAMP(@D3,'15:39:00'), 'cerrado', 'efectivo', 165, @M1),
(376, 3, 'Regina Alfaro', TIMESTAMP(@D3,'15:20:00'), TIMESTAMP(@D3,'16:19:00'), TIMESTAMP(@D3,'16:19:00'), 'cerrado', 'tarjeta', 185, @M2),
(377, 3, 'Samuel Íñiguez', TIMESTAMP(@D3,'15:37:00'), TIMESTAMP(@D3,'16:43:00'), TIMESTAMP(@D3,'16:43:00'), 'cerrado', 'tarjeta', 132, @M3),
(378, 2, 'Tere Camarillo', TIMESTAMP(@D3,'16:27:00'), TIMESTAMP(@D3,'17:40:00'), TIMESTAMP(@D3,'17:40:00'), 'cerrado', 'efectivo', 44, @M1),
(379, 2, 'Uriel Bermúdez', TIMESTAMP(@D3,'17:34:00'), TIMESTAMP(@D3,'18:54:00'), TIMESTAMP(@D3,'18:54:00'), 'cerrado', 'tarjeta', 29, @M2),
(380, 2, 'Vania Loera', TIMESTAMP(@D3,'18:41:00'), TIMESTAMP(@D3,'20:08:00'), TIMESTAMP(@D3,'20:08:00'), 'cerrado', 'tarjeta', 35, @M3),
(381, 2, 'Wilfrido Anaya', TIMESTAMP(@D3,'19:48:00'), TIMESTAMP(@D3,'21:22:00'), TIMESTAMP(@D3,'21:22:00'), 'cerrado', 'efectivo', 59, @M1),
(382, 2, 'Ximena Duarte', TIMESTAMP(@D3,'19:20:00'), TIMESTAMP(@D3,'21:01:00'), TIMESTAMP(@D3,'21:01:00'), 'cerrado', 'tarjeta', 108, @M2),
(383, 3, 'Yolanda Prieto', TIMESTAMP(@D3,'20:10:00'), TIMESTAMP(@D3,'21:58:00'), TIMESTAMP(@D3,'21:58:00'), 'cerrado', 'tarjeta', 159, @M3),
(384, 2, 'Zacarías Beltrán', TIMESTAMP(@D3,'20:27:00'), TIMESTAMP(@D3,'21:21:00'), TIMESTAMP(@D3,'21:21:00'), 'cerrado', 'efectivo', 0, @M1),
(385, 3, 'Adriana Lozano', TIMESTAMP(@D3,'20:44:00'), TIMESTAMP(@D3,'21:45:00'), TIMESTAMP(@D3,'21:45:00'), 'cerrado', 'tarjeta', 198, @M2),
(386, 3, 'Beto Nájera', TIMESTAMP(@D3,'21:17:00'), TIMESTAMP(@D3,'22:25:00'), TIMESTAMP(@D3,'22:25:00'), 'cerrado', 'tarjeta', 125, @M3),
(387, 3, 'Cecilia Ynzunza', TIMESTAMP(@D3,'21:34:00'), TIMESTAMP(@D3,'22:49:00'), TIMESTAMP(@D3,'22:49:00'), 'cerrado', 'efectivo', 199, @M1),
(388, 2, 'Damián Portillo', TIMESTAMP(@D3,'21:06:00'), TIMESTAMP(@D3,'22:28:00'), TIMESTAMP(@D3,'22:28:00'), 'cerrado', 'tarjeta', 166, @M2);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(360, 6, 1),
(361, 8, 1),
(362, 9, 1),
(363, 10, 1),
(364, 1, 1),
(365, 2, 1),
(366, 3, 1),
(367, 5, 1),
(368, 6, 1),
(369, 8, 1),
(370, 10, 1),
(371, 11, 1),
(372, 1, 1),
(373, 3, 1),
(374, 4, 1),
(375, 5, 1),
(376, 7, 1),
(377, 8, 1),
(378, 10, 1),
(379, 1, 1),
(380, 3, 1),
(381, 5, 1),
(382, 6, 1),
(383, 8, 1),
(384, 9, 1),
(385, 10, 1),
(386, 1, 1),
(387, 2, 1),
(388, 3, 1);

INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(360, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'08:41:00')),
(360, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D3,'08:41:00')),
(361, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'09:29:00')),
(361, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D3,'09:29:00')),
(361, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'09:29:00')),
(362, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'09:46:00')),
(362, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'09:46:00')),
(363, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'09:18:00')),
(363, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D3,'09:18:00')),
(364, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'10:36:00')),
(364, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'10:36:00')),
(365, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'10:53:00')),
(365, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D3,'10:53:00')),
(366, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'10:25:00')),
(366, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'10:25:00')),
(367, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'11:43:00')),
(367, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'11:43:00')),
(368, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D3,'11:15:00')),
(368, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'11:15:00')),
(369, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D3,'12:50:00')),
(369, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D3,'12:50:00')),
(369, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'cancelado', TIMESTAMP(@D3,'12:50:00')),
(370, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D3,'13:12:00')),
(370, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D3,'13:12:00')),
(371, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D3,'13:29:00')),
(371, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D3,'13:29:00')),
(372, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D3,'13:46:00')),
(372, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D3,'13:46:00')),
(373, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', TIMESTAMP(@D3,'14:19:00')),
(373, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', TIMESTAMP(@D3,'14:19:00')),
(373, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'14:19:00')),
(374, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 3, 'entregado', TIMESTAMP(@D3,'14:36:00')),
(374, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', TIMESTAMP(@D3,'14:36:00')),
(375, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D3,'14:53:00')),
(375, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D3,'14:53:00')),
(376, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D3,'15:26:00')),
(376, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D3,'15:26:00')),
(376, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'15:26:00')),
(377, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D3,'15:43:00')),
(377, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D3,'15:43:00')),
(378, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D3,'16:33:00')),
(378, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D3,'16:33:00')),
(378, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D3,'16:33:00')),
(379, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D3,'17:40:00')),
(379, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D3,'17:40:00')),
(380, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D3,'18:47:00')),
(380, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D3,'18:47:00')),
(381, 'Burrata', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D3,'19:54:00')),
(381, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D3,'19:54:00')),
(381, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D3,'19:54:00')),
(382, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D3,'19:26:00')),
(382, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D3,'19:26:00')),
(383, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D3,'20:16:00')),
(383, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D3,'20:16:00')),
(383, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D3,'20:16:00')),
(384, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D3,'20:33:00')),
(384, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D3,'20:33:00')),
(385, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D3,'20:50:00')),
(385, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D3,'20:50:00')),
(385, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D3,'20:50:00')),
(386, 'Burrata', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D3,'21:23:00')),
(386, 'Milano', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D3,'21:23:00')),
(387, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D3,'21:40:00')),
(387, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D3,'21:40:00')),
(387, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D3,'21:40:00')),
(388, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 1, 'entregado', TIMESTAMP(@D3,'21:12:00')),
(388, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D3,'21:12:00'));

-- Cuentas divididas del día: la suma de ticket_pagos.monto cubre el
-- consumo más la propina, que es lo que valida MapaController::cerrarTicket.
INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto) VALUES
(374, 1, 'tarjeta', 554.50),
(374, 2, 'efectivo', 554.50);

-- -------------------------------------------------------------------
-- VIERNES 2026-08-07 (@D4) · 35 tickets
-- -------------------------------------------------------------------
INSERT INTO tickets (id, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago, propina, mesero_id) VALUES
(389, 2, 'Estela Guardado', TIMESTAMP(@D4,'08:35:00'), TIMESTAMP(@D4,'10:04:00'), TIMESTAMP(@D4,'10:04:00'), 'cerrado', 'tarjeta', 62, @M3),
(390, 2, 'Fausto Rivas', TIMESTAMP(@D4,'08:42:00'), TIMESTAMP(@D4,'10:18:00'), TIMESTAMP(@D4,'10:18:00'), 'cerrado', 'efectivo', 69, @M1),
(391, 2, 'Gael Ruan', TIMESTAMP(@D4,'09:23:00'), TIMESTAMP(@D4,'11:06:00'), TIMESTAMP(@D4,'11:06:00'), 'cerrado', 'dividido', 65, @M2),
(392, 2, 'Héctor Salcedo', TIMESTAMP(@D4,'09:40:00'), TIMESTAMP(@D4,'11:30:00'), TIMESTAMP(@D4,'11:30:00'), 'cerrado', 'tarjeta', 61, @M3),
(393, 2, 'Irene Bustos', TIMESTAMP(@D4,'09:12:00'), TIMESTAMP(@D4,'10:08:00'), TIMESTAMP(@D4,'10:08:00'), 'cerrado', 'efectivo', 52, @M1),
(394, 2, 'Joaquín Nieto', TIMESTAMP(@D4,'10:30:00'), TIMESTAMP(@D4,'11:33:00'), TIMESTAMP(@D4,'11:33:00'), 'cerrado', 'tarjeta', 67, @M2),
(395, 2, 'Karla Villalobos', TIMESTAMP(@D4,'10:47:00'), TIMESTAMP(@D4,'11:57:00'), TIMESTAMP(@D4,'11:57:00'), 'cerrado', 'tarjeta', 59, @M3),
(396, 2, 'Lía Ordaz', TIMESTAMP(@D4,'10:19:00'), TIMESTAMP(@D4,'11:36:00'), TIMESTAMP(@D4,'11:36:00'), 'cerrado', 'efectivo', 0, @M1),
(397, 2, 'Mar Cueto', TIMESTAMP(@D4,'10:36:00'), TIMESTAMP(@D4,'12:00:00'), TIMESTAMP(@D4,'12:00:00'), 'cerrado', 'tarjeta', 18, @M2),
(398, 2, 'Noa Cid', TIMESTAMP(@D4,'11:37:00'), TIMESTAMP(@D4,'13:08:00'), TIMESTAMP(@D4,'13:08:00'), 'cerrado', 'tarjeta', 82, @M3),
(399, 2, 'Ori Lozano', TIMESTAMP(@D4,'11:09:00'), TIMESTAMP(@D4,'12:47:00'), TIMESTAMP(@D4,'12:47:00'), 'cerrado', 'efectivo', 67, @M1),
(400, 2, 'Paula Rivas', TIMESTAMP(@D4,'12:44:00'), TIMESTAMP(@D4,'14:29:00'), TIMESTAMP(@D4,'14:29:00'), 'cerrado', 'tarjeta', 90, @M2),
(401, 3, 'Quirino Ávila', TIMESTAMP(@D4,'13:06:00'), TIMESTAMP(@D4,'13:57:00'), TIMESTAMP(@D4,'13:57:00'), 'cerrado', 'tarjeta', 88, @M3),
(402, 3, 'Rita Peña', TIMESTAMP(@D4,'13:23:00'), TIMESTAMP(@D4,'14:21:00'), TIMESTAMP(@D4,'14:21:00'), 'cerrado', 'efectivo', 132, @M1),
(403, 2, 'Sol Marín', TIMESTAMP(@D4,'13:40:00'), TIMESTAMP(@D4,'14:45:00'), TIMESTAMP(@D4,'14:45:00'), 'cerrado', 'tarjeta', 79, @M2),
(404, 2, 'Tono Gil', TIMESTAMP(@D4,'14:13:00'), TIMESTAMP(@D4,'15:25:00'), TIMESTAMP(@D4,'15:25:00'), 'cerrado', 'tarjeta', 72, @M3),
(405, 2, 'Ulises Nava', TIMESTAMP(@D4,'14:30:00'), TIMESTAMP(@D4,'15:49:00'), TIMESTAMP(@D4,'15:49:00'), 'cerrado', 'efectivo', 72, @M1),
(406, 2, 'Vera Luna', TIMESTAMP(@D4,'14:47:00'), TIMESTAMP(@D4,'16:13:00'), TIMESTAMP(@D4,'16:13:00'), 'cerrado', 'tarjeta', 102, @M2),
(407, 2, 'Wendy Fuentes', TIMESTAMP(@D4,'14:19:00'), TIMESTAMP(@D4,'15:52:00'), TIMESTAMP(@D4,'15:52:00'), 'cerrado', 'tarjeta', 64, @M3),
(408, 2, 'Xavier Peña', TIMESTAMP(@D4,'15:20:00'), TIMESTAMP(@D4,'17:00:00'), TIMESTAMP(@D4,'17:00:00'), 'cerrado', 'dividido', 0, @M1),
(409, 3, 'Yara Sol', TIMESTAMP(@D4,'15:37:00'), TIMESTAMP(@D4,'17:24:00'), TIMESTAMP(@D4,'17:24:00'), 'cerrado', 'tarjeta', 106, @M2),
(410, 2, 'Grupo Lara', TIMESTAMP(@D4,'16:27:00'), TIMESTAMP(@D4,'17:20:00'), TIMESTAMP(@D4,'17:20:00'), 'cerrado', 'tarjeta', 35, @M3),
(411, 2, 'Grupo Cano', TIMESTAMP(@D4,'17:34:00'), TIMESTAMP(@D4,'18:34:00'), TIMESTAMP(@D4,'18:34:00'), 'cerrado', 'efectivo', 49, @M1),
(412, 2, 'Grupo Ibarra', TIMESTAMP(@D4,'18:41:00'), TIMESTAMP(@D4,'19:48:00'), TIMESTAMP(@D4,'19:48:00'), 'cerrado', 'tarjeta', 53, @M2),
(413, 2, 'Grupo Villa', TIMESTAMP(@D4,'18:13:00'), TIMESTAMP(@D4,'19:27:00'), TIMESTAMP(@D4,'19:27:00'), 'cerrado', 'tarjeta', 35, @M3),
(414, 2, 'Grupo Sáez', TIMESTAMP(@D4,'19:48:00'), TIMESTAMP(@D4,'21:09:00'), TIMESTAMP(@D4,'21:09:00'), 'cerrado', 'efectivo', 62, @M1),
(415, 3, 'Familia Nava', TIMESTAMP(@D4,'19:20:00'), TIMESTAMP(@D4,'20:48:00'), TIMESTAMP(@D4,'20:48:00'), 'cerrado', 'tarjeta', 110, @M2),
(416, 4, 'Familia Robles', TIMESTAMP(@D4,'20:10:00'), TIMESTAMP(@D4,'21:45:00'), TIMESTAMP(@D4,'21:45:00'), 'cerrado', 'tarjeta', 227, @M3),
(417, 4, 'Familia Prado', TIMESTAMP(@D4,'20:27:00'), TIMESTAMP(@D4,'22:09:00'), TIMESTAMP(@D4,'22:09:00'), 'cerrado', 'efectivo', 144, @M1),
(418, 4, 'Familia Mena', TIMESTAMP(@D4,'20:44:00'), TIMESTAMP(@D4,'22:33:00'), TIMESTAMP(@D4,'22:33:00'), 'cerrado', 'tarjeta', 284, @M2),
(419, 4, 'Familia Cruz', TIMESTAMP(@D4,'20:16:00'), TIMESTAMP(@D4,'21:11:00'), TIMESTAMP(@D4,'21:11:00'), 'cerrado', 'tarjeta', 181, @M3),
(420, 4, 'Ana Rueda', TIMESTAMP(@D4,'21:17:00'), TIMESTAMP(@D4,'22:19:00'), TIMESTAMP(@D4,'22:19:00'), 'cerrado', 'efectivo', 0, @M1),
(421, 3, 'Bruno Salas', TIMESTAMP(@D4,'21:34:00'), TIMESTAMP(@D4,'22:43:00'), TIMESTAMP(@D4,'22:43:00'), 'cerrado', 'tarjeta', 133, @M2),
(422, 4, 'Carla Ibáñez', TIMESTAMP(@D4,'21:06:00'), TIMESTAMP(@D4,'22:22:00'), TIMESTAMP(@D4,'22:22:00'), 'cerrado', 'tarjeta', 199, @M3),
(423, 3, 'Diego Nava', TIMESTAMP(@D4,'21:23:00'), TIMESTAMP(@D4,'22:46:00'), TIMESTAMP(@D4,'22:46:00'), 'cerrado', 'efectivo', 174, @M1);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
(389, 2, 1),
(390, 3, 1),
(391, 5, 1),
(392, 6, 1),
(393, 7, 1),
(394, 9, 1),
(395, 10, 1),
(396, 11, 1),
(397, 1, 1),
(398, 3, 1),
(399, 4, 1),
(400, 6, 1),
(401, 8, 1),
(402, 9, 1),
(403, 10, 1),
(404, 1, 1),
(405, 2, 1),
(406, 3, 1),
(407, 4, 1),
(408, 6, 1),
(409, 7, 1),
(410, 9, 1),
(411, 11, 1),
(412, 2, 1),
(413, 3, 1),
(414, 5, 1),
(415, 6, 1),
(416, 8, 1),
(417, 9, 1),
(418, 10, 1),
(419, 11, 1),
(420, 2, 1),
(421, 3, 1),
(422, 4, 1),
(423, 5, 1);

INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado, created_at) VALUES
(389, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'08:41:00')),
(389, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'08:41:00')),
(389, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'08:41:00')),
(390, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'08:48:00')),
(390, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'08:48:00')),
(391, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'09:29:00')),
(391, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'09:29:00')),
(391, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'09:29:00')),
(392, 'Enchiladas Suizas', 220.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'09:46:00')),
(392, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'09:46:00')),
(393, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'09:18:00')),
(393, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'09:18:00')),
(393, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'09:18:00')),
(394, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'10:36:00')),
(394, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'10:36:00')),
(395, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'10:53:00')),
(395, 'Café de Olla', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D4,'10:53:00')),
(396, 'Enmoladas', 240.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'10:25:00')),
(396, 'Jugo Verde', 95.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'10:25:00')),
(397, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D4,'10:42:00')),
(397, 'Latte', 80.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'10:42:00')),
(398, 'Toast de Salmón Ahumado', 230.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'11:43:00')),
(398, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D4,'11:43:00')),
(398, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'11:43:00')),
(399, 'Chilaquiles', 180.00, 'Desayunos', 3, 2, 'entregado', TIMESTAMP(@D4,'11:15:00')),
(399, 'Jugo de Naranja', 85.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'11:15:00')),
(400, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', TIMESTAMP(@D4,'12:50:00')),
(400, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', TIMESTAMP(@D4,'12:50:00')),
(401, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D4,'13:12:00')),
(401, 'Burrata', 260.00, 'Pizzas', 4, 2, 'entregado', TIMESTAMP(@D4,'13:12:00')),
(402, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D4,'13:29:00')),
(402, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D4,'13:29:00')),
(403, 'Filete de Res en su Jugo', 320.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D4,'13:46:00')),
(403, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 2, 'entregado', TIMESTAMP(@D4,'13:46:00')),
(404, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 2, 'entregado', TIMESTAMP(@D4,'14:19:00')),
(404, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 1, 'entregado', TIMESTAMP(@D4,'14:19:00')),
(405, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 2, 'entregado', TIMESTAMP(@D4,'14:36:00')),
(405, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D4,'14:36:00')),
(406, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D4,'14:53:00')),
(406, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D4,'14:53:00')),
(407, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D4,'14:25:00')),
(407, 'Burrata', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D4,'14:25:00')),
(407, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'14:25:00')),
(408, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D4,'15:26:00')),
(408, 'Margarita', 190.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D4,'15:26:00')),
(409, 'Frutos Rojos', 210.00, 'Ensaladas', 3, 3, 'entregado', TIMESTAMP(@D4,'15:43:00')),
(409, 'Crema del Día', 180.00, 'Sopas & Cremas', 3, 2, 'entregado', TIMESTAMP(@D4,'15:43:00')),
(409, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'15:43:00')),
(410, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D4,'16:33:00')),
(410, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D4,'16:33:00')),
(410, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'cancelado', TIMESTAMP(@D4,'16:33:00')),
(411, 'Cappuccino', 75.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D4,'17:40:00')),
(411, 'Molletes', 100.00, 'Desayunos', 3, 1, 'entregado', TIMESTAMP(@D4,'17:40:00')),
(411, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'17:40:00')),
(412, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D4,'18:47:00')),
(412, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D4,'18:47:00')),
(412, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'18:47:00')),
(413, 'Café Americano', 65.00, 'Café & Bebidas', 1, 2, 'entregado', TIMESTAMP(@D4,'18:19:00')),
(413, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D4,'18:19:00')),
(414, 'Burrata', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D4,'19:54:00')),
(414, 'Milano', 260.00, 'Pizzas', 4, 1, 'entregado', TIMESTAMP(@D4,'19:54:00')),
(415, 'Salmón al Horno', 295.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D4,'19:26:00')),
(415, 'Aros de Calamar', 210.00, 'Entradas', 3, 1, 'entregado', TIMESTAMP(@D4,'19:26:00')),
(416, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D4,'20:16:00')),
(416, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D4,'20:16:00')),
(417, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 4, 'entregado', TIMESTAMP(@D4,'20:33:00')),
(417, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D4,'20:33:00')),
(418, 'Rib Eye (450 grs.)', 785.00, 'Platos Fuertes', 3, 2, 'entregado', TIMESTAMP(@D4,'20:50:00')),
(418, 'Tabla Mixta', 320.00, 'Para Picar', 3, 1, 'entregado', TIMESTAMP(@D4,'20:50:00')),
(419, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 4, 'entregado', TIMESTAMP(@D4,'20:22:00')),
(419, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D4,'20:22:00')),
(419, 'Café Americano', 65.00, 'Café & Bebidas', 1, 1, 'entregado', TIMESTAMP(@D4,'20:22:00')),
(420, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 4, 'entregado', TIMESTAMP(@D4,'21:23:00')),
(420, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D4,'21:23:00')),
(420, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 4, 'entregado', TIMESTAMP(@D4,'21:23:00')),
(420, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 1, 'entregado', TIMESTAMP(@D4,'21:23:00')),
(421, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 3, 'entregado', TIMESTAMP(@D4,'21:40:00')),
(421, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D4,'21:40:00')),
(421, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 3, 'entregado', TIMESTAMP(@D4,'21:40:00')),
(422, 'Hamburguesa de la Casa', 260.00, 'Platos Fuertes', 3, 4, 'entregado', TIMESTAMP(@D4,'21:12:00')),
(422, 'Papas a la Francesa con Parmesano', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D4,'21:12:00')),
(422, 'Limonada Natural', 75.00, 'Jugos & Smoothies', 2, 4, 'entregado', TIMESTAMP(@D4,'21:12:00')),
(423, 'Spaguetti a la Boloñesa', 280.00, 'Pastas', 3, 3, 'entregado', TIMESTAMP(@D4,'21:29:00')),
(423, 'Mix de 3 Brusquetas', 160.00, 'Para Picar', 3, 2, 'entregado', TIMESTAMP(@D4,'21:29:00'));

-- Cuentas divididas del día: la suma de ticket_pagos.monto cubre el
-- consumo más la propina, que es lo que valida MapaController::cerrarTicket.
INSERT INTO ticket_pagos (ticket_id, comensal, metodo_pago, monto) VALUES
(391, 1, 'tarjeta', 357.50),
(391, 2, 'efectivo', 357.50),
(408, 1, 'tarjeta', 225.00),
(408, 2, 'efectivo', 225.00);

-- ---------------------------------------------------------------------
-- Reservaciones de la misma semana.
--
-- Ninguna de las tres analíticas diagnósticas las usa; alimentan las
-- gráficas descriptivas de "reservaciones por día" y "por estado", que sin
-- ellas quedarían en cero justo en la semana que se está analizando.
-- created_at se siembra uno a tres días antes de la fecha reservada: esa
-- diferencia es la anticipación de reserva que documenta ANALITICAS.md §2.
--
-- La lista de columnas es la de ddl.sql. Este INSERT usaba antes cinco
-- columnas que la tabla no declara y fallaba sobre una base creada de cero;
-- se corrigió al fundir los fixtures (ver la cabecera del archivo).
-- ---------------------------------------------------------------------
INSERT INTO reservaciones
  (
   nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, estado, estado_changed_at, origen, request_token, created_at
  ) VALUES
  ('Gina Palomares', 'email', 'reserva.018@example.test', @D5, '13:00:00', 6, 'Cumpleaños', 'completada', TIMESTAMP(@D5,'13:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-018',
   TIMESTAMP(@D5,'13:00:00') - INTERVAL 1 DAY),
  ('Nicolás Arámbula', 'email', 'reserva.019@example.test', @D5, '20:00:00', 4, 'Carriola', 'completada', TIMESTAMP(@D5,'20:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-019',
   TIMESTAMP(@D5,'20:00:00') - INTERVAL 2 DAY),
  ('Vania Loera', 'email', 'reserva.020@example.test', @D5, '13:30:00', 2, 'Cliente frecuente', 'completada', TIMESTAMP(@D5,'13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-020',
   TIMESTAMP(@D5,'13:30:00') - INTERVAL 3 DAY),
  ('Cecilia Ynzunza', 'email', 'reserva.021@example.test', @D5, '13:00:00', 5, 'Alergia a nueces', 'no_show', TIMESTAMP(@D5,'13:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-021',
   TIMESTAMP(@D5,'13:00:00') - INTERVAL 1 DAY),
  ('Joaquín Nieto', 'email', 'reserva.022@example.test', @D5, '20:00:00', 3, 'Aniversario', 'completada', TIMESTAMP(@D5,'20:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-022',
   TIMESTAMP(@D5,'20:00:00') - INTERVAL 2 DAY),
  ('Quirino Ávila', 'email', 'reserva.023@example.test', @D5, '13:30:00', 6, 'Terraza si hay', 'cancelada', TIMESTAMP(@D5,'13:30:00') - INTERVAL 1 DAY, 'admin', 'fx-revpash-res-023',
   TIMESTAMP(@D5,'13:30:00') - INTERVAL 3 DAY),
  ('Xavier Peña', 'email', 'reserva.024@example.test', @D6, '13:00:00', 4, '', 'completada', TIMESTAMP(@D6,'13:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-024',
   TIMESTAMP(@D6,'13:00:00') - INTERVAL 1 DAY),
  ('Familia Nava', 'email', 'reserva.025@example.test', @D6, '20:00:00', 2, 'Mesa junto a ventana', 'completada', TIMESTAMP(@D6,'20:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-025',
   TIMESTAMP(@D6,'20:00:00') - INTERVAL 2 DAY),
  ('Carla Ibáñez', 'email', 'reserva.026@example.test', @D6, '13:30:00', 5, 'Cumpleaños', 'completada', TIMESTAMP(@D6,'13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-026',
   TIMESTAMP(@D6,'13:30:00') - INTERVAL 3 DAY),
  ('Jonás Ledesma', 'email', 'reserva.027@example.test', @D6, '13:00:00', 3, 'Carriola', 'completada', TIMESTAMP(@D6,'13:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-027',
   TIMESTAMP(@D6,'13:00:00') - INTERVAL 1 DAY),
  ('Hugo Villaseñor', 'email', 'reserva.001@example.test', @D0, '14:00:00', 5, 'Mesa junto a ventana', 'completada', TIMESTAMP(@D0,'14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-001',
   TIMESTAMP(@D0,'14:00:00') - INTERVAL 2 DAY),
  ('Odette Fierro', 'email', 'reserva.002@example.test', @D0, '21:00:00', 3, 'Cumpleaños', 'completada', TIMESTAMP(@D0,'21:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-002',
   TIMESTAMP(@D0,'21:00:00') - INTERVAL 3 DAY),
  ('Wilfrido Anaya', 'email', 'reserva.003@example.test', @D1, '21:00:00', 6, 'Carriola', 'completada', TIMESTAMP(@D1,'21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-003',
   TIMESTAMP(@D1,'21:00:00') - INTERVAL 1 DAY),
  ('Damián Portillo', 'email', 'reserva.004@example.test', @D1, '20:30:00', 4, 'Cliente frecuente', 'completada', TIMESTAMP(@D1,'20:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-004',
   TIMESTAMP(@D1,'20:30:00') - INTERVAL 2 DAY),
  ('Karla Villalobos', 'email', 'reserva.005@example.test', @D1, '14:00:00', 2, 'Alergia a nueces', 'no_show', TIMESTAMP(@D1,'14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-005',
   TIMESTAMP(@D1,'14:00:00') - INTERVAL 3 DAY),
  ('Rita Peña', 'email', 'reserva.006@example.test', @D2, '13:00:00', 5, 'Aniversario', 'completada', TIMESTAMP(@D2,'13:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-006',
   TIMESTAMP(@D2,'13:00:00') - INTERVAL 1 DAY),
  ('Yara Sol', 'email', 'reserva.007@example.test', @D2, '20:00:00', 3, 'Terraza si hay', 'cancelada', TIMESTAMP(@D2,'20:00:00') - INTERVAL 1 DAY, 'admin', 'fx-revpash-res-007',
   TIMESTAMP(@D2,'20:00:00') - INTERVAL 2 DAY),
  ('Familia Robles', 'email', 'reserva.008@example.test', @D2, '13:30:00', 6, '', 'completada', TIMESTAMP(@D2,'13:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-008',
   TIMESTAMP(@D2,'13:30:00') - INTERVAL 3 DAY),
  ('Diego Nava', 'email', 'reserva.009@example.test', @D3, '21:00:00', 4, 'Mesa junto a ventana', 'completada', TIMESTAMP(@D3,'21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-009',
   TIMESTAMP(@D3,'21:00:00') - INTERVAL 1 DAY),
  ('Katia Berrones', 'email', 'reserva.010@example.test', @D3, '20:30:00', 2, 'Cumpleaños', 'completada', TIMESTAMP(@D3,'20:30:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-010',
   TIMESTAMP(@D3,'20:30:00') - INTERVAL 2 DAY),
  ('Samuel Íñiguez', 'email', 'reserva.011@example.test', @D3, '14:00:00', 5, 'Carriola', 'completada', TIMESTAMP(@D3,'14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-011',
   TIMESTAMP(@D3,'14:00:00') - INTERVAL 3 DAY),
  ('Zacarías Beltrán', 'email', 'reserva.012@example.test', @D3, '21:00:00', 3, 'Cliente frecuente', 'completada', TIMESTAMP(@D3,'21:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-012',
   TIMESTAMP(@D3,'21:00:00') - INTERVAL 1 DAY),
  ('Gael Ruan', 'email', 'reserva.013@example.test', @D4, '14:00:00', 6, 'Alergia a nueces', 'no_show', TIMESTAMP(@D4,'14:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-013',
   TIMESTAMP(@D4,'14:00:00') - INTERVAL 2 DAY),
  ('Noa Cid', 'email', 'reserva.014@example.test', @D4, '21:00:00', 4, 'Aniversario', 'completada', TIMESTAMP(@D4,'21:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-014',
   TIMESTAMP(@D4,'21:00:00') - INTERVAL 3 DAY),
  ('Ulises Nava', 'email', 'reserva.015@example.test', @D4, '20:30:00', 2, 'Terraza si hay', 'cancelada', TIMESTAMP(@D4,'20:30:00') - INTERVAL 1 DAY, 'admin', 'fx-revpash-res-015',
   TIMESTAMP(@D4,'20:30:00') - INTERVAL 1 DAY),
  ('Grupo Ibarra', 'email', 'reserva.016@example.test', @D4, '14:00:00', 5, '', 'completada', TIMESTAMP(@D4,'14:00:00') + INTERVAL 120 MINUTE, 'landing', 'fx-revpash-res-016',
   TIMESTAMP(@D4,'14:00:00') - INTERVAL 2 DAY),
  ('Familia Cruz', 'email', 'reserva.017@example.test', @D4, '21:00:00', 3, 'Mesa junto a ventana', 'completada', TIMESTAMP(@D4,'21:00:00') + INTERVAL 120 MINUTE, 'admin', 'fx-revpash-res-017',
   TIMESTAMP(@D4,'21:00:00') - INTERVAL 3 DAY);

-- ---------------------------------------------------------------------
-- Comprobación rápida: imprime el rango exacto que hay que pedirle al panel
-- para ver la semana aislada, y el volumen sembrado.
-- ---------------------------------------------------------------------
SELECT @SEM_INI AS desde,
       @SEM_FIN AS hasta,
       CONCAT('/admin/analytics?desde=', @SEM_INI, '&hasta=', @SEM_FIN) AS url,
       (SELECT COUNT(*) FROM tickets WHERE id BETWEEN 300 AND 499)              AS tickets,
       (SELECT COUNT(*) FROM ticket_items WHERE ticket_id BETWEEN 300 AND 499)  AS partidas,
       (SELECT ROUND(SUM(ti.precio * ti.cantidad))
          FROM ticket_items ti
         WHERE ti.ticket_id BETWEEN 300 AND 499 AND ti.estado <> 'cancelado')   AS venta_semana;

-- ---------------------------------------------------------------------
-- Consultas de verificación (descomentar para auditar a mano lo que el panel
-- debería estar mostrando). Reproducen el cálculo de Services\Analiticas.
-- ---------------------------------------------------------------------

-- §3.2 · El mapa de calor, tal cual: RevPASH = ingreso ÷ (44 asientos × 1 día).
-- Con el rango acotado a la semana, cada día de la semana ocurre una sola vez,
-- así que el denominador es 44 y la celda es directamente ingreso/44.
-- SELECT HOUR(t.hora_apertura) AS hora,
--        ROUND(SUM(CASE WHEN DAYOFWEEK(t.hora_apertura)=2 THEN ti.precio*ti.cantidad END)/44, 2) AS lun,
--        ROUND(SUM(CASE WHEN DAYOFWEEK(t.hora_apertura)=3 THEN ti.precio*ti.cantidad END)/44, 2) AS mar,
--        ROUND(SUM(CASE WHEN DAYOFWEEK(t.hora_apertura)=4 THEN ti.precio*ti.cantidad END)/44, 2) AS mie,
--        ROUND(SUM(CASE WHEN DAYOFWEEK(t.hora_apertura)=5 THEN ti.precio*ti.cantidad END)/44, 2) AS jue,
--        ROUND(SUM(CASE WHEN DAYOFWEEK(t.hora_apertura)=6 THEN ti.precio*ti.cantidad END)/44, 2) AS vie,
--        ROUND(SUM(CASE WHEN DAYOFWEEK(t.hora_apertura)=7 THEN ti.precio*ti.cantidad END)/44, 2) AS sab,
--        ROUND(SUM(CASE WHEN DAYOFWEEK(t.hora_apertura)=1 THEN ti.precio*ti.cantidad END)/44, 2) AS dom
--   FROM ticket_items ti
--   JOIN tickets t ON t.id = ti.ticket_id
--  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
--    AND DATE(t.hora_apertura) BETWEEN @SEM_INI AND @SEM_FIN
--  GROUP BY hora ORDER BY hora;

-- §3.1 · Unidades por producto y categoría, que es el eje de popularidad.
-- El eje de margen sale de productos.precio − Inventario::costoDeProducto().
-- SELECT ti.categoria, ti.nombre, SUM(ti.cantidad) AS unidades
--   FROM ticket_items ti
--   JOIN tickets t ON t.id = ti.ticket_id
--  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
--    AND DATE(t.hora_apertura) BETWEEN @SEM_INI AND @SEM_FIN
--  GROUP BY ti.categoria, ti.nombre
--  ORDER BY ti.categoria, unidades DESC;

-- §3.4 · Coocurrencias por par. El lift lo termina de calcular PHP; aquí se ve
-- el insumo: cuántas cuentas contienen A y B a la vez.
-- SELECT a.nombre AS a, b.nombre AS b, COUNT(*) AS coocurrencias
--   FROM (SELECT DISTINCT ti.ticket_id, ti.nombre
--           FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
--          WHERE t.estado='cerrado' AND ti.estado<>'cancelado'
--            AND DATE(t.hora_apertura) BETWEEN @SEM_INI AND @SEM_FIN) a
--   JOIN (SELECT DISTINCT ti.ticket_id, ti.nombre
--           FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
--          WHERE t.estado='cerrado' AND ti.estado<>'cancelado'
--            AND DATE(t.hora_apertura) BETWEEN @SEM_INI AND @SEM_FIN) b
--     ON a.ticket_id = b.ticket_id AND a.nombre < b.nombre
--  GROUP BY a, b HAVING coocurrencias >= 3 ORDER BY coocurrencias DESC;

-- Fin de REVPash-pruebas.sql

