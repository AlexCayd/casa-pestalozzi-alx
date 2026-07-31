-- =====================================================================
-- Casa Pestalozzi — Datos de prueba para las analíticas de Nivel 1
-- (ver database/ANALITICAS.md §3). SE CARGA DESPUÉS de dml.sql.
--
--   §3.1 Ingeniería de menú   — recetas de comida para dar margen real.
--   §3.2 RevPASH              — horarios_operacion + ventas por franja.
--   §3.3 Varianza inventario  — ajustes de merma en movimientos_inventario.
--   §3.4 Reglas de asociación — tickets con pares recurrentes (afinidad).
--
-- Deliberadamente SEPARADO de dml.sql: son datos de demostración de la
-- analítica, no el seed base del sistema. Es idempotente (limpia sus propios
-- rangos antes de insertar), así que puede recargarse sin duplicar.
--
-- Rango temporal: últimos ~30 días respecto a 2026-07-28 (la "fecha de hoy"
-- del entorno de demo), para que caiga dentro del filtro por defecto del panel.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Limpieza idempotente de los datos que crea ESTE archivo.
-- ---------------------------------------------------------------------
DELETE FROM ticket_items WHERE ticket_id BETWEEN 200 AND 299;
DELETE FROM tickets      WHERE id        BETWEEN 200 AND 299;
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
-- §3.2 · Horario de operación semanal (denominador del RevPASH).
-- dia_semana: 0=Dom 1=Lun 2=Mar 3=Mie 4=Jue 5=Vie 6=Sab. Lunes cerrado.
-- ON DUPLICATE KEY: la tabla tiene UNIQUE(dia_semana); recargable sin error.
-- ---------------------------------------------------------------------
INSERT INTO horarios_operacion (dia_semana, abierto, hora_apertura, hora_cierre) VALUES
(0, 1, '08:00:00', '22:00:00'),
(1, 0, NULL, NULL),
(2, 1, '08:00:00', '23:00:00'),
(3, 1, '08:00:00', '23:00:00'),
(4, 1, '08:00:00', '23:00:00'),
(5, 1, '08:00:00', '23:00:00'),
(6, 1, '08:00:00', '23:00:00')
ON DUPLICATE KEY UPDATE
  abierto = VALUES(abierto),
  hora_apertura = VALUES(hora_apertura),
  hora_cierre = VALUES(hora_cierre);

