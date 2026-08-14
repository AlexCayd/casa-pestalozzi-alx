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
--   2. database/dml_operativo.sql     (categorías, productos, mesas, horario)
--   3. database/REVPash-pruebas.sql   (este archivo)
--
-- database/dml_pruebas.sql es opcional y aporta dos cosas que este fixture
-- aprovecha si están: los usuarios demo —los meseros a los que se atribuyen
-- estos tickets— y las recetas de bebida (ingredientes 1-9). Sin él el archivo
-- carga igual: mesero_id queda en NULL y los cafés y jugos entran a la
-- ingeniería de menú con costo 0, o sea con margen igual a su precio.
--
-- CÓMO VERLO
--   La semana sembrada es del 1 al 7 de agosto de 2026 —sábado a viernes, los
--   siete días de la semana exactamente una vez cada uno—, así que el
--   denominador del RevPASH vale 1 día por celda y el mapa muestra
--   directamente ingreso ÷ 44 asientos, sin promediar nada:
--
--     /admin/analytics?desde=2026-08-01&hasta=2026-08-07
--
--   Con el filtro por defecto ("últimos 30 días") el relieve se sigue leyendo
--   igual mientras la semana caiga dentro; después de eso hay que pedir el
--   rango a mano. Para mover la semana a otras fechas basta editar los siete
--   SET @D0..@D6 de abajo: el resto del archivo se cuelga de esas variables.
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
-- el relieve dice "los lunes son flojos", no "el tercer día es flojo". El 1 de
-- agosto de 2026 cae en sábado, así que la semana corre de sábado a viernes y
-- cada día de la semana ocurre exactamente una vez.
--
-- Para mover el fixture a otras fechas, cambiar solo estas siete líneas
-- respetando el día de la semana de cada una.
-- ---------------------------------------------------------------------
SET @D0 := '2026-08-03';   -- lunes
SET @D1 := '2026-08-04';   -- martes
SET @D2 := '2026-08-05';   -- miércoles
SET @D3 := '2026-08-06';   -- jueves
SET @D4 := '2026-08-07';   -- viernes
SET @D5 := '2026-08-01';   -- sábado
SET @D6 := '2026-08-02';   -- domingo

-- Extremos del rango, para las consultas de verificación del final.
SET @SEM_INI := '2026-08-01';
SET @SEM_FIN := '2026-08-07';

-- Meseros por username y no por id: los ids dependen del orden de inserción de
-- dml_pruebas.sql. Si ese archivo no está cargado, quedan en NULL y el ticket
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

-- producto_componentes no tiene índice único, así que recargar el archivo sin
-- borrar antes duplicaría cada componente y el costo de receta saldría al
-- doble. La lista es exactamente la misma que siembra abajo.
DELETE FROM producto_componentes
 WHERE producto_id IN (SELECT id FROM productos WHERE nombre IN (
   'Chilaquiles','Enmoladas','Enchiladas Suizas','Molletes',
   'Toast de Salmón Ahumado','Hamburguesa de la Casa',
   'Papas a la Francesa con Parmesano','Spaguetti a la Boloñesa',
   'Mix de 3 Brusquetas','Frutos Rojos','Crema del Día','Milano',
   'Margarita','Burrata','Rib Eye (450 grs.)','Salmón al Horno',
   'Tabla Mixta','Filete de Res en su Jugo','Aros de Calamar'));

-- ---------------------------------------------------------------------
-- §3.1 · Ingredientes y recetas de cocina.
--
-- Sin receta, Inventario::costoDeProducto() devuelve 0 y la matriz de
-- ingeniería de menú clasificaría todo por precio: cada platillo tendría el
-- 100 % de margen y el eje vertical dejaría de discriminar. Con costo real
-- aparecen los cuatro cuadrantes.
--
-- Los ids 10-34 y las cantidades son los MISMOS que usa
-- analiticas-datos-ex.sql a propósito: los dos archivos describen la misma
-- cocina, así que cargar uno después del otro converge en los mismos costos
-- en vez de dejar dos catálogos de ingredientes compitiendo. Los ids 1-9
-- (café, leche, fruta) los siembra dml_pruebas.sql.
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
(34, 'Filete de res',         'g',   12000, 2500, 0.5500, 1)
ON DUPLICATE KEY UPDATE
  nombre       = VALUES(nombre),
  unidad       = VALUES(unidad),
  stock        = VALUES(stock),
  stock_minimo = VALUES(stock_minimo),
  costo        = VALUES(costo),
  activo       = VALUES(activo);

