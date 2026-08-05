<?php

declare(strict_types=1);

/**
 * Limpieza destructiva de fixtures, exclusivamente para bases de pruebas.
 *
 * Ejemplo:
 * php scripts/limpiar-fixtures-reservaciones.php --active-db=casa_pestalozzi --db=casa_pestalozzi_testing_11_9 --fecha-desde=2026-11-01 --fecha-hasta=2026-11-30 --prefijo=ETAPA11_9_ --confirm="LIMPIAR RESERVACIONES"
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Este comando solo puede ejecutarse desde CLI.\n");
}

$args = [];
foreach (array_slice($argv, 1) as $argumento) {
    if (str_starts_with($argumento, '--') && str_contains($argumento, '=')) {
        [$clave, $valor] = explode('=', $argumento, 2);
        $args[substr($clave, 2)] = $valor;
    }
}

$activeDatabase = trim((string)($args['active-db'] ?? ''));
$database = trim((string)($args['db'] ?? ''));
$prefijo = trim((string)($args['prefijo'] ?? ''));
$confirmacion = trim((string)($args['confirm'] ?? ''));
$fechaDesde = trim((string)($args['fecha-desde'] ?? ''));
$fechaHasta = trim((string)($args['fecha-hasta'] ?? ''));

if ($activeDatabase === '' || $database === '' || $database === $activeDatabase) {
    fwrite(STDERR, "Se requiere --active-db y una base de pruebas distinta de la base activa.\n");
    exit(2);
}
if (preg_match('/^casa_pestalozzi_(?:test|testing|etapa[0-9]+)[A-Za-z0-9_-]*$/', $database) !== 1) {
    fwrite(STDERR, "La base destino debe tener un nombre inequívoco de pruebas.\n");
    exit(2);
}
if ($prefijo === '' || preg_match('/^[A-Za-z0-9_-]{1,60}$/', $prefijo) !== 1) {
    fwrite(STDERR, "Se requiere un prefijo de fixture seguro.\n");
    exit(2);
}
if ($confirmacion !== 'LIMPIAR RESERVACIONES') {
    fwrite(STDERR, "La confirmación no coincide.\n");
    exit(2);
}
foreach ([$fechaDesde, $fechaHasta] as $fecha) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) !== 1) {
        fwrite(STDERR, "El rango de fechas es obligatorio y debe usar YYYY-MM-DD.\n");
        exit(2);
    }
}
if ($fechaDesde > $fechaHasta) {
    fwrite(STDERR, "El rango de fechas no es válido.\n");
    exit(2);
}

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);

require dirname(__DIR__) . '/includes/app.php';

use Model\ActiveRecord;
use Services\ReservacionMantenimientoService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No fue posible abrir la base de pruebas.\n");
    exit(2);
}
ActiveRecord::setDB($db);

$resultado = ReservacionMantenimientoService::limpiar([
    'fecha_desde' => $fechaDesde,
    'fecha_hasta' => $fechaHasta,
    'prefijo' => $prefijo,
    'estados' => ['no_show', 'expirada', 'pendiente_verificacion'],
    'confirmacion' => $confirmacion,
]);

echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($resultado['ok'] ?? false) ? 0 : 1);
