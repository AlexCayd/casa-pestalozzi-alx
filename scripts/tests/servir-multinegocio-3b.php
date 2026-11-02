<?php
declare(strict_types=1);

/**
 * Levanta la aplicación 3B sobre una base temporal y un servidor loopback.
 * Uso: php scripts/tests/servir-multinegocio-3b.php --host HOST --base BASE --entorno development|test
 * Es interactivo: mantiene el servidor mientras espera una línea por STDIN y
 * detiene/limpia solo los recursos que verifica como propios en finally.
 */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';

use MenuPreservacion\Comun;
use Multinegocio\SqlArchivo;

function servir3bOpciones(array $args): array
{
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        if (!preg_match('/^--(host|base|entorno)$/D', $args[$i], $m)
            || !isset($args[$i + 1]) || str_starts_with($args[$i + 1], '--') || isset($options[$m[1]])) {
            throw new RuntimeException('Uso: --host HOST --base BASE --entorno development|test');
        }
        $options[$m[1]] = $args[++$i];
    }
    foreach (['host','base','entorno'] as $key) {
        if (!isset($options[$key]) || $options[$key] === '') throw new RuntimeException("Falta --$key.");
    }
    return $options;
}

function servir3bIdent(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Identificador SQL inesperado.');
    return '`' . $name . '`';
}

function servir3bVerificarBase(mysqli $admin, string $name, string $token, string $baseOrigen): bool
{
    if ($name === $baseOrigen || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) return false;
    $found = $admin->query("SHOW DATABASES LIKE '" . $admin->real_escape_string($name) . "'")->fetch_row();
    if (!$found) return false;
    $admin->select_db($name);
    try {
        $rows = $admin->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
        return count($rows) === 1 && hash_equals($token, (string)$rows[0][0]);
    } catch (Throwable) { return false; }
}

function servir3bLimpiarDirectorio(string $path, string $parent): void
{
    if (!file_exists($path) && !is_link($path)) return;
    $realParent = realpath($parent);
    $real = realpath($path);
    if (!$realParent || !$real || is_link($path) || dirname($real) !== $realParent
        || !preg_match('/^cp-multinegocio-3b-session-[a-f0-9]{16}$/D', basename($real))) {
        throw new RuntimeException('No se verificó el directorio temporal de sesiones antes de borrarlo.');
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        if ($entry->isLink() || !$entry->isDir()) unlink($entry->getPathname());
        else rmdir($entry->getPathname());
    }
    rmdir($real);
}

