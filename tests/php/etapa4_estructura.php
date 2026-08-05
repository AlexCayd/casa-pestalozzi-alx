<?php

declare(strict_types=1);

$expectCounts = null;
$allowActive = false;
foreach ($argv as $argumento) {
    if (str_starts_with($argumento, '--db=')) {
        putenv('ETAPA4_TEST_DB_NAME=' . substr($argumento, 5));
    } elseif (str_starts_with($argumento, '--expect=')) {
        $expectCounts = array_map('intval', explode(',', substr($argumento, 9)));
    } elseif ($argumento === '--allow-active') {
        $allowActive = true;
    }
}

$base = getenv('ETAPA4_TEST_DB_NAME') ?: '';
if ($base === '') {
    fwrite(STDERR, "Uso: php etapa4_estructura.php --db=casa_pestalozzi_etapa4_test [--allow-active]\n");
    exit(2);
}
if ($base === 'casa-pestalozzi' && !$allowActive) {
    fwrite(STDERR, "La base activa requiere --allow-active de forma explícita.\n");
    exit(2);
}
if ($allowActive) {
    putenv('ETAPA45_ALLOW_ACTIVE=YES');
}

require __DIR__ . '/bootstrap_etapa4.php';

use Model\ActiveRecord;
use Services\ReservacionConfig;

$db = ActiveRecord::getDB();
mysqli_report(MYSQLI_REPORT_OFF);
$casos = [];
$errores = [];
$marker = 'ETAPA4_STRUCTURE_' . bin2hex(random_bytes(5));

/** @return array<int, array<string, mixed>> */
function estructuraRows(mysqli $db, string $sql): array
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

function estructuraScalar(mysqli $db, string $sql): int
{
    $fila = estructuraRows($db, $sql)[0] ?? [];
    return (int)array_values($fila)[0];
}

function estructuraCaso(array &$casos, array &$errores, string $nombre, bool $ok, string $detalle = ''): void
{
    $casos[$nombre] = ['ok' => $ok, 'detalle' => $detalle];
    if (!$ok) {
        $errores[] = $nombre . ($detalle !== '' ? ': ' . $detalle : '');
    }
}

function estructuraExec(mysqli $db, string $sql): bool
{
    return $db->query($sql) !== false;
}

