<?php

declare(strict_types=1);

$arguments = [];
foreach ($argv ?? [] as $argumento) {
    if (str_starts_with((string)$argumento, '--') && str_contains((string)$argumento, '=')) {
        [$key, $value] = explode('=', (string)$argumento, 2);
        $arguments[substr($key, 2)] = trim($value, "'\"");
    }
}
$database = (string)($arguments['db'] ?? '');
$operation = (string)($arguments['op'] ?? '');
$barrier = (string)($arguments['barrier'] ?? '');
$reservationId = (int)($arguments['id'] ?? 0);
$ticketId = (int)($arguments['ticket-id'] ?? 0);
if ($database === '' || $operation === '' || $barrier === '' || $reservationId < 1 && in_array($operation, ['start', 'cancel', 'no-show', 'reassign'], true)) {
    fwrite(STDERR, "Argumentos incompletos para worker Etapa 10.\n");
    exit(2);
}

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('APP_ENV=testing');
putenv('DB_NAME=' . $database);
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\PuntoVentaReservacionService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionMapaAdministrativaService;
use Model\ReservacionMesa;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No hay conexión MySQL para worker Etapa 10.\n");
    exit(2);
}
ActiveRecord::setDB($db);
$deadline = microtime(true) + 20.0;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($barrier)) {
    echo json_encode(['ok' => false, 'codigo' => 'BARRERA_EXPIRADA']) . PHP_EOL;
    exit(1);
}

try {
    $resultado = match ($operation) {
        'start' => PuntoVentaReservacionService::comenzar($reservationId, 1, null),
        'cancel' => ReservacionAdministrativaService::cancelar($reservationId, 1, 'Carrera Etapa 10'),
        'no-show' => PuntoVentaReservacionService::noShow($reservationId, 1, false, false, 'Carrera Etapa 10'),
        'close' => PuntoVentaReservacionService::cerrarTicket($ticketId, 'efectivo', 0.0, [], 1),
        'reassign' => (static function () use ($db, $reservationId): array {
            $row = $db->query("SELECT id, fecha, hora, updated_at, created_at FROM reservaciones WHERE id = {$reservationId} LIMIT 1")->fetch_assoc() ?: [];
            $ids = ReservacionMesa::obtenerIdsPorReservacion($reservationId);
            $newId = 0;
            $tables = $db->query("SELECT m.id FROM mesas m
                WHERE m.activo = 1 AND m.reservable = 1 AND m.tipo = 'mesa'
                  AND m.id NOT IN (" . ($ids === [] ? '0' : implode(',', array_map('intval', $ids))) . ")
                ORDER BY m.numero, m.id LIMIT 1");
            if ($tables) {
                $newId = (int)(($tables->fetch_assoc() ?: [])['id'] ?? 0);
                $tables->free();
            }
            $version = hash('sha256', (string)($row['updated_at'] ?: $row['created_at']) . '|' . implode(',', $ids));
            return ReservacionMapaAdministrativaService::guardarAsignacion($reservationId, [$newId], [
                'fecha_esperada' => (string)($row['fecha'] ?? ''),
                'hora_esperada' => (string)($row['hora'] ?? ''),
                'mesa_ids_actuales' => $ids,
                'version_esperada' => $version,
                'validar_contexto' => true,
                'contexto_completo' => true,
            ]);
        })(),
        default => ['ok' => false, 'codigo' => 'OPERACION_DESCONOCIDA'],
    };
    echo json_encode(['ok_proceso' => true, 'op' => $operation, 'resultado' => $resultado], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(($resultado['ok'] ?? false) || in_array((string)($resultado['codigo'] ?? ''), [
        PuntoVentaReservacionService::ESTADO_INVALIDO,
        PuntoVentaReservacionService::TICKET_ABIERTO,
        PuntoVentaReservacionService::TOLERANCIA_VIGENTE,
    ], true) ? 0 : 0);
} catch (Throwable $error) {
    error_log('etapa10_concurrencia_worker: ' . $error->getMessage());
    echo json_encode(['ok_proceso' => false, 'op' => $operation, 'codigo' => 'ERROR_INTERNO']) . PHP_EOL;
    exit(1);
}
