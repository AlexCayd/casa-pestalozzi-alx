<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/sql-loader.php';
Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$database = 'cp_development_seed_test_' . bin2hex(random_bytes(6));
$existingEmptyDatabase = 'cp_development_seed_test_' . bin2hex(random_bytes(6));
$existingMarkedDatabase = 'cp_development_seed_test_' . bin2hex(random_bytes(6));
$db = null;
$createdDatabases = [];
$failed = false;
$phase = 'connect';
$encKey = random_bytes(32);
do { $lookupKey = random_bytes(32); } while (hash_equals($encKey, $lookupKey));
$encEncoded = base64_encode($encKey);
$lookupEncoded = base64_encode($lookupKey);
$_ENV['CONTACT_ENCRYPTION_KEY'] = $encEncoded;
$_ENV['CONTACT_LOOKUP_KEY'] = $lookupEncoded;
$_ENV['APP_ENV'] = 'test';
putenv('CONTACT_ENCRYPTION_KEY=' . $encEncoded);
putenv('CONTACT_LOOKUP_KEY=' . $lookupEncoded);
putenv('APP_ENV=test');

function developmentSeedAssert(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

/** @return array{exit:int,output:string} */
function invokeDevelopmentSeeder(string $root, string $database, string $enc, string $lookup, bool $initialize = false, bool $clearKeys = false): array
{
    $env = array_merge(getenv(), $_ENV);
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS'] as $name) {
        $env[$name] = (string)($_ENV[$name] ?? getenv($name) ?: '');
    }
    $env['APP_ENV'] = 'test';
    $env['CONTACT_ENCRYPTION_KEY'] = $enc;
    $env['CONTACT_LOOKUP_KEY'] = $lookup;
    $stdout = tempnam(sys_get_temp_dir(), 'cp-dev-seed-out-');
    $stderr = tempnam(sys_get_temp_dir(), 'cp-dev-seed-err-');
    $command = [PHP_BINARY, $root . '/scripts/seed-development.php', '--database=' . $database];
    if ($initialize) $command[] = '--initialize';
    if ($clearKeys) {
        $wrapper = tempnam(sys_get_temp_dir(), 'cp-dev-seed-wrapper-');
        $wrapperPhp = "<?php\nputenv('CONTACT_ENCRYPTION_KEY=');\nputenv('CONTACT_LOOKUP_KEY=');\n"
            . "\$_ENV['CONTACT_ENCRYPTION_KEY'] = '';\n\$_ENV['CONTACT_LOOKUP_KEY'] = '';\n"
            . '$argv = [__FILE__, ' . var_export('--database=' . $database, true) . ', ' . var_export('--initialize', true) . "];\n"
            . 'require ' . var_export($root . '/scripts/seed-development.php', true) . ";\n";
        if (file_put_contents($wrapper, $wrapperPhp) === false) throw new RuntimeException('No se pudo preparar el runner de configuración ausente.');
        $command = [PHP_BINARY, $wrapper];
    }
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']],
        $pipes,
        $root,
        $env
    );
    if (!is_resource($process)) throw new RuntimeException('No se pudo iniciar seeder.');
    fclose($pipes[0]);
    $exit = proc_close($process);
    $output = (string)file_get_contents($stdout) . (string)file_get_contents($stderr);
    unlink($stdout);
    unlink($stderr);
    if (isset($wrapper) && is_file($wrapper)) unlink($wrapper);
    return ['exit' => $exit, 'output' => $output];
}

/** Inserta una fila deliberadamente incompatible con CHECK. */
function rejectedReservationInsert(mysqli $db, string $type, ?string $contact, ?string $hash): bool
{
    $name = 'DDL constraint probe';
    $date = (new DateTimeImmutable('+5 days'))->format('Y-m-d');
    $time = '14:00:00';
    $guests = 2;
    $note = '';
    $origin = 'admin';
    $status = 'confirmada';
    $changed = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    try {
        $stmt = $db->prepare('INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, contacto_hash, fecha, hora, comensales, nota, origen, estado, estado_changed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssssissss', $name, $type, $contact, $hash, $date, $time, $guests, $note, $origin, $status, $changed);
        $stmt->execute();
        $stmt->close();
        return false;
    } catch (mysqli_sql_exception $exception) {
        return $exception->getCode() === 3819;
    }
}

