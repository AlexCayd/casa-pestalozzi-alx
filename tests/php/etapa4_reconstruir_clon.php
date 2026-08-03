<?php

declare(strict_types=1);

foreach ($argv as $argumento) {
    if (str_starts_with($argumento, '--db=')) {
        $nombreBase = substr($argumento, 5);
        if ($nombreBase !== '') {
            putenv('ETAPA4_TEST_DB_NAME=' . $nombreBase);
        }
    }
}

$nombreBase = getenv('ETAPA4_TEST_DB_NAME') ?: '';
if ($nombreBase === '' || $nombreBase === 'casa-pestalozzi') {
    fwrite(STDERR, "Uso: php etapa4_reconstruir_clon.php --db=casa_pestalozzi_etapa4_test\n");
    exit(2);
}

require __DIR__ . '/bootstrap_etapa4.php';

use Model\ActiveRecord;
use Services\ContactoService;
use Services\ReservacionConfig;

$db = ActiveRecord::getDB();
$tablaAnterior = 'reservaciones';
$tablaNueva = 'reservaciones_etapa4_nueva';

/** @return array<int, array<string, mixed>> */
function etapa4Rows(mysqli $db, string $sql): array
{
    $resultado = $db->query($sql);
    if (!$resultado) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }

    $filas = [];
    while ($fila = $resultado->fetch_assoc()) {
        $filas[] = $fila;
    }
    $resultado->free();

    return $filas;
}

function etapa4Exec(mysqli $db, string $sql): void
{
    if (!$db->query($sql)) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
}

function etapa4SqlValue(mysqli $db, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . $db->real_escape_string((string)$value) . "'";
}

function etapa4FechaValida(?string $value): bool
{
    if ($value === null || trim($value) === '') {
        return false;
    }

    try {
        new DateTimeImmutable($value);
        return true;
    } catch (Throwable) {
        return false;
    }
}

if (etapa4Rows(
    $db,
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservaciones'"
) === []) {
    throw new RuntimeException('La base objetivo no contiene la tabla reservaciones.');
}

$columnasAnteriores = array_column(
    etapa4Rows(
        $db,
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservaciones'"
    ),
    'COLUMN_NAME'
);

if (!in_array('status_changed_at', $columnasAnteriores, true)) {
    throw new RuntimeException('El clon ya no tiene el esquema heredado; no se repite la reconstrucción.');
}

if (etapa4Rows(
    $db,
    "SELECT TABLE_NAME FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$tablaNueva}'"
) !== []) {
    etapa4Exec($db, "DROP TABLE `{$tablaNueva}`");
}

$conteosAntes = [];
foreach (['reservaciones', 'reservacion_mesas', 'verificaciones_contacto', 'tickets', 'ticket_mesas'] as $tabla) {
    $fila = etapa4Rows($db, "SELECT COUNT(*) AS total FROM `{$tabla}`")[0];
    $conteosAntes[$tabla] = (int)$fila['total'];
}

$estadosAntes = [];
foreach (etapa4Rows($db, 'SELECT estado, COUNT(*) AS total FROM reservaciones GROUP BY estado ORDER BY estado') as $fila) {
    $estadosAntes[(string)$fila['estado']] = (int)$fila['total'];
}

$ahoraFila = etapa4Rows($db, 'SELECT NOW() AS ahora')[0];
$ahora = (string)$ahoraFila['ahora'];
$ahoraObjeto = new DateTimeImmutable($ahora, ReservacionConfig::timezone());

$ticketsAbiertos = [];
foreach (etapa4Rows(
    $db,
    "SELECT DISTINCT reservacion_id FROM tickets
     WHERE reservacion_id IS NOT NULL AND estado = 'abierto'"
) as $fila) {
    $ticketsAbiertos[(int)$fila['reservacion_id']] = true;
}

