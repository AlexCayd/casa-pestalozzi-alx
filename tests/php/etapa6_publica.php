<?php

declare(strict_types=1);

/**
 * Suite integrada de Etapa 6. Todos los fixtures viven dentro de una sola
 * transacción y se revierten al terminar.
 */

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
$testSessionPath = sys_get_temp_dir();
if (is_dir($testSessionPath) && is_writable($testSessionPath)) {
    ini_set('session.save_path', $testSessionPath);
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexión MySQL para la suite de Etapa 6.\n");
    exit(2);
}

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
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
    return $result;
};
$escape = static fn(string $value): string => $db->real_escape_string($value);
$count = static function (string $table, string $where) use ($query): int {
    $result = $query("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}");
    $row = $result->fetch_assoc();
    $result->free();
    return (int)($row['total'] ?? 0);
};
$findFreeDate = static function () use ($query, $escape): string {
    for ($offset = 5; $offset <= 80; $offset++) {
        $date = (new DateTimeImmutable('2026-11-01', ReservacionConfig::timezone()))
            ->modify('+' . $offset . ' days')->format('Y-m-d');
        $sqlDate = $escape($date);
        $result = $query("SELECT
            (SELECT COUNT(*) FROM reservaciones WHERE fecha = '{$sqlDate}') AS reservations,
            (SELECT COUNT(*) FROM excepciones_operacion WHERE fecha = '{$sqlDate}') AS exceptions");
        $row = $result->fetch_assoc();
        $result->free();
        if ((int)$row['reservations'] === 0 && (int)$row['exceptions'] === 0) {
            return $date;
        }
    }
    throw new RuntimeException('No se encontró una fecha libre para Etapa 6.');
};
$payload = static function (string $name, string $contact, string $date, string $hour, string $token, string $note = ''): array {
    return [
        'nombre' => $name,
        'tipo_contacto' => 'email',
        'contacto' => $contact,
        'fecha' => $date,
        'hora' => $hour,
        'personas' => 2,
        'notas' => $note,
        'request_token' => $token,
    ];
};
$reservationByToken = static function (string $token) use ($query, $escape): ?array {
    $sqlToken = $escape($token);
    $result = $query("SELECT * FROM reservaciones WHERE request_token = '{$sqlToken}' LIMIT 1");
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};

$now = new DateTimeImmutable('2026-11-01 12:00:00', ReservacionConfig::timezone());
$date = '';
$hour = '14:00';
$expiredId = 0;
$batchExpiredId = 0;

try {
    $db->begin_transaction();

    // Fixture de horario controlado; el rollback restaura la configuración.
    $query("UPDATE horarios_operacion
            SET abierto = 1, hora_apertura = '12:00:00', hora_cierre = '22:00:00'");
    $date = $findFreeDate();
    $calendar = HorarioReservacionService::resolverFecha($date, $now);
    $hour = substr((string)($calendar['horarios_candidatos'][4] ?? '14:00'), 0, 5);
    $assert(($calendar['reservable'] ?? false) === true, '6.1: fecha de prueba reservable');

    // Consulta pública: sólo binario, slots y alternativas acotadas.
    $public = DisponibilidadReservacionService::consultar($date, 2, 0, '23:30');
    $assert(($public['disponible'] ?? true) === false, '6.1: hora solicitada no disponible');
    $assert(count((array)($public['alternativas'] ?? [])) <= 5, '6.1: máximo de cinco alternativas');
    $assert(!array_key_exists('codigo', $public) && !array_key_exists('personas', $public), '6.1: consulta pública sin claves internas');
    foreach ((array)($public['horarios'] ?? []) as $slot) {
        $assert(array_keys($slot) === ['hora', 'disponible'], '6.1: slot público sin capacidad ni IDs');
    }
    $largePublic = DisponibilidadReservacionService::consultar($date, 13);
    $assert(($largePublic['motivo'] ?? '') === 'requiere_contactar_restaurante', '6.1: grupo grande deriva a contacto');

    // Creación atómica, asignación y OTP hash.
    $contact = 'etapa6-' . substr(hash('sha256', $date), 0, 12) . '@example.test';
    $firstToken = 'ETAPA6_FIRST_' . bin2hex(random_bytes(12));
    $firstPayload = $payload('Etapa 6 Primero', $contact, $date, $hour, $firstToken, 'Prueba pública');
    $first = ReservacionPublicaService::crearRetencion($firstPayload);
    $firstRow = $reservationByToken($firstToken);
    $firstId = (int)($firstRow['id'] ?? 0);
    $firstMesaCount = $firstId > 0 ? $count('reservacion_mesas', 'reservacion_id = ' . $firstId) : 0;
    $firstOtpCount = $firstId > 0 ? $count('verificaciones_contacto', 'reservacion_id = ' . $firstId) : 0;
    $assert(($first['ok'] ?? false) === true && ($first['codigo'] ?? '') === ReservacionPublicaService::RETENCION_CREADA, '6.2: crea retención pública');
    $assert(($firstRow['estado'] ?? '') === 'pendiente_verificacion' && ($firstRow['origen'] ?? '') === 'landing', '6.2: estado inicial y origen landing');
    $assert($firstMesaCount > 0 && $firstOtpCount === 1, '6.2: mesas y OTP se materializan en la misma transacción');
    $assert(str_contains((string)($first['mensaje'] ?? ''), '15 minutos'), '6.2: mensaje de hold consistente con 15 minutos');
    $assert(isset($first['preview_code']) && preg_match('/^\d{6}$/', (string)$first['preview_code']) === 1, '6.2: OTP de prueba disponible sin guardar plaintext');
    $otpRow = $firstId > 0 ? $query("SELECT codigo_hash FROM verificaciones_contacto WHERE reservacion_id = {$firstId} LIMIT 1")->fetch_assoc() : [];
    $assert(($otpRow['codigo_hash'] ?? '') !== (string)($first['preview_code'] ?? ''), '6.2: OTP persistido como hash');

    $same = ReservacionPublicaService::crearRetencion($firstPayload);
    $sameCount = $count('reservaciones', "request_token = '" . $escape($firstToken) . "'");
    $assert(($same['ok'] ?? false) === true && ($same['idempotente'] ?? false) === true && $sameCount === 1, '6.2: reintento con mismo token es idempotente');

    $conflictPayload = $firstPayload;
    $conflictPayload['notas'] = 'Payload diferente';
    $conflict = ReservacionPublicaService::crearRetencion($conflictPayload);
    $assert(($conflict['codigo'] ?? '') === ReservacionPublicaService::REQUEST_TOKEN_CONFLICTO, '6.2: mismo token con payload diferente se rechaza');

    $duplicatePayload = $payload('Etapa 6 Duplicado', $contact, $date, $hour, 'ETAPA6_DUP_' . bin2hex(random_bytes(10)));
    $duplicate = ReservacionPublicaService::crearRetencion($duplicatePayload);
    $assert(($duplicate['codigo'] ?? '') === ReservacionPublicaService::RESERVACION_DUPLICADA, '6.2: duplicado normalizado por contacto/turno');

    // Confirmación OTP, consumo de un solo uso e idempotencia posterior.
    $confirmPayload = $payload('Etapa 6 Confirmada', 'etapa6-confirm-' . substr(hash('sha256', $date), 0, 10) . '@example.test', $date, '15:00', 'ETAPA6_CONFIRM_' . bin2hex(random_bytes(10)));
    $pending = ReservacionPublicaService::crearRetencion($confirmPayload);
    $confirm = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $confirmPayload['contacto'],
        'codigo' => (string)($pending['preview_code'] ?? ''),
        'request_token' => $confirmPayload['request_token'],
    ]);
    $confirmedRow = $reservationByToken($confirmPayload['request_token']);
    $stateChangedAt = (string)($confirmedRow['estado_changed_at'] ?? '');
    $repeat = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $confirmPayload['contacto'],
        'codigo' => (string)($pending['preview_code'] ?? ''),
        'request_token' => $confirmPayload['request_token'],
    ]);
    $confirmedAgain = $reservationByToken($confirmPayload['request_token']);
    $assert(($confirm['ok'] ?? false) === true && ($confirmedRow['estado'] ?? '') === 'confirmada', '6.3: OTP válido confirma la retención');
    $assert(($repeat['ok'] ?? false) === true && ($repeat['idempotente'] ?? false) === true, '6.3: confirmación repetida es idempotente');
    $assert($stateChangedAt !== '' && $stateChangedAt === (string)($confirmedAgain['estado_changed_at'] ?? ''), '6.3: repetición no cambia estado_changed_at');
    $used = $count('verificaciones_contacto', "reservacion_id = " . (int)($confirmedRow['id'] ?? 0) . " AND used_at IS NOT NULL");
    $assert($used === 1, '6.3: OTP se consume una sola vez');

    // Intentos erróneos y expiración técnica del código.
    $wrongPayload = $payload('Etapa 6 Intentos', 'etapa6-wrong-' . substr(hash('sha256', $date), 0, 10) . '@example.test', $date, '15:30', 'ETAPA6_WRONG_' . bin2hex(random_bytes(10)));
    $wrong = ReservacionPublicaService::crearRetencion($wrongPayload);
    $wrongResults = [];
    for ($attempt = 1; $attempt <= ReservacionConfig::OTP_MAX_ATTEMPTS; $attempt++) {
        $wrongResults[] = ReservacionPublicaService::confirmarRetencion([
            'tipo' => 'email',
            'contacto' => $wrongPayload['contacto'],
            'codigo' => '000000',
            'request_token' => $wrongPayload['request_token'],
        ]);
    }
    $wrongRow = $reservationByToken($wrongPayload['request_token']);
    $wrongOtp = $wrongRow ? $query('SELECT attempts, invalidated_at FROM verificaciones_contacto WHERE reservacion_id = ' . (int)$wrongRow['id'] . ' LIMIT 1')->fetch_assoc() : [];
    $assert(($wrongResults[0]['codigo'] ?? '') === Services\ContactoAccesoService::CODIGO_INVALIDO, '6.3: código erróneo registra intento');
    $assert(($wrongResults[4]['codigo'] ?? '') === Services\ContactoAccesoService::DEMASIADOS_INTENTOS && (int)($wrongOtp['attempts'] ?? 0) === 5 && $wrongOtp['invalidated_at'] !== null, '6.3: quinto intento invalida OTP');

    $expiredOtpPayload = $payload('Etapa 6 OTP Expirado', 'etapa6-otp-exp-' . substr(hash('sha256', $date), 0, 10) . '@example.test', $date, '16:00', 'ETAPA6_OTP_EXP_' . bin2hex(random_bytes(10)));
    $expiredOtp = ReservacionPublicaService::crearRetencion($expiredOtpPayload);
    $expiredOtpRow = $reservationByToken($expiredOtpPayload['request_token']);
    $query("UPDATE verificaciones_contacto SET expires_at = '2026-11-01 11:59:59' WHERE reservacion_id = " . (int)($expiredOtpRow['id'] ?? 0));
    $expiredOtpResult = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $expiredOtpPayload['contacto'],
        'codigo' => (string)($expiredOtp['preview_code'] ?? ''),
        'request_token' => $expiredOtpPayload['request_token'],
    ]);
    $assert(($expiredOtpResult['codigo'] ?? '') === Services\ContactoAccesoService::CODIGO_EXPIRADO, '6.3: OTP expirado no confirma');

    // El límite cuenta reservas confirmadas y holds pendientes, con exclusión al confirmar.
    $limitContact = 'etapa6-limit-' . substr(hash('sha256', $date), 0, 10) . '@example.test';
    $limitSeed = bin2hex(random_bytes(5));
    for ($offset = 10; $offset <= 13; $offset++) {
        $limitDate = $now->modify('+' . $offset . ' days')->format('Y-m-d');
        $sqlContact = $escape($limitContact);
        $sqlDate = $escape($limitDate);
        $query("INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado, request_token)
            VALUES ('Etapa6 límite', 'email', '{$sqlContact}', '{$sqlDate}', '14:00:00', 2, 'landing', 'confirmada', 'ETAPA6_LIMIT_CONF_{$limitSeed}_{$offset}')");
    }
    $fifthPayload = $payload('Etapa 6 Quinta', $limitContact, $date, '17:00', 'ETAPA6_LIMIT_FIFTH_' . bin2hex(random_bytes(8)));
    $fifth = ReservacionPublicaService::crearRetencion($fifthPayload);
    $fifthConfirmed = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $limitContact,
        'codigo' => (string)($fifth['preview_code'] ?? ''),
        'request_token' => $fifthPayload['request_token'],
    ]);
    $sixthPayload = $payload('Etapa 6 Sexta', $limitContact, $now->modify('+1 day')->format('Y-m-d'), '17:00', 'ETAPA6_LIMIT_SIXTH_' . bin2hex(random_bytes(8)));
    $sixth = ReservacionPublicaService::crearRetencion($sixthPayload);
    $assert(($fifth['ok'] ?? false) === true && ($fifthConfirmed['ok'] ?? false) === true, '6.3: quinta reservación puede confirmar excluyendo su propio hold');
    $assert(($sixth['codigo'] ?? '') === ReservacionPublicaService::LIMITE_RESERVACIONES_ALCANZADO, '6.3: sexta reservación respeta límite');

    $pendingLimitContact = 'etapa6-pending-limit-' . substr(hash('sha256', $date), 0, 8) . '@example.test';
    $pendingLimitSeed = bin2hex(random_bytes(5));
    for ($offset = 30; $offset <= 34; $offset++) {
        $pendingDate = $now->modify('+' . $offset . ' days')->format('Y-m-d');
        $query("INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado, hold_expires_at, request_token)
            VALUES ('Etapa6 holds', 'email', '" . $escape($pendingLimitContact) . "', '{$pendingDate}', '14:00:00', 2, 'landing', 'pendiente_verificacion', '2026-11-01 12:15:00', 'ETAPA6_LIMIT_PENDING_{$pendingLimitSeed}_{$offset}')");
    }
    $pendingLimit = ReservacionPublicaService::crearRetencion(
        $payload('Etapa 6 Hold límite', $pendingLimitContact, $date, '18:00', 'ETAPA6_LIMIT_PENDING_NEW_' . bin2hex(random_bytes(8)))
    );
    $assert(($pendingLimit['codigo'] ?? '') === ReservacionPublicaService::LIMITE_RESERVACIONES_ALCANZADO, '6.3: holds pendientes vigentes cuentan para el límite');

    // Expiración de hold: transiciona, invalida OTP y conserva la asignación.
    $expiryPayload = $payload('Etapa 6 Hold Expirado', 'etapa6-expiry-' . substr(hash('sha256', $date), 0, 10) . '@example.test', $date, '19:00', 'ETAPA6_EXPIRY_' . bin2hex(random_bytes(10)));
    $expiry = ReservacionPublicaService::crearRetencion($expiryPayload);
    $expiryRow = $reservationByToken($expiryPayload['request_token']);
    $expiredId = (int)($expiryRow['id'] ?? 0);
    $assignedBeforeExpiry = $count('reservacion_mesas', 'reservacion_id = ' . $expiredId);
    $query("UPDATE reservaciones SET hold_expires_at = '2026-11-01 11:59:59' WHERE id = {$expiredId}");
    $expiredResult = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $expiryPayload['contacto'],
        'codigo' => (string)($expiry['preview_code'] ?? ''),
        'request_token' => $expiryPayload['request_token'],
    ]);
    $expiredRow = $reservationByToken($expiryPayload['request_token']);
    $invalidated = $count('verificaciones_contacto', 'reservacion_id = ' . $expiredId . ' AND invalidated_at IS NOT NULL');
    $publicAfterExpiry = DisponibilidadReservacionService::consultar($date, 2, 0, '19:00');
    $assert(($expiredResult['codigo'] ?? '') === ReservacionPublicaService::RETENCION_EXPIRADA && ($expiredRow['estado'] ?? '') === 'expirada', '6.4: hold vencido transiciona a expirada');
    $assert($assignedBeforeExpiry > 0 && $assignedBeforeExpiry === $count('reservacion_mesas', 'reservacion_id = ' . $expiredId) && $invalidated === 1, '6.4: expiración conserva mesas e invalida OTP');
    $assert(($publicAfterExpiry['disponible'] ?? false) === true, '6.4: hold expirado deja de influir en disponibilidad');

    $batchPayload = $payload('Etapa 6 Batch Expirado', 'etapa6-batch-' . substr(hash('sha256', $date), 0, 10) . '@example.test', $date, '20:00', 'ETAPA6_BATCH_' . bin2hex(random_bytes(10)));
    $batch = ReservacionPublicaService::crearRetencion($batchPayload);
    $batchRow = $reservationByToken($batchPayload['request_token']);
    $batchExpiredId = (int)($batchRow['id'] ?? 0);
    $query("UPDATE reservaciones SET hold_expires_at = '2026-11-01 11:59:58' WHERE id = {$batchExpiredId}");
    $batchResult = ReservacionPublicaService::expirarRetenciones();
    $batchRowAfter = $reservationByToken($batchPayload['request_token']);
    $assert(($batchResult['ok'] ?? false) === true && ($batchRowAfter['estado'] ?? '') === 'expirada', '6.4: job de expiración materializa holds vencidos');

    $db->rollback();
    echo json_encode([
        'ok' => $failed === [],
        'passed' => $passed,
        'failed' => $failed,
        'fixtures_rolled_back' => true,
        'clock' => $now->format('Y-m-d H:i:s'),
        'timezone' => ReservacionConfig::timezone()->getName(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $e) {
    $db->rollback();
    fwrite(STDERR, "ETAPA6_FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