try {
    $db = new mysqli((string)($_ENV['DB_HOST'] ?? getenv('DB_HOST')),
        (string)($_ENV['DB_USER'] ?? getenv('DB_USER')),
        (string)($_ENV['DB_PASS'] ?? getenv('DB_PASS')));
    $db->set_charset('utf8mb4');
    $schemaExists = static function (mysqli $connection, string $name): bool {
        $stmt = $connection->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $exists = (int)$stmt->get_result()->fetch_row()[0] === 1;
        $stmt->close();
        return $exists;
    };
    $rememberOwnedDatabase = static function (string $name) use ($db, $schemaExists, &$createdDatabases): void {
        if ($schemaExists($db, $name) && !in_array($name, $createdDatabases, true)) $createdDatabases[] = $name;
    };
    foreach ([$database, $existingEmptyDatabase, $existingMarkedDatabase] as $temporaryName) {
        developmentSeedAssert(!$schemaExists($db, $temporaryName), 'Nombre temporal ya existente; suite abortada sin cambios.');
    }

    $phase = 'invalid-key-initialize';
    $invalidKey = invokeDevelopmentSeeder($root, $database, '', '', true, true);
    $rememberOwnedDatabase($database);
    if ($invalidKey['exit'] === 0) { $phase = 'invalid-key-accepted'; throw new RuntimeException(''); }
    $phase = 'invalid-key-no-database';
    developmentSeedAssert(!$schemaExists($db, $database), 'Configuración ausente creó una base.');
    developmentSeedAssert(!str_contains($invalidKey['output'], $encEncoded) && !str_contains($invalidKey['output'], $lookupEncoded), 'Configuración ausente filtró claves.');
    $phase = 'malformed-key-initialize';
    $malformedKey = invokeDevelopmentSeeder($root, $database, 'not-base64', $lookupEncoded, true);
    $rememberOwnedDatabase($database);
    developmentSeedAssert($malformedKey['exit'] !== 0, 'Seeder aceptó clave malformada.');
    $phase = 'malformed-key-no-database';
    developmentSeedAssert(!$schemaExists($db, $database), 'Configuración inválida creó una base.');
    developmentSeedAssert(!str_contains($malformedKey['output'], 'not-base64') && !str_contains($malformedKey['output'], $lookupEncoded), 'Configuración inválida filtró valores.');

    $db->query("CREATE DATABASE `{$existingEmptyDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdDatabases[] = $existingEmptyDatabase;
    $phase = 'existing-empty-guard';
    $emptyTarget = invokeDevelopmentSeeder($root, $existingEmptyDatabase, $encEncoded, $lookupEncoded, true);
    $db->select_db($existingEmptyDatabase);
    developmentSeedAssert($emptyTarget['exit'] !== 0
        && (int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_row()[0] === 0,
        'Initialize alteró una base existente vacía.');

    $db->query("CREATE DATABASE `{$existingMarkedDatabase}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $createdDatabases[] = $existingMarkedDatabase;
    $db->select_db($existingMarkedDatabase);
    $db->query('CREATE TABLE sentinel (id INT PRIMARY KEY, marker VARCHAR(40) NOT NULL)');
    $db->query("INSERT INTO sentinel VALUES (1, 'preserve-this-row')");
    $phase = 'existing-marked-guard';
    $markedTarget = invokeDevelopmentSeeder($root, $existingMarkedDatabase, $encEncoded, $lookupEncoded, true);
    $marker = $db->query('SELECT marker FROM sentinel WHERE id = 1')->fetch_row()[0];
    developmentSeedAssert($markedTarget['exit'] !== 0 && $marker === 'preserve-this-row'
        && (int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetch_row()[0] === 1,
        'Initialize modificó objetos/datos de una base existente.');

    $phase = 'initialize';
    $seed = invokeDevelopmentSeeder($root, $database, rtrim($encEncoded, '='), rtrim($lookupEncoded, '='), true);
    $rememberOwnedDatabase($database);
    if ($seed['exit'] !== 0) {
        $phase = preg_match('/Carga de development\.sql detenida en fase ([a-z-]+)/', $seed['output'], $seedPhase)
            ? 'seed-' . $seedPhase[1]
            : 'seed-child-failure';
        throw new RuntimeException('Seeder no completó.');
    }
    $db->select_db($database);
    developmentSeedAssert((string)$db->query('SELECT DATABASE()')->fetch_row()[0] === $database, 'La suite no aisló la base de prueba.');
    $db->query("SET time_zone = '-06:00'");
    developmentSeedAssert($seed['exit'] === 0 && str_contains($seed['output'], 'DEVELOPMENT_SEEDED: 142 reservaciones protegidas.'), 'La carga limpia no completó los 142 escenarios.');
    developmentSeedAssert(!str_contains($seed['output'], $encEncoded) && !str_contains($seed['output'], $lookupEncoded), 'Seeder mostró una clave.');

    $phase = 'verify-rows';
    $total = (int)$db->query('SELECT COUNT(*) FROM reservaciones')->fetch_row()[0];
    developmentSeedAssert($total === 142, 'Conteo de escenarios reservación cambió.');
    $result = $db->query('SELECT id, contacto_tipo, contacto, contacto_hash, request_token, fecha, comensales, estado FROM reservaciones ORDER BY id');
    $contactCounts = ['email' => 0, 'telefono' => 0, 'ninguno' => 0];
    while ($row = $result->fetch_assoc()) {
        $type = (string)$row['contacto_tipo'];
        $contactCounts[$type]++;
        if ($type === 'ninguno') {
            developmentSeedAssert($row['contacto'] === null && $row['contacto_hash'] === null, 'Fixture ninguno debe persistir NULL/NULL.');
        } else {
            $plain = Services\Contact\ContactoProtegidoService::descifrar($type, (string)$row['contacto']);
            $expectedHash = Services\Contact\ContactoProtegidoService::identificador($type, $plain);
            developmentSeedAssert(str_starts_with((string)$row['contacto'], 'cp1:')
                && hash_equals($expectedHash, (string)$row['contacto_hash']), 'Ciphertext/HMAC de desarrollo no pasa verificación.');
        }
    }
    $result->free();
    developmentSeedAssert($contactCounts === ['email' => 141, 'telefono' => 1, 'ninguno' => 0], 'Distribución de contactos de fixtures cambió.');
    foreach ([
        'fx-limite-una-0001' => ['email', 'limite.una@example.test'],
        'fx-contacto-tel-001' => ['telefono', '+525544442026'],
        'fx-hold-vigente-001' => ['email', 'hold.vigente@example.test'],
        'fx-limite-cinco-05' => ['email', 'limite.cinco@example.test'],
    ] as $token => [$type, $expectedPlain]) {
        $stmt = $db->prepare('SELECT contacto_tipo,contacto,contacto_hash FROM reservaciones WHERE request_token = ?');
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        developmentSeedAssert(is_array($row) && $row['contacto_tipo'] === $type
            && Services\Contact\ContactoProtegidoService::descifrar($type, (string)$row['contacto']) === $expectedPlain
            && hash_equals(Services\Contact\ContactoProtegidoService::identificador($type, $expectedPlain), (string)$row['contacto_hash']),
            'Metadata asignó contacto a escenario equivocado.');
    }
    developmentSeedAssert((int)$db->query("SELECT COUNT(*) FROM reservaciones WHERE request_token LIKE 'fx-limite-cinco-%'")->fetch_row()[0] === 5, 'Escenario límite de cinco reservas incompleto.');
    developmentSeedAssert((int)$db->query("SELECT COUNT(*) FROM reservaciones WHERE estado = 'pendiente_verificacion'")->fetch_row()[0] === 2, 'Escenarios hold incompletos.');
    developmentSeedAssert((int)$db->query('SELECT COUNT(*) FROM reservaciones WHERE comensales > 12')->fetch_row()[0] >= 2, 'Escenarios de grupos grandes incompletos.');
    developmentSeedAssert((int)$db->query("SELECT COUNT(*) FROM reservaciones WHERE request_token = 'fx-historica-000001' AND fecha = CURDATE()")->fetch_row()[0] === 1, 'Fecha de escenario histórico no se conserva.');
    developmentSeedAssert((int)$db->query('SELECT COUNT(*) FROM reservacion_mesas rm LEFT JOIN reservaciones r ON r.id = rm.reservacion_id WHERE r.id IS NULL')->fetch_row()[0] === 0, 'Hay relaciones de mesas sin reservación.');
    developmentSeedAssert((int)$db->query('SELECT COUNT(*) FROM tickets t LEFT JOIN reservaciones r ON r.id = t.reservacion_id WHERE t.reservacion_id IS NOT NULL AND r.id IS NULL')->fetch_row()[0] === 0, 'Hay tickets sin reservación relacionada.');

    $phase = 'check-constraints';
    $plain = 'plain.constraint@example.test';
    $plainHash = Services\Contact\ContactoProtegidoService::identificador('email', $plain);
    developmentSeedAssert(rejectedReservationInsert($db, 'email', $plain, $plainHash), 'CHECK aceptó texto plano con hash válido.');
    $protected = Services\Contact\ContactoProtegidoService::cifrar('email', 'null.hash.constraint@example.test');
    developmentSeedAssert(rejectedReservationInsert($db, 'email', $protected, null), 'CHECK aceptó cp1 sin HMAC.');
    developmentSeedAssert(rejectedReservationInsert($db, 'ninguno', null, str_repeat('a', 64)), 'CHECK aceptó hash en tipo ninguno.');
    $upperPrefix = 'CP1:' . substr($protected, 4);
    $upperHash = Services\Contact\ContactoProtegidoService::identificador('email', 'null.hash.constraint@example.test');
    developmentSeedAssert(rejectedReservationInsert($db, 'email', $upperPrefix, $upperHash), 'CHECK aceptó envoltura con prefijo de versión incorrecto.');

    $otpPlainRejected = false;
    try {
        $otpType = 'email';
        $otpContact = 'plain.otp.constraint@example.test';
        $otpHash = Services\Contact\ContactoProtegidoService::identificador($otpType, $otpContact);
        $codeHash = password_hash('123456', PASSWORD_DEFAULT);
        $expires = (new DateTimeImmutable('+10 minutes'))->format('Y-m-d H:i:s');
        $stmt = $db->prepare('INSERT INTO verificaciones_contacto (contacto_tipo,contacto,contacto_hash,codigo_hash,expires_at) VALUES (?,?,?,?,?)');
        $stmt->bind_param('sssss', $otpType, $otpContact, $otpHash, $codeHash, $expires);
        $stmt->execute();
        $stmt->close();
    } catch (mysqli_sql_exception $exception) {
        $otpPlainRejected = $exception->getCode() === 3819;
    }
    developmentSeedAssert($otpPlainRejected, 'CHECK OTP aceptó contacto en texto plano.');

    $phase = 'reseed-guard';
    $rerun = invokeDevelopmentSeeder($root, $database, $encEncoded, $lookupEncoded);
    developmentSeedAssert($rerun['exit'] !== 0 && (int)$db->query('SELECT COUNT(*) FROM reservaciones')->fetch_row()[0] === 142, 'Seeder aceptó una base ya sembrada.');
    echo "Reconstrucción limpia: 142 reservaciones, 134 contactos sintéticos, referencias y CHECK protegidos PASS.\n";
} catch (Throwable) {
    $failed = true;
    fwrite(STDERR, "Prueba aislada de reconstrucción fallida en fase {$phase}; no se muestran datos ni SQL.\n");
} finally {
    if ($db instanceof mysqli) {
        foreach ($createdDatabases as $createdDatabase) {
            if (!preg_match('/^cp_development_seed_test_[a-f0-9]{12}$/D', $createdDatabase)) {
                $failed = true;
                continue;
            }
            try { $db->query("DROP DATABASE `{$createdDatabase}`"); } catch (Throwable) { $failed = true; }
        }
    }
    if ($db instanceof mysqli) $db->close();
}
exit($failed ? 1 : 0);