$reservacionesPublicas = [];
foreach (etapa4Rows(
    $db,
    'SELECT DISTINCT reservacion_id FROM verificaciones_contacto WHERE reservacion_id IS NOT NULL'
) as $fila) {
    $reservacionesPublicas[(int)$fila['reservacion_id']] = true;
}

etapa4Exec(
    $db,
    "CREATE TABLE `{$tablaNueva}` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      nombre VARCHAR(100) NOT NULL,
      contacto_tipo ENUM('email','telefono','ninguno') NOT NULL DEFAULT 'ninguno',
      contacto VARCHAR(150) NULL,
      fecha DATE NOT NULL,
      hora TIME NOT NULL,
      comensales INT UNSIGNED NOT NULL DEFAULT 2,
      nota TEXT NULL,
      comentario_admin TEXT NULL,
      origen ENUM('landing','admin') NOT NULL,
      request_token VARCHAR(64) NULL,
      hold_expires_at DATETIME NULL,
      estado ENUM(
        'pendiente_verificacion','confirmada','en_curso','completada',
        'cancelada','no_show','expirada','reemplazada'
      ) NOT NULL DEFAULT 'pendiente_verificacion',
      reemplaza_reservacion_id INT NULL,
      estado_changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT fk_reservacion_reemplazada_etapa4
        FOREIGN KEY (reemplaza_reservacion_id) REFERENCES `{$tablaNueva}`(id) ON DELETE RESTRICT,
      CONSTRAINT chk_reservacion_comensales_etapa4 CHECK (comensales > 0),
      CONSTRAINT chk_reservacion_contacto_etapa4 CHECK (
        (contacto_tipo = 'ninguno' AND contacto IS NULL)
        OR
        (contacto_tipo IN ('email','telefono') AND contacto IS NOT NULL AND TRIM(contacto) <> '')
      ),
      CONSTRAINT chk_reservacion_hold_etapa4
        CHECK (estado <> 'pendiente_verificacion' OR hold_expires_at IS NOT NULL),
      UNIQUE KEY uq_reservaciones_request_token (request_token),
      INDEX idx_reservaciones_fecha_estado_hora (fecha, estado, hora),
      INDEX idx_reservaciones_contacto_horario (contacto_tipo, contacto, fecha, hora, estado),
      INDEX idx_reservaciones_retenciones_vencidas (estado, hold_expires_at),
      INDEX idx_reservaciones_reemplazo (reemplaza_reservacion_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci"
);

$filasAnteriores = etapa4Rows(
    $db,
    "SELECT id, nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
            comentario_admin, request_token, hold_expires_at, status_changed_at,
            created_at, updated_at, estado
     FROM `{$tablaAnterior}`
     ORDER BY id ASC"
);

$conteosMigracion = [
    'llego_a_en_curso' => 0,
    'llego_a_confirmada' => 0,
    'holds_expirados' => 0,
    'holds_sin_fecha_reparados' => 0,
    'contactos_normalizados' => 0,
    'contactos_invalidados' => 0,
    'origen_landing' => 0,
    'origen_admin' => 0,
    'estado_changed_at_desde_status' => 0,
    'estado_changed_at_desde_updated' => 0,
    'estado_changed_at_desde_created' => 0,
    'estado_changed_at_desde_ahora' => 0,
];

$estadosNuevos = [
    'pendiente_verificacion',
    'confirmada',
    'en_curso',
    'completada',
    'cancelada',
    'no_show',
    'expirada',
    'reemplazada',
];

foreach ($filasAnteriores as $fila) {
    $id = (int)$fila['id'];
    $estadoAnterior = (string)$fila['estado'];
    $estado = $estadoAnterior;

    if ($estadoAnterior === 'llego') {
        if (isset($ticketsAbiertos[$id])) {
            $estado = 'en_curso';
            $conteosMigracion['llego_a_en_curso']++;
        } else {
            $estado = 'confirmada';
            $conteosMigracion['llego_a_confirmada']++;
        }
    }

    $hold = $fila['hold_expires_at'] !== null ? (string)$fila['hold_expires_at'] : null;
    if ($estado === 'pendiente_verificacion') {
        if ($hold === null || trim($hold) === '') {
            $hold = $ahoraObjeto->modify('+' . ReservacionConfig::RESERVATION_HOLD_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $conteosMigracion['holds_sin_fecha_reparados']++;
        } elseif (new DateTimeImmutable($hold, ReservacionConfig::timezone()) <= $ahoraObjeto) {
            $estado = 'expirada';
            $hold = null;
            $conteosMigracion['holds_expirados']++;
        }
    } else {
        $hold = null;
    }

    if (!in_array($estado, $estadosNuevos, true)) {
        throw new RuntimeException("Estado heredado no reconocido en reservación {$id}: {$estado}");
    }

    $tipoAnterior = strtolower(trim((string)$fila['contacto_tipo']));
    $contactoAnterior = trim((string)($fila['contacto'] ?? ''));
    $tipo = 'ninguno';
    $contacto = null;
    if (in_array($tipoAnterior, ['email', 'telefono'], true) && $contactoAnterior !== '') {
        try {
            $contacto = ContactoService::normalizar($tipoAnterior, $contactoAnterior);
            $tipo = $tipoAnterior;
            if ($contacto !== $contactoAnterior) {
                $conteosMigracion['contactos_normalizados']++;
            }
        } catch (InvalidArgumentException) {
            $conteosMigracion['contactos_invalidados']++;
        }
    } else {
        $conteosMigracion['contactos_invalidados']++;
    }

    $token = trim((string)($fila['request_token'] ?? ''));
    $token = $token === '' ? null : $token;
    $origen = ($token !== null || isset($reservacionesPublicas[$id])) ? 'landing' : 'admin';
    $conteosMigracion[$origen === 'landing' ? 'origen_landing' : 'origen_admin']++;

    $estadoChanged = null;
    $fuenteEstadoChanged = 'ahora';
    foreach ([
        'status_changed_at' => 'estado_changed_at_desde_status',
        'updated_at' => 'estado_changed_at_desde_updated',
        'created_at' => 'estado_changed_at_desde_created',
    ] as $campo => $contador) {
        if (etapa4FechaValida($fila[$campo] ?? null)) {
            $estadoChanged = (string)$fila[$campo];
            $fuenteEstadoChanged = $contador;
            break;
        }
    }
    if ($estadoChanged === null) {
        $estadoChanged = $ahora;
        $fuenteEstadoChanged = 'estado_changed_at_desde_ahora';
    }
    $conteosMigracion[$fuenteEstadoChanged]++;

    $createdAt = etapa4FechaValida($fila['created_at'] ?? null)
        ? (string)$fila['created_at']
        : $ahora;
    $updatedAt = etapa4FechaValida($fila['updated_at'] ?? null)
        ? (string)$fila['updated_at']
        : null;
    $comensales = max(1, (int)$fila['comensales']);

    $valores = [
        (string)$id,
        etapa4SqlValue($db, $fila['nombre']),
        etapa4SqlValue($db, $tipo),
        etapa4SqlValue($db, $contacto),
        etapa4SqlValue($db, $fila['fecha']),
        etapa4SqlValue($db, $fila['hora']),
        (string)$comensales,
        etapa4SqlValue($db, $fila['nota']),
        etapa4SqlValue($db, $fila['comentario_admin']),
        etapa4SqlValue($db, $origen),
        etapa4SqlValue($db, $estado),
        etapa4SqlValue($db, $hold),
        'NULL',
        etapa4SqlValue($db, $token),
        etapa4SqlValue($db, $estadoChanged),
        etapa4SqlValue($db, $createdAt),
        etapa4SqlValue($db, $updatedAt),
    ];

    etapa4Exec(
        $db,
        "INSERT INTO `{$tablaNueva}`
         (id, nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
          comentario_admin, origen, estado, hold_expires_at, reemplaza_reservacion_id,
          request_token, estado_changed_at, created_at, updated_at)
         VALUES (" . implode(', ', $valores) . ')'
    );
}

$referencias = etapa4Rows(
    $db,
    "SELECT TABLE_NAME, CONSTRAINT_NAME
     FROM information_schema.KEY_COLUMN_USAGE
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND REFERENCED_TABLE_NAME = 'reservaciones'
       AND TABLE_NAME <> 'reservaciones'"
);
foreach ($referencias as $referencia) {
    $tabla = preg_replace('/[^A-Za-z0-9_]/', '', (string)$referencia['TABLE_NAME']);
    $constraint = preg_replace('/[^A-Za-z0-9_]/', '', (string)$referencia['CONSTRAINT_NAME']);
    if ($tabla === '' || $constraint === '') {
        continue;
    }
    etapa4Exec($db, "ALTER TABLE `{$tabla}` DROP FOREIGN KEY `{$constraint}`");
}

etapa4Exec($db, "DROP TABLE `{$tablaAnterior}`");
etapa4Exec($db, "RENAME TABLE `{$tablaNueva}` TO `{$tablaAnterior}`");

etapa4Exec(
    $db,
    "CREATE TRIGGER trg_reservaciones_no_auto_reemplazo_insert
     BEFORE INSERT ON reservaciones
     FOR EACH ROW
     BEGIN
       IF NEW.reemplaza_reservacion_id IS NOT NULL
          AND NEW.id IS NOT NULL
          AND NEW.reemplaza_reservacion_id = NEW.id THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una reservacion no puede reemplazarse a si misma';
       END IF;
     END"
);
etapa4Exec(
    $db,
    "CREATE TRIGGER trg_reservaciones_no_auto_reemplazo_update
     BEFORE UPDATE ON reservaciones
     FOR EACH ROW
     BEGIN
       IF NEW.reemplaza_reservacion_id IS NOT NULL
          AND NEW.reemplaza_reservacion_id = NEW.id THEN
         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Una reservacion no puede reemplazarse a si misma';
       END IF;
     END"
);

etapa4Exec(
    $db,
    "ALTER TABLE reservacion_mesas
     ADD CONSTRAINT fk_reservacion_mesas_reservacion
     FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE CASCADE"
);
etapa4Exec(
    $db,
    "ALTER TABLE verificaciones_contacto
     ADD CONSTRAINT fk_verificacion_reservacion
     FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE CASCADE"
);
etapa4Exec(
    $db,
    "ALTER TABLE tickets
     ADD CONSTRAINT tickets_ibfk_1
     FOREIGN KEY (reservacion_id) REFERENCES reservaciones(id) ON DELETE SET NULL"
);

$conteosDespues = [];
foreach (['reservaciones', 'reservacion_mesas', 'verificaciones_contacto', 'tickets', 'ticket_mesas'] as $tabla) {
    $fila = etapa4Rows($db, "SELECT COUNT(*) AS total FROM `{$tabla}`")[0];
    $conteosDespues[$tabla] = (int)$fila['total'];
}

$estadosDespues = [];
foreach (etapa4Rows($db, 'SELECT estado, COUNT(*) AS total FROM reservaciones GROUP BY estado ORDER BY estado') as $fila) {
    $estadosDespues[(string)$fila['estado']] = (int)$fila['total'];
}

$resultado = [
    'ok' => true,
    'database' => $nombreBase,
    'conteos_antes' => $conteosAntes,
    'conteos_despues' => $conteosDespues,
    'estados_antes' => $estadosAntes,
    'estados_despues' => $estadosDespues,
    'migracion' => $conteosMigracion,
    'reglas' => [
        'origen' => 'landing si existe request_token o verificación vinculada; admin en otro caso',
        'estado_changed_at' => 'status_changed_at, luego updated_at, luego created_at, luego NOW()',
        'hold' => 'solo los pendientes conservan hold; holds vencidos pasan a expirada',
        'reemplazo' => 'NULL en todos los registros heredados por ausencia de evidencia',
    ],
];

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
