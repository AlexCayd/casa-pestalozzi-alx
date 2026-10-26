<?php
declare(strict_types=1);

/** Pruebas de concurrencia 3C con workers PHP y una base InnoDB desechable. */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';
require_once __DIR__ . '/fixture-3c.php';

use MenuPreservacion\Comun;
use Multinegocio\SqlArchivo;
use Services\Inventory\Almacenes;

function conc3cOptions(array $args): array
{
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        if (!preg_match('/^--(host|base|entorno)$/D', $args[$i], $m) || !isset($args[$i + 1])
            || str_starts_with($args[$i + 1], '--') || isset($options[$m[1]])) {
            throw new RuntimeException('Uso: --host 127.0.0.1 --base casa-pestalozzi --entorno development');
        }
        $options[$m[1]] = $args[++$i];
    }
    foreach (['host', 'base', 'entorno'] as $key) if (empty($options[$key])) throw new RuntimeException("Falta --$key.");
    if ($options['host'] !== '127.0.0.1' || $options['base'] !== 'casa-pestalozzi' || $options['entorno'] !== 'development') {
        throw new RuntimeException('Este runner solo acepta --host 127.0.0.1 --base casa-pestalozzi --entorno development.');
    }
    return $options;
}

function conc3cAssert(bool $ok, string $message, int &$count): void
{
    if (!$ok) throw new RuntimeException($message);
    $count++;
}

function conc3cIdent(string $name): string
{
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) throw new RuntimeException('Nombre de base temporal inválido.');
    return '`' . $name . '`';
}

function conc3cCreateDb(mysqli $admin, string $root, array &$owned): array
{
    $name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
    $token = bin2hex(random_bytes(32));
    $admin->query('CREATE DATABASE ' . conc3cIdent($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $owned[] = [$name, $token];
    $admin->select_db($name);
    $admin->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $stmt = $admin->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');
    $stmt->bind_param('s', $token); $stmt->execute(); $stmt->close();
    SqlArchivo::ejecutar($admin, $root . '/database/ddl.sql');
    SqlArchivo::ejecutar($admin, $root . '/database/deploy.sql');
    return [$name, $token];
}

function conc3cOwned(mysqli $admin, string $name, string $token, string $origin): bool
{
    if ($name === $origin || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) return false;
    try {
        $exists = $admin->query("SHOW DATABASES LIKE '" . $admin->real_escape_string($name) . "'")->fetch_row();
        if (!$exists) return false;
        $admin->select_db($name);
        $rows = $admin->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
        return count($rows) === 1 && hash_equals($token, (string)$rows[0][0]);
    } catch (Throwable) { return false; }
}

function conc3cUnidad(mysqli $db, string $slug): int
{
    $stmt = $db->prepare('SELECT id FROM unidades WHERE slug=?'); $stmt->bind_param('s', $slug); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row) throw new RuntimeException("Falta unidad $slug en deploy.sql.");
    return (int)$row['id'];
}

function conc3cService(mysqli $db, int $actor): Almacenes { return new Almacenes($db, $actor, 'contrasena'); }

function conc3cOk(array $result, string $what): array
{
    if (($result['ok'] ?? false) !== true || ($result['confirmado'] ?? false) !== true) {
        throw new RuntimeException($what . ': ' . (string)($result['codigo'] ?? 'falló o quedó pendiente'));
    }
    return $result;
}

function conc3cSucceeded(array $result): bool
{
    return ($result['ok'] ?? false) === true && ($result['confirmado'] ?? false) === true;
}

function conc3cDoc(string $key, string $ref, int $warehouse, int $article, string $quantity, string $reason = 'prueba_concurrencia'): array
{
    return ['clave' => $key, 'referencia' => $ref, 'origen' => 'motor_inventario', 'motivo' => $reason,
        'lineas' => [['almacen_id' => $warehouse, 'articulo_id' => $article, 'cantidad' => $quantity]]];
}

function conc3cTmpDir(string $owner): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cp3c_' . bin2hex(random_bytes(12));
    if (!mkdir($dir, 0700)) throw new RuntimeException('No se pudo crear carpeta temporal de barreras.');
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . 'owner', $owner, LOCK_EX) === false) throw new RuntimeException('No se pudo marcar carpeta temporal.');
    return $dir;
}

