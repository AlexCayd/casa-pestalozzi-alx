-- Casa Pestalozzi — Tablas de reservaciones
-- Ejecutar contra la BD configurada en includes/.env (DB_NAME)

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
