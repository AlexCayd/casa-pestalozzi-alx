<?php

declare(strict_types=1);

/**
 * Carreras cruzadas de Etapa 7.5.
 *
 * Cada worker abre su propio proceso PHP y, por tanto, su propia conexiÃ³n
 * MySQL. El runner exige una base de pruebas explÃ­cita para no tocar datos
 * manuales por accidente.
 */

$database = '';
$worker = false;
foreach ($argv ?? [] as $argumento) {
    if (str_starts_with((string)$argumento, '--db=')) {
        $database = substr((string)$argumento, 5);
    } elseif ($argumento === '--worker') {
        $worker = true;
    }
}

if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1) {
    fwrite(STDERR, "Uso: php etapa7_5_concurrencia_cruzada.php --db=BASE_DE_PRUEBAS\n");
    exit(2);
}
if ($database === 'casa-pestalozzi' || $database === 'casa_pestalozzi') {
    fwrite(STDERR, "La suite no permite apuntar a la base activa.\n");
    exit(2);
}

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);
ini_set('session.save_path', dirname(__DIR__));

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\Reservacion;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\PosReservacionQueryService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexiÃ³n MySQL para la suite de Etapa 7.5.\n");
    exit(2);
}

$workerScenario = (string)(getenv('ETAPA7_5_SCENARIO') ?: '');
if ($worker) {
    $barrier = (string)(getenv('ETAPA7_5_BARRIER') ?: '');
    $deadline = microtime(true) + 15;
    while ($barrier !== '' && !is_file($barrier) && microtime(true) < $deadline) {
        usleep(10000);
    }
    if ($barrier !== '' && !is_file($barrier)) {
        fwrite(STDERR, "No se abriÃ³ la barrera de concurrencia.\n");
        exit(1);
    }

    $kind = (string)(getenv('ETAPA7_5_KIND') ?: '');
    $tipo = 'email';
    $contacto = (string)(getenv('ETAPA7_5_CONTACT') ?: '');
    $session = [
        'contacto_tipo' => $tipo,
        'contacto' => $contacto,
    ];

    try {
        $resultado = match ($kind) {
            'confirmar' => ReservacionPublicaService::confirmarReemplazo([
                'request_token' => (string)getenv('ETAPA7_5_REPLACEMENT_TOKEN'),
                'codigo' => (string)getenv('ETAPA7_5_OTP'),
            ], $session),
            'cancelar' => ReservacionPublicaService::cancelar(
                (int)getenv('ETAPA7_5_ORIGINAL_ID'),
                $session
            ),
            'pos' => PuntoVentaReservacionService::comenzar(
                (int)getenv('ETAPA7_5_ORIGINAL_ID'),
                1,
                null
            ),
            'expirar' => ReservacionPublicaService::expirarRetenciones(100, false),
            'modificar' => ReservacionPublicaService::crearReemplazo([
                'reservacion_id' => (int)getenv('ETAPA7_5_ORIGINAL_ID'),
                'fecha' => (string)getenv('ETAPA7_5_TARGET_DATE'),
                'hora' => (string)getenv('ETAPA7_5_TARGET_HOUR'),
                'personas' => 2,
                'notas' => (string)getenv('ETAPA7_5_NOTE'),
                'request_token' => (string)getenv('ETAPA7_5_REPLACEMENT_TOKEN'),
            ], $session),
            default => throw new RuntimeException('Worker desconocido: ' . $kind),
        };

        // Nunca imprimir códigos OTP, aunque el entorno de prueba tenga
        // habilitado preview_code.
        unset($resultado['preview_code']);
        echo json_encode([
            'ok_proceso' => true,
            'scenario' => $workerScenario,
            'kind' => $kind,
            'resultado' => $resultado,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        echo json_encode([
            'ok_proceso' => false,
            'scenario' => $workerScenario,
            'kind' => $kind,
            'error' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(1);
    }
}

$runId = strtoupper(bin2hex(random_bytes(5)));
$fixturePrefix = 'ETAPA7_5_' . $runId;
$contactPrefix = 'etapa75-' . strtolower($runId);
$barriers = [];
$sessionFilesBefore = array_fill_keys(glob(dirname(__DIR__) . '/sess_*') ?: [], true);
$passed = 0;
$failed = [];

$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $message;
};

$query = static function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException($db->error . ' â€” ' . $sql);
    }
    return $result;
};
$escape = static fn(string $value): string => $db->real_escape_string($value);
$setClock = static function (string $value): void {
    $_ENV['RESERVATION_TEST_NOW'] = $value;
    $_SERVER['RESERVATION_TEST_NOW'] = $value;
    putenv('RESERVATION_TEST_NOW=' . $value);
};
$rowById = static function (int $id) use ($query): ?array {
    if ($id < 1) {
        return null;
    }
    $result = $query("SELECT * FROM reservaciones WHERE id = {$id} LIMIT 1");
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};
$rowByToken = static function (string $token) use ($query, $escape): ?array {
    $token = $escape($token);
    $result = $query("SELECT * FROM reservaciones WHERE request_token = '{$token}' LIMIT 1");
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};
$count = static function (string $table, string $where) use ($query): int {
    $result = $query("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}");
    $row = $result->fetch_assoc() ?: [];
    $result->free();
    return (int)($row['total'] ?? 0);
};
$cleanup = static function () use ($db, $contactPrefix, $fixturePrefix): void {
    $contactLike = $db->real_escape_string($contactPrefix . '%@example.test');
    $nameLike = $db->real_escape_string($fixturePrefix . '%');

    $ticketIds = [];
    $tickets = $db->query("SELECT id FROM tickets WHERE nombre LIKE '{$nameLike}'");
    if ($tickets) {
        while ($fila = $tickets->fetch_assoc()) {
            $ticketIds[] = (int)$fila['id'];
        }
        $tickets->free();
    }
    if ($ticketIds !== []) {
        $ids = implode(',', array_map('intval', $ticketIds));
        $db->query("DELETE FROM ticket_mesas WHERE ticket_id IN ({$ids})");
        $db->query("DELETE FROM ticket_items WHERE ticket_id IN ({$ids})");
        $db->query("DELETE FROM ticket_pagos WHERE ticket_id IN ({$ids})");
        $db->query("DELETE FROM feedback_tokens WHERE ticket_id IN ({$ids})");
        $db->query("DELETE FROM tickets WHERE id IN ({$ids})");
    }

    $db->query("DELETE FROM verificaciones_contacto WHERE contacto LIKE '{$contactLike}'");
    $db->query(
        "DELETE rm FROM reservacion_mesas rm
         INNER JOIN reservaciones r ON r.id = rm.reservacion_id
         WHERE r.contacto LIKE '{$contactLike}'"
    );
    $db->query(
        "DELETE FROM reservaciones
         WHERE contacto LIKE '{$contactLike}'
           AND reemplaza_reservacion_id IS NOT NULL"
    );
    $db->query("DELETE FROM reservaciones WHERE contacto LIKE '{$contactLike}'");
};

/** @return array{fecha:string,hora:string} */
$findSlot = static function (
    bool $today,
    int $personas = 2,
    array $used = [],
    int $maxTodayLeadMinutes = 0
) use ($query): array {
    $now = ReservacionConfig::ahora();
    $baseDate = $now->format('Y-m-d');
    $usedKeys = array_fill_keys(array_map(
        static fn(array $slot): string => $slot['fecha'] . ' ' . $slot['hora'],
        $used
    ), true);

    for ($offset = $today ? 0 : 1; $offset <= 80; $offset++) {
        $date = $now->modify('+' . $offset . ' days')->format('Y-m-d');
        if ($today && $date !== $baseDate) {
            break;
        }
        $calendar = HorarioReservacionService::resolverFecha($date, $now);
        if (!($calendar['reservable'] ?? false)) {
            continue;
        }
        foreach ((array)($calendar['horarios_candidatos'] ?? []) as $candidate) {
            $hour = substr((string)$candidate, 0, 5);
            $key = $date . ' ' . $hour;
            if ($hour === '' || isset($usedKeys[$key])) {
                continue;
            }
            $start = new DateTimeImmutable($date . ' ' . $hour . ':00', ReservacionConfig::timezone());
            if ($today) {
                $lead = (int)ceil(($start->getTimestamp() - $now->getTimestamp()) / 60);
                if ($lead < ReservacionConfig::ANTICIPACION_MINIMA_MINUTOS
                    || ($maxTodayLeadMinutes > 0 && $lead > $maxTodayLeadMinutes)) {
                    continue;
                }
            }
            $availability = DisponibilidadReservacionService::evaluarHorario(
                $date,
                $hour,
                $personas,
                0,
                false,
                true
            );
            if (($availability['ok'] ?? false) === true) {
                return ['fecha' => $date, 'hora' => $hour];
            }
        }
    }

    throw new RuntimeException('No se encontrÃ³ un turno libre para la suite cruzada.');
};

$makeConfirmed = static function (array $slot, string $label) use (
    &$assert,
    $contactPrefix,
    $fixturePrefix
): array {
    $contact = $contactPrefix . '-' . strtolower($label) . '@example.test';
    $token = $fixturePrefix . '_' . $label . '_ORIG_' . strtoupper(bin2hex(random_bytes(5)));
    $payload = [
        'nombre' => $fixturePrefix . ' ' . $label . ' Original',
        'tipo_contacto' => 'email',
        'contacto' => $contact,
        'fecha' => $slot['fecha'],
        'hora' => $slot['hora'],
        'personas' => 2,
        'notas' => 'fixture de concurrencia',
        'request_token' => $token,
    ];
    $pending = ReservacionPublicaService::crearRetencion($payload);
    $assert(($pending['ok'] ?? false) === true, $label . ': crear original');
    if (!($pending['ok'] ?? false)) {
        throw new RuntimeException('No se pudo crear la original de ' . $label . '.');
    }
    $row = ($GLOBALS['__etapa75_row_by_token'] ?? null) instanceof Closure
        ? $GLOBALS['__etapa75_row_by_token']($token)
        : null;
    if (!is_array($row)) {
        throw new RuntimeException('No se pudo leer la original de ' . $label . '.');
    }
    $confirm = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $contact,
        'codigo' => (string)($pending['preview_code'] ?? ''),
        'request_token' => $token,
    ]);
    $assert(($confirm['ok'] ?? false) === true, $label . ': confirmar original');
    if (!($confirm['ok'] ?? false)) {
        throw new RuntimeException('No se pudo confirmar la original de ' . $label . '.');
    }

    return [
        'id' => (int)$row['id'],
        'contacto' => $contact,
        'token' => $token,
        'slot' => $slot,
        'mesa_count' => null,
    ];
};

