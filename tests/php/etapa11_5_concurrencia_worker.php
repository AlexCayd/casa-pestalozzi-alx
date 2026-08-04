<?php

declare(strict_types=1);

/** Worker independiente para la matriz exacta A-L de Etapa 11.5. */

$args = [];
foreach ($argv ?? [] as $argument) {
    if (str_starts_with((string)$argument, '--') && str_contains((string)$argument, '=')) {
        [$key, $value] = explode('=', (string)$argument, 2);
        $args[substr($key, 2)] = trim($value, "'\"");
    }
}

$database = (string)($args['db'] ?? '');
$barrier = (string)($args['barrier'] ?? '');
$kind = (string)($args['kind'] ?? '');
$reservationId = (int)($args['reservation-id'] ?? 0);
$ticketId = (int)($args['ticket-id'] ?? 0);
$scenario = (string)($args['scenario'] ?? '');
$now = (string)($args['now'] ?? '2026-11-01 12:00:00');

if ($database === '' || preg_match('/^[A-Za-z0-9_]+$/', $database) !== 1
    || $barrier === '' || $kind === '') {
    fwrite(STDERR, "Uso: php etapa11_5_concurrencia_worker.php --db=BASE --kind=TIPO --barrier=RUTA\n");
    exit(2);
}

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('APP_ENV=testing');
putenv('DB_NAME=' . $database);
putenv('RESERVATION_TEST_NOW=' . $now);
$_ENV['RESERVATION_TEST_NOW'] = $now;
$_SERVER['RESERVATION_TEST_NOW'] = $now;
ini_set('session.save_path', dirname(__DIR__));

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\AsignacionMesasService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionMapaAdministrativaService;
use Services\ReservacionPublicaService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli || !$db->select_db($database)) {
    fwrite(STDERR, "No hay conexion MySQL para worker Etapa 11.5.\n");
    exit(2);
}
ActiveRecord::setDB($db);

$jsonArray = static function (string $value): array {
    $decoded = json_decode($value, true);
    return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
};

$context = static function (int $id) use ($db): array {
    $result = $db->query(
        'SELECT fecha, hora, created_at, updated_at FROM reservaciones WHERE id = '
        . $id . ' LIMIT 1'
    );
    $row = $result ? ($result->fetch_assoc() ?: null) : null;
    if ($result) $result->free();
    if (!$row) throw new RuntimeException('La reservacion no existe antes de la barrera.');
    $mesaIds = ReservacionMesa::obtenerIdsPorReservacion($id);
    sort($mesaIds, SORT_NUMERIC);
    return [
        'version_esperada' => hash(
            'sha256',
            (string)($row['updated_at'] ?: $row['created_at']) . '|' . implode(',', $mesaIds)
        ),
        'fecha_esperada' => (string)$row['fecha'],
        'hora_esperada' => (string)$row['hora'],
        'mesa_ids_actuales' => $mesaIds,
    ];
};

$snapshot = in_array($kind, ['admin_assign', 'admin_reassign'], true)
    ? $context($reservationId)
    : null;

$deadline = microtime(true) + 20;
while (!is_file($barrier) && microtime(true) < $deadline) usleep(10000);
if (!is_file($barrier)) {
    echo json_encode([
        'ok_proceso' => false,
        'scenario' => $scenario,
        'kind' => $kind,
        'error' => 'BARRERA_EXPIRADA',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

try {
    $resultado = match ($kind) {
        'public_confirm' => ReservacionPublicaService::confirmarRetencion([
            'request_token' => (string)($args['token'] ?? ''),
            'tipo' => 'email',
            'contacto' => (string)($args['contact'] ?? ''),
            'codigo' => (string)($args['otp'] ?? ''),
        ]),
        'public_hold' => ReservacionPublicaService::crearRetencion([
            'nombre' => (string)($args['name'] ?? 'Etapa 11.5 publica'),
            'tipo_contacto' => 'email',
            'contacto' => (string)($args['contact'] ?? ''),
            'fecha' => (string)($args['date'] ?? ''),
            'hora' => (string)($args['hour'] ?? ''),
            'personas' => 2,
            'notas' => 'Carrera controlada Etapa 11.5',
            'request_token' => (string)($args['token'] ?? ''),
        ]),
        'public_confirm_replacement' => ReservacionPublicaService::confirmarReemplazo([
            'request_token' => (string)($args['token'] ?? ''),
            'codigo' => (string)($args['otp'] ?? ''),
        ], [
            'contacto_tipo' => 'email',
            'contacto' => (string)($args['contact'] ?? ''),
        ]),
        'admin_assign', 'admin_reassign' => (static function () use (
            $reservationId,
            $snapshot,
            $jsonArray,
            $args
        ): array {
            $targetIds = $jsonArray((string)($args['target-ids'] ?? '[]'));
            return ReservacionMapaAdministrativaService::guardarAsignacion(
                $reservationId,
                $targetIds,
                [
                    'validar_contexto' => true,
                    'contexto_completo' => true,
                    'version_esperada' => $snapshot['version_esperada'],
                    'fecha_esperada' => $snapshot['fecha_esperada'],
                    'hora_esperada' => $snapshot['hora_esperada'],
                    'mesa_ids_actuales' => $snapshot['mesa_ids_actuales'],
                    'permitir_superposicion_ticket_abierto' => true,
                ]
            );
        })(),
        'admin_cancel', 'cancel' => ReservacionAdministrativaService::cancelar(
            $reservationId,
            1,
            'Carrera controlada Etapa 11.5'
        ),
        'no_show' => PuntoVentaReservacionService::noShow(
            $reservationId,
            1,
            false,
            false,
            'Carrera controlada Etapa 11.5'
        ),
        'start', 'start_again' => PuntoVentaReservacionService::comenzar($reservationId, 1, null),
        'close' => PuntoVentaReservacionService::cerrarTicket($ticketId, 'efectivo', 0.0, [], 1),
        'expire' => ReservacionPublicaService::expirarRetenciones(100, false),
        default => ['ok' => false, 'codigo' => 'OPERACION_DESCONOCIDA'],
    };

    if (is_array($resultado)) unset($resultado['preview_code']);
    echo json_encode([
        'ok_proceso' => true,
        'scenario' => $scenario,
        'kind' => $kind,
        'resultado' => $resultado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    error_log('etapa11_5_concurrencia_worker: ' . $error->getMessage());
    echo json_encode([
        'ok_proceso' => false,
        'scenario' => $scenario,
        'kind' => $kind,
        'error' => 'ERROR_INTERNO',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
