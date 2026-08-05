<?php

declare(strict_types=1);

/** Casos focalizados de las divergencias F-01/F-02/F-03/F-05/F-04 y D-01. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
ini_set('session.save_path', dirname(__DIR__) . DIRECTORY_SEPARATOR . '.sessions');

$args = [];
foreach (array_slice($argv, 1) as $argumento) {
    if (str_starts_with($argumento, '--') && str_contains($argumento, '=')) {
        [$clave, $valor] = explode('=', $argumento, 2);
        $args[substr($clave, 2)] = $valor;
    }
}
$database = trim((string)($args['db'] ?? ''));
if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1) {
    fwrite(STDERR, "Uso: php etapa11_9_correcciones.php --db=BASE\n");
    exit(2);
}
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);

require dirname(__DIR__, 2) . '/includes/app.php';

use Classes\Auth;
use MVC\Router;
use Model\ActiveRecord;
use Model\Reservacion;
use Model\ReservacionMesa;
use Services\PuntoVentaReservacionService;
use Services\ReservacionPublicaService;
use Services\ReservationClientSession;
use Services\StaffCsrfService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No hay conexión con la base de pruebas.\n");
    exit(2);
}
ActiveRecord::setDB($db);

$passed = 0;
$failed = [];
$cases = [];
$fixtureIds = [];
$fixturePrefix = 'ETAPA119_' . strtoupper(bin2hex(random_bytes(4)));
$escape = static fn(string $value): string => $db->real_escape_string($value);
$query = static function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException($db->error . ' - ' . $sql);
    }
    return $result;
};
$assert = static function (string $name, bool $ok, array $detail = []) use (&$passed, &$failed, &$cases): void {
    $cases[$name] = ['ok' => $ok, 'detail' => $detail];
    if ($ok) {
        $passed++;
        return;
    }
    $failed[] = $name . ': ' . json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
};
$insert = static function (
    string $suffix,
    string $fecha,
    string $hora,
    string $estado = 'confirmada',
    string $contactoTipo = 'ninguno',
    ?string $contacto = null,
    ?string $hold = null,
    ?int $reemplaza = null,
    string $origen = 'admin'
) use ($db, $escape, $fixturePrefix, &$fixtureIds): int {
    $nombre = $fixturePrefix . '_' . $suffix;
    $token = $fixturePrefix . '_TOKEN_' . $suffix . '_' . bin2hex(random_bytes(3));
    $contactoSql = $contacto === null ? 'NULL' : "'" . $escape($contacto) . "'";
    $holdSql = $hold === null ? 'NULL' : "'" . $escape($hold) . "'";
    $reemplazaSql = $reemplaza === null ? 'NULL' : (string)$reemplaza;
    $sql = "INSERT INTO reservaciones
        (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota,
         origen, estado, hold_expires_at, reemplaza_reservacion_id,
         request_token, estado_changed_at)
        VALUES ('" . $escape($nombre) . "', '" . $escape($contactoTipo) . "', {$contactoSql},
         '" . $escape($fecha) . "', '" . $escape($hora) . "', 2,
         'Fixture Etapa 11.9', '" . $escape($origen) . "', '" . $escape($estado) . "',
         {$holdSql}, {$reemplazaSql}, '" . $escape($token) . "', '2026-11-01 12:00:00')";
    if (!$db->query($sql)) {
        throw new RuntimeException($db->error);
    }
    $id = (int)$db->insert_id;
    $fixtureIds[] = $id;
    return $id;
};
$assign = static function (int $reservacionId, array $mesaIds) use ($db): void {
    $db->query('DELETE FROM reservacion_mesas WHERE reservacion_id = ' . $reservacionId);
    foreach (array_values(array_map('intval', $mesaIds)) as $order => $mesaId) {
        $db->query('INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES ('
            . $reservacionId . ', ' . $mesaId . ', ' . ($order + 1) . ')');
    }
};
$row = static function (int $id) use ($query): ?array {
    $result = $query('SELECT * FROM reservaciones WHERE id = ' . $id . ' LIMIT 1');
    $value = $result->fetch_assoc() ?: null;
    $result->free();
    return $value;
};
$tableResult = $query("SELECT id FROM mesas WHERE activo = 1 AND reservable = 1 AND tipo = 'mesa' ORDER BY numero LIMIT 6");
$tables = [];
while ($table = $tableResult->fetch_assoc()) {
    $tables[] = (int)$table['id'];
}
$tableResult->free();
if (count($tables) < 4) {
    fwrite(STDERR, "La instalación de prueba necesita cuatro mesas reservables.\n");
    exit(2);
}
[$t1, $t2, $t3, $t4] = array_slice($tables, 0, 4);

try {
    // F-01: conflicto entre la mesa provisional y una reservación posterior.
    $original = $insert('F01_ORIGINAL', '2026-11-05', '14:00', 'confirmada', 'email', 'f01@example.test', null, null, 'landing');
    $replacement = $insert('F01_REPLACEMENT', '2026-11-06', '14:00', 'pendiente_verificacion', 'email', 'f01@example.test', '2026-11-01 12:15:00', $original, 'landing');
    $conflict = $insert('F01_CONFLICT', '2026-11-06', '14:00');
    $assign($replacement, [$t1]);
    $assign($conflict, [$t1]);
    $f01 = ReservacionPublicaService::confirmarReemplazo(
        ['request_token' => $row($replacement)['request_token']],
        ['contacto_tipo' => 'email', 'contacto' => 'f01@example.test']
    );
    $assert('F-01 revalidacion final conserva original ante conflicto', ($f01['ok'] ?? true) === false
        && ($f01['codigo'] ?? '') === ReservacionPublicaService::SIN_DISPONIBILIDAD
        && ($row($original)['estado'] ?? '') === 'confirmada'
        && ($row($replacement)['estado'] ?? '') === 'pendiente_verificacion'
        && ($row($conflict)['estado'] ?? '') === 'confirmada', ['resultado' => $f01]);

    // F-02: un hold vigente bloquea walk-in y apertura de ticket.
    $holdWalkin = $insert('F02_HOLD_WALKIN', '2026-11-01', '12:20', 'pendiente_verificacion', 'email', 'f02-walkin@example.test', '2026-11-01 12:15:00', null, 'landing');
    $assign($holdWalkin, [$t2]);
    $ticketsBefore = (int)$query("SELECT COUNT(*) AS total FROM tickets WHERE nombre LIKE '" . $escape($fixturePrefix) . "%'")->fetch_assoc()['total'];
    $walkin = PuntoVentaReservacionService::abrirWalkIn(['mesa_ids' => [$t2], 'comensales' => 2], 1);
    $ticketsAfterWalkin = (int)$query("SELECT COUNT(*) AS total FROM tickets WHERE nombre LIKE '" . $escape($fixturePrefix) . "%'")->fetch_assoc()['total'];
    $assert('F-02 hold vigente bloquea walk-in sin ticket', ($walkin['ok'] ?? false) === false
        && ($walkin['codigo'] ?? '') === PuntoVentaReservacionService::MESA_OCUPADA
        && $ticketsBefore === $ticketsAfterWalkin, ['resultado' => $walkin]);

    $holdTicket = $insert('F02_HOLD_TICKET', '2026-11-01', '12:20', 'pendiente_verificacion', 'email', 'f02-ticket@example.test', '2026-11-01 12:15:00', null, 'landing');
    $confirmedTicket = $insert('F02_CONFIRMED_TICKET', '2026-11-01', '12:00');
    $assign($holdTicket, [$t3]);
    $assign($confirmedTicket, [$t3]);
    $ticketOpening = PuntoVentaReservacionService::comenzar($confirmedTicket, 1, null);
    $assert('F-02 hold vigente bloquea apertura de ticket de reservacion', ($ticketOpening['ok'] ?? false) === false
        && ($ticketOpening['codigo'] ?? '') === PuntoVentaReservacionService::MESA_OCUPADA, ['resultado' => $ticketOpening]);

    // D-01: la visibilidad y el límite usan fecha del restaurante, no hora.
    $contact = 'd01@example.test';
    $yesterday = $insert('D01_YESTERDAY', '2026-10-31', '23:59', 'confirmada', 'email', $contact, null, null, 'landing');
    $todayPast = $insert('D01_TODAY_PAST', '2026-11-01', '10:00', 'confirmada', 'email', $contact, null, null, 'landing');
    $todayFuture = $insert('D01_TODAY_FUTURE', '2026-11-01', '23:00', 'confirmada', 'email', $contact, null, null, 'landing');
    $tomorrow = $insert('D01_TOMORROW', '2026-11-02', '14:00', 'confirmada', 'email', $contact, null, null, 'landing');
    $holdLimit = $insert('D01_HOLD', '2026-11-01', '18:00', 'pendiente_verificacion', 'email', $contact, '2026-11-01 12:15:00', null, 'landing');
    $replacementLimit = $insert('D01_REPLACEMENT', '2026-11-01', '19:00', 'pendiente_verificacion', 'email', $contact, '2026-11-01 12:15:00', $todayFuture, 'landing');
    $visible = Reservacion::buscarActivasPorContacto('email', $contact, '2026-11-01', '12:00', 5);
    $visibleIds = array_map(static fn(array $item): int => (int)$item['id'], $visible);
    $count = Reservacion::contarActivasPorContacto('email', $contact, '2026-11-01', '12:00');
    $assert('D-01 fecha actual canonica incluye hoy pasado y excluye ayer', in_array($todayPast, $visibleIds, true)
        && !in_array($yesterday, $visibleIds, true)
        && in_array($todayFuture, $visibleIds, true)
        && in_array($tomorrow, $visibleIds, true)
        && $count === 4, ['ids' => $visibleIds, 'count' => $count, 'replacement' => $replacementLimit, 'hold' => $holdLimit]);

    // F-03: crear verificada sin CSRF no muta aunque exista sesion publica.
    ReservationClientSession::crear('email', 'f03@example.test');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = '';
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $_POST = [
        'nombre' => $fixturePrefix . '_F03_PUBLIC',
        'tipo_contacto' => 'email',
        'contacto' => 'f03@example.test',
        'fecha' => '2026-11-08',
        'hora' => '14:00',
        'personas' => '2',
        'request_token' => $fixturePrefix . '_F03_TOKEN',
    ];
    $beforeF03 = (int)$query("SELECT COUNT(*) AS total FROM reservaciones WHERE request_token = '" . $escape($fixturePrefix . '_F03_TOKEN') . "'")->fetch_assoc()['total'];
    ob_start();
    Controllers\ReservacionController::crearVerificada(new Router());
    $f03Body = (string)ob_get_clean();
    $f03 = json_decode($f03Body, true) ?: [];
    $afterF03 = (int)$query("SELECT COUNT(*) AS total FROM reservaciones WHERE request_token = '" . $escape($fixturePrefix . '_F03_TOKEN') . "'")->fetch_assoc()['total'];
    $assert('F-03 crear verificada rechaza CSRF ausente sin mutar', ($f03['codigo'] ?? '') === 'CSRF_INVALIDO'
        && $beforeF03 === 0 && $afterF03 === 0, ['respuesta' => $f03]);

    // F-05: una escritura POS sin token común no llega al servicio.
    Auth::start();
    $_SESSION['id'] = 0;
    $_SESSION['rol'] = 'waiter';
    StaffCsrfService::token();
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);
    $_POST = ['mesa_ids' => [$t4], 'comensales' => 2];
    $beforeF05 = (int)$query("SELECT COUNT(*) AS total FROM tickets WHERE nombre LIKE '" . $escape($fixturePrefix) . "%'")->fetch_assoc()['total'];
    ob_start();
    Controllers\PuntoVentaController::abrirTicket(new Router());
    $f05Body = (string)ob_get_clean();
    $f05 = json_decode($f05Body, true) ?: [];
    $afterF05 = (int)$query("SELECT COUNT(*) AS total FROM tickets WHERE nombre LIKE '" . $escape($fixturePrefix) . "%'")->fetch_assoc()['total'];
    $assert('F-05 mutacion POS rechaza CSRF ausente sin abrir ticket', ($f05['codigo'] ?? '') === 'CSRF_INVALIDO'
        && $beforeF05 === $afterF05, ['respuesta' => $f05]);

    // F-04: las rutas web destructivas desaparecieron y el comando exige CLI/base segura.
    $routes = (string)file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    $view = (string)file_get_contents(dirname(__DIR__, 2) . '/views/admin/reservations/development-tools.php');
    $cli = (string)file_get_contents(dirname(__DIR__, 2) . '/scripts/limpiar-fixtures-reservaciones.php');
    $assert('F-04 limpieza destructiva solo queda en CLI aislado', !str_contains($routes, 'cleanup-preview')
        && !str_contains($routes, "development-tools/cleanup'")
        && !str_contains($view, 'Eliminar definitivamente')
        && str_contains($cli, "PHP_SAPI !== 'cli'")
        && str_contains($cli, '--active-db')
        && str_contains($cli, 'LIMPIAR RESERVACIONES'), []);
} catch (Throwable $error) {
    $failed[] = 'Excepcion de casos focalizados: ' . $error->getMessage();
} finally {
    try {
        $ids = array_values(array_unique(array_map('intval', $fixtureIds)));
        if ($ids !== []) {
            $list = implode(',', $ids);
            $query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$list})");
            $query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$list})");
            $query("DELETE FROM reservaciones WHERE reemplaza_reservacion_id IN ({$list})");
            $query("DELETE FROM reservaciones WHERE id IN ({$list})");
        }
    } catch (Throwable $cleanupError) {
        $failed[] = 'Limpieza de fixtures focalizados: ' . $cleanupError->getMessage();
    }
    $_POST = [];
}

echo json_encode([
    'ok' => $failed === [],
    'suite' => 'etapa11_9_correcciones',
    'fixture_prefix' => $fixturePrefix,
    'passed' => $passed,
    'failed' => $failed,
    'cases' => $cases,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed === [] ? 0 : 1);
