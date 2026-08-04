<?php

declare(strict_types=1);

/** Worker independiente para carreras de asignacion manual administrativa. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

$database = '';
$reservacionId = 0;
$mesaId = 0;
$barrier = '';
foreach ($argv as $argumento) {
    if (str_starts_with($argumento, '--db=')) $database = substr($argumento, 5);
    if (str_starts_with($argumento, '--reservation-id=')) $reservacionId = (int)substr($argumento, 17);
    if (str_starts_with($argumento, '--table-id=')) $mesaId = (int)substr($argumento, 11);
    if (str_starts_with($argumento, '--barrier=')) $barrier = substr($argumento, 10);
}
if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1 || $reservacionId < 1 || $mesaId < 1) {
    fwrite(STDERR, "Uso: php etapa9_concurrencia_worker.php --db=BASE --reservation-id=ID --table-id=ID --barrier=RUTA\n");
    exit(2);
}

$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\AsignacionMesasService;
use Services\ReservacionMapaAdministrativaService;

$db = ActiveRecord::getDB();
if (!$db->select_db($database)) {
    fwrite(STDERR, $db->error . "\n");
    exit(2);
}
ActiveRecord::setDB($db);

$deadline = microtime(true) + 15;
while ($barrier !== '' && !is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if ($barrier !== '' && !is_file($barrier)) {
    fwrite(STDERR, "La barrera no se abrio.\n");
    exit(1);
}

$filaResultado = $db->query("SELECT fecha, hora, created_at, updated_at FROM reservaciones WHERE id = {$reservacionId} LIMIT 1");
$fila = $filaResultado ? ($filaResultado->fetch_assoc() ?: null) : null;
if ($filaResultado) $filaResultado->free();
if (!$fila) {
    fwrite(STDERR, "La reservacion no existe.\n");
    exit(1);
}
$mesaIdsActuales = ReservacionMesa::obtenerIdsPorReservacion($reservacionId);
sort($mesaIdsActuales, SORT_NUMERIC);
$version = hash(
    'sha256',
    (string)($fila['updated_at'] ?: $fila['created_at']) . '|' . implode(',', $mesaIdsActuales)
);
$resultado = ReservacionMapaAdministrativaService::guardarAsignacion(
    $reservacionId,
    [$mesaId],
    [
        'validar_contexto' => true,
        'contexto_completo' => true,
        'version_esperada' => $version,
        'fecha_esperada' => (string)$fila['fecha'],
        'hora_esperada' => (string)$fila['hora'],
        'mesa_ids_actuales' => $mesaIdsActuales,
        'permitir_superposicion_ticket_abierto' => true,
    ]
);

echo json_encode($resultado, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($resultado['ok'] ?? false) ? 0 : 1);
