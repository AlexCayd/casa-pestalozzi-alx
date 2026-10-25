<?php
declare(strict_types=1);

/** Worker concurrente 3D.2: solo abre una base marcada por este runner. */
if (PHP_SAPI !== 'cli') { exit(2); }
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Model\ActiveRecord;
use Services\Pos\Comercial;

function worker3d2Ident(string $database): string
{
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $database)) throw new RuntimeException('BASE_INVALIDA');
    return '`' . $database . '`';
}

function worker3d2EsperarBarrera(array $barrier, string $owner): void
{
    if (!isset($barrier['dir'], $barrier['prefix']) || !is_string($barrier['dir']) || !is_string($barrier['prefix'])
        || !preg_match('/^[a-z0-9_-]{1,80}$/D', $barrier['prefix'])) throw new RuntimeException('BARRERA_INVALIDA');
    $dir = realpath($barrier['dir']);
    $temp = realpath(sys_get_temp_dir());
    if ($dir === false || $temp === false || !str_starts_with(strtolower($dir . DIRECTORY_SEPARATOR), strtolower($temp . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('BARRERA_FUERA_TEMP');
    }
    $ownerFile = $dir . DIRECTORY_SEPARATOR . 'owner';
    if (!is_file($ownerFile) || is_link($ownerFile) || !hash_equals($owner, trim((string)file_get_contents($ownerFile)))) {
        throw new RuntimeException('BARRERA_OWNER_INVALIDO');
    }
    $ready = $dir . DIRECTORY_SEPARATOR . $barrier['prefix'] . '-' . getmypid() . '.ready';
    if (file_put_contents($ready, $owner, LOCK_EX) === false) throw new RuntimeException('BARRERA_READY_FALLO');
    $go = $dir . DIRECTORY_SEPARATOR . $barrier['prefix'] . '.go';
    $deadline = microtime(true) + 30.0;
    while (!is_file($go)) {
        if (microtime(true) >= $deadline) throw new RuntimeException('BARRERA_TIMEOUT');
        usleep(10000);
    }
    if (is_link($go) || !hash_equals($owner, trim((string)file_get_contents($go)))) throw new RuntimeException('BARRERA_GO_INVALIDA');
}

try {
    $input = json_decode((string)stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('INPUT_INVALIDO');
    $database = (string)(getenv('CP_3D2_DATABASE') ?: '');
    $owner = (string)(getenv('CP_3D2_OWNER') ?: '');
    $host = (string)(getenv('DB_HOST') ?: '');
    $user = (string)(getenv('DB_USER') ?: '');
    $pass = (string)(getenv('DB_PASS') ?: '');
    $port = (int)(getenv('DB_PORT') ?: 3306);
    if (!preg_match('/^[a-f0-9]{64}$/D', $owner) || $host !== '127.0.0.1' || $user === '' || $port < 1 || $port > 65535) {
        throw new RuntimeException('WORKER_CONFIG_INVALIDA');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = new mysqli($host, $user, $pass, '', $port);
    $admin->set_charset('utf8mb4');
    $marked = $admin->query('SELECT token FROM ' . worker3d2Ident($database) . '._cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
    $admin->close();
    if (count($marked) !== 1 || !hash_equals($owner, (string)$marked[0][0])) throw new RuntimeException('DB_OWNER_INVALIDO');

    $db = new mysqli($host, $user, $pass, $database, $port);
    $db->set_charset('utf8mb4');
    ActiveRecord::setDB($db);
    $actorId = (int)($input['actor_id'] ?? 0);
    if ($actorId < 1) throw new RuntimeException('ACTOR_INVALIDO');
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION = ['login' => true, 'id' => $actorId, 'auth_nivel' => 'nip', '_auth3b' => true];
    if (isset($input['barrier'])) worker3d2EsperarBarrera($input['barrier'], $owner);

    $operation = (string)($input['operation'] ?? '');
    $ctx = (string)($input['ctx'] ?? 'restaurante');
    $result = match ($operation) {
        'send' => Comercial::enviarComanda((int)($input['ticket_id'] ?? 0), $input['items'] ?? [], (string)($input['key'] ?? ''), $ctx),
        'cancel' => Comercial::cancelarItem((int)($input['item_id'] ?? 0), $ctx),
        default => throw new RuntimeException('WORKER_OPERATION_INVALIDA'),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $db->close();
    exit(0);
} catch (Throwable $e) {
    $code = $e instanceof mysqli_sql_exception ? 'SQL_' . $e->getCode() : $e->getMessage();
    fwrite(STDOUT, json_encode(['ok' => false, 'worker_error' => $code], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    exit(1);
}
