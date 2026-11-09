<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || !getenv('CP_NOTIFICATION_TEST_DATABASE')) {
    fwrite(STDERR, "Usar run-notifications-isolated.php.\n");
    exit(2);
}

require_once __DIR__ . '/notifications-test-bootstrap.php';
require dirname(__DIR__, 2) . '/includes/app.php';
require_once __DIR__ . '/contacto-fixture-helper.php';

use Model\ActiveRecord;
use Model\Reservacion;
use Services\Contact\ContactoAccesoService;
use Services\Contact\ContactoProtegidoService;
use Services\Contact\ContactoService;
use Model\VerificacionContacto;
use Services\Reservations\ReservacionAdministrativaService;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionMantenimientoService;
use Services\Reservations\ReservacionPublicaService;
use Services\Pos\PosReservacionSerializer;
use Services\Integrations\N8nClient;

function protectedContactsAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

function insertProtectedContactFixture(
    mysqli $db,
    array &$ids,
    string $name,
    string $type,
    ?string $contact,
    string $date,
    string $time = '13:00:00',
    string $status = 'confirmada',
    ?string $expiresAt = null
): int {
    $protected = contactoProtegidoFixture($type, $contact);
    $cipher = $protected['contacto'];
    $hash = $protected['contacto_hash'];
    $origin = 'admin';
    $hold = $expiresAt;
    $stmt = $db->prepare(
        'INSERT INTO reservaciones
          (nombre,contacto_tipo,contacto,contacto_hash,fecha,hora,comensales,nota,origen,estado,hold_expires_at)
         VALUES (?,?,?,?,?,?,2,\'fixture stage4\',?,?,?)'
    );
    $stmt->bind_param('sssssssss', $name, $type, $cipher, $hash, $date, $time, $origin, $status, $hold);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    $ids[] = $id;
    return $id;
}

function protectedSearchIds(array $rows): array
{
    return array_map(static fn(Reservacion $row): int => (int)$row->id, $rows);
}

$db = ActiveRecord::getDB();
$isolatedDatabase = (string)getenv('CP_NOTIFICATION_TEST_DATABASE');
protectedContactsAssert(preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $isolatedDatabase) === 1
    && (string)$db->query('SELECT DATABASE()')->fetch_row()[0] === $isolatedDatabase, 'La suite rechazó una base no aislada.');
$ids = [];
$otpIds = [];
$oldEnv = $_ENV['APP_ENV'] ?? null;
$oldNow = $_ENV['RESERVATION_TEST_NOW'] ?? null;
$oldEnc = $_ENV['CONTACT_ENCRYPTION_KEY'] ?? null;
$oldLookup = $_ENV['CONTACT_LOOKUP_KEY'] ?? null;
$oldErrorLog = ini_get('error_log');
$columnMetadata = $db->query("SELECT CHARACTER_SET_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reservaciones' AND COLUMN_NAME = 'contacto'")->fetch_assoc();
$errorLog = tempnam(sys_get_temp_dir(), 'cp-protected-contact-log-');