// Se expone sólo dentro del proceso padre para evitar pasar el mysqli por
// closures anidadas; los workers nunca ejecutan este bloque.
$GLOBALS['__etapa75_row_by_token'] = $rowByToken;

$makeReplacement = static function (array $original, array $slot, string $label, string $note = '') use (
    &$assert,
    $fixturePrefix
): array {
    $token = $fixturePrefix . '_' . $label . '_REPL_' . strtoupper(bin2hex(random_bytes(5)));
    $result = ReservacionPublicaService::crearReemplazo([
        'reservacion_id' => $original['id'],
        'fecha' => $slot['fecha'],
        'hora' => $slot['hora'],
        'personas' => 2,
        'notas' => $note !== '' ? $note : 'reemplazo de concurrencia',
        'request_token' => $token,
    ], [
        'contacto_tipo' => 'email',
        'contacto' => $original['contacto'],
    ]);
    $assert(($result['ok'] ?? false) === true, $label . ': crear reemplazo');
    if (!($result['ok'] ?? false)) {
        throw new RuntimeException('No se pudo crear el reemplazo de ' . $label . '.');
    }
    $row = ($GLOBALS['__etapa75_row_by_token'])($token);
    if (!is_array($row)) {
        throw new RuntimeException('No se pudo leer el reemplazo de ' . $label . '.');
    }

    return [
        'id' => (int)$row['id'],
        'token' => $token,
        'otp' => (string)($result['preview_code'] ?? ''),
        'slot' => $slot,
    ];
};