$admin = null;
$server = null;
$pipes = [];
$base = '';
$ownerToken = '';
$sessionPath = '';
$serverLogPath = '';
$cleanupBaseAuthorized = false;
$options = [];
$config = [];
$failure = null;
$phase = 'argumentos/configuración';
$exitCode = 0;
try {
    $options = servir3bOpciones(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $config = $_ENV;
    Comun::origen($config, $options, $root); // Confirma origen; no abre ni selecciona DB_NAME.
    if (!extension_loaded('mysqli') || !function_exists('proc_open')) throw new RuntimeException('Se requieren mysqli y proc_open para el harness local.');
    $portProbe = @fsockopen('127.0.0.1', 8091, $errno, $errstr, 0.2);
    if (is_resource($portProbe)) { fclose($portProbe); throw new RuntimeException('El puerto 8091 ya está ocupado.'); }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $phase = 'conexión administrativa sin esquema';
    $admin = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');

    $phase = 'creación y despliegue en base temporal';
    $base = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
    $ownerToken = bin2hex(random_bytes(32));
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $base)) throw new RuntimeException('Nombre de base temporal inválido.');
    $admin->query('CREATE DATABASE ' . servir3bIdent($base) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $cleanupBaseAuthorized = true;
    $admin->select_db($base);
    $admin->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $stmt = $admin->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');
    $stmt->bind_param('s', $ownerToken);
    $stmt->execute();
    $stmt->close();
    SqlArchivo::ejecutar($admin, $root . '/database/ddl.sql');
    SqlArchivo::ejecutar($admin, $root . '/database/deploy.sql');

    $phase = 'cuentas de prueba sintéticas';
    $nipSecret = bin2hex(random_bytes(32));
    putenv('NIP_LOOKUP_SECRET=' . $nipSecret);
    $_ENV['NIP_LOOKUP_SECRET'] = $nipSecret;
    $adminPassword = 'Pass3B_' . bin2hex(random_bytes(6));
    $adminUsername = 'qa3b_' . bin2hex(random_bytes(4));
    $adminHash = password_hash($adminPassword, PASSWORD_DEFAULT);
    $stmt = $admin->prepare('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin) VALUES (?,?,?,1,1)');
    $adminName = 'QA 3B Superadministrador';
    $stmt->bind_param('sss', $adminUsername, $adminName, $adminHash);
    $stmt->execute();
    $stmt->close();

    $nipUsername = 'qa3b_piso_' . bin2hex(random_bytes(3));
    $nipValue = str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
    $nipCredential = Services\Users\NipService::credencial($nipValue);
    $nipHash = $nipCredential['hash'];
    $nipLookup = $nipCredential['lookup'];
    $floorPasswordHash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
    $floorName = 'QA 3B Operación Restaurante';
    $stmt = $admin->prepare('INSERT INTO usuarios(username,nombre,password_hash,nip_hash,nip_lookup,activo,es_superadmin) VALUES (?,?,?,?,?,1,0)');
    $stmt->bind_param('sssss', $nipUsername, $floorName, $floorPasswordHash, $nipHash, $nipLookup);
    $stmt->execute();
    $nipUserId = (int)$admin->insert_id;
    $stmt->close();
    $restUnitId = (int)$admin->query("SELECT id FROM unidades WHERE slug='restaurante'")->fetch_row()[0];
    $stmt = $admin->prepare('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,1)');
    $stmt->bind_param('ii', $nipUserId, $restUnitId);
    $stmt->execute();
    $stmt->close();
    $stmt = $admin->prepare("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,'pdv','operar')");
    $stmt->bind_param('ii', $nipUserId, $restUnitId);
    $stmt->execute();
    $stmt->close();

    $readerUsername = 'qa3b_lectura_' . bin2hex(random_bytes(3));
    $readerPassword = 'Pass3B_' . bin2hex(random_bytes(6));
    $readerHash = password_hash($readerPassword, PASSWORD_DEFAULT);
    $readerName = 'QA 3B Solo lectura';
    $stmt = $admin->prepare('INSERT INTO usuarios(username,nombre,password_hash,activo,es_superadmin) VALUES (?,?,?,1,0)');
    $stmt->bind_param('sss', $readerUsername, $readerName, $readerHash);
    $stmt->execute();
    $readerId = (int)$admin->insert_id;
    $stmt->close();
    $globalUnitId = (int)$admin->query("SELECT id FROM unidades WHERE slug='global'")->fetch_row()[0];
    $stmt = $admin->prepare('INSERT INTO usuario_unidades(usuario_id,unidad_id,rol_id,activo) VALUES (?,?,NULL,1)');
    $stmt->bind_param('ii', $readerId, $globalUnitId);
    $stmt->execute();
    $stmt->close();
    $stmt = $admin->prepare("INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,'users','ver'), (?,?,'menu','ver')");
    $stmt->bind_param('iiii', $readerId, $globalUnitId, $readerId, $globalUnitId);
    $stmt->execute();
    $stmt->close();
    $stmt = $admin->prepare("INSERT INTO usuario_unidad_facultades(usuario_id,unidad_id,facultad) VALUES (?,?,'usuarios.gestionar')");
    $stmt->bind_param('ii', $readerId, $globalUnitId);
    $stmt->execute();
    $stmt->close();

    $sessionParent = sys_get_temp_dir();
    $sessionPath = $sessionParent . DIRECTORY_SEPARATOR . 'cp-multinegocio-3b-session-' . bin2hex(random_bytes(8));
    if (!mkdir($sessionPath, 0700)) throw new RuntimeException('No se pudo crear la carpeta aislada de sesiones.');
    $serverLogPath = $sessionPath . DIRECTORY_SEPARATOR . 'php-server.log';
    $nonce = bin2hex(random_bytes(16));
    $router = $root . '/scripts/tests/router-multinegocio-3b.php';
    $public = $root . '/public';
    if (!is_file($router) || !is_dir($public)) throw new RuntimeException('Faltan el router fixture o el directorio público.');

    $phase = 'servidor PHP local';
    $descriptors = [0 => ['pipe','r'], 1 => ['file',$serverLogPath,'a'], 2 => ['file',$serverLogPath,'a']];
    $env = getenv();
    if (!is_array($env)) $env = [];
    $env['CP_3B_TEST_DB'] = $base;
    $env['CP_3B_DB_HOST'] = (string)$config['DB_HOST'];
    $env['CP_3B_DB_USER'] = (string)$config['DB_USER'];
    $env['CP_3B_DB_PASS'] = (string)$config['DB_PASS'];
    $env['CP_3B_DB_PORT'] = (string)($config['DB_PORT'] ?? 3306);
    $env['CP_3B_OWNER_TOKEN'] = $ownerToken;
    $env['CP_3B_NIP_SECRET'] = $nipSecret;
    $env['CP_3B_SESSION_PATH'] = $sessionPath;
    $env['CP_3B_APP_ENV'] = $options['entorno'];
    $env['CP_3B_BASE_ORIGEN'] = $options['base'];
    $env['CP_3B_EXTERNAL_URL'] = 'http://127.0.0.1:9';
    $env['CP_3B_EXTERNAL_SECRET'] = bin2hex(random_bytes(24));
    $env['TMP'] = $sessionPath;
    $env['TEMP'] = $sessionPath;
    $env['TMPDIR'] = $sessionPath;
    $command = [PHP_BINARY, '-S', '127.0.0.1:8091', '-t', $public, $router];
    $server = proc_open($command, $descriptors, $pipes, $root, $env, ['create_process_group' => true]);
    if (!is_resource($server)) throw new RuntimeException('No se pudo iniciar PHP built-in server.');
    $ready = false;
    $until = microtime(true) + 12;
    while (microtime(true) < $until) {
        $socket = @fsockopen('127.0.0.1', 8091, $socketErrno, $socketError, 0.1);
        if (is_resource($socket)) { fclose($socket); $ready = true; break; }
        $status = proc_get_status($server);
        if (!$status['running']) break;
        usleep(100000);
    }
    if (!$ready) {
        $serverLog = is_file($serverLogPath) ? trim((string)file_get_contents($serverLogPath)) : '';
        throw new RuntimeException('El servidor no inició en 127.0.0.1:8091.' . ($serverLog !== '' ? ' ' . $serverLog : ''));
    }

    $info = [
        'ok' => true,
        'url' => 'http://127.0.0.1:8091/',
        'base_temporal' => $base,
        'nonce_limpieza' => $nonce,
        'superadministrador' => ['usuario' => $adminUsername, 'contrasena' => $adminPassword],
        'piso_restaurante' => ['usuario' => $nipUsername, 'nip' => $nipValue],
        'solo_lectura_global' => ['usuario' => $readerUsername, 'contrasena' => $readerPassword],
        'instruccion' => 'Servidor temporal listo. Para detenerlo, envía la línea LIMPIAR ' . $nonce . ' por STDIN. La base se borra tras verificar su marca privada.',
    ];
    fwrite(STDOUT, Comun::json($info) . "\n");
    fflush(STDOUT);
    $expected = 'LIMPIAR ' . $nonce;
    while (($line = fgets(STDIN)) !== false) {
        if (hash_equals($expected, trim($line))) break;
    }
} catch (Throwable $e) {
    $exitCode = 1;
    $failure = $e instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $e->getCode() . '.' : $e->getMessage();
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);
        proc_close($server);
    }
    $cleanupFailed = false;
    if ($admin instanceof mysqli) {
        if ($cleanupBaseAuthorized && $base !== '' && $ownerToken !== '') {
            try {
                if (servir3bVerificarBase($admin, $base, $ownerToken, (string)($config['DB_NAME'] ?? ''))) {
                    $admin->query('DROP DATABASE ' . servir3bIdent($base));
                    if ($failure === null) fwrite(STDOUT, "Base temporal verificada y eliminada: $base\n");
                } else { $cleanupFailed = true; }
            } catch (Throwable) { $cleanupFailed = true; }
        }
        $admin->close();
    }
    if ($sessionPath !== '') {
        try { servir3bLimpiarDirectorio($sessionPath, sys_get_temp_dir()); }
        catch (Throwable) { $cleanupFailed = true; }
    }
    if ($cleanupFailed) { $exitCode = 1; if ($failure === null) $failure = 'No se pudo comprobar o limpiar un recurso temporal.'; }
}
if ($failure !== null) fwrite(STDERR, Comun::json(['ok' => false, 'fase' => $phase, 'error' => $failure]) . "\n");
exit($exitCode);