try {
    $_ENV['APP_ENV'] = 'test';
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 17:59:00';
    putenv('RESERVATION_TEST_NOW=2037-01-14 17:59:00');
    $contactEmail = "O'Reilly.case@example.test";
    $contactPhone = '+52 (55) 1000-2020';
    $emailId = insertProtectedContactFixture($db, $ids, 'Café Águila', 'email', $contactEmail, '2037-01-15');
    $phoneId = insertProtectedContactFixture($db, $ids, 'Teléfono protegido', 'telefono', $contactPhone, '2037-01-16');
    $literalId = insertProtectedContactFixture($db, $ids, 'Literal fixture', 'email', 'needle%tag_marker@example.test', '2037-01-17');
    $wildcardControlId = insertProtectedContactFixture($db, $ids, 'Wildcard control', 'email', 'needleXtagXmarker@example.test', '2037-01-18');

    foreach ([[$emailId, 'email', "o'reilly.case@example.test"], [$phoneId, 'telefono', '+525510002020']] as [$id, $type, $normalized]) {
        $stmt = $db->prepare('SELECT contacto,contacto_hash FROM reservaciones WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        protectedContactsAssert(str_starts_with((string)$stored['contacto'], 'cp1:'), 'La fila almacenó texto plano.');
        protectedContactsAssert($stored['contacto_hash'] === ContactoProtegidoService::identificador($type, $normalized), 'HMAC almacenado incorrecto.');
        protectedContactsAssert(ContactoProtegidoService::descifrar($type, (string)$stored['contacto']) === $normalized, 'Roundtrip de fila no coincide.');
    }

    $liveEmail = Reservacion::buscarActivasPorContacto('email', ' O\'REILLY.CASE@EXAMPLE.TEST ', '2037-01-14', '17:59:00', 5);
    protectedContactsAssert(count($liveEmail) === 1 && (int)$liveEmail[0]['id'] === $emailId, 'La búsqueda exacta por email no normalizó.');
    protectedContactsAssert(!array_key_exists('contacto', $liveEmail[0]) && !array_key_exists('contacto_hash', $liveEmail[0]), 'El lookup exacto alteró el contrato público.');
    $livePhone = Reservacion::buscarActivasPorContacto('telefono', '+52 (55) 1000-2020', '2037-01-14', '17:59:00', 5);
    protectedContactsAssert(count($livePhone) === 1 && (int)$livePhone[0]['id'] === $phoneId, 'La búsqueda exacta por teléfono no normalizó.');
    protectedContactsAssert(Reservacion::contarActivasPorContacto('email', $contactEmail, '2037-01-14', '17:59:00') === 1, 'El conteo exacto por HMAC no coincide.');

    $generic = new Reservacion();
    $generic->nombre = 'ActiveRecord genérico';
    $generic->contacto_tipo = 'email';
    $generic->contacto = 'generic.before@example.test';
    $generic->fecha = '2037-01-19';
    $generic->hora = '13:00:00';
    $generic->comensales = 2;
    $generic->origen = 'admin';
    $generic->estado = 'confirmada';
    $generic->estado_changed_at = '2037-01-14 17:59:00';
    $genericCreated = $generic->crear();
    protectedContactsAssert(($genericCreated['resultado'] ?? false) === true, 'ActiveRecord no pudo crear contacto protegido.');
    $genericId = (int)$genericCreated['id'];
    $ids[] = $genericId;
    protectedContactsAssert($generic->contacto === 'generic.before@example.test', 'El cifrado mutó el objeto en memoria.');
    $genericRaw = $db->query('SELECT contacto,contacto_hash FROM reservaciones WHERE id = ' . $genericId)->fetch_assoc();
    protectedContactsAssert(str_starts_with((string)$genericRaw['contacto'], 'cp1:')
        && $genericRaw['contacto_hash'] === ContactoProtegidoService::identificador('email', 'generic.before@example.test'), 'ActiveRecord no cifró/HMAC en alta.');
    $generic->id = $genericId;
    $generic->contacto = 'generic.after@example.test';
    protectedContactsAssert($generic->actualizar() === true && $generic->contacto === 'generic.after@example.test', 'ActiveRecord no actualizó sin mutar el contacto.');
    $genericRawAfter = $db->query('SELECT contacto,contacto_hash FROM reservaciones WHERE id = ' . $genericId)->fetch_assoc();
    protectedContactsAssert(str_starts_with((string)$genericRawAfter['contacto'], 'cp1:')
        && $genericRawAfter['contacto_hash'] === ContactoProtegidoService::identificador('email', 'generic.after@example.test')
        && Reservacion::buscarFilaPorId($genericId)['contacto'] === 'generic.after@example.test', 'ActiveRecord no cifró/HMAC/descifró en edición.');

    $publicDuplicate = ReservacionPublicaService::crearRetencion([
        'nombre' => 'Caso duplicado', 'tipo_contacto' => 'email', 'contacto' => $contactEmail,
        'fecha' => '2037-01-15', 'hora' => '13:00', 'personas' => 2, 'request_token' => bin2hex(random_bytes(16)),
    ]);
    protectedContactsAssert(($publicDuplicate['codigo'] ?? '') === ReservacionPublicaService::RESERVACION_DUPLICADA, 'La detección de duplicado por contacto no funcionó.');
    $publicJson = (string)json_encode($publicDuplicate);
    protectedContactsAssert(!str_contains($publicJson, 'cp1:') && !str_contains($publicJson, ContactoProtegidoService::identificador('email', $contactEmail)), 'La respuesta pública filtró ciphertext o HMAC.');

    $limitContact = 'five.active@example.test';
    for ($i = 0; $i < ReservacionConfig::MAX_RESERVACIONES_ACTIVAS_POR_CONTACTO; $i++) {
        insertProtectedContactFixture($db, $ids, 'Fixture límite ' . $i, 'email', $limitContact, sprintf('2037-01-%02d', 18 + $i));
    }
    protectedContactsAssert(Reservacion::contarActivasPorContacto('email', $limitContact, '2037-01-14', '17:59:00') === 5, 'El conteo no incluyó cinco activas.');
    $limitAttempt = ReservacionPublicaService::crearRetencion([
        'nombre' => 'Caso límite', 'tipo_contacto' => 'email', 'contacto' => $limitContact,
        'fecha' => '2037-01-25', 'hora' => '13:00', 'personas' => 2, 'request_token' => bin2hex(random_bytes(16)),
    ]);
    protectedContactsAssert(($limitAttempt['codigo'] ?? '') === ReservacionPublicaService::LIMITE_RESERVACIONES_ALCANZADO, 'El límite de cinco activas no bloqueó.');
    protectedContactsAssert(Reservacion::contarActivasPorContacto('email', $limitContact, '2037-01-14', '17:59:00') === 5, 'El intento bloqueado alteró las cinco activas.');

    $adminContact = 'admin.initial@example.test';
    $created = ReservacionAdministrativaService::crear([
        'nombre' => 'Alta administrativa protegida', 'contacto_tipo' => 'email', 'contacto' => $adminContact,
        'fecha' => '2037-01-20', 'hora' => '14:00', 'comensales' => 13,
        'confirmar_sobrecapacidad' => '1',
        'confirmaciones' => ['SIN_ASIGNACION', 'ASIGNACION_MANUAL_REQUERIDA', 'CAPACIDAD_INSUFICIENTE'],
    ]);
    protectedContactsAssert(($created['ok'] ?? false) === true && (int)($created['id'] ?? 0) > 0, 'No se creó la alta administrativa de más de 12 personas.');
    $adminId = (int)$created['id'];
    $ids[] = $adminId;
    $adminRow = Reservacion::buscarFilaPorId($adminId);
    protectedContactsAssert((int)$adminRow['comensales'] === 13 && $adminRow['contacto'] === $adminContact, 'La alta administrativa no conservó comensales/contacto.');
    $rawAdmin = $db->query('SELECT contacto,contacto_hash FROM reservaciones WHERE id = ' . $adminId)->fetch_assoc();
    protectedContactsAssert(str_starts_with((string)$rawAdmin['contacto'], 'cp1:') && $rawAdmin['contacto_hash'] === ContactoProtegidoService::identificador('email', $adminContact), 'Alta administrativa no persistió ciphertext/HMAC.');

    $updatedContact = 'admin.updated@example.test';
    $updated = ReservacionAdministrativaService::actualizar($adminId, [
        'nombre' => 'Alta administrativa protegida', 'contacto_tipo' => 'email', 'contacto' => $updatedContact,
        'fecha' => '2037-01-21', 'hora' => '14:30', 'comensales' => 13,
        'confirmar_sobrecapacidad' => '1',
        'confirmaciones' => ['SIN_ASIGNACION', 'ASIGNACION_MANUAL_REQUERIDA', 'CAPACIDAD_INSUFICIENTE'],
    ]);
    protectedContactsAssert(($updated['ok'] ?? false) === true, 'No se actualizó la fila administrativa protegida.');
    $updatedRow = Reservacion::buscarFilaPorId($adminId);
    protectedContactsAssert($updatedRow['contacto'] === $updatedContact, 'La lectura después de editar no descifró el contacto.');
    $cancelled = ReservacionAdministrativaService::cancelar($adminId, null, 'Cancelación de fixture');
    protectedContactsAssert(($cancelled['ok'] ?? false) === true, 'No se canceló la fila administrativa.');
    $afterCancel = $db->query('SELECT estado,contacto,contacto_hash FROM reservaciones WHERE id = ' . $adminId)->fetch_assoc();
    protectedContactsAssert($afterCancel['estado'] === 'cancelada' && str_starts_with((string)$afterCancel['contacto'], 'cp1:')
        && $afterCancel['contacto_hash'] === ContactoProtegidoService::identificador('email', $updatedContact), 'Cancelación alteró o expuso contacto protegido.');

    $createdNoContact = ReservacionAdministrativaService::crear([
        'nombre' => 'Alta administrativa sin contacto', 'contacto_tipo' => 'ninguno', 'contacto' => '',
        'fecha' => '2037-01-22', 'hora' => '15:00', 'comensales' => 2,
        'confirmaciones' => ['SIN_CONTACTO', 'SIN_ASIGNACION', 'ASIGNACION_MANUAL_REQUERIDA'],
    ]);
    protectedContactsAssert(($createdNoContact['ok'] ?? false) === true, 'No se creó alta administrativa sin contacto.');
    $noContactId = (int)$createdNoContact['id'];
    $ids[] = $noContactId;
    $noneRaw = $db->query('SELECT contacto,contacto_hash FROM reservaciones WHERE id = ' . $noContactId)->fetch_assoc();
    protectedContactsAssert($noneRaw['contacto'] === null && $noneRaw['contacto_hash'] === null, 'El tipo ninguno no quedó NULL.');

    $allRows = Reservacion::buscarAdmin(['q' => "O'Reilly"]);
    $allIds = protectedSearchIds($allRows);
    protectedContactsAssert(in_array($emailId, $allIds, true), 'Filtro admin no buscó el contacto con apóstrofo.');
    $accentRows = Reservacion::buscarAdmin(['q' => 'CAFE']);
    protectedContactsAssert(in_array($emailId, protectedSearchIds($accentRows), true), 'Filtro admin perdió semántica case/acentos en nombre.');
    $percentRows = protectedSearchIds(Reservacion::buscarAdmin(['q' => 'needle%tag']));
    protectedContactsAssert(in_array($literalId, $percentRows, true) && !in_array($wildcardControlId, $percentRows, true), 'Filtro admin no trató % literalmente en contacto.');
    $underscoreRows = protectedSearchIds(Reservacion::buscarAdmin(['q' => '_marker']));
    protectedContactsAssert(in_array($literalId, $underscoreRows, true) && !in_array($wildcardControlId, $underscoreRows, true), 'Filtro admin no trató _ literalmente en contacto.');
    protectedContactsAssert(Reservacion::buscarAdmin(['q' => "%\' OR 1=1 --"]) === [], 'Filtro admin aceptó un ataque de comillas.');
    $metrics = Reservacion::metricasAdmin(['q' => "O'Reilly"]);
    protectedContactsAssert(($metrics['total'] ?? -1) === count($allRows) && ($metrics['confirmadas'] ?? -1) === count($allRows), 'Métricas admin no comparten el filtro parcial.');
    foreach ($allRows as $row) {
        protectedContactsAssert(!property_exists($row, 'contacto_hash') && !array_key_exists('contacto_hash', get_object_vars($row)), 'El modelo expuso contacto_hash.');
    }

    $db->query('ALTER TABLE reservaciones MODIFY contacto VARCHAR(512) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL');
    protectedContactsAssert(in_array($emailId, protectedSearchIds(Reservacion::buscarAdmin(['q' => "o'reilly.case"])), true), 'Búsqueda parcial falló con columna latin1.');
    $db->query('ALTER TABLE reservaciones MODIFY contacto VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
    protectedContactsAssert(in_array($emailId, protectedSearchIds(Reservacion::buscarAdmin(['q' => "o'reilly.case"])), true), 'Búsqueda parcial falló con utf8mb4_bin.');
    protectedContactsAssert(!in_array($emailId, protectedSearchIds(Reservacion::buscarAdmin(['q' => "O'REILLY.CASE"])), true), 'utf8mb4_bin perdió semántica case-sensitive.');
    $db->query('ALTER TABLE reservaciones MODIFY contacto VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');

    $maintenanceId = insertProtectedContactFixture($db, $ids, 'Mantenimiento de fixture', 'email', 'wild%_prefix@example.test', '2037-01-14', '13:00:00', 'pendiente_verificacion', '2037-01-14 12:00:00');
    $maintenanceControlId = insertProtectedContactFixture($db, $ids, 'Otro contacto', 'email', 'other.prefix@example.test', '2037-01-14', '14:00:00', 'pendiente_verificacion', '2037-01-14 12:00:00');
    $maintenanceFilters = ['fecha_desde' => '2037-01-14', 'fecha_hasta' => '2037-01-14', 'estados' => ['pendiente_verificacion'], 'prefijo' => 'wild'];
    $db->query('ALTER TABLE reservaciones MODIFY contacto VARCHAR(512) CHARACTER SET latin1 COLLATE latin1_swedish_ci NULL');
    $maintenance = ReservacionMantenimientoService::vistaPreviaLimpieza($maintenanceFilters);
    protectedContactsAssert(($maintenance['ok'] ?? false) === true && in_array($maintenanceId, $maintenance['ids'] ?? [], true)
        && !in_array($maintenanceControlId, $maintenance['ids'] ?? [], true), 'Prefijo de mantenimiento no filtró candidatos por contacto.');
    $wildcardMaintenance = ReservacionMantenimientoService::vistaPreviaLimpieza(array_replace($maintenanceFilters, ['prefijo' => 'wild%']));
    protectedContactsAssert(($wildcardMaintenance['ok'] ?? false) === true && in_array($maintenanceId, $wildcardMaintenance['ids'] ?? [], true), 'Mantenimiento cambió la semántica de prefijo con wildcard.');
    $db->query('ALTER TABLE reservaciones MODIFY contacto VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL');

    $otpContact = 'otp.attempts.fixture@example.test';
    $otpHash = password_hash('123456', PASSWORD_DEFAULT);
    protectedContactsAssert(is_string($otpHash), 'No se pudo crear el hash de prueba OTP.');
    $otpIds[] = VerificacionContacto::crearHash('email', $otpContact, $otpHash, '2037-01-15 18:00:00', null, bin2hex(random_bytes(16)), '2037-01-15 18:00:00', '2037-01-14 17:00:00');
    $otpStored = $db->query('SELECT contacto,contacto_hash,codigo_hash FROM verificaciones_contacto WHERE id = ' . (int)end($otpIds))->fetch_assoc();
    protectedContactsAssert(str_starts_with((string)$otpStored['contacto'], 'cp1:')
        && $otpStored['contacto_hash'] === ContactoProtegidoService::identificador('email', $otpContact)
        && $otpStored['codigo_hash'] !== '123456', 'No fue posible leer OTP cifrado.');
    protectedContactsAssert(password_verify('123456', (string)$otpStored['codigo_hash']), 'El OTP no persiste mediante password_hash.');
    for ($attempt = 1; $attempt <= ReservacionConfig::OTP_MAX_ATTEMPTS; $attempt++) {
        $otpResult = ContactoAccesoService::verificarCodigo('email', $otpContact, '000000');
        $expected = $attempt === ReservacionConfig::OTP_MAX_ATTEMPTS
            ? ContactoAccesoService::OTP_INTENTOS_AGOTADOS
            : ContactoAccesoService::OTP_INCORRECTO;
        protectedContactsAssert(($otpResult['codigo'] ?? '') === $expected, 'El contador OTP no aplicó el máximo de intentos.');
    }
    $attemptRow = $db->query('SELECT attempts,invalidated_at FROM verificaciones_contacto WHERE id = ' . (int)end($otpIds))->fetch_assoc();
    protectedContactsAssert((int)$attemptRow['attempts'] === ReservacionConfig::OTP_MAX_ATTEMPTS && $attemptRow['invalidated_at'] !== null, 'OTP no se invalidó al alcanzar intentos máximos.');

    $expiredContact = 'otp.expired.fixture@example.test';
    $otpIds[] = VerificacionContacto::crearHash('email', $expiredContact, password_hash('654321', PASSWORD_DEFAULT), '2037-01-14 17:58:00', null, bin2hex(random_bytes(16)), '2037-01-15 18:00:00', '2037-01-14 17:00:00');
    $expiredResult = ContactoAccesoService::verificarCodigo('email', $expiredContact, '654321');
    protectedContactsAssert(($expiredResult['codigo'] ?? '') === ContactoAccesoService::OTP_EXPIRADO, 'OTP vencido no se rechazó.');

    $brokenCipher = (string)$rawAdmin['contacto'];
    $raw = base64_decode(substr($brokenCipher, 4), true);
    $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
    $bad = 'cp1:' . base64_encode($raw);
    $stmt = $db->prepare('UPDATE reservaciones SET contacto = ? WHERE id = ?');
    $stmt->bind_param('si', $bad, $adminId);
    $stmt->execute();
    $stmt->close();
    $failedClosed = false;
    try { Reservacion::buscarFilaPorId($adminId); } catch (RuntimeException) { $failedClosed = true; }
    protectedContactsAssert($failedClosed, 'Una fila corrupta no falló cerrada.');
    $restore = $db->prepare('UPDATE reservaciones SET contacto = ? WHERE id = ?');
    $restore->bind_param('si', $afterCancel['contacto'], $adminId);
    $restore->execute();
    $restore->close();

    $secretKey = (string)$_ENV['CONTACT_ENCRYPTION_KEY'];
    $lookupKey = (string)$_ENV['CONTACT_LOOKUP_KEY'];
    ini_set('error_log', $errorLog);
    $transportCalls = 0;
    $mockClient = new N8nClient('http://n8n.invalid', 'fixture-secret', static function () use (&$transportCalls): array {
        $transportCalls++;
        return ['status' => 200, 'body' => '{"ok":true,"accepted":true,"channel":"email"}'];
    });
    unset($_ENV['CONTACT_ENCRYPTION_KEY'], $_ENV['CONTACT_LOOKUP_KEY']);
    putenv('CONTACT_ENCRYPTION_KEY');
    putenv('CONTACT_LOOKUP_KEY');
    $genericNone = new Reservacion();
    $genericNone->nombre = 'ActiveRecord ninguno';
    $genericNone->contacto_tipo = 'ninguno';
    $genericNone->contacto = null;
    $genericNone->fecha = '2037-01-23';
    $genericNone->hora = '13:00:00';
    $genericNone->comensales = 2;
    $genericNone->origen = 'admin';
    $genericNone->estado = 'confirmada';
    $genericNone->estado_changed_at = '2037-01-14 17:59:00';
    $noneCreated = $genericNone->crear();
    protectedContactsAssert(($noneCreated['resultado'] ?? false) === true, 'ActiveRecord con tipo ninguno exigió claves.');
    $genericNoneId = (int)$noneCreated['id'];
    $ids[] = $genericNoneId;
    $genericNone->id = $genericNoneId;
    $genericNone->fecha = '2037-01-24';
    protectedContactsAssert($genericNone->actualizar() === true, 'ActiveRecord no actualizó tipo ninguno sin claves.');
    $noneStored = $db->query('SELECT contacto,contacto_hash FROM reservaciones WHERE id = ' . $genericNoneId)->fetch_assoc();
    protectedContactsAssert($noneStored['contacto'] === null && $noneStored['contacto_hash'] === null, 'ActiveRecord no conservó NULL sin claves.');
    $beforeReservations = (int)$db->query('SELECT COUNT(*) FROM reservaciones')->fetch_row()[0];
    $beforeOtp = (int)$db->query('SELECT COUNT(*) FROM verificaciones_contacto')->fetch_row()[0];
    $badConfig = ReservacionPublicaService::crearRetencion([
        'nombre' => 'Falla segura', 'tipo_contacto' => 'email', 'contacto' => 'fail.closed@example.test',
        'fecha' => '2037-01-25', 'hora' => '14:00', 'personas' => 2, 'request_token' => 'failure-token-stage4-001',
    ]);
    protectedContactsAssert(($badConfig['codigo'] ?? '') === ReservacionPublicaService::ERROR_INTERNO, 'Configuración ausente no devolvió ERROR_INTERNO.');
    $badTransport = ContactoAccesoService::solicitarCodigo('email', 'fail.closed@example.test', $mockClient);
    protectedContactsAssert(($badTransport['codigo'] ?? '') === ContactoAccesoService::ERROR_INTERNO, 'Configuración ausente no falló cerrada en OTP.');
    $_ENV['CONTACT_ENCRYPTION_KEY'] = 'not-base64';
    $_ENV['CONTACT_LOOKUP_KEY'] = $lookupKey;
    putenv('CONTACT_ENCRYPTION_KEY=not-base64');
    putenv('CONTACT_LOOKUP_KEY=' . $lookupKey);
    $malformedApi = ReservacionPublicaService::crearRetencion([
        'nombre' => 'Falla segura malformada', 'tipo_contacto' => 'email', 'contacto' => 'malformed.key@example.test',
        'fecha' => '2037-01-25', 'hora' => '14:00', 'personas' => 2, 'request_token' => 'failure-token-stage4-002',
    ]);
    protectedContactsAssert(($malformedApi['codigo'] ?? '') === ReservacionPublicaService::ERROR_INTERNO, 'Clave malformada no devolvió ERROR_INTERNO.');
    $malformedTransport = ContactoAccesoService::solicitarCodigo('email', 'malformed.key@example.test', $mockClient);
    protectedContactsAssert(($malformedTransport['codigo'] ?? '') === ContactoAccesoService::ERROR_INTERNO, 'Clave malformada no falló cerrada en OTP.');
    protectedContactsAssert($transportCalls === 0, 'Se intentó enviar un código con claves inválidas.');
    protectedContactsAssert((int)$db->query('SELECT COUNT(*) FROM reservaciones')->fetch_row()[0] === $beforeReservations
        && (int)$db->query('SELECT COUNT(*) FROM verificaciones_contacto')->fetch_row()[0] === $beforeOtp, 'Configuración inválida escribió reservación u OTP.');
    $_ENV['CONTACT_ENCRYPTION_KEY'] = $secretKey;
    $_ENV['CONTACT_LOOKUP_KEY'] = $lookupKey;
    putenv('CONTACT_ENCRYPTION_KEY=' . $secretKey);
    putenv('CONTACT_LOOKUP_KEY=' . $lookupKey);
    $logged = (string)file_get_contents($errorLog);
    foreach (['fail.closed@example.test', 'malformed.key@example.test', $secretKey, $lookupKey, 'not-base64', 'failure-token-stage4-001', 'failure-token-stage4-002', 'cp1:'] as $sensitive) {
        protectedContactsAssert(!str_contains($logged, $sensitive), 'El log de rechazo filtró datos sensibles.');
    }

    $posPayload = PosReservacionSerializer::sanitizarParaWaiter([
        'reservaciones' => [['id' => 4, 'contacto_hash' => str_repeat('a', 64), 'contacto' => '123', 'datos' => ['contacto_hash' => str_repeat('b', 64)]]],
    ]);
    $jsonPos = (string)json_encode($posPayload);
    protectedContactsAssert(!str_contains($jsonPos, 'contacto_hash') && !str_contains($jsonPos, 'cp1:'), 'El payload POS filtró hash/ciphertext anidado.');

    echo "Contactos protegidos: writes, lookups, admin, parciales/collations, privacidad, rechazo seguro PASS.\n";
} finally {
    if ($ids !== []) {
        $in = implode(',', array_map('intval', $ids));
        $db->query("DELETE FROM horario_impacto_reservaciones WHERE reservacion_id IN ({$in})");
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$in})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$in})");
        $db->query("DELETE FROM reservaciones WHERE id IN ({$in})");
    }
    if ($otpIds !== []) $db->query('DELETE FROM verificaciones_contacto WHERE id IN (' . implode(',', array_map('intval', $otpIds)) . ')');
    if (is_array($columnMetadata) && preg_match('/^[A-Za-z0-9_]+$/D', (string)$columnMetadata['CHARACTER_SET_NAME'])
        && preg_match('/^[A-Za-z0-9_]+$/D', (string)$columnMetadata['COLLATION_NAME'])) {
        $length = max(1, min(512, (int)$columnMetadata['CHARACTER_MAXIMUM_LENGTH']));
        $db->query('ALTER TABLE reservaciones MODIFY contacto VARCHAR(' . $length . ') CHARACTER SET '
            . $columnMetadata['CHARACTER_SET_NAME'] . ' COLLATE ' . $columnMetadata['COLLATION_NAME'] . ' NULL');
    }
    $_ENV['APP_ENV'] = $oldEnv;
    if ($oldNow === null) unset($_ENV['RESERVATION_TEST_NOW']); else $_ENV['RESERVATION_TEST_NOW'] = $oldNow;
    if ($oldEnv === null) unset($_ENV['APP_ENV']);
    if ($oldNow === null) putenv('RESERVATION_TEST_NOW'); else putenv('RESERVATION_TEST_NOW=' . $oldNow);
    foreach ([['CONTACT_ENCRYPTION_KEY', $oldEnc], ['CONTACT_LOOKUP_KEY', $oldLookup]] as [$keyName, $oldValue]) {
        if ($oldValue === null) {
            unset($_ENV[$keyName]);
            putenv($keyName);
        } else {
            $_ENV[$keyName] = $oldValue;
            putenv($keyName . '=' . $oldValue);
        }
    }
    ini_set('error_log', (string)$oldErrorLog);
    if (is_file($errorLog)) unlink($errorLog);
}