-- ---------------------------------------------------------------------
-- §3.1 / §3.3 · Ingredientes de cocina (ids 10+; los 1-9 los pone dml.sql).
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
-- §3.1 / §3.3 · Recetas principales de comida (enlace por nombre de platillo,
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
INSERT INTO tickets (id, mesa_id, comensales, nombre, hora_apertura, estado, metodo_pago, propina, mesero_id) VALUES
(200, 1, 2, 'Ana Ruiz', '2026-06-30 09:15:00', 'cerrado', 'tarjeta', 59, 3),
(201, 3, 3, 'León Vega', '2026-07-02 09:40:00', 'cerrado', 'efectivo', 52, 6),
(202, 2, 2, 'Bruno Salas', '2026-07-05 10:05:00', 'cerrado', 'tarjeta', 32, 3),
(203, 4, 4, 'Familia Nava', '2026-07-09 08:50:00', 'cerrado', 'tarjeta', 92, 6),
(204, 5, 2, 'Ivy Cano', '2026-07-14 09:30:00', 'cerrado', 'efectivo', 0, 3),
(205, 6, 2, 'Sol Marín', '2026-07-21 10:10:00', 'cerrado', 'tarjeta', 51, 6),
(206, 7, 2, 'Rita Peña', '2026-07-01 09:00:00', 'cerrado', 'tarjeta', 87, 3),
(207, 8, 3, 'Tono Gil', '2026-07-07 09:20:00', 'cerrado', 'efectivo', 40, 6),
(208, 9, 2, 'Lía Ordaz', '2026-07-15 10:00:00', 'cerrado', 'tarjeta', 69, 3),
(209, 10, 2, 'Gael Ruan', '2026-07-22 09:45:00', 'cerrado', 'tarjeta', 47, 6),
(210, 1, 2, 'Vera Luna', '2026-07-03 10:30:00', 'cerrado', 'tarjeta', 92, 3),
(211, 2, 2, 'Pau Rivas', '2026-07-08 09:10:00', 'cerrado', 'tarjeta', 37, 6),
(212, 3, 3, 'Cris Mora', '2026-07-16 10:20:00', 'cerrado', 'efectivo', 48, 3),
(213, 4, 2, 'Noa Cid', '2026-07-24 09:35:00', 'cerrado', 'tarjeta', 70, 6),
(214, 5, 2, 'Dani Frey', '2026-07-04 09:25:00', 'cerrado', 'tarjeta', 68, 3),
(215, 6, 3, 'Uri Mena', '2026-07-11 10:15:00', 'cerrado', 'efectivo', 0, 6),
(216, 7, 2, 'Bea Toro', '2026-07-23 09:50:00', 'cerrado', 'tarjeta', 37, 3),
(217, 3, 4, 'Grupo Lara', '2026-06-30 14:10:00', 'cerrado', 'tarjeta', 109, 4),
(218, 6, 3, 'Toña Vela', '2026-07-03 13:40:00', 'cerrado', 'efectivo', 42, 3),
(219, 2, 4, 'Nico Paz', '2026-07-10 15:00:00', 'cerrado', 'tarjeta', 100, 4),
(220, 5, 2, 'Ele Sáenz', '2026-07-17 14:30:00', 'cerrado', 'tarjeta', 59, 3),
(221, 1, 3, 'Rux Dávila', '2026-07-25 13:20:00', 'cerrado', 'tarjeta', 109, 4),
(222, 4, 3, 'Beto Nájera', '2026-07-02 14:00:00', 'cerrado', 'tarjeta', 86, 3),
(223, 2, 2, 'Kena Ríos', '2026-07-13 15:10:00', 'cerrado', 'efectivo', 44, 4),
(224, 6, 4, 'Grupo Sáez', '2026-07-23 13:50:00', 'cerrado', 'tarjeta', 134, 3),
(225, 1, 2, 'Mar Cueto', '2026-07-07 15:20:00', 'cerrado', 'tarjeta', 72, 4),
(226, 5, 2, 'Alma Vidal', '2026-07-15 14:40:00', 'cerrado', 'efectivo', 0, 3),
(227, 3, 3, 'Ori Lozano', '2026-07-26 13:30:00', 'cerrado', 'tarjeta', 101, 4),
(228, 2, 3, 'Fito Reyna', '2026-07-05 14:20:00', 'cerrado', 'tarjeta', 54, 3),
(229, 4, 4, 'Grupo Ibarra', '2026-07-18 15:30:00', 'cerrado', 'tarjeta', 92, 4),
(230, 6, 2, 'Sam Peña', '2026-07-27 14:05:00', 'cerrado', 'efectivo', 45, 3),
(231, 3, 4, 'Grupo Cano', '2026-07-01 20:15:00', 'cerrado', 'tarjeta', 287, 4),
(232, 6, 2, 'Vic Aranda', '2026-07-11 21:00:00', 'cerrado', 'tarjeta', 127, 6),
(233, 2, 3, 'Lalo Bravo', '2026-07-21 20:40:00', 'cerrado', 'tarjeta', 166, 4),
(234, 5, 2, 'Nube Ríos', '2026-07-04 21:20:00', 'cerrado', 'tarjeta', 85, 6),
(235, 1, 2, 'Tavo Cruz', '2026-07-14 20:10:00', 'cerrado', 'efectivo', 36, 4),
(236, 4, 3, 'Yara Sol', '2026-07-24 21:15:00', 'cerrado', 'tarjeta', 85, 6),
(237, 6, 4, 'Grupo Villa', '2026-07-08 20:30:00', 'cerrado', 'tarjeta', 118, 4),
(238, 2, 3, 'Mina Fox', '2026-07-19 21:10:00', 'cerrado', 'tarjeta', 70, 6),
(239, 3, 4, 'Grupo Elías', '2026-07-26 20:50:00', 'cerrado', 'tarjeta', 117, 4),
(240, 4, 3, 'Ceci Robles', '2026-07-06 21:05:00', 'cerrado', 'tarjeta', 111, 6),
(241, 5, 2, 'Gus Prado', '2026-07-16 20:20:00', 'cerrado', 'efectivo', 53, 4),
(242, 1, 3, 'Rey Molina', '2026-07-27 21:30:00', 'cerrado', 'tarjeta', 148, 6);

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
-- §3.3 · Ajustes de inventario = merma registrada (conteo físico).
-- Cantidad negativa = faltante frente a lo que las recetas descontaron.
-- Valorizada con ingredientes.costo, produce el ranking de fuga en pesos.
-- ids 900+ para poder limpiarlos de forma idempotente arriba.
-- ---------------------------------------------------------------------
INSERT INTO movimientos_inventario (id, ingrediente_id, tipo, cantidad, ticket_item_id, created_at) VALUES
(900, 12, 'ajuste', -400.000, NULL, '2026-07-15 23:40:00'),  -- Queso gouda
(901, 20, 'ajuste', -120.000, NULL, '2026-07-15 23:40:00'),  -- Salmón ahumado
(902, 16, 'ajuste', -300.000, NULL, '2026-07-15 23:40:00'),  -- Carne molida de res
(903,  1, 'ajuste', -200.000, NULL, '2026-07-15 23:40:00'),  -- Café molido
(904, 15, 'ajuste', -150.000, NULL, '2026-07-22 23:40:00'),  -- Rib eye
(905, 18, 'ajuste', -900.000, NULL, '2026-07-22 23:40:00'),  -- Papa
(906, 25, 'ajuste', -250.000, NULL, '2026-07-22 23:40:00'),  -- Queso mozzarella
(907, 26, 'ajuste', -120.000, NULL, '2026-07-22 23:40:00');  -- Mole negro

-- Fin de analiticas.sql
