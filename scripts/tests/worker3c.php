<?php
declare(strict_types=1);

/** Worker de concurrencia 3C. Solo conecta a una base temporal marcada. */
if (PHP_SAPI !== 'cli') { exit(2); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Services\Inventory\Almacenes;

function worker3cIdent(string $name): string
{
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) {
        throw new RuntimeException('Nombre de base temporal inválido.');
    }
    return '`' . $name . '`';
}

function worker3cBarrier(array $barrier, string $owner, int $threadId): void
{
    if (!isset($barrier['dir'], $barrier['prefix']) || !is_string($barrier['dir']) || !is_string($barrier['prefix'])
        || !preg_match('/^[a-z0-9_-]{1,80}$/D', $barrier['prefix'])) {
        throw new RuntimeException('Barrera inválida.');
    }
    $dir = realpath($barrier['dir']);
    $temp = realpath(sys_get_temp_dir());
    if ($dir === false || $temp === false || !str_starts_with(strtolower($dir . DIRECTORY_SEPARATOR), strtolower($temp . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('La ruta de barrera no pertenece al directorio temporal.');
    }
    $marker = $dir . DIRECTORY_SEPARATOR . 'owner';
    if (!is_file($marker) || !hash_equals($owner, trim((string)file_get_contents($marker)))) {
        throw new RuntimeException('La barrera temporal no está marcada por este runner.');
    }
    $ready = $dir . DIRECTORY_SEPARATOR . $barrier['prefix'] . '-' . getmypid() . '.ready';
    if (file_put_contents($ready, $owner . "\t" . $threadId, LOCK_EX) === false) throw new RuntimeException('No se pudo señalar disponibilidad.');
    $deadline = microtime(true) + 15.0;
    $go = $dir . DIRECTORY_SEPARATOR . $barrier['prefix'] . '.go';
    while (!is_file($go)) {
        if (microtime(true) >= $deadline) throw new RuntimeException('Tiempo agotado esperando barrera.');
        usleep(10000);
    }
    if (!hash_equals($owner, trim((string)file_get_contents($go)))) throw new RuntimeException('La liberación de barrera no coincide.');
}

try {
    $raw = stream_get_contents(STDIN);
    $input = json_decode($raw === false ? '' : $raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new RuntimeException('Entrada worker inválida.');
    $database = (string)(getenv('CP_3C_DATABASE') ?: '');
    $owner = (string)(getenv('CP_3C_OWNER') ?: '');
    $dbHost = (string)(getenv('DB_HOST') ?: '');
    $dbUser = (string)(getenv('DB_USER') ?: '');
    $dbPass = (string)(getenv('DB_PASS') ?: '');
    $dbPort = (int)(getenv('DB_PORT') ?: 3306);
    if (!preg_match('/^[a-f0-9]{64}$/D', $owner) || $dbHost === '' || $dbUser === '' || $dbPort < 1 || $dbPort > 65535) {
        throw new RuntimeException('Configuración de worker incompleta.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = new mysqli($dbHost, $dbUser, $dbPass, '', $dbPort);
    $admin->set_charset('utf8mb4');
    $qualified = worker3cIdent($database) . '._cp_multinegocio_owner';
    $rows = $admin->query('SELECT token FROM ' . $qualified)->fetch_all(MYSQLI_NUM);
    if (count($rows) !== 1 || !hash_equals($owner, (string)$rows[0][0])) {
        throw new RuntimeException('La base no tiene la marca de propiedad esperada.');
    }
    $admin->close();

    $db = new mysqli($dbHost, $dbUser, $dbPass, $database, $dbPort);
    $db->set_charset('utf8mb4');
    if (isset($input['barrier'])) worker3cBarrier($input['barrier'], $owner, $db->thread_id);

    $actorId = (int)($input['actor_id'] ?? 0);
    $ctx = (string)($input['ctx'] ?? '');
    if ($actorId < 1 || !in_array($ctx, ['global', 'restaurante', 'panaderia'], true)) {
        throw new RuntimeException('Contexto de usuario inválido.');
    }
    $service = new Almacenes($db, $actorId, (string)($input['auth_nivel'] ?? 'contrasena'));
    $operation = (string)($input['operation'] ?? 'salida');
    $document = $input['document'] ?? null;
    if (!is_array($document)) throw new RuntimeException('Documento inválido.');
    $result = match ($operation) {
        'salida' => $service->registrarSalida($ctx, $document),
        'revertir' => $service->revertirMovimientos($ctx, $document),
        default => throw new RuntimeException('Operación worker no permitida.'),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $db->close();
    exit(0);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'worker_error' => $e instanceof mysqli_sql_exception ? 'SQL_' . $e->getCode() : $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    exit(1);
}