-- Receta por 1 unidad de producto. El enlace es por NOMBRE, igual que en todo
-- el sistema (deuda D-3 de ANALITICAS.md): ticket_items no guarda producto_id.
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
-- La lista de columnas es la MISMA que usa analiticas-datos-ex.sql, que es
-- la que existe en la base de desarrollo. Ojo: no coincide con la que
-- declara ddl.sql para esta tabla (ahí la reserva tiene `origen` y
-- `estado_changed_at` en vez de `last_modified_source` y `status_changed_at`).
-- Sobre una base creada desde cero con ddl.sql hay que ajustar este INSERT;
-- el resto del archivo carga igual en las dos.
-- ---------------------------------------------------------------------
INSERT INTO reservaciones
  (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, estado,
   confirmed_at, completed_at, status_changed_at, last_modified_source,
   last_change_reason, request_token, created_at) VALUES
  ('Gina Palomares', 'email', 'reserva.018@example.test', @D5, '13:00:00', 6, 'Cumpleaños', 'completada',
   TIMESTAMP(@D5,'13:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D5,'13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D5,'13:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-018',
   TIMESTAMP(@D5,'13:00:00') - INTERVAL 1 DAY),
  ('Nicolás Arámbula', 'email', 'reserva.019@example.test', @D5, '20:00:00', 4, 'Carriola', 'completada',
   TIMESTAMP(@D5,'20:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D5,'20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D5,'20:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-019',
   TIMESTAMP(@D5,'20:00:00') - INTERVAL 2 DAY),
  ('Vania Loera', 'email', 'reserva.020@example.test', @D5, '13:30:00', 2, 'Cliente frecuente', 'completada',
   TIMESTAMP(@D5,'13:30:00') - INTERVAL 1 DAY, TIMESTAMP(@D5,'13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D5,'13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-020',
   TIMESTAMP(@D5,'13:30:00') - INTERVAL 3 DAY),
  ('Cecilia Ynzunza', 'email', 'reserva.021@example.test', @D5, '13:00:00', 5, 'Alergia a nueces', 'no_show',
   TIMESTAMP(@D5,'13:00:00') - INTERVAL 1 DAY, NULL, TIMESTAMP(@D5,'13:00:00') + INTERVAL 120 MINUTE, 'personal', 'No se presentó', 'fx-revpash-res-021',
   TIMESTAMP(@D5,'13:00:00') - INTERVAL 1 DAY),
  ('Joaquín Nieto', 'email', 'reserva.022@example.test', @D5, '20:00:00', 3, 'Aniversario', 'completada',
   TIMESTAMP(@D5,'20:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D5,'20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D5,'20:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-022',
   TIMESTAMP(@D5,'20:00:00') - INTERVAL 2 DAY),
  ('Quirino Ávila', 'email', 'reserva.023@example.test', @D5, '13:30:00', 6, 'Terraza si hay', 'cancelada',
   NULL, NULL, TIMESTAMP(@D5,'13:30:00') - INTERVAL 1 DAY, 'personal', 'Cancelada por el cliente', 'fx-revpash-res-023',
   TIMESTAMP(@D5,'13:30:00') - INTERVAL 3 DAY),
  ('Xavier Peña', 'email', 'reserva.024@example.test', @D6, '13:00:00', 4, '', 'completada',
   TIMESTAMP(@D6,'13:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D6,'13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D6,'13:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-024',
   TIMESTAMP(@D6,'13:00:00') - INTERVAL 1 DAY),
  ('Familia Nava', 'email', 'reserva.025@example.test', @D6, '20:00:00', 2, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(@D6,'20:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D6,'20:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D6,'20:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-025',
   TIMESTAMP(@D6,'20:00:00') - INTERVAL 2 DAY),
  ('Carla Ibáñez', 'email', 'reserva.026@example.test', @D6, '13:30:00', 5, 'Cumpleaños', 'completada',
   TIMESTAMP(@D6,'13:30:00') - INTERVAL 1 DAY, TIMESTAMP(@D6,'13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D6,'13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-026',
   TIMESTAMP(@D6,'13:30:00') - INTERVAL 3 DAY),
  ('Jonás Ledesma', 'email', 'reserva.027@example.test', @D6, '13:00:00', 3, 'Carriola', 'completada',
   TIMESTAMP(@D6,'13:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D6,'13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D6,'13:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-027',
   TIMESTAMP(@D6,'13:00:00') - INTERVAL 1 DAY),
  ('Hugo Villaseñor', 'email', 'reserva.001@example.test', @D0, '14:00:00', 5, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(@D0,'14:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D0,'14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D0,'14:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-001',
   TIMESTAMP(@D0,'14:00:00') - INTERVAL 2 DAY),
  ('Odette Fierro', 'email', 'reserva.002@example.test', @D0, '21:00:00', 3, 'Cumpleaños', 'completada',
   TIMESTAMP(@D0,'21:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D0,'21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D0,'21:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-002',
   TIMESTAMP(@D0,'21:00:00') - INTERVAL 3 DAY),
  ('Wilfrido Anaya', 'email', 'reserva.003@example.test', @D1, '21:00:00', 6, 'Carriola', 'completada',
   TIMESTAMP(@D1,'21:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D1,'21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D1,'21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-003',
   TIMESTAMP(@D1,'21:00:00') - INTERVAL 1 DAY),
  ('Damián Portillo', 'email', 'reserva.004@example.test', @D1, '20:30:00', 4, 'Cliente frecuente', 'completada',
   TIMESTAMP(@D1,'20:30:00') - INTERVAL 1 DAY, TIMESTAMP(@D1,'20:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D1,'20:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-004',
   TIMESTAMP(@D1,'20:30:00') - INTERVAL 2 DAY),
  ('Karla Villalobos', 'email', 'reserva.005@example.test', @D1, '14:00:00', 2, 'Alergia a nueces', 'no_show',
   TIMESTAMP(@D1,'14:00:00') - INTERVAL 1 DAY, NULL, TIMESTAMP(@D1,'14:00:00') + INTERVAL 120 MINUTE, 'personal', 'No se presentó', 'fx-revpash-res-005',
   TIMESTAMP(@D1,'14:00:00') - INTERVAL 3 DAY),
  ('Rita Peña', 'email', 'reserva.006@example.test', @D2, '13:00:00', 5, 'Aniversario', 'completada',
   TIMESTAMP(@D2,'13:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D2,'13:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D2,'13:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-006',
   TIMESTAMP(@D2,'13:00:00') - INTERVAL 1 DAY),
  ('Yara Sol', 'email', 'reserva.007@example.test', @D2, '20:00:00', 3, 'Terraza si hay', 'cancelada',
   NULL, NULL, TIMESTAMP(@D2,'20:00:00') - INTERVAL 1 DAY, 'personal', 'Cancelada por el cliente', 'fx-revpash-res-007',
   TIMESTAMP(@D2,'20:00:00') - INTERVAL 2 DAY),
  ('Familia Robles', 'email', 'reserva.008@example.test', @D2, '13:30:00', 6, '', 'completada',
   TIMESTAMP(@D2,'13:30:00') - INTERVAL 1 DAY, TIMESTAMP(@D2,'13:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D2,'13:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-008',
   TIMESTAMP(@D2,'13:30:00') - INTERVAL 3 DAY),
  ('Diego Nava', 'email', 'reserva.009@example.test', @D3, '21:00:00', 4, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(@D3,'21:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D3,'21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D3,'21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-009',
   TIMESTAMP(@D3,'21:00:00') - INTERVAL 1 DAY),
  ('Katia Berrones', 'email', 'reserva.010@example.test', @D3, '20:30:00', 2, 'Cumpleaños', 'completada',
   TIMESTAMP(@D3,'20:30:00') - INTERVAL 1 DAY, TIMESTAMP(@D3,'20:30:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D3,'20:30:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-010',
   TIMESTAMP(@D3,'20:30:00') - INTERVAL 2 DAY),
  ('Samuel Íñiguez', 'email', 'reserva.011@example.test', @D3, '14:00:00', 5, 'Carriola', 'completada',
   TIMESTAMP(@D3,'14:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D3,'14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D3,'14:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-011',
   TIMESTAMP(@D3,'14:00:00') - INTERVAL 3 DAY),
  ('Zacarías Beltrán', 'email', 'reserva.012@example.test', @D3, '21:00:00', 3, 'Cliente frecuente', 'completada',
   TIMESTAMP(@D3,'21:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D3,'21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D3,'21:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-012',
   TIMESTAMP(@D3,'21:00:00') - INTERVAL 1 DAY),
  ('Gael Ruan', 'email', 'reserva.013@example.test', @D4, '14:00:00', 6, 'Alergia a nueces', 'no_show',
   TIMESTAMP(@D4,'14:00:00') - INTERVAL 1 DAY, NULL, TIMESTAMP(@D4,'14:00:00') + INTERVAL 120 MINUTE, 'personal', 'No se presentó', 'fx-revpash-res-013',
   TIMESTAMP(@D4,'14:00:00') - INTERVAL 2 DAY),
  ('Noa Cid', 'email', 'reserva.014@example.test', @D4, '21:00:00', 4, 'Aniversario', 'completada',
   TIMESTAMP(@D4,'21:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D4,'21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D4,'21:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-014',
   TIMESTAMP(@D4,'21:00:00') - INTERVAL 3 DAY),
  ('Ulises Nava', 'email', 'reserva.015@example.test', @D4, '20:30:00', 2, 'Terraza si hay', 'cancelada',
   NULL, NULL, TIMESTAMP(@D4,'20:30:00') - INTERVAL 1 DAY, 'personal', 'Cancelada por el cliente', 'fx-revpash-res-015',
   TIMESTAMP(@D4,'20:30:00') - INTERVAL 1 DAY),
  ('Grupo Ibarra', 'email', 'reserva.016@example.test', @D4, '14:00:00', 5, '', 'completada',
   TIMESTAMP(@D4,'14:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D4,'14:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D4,'14:00:00') + INTERVAL 120 MINUTE, 'cliente', 'Servicio concluido', 'fx-revpash-res-016',
   TIMESTAMP(@D4,'14:00:00') - INTERVAL 2 DAY),
  ('Familia Cruz', 'email', 'reserva.017@example.test', @D4, '21:00:00', 3, 'Mesa junto a ventana', 'completada',
   TIMESTAMP(@D4,'21:00:00') - INTERVAL 1 DAY, TIMESTAMP(@D4,'21:00:00') + INTERVAL 120 MINUTE, TIMESTAMP(@D4,'21:00:00') + INTERVAL 120 MINUTE, 'personal', 'Servicio concluido', 'fx-revpash-res-017',
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