/**
 * @param array<int, array<string, string>> $workers
 * @return array<int, array<string, mixed>>
 */
$runRace = static function (string $scenario, array $workers) use (&$barriers, $database): array {
    $barrier = dirname(__DIR__) . '/.etapa7_5_' . strtolower($scenario) . '_' . bin2hex(random_bytes(5)) . '.start';
    $barriers[] = $barrier;
    @unlink($barrier);
    $descriptor = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $processes = [];
    $script = __FILE__;

    foreach ($workers as $index => $workerConfig) {
        $environment = [
            'ETAPA7_5_SCENARIO' => $scenario,
            'ETAPA7_5_BARRIER' => $barrier,
            'ETAPA7_5_KIND' => $workerConfig['kind'],
            'ETAPA7_5_CONTACT' => $workerConfig['contact'],
            'ETAPA7_5_ORIGINAL_ID' => (string)$workerConfig['original_id'],
            'ETAPA7_5_REPLACEMENT_TOKEN' => (string)($workerConfig['replacement_token'] ?? ''),
            'ETAPA7_5_OTP' => (string)($workerConfig['otp'] ?? ''),
            'ETAPA7_5_TARGET_DATE' => (string)($workerConfig['target_date'] ?? ''),
            'ETAPA7_5_TARGET_HOUR' => (string)($workerConfig['target_hour'] ?? ''),
            'ETAPA7_5_NOTE' => (string)($workerConfig['note'] ?? ''),
            'RESERVATION_TEST_NOW' => (string)$workerConfig['clock'],
            'DB_NAME' => $database,
            'APP_ENV' => 'testing',
        ];
        foreach ($environment as $key => $value) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)
            . ' --db=' . escapeshellarg($database) . ' --worker';
        $pipes = [];
        $handle = proc_open($command, $descriptor, $pipes, dirname(__DIR__, 2));
        if (!is_resource($handle)) {
            throw new RuntimeException('No se pudo iniciar el worker ' . $index . '.');
        }
        fclose($pipes[0]);
        $processes[$index] = [
            'handle' => $handle,
            'pipes' => $pipes,
            'config' => $workerConfig,
        ];
    }

    if (file_put_contents($barrier, 'go') === false) {
        throw new RuntimeException('No se pudo abrir la barrera de ' . $scenario . '.');
    }

    $deadline = microtime(true) + 25;
    $terminaron = false;
    while (!$terminaron && microtime(true) < $deadline) {
        $terminaron = true;
        foreach ($processes as $process) {
            $status = proc_get_status($process['handle']);
            if ($status['running'] ?? false) {
                $terminaron = false;
                break;
            }
        }
        if (!$terminaron) {
            usleep(20000);
        }
    }
    if (!$terminaron) {
        foreach ($processes as $process) {
            @proc_terminate($process['handle']);
        }
        throw new RuntimeException('Timeout esperando workers de ' . $scenario . '.');
    }

    $resultados = [];
    foreach ($processes as $index => $process) {
        $stdout = stream_get_contents($process['pipes'][1]);
        $stderr = stream_get_contents($process['pipes'][2]);
        fclose($process['pipes'][1]);
        fclose($process['pipes'][2]);
        $exitCode = proc_close($process['handle']);
        $decoded = json_decode(trim((string)$stdout), true);
        $resultados[] = [
            'index' => $index,
            'exit_code' => $exitCode,
            'resultado' => is_array($decoded)
                ? $decoded
                : ['raw' => trim((string)$stdout), 'stderr' => trim((string)$stderr)],
        ];
    }
    @unlink($barrier);

    return $resultados;
};