function conc3cSpawn(string $worker, array $env, array $request): array
{
    $pipes = [];
    $proc = proc_open([PHP_BINARY, $worker], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname($worker), $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) throw new RuntimeException('No se pudo iniciar worker PHP.');
    $GLOBALS['conc3c_children'][] = ['proc'=>$proc,'pipes'=>$pipes];
    fwrite($pipes[0], json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); fclose($pipes[0]);
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    return ['proc' => $proc, 'pipes' => $pipes, 'stdout' => '', 'stderr' => '', 'started' => microtime(true)];
}

function conc3cCollect(array &$child): array
{
    $deadline = microtime(true) + 15.0;
    do {
        $child['stdout'] .= (string)stream_get_contents($child['pipes'][1]);
        $child['stderr'] .= (string)stream_get_contents($child['pipes'][2]);
        $status = proc_get_status($child['proc']);
        if (!$status['running']) break;
        if (microtime(true) >= $deadline) { proc_terminate($child['proc']); throw new RuntimeException('Worker excedió el timeout de 15 segundos.'); }
        usleep(10000);
    } while (true);
    $child['stdout'] .= (string)stream_get_contents($child['pipes'][1]);
    $child['stderr'] .= (string)stream_get_contents($child['pipes'][2]);
    fclose($child['pipes'][1]); fclose($child['pipes'][2]);
    $exit = proc_close($child['proc']);
    $decoded = json_decode($child['stdout'], true);
    if (!is_array($decoded)) throw new RuntimeException('Worker sin respuesta JSON válida.');
    $decoded['_exit'] = $exit;
    return $decoded;
}

function conc3cReady(string $dir, string $prefix, int $count, string $owner): array
{
    $deadline = microtime(true) + 15.0;
    do {
        $files = glob($dir . DIRECTORY_SEPARATOR . $prefix . '-*.ready') ?: [];
        if (count($files) >= $count) {
            $threads = [];
            foreach ($files as $file) {
                $parts = explode("\t", trim((string)file_get_contents($file)));
                if (count($parts) !== 2 || !hash_equals($owner, $parts[0]) || !ctype_digit($parts[1])) throw new RuntimeException('Ready marker worker alterado.');
                $threads[] = (int)$parts[1];
            }
            return $threads;
        }
        if (microtime(true) >= $deadline) throw new RuntimeException('Timeout esperando workers listos.');
        usleep(10000);
    } while (true);
}

function conc3cRelease(string $dir, string $prefix, string $owner): void
{
    if (file_put_contents($dir . DIRECTORY_SEPARATOR . $prefix . '.go', $owner, LOCK_EX) === false) throw new RuntimeException('No se pudo liberar barrera.');
}

function conc3cWaitForLock(mysqli $db, int $threadId): bool
{
    $deadline = microtime(true) + 15.0;
    do {
        $stmt = $db->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=?');
        $stmt->bind_param('i', $threadId); $stmt->execute(); $waiting = (int)$stmt->get_result()->fetch_row()[0] > 0; $stmt->close();
        if ($waiting) return true;
        if (microtime(true) >= $deadline) return false;
        usleep(10000);
    } while (true);
}

function conc3cCount(mysqli $db, string $query, string $types, array $values): int
{
    $stmt = $db->prepare($query); if ($types !== '') $stmt->bind_param($types, ...$values); $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_row()[0]; $stmt->close(); return $count;
}

