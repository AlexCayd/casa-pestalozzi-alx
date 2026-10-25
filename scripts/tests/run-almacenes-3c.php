<?php
declare(strict_types=1);

/** Pruebas aisladas del motor de Almacenes 3C.1; nunca abre la base configurada. */
if (PHP_SAPI !== 'cli') { exit(1); }

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
require_once $root . '/scripts/menu/bootstrap.php';
require_once $root . '/scripts/multinegocio/SqlArchivo.php';
require_once __DIR__ . '/fixture-3c.php';

use MenuPreservacion\Comun;
use Multinegocio\SqlArchivo;
use Services\Inventory\Almacenes;

function test3cOptions(array $args): array
{
    $options = [];
    for ($i = 0; $i < count($args); $i++) {
        if ($args[$i] === '--solo-motor') {
            if (isset($options['solo_motor'])) throw new RuntimeException('Opción duplicada: --solo-motor.');
            $options['solo_motor'] = true;
            continue;
        }
        if (!preg_match('/^--(host|base|entorno)$/D', $args[$i], $m) || !isset($args[$i + 1])
            || str_starts_with($args[$i + 1], '--') || isset($options[$m[1]])) {
            throw new RuntimeException('Uso: --host 127.0.0.1 --base casa-pestalozzi --entorno development|test [--solo-motor]');
        }
        $options[$m[1]] = $args[++$i];
    }
    foreach (['host', 'base', 'entorno'] as $key) {
        if (!isset($options[$key]) || $options[$key] === '') throw new RuntimeException("Falta --$key.");
    }
    if ($options['host'] !== '127.0.0.1' || $options['base'] !== 'casa-pestalozzi'
        || !in_array($options['entorno'], ['development', 'test'], true)) {
        throw new RuntimeException('3C solo acepta --host 127.0.0.1 --base casa-pestalozzi y --entorno development|test.');
    }
    return $options;
}

function test3cAssert(bool $condition, string $message, int &$count): void
{
    if (!$condition) throw new RuntimeException($message);
    $count++;
}

function test3cIdent(string $name): string
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new RuntimeException('Identificador SQL inesperado.');
    return '`' . $name . '`';
}

