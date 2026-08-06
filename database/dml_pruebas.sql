-- Casa Pestalozzi — DML de pruebas y demostración
--
-- Datos ficticios para desarrollo y QA: usuarios demo, ventas, feedback,
-- reservaciones, tickets, inventario y escenarios operativos.
-- Ejecutar después de database/ddl.sql y database/dml_operativo.sql.
-- No usar este archivo como semilla de producción.
--
SET NAMES utf8mb4;
SET @HOY := CURDATE();

-- -------------------------------------------------------
-- Usuarios demo
-- admin_demo entra en /login (pestaña Contraseña) con password: Pestalozzi2026
-- (el resto conserva un bcrypt de prueba sin password conocida)
-- -------------------------------------------------------

-- ids implícitos por orden: 1 admin, 2-3 y 5-6 meseros activos, 4 cocinero,
-- 7 mesero inactivo. Tres meseros activos para comparar rendimiento.
--
-- Las fechas de nacimiento están elegidas para que su DDMM no choque entre sí
-- ni con los NIP fijados abajo: así la semilla ejercita los dos caminos, el
-- NIP explícito y el derivado del cumpleaños.
INSERT INTO usuarios (username, nombre, fecha_nacimiento, password_hash, rol, activo) VALUES
('admin_demo',      'Administrador Demo',  '1985-06-12', '$2y$12$qH/BVO2OPCYRbt7rUfYtIecXWTXOSk8hxWavaadrcfbwEnIHsXXd.', 'admin',  1),
('mesero1',         'Carlos Hernández',    '1993-11-23', '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'waiter', 1),
('mesero2',         'Valeria Ríos',        '1996-02-17', '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'waiter', 1),
('cocinero1',       'Mariana López',       '1991-09-05', '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'cook',   1),
('mesero3',         'Emilio Cárdenas',     '1998-07-30', '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'waiter', 1),
('mesero_inactivo', 'Daniel Torres',       '1994-12-03', '$2y$10$wH8Lm8rMjYpPqOQF3AbY3eGy7PV8wzg6kgAYZ3i.E2oQ1FjiZ3Xj2', 'waiter', 0);

-- NIP de acceso demo del personal de piso (bcrypt de 4 dígitos), para /login:
--   mesero1 → 2345 · cocinero1 → 3456
--   mesero2 → 1702 · mesero3 → 3007  (ambos son el DDMM de su cumpleaños)
-- El admin NO usa NIP: entra en /login, pestaña de administrador, con
-- usuario + password.
UPDATE usuarios SET nip_hash = '$2y$12$Jkhr3umCEYaNQY4OSGedgOu5eHImaGx1PtjXSMY9hXn3Zqu1OmReW' WHERE username = 'mesero1';
UPDATE usuarios SET nip_hash = '$2y$12$bb8wu.UY6FK8vBzU4E5X6uAZq3lZwzfSOn4kXcG9vRuV9eFMXF1MW' WHERE username = 'cocinero1';
UPDATE usuarios SET nip_hash = '$2y$12$wbcjrmcyjQqdNQ3l24.zK.FUHBaX55O6866E40kAueBYyiSyiTgxO' WHERE username = 'mesero2';
UPDATE usuarios SET nip_hash = '$2y$12$ACcHQkJyV/2dXYaxohNsVOz7V3XWaQXgVMAnJoaJOwnbY6coSjjEW' WHERE username = 'mesero3';

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
-- Los nombres deben coincidir EXACTO con productos.nombre y menu.nombre:
-- el motor de recomendacion parte de 'menu' y une por nombre.
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

-- Anuncio inicial que se modificará
INSERT INTO configuracion_anuncio (id, mensaje, activo) VALUES (1, 'Test', 0);

-- -------------------------------------------------------
-- ESCENARIOS DE RESERVACIONES: 27 NOVIEMBRE–3 DICIEMBRE 2026
-- La jornada principal y el reloj reproducible son el 30 de noviembre.
-- No se siembran códigos OTP; las suites generan hashes efímeros.
-- -------------------------------------------------------

SET @fecha_historica = '2026-11-27';
SET @fecha_cerrada = '2026-11-29';
SET @fecha_principal = '2026-11-30';
SET @fecha_posterior = '2026-12-01';
SET @fecha_especial = '2026-12-02';
SET @fecha_futura = '2026-12-03';
SET @reloj_prueba = '2026-11-30 12:00:00';

INSERT INTO excepciones_operacion
  (fecha, tipo, motivo, hora_apertura, hora_cierre, activo)
VALUES
  (@fecha_cerrada, 'cerrado', 'Cierre de prueba', NULL, NULL, 1),
  (@fecha_especial, 'horario_especial', 'Horario especial de prueba',
   '14:00:00', '21:00:00', 1);

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
   'fx-historica-000001', '2026-11-27 19:30:00');

-- Retenciones, modificación, cancelación y falta de capacidad.
INSERT INTO reservaciones
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado,
   hold_expires_at, request_token, estado_changed_at)
VALUES
  ('Retención Vigente', 'email', 'hold.vigente@example.test',
   @fecha_principal, '17:30:00', 2, '', 'landing', 'pendiente_verificacion',
   '2026-11-30 12:05:00',
   'fx-hold-vigente-001', @reloj_prueba),
  ('Retención Vencida', 'email', 'hold.vencida@example.test',
   @fecha_principal, '18:00:00', 2, '', 'landing', 'pendiente_verificacion',
   '2026-11-30 11:59:59',
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
   'fx-pos-convertida-000001', '2026-11-30 19:50:00'),
  ('POS En Curso', 'email', 'pos.encurso@example.test',
   @fecha_principal, '20:00:00', 6, '', 'admin', 'en_curso',
   'fx-pos-encurso-001', '2026-11-30 20:00:00'),
  ('POS Completada', 'email', 'pos.completa@example.test',
   @fecha_historica, '18:00:00', 2, '', 'admin', 'completada',
   'fx-pos-completa-001', '2026-11-27 19:30:00'),
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
  (6, 'POS En Curso', '2026-11-30 20:00:00', NULL, 'abierto', NULL, @pos_en_curso),
  (2, 'POS Completada', '2026-11-27 18:00:00', '2026-11-27 19:30:00',
   'cerrado', 'efectivo', @pos_completada);

SET @ticket_en_curso = (SELECT id FROM tickets WHERE reservacion_id = @pos_en_curso);
SET @ticket_completado = (SELECT id FROM tickets WHERE reservacion_id = @pos_completada);

INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES
  (@ticket_en_curso, 5, 1), (@ticket_en_curso, 6, 2),
  (@ticket_completado, 6, 1);

-- Walk-in de varias mesas y una reserva futura sobre la misma zona.
INSERT INTO tickets (comensales, nombre, hora_apertura, estado)
VALUES
  (2, 'Walk-in Una Mesa', '2026-11-30 20:10:00', 'abierto'),
  (6, 'Walk-in Varias Mesas', '2026-11-30 20:15:00', 'abierto');

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