$workerResults = static function (array $results): array {
    return array_values(array_map(
        static fn(array $item): array => (array)($item['resultado'] ?? []),
        $results
    ));
};

$resultSummary = [];
$exception = null;

try {
    $cleanup();
    $setClock('2026-11-01 11:20:00');

    // Carrera 1: confirmar reemplazo contra cancelar original.
    $posTodaySlot = $findSlot(true, 2, [], 70);
    $futureSlot = $findSlot(false);
    $fixture = $makeConfirmed($posTodaySlot, 'CANCEL');
    $setClock('2026-11-01 11:30:00');
    $replacement = $makeReplacement($fixture, $futureSlot, 'CANCEL');
    $race = $runRace('confirmar_cancelar', [
        [
            'kind' => 'confirmar',
            'contact' => $fixture['contacto'],
            'original_id' => $fixture['id'],
            'replacement_token' => $replacement['token'],
            'otp' => $replacement['otp'],
            'clock' => '2026-11-01 11:34:59',
        ],
        [
            'kind' => 'cancelar',
            'contact' => $fixture['contacto'],
            'original_id' => $fixture['id'],
            'clock' => '2026-11-01 11:34:59',
        ],
    ]);
    $originalAfter = $rowById($fixture['id']);
    $replacementAfter = $rowById($replacement['id']);
    $confirmWon = ($originalAfter['estado'] ?? '') === 'reemplazada'
        && ($replacementAfter['estado'] ?? '') === 'confirmada';
    $cancelWon = ($originalAfter['estado'] ?? '') === 'cancelada'
        && ($replacementAfter['estado'] ?? '') === 'expirada';
    $assert($confirmWon xor $cancelWon, 'Carrera 1: sólo una transición final válida');
    $assert(
        !(($originalAfter['estado'] ?? '') === 'cancelada' && ($replacementAfter['estado'] ?? '') === 'confirmada'),
        'Carrera 1: nunca cancelada + reemplazo confirmado'
    );
    $assert(
        !(($originalAfter['estado'] ?? '') === 'confirmada' && ($replacementAfter['estado'] ?? '') === 'confirmada'),
        'Carrera 1: nunca dos confirmadas'
    );
    $setClock('2026-11-01 11:34:59');
    $posRejected = PuntoVentaReservacionService::comenzar($fixture['id'], 1, null);
    $assert(($posRejected['ok'] ?? false) === false, 'POS: original cancelada o reemplazada no puede iniciar servicio');
    $posRead = PosReservacionQueryService::paraFecha($fixture['slot']['fecha'], $fixture['slot']['hora']);
    $operationalIds = array_map(
        static fn(array $row): int => (int)($row['reservacion_id'] ?? $row['id'] ?? 0),
        (array)($posRead['reservaciones_operativas'] ?? [])
    );
    $assert(!in_array($fixture['id'], $operationalIds, true), 'POS: original reemplazada/cancelada no aparece como operativa');
    $resultSummary['confirmar_vs_cancelar'] = [
        'workers' => $workerResults($race),
        'estado_original' => $originalAfter['estado'] ?? null,
        'estado_reemplazo' => $replacementAfter['estado'] ?? null,
        'pos_inicio_original' => $posRejected['codigo'] ?? null,
        'original_en_lista_operativa' => in_array($fixture['id'], $operationalIds, true),
    ];

    // Carrera 2: confirmar reemplazo contra inicio de servicio POS.
    $setClock('2026-11-01 11:20:00');
    $posSlot = $findSlot(true, 2, [], 70);
    $posFuture = $findSlot(false, 2, [$futureSlot]);
    $posFixture = $makeConfirmed($posSlot, 'POS');
    $setClock('2026-11-01 11:30:00');
    $posReplacement = $makeReplacement($posFixture, $posFuture, 'POS');
    $race = $runRace('confirmar_pos', [
        [
            'kind' => 'confirmar',
            'contact' => $posFixture['contacto'],
            'original_id' => $posFixture['id'],
            'replacement_token' => $posReplacement['token'],
            'otp' => $posReplacement['otp'],
            'clock' => '2026-11-01 11:34:59',
        ],
        [
            'kind' => 'pos',
            'contact' => $posFixture['contacto'],
            'original_id' => $posFixture['id'],
            'clock' => '2026-11-01 11:34:59',
        ],
    ]);
    $posOriginalAfter = $rowById($posFixture['id']);
    $posReplacementAfter = $rowById($posReplacement['id']);
    $openTicketCount = $count(
        'tickets',
        'reservacion_id = ' . $posFixture['id'] . " AND estado = 'abierto' AND closed_at IS NULL"
    );
    $confirmPosWon = ($posOriginalAfter['estado'] ?? '') === 'reemplazada'
        && ($posReplacementAfter['estado'] ?? '') === 'confirmada'
        && $openTicketCount === 0;
    $posWon = ($posOriginalAfter['estado'] ?? '') === 'en_curso'
        && ($posReplacementAfter['estado'] ?? '') === 'expirada'
        && $openTicketCount === 1;
    $assert($confirmPosWon xor $posWon, 'Carrera 2: confirmar o POS gana, nunca ambos');
    $assert(
        !(($posOriginalAfter['estado'] ?? '') === 'reemplazada' && $openTicketCount > 0),
        'Carrera 2: reemplazada no conserva ticket abierto'
    );
    $assert(
        !(($posOriginalAfter['estado'] ?? '') === 'en_curso' && ($posReplacementAfter['estado'] ?? '') === 'confirmada'),
        'Carrera 2: en curso no acepta reemplazo confirmado'
    );
    $posReplacementRead = PosReservacionQueryService::paraFecha(
        $posReplacement['slot']['fecha'],
        $posReplacement['slot']['hora']
    );
    $posReplacementOperationalIds = array_map(
        static fn(array $row): int => (int)($row['reservacion_id'] ?? $row['id'] ?? 0),
        (array)($posReplacementRead['reservaciones_operativas'] ?? [])
    );
    $assert(
        !$confirmPosWon || in_array($posReplacement['id'], $posReplacementOperationalIds, true),
        'POS: reemplazo confirmado aparece como operativo'
    );
    $assert(
        !$posWon || !in_array($posReplacement['id'], $posReplacementOperationalIds, true),
        'POS: reemplazo expirado no aparece como operativo'
    );
    $resultSummary['confirmar_vs_pos'] = [
        'workers' => $workerResults($race),
        'estado_original' => $posOriginalAfter['estado'] ?? null,
        'estado_reemplazo' => $posReplacementAfter['estado'] ?? null,
        'tickets_abiertos_original' => $openTicketCount,
        'reemplazo_en_lista_operativa' => in_array($posReplacement['id'], $posReplacementOperationalIds, true),
    ];

    // Carrera 3: confirmar reemplazo contra expirador. El confirmador observa
    // 11:34:59 y el expirador 11:35:00 para que ambos resultados sean válidos.
    $setClock('2026-11-01 11:20:00');
    $expireOriginalSlot = $findSlot(false, 2, [$futureSlot, $posFuture]);
    $expireReplacementSlot = $findSlot(false, 2, [$futureSlot, $posFuture, $expireOriginalSlot]);
    $expireFixture = $makeConfirmed($expireOriginalSlot, 'EXPIRAR');
    $setClock('2026-11-01 11:40:00');
    $expireReplacement = $makeReplacement($expireFixture, $expireReplacementSlot, 'EXPIRAR');
    // El OTP canónico dura cinco minutos y el hold quince. Para probar la
    // carrera exacta del final del hold se extiende sólo este fixture hasta
    // un instante posterior al cierre del hold; no cambia la regla productiva.
    $query(
        "UPDATE verificaciones_contacto
         SET expires_at = '2026-11-01 11:55:01'
         WHERE reservacion_id = " . $expireReplacement['id'] . "
           AND invalidated_at IS NULL"
    );
    $race = $runRace('confirmar_expirar', [
        [
            'kind' => 'confirmar',
            'contact' => $expireFixture['contacto'],
            'original_id' => $expireFixture['id'],
            'replacement_token' => $expireReplacement['token'],
            'otp' => $expireReplacement['otp'],
            'clock' => '2026-11-01 11:54:59',
        ],
        [
            'kind' => 'expirar',
            'contact' => $expireFixture['contacto'],
            'original_id' => $expireFixture['id'],
            'clock' => '2026-11-01 11:55:00',
        ],
    ]);
    $expireOriginalAfter = $rowById($expireFixture['id']);
    $expireReplacementAfter = $rowById($expireReplacement['id']);
    $confirmExpireWon = ($expireOriginalAfter['estado'] ?? '') === 'reemplazada'
        && ($expireReplacementAfter['estado'] ?? '') === 'confirmada';
    $expirationWon = ($expireOriginalAfter['estado'] ?? '') === 'confirmada'
        && ($expireReplacementAfter['estado'] ?? '') === 'expirada';
    $assert($confirmExpireWon xor $expirationWon, 'Carrera 3: confirmar o expiración gana, nunca ambos');
    $assert(
        !(($expireOriginalAfter['estado'] ?? '') === 'confirmada'
            && ($expireReplacementAfter['estado'] ?? '') === 'confirmada'),
        'Carrera 3: no quedan original y reemplazo confirmados'
    );
    $resultSummary['confirmar_vs_expirar'] = [
        'workers' => $workerResults($race),
        'estado_original' => $expireOriginalAfter['estado'] ?? null,
        'estado_reemplazo' => $expireReplacementAfter['estado'] ?? null,
    ];

    // Carrera 4: dos modificaciones diferentes, además de token repetido y
    // token repetido con payload incompatible.
    $setClock('2026-11-01 11:20:00');
    $modOriginalSlot = $findSlot(false, 2, [$futureSlot, $posFuture, $expireOriginalSlot, $expireReplacementSlot]);
    $sameTokenSlot = $findSlot(false, 2, [$futureSlot, $posFuture, $expireOriginalSlot, $expireReplacementSlot, $modOriginalSlot]);
    $modTargetA = $findSlot(false, 2, [$futureSlot, $posFuture, $expireOriginalSlot, $expireReplacementSlot, $modOriginalSlot, $sameTokenSlot]);
    $modTargetB = $findSlot(false, 2, [$futureSlot, $posFuture, $expireOriginalSlot, $expireReplacementSlot, $modOriginalSlot, $sameTokenSlot, $modTargetA]);
    $modFixture = $makeConfirmed($modOriginalSlot, 'MODIFICAR');
    $sameTokenReplacement = $makeReplacement($modFixture, $sameTokenSlot, 'MODIFICAR_BASE', 'payload base');
    $sameTokenRepeat = ReservacionPublicaService::crearReemplazo([
        'reservacion_id' => $modFixture['id'],
        'fecha' => $sameTokenSlot['fecha'],
        'hora' => $sameTokenSlot['hora'],
        'personas' => 2,
        'notas' => 'payload base',
        'request_token' => $sameTokenReplacement['token'],
    ], ['contacto_tipo' => 'email', 'contacto' => $modFixture['contacto']]);
    $assert(($sameTokenRepeat['ok'] ?? false) === true && ($sameTokenRepeat['idempotente'] ?? false) === true, 'Carrera 4: mismo token y payload idempotente');
    $sameTokenConflict = ReservacionPublicaService::crearReemplazo([
        'reservacion_id' => $modFixture['id'],
        'fecha' => $modTargetA['fecha'],
        'hora' => $modTargetA['hora'],
        'personas' => 2,
        'notas' => 'payload distinto',
        'request_token' => $sameTokenReplacement['token'],
    ], ['contacto_tipo' => 'email', 'contacto' => $modFixture['contacto']]);
    $assert(($sameTokenConflict['ok'] ?? true) === false, 'Carrera 4: mismo token y payload distinto rechazado');

    $tokenA = $fixturePrefix . '_MOD_A_' . strtoupper(bin2hex(random_bytes(5)));
    $tokenB = $fixturePrefix . '_MOD_B_' . strtoupper(bin2hex(random_bytes(5)));
    $race = $runRace('dos_modificaciones', [
        [
            'kind' => 'modificar',
            'contact' => $modFixture['contacto'],
            'original_id' => $modFixture['id'],
            'replacement_token' => $tokenA,
            'target_date' => $modTargetA['fecha'],
            'target_hour' => $modTargetA['hora'],
            'note' => 'cambio A',
            'clock' => '2026-11-01 11:20:00',
        ],
        [
            'kind' => 'modificar',
            'contact' => $modFixture['contacto'],
            'original_id' => $modFixture['id'],
            'replacement_token' => $tokenB,
            'target_date' => $modTargetB['fecha'],
            'target_hour' => $modTargetB['hora'],
            'note' => 'cambio B',
            'clock' => '2026-11-01 11:20:00',
        ],
    ]);
    $modRowsResult = $query(
        'SELECT id, estado, hold_expires_at, reemplaza_reservacion_id, request_token
         FROM reservaciones WHERE reemplaza_reservacion_id = ' . $modFixture['id'] . ' ORDER BY id'
    );
    $modRows = [];
    while ($row = $modRowsResult->fetch_assoc()) {
        $modRows[] = $row;
    }
    $modRowsResult->free();
    $pendingMods = array_values(array_filter(
        $modRows,
        static fn(array $row): bool => $row['estado'] === 'pendiente_verificacion'
            && (string)$row['hold_expires_at'] > '2026-11-01 11:20:00'
    ));
    $activeOtpCount = $count(
        'verificaciones_contacto',
        'reservacion_id IN (' . implode(',', array_map(static fn(array $row): string => (string)$row['id'], $modRows)) . ")
         AND used_at IS NULL AND invalidated_at IS NULL
         AND expires_at > '2026-11-01 11:20:00'"
    );
    $modOriginalAfter = $rowById($modFixture['id']);
    $assert(count($pendingMods) === 1, 'Carrera 4: sólo un reemplazo pendiente vigente');
    $assert(($modOriginalAfter['estado'] ?? '') === 'confirmada', 'Carrera 4: original permanece confirmada');
    $assert($activeOtpCount === 1, 'Carrera 4: sólo un OTP ligado permanece activo');
    $resultSummary['dos_modificaciones'] = [
        'workers' => $workerResults($race),
        'reemplazos' => array_map(
            static fn(array $row): array => ['id' => (int)$row['id'], 'estado' => $row['estado']],
            $modRows
        ),
    ];

    // Carrera 5: dos cancelaciones simultáneas.
    $setClock('2026-11-01 11:20:00');
    $cancel2Slot = $findSlot(false, 2, [$futureSlot, $posFuture, $expireOriginalSlot, $expireReplacementSlot, $modOriginalSlot, $sameTokenSlot, $modTargetA, $modTargetB]);
    $cancel2Fixture = $makeConfirmed($cancel2Slot, 'CANCEL2');
    $mesaCountBeforeCancel = $count('reservacion_mesas', 'reservacion_id = ' . $cancel2Fixture['id']);
    $race = $runRace('dos_cancelaciones', [
        [
            'kind' => 'cancelar',
            'contact' => $cancel2Fixture['contacto'],
            'original_id' => $cancel2Fixture['id'],
            'clock' => '2026-11-01 11:20:00',
        ],
        [
            'kind' => 'cancelar',
            'contact' => $cancel2Fixture['contacto'],
            'original_id' => $cancel2Fixture['id'],
            'clock' => '2026-11-01 11:20:00',
        ],
    ]);
    $cancel2After = $rowById($cancel2Fixture['id']);
    $cancel2Results = $workerResults($race);
    $cancel2OkCount = count(array_filter(
        $cancel2Results,
        static fn(array $worker): bool => ($worker['resultado']['ok'] ?? false) === true
    ));
    $cancel2IdempotentCount = count(array_filter(
        $cancel2Results,
        static fn(array $worker): bool => ($worker['resultado']['idempotente'] ?? false) === true
    ));
    $mesaCountAfterCancel = $count('reservacion_mesas', 'reservacion_id = ' . $cancel2Fixture['id']);
    $assert(($cancel2After['estado'] ?? '') === 'cancelada', 'Carrera 5: estado final cancelada');
    $assert($cancel2OkCount === 2 && $cancel2IdempotentCount === 1, 'Carrera 5: una transición y una repetición idempotente');
    $assert($mesaCountBeforeCancel === $mesaCountAfterCancel, 'Carrera 5: asignación histórica conservada');
    $cancel2Availability = DisponibilidadReservacionService::evaluarHorario(
        $cancel2Slot['fecha'],
        $cancel2Slot['hora'],
        2,
        0,
        false,
        true
    );
    $assert(($cancel2Availability['disponible'] ?? false) === true, 'Carrera 5: cancelación libera disponibilidad');
    $resultSummary['dos_cancelaciones'] = [
        'workers' => $cancel2Results,
        'estado_final' => $cancel2After['estado'] ?? null,
        'mesas_antes' => $mesaCountBeforeCancel,
        'mesas_despues' => $mesaCountAfterCancel,
        'disponibilidad_liberada' => (bool)($cancel2Availability['disponible'] ?? false),
    ];
} catch (Throwable $e) {
    $exception = $e;
    $failed[] = 'Excepción de suite: ' . $e->getMessage();
} finally {
    foreach ($barriers as $barrier) {
        @unlink($barrier);
    }
    foreach (glob(dirname(__DIR__) . '/sess_*') ?: [] as $sessionFile) {
        if (!isset($sessionFilesBefore[$sessionFile])) {
            @unlink($sessionFile);
        }
    }
    $cleanup();
}

echo json_encode([
    'ok' => $exception === null && $failed === [],
    'database' => $database,
    'fixture_prefix' => $fixturePrefix,
    'clock_setup' => '2026-11-01 11:20:00',
    'timezone' => ReservacionConfig::timezone()->getName(),
    'processes_per_race' => 2,
    'passed' => $passed,
    'failed' => $failed,
    'scenarios' => $resultSummary,
    'fixtures_cleaned' => true,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($exception === null && $failed === [] ? 0 : 1);
