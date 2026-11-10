<?php
declare(strict_types=1);

use Services\Contact\ContactoProtegidoService;
use Services\Contact\ContactoService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once __DIR__ . '/sql-loader.php';
try {
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
} catch (Throwable) {
    fwrite(STDERR, "Configuración inválida.\n");
    exit(1);
}

function seedEnv(string $name): ?string
{
    $processValue = getenv($name);
    $value = $processValue !== false ? $processValue : ($_ENV[$name] ?? null);
    return is_string($value) && $value !== '' ? $value : null;
}

/** @return array<string, array{type:string,plain:string,cipher:string,hash:string}> */
function readContactFixtureMetadata(string $sql): array
{
    $fixtures = [];
    $seenContacts = [];
    foreach (preg_split('/\R/', $sql) as $line) {
        if (!preg_match('/^\s*--\s*@contact-fixture\s+([a-f0-9]{16})\s+(email|telefono)\s+(.+)\s*$/D', $line, $match)) continue;
        $id = $match[1];
        $type = $match[2];
        $plainInput = json_decode($match[3], true);
        if (!is_string($plainInput) || isset($fixtures[$id])) {
            throw new RuntimeException('Metadata de fixture inválida.');
        }
        $plain = ContactoService::normalizar($type, $plainInput);
        $expectedId = substr(hash('sha256', $type . "\0" . $plain), 0, 16);
        $contactKey = $type . "\0" . $plain;
        if (!hash_equals($expectedId, $id) || isset($seenContacts[$contactKey])) {
            throw new RuntimeException('Metadata de fixture ambigua.');
        }
        $seenContacts[$contactKey] = true;
        $cipher = ContactoProtegidoService::cifrar($type, $plain);
        $hash = ContactoProtegidoService::identificador($type, $plain);
        $fixtures[$id] = compact('type', 'plain', 'cipher', 'hash');
    }
    if ($fixtures === []) throw new RuntimeException('Falta metadata de contactos.');

    preg_match_all('/@fx_contact_([a-f0-9]{16})\b/', $sql, $contactRefs);
    preg_match_all('/@fx_hash_([a-f0-9]{16})\b/', $sql, $hashRefs);
    $contactIds = array_count_values($contactRefs[1]);
    $hashIds = array_count_values($hashRefs[1]);
    if ($contactIds === [] || $contactIds !== $hashIds || array_diff_key($contactIds, $fixtures) !== []
        || array_diff_key($fixtures, $contactIds) !== []) {
        throw new RuntimeException('Referencias de metadata incompletas.');
    }
    return $fixtures;
}

$database = '';
$initialize = false;
$databaseSeen = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--database=') && !$databaseSeen) {
        $database = substr($argument, 11);
        $databaseSeen = true;
    }
    elseif ($argument === '--initialize' && !$initialize) $initialize = true;
    else {
        fwrite(STDERR, "Uso: php scripts/seed-development.php --database=NOMBRE [--initialize]\n");
        exit(2);
    }
}

if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database)
    || !in_array(seedEnv('APP_ENV'), ['development', 'test'], true)) {
    fwrite(STDERR, "Seeder sólo disponible en development/test y requiere destino explícito.\n");
    exit(2);
}

$db = null;
$transaction = false;
$phase = 'metadata';
try {
    $phase = 'configuration';
    ContactoProtegidoService::validarConfiguracion();
    $sql = file_get_contents($root . '/database/development.sql');
    if (!is_string($sql)) throw new RuntimeException('No se pudo leer la semilla.');
    $fixtures = readContactFixtureMetadata($sql);

    $phase = 'connect';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $db = new mysqli((string)seedEnv('DB_HOST'), (string)seedEnv('DB_USER'), (string)seedEnv('DB_PASS'));
    $db->set_charset('utf8mb4');
    if ($initialize) {
        $phase = 'create-database';
        $db->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }
    $db->select_db($database);
    $selected = (string)$db->query('SELECT DATABASE()')->fetch_row()[0];
    if ($selected !== $database) throw new RuntimeException('No se pudo seleccionar destino.');
    $db->query("SET time_zone = '-06:00'");

    if ($initialize) {
        $phase = 'create-schema';
        executeSqlFile($db, (string)file_get_contents($root . '/database/ddl.sql'));
        executeSqlFile($db, (string)file_get_contents($root . '/database/deploy.sql'));
    }

    $phase = 'preflight';
    // development.sql sólo es una entrada para bases recién desplegadas.
    $reservations = (int)$db->query('SELECT COUNT(*) FROM reservaciones')->fetch_row()[0];
    $tickets = (int)$db->query('SELECT COUNT(*) FROM tickets')->fetch_row()[0];
    if ($reservations !== 0 || $tickets !== 0) throw new RuntimeException('El destino no está limpio.');

    $phase = 'bind-fixtures';
    foreach ($fixtures as $id => $fixture) {
        $stmt = $db->prepare("SET @fx_contact_{$id} = ?, @fx_hash_{$id} = ?");
        $stmt->bind_param('ss', $fixture['cipher'], $fixture['hash']);
        $stmt->execute();
        $stmt->close();
    }

    $phase = 'load-sql';
    $db->begin_transaction();
    $transaction = true;
    executeSqlFile($db, $sql);

    $phase = 'verify-rows';
    $result = $db->query('SELECT contacto_tipo, contacto, contacto_hash FROM reservaciones');
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $type = (string)$row['contacto_tipo'];
        if ($type === 'ninguno') {
            if ($row['contacto'] !== null || $row['contacto_hash'] !== null) {
                throw new RuntimeException('Fila sin contacto incompatible.');
            }
        } else {
            $plain = ContactoProtegidoService::descifrar($type, (string)$row['contacto']);
            $expectedHash = ContactoProtegidoService::identificador($type, $plain);
            if (!is_string($row['contacto_hash']) || !hash_equals($expectedHash, $row['contacto_hash'])) {
                throw new RuntimeException('Fila de contacto no íntegra.');
            }
        }
        $count++;
    }
    $result->free();
    if ($count !== 142) throw new RuntimeException('La carga no produjo el conjunto esperado.');
    $phase = 'commit';
    $db->commit();
    $transaction = false;
    echo "DEVELOPMENT_SEEDED: {$count} reservaciones protegidas.\n";
} catch (Throwable) {
    if ($transaction && $db instanceof mysqli) {
        try { $db->rollback(); } catch (Throwable) {}
    }
    fwrite(STDERR, "Carga de development.sql detenida en fase {$phase}; no se muestran datos ni secretos.\n");
    exit(1);
} finally {
    if ($db instanceof mysqli) $db->close();
}