function test3cCreateDatabase(mysqli $admin, string $root, array &$bases): array
{
    $name = 'cp_multinegocio_test_' . bin2hex(random_bytes(10));
    $token = bin2hex(random_bytes(32));
    if (!preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) throw new RuntimeException('Base temporal inválida.');
    $admin->query('CREATE DATABASE ' . test3cIdent($name) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $bases[] = [$name, $token];
    $admin->select_db($name);
    $admin->query('CREATE TABLE _cp_multinegocio_owner(token CHAR(64) NOT NULL PRIMARY KEY) ENGINE=InnoDB');
    $stmt = $admin->prepare('INSERT INTO _cp_multinegocio_owner(token) VALUES (?)');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
    SqlArchivo::ejecutar($admin, $root . '/database/ddl.sql');
    SqlArchivo::ejecutar($admin, $root . '/database/deploy.sql');
    return [$name, $token];
}

function test3cCanDrop(mysqli $admin, string $name, string $token, string $origin): bool
{
    if ($name === $origin || !preg_match('/^cp_multinegocio_test_[a-f0-9]{20}$/D', $name)) return false;
    $found = $admin->query("SHOW DATABASES LIKE '" . $admin->real_escape_string($name) . "'")->fetch_row();
    if (!$found) return false;
    $admin->select_db($name);
    try {
        $rows = $admin->query('SELECT token FROM _cp_multinegocio_owner')->fetch_all(MYSQLI_NUM);
        return count($rows) === 1 && hash_equals($token, (string)$rows[0][0]);
    } catch (Throwable) { return false; }
}

function test3cCall(callable $call, string $message, int &$count): array
{
    $result = $call();
    test3cAssert(is_array($result) && ($result['ok'] ?? false) === true && ($result['confirmado'] ?? false) === true, $message . ' ' . json_encode($result, JSON_UNESCAPED_UNICODE), $count);
    return $result;
}

function test3cDenied(callable $call, string $message, int &$count): array
{
    $result = $call();
    test3cAssert(is_array($result) && ($result['ok'] ?? true) === false, $message . ' ' . json_encode($result, JSON_UNESCAPED_UNICODE), $count);
    return $result;
}

function test3cService(mysqli $db, int $actorId): Almacenes
{
    return new Almacenes($db, $actorId, 'contrasena');
}

function test3cDoc(string $key, string $reference, array $lines, string $reason = 'qa-3c'): array
{
    return ['clave' => $key, 'referencia' => $reference, 'origen' => 'motor_inventario', 'motivo' => $reason, 'lineas' => $lines];
}

function test3cCore(mysqli $db, int &$count): array
{
    $global = FixtureAlmacenes3C::usuario($db, 'global', ['global' => [
        'permisos' => ['inventario' => 'editar'], 'facultades' => ['almacenes.crear', 'catalogo.editar'],
    ]]);
    $rest = FixtureAlmacenes3C::usuario($db, 'rest', ['restaurante' => ['permisos' => ['inventario' => 'editar']]]);
    $pan = FixtureAlmacenes3C::usuario($db, 'pan', ['panaderia' => ['permisos' => ['inventario' => 'editar']]]);
    $inactive = FixtureAlmacenes3C::usuario($db, 'inactive', ['restaurante' => ['permisos' => ['inventario' => 'editar']]], false);
    $localGlobal = test3cService($db, (int)$global['id']);
    $localRest = test3cService($db, (int)$rest['id']);
    $localPan = test3cService($db, (int)$pan['id']);

    $g = test3cCall(fn() => $localGlobal->crearAlmacen('global', 'global', 'Almacén QA Global', false), 'Crear almacén Global.', $count);
    $r = test3cCall(fn() => $localGlobal->crearAlmacen('global', 'restaurante', 'Almacén QA Restaurante', true), 'Global crea almacén de Restaurante con negativos.', $count);
    $p = test3cCall(fn() => $localGlobal->crearAlmacen('global', 'panaderia', 'Almacén QA Panadería', false), 'Global crea almacén de Panadería.', $count);
    $gid = (int)($g['almacen_id'] ?? 0); $rid = (int)($r['almacen_id'] ?? 0); $pid = (int)($p['almacen_id'] ?? 0);
    test3cAssert($gid > 0 && $rid > 0 && $pid > 0, 'crearAlmacen debe devolver almacen_id.', $count);
    test3cDenied(fn() => $localRest->crearAlmacen('restaurante', 'restaurante', 'No permitido', false), 'Administrador local no crea almacenes.', $count);

    $articleData = ['nombre' => 'Artículo QA 3C', 'unidad' => 'kg', 'costo' => '1.2500', 'activo' => true];
    $article = test3cCall(fn() => $localGlobal->crearArticulo('global', $articleData), 'Crear artículo maestro.', $count);
    $articleId = (int)($article['articulo_id'] ?? 0);
    test3cAssert($articleId > 0, 'crearArticulo debe devolver articulo_id.', $count);
    $master = $db->query("SELECT nombre,unidad,costo,activo FROM ingredientes WHERE id=$articleId")->fetch_assoc();
    test3cAssert($master['unidad'] === 'kg' && $master['costo'] === '1.2500' && (int)$master['activo'] === 1, 'La identidad maestra conserva unidad, costo decimal y estado.', $count);
    test3cDenied(fn() => $localGlobal->crearArticulo('global', $articleData), 'Rechaza artículos maestros duplicados.', $count);
    test3cDenied(fn() => $localRest->editarArticulo('restaurante', $articleId, ['nombre'=>'Intento local']), 'Negocio no puede editar la identidad maestra.', $count);
    test3cCall(fn() => $localGlobal->editarArticulo('global', $articleId, ['costo'=>'2.3456','activo'=>false]), 'Global actualiza costo y estado maestro.', $count);
    $updatedMaster = $db->query("SELECT unidad,costo,activo FROM ingredientes WHERE id=$articleId")->fetch_assoc();
    test3cAssert($updatedMaster['unidad'] === 'kg' && $updatedMaster['costo'] === '2.3456' && (int)$updatedMaster['activo'] === 0, 'Edición maestra mantiene unidad y actualiza costo/estado con precisión.', $count);
    test3cCall(fn() => $localGlobal->editarArticulo('global', $articleId, ['activo'=>true]), 'Reactivar artículo maestro para operaciones.', $count);
    test3cCall(fn() => $localGlobal->asociarArticulo('global', $gid, $articleId), 'Asociar artículo a Global.', $count);
    test3cCall(fn() => $localRest->asociarArticulo('restaurante', $rid, $articleId), 'Asociar el mismo artículo a Restaurante.', $count);
    test3cCall(fn() => $localPan->asociarArticulo('panaderia', $pid, $articleId), 'Asociar el mismo artículo a Panadería.', $count);
    $initial = $db->query("SELECT COUNT(*),SUM(saldo=0) FROM almacen_articulos WHERE articulo_id=$articleId AND almacen_id IN ($gid,$rid,$pid)")->fetch_row();
    test3cAssert((int)$initial[0] === 3 && (int)$initial[1] === 3, 'Asociaciones separadas empiezan en cero.', $count);

    $entryG = test3cCall(fn() => $localGlobal->registrarEntrada('global', test3cDoc('qa3c-entry-g', 'qa3c-entry-g', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'5.125','costo_unitario'=>'1.2500']])), 'Registrar entrada decimal.', $count);
    $entryR = test3cCall(fn() => $localRest->registrarEntrada('restaurante', test3cDoc('qa3c-entry-r', 'qa3c-entry-r', [['almacen_id'=>$rid,'articulo_id'=>$articleId,'cantidad'=>'1.000']])), 'Registrar entrada en Restaurante.', $count);
    test3cDenied(fn() => $localGlobal->editarArticulo('global', $articleId, ['unidad'=>'g']), 'La unidad maestra no puede cambiarse tras registrar movimientos.', $count);
    test3cAssert(($entryG['movimientos'][0]['saldo'] ?? '') === '5.125', 'La cantidad decimal se conserva exactamente.', $count);
    test3cAssert((string)$db->query("SELECT costo_unitario FROM movimientos_inventario WHERE operacion_id=" . (int)$entryG['operacion_id'] . ' LIMIT 1')->fetch_row()[0] === '1.2500', 'El costo unitario del movimiento conserva cuatro decimales.', $count);
    $version = (int)$db->query("SELECT version FROM almacen_articulos WHERE almacen_id=$gid AND articulo_id=$articleId")->fetch_row()[0];
    $adjust = test3cCall(fn() => $localGlobal->registrarAjuste('global', test3cDoc('qa3c-adjust', 'qa3c-adjust', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'4.500','version'=>$version]], 'conteo')), 'Registrar ajuste con versión.', $count);
    test3cAssert(($adjust['movimientos'][0]['saldo'] ?? '') === '4.500', 'Ajuste establece el conteo exacto.', $count);
    $waste = test3cCall(fn() => $localGlobal->registrarMerma('global', test3cDoc('qa3c-waste', 'qa3c-waste', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.500']], 'otro')), 'Registrar merma.', $count);
    test3cAssert(($waste['movimientos'][0]['saldo'] ?? '') === '4.000', 'La merma descuenta el saldo.', $count);
    $localNegative = test3cCall(fn() => $localRest->registrarMerma('restaurante', test3cDoc('qa3c-negative-ok', 'qa3c-negative-ok', [['almacen_id'=>$rid,'articulo_id'=>$articleId,'cantidad'=>'2.000']], 'otro')), 'Restaurante permite negativos.', $count);
    test3cAssert(($localNegative['movimientos'][0]['saldo'] ?? '') === '-1.000', 'La política negativa está en el almacén.', $count);
    test3cDenied(fn() => $localRest->configurarAlmacen('restaurante', $rid, ['permitir_negativos'=>false]), 'No se deshabilitan negativos mientras hay saldo negativo pendiente.', $count);
    $staleVersion = $version;
    $newVersion = (int)$db->query("SELECT version FROM almacen_articulos WHERE almacen_id=$gid AND articulo_id=$articleId")->fetch_row()[0];
    test3cDenied(fn() => $localGlobal->registrarAjuste('global', test3cDoc('qa3c-adjust-stale', 'qa3c-adjust-stale', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'4.000','version'=>$staleVersion]], 'conteo')), 'Rechaza ajuste con versión obsoleta.', $count);
    test3cAssert($newVersion > $staleVersion, 'Las operaciones incrementan versión de concurrencia.', $count);
    $negativo = test3cDenied(fn() => $localGlobal->registrarMerma('global', test3cDoc('qa3c-negative-no', 'qa3c-negative-no', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'4.001']], 'otro')), 'Almacén con negativos deshabilitados bloquea saldo menor que cero.', $count);
    test3cAssert($negativo['codigo']==='SALDO_INSUFICIENTE','La salida se rechaza por falta de saldo, no por otro error.', $count);

    test3cDenied(fn() => $localRest->registrarEntrada('restaurante', test3cDoc('qa3c-cross-scope', 'qa3c-cross-scope', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'1.000']])), 'Rechaza operación sobre almacén ajeno.', $count);
    $emptyStore = test3cCall(fn() => $localGlobal->crearAlmacen('global', 'global', 'Almacén QA Inactivo', false), 'Crear almacén vacío para probar inactividad.', $count);
    $inactiveStoreId = (int)($emptyStore['almacen_id'] ?? 0);
    test3cCall(fn() => $localGlobal->asociarArticulo('global', $inactiveStoreId, $articleId), 'Asociar artículo al almacén que se desactivará.', $count);
    test3cCall(fn() => $localGlobal->configurarAlmacen('global', $inactiveStoreId, ['activo'=>false]), 'Desactivar almacén sin existencias ni movimientos.', $count);
    test3cDenied(fn() => $localGlobal->registrarEntrada('global', test3cDoc('qa3c-inactive', 'qa3c-inactive', [['almacen_id'=>$inactiveStoreId,'articulo_id'=>$articleId,'cantidad'=>'1.000']])), 'Rechaza operaciones en almacén inactivo.', $count);
    test3cDenied(fn() => test3cService($db, (int)$inactive['id'])->registrarEntrada('restaurante', test3cDoc('qa3c-inactive-actor', 'qa3c-inactive-actor', [['almacen_id'=>$rid,'articulo_id'=>$articleId,'cantidad'=>'1.000']])), 'Rechaza cuenta inactiva aunque tenga permiso asignado.', $count);

    $idemDoc = test3cDoc('qa3c-idem', 'qa3c-idem', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.250']]);
    $idem1 = test3cCall(fn() => $localGlobal->registrarEntrada('global', $idemDoc), 'Primera operación idempotente.', $count);
    $movesBefore = (int)$db->query("SELECT COUNT(*) FROM movimientos_inventario WHERE operacion_id=" . (int)$idem1['operacion_id'])->fetch_row()[0];
    $idem2 = test3cCall(fn() => $localGlobal->registrarEntrada('global', $idemDoc), 'Reintento idéntico idempotente.', $count);
    test3cAssert((int)$idem1['operacion_id'] === (int)$idem2['operacion_id'] && $movesBefore === (int)$db->query("SELECT COUNT(*) FROM movimientos_inventario WHERE operacion_id=" . (int)$idem1['operacion_id'])->fetch_row()[0], 'Reintento no duplica movimientos.', $count);
    test3cDenied(fn() => $localGlobal->registrarEntrada('global', test3cDoc('qa3c-idem', 'qa3c-idem', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.251']])), 'Misma clave con payload distinto entra en conflicto.', $count);

    $out = test3cCall(fn() => $localGlobal->registrarSalida('global', test3cDoc('qa3c-out-reverse', 'qa3c-out-reverse', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.500']])), 'Registrar salida para reversión.', $count);
    $movementId = (int)$out['movimientos'][0]['id'];
    $revDoc = test3cDoc('qa3c-reverse', 'qa3c-reverse', [['movimiento_id'=>$movementId,'cantidad'=>'0.500']]);
    test3cCall(fn() => $localGlobal->revertirMovimientos('global', $revDoc), 'Revertir movimiento.', $count);
    $secondReverse = test3cDoc('qa3c-reverse-again', 'qa3c-reverse-again', [['movimiento_id'=>$movementId,'cantidad'=>'0.500']]);
    test3cDenied(fn() => $localGlobal->revertirMovimientos('global', $secondReverse), 'Evitar doble reversión bajo una clave nueva.', $count);

    $saldoBeforeSqlFailure = (string)$db->query("SELECT saldo FROM almacen_articulos WHERE almacen_id=$gid AND articulo_id=$articleId")->fetch_row()[0];
    $db->query("CREATE TRIGGER qa3c_fail_movement BEFORE INSERT ON movimientos_inventario FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qa injected failure'");
    try {
        $failed = $localGlobal->registrarEntrada('global', test3cDoc('qa3c-sql-fail', 'qa3c-sql-fail', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'9.000']]));
        test3cAssert(($failed['ok'] ?? true) === false, 'Error SQL debe reportar fallo de operación.', $count);
    } catch (mysqli_sql_exception) {
        $count++;
    } finally { $db->query('DROP TRIGGER IF EXISTS qa3c_fail_movement'); }
    $saldoAfterSqlFailure = (string)$db->query("SELECT saldo FROM almacen_articulos WHERE almacen_id=$gid AND articulo_id=$articleId")->fetch_row()[0];
    test3cAssert($saldoBeforeSqlFailure === $saldoAfterSqlFailure, 'Fallo SQL revierte el saldo completo.', $count);

    $db->begin_transaction();
    $outer = $localGlobal->registrarEntrada('global', test3cDoc('qa3c-outer-rollback', 'qa3c-outer-rollback', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.125']]));
    test3cAssert(($outer['ok'] ?? null) === null && ($outer['confirmado'] ?? true) === false && ($outer['pendiente'] ?? false) === true, 'Dentro de transacción externa devuelve estado pendiente sin confirmar.', $count);
    $db->rollback();
    test3cAssert((int)$db->query("SELECT COUNT(*) FROM operaciones_inventario WHERE clave_idempotencia='qa3c-outer-rollback'")->fetch_row()[0] === 0, 'Rollback externo revierte operación de inventario.', $count);

    $second=test3cCall(fn()=>$localGlobal->crearArticulo('global',['nombre'=>'Segundo artículo atomicidad','unidad'=>'pza','costo'=>'0.1234']),'Segundo artículo para atomicidad.',$count);
    $secondId=(int)$second['articulo_id'];
    test3cCall(fn()=>$localGlobal->asociarArticulo('global',$gid,$secondId),'Asociación para atomicidad.',$count);
    $snapshot=$db->query("SELECT articulo_id,saldo,version FROM almacen_articulos WHERE almacen_id=$gid ORDER BY articulo_id")->fetch_all(MYSQLI_ASSOC);
    $docBatch=test3cDoc('qa3c-batch-fail','qa3c-batch-fail',[
        ['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'1.000'],
        ['almacen_id'=>$gid,'articulo_id'=>$secondId,'cantidad'=>'2.000']]);
    $db->query("CREATE TRIGGER qa3c_fail_second BEFORE INSERT ON movimientos_inventario FOR EACH ROW BEGIN IF NEW.articulo_id=$secondId THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='qa segunda linea'; END IF; END");
    try {
        test3cDenied(fn()=>$localGlobal->registrarEntrada('global',$docBatch),'Error en segunda línea revierte la primera.',$count);
        test3cAssert($snapshot===$db->query("SELECT articulo_id,saldo,version FROM almacen_articulos WHERE almacen_id=$gid ORDER BY articulo_id")->fetch_all(MYSQLI_ASSOC),'Atomicidad conserva saldos y versiones.',$count);
        test3cAssert((int)$db->query("SELECT COUNT(*) FROM operaciones_inventario WHERE clave_idempotencia='qa3c-batch-fail'")->fetch_row()[0]===0,'No queda documento parcial.',$count);
        $db->begin_transaction();
        $db->query("UPDATE ingredientes SET nombre='Cambio del coordinador' WHERE id=$secondId");
        test3cDenied(fn()=>$localGlobal->registrarEntrada('global',$docBatch),'Fallo externo devuelve error.',$count);
        test3cAssert($db->query("SELECT nombre FROM ingredientes WHERE id=$secondId")->fetch_row()[0]==='Cambio del coordinador','Savepoint conserva el trabajo previo del coordinador.',$count);
        $db->rollback();
        test3cAssert($db->query("SELECT nombre FROM ingredientes WHERE id=$secondId")->fetch_row()[0]==='Segundo artículo atomicidad','El servicio no confirmó el trabajo del coordinador.',$count);
    } finally { $db->rollback(); $db->query('DROP TRIGGER qa3c_fail_second'); }

    $grouped=test3cDoc('qa3c-grouped','qa3c-grouped',[
        ['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.100'],
        ['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.200']]);
    $groupedResult=test3cCall(fn()=>$localGlobal->registrarEntrada('global',$grouped),'Agrupa líneas repetidas.',$count);
    test3cAssert(count($groupedResult['movimientos'])===1 && $groupedResult['movimientos'][0]['delta']==='0.300','Suma exacta en un solo movimiento.',$count);
    $grouped['lineas']=[['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>'0.300']];
    $sameGrouped=test3cCall(fn()=>$localGlobal->registrarEntrada('global',$grouped),'Huella canónica de líneas agregadas.',$count);
    test3cAssert($sameGrouped['operacion_id']===$groupedResult['operacion_id'],'Agregación no duplica operación.',$count);
    test3cDenied(fn()=>$localGlobal->eliminarAlmacen('global',$gid),'No borrar almacén con historial.',$count);
    test3cDenied(fn()=>$localGlobal->configurarAlmacen('global',$gid,['unidad_id'=>3]),'No reasignar dueño mediante payload.',$count);
    test3cDenied(fn()=>test3cService($db,(int)$global['id'])->registrarEntrada('global',test3cDoc('qa3c-global-cross','qa3c-global-cross',[['almacen_id'=>$rid,'articulo_id'=>$articleId,'cantidad'=>'1.000']])),'Global no escribe existencias de Restaurante.',$count);
    $pin=new Almacenes($db,(int)$global['id'],'nip');
    test3cDenied(fn()=>$pin->crearAlmacen('global','global','PIN no permite panel'),'PIN no permite inventario administrativo.',$count);
    foreach(['1.0001','1e3','NaN','1000000000.000'] as $bad) {
        $badResult=$localGlobal->registrarEntrada('global',test3cDoc('bad-'.$bad,'bad-'.$bad,[['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>$bad]]));
        test3cAssert($badResult['ok']===false && in_array($badResult['codigo'],['DECIMAL_INVALIDO','DECIMAL_FUERA_RANGO'],true),'Rechaza precisión, notación o rango inválidos.',$count);
    }
    $nullAdjustment=test3cCall(fn()=>$localGlobal->registrarAjuste('global',test3cDoc('qa3c-noop','qa3c-noop',[['almacen_id'=>$gid,'articulo_id'=>$secondId,'cantidad'=>'0','version'=>0]],'conteo')),'Ajuste cero trazable sin movimiento.',$count);
    test3cAssert($nullAdjustment['movimientos']===[],'No se registran deltas cero.',$count);
    foreach(["UPDATE movimientos_inventario SET motivo='alterado' WHERE id=$movementId","DELETE FROM movimientos_inventario WHERE id=$movementId"] as $sql) {
        $denegado=false;
        try { $db->query($sql); } catch(mysqli_sql_exception $e) { $denegado=$e->getCode()===1644; }
        test3cAssert($denegado,'Bitácora inmutable también mediante SQL directo.',$count);
    }

    $float = $localGlobal->registrarEntrada('global', test3cDoc('qa3c-float', 'qa3c-float', [['almacen_id'=>$gid,'articulo_id'=>$articleId,'cantidad'=>0.2]]));
    test3cAssert($float['ok'] === false && $float['codigo'] === 'DECIMAL_INVALIDO', 'Las cantidades float se rechazan explícitamente.', $count);
    $sum = (string)$db->query("SELECT COALESCE(SUM(delta),0) FROM movimientos_inventario WHERE almacen_id=$gid AND articulo_id=$articleId")->fetch_row()[0];
    $balance = (string)$db->query("SELECT saldo FROM almacen_articulos WHERE almacen_id=$gid AND articulo_id=$articleId")->fetch_row()[0];
    test3cAssert($sum === $balance, 'El saldo coincide con la suma de deltas de movimientos.', $count);
    test3cAssert((int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='ingredientes' AND column_name='stock'")->fetch_row()[0] === 0, 'El catálogo maestro no contiene stock editable.', $count);

    return compact('gid','rid','pid','articleId','entryR','inactive','global','rest','pan');
}

$admin = null; $bases = []; $options = []; $config = []; $phase = 'argumentos/configuración'; $error = null; $total = 0; $output = null;
try {
    $options = test3cOptions(array_slice($argv, 1));
    Dotenv\Dotenv::createImmutable($root . '/includes')->safeLoad();
    $config = $_ENV;
    Comun::origen($config, $options, $root);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $phase = 'conexión administrativa sin esquema';
    $admin = new mysqli((string)$config['DB_HOST'], (string)$config['DB_USER'], (string)$config['DB_PASS'], '', (int)($config['DB_PORT'] ?? 3306));
    $admin->set_charset('utf8mb4');
    if ($admin->thread_id === 0) throw new RuntimeException('No se pudo abrir conexión administrativa sin esquema.');
    $phase = 'DDL/deploy sobre base temporal';
    [$base, $token] = test3cCreateDatabase($admin, $root, $bases);
    $phase = 'motor de almacenes 3C.1';
    $fixtures = test3cCore($admin, $total);
    if(empty($options['solo_motor'])) {
        $phase='consultas y autorización 3C.2';
        require __DIR__.'/pruebas-consultas-3c.php';
        test3cConsultas($admin,$fixtures,$total);
    }
    $output = ['ok'=>true, 'comprobaciones'=>$total, 'fase'=>empty($options['solo_motor'])?'3C.1 y 3C.2':'3C.1', 'base_temporal'=>$base,
        'almacenes'=>['global'=>$fixtures['gid'],'restaurante'=>$fixtures['rid'],'panaderia'=>$fixtures['pid']], 'articulo_id'=>$fixtures['articleId']];
} catch (Throwable $e) {
    $error = $e instanceof mysqli_sql_exception ? 'Error MySQL; código ' . $e->getCode() . '.' : $e->getMessage();
} finally {
    $cleanupFailed = false;
    if ($admin instanceof mysqli) {
        foreach (array_reverse($bases) as [$name, $token]) {
            try {
                if (test3cCanDrop($admin, $name, $token, (string)($config['DB_NAME'] ?? ''))) {
                    $admin->query('DROP DATABASE ' . test3cIdent($name));
                    fwrite(STDOUT, "Base temporal verificada y eliminada: $name\n");
                } else { $cleanupFailed = true; }
            } catch (Throwable) { $cleanupFailed = true; }
        }
        $admin->close();
    }
    if ($cleanupFailed && $error === null) { $phase = 'limpieza'; $error = 'No se pudo verificar o limpiar una base temporal.'; }
}
if ($error === null) echo Comun::json($output ?? ['ok'=>true,'comprobaciones'=>$total]);
else fwrite(STDERR, Comun::json(['ok'=>false,'fase'=>$phase,'comprobaciones'=>$total,'error'=>$error]));
exit($error === null ? 0 : 1);