try {
    $esperadas = [
        'id', 'nombre', 'contacto_tipo', 'contacto', 'fecha', 'hora',
        'comensales', 'nota', 'comentario_admin', 'origen', 'request_token',
        'hold_expires_at', 'estado', 'reemplaza_reservacion_id',
        'estado_changed_at', 'created_at', 'updated_at',
    ];
    $actuales = array_column(
        estructuraRows(
            $db,
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservaciones'
             ORDER BY ORDINAL_POSITION"
        ),
        'COLUMN_NAME'
    );
    estructuraCaso(
        $casos,
        $errores,
        'columnas canónicas',
        $actuales === $esperadas,
        json_encode(['esperadas' => $esperadas, 'actuales' => $actuales], JSON_UNESCAPED_UNICODE)
    );

    // PRUEBA_DE_MIGRACION: estos nombres se conservan únicamente para demostrar
    // que el esquema legado quedó fuera de la instalación canónica.
    $retiradas = [
        'llego', 'arrived_at', 'confirmed_at', 'completed_at',
        'request_fingerprint', 'last_modified_by', 'last_modified_source',
        'last_change_reason', 'status_changed_at',
    ];
    $retiradasEncontradas = array_values(array_intersect($retiradas, $actuales));
    estructuraCaso($casos, $errores, 'campos heredados retirados', $retiradasEncontradas === [], json_encode($retiradasEncontradas, JSON_UNESCAPED_UNICODE));

    $estadoType = (string)(estructuraRows(
        $db,
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservaciones'
           AND COLUMN_NAME = 'estado'"
    )[0]['COLUMN_TYPE'] ?? '');
    $estadosEsperados = [
        'pendiente_verificacion', 'confirmada', 'en_curso', 'completada',
        'cancelada', 'no_show', 'expirada', 'reemplazada',
    ];
    $estadosOk = str_starts_with($estadoType, 'enum(')
        && !str_contains($estadoType, "'llego'")
        && array_reduce($estadosEsperados, static fn(bool $ok, string $estado): bool => $ok && str_contains($estadoType, "'{$estado}'"), true);
    estructuraCaso($casos, $errores, 'estados definitivos', $estadosOk, $estadoType);

    $origenType = (string)(estructuraRows(
        $db,
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservaciones'
           AND COLUMN_NAME = 'origen'"
    )[0]['COLUMN_TYPE'] ?? '');
    estructuraCaso($casos, $errores, 'origen limitado', $origenType === "enum('landing','admin')", $origenType);

    $indices = array_column(
        estructuraRows(
            $db,
            "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservaciones'"
        ),
        'INDEX_NAME'
    );
    $indicesEsperados = [
        'PRIMARY', 'uq_reservaciones_request_token',
        'idx_reservaciones_fecha_estado_hora',
        'idx_reservaciones_contacto_horario',
        'idx_reservaciones_retenciones_vencidas',
        'idx_reservaciones_reemplazo',
    ];
    estructuraCaso($casos, $errores, 'índices canónicos', count(array_diff($indicesEsperados, $indices)) === 0, json_encode($indices, JSON_UNESCAPED_UNICODE));

    $fkRows = estructuraRows(
        $db,
        "SELECT kcu.TABLE_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME,
                kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE
         FROM information_schema.KEY_COLUMN_USAGE kcu
         INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
           ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
          AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
          AND rc.TABLE_NAME = kcu.TABLE_NAME
         WHERE kcu.CONSTRAINT_SCHEMA = DATABASE()
           AND kcu.REFERENCED_TABLE_NAME IN ('reservaciones','mesas','tickets')"
    );
    $fkOk = false;
    foreach ($fkRows as $fk) {
        if (
            $fk['TABLE_NAME'] === 'reservaciones'
            && $fk['COLUMN_NAME'] === 'reemplaza_reservacion_id'
            && $fk['REFERENCED_TABLE_NAME'] === 'reservaciones'
            && $fk['DELETE_RULE'] === 'RESTRICT'
        ) {
            $fkOk = true;
        }
    }
    foreach ([
        ['reservacion_mesas', 'reservacion_id', 'CASCADE'],
        ['verificaciones_contacto', 'reservacion_id', 'CASCADE'],
        ['tickets', 'reservacion_id', 'SET NULL'],
    ] as [$tabla, $columna, $deleteRule]) {
        $encontrada = false;
        foreach ($fkRows as $fk) {
            $encontrada = $encontrada
                || ($fk['TABLE_NAME'] === $tabla && $fk['COLUMN_NAME'] === $columna && $fk['DELETE_RULE'] === $deleteRule);
        }
        $fkOk = $fkOk && $encontrada;
    }
    estructuraCaso($casos, $errores, 'relaciones foráneas', $fkOk, json_encode($fkRows, JSON_UNESCAPED_UNICODE));

    $checks = array_column(
        estructuraRows(
            $db,
            "SELECT cc.CHECK_CLAUSE
             FROM information_schema.CHECK_CONSTRAINTS cc
             INNER JOIN information_schema.TABLE_CONSTRAINTS tc
               ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
              AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = DATABASE()
               AND tc.TABLE_NAME = 'reservaciones'
               AND tc.CONSTRAINT_TYPE = 'CHECK'"
        ),
        'CHECK_CLAUSE'
    );
    $checksTexto = implode(' | ', $checks);
    estructuraCaso($casos, $errores, 'checks de dominio', str_contains($checksTexto, 'comensales') && str_contains($checksTexto, 'hold_expires_at'), $checksTexto);

    $conteos = [];
    foreach (['reservaciones', 'reservacion_mesas', 'verificaciones_contacto', 'tickets', 'ticket_mesas'] as $tabla) {
        $conteos[$tabla] = estructuraScalar($db, "SELECT COUNT(*) FROM {$tabla}");
    }
    estructuraCaso(
        $casos,
        $errores,
        $expectCounts === null ? 'conteos de instalacion' : 'conteos preservados',
        $expectCounts === null
            ? array_sum($conteos) > 0
            : array_values($conteos) === $expectCounts,
        $expectCounts === null
            ? json_encode($conteos, JSON_UNESCAPED_UNICODE)
            : json_encode(['esperados' => $expectCounts, 'actuales' => $conteos], JSON_UNESCAPED_UNICODE)
    );

    $huérfanas = [
        'reservacion_mesas' => estructuraScalar($db, 'SELECT COUNT(*) FROM reservacion_mesas rm LEFT JOIN reservaciones r ON r.id = rm.reservacion_id WHERE r.id IS NULL'),
        'verificaciones_contacto' => estructuraScalar($db, 'SELECT COUNT(*) FROM verificaciones_contacto vc LEFT JOIN reservaciones r ON r.id = vc.reservacion_id WHERE vc.reservacion_id IS NOT NULL AND r.id IS NULL'),
        'tickets' => estructuraScalar($db, 'SELECT COUNT(*) FROM tickets t LEFT JOIN reservaciones r ON r.id = t.reservacion_id WHERE t.reservacion_id IS NOT NULL AND r.id IS NULL'),
        'ticket_mesas' => estructuraScalar($db, 'SELECT COUNT(*) FROM ticket_mesas tm LEFT JOIN tickets t ON t.id = tm.ticket_id WHERE t.id IS NULL'),
    ];
    estructuraCaso($casos, $errores, 'sin filas huérfanas', array_sum($huérfanas) === 0, json_encode($huérfanas, JSON_UNESCAPED_UNICODE));

    $fecha = ReservacionConfig::ahora()->modify('+10 days')->format('Y-m-d');
    $token = $marker . '_TOKEN';
    $insert = $db->prepare(
        "INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado, request_token)
         VALUES ('{$marker}', 'ninguno', NULL, '{$fecha}', '12:00:00', 2, 'admin', 'confirmada', ?)"
    );
    $insert->bind_param('s', $token);
    $insertOk = $insert->execute();
    $primeraId = (int)$insert->insert_id;
    $insert->close();
    estructuraCaso($casos, $errores, 'inserción canónica', $insertOk, $db->error);

    $duplicate = $db->prepare(
        "INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado, request_token)
         VALUES ('{$marker} DUP', 'ninguno', NULL, '{$fecha}', '12:30:00', 2, 'admin', 'confirmada', ?)"
    );
    $duplicate->bind_param('s', $token);
    $duplicateOk = $duplicate->execute();
    $duplicate->close();
    estructuraCaso($casos, $errores, 'request_token único', !$duplicateOk, $db->error);

    $invalidContact = estructuraExec($db, "INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado) VALUES ('{$marker} CONTACTO', 'ninguno', 'N/A', '{$fecha}', '13:00:00', 2, 'admin', 'confirmada')");
    $invalidPeople = estructuraExec($db, "INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado) VALUES ('{$marker} PERSONAS', 'ninguno', NULL, '{$fecha}', '13:30:00', 0, 'admin', 'confirmada')");
    $invalidHold = estructuraExec($db, "INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado) VALUES ('{$marker} HOLD', 'ninguno', NULL, '{$fecha}', '14:00:00', 2, 'admin', 'pendiente_verificacion')");
    estructuraCaso($casos, $errores, 'checks rechazan datos inválidos', !$invalidContact && !$invalidPeople && !$invalidHold, json_encode([
        'contacto' => $invalidContact,
        'comensales' => $invalidPeople,
        'hold' => $invalidHold,
    ], JSON_UNESCAPED_UNICODE));

    $replacementToken = $marker . '_REPLACEMENT';
    $replacement = $db->prepare(
        "INSERT INTO reservaciones (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado, request_token)
         VALUES ('{$marker} REEMPLAZO', 'ninguno', NULL, '{$fecha}', '14:30:00', 2, 'landing', 'confirmada', ?)"
    );
    $replacement->bind_param('s', $replacementToken);
    $replacement->execute();
    $replacementId = (int)$replacement->insert_id;
    $replacement->close();
    $linkOk = estructuraExec($db, "UPDATE reservaciones SET reemplaza_reservacion_id = {$primeraId} WHERE id = {$replacementId}");
    $selfOk = estructuraExec($db, "UPDATE reservaciones SET reemplaza_reservacion_id = {$replacementId} WHERE id = {$replacementId}");
    $deleteParentOk = estructuraExec($db, "DELETE FROM reservaciones WHERE id = {$primeraId}");
    estructuraCaso($casos, $errores, 'reemplazo, no auto-reemplazo y ON DELETE RESTRICT', $linkOk && !$selfOk && !$deleteParentOk, $db->error);
} catch (Throwable $error) {
    $errores[] = 'excepción: ' . $error->getMessage();
} finally {
    $db->query("DELETE FROM reservaciones WHERE nombre LIKE '{$marker}%' AND reemplaza_reservacion_id IS NOT NULL");
    $db->query("DELETE FROM reservaciones WHERE nombre LIKE '{$marker}%'");
}

echo json_encode([
    'ok' => $errores === [],
    'database' => $base,
    'casos' => $casos,
    'errores' => $errores,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($errores === [] ? 0 : 1);
