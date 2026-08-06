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
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, estado,
   confirmed_at, completed_at, status_changed_at, last_modified_source,
   last_change_reason, request_token)
VALUES
  ('Adriana Lozano', 'email', 'adriana.000@example.test',
   DATE_SUB(@HOY, INTERVAL 29 DAY), '13:00:00', 2, '', 'cancelada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 29 DAY), '13:00:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 29 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'personal', 'Cancelada por el cliente', 'fx-analytics-res-000'),
  ('Bruno Cerván', 'email', 'bruno.001@example.test',
   DATE_SUB(@HOY, INTERVAL 29 DAY), '20:00:00', 4, 'Alergia a nueces', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 29 DAY), '20:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 29 DAY), '20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 29 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-001'),
  ('Carla Ibáñez', 'email', 'carla.002@example.test',
   DATE_SUB(@HOY, INTERVAL 28 DAY), '21:00:00', 2, 'Aniversario', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-002'),
  ('Diego Maldonado', 'email', 'diego.003@example.test',
   DATE_SUB(@HOY, INTERVAL 28 DAY), '19:00:00', 6, 'Celebración de trabajo', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-003'),
  ('Elena Ferrer', 'email', 'elena.004@example.test',
   DATE_SUB(@HOY, INTERVAL 28 DAY), '14:00:00', 3, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '14:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 28 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-004'),
  ('Fabián Ortuño', 'email', 'fabian.005@example.test',
   DATE_SUB(@HOY, INTERVAL 27 DAY), '13:30:00', 2, 'Carriola', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 27 DAY), '13:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 27 DAY), '13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 27 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-005'),
  ('Gabriela Rentería', 'email', 'gabriela.006@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00', 8, 'Cliente frecuente', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-006'),
  ('Héctor Salcedo', 'email', 'hector.007@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '14:00:00', 4, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '14:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-007'),
  ('Irene Bustos', 'email', 'irene.008@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '21:00:00', 2, 'Cumpleaños', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-008'),
  ('Joaquín Nieto', 'email', 'joaquin.009@example.test',
   DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00', 5, 'Silla alta para bebé', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 26 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-009'),
  ('Karla Villalobos', 'email', 'karla.010@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00', 2, 'Terraza si hay', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-010'),
  ('Leonardo Prats', 'email', 'leonardo.011@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00', 4, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-011'),
  ('Mariana Escobar', 'email', 'mariana.012@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '19:00:00', 2, 'Alergia a nueces', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-012'),
  ('Néstor Aguilar', 'email', 'nestor.013@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00', 6, 'Aniversario', 'cancelada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Cancelada por el cliente', 'fx-analytics-res-013'),
  ('Olivia Cardona', 'email', 'olivia.014@example.test',
   DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00', 3, 'Celebración de trabajo', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 25 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-014'),
  ('Patricio Rueda', 'email', 'patricio.015@example.test',
   DATE_SUB(@HOY, INTERVAL 24 DAY), '14:30:00', 2, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '14:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '14:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-015'),
  ('Quetzali Moreno', 'email', 'quetzali.016@example.test',
   DATE_SUB(@HOY, INTERVAL 24 DAY), '21:30:00', 8, 'Carriola', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '21:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '21:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 24 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-016'),
  ('Rodrigo Bañuelos', 'email', 'rodrigo.017@example.test',
   DATE_SUB(@HOY, INTERVAL 22 DAY), '13:30:00', 4, 'Cliente frecuente', 'no_show',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 22 DAY), '13:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 22 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'No se presentó', 'fx-analytics-res-017'),
  ('Sofía Zamudio', 'email', 'sofia.018@example.test',
   DATE_SUB(@HOY, INTERVAL 21 DAY), '19:00:00', 2, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-018'),
  ('Tomás Iriarte', 'email', 'tomas.019@example.test',
   DATE_SUB(@HOY, INTERVAL 21 DAY), '14:00:00', 5, 'Cumpleaños', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '14:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-019'),
  ('Ursula Pineda', 'email', 'ursula.020@example.test',
   DATE_SUB(@HOY, INTERVAL 21 DAY), '21:00:00', 2, 'Silla alta para bebé', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 21 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-020'),
  ('Valentín Cortés', 'email', 'valentin.021@example.test',
   DATE_SUB(@HOY, INTERVAL 20 DAY), '20:30:00', 4, 'Terraza si hay', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '20:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '20:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '20:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-021'),
  ('Ximena Arreola', 'email', 'ximena.022@example.test',
   DATE_SUB(@HOY, INTERVAL 20 DAY), '15:30:00', 2, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '15:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '15:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 20 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-022'),
  ('Yago Benítez', 'email', 'yago.023@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00', 6, 'Alergia a nueces', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-023'),
  ('Zoé Márquez', 'email', 'zoe.024@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '14:30:00', 3, 'Aniversario', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '14:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '14:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-024'),
  ('Alonso Vergara', 'email', 'alonso.025@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '21:30:00', 2, 'Celebración de trabajo', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '21:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '21:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-025'),
  ('Brenda Quiroz', 'email', 'brenda.026@example.test',
   DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00', 8, 'Mesa junto a ventana', 'cancelada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 19 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'personal', 'Cancelada por el cliente', 'fx-analytics-res-026'),
  ('Cristóbal Serna', 'email', 'cristobal.027@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00', 4, 'Carriola', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-027'),
  ('Daniela Rojas', 'email', 'daniela.028@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00', 2, 'Cliente frecuente', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-028'),
  ('Emiliano Tapia', 'email', 'emiliano.029@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00', 5, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-029'),
  ('Fernanda Olmos', 'email', 'fernanda.030@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00', 2, 'Cumpleaños', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-030'),
  ('Gerardo Pantoja', 'email', 'gerardo.031@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00', 4, 'Silla alta para bebé', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-031'),
  ('Helena Muñiz', 'email', 'helena.032@example.test',
   DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00', 2, 'Terraza si hay', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 18 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-032'),
  ('Ignacio Bravo', 'email', 'ignacio.033@example.test',
   DATE_SUB(@HOY, INTERVAL 17 DAY), '20:30:00', 6, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '20:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '20:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '20:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-033'),
  ('Julieta Ocampo', 'email', 'julieta.034@example.test',
   DATE_SUB(@HOY, INTERVAL 17 DAY), '15:30:00', 3, 'Alergia a nueces', 'no_show',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '15:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'personal', 'No se presentó', 'fx-analytics-res-034'),
  ('Kevin Alcaraz', 'email', 'kevin.035@example.test',
   DATE_SUB(@HOY, INTERVAL 17 DAY), '13:30:00', 2, 'Aniversario', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '13:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 17 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-035'),
  ('Lucía Sandoval', 'email', 'lucia.036@example.test',
   DATE_SUB(@HOY, INTERVAL 16 DAY), '13:00:00', 8, 'Celebración de trabajo', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 16 DAY), '13:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 16 DAY), '13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 16 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-036'),
  ('Matías Grijalva', 'email', 'matias.037@example.test',
   DATE_SUB(@HOY, INTERVAL 14 DAY), '15:30:00', 4, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '15:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '15:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-037'),
  ('Natalia Espinoza', 'email', 'natalia.038@example.test',
   DATE_SUB(@HOY, INTERVAL 14 DAY), '13:30:00', 2, 'Carriola', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '13:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 14 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-038'),
  ('Óscar Valadez', 'email', 'oscar.039@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00', 5, 'Cliente frecuente', 'cancelada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Cancelada por el cliente', 'fx-analytics-res-039'),
  ('Paola Zúñiga', 'email', 'paola.040@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00', 2, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-040'),
  ('Ramiro Cisneros', 'email', 'ramiro.041@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '19:30:00', 4, 'Cumpleaños', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '19:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '19:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-041'),
  ('Sergio Maldonado', 'email', 'sergio.042@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00', 2, 'Silla alta para bebé', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '14:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-042'),
  ('Tania Vergara', 'email', 'tania.043@example.test',
   DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00', 6, 'Terraza si hay', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 13 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-043'),
  ('Ulises Fonseca', 'email', 'ulises.044@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00', 3, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-044'),
  ('Verónica Nájera', 'email', 'veronica.045@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '13:00:00', 2, 'Alergia a nueces', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '13:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-045'),
  ('Wendy Cabrera', 'email', 'wendy.046@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '20:00:00', 8, 'Aniversario', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '20:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-046'),
  ('Xavier Robledo', 'email', 'xavier.047@example.test',
   DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00', 4, 'Celebración de trabajo', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 12 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-047'),
  ('Yolanda Prieto', 'email', 'yolanda.048@example.test',
   DATE_SUB(@HOY, INTERVAL 11 DAY), '13:00:00', 2, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '13:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-048'),
  ('Zacarías Beltrán', 'email', 'zacarias.049@example.test',
   DATE_SUB(@HOY, INTERVAL 11 DAY), '20:00:00', 5, 'Carriola', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '20:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 11 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-049'),
  ('Ana Sofía Lira', 'email', 'ana.050@example.test',
   DATE_SUB(@HOY, INTERVAL 10 DAY), '21:00:00', 2, 'Cliente frecuente', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-050'),
  ('Braulio Mendieta', 'email', 'braulio.051@example.test',
   DATE_SUB(@HOY, INTERVAL 10 DAY), '19:00:00', 4, '', 'no_show',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '19:00:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'cliente', 'No se presentó', 'fx-analytics-res-051'),
  ('Cecilia Ynzunza', 'email', 'cecilia.052@example.test',
   DATE_SUB(@HOY, INTERVAL 10 DAY), '14:00:00', 2, 'Cumpleaños', 'cancelada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '14:00:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 10 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'personal', 'Cancelada por el cliente', 'fx-analytics-res-052'),
  ('Damián Portillo', 'email', 'damian.053@example.test',
   DATE_SUB(@HOY, INTERVAL 9 DAY), '13:30:00', 6, 'Silla alta para bebé', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 9 DAY), '13:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 9 DAY), '13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 9 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-053'),
  ('Estela Guardado', 'email', 'estela.054@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00', 3, 'Terraza si hay', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-054'),
  ('Fausto Rivadeneira', 'email', 'fausto.055@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '14:00:00', 2, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '14:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-055'),
  ('Gina Palomares', 'email', 'gina.056@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '21:00:00', 8, 'Alergia a nueces', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-056'),
  ('Hugo Villaseñor', 'email', 'hugo.057@example.test',
   DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00', 4, 'Aniversario', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 8 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-057'),
  ('Inés Carbajal', 'email', 'ines.058@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00', 2, 'Celebración de trabajo', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-058'),
  ('Jonás Ledesma', 'email', 'jonas.059@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00', 5, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-059'),
  ('Katia Berrones', 'email', 'katia.060@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00', 2, 'Carriola', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-060'),
  ('Luis Ángel Toledo', 'email', 'luis.061@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00', 4, 'Cliente frecuente', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '14:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-061'),
  ('Miriam Cuéllar', 'email', 'miriam.062@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00', 2, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-062'),
  ('Nicolás Arámbula', 'email', 'nicolas.063@example.test',
   DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00', 6, 'Cumpleaños', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 7 DAY), '19:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-063'),
  ('Odette Fierro', 'email', 'odette.064@example.test',
   DATE_SUB(@HOY, INTERVAL 6 DAY), '20:00:00', 3, 'Silla alta para bebé', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '20:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-064'),
  ('Pablo Zepeda', 'email', 'pablo.065@example.test',
   DATE_SUB(@HOY, INTERVAL 6 DAY), '15:00:00', 2, 'Terraza si hay', 'cancelada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '15:00:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Cancelada por el cliente', 'fx-analytics-res-065'),
  ('Regina Alfaro', 'email', 'regina.066@example.test',
   DATE_SUB(@HOY, INTERVAL 6 DAY), '13:00:00', 8, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '13:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 6 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-066'),
  ('Samuel Íñiguez', 'email', 'samuel.067@example.test',
   DATE_SUB(@HOY, INTERVAL 5 DAY), '21:30:00', 4, 'Alergia a nueces', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 5 DAY), '21:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 5 DAY), '21:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 5 DAY), '21:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-067'),
  ('Tere Camarillo', 'email', 'tere.068@example.test',
   DATE_SUB(@HOY, INTERVAL 5 DAY), '19:30:00', 2, 'Aniversario', 'no_show',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 5 DAY), '19:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 5 DAY), '19:30:00') + INTERVAL 120 MINUTE, 'personal', 'No se presentó', 'fx-analytics-res-068'),
  ('Uriel Bermúdez', 'email', 'uriel.069@example.test',
   DATE_SUB(@HOY, INTERVAL 3 DAY), '20:30:00', 5, 'Celebración de trabajo', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '20:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '20:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '20:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-069'),
  ('Vania Loera', 'email', 'vania.070@example.test',
   DATE_SUB(@HOY, INTERVAL 3 DAY), '15:30:00', 2, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '15:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '15:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '15:30:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-070'),
  ('Wilfrido Anaya', 'email', 'wilfrido.071@example.test',
   DATE_SUB(@HOY, INTERVAL 3 DAY), '13:30:00', 4, 'Carriola', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '13:30:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 3 DAY), '13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-071'),
  ('Adriana Lozano', 'email', 'adriana.072@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00', 2, 'Cliente frecuente', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-072'),
  ('Bruno Cerván', 'email', 'bruno.073@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00', 6, '', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-073'),
  ('Carla Ibáñez', 'email', 'carla.074@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '15:00:00', 3, 'Cumpleaños', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '15:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '15:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '15:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-074'),
  ('Diego Maldonado', 'email', 'diego.075@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00', 2, 'Silla alta para bebé', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '13:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-analytics-res-075'),
  ('Elena Ferrer', 'email', 'elena.076@example.test',
   DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00', 8, 'Terraza si hay', 'completada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL -1440 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 2 DAY), '20:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-analytics-res-076'),
  ('Fabián Ortuño', 'email', 'fabian.077@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00', 4, '', 'confirmada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00') + INTERVAL -1440 MINUTE, 'cliente', 'Reserva confirmada', 'fx-analytics-res-077'),
  ('Gabriela Rentería', 'email', 'gabriela.078@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '20:30:00', 2, 'Alergia a nueces', 'confirmada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '20:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '20:30:00') + INTERVAL -1440 MINUTE, 'personal', 'Reserva confirmada', 'fx-analytics-res-078'),
  ('Héctor Salcedo', 'email', 'hector.079@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '15:30:00', 5, 'Aniversario', 'confirmada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '15:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '15:30:00') + INTERVAL -1440 MINUTE, 'cliente', 'Reserva confirmada', 'fx-analytics-res-079'),
  ('Irene Bustos', 'email', 'irene.080@example.test',
   DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00', 2, 'Celebración de trabajo', 'confirmada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 1 DAY), '13:30:00') + INTERVAL -1440 MINUTE, 'personal', 'Reserva confirmada', 'fx-analytics-res-080'),
  ('Joaquín Nieto', 'email', 'joaquin.081@example.test',
   DATE_SUB(@HOY, INTERVAL 0 DAY), '20:30:00', 4, 'Mesa junto a ventana', 'confirmada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 0 DAY), '20:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 0 DAY), '20:30:00') + INTERVAL -1440 MINUTE, 'cliente', 'Reserva confirmada', 'fx-analytics-res-081'),
  ('Karla Villalobos', 'email', 'karla.082@example.test',
   DATE_SUB(@HOY, INTERVAL 0 DAY), '15:30:00', 2, 'Carriola', 'confirmada',
   TIMESTAMP(DATE_SUB(@HOY, INTERVAL 0 DAY), '15:30:00') + INTERVAL -1440 MINUTE, NULL, TIMESTAMP(DATE_SUB(@HOY, INTERVAL 0 DAY), '15:30:00') + INTERVAL -1440 MINUTE, 'personal', 'Reserva confirmada', 'fx-analytics-res-082');

-- Fin de analiticas-datos-ex.sql