$admin = null; $owned = []; $tmpDir = null; $options = []; $config = []; $count = 0; $phase = 'argumentos/configuración'; $error = null; $output = null;
try {
    $options = conc3cOptions(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $config = $_ENV;
    Comun::origen($config, $options, $root); // Valida el destino lógico; no abre el esquema origen.
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $admin = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');
    $phase = 'crear esquema temporal con marca de propiedad';
    [$database, $owner] = conc3cCreateDb($admin, $root, $owned);
    $tmpDir = conc3cTmpDir($owner);
    $fixtures = FixtureAlmacenes3C::usuario($admin, 'concurrencia-global', [
        'global' => ['permisos' => ['inventario' => 'editar'], 'facultades' => ['almacenes.crear', 'catalogo.editar']],
        'restaurante' => ['permisos' => ['inventario' => 'editar']],
    ]);
    $actor = (int)$fixtures['id'];
    $service = conc3cService($admin, $actor);
    $phase = 'fixtures de almacén y existencias';
    $warehouse = (int)conc3cOk($service->crearAlmacen('global', 'restaurante', 'Concurrencia', false), 'crear almacén')['almacen_id'];
    $item = (int)conc3cOk($service->crearArticulo('global', ['nombre' => 'Artículo concurrencia', 'unidad' => 'pza', 'costo' => '1.0000']), 'crear artículo')['articulo_id'];
    conc3cOk($service->asociarArticulo('restaurante', $warehouse, $item, '0.000'), 'asociar artículo');
    conc3cOk($service->registrarEntrada('restaurante', ['clave' => 'seed-stock', 'referencia' => 'seed-stock', 'origen' => 'motor_inventario', 'motivo' => 'fixture', 'lineas' => [['almacen_id' => $warehouse, 'articulo_id' => $item, 'cantidad' => '5.000', 'costo_unitario' => '1.0000']]]), 'sembrar existencias');

    $worker = __DIR__ . '/worker3c.php';
    $workerEnv = array_replace(getenv(), ['DB_HOST' => (string)$config['DB_HOST'], 'DB_USER' => (string)$config['DB_USER'], 'DB_PASS' => (string)$config['DB_PASS'],
        'DB_PORT' => (string)($config['DB_PORT'] ?? 3306), 'CP_3C_DATABASE' => $database, 'CP_3C_OWNER' => $owner]);
    $barrier = static fn(string $prefix): array => ['dir' => $tmpDir, 'prefix' => $prefix];

    // Dos salidas concurrentes de 4 contra saldo 5: una sola transacción puede confirmar.
    $phase = 'salidas concurrentes con bloqueo de saldo';
    $prefix = 'saldo';
    $docA = conc3cDoc('saldo-a', 'saldo-a', $warehouse, $item, '4.000');
    $docB = conc3cDoc('saldo-b', 'saldo-b', $warehouse, $item, '4.000');
    $a = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $docA, 'barrier' => $barrier($prefix)]);
    $b = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $docB, 'barrier' => $barrier($prefix)]);
    conc3cReady($tmpDir, $prefix, 2, $owner); conc3cRelease($tmpDir, $prefix, $owner);
    $ra = conc3cCollect($a); $rb = conc3cCollect($b);
    conc3cAssert((int)(conc3cSucceeded($ra) + conc3cSucceeded($rb)) === 1, 'Exactamente una salida concurrente debe confirmar sobre saldo 5.',$count);
    conc3cAssert(conc3cCount($admin, 'SELECT COUNT(*) FROM movimientos_inventario WHERE almacen_id=? AND articulo_id=? AND tipo IN (\'venta\',\'salida\')', 'ii', [$warehouse, $item]) === 1, 'La carrera de saldo no debe crear movimiento parcial ni duplicado.', $count);

    // Reiniciar saldo por entrada y validar dos peticiones simultáneas idénticas.
    conc3cOk($service->registrarEntrada('restaurante', ['clave' => 'seed-replay', 'referencia' => 'seed-replay', 'origen' => 'motor_inventario', 'motivo' => 'fixture', 'lineas' => [['almacen_id' => $warehouse, 'articulo_id' => $item, 'cantidad' => '10.000']]]), 'reponer existencias');
    $phase = 'idempotencia concurrente'; $prefix = 'replay';
    $same = conc3cDoc('same-key', 'same-key', $warehouse, $item, '2.000');
    $a = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $same, 'barrier' => $barrier($prefix)]);
    $b = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $same, 'barrier' => $barrier($prefix)]);
    conc3cReady($tmpDir, $prefix, 2, $owner); conc3cRelease($tmpDir, $prefix, $owner);
    $ra = conc3cCollect($a); $rb = conc3cCollect($b);
    conc3cAssert(conc3cSucceeded($ra) && conc3cSucceeded($rb), 'Reintentos concurrentes idénticos deben tener éxito.',$count);
    conc3cAssert(($ra['operacion_id'] ?? null) === ($rb['operacion_id'] ?? null), 'Reintentos idénticos deben devolver la misma operación.',$count);
    conc3cAssert(count($ra['movimientos'] ?? []) === count($rb['movimientos'] ?? []), 'Reintentos idénticos deben devolver el mismo resultado de movimientos.',$count);
    $movementIdsA = array_column($ra['movimientos'] ?? [], 'id'); sort($movementIdsA);
    $movementIdsB = array_column($rb['movimientos'] ?? [], 'id'); sort($movementIdsB);
    conc3cAssert($movementIdsA === $movementIdsB, 'Reintentos idénticos deben devolver los mismos IDs de movimiento.',$count);
    conc3cAssert(conc3cCount($admin, 'SELECT COUNT(*) FROM movimientos_inventario m JOIN operaciones_inventario o ON o.id=m.operacion_id AND o.unidad_id=m.unidad_id WHERE o.clave_idempotencia=?', 's', ['same-key']) === 1, 'La clave repetida debe crear un único movimiento.',$count);

    // La misma clave con cantidades distintas debe terminar en conflicto, aun en paralelo.
    $phase = 'conflicto de payload concurrente'; $prefix = 'conflict';
    $different = conc3cDoc('same-conflict-key', 'same-conflict-key', $warehouse, $item, '1.000');
    $other = conc3cDoc('same-conflict-key', 'same-conflict-key', $warehouse, $item, '3.000');
    $a = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $different, 'barrier' => $barrier($prefix)]);
    $b = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $other, 'barrier' => $barrier($prefix)]);
    conc3cReady($tmpDir, $prefix, 2, $owner); conc3cRelease($tmpDir, $prefix, $owner);
    $ra = conc3cCollect($a); $rb = conc3cCollect($b);
    conc3cAssert((int)(conc3cSucceeded($ra) + conc3cSucceeded($rb)) === 1, 'Solo un payload puede ganar una clave idempotente.',$count);
    $failure = conc3cSucceeded($ra) ? $rb : $ra;
    conc3cAssert(($failure['codigo'] ?? '') === 'CONFLICTO_IDEMPOTENCIA', 'Payload distinto con la misma clave debe devolver conflicto.',$count);

    // Preparar una salida original y tratar de revertirla dos veces desde conexiones distintas.
    $phase = 'reversión concurrente';
    conc3cOk($service->registrarEntrada('restaurante', ['clave' => 'seed-reverse', 'referencia' => 'seed-reverse', 'origen' => 'motor_inventario', 'motivo' => 'fixture', 'lineas' => [['almacen_id' => $warehouse, 'articulo_id' => $item, 'cantidad' => '10.000']]]), 'reponer existencias para reversión');
    $original = conc3cOk($service->registrarSalida('restaurante', conc3cDoc('origin-reverse', 'origin-reverse', $warehouse, $item, '4.000')), 'salida origen reversión');
    $sourceMovement = (int)$original['movimientos'][0]['id'];
    $reverseDoc = static fn(string $key): array => ['clave' => $key, 'referencia' => $key, 'origen' => 'motor_inventario', 'motivo' => 'reversion_prueba', 'lineas' => [['movimiento_id' => $sourceMovement, 'cantidad' => '4.000']]];
    $prefix = 'reverse';
    $a = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'revertir', 'document' => $reverseDoc('reverse-a'), 'barrier' => $barrier($prefix)]);
    $b = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'revertir', 'document' => $reverseDoc('reverse-b'), 'barrier' => $barrier($prefix)]);
    conc3cReady($tmpDir, $prefix, 2, $owner); conc3cRelease($tmpDir, $prefix, $owner);
    $ra = conc3cCollect($a); $rb = conc3cCollect($b);
    conc3cAssert((int)(conc3cSucceeded($ra) + conc3cSucceeded($rb)) === 1, 'Una sola reversión concurrente puede compensar el movimiento completo.',$count);
    $failure = conc3cSucceeded($ra) ? $rb : $ra;
    conc3cAssert(($failure['codigo'] ?? '') === 'REVERSION_EXCEDIDA', 'Una segunda reversión debe respetar la cantidad original.',$count);

    // La consulta de innodb_trx confirma que la segunda conexión esperó el bloqueo de fila.
    $phase = 'espera de bloqueo InnoDB'; $prefix = 'lockwait';
    $admin->begin_transaction();
    $lockStmt = $admin->prepare('SELECT saldo FROM almacen_articulos WHERE almacen_id=? AND articulo_id=? FOR UPDATE');
    $lockStmt->bind_param('ii', $warehouse, $item); $lockStmt->execute(); $lockStmt->get_result()->fetch_row(); $lockStmt->close();
    $waitDoc = conc3cDoc('wait-lock', 'wait-lock', $warehouse, $item, '1.000');
    $child = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $waitDoc, 'barrier' => $barrier($prefix)]);
    $threadIds = conc3cReady($tmpDir, $prefix, 1, $owner);
    conc3cRelease($tmpDir, $prefix, $owner);
    $observedWait = conc3cWaitForLock($admin, $threadIds[0]);
    $admin->commit();
    $waitResult = conc3cCollect($child);
    conc3cAssert($observedWait, 'El worker debe aparecer esperando bloqueo antes de liberar la fila: '.json_encode($waitResult), $count);
    conc3cAssert(conc3cSucceeded($waitResult), 'La operación debe continuar al liberar el bloqueo.', $count);

    // Quitar el permiso antes de una nueva escritura; no debe persistir operación ni movimiento.
    $phase = 'permiso revocado antes de escritura';
    $restId = conc3cUnidad($admin, 'restaurante');
    $beforeOps = conc3cCount($admin, 'SELECT COUNT(*) FROM operaciones_inventario WHERE clave_idempotencia=?', 's', ['revoked-write']);
    $revoke = $admin->prepare('DELETE FROM usuario_unidad_permisos WHERE usuario_id=? AND unidad_id=? AND modulo=\'inventario\'');
    $revoke->bind_param('ii', $actor, $restId); $revoke->execute(); $revoke->close();
    $denied = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida',
        'document' => conc3cDoc('revoked-write', 'revoked-write', $warehouse, $item, '1.000')]);
    $deniedResult = conc3cCollect($denied);
    conc3cAssert(($deniedResult['ok'] ?? true) === false, 'El permiso revocado debe denegar una salida nueva.', $count);
    conc3cAssert(conc3cCount($admin, 'SELECT COUNT(*) FROM operaciones_inventario WHERE clave_idempotencia=?', 's', ['revoked-write']) === $beforeOps,
        'El permiso revocado debe denegar antes de registrar operación.', $count);
    conc3cAssert(conc3cCount($admin, 'SELECT COUNT(*) FROM movimientos_inventario m JOIN operaciones_inventario o ON o.id=m.operacion_id AND o.unidad_id=m.unidad_id WHERE o.clave_idempotencia=?', 's', ['revoked-write']) === 0,
        'El permiso revocado no debe registrar movimientos.', $count);

    // Dos workers solicitan los mismos recursos en orden inverso; el servicio debe ordenar bloqueos.
    $phase = 'orden determinista de bloqueos';
    $grant = $admin->prepare('INSERT INTO usuario_unidad_permisos(usuario_id,unidad_id,modulo,nivel) VALUES (?,?,\'inventario\',\'editar\')');
    $grant->bind_param('ii', $actor, $restId); $grant->execute(); $grant->close();
    $otherItem = (int)conc3cOk($service->crearArticulo('global', ['nombre' => 'Artículo orden', 'unidad' => 'pza', 'costo' => '1.0000']), 'crear segundo artículo')['articulo_id'];
    conc3cOk($service->asociarArticulo('restaurante', $warehouse, $otherItem, '0.000'), 'asociar segundo artículo');
    $seedOrder = ['clave' => 'seed-order', 'referencia' => 'seed-order', 'origen' => 'motor_inventario', 'motivo' => 'fixture', 'lineas' => [
        ['almacen_id' => $warehouse, 'articulo_id' => $item, 'cantidad' => '3.000'], ['almacen_id' => $warehouse, 'articulo_id' => $otherItem, 'cantidad' => '3.000'],
    ]];
    conc3cOk($service->registrarEntrada('restaurante', $seedOrder), 'sembrar artículos para orden');
    $phase = 'orden determinista de bloqueos'; $prefix = 'order';
    $orderedA = ['clave' => 'order-a', 'referencia' => 'order-a', 'origen' => 'motor_inventario', 'motivo' => 'orden', 'lineas' => [
        ['almacen_id' => $warehouse, 'articulo_id' => $item, 'cantidad' => '1.000'], ['almacen_id' => $warehouse, 'articulo_id' => $otherItem, 'cantidad' => '1.000'],
    ]];
    $orderedB = ['clave' => 'order-b', 'referencia' => 'order-b', 'origen' => 'motor_inventario', 'motivo' => 'orden', 'lineas' => array_reverse($orderedA['lineas'])];
    $a = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $orderedA, 'barrier' => $barrier($prefix)]);
    $b = conc3cSpawn($worker, $workerEnv, ['actor_id' => $actor, 'ctx' => 'restaurante', 'operation' => 'salida', 'document' => $orderedB, 'barrier' => $barrier($prefix)]);
    conc3cReady($tmpDir, $prefix, 2, $owner); conc3cRelease($tmpDir, $prefix, $owner);
    $ra = conc3cCollect($a); $rb = conc3cCollect($b);
    conc3cAssert(conc3cSucceeded($ra) && conc3cSucceeded($rb), 'Operaciones con líneas en orden inverso deben concluir sin deadlock.', $count);

    $output = ['ok' => true, 'comprobaciones' => $count, 'escenarios' => ['salidas concurrentes', 'replay idempotente', 'conflicto de payload',
        'reversión concurrente', 'espera InnoDB', 'permiso revocado antes de escritura', 'orden de bloqueo'], 'base_temporal' => $database];
} catch (Throwable $e) {
    $error = $e instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $e->getCode() . '.' : $e->getMessage();
} finally {
    if ($admin instanceof mysqli) { try { $admin->rollback(); } catch (Throwable) {} }
    foreach ($GLOBALS['conc3c_children'] ?? [] as $child) {
        if (!is_resource($child['proc'])) continue;
        if (proc_get_status($child['proc'])['running']) proc_terminate($child['proc']);
        foreach ($child['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($child['proc']);
    }
    if (is_string($tmpDir) && is_dir($tmpDir)) {
        $real = realpath($tmpDir); $temp = realpath(sys_get_temp_dir());
        if ($real !== false && $temp !== false && dirname($real) === $temp && !is_link($tmpDir)
            && preg_match('/^cp3c_[a-f0-9]{24}$/D',basename($real)) && is_file($real . '/owner')
            && hash_equals($owner,trim((string)file_get_contents($real . '/owner')))) {
            foreach (glob($real . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) @unlink($file);
            @rmdir($real);
        }
    }
    $cleanupFailed = false;
    if ($admin instanceof mysqli) {
        foreach (array_reverse($owned) as [$name, $token]) {
            try {
                if (conc3cOwned($admin, $name, $token, (string)($config['DB_NAME'] ?? ''))) {
                    $admin->query('DROP DATABASE ' . conc3cIdent($name));
                    fwrite(STDOUT, "Base temporal verificada y eliminada: $name\n");
                } else $cleanupFailed = true;
            } catch (Throwable) { $cleanupFailed = true; }
        }
        $admin->close();
    }
    if ($cleanupFailed && $error === null) { $phase = 'limpieza'; $error = 'No se pudo verificar o limpiar una base temporal.'; }
}
if ($error === null) echo Comun::json($output ?? ['ok' => true, 'comprobaciones' => $count]);
else fwrite(STDERR, Comun::json(['ok' => false, 'fase' => $phase, 'comprobaciones' => $count, 'error' => $error]));
exit($error === null ? 0 : 1);
