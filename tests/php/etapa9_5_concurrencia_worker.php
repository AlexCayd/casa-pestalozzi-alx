<?php

declare(strict_types=1);

/** Worker independiente para las carreras transversales de Etapa 9.5. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');

$args = [];
foreach ($argv as $argumento) {
    if (str_starts_with((string)$argumento, '--') && str_contains((string)$argumento, '=')) {
        [$clave, $valor] = explode('=', (string)$argumento, 2);
        $args[substr($clave, 2)] = $valor;
    }
}

$database = (string)($args['db'] ?? '');
$barrier = (string)($args['barrier'] ?? '');
$reservationId = (int)($args['reservation-id'] ?? 0);
$kind = (string)($args['kind'] ?? '');
$scenario = (string)($args['scenario'] ?? '');
$now = (string)($args['now'] ?? '2026-11-01 12:00:00');
$ready = (string)($args['ready'] ?? '');

if ($database === '' || preg_match('/^[A-Za-z0-9_-]+$/', $database) !== 1 || $barrier === '' || $kind === '') {
    fwrite(STDERR, "Uso: php etapa9_5_concurrencia_worker.php --db=BASE --kind=TIPO --barrier=RUTA\n");
    exit(2);
}

$_ENV['DB_NAME'] = $database;
$_SERVER['DB_NAME'] = $database;
putenv('DB_NAME=' . $database);
$_ENV['RESERVATION_TEST_NOW'] = $now;
$_SERVER['RESERVATION_TEST_NOW'] = $now;
putenv('RESERVATION_TEST_NOW=' . $now);
ini_set('session.save_path', dirname(__DIR__));

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\AsignacionMesasService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionMapaAdministrativaService;
use Services\ReservacionPublicaService;
use Services\PuntoVentaReservacionService;

$db = ActiveRecord::getDB();
if (!$db->select_db($database)) {
    fwrite(STDERR, $db->error . "\n");
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
    if ($result) {
        $result->free();
    }
    if (!$row) {
        throw new RuntimeException('La reservacion no existe antes de la barrera.');
    }
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

$snapshotBeforeBarrier = in_array($kind, ['map_assign', 'release'], true)
    ? $context($reservationId)
    : null;
if ($ready !== '' && !touch($ready)) {
    throw new RuntimeException('No se pudo registrar que el worker está listo.');
}

$deadline = microtime(true) + 20;
while (!is_file($barrier) && microtime(true) < $deadline) {
    usleep(10000);
}
if (!is_file($barrier)) {
    echo json_encode([
        'ok_proceso' => false,
        'scenario' => $scenario,
        'kind' => $kind,
        'error' => 'La barrera no se abrio.',
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}

try {
    $resultado = match ($kind) {
        'map_assign', 'release' => (static function () use (
            $kind,
            $reservationId,
            $context,
            $jsonArray,
            $args,
            $snapshotBeforeBarrier
        ): array {
            $snapshot = $snapshotBeforeBarrier;
            $mesaIds = $jsonArray((string)($args['target-ids'] ?? '[]'));
            $opciones = [
                'validar_contexto' => true,
                'contexto_completo' => true,
                'version_esperada' => $snapshot['version_esperada'],
                'fecha_esperada' => $snapshot['fecha_esperada'],
                'hora_esperada' => $snapshot['hora_esperada'],
                'mesa_ids_actuales' => $snapshot['mesa_ids_actuales'],
                'permitir_superposicion_ticket_abierto' => true,
            ];
            if ($kind === 'release') {
                $opciones['confirmaciones'] = [AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL];
                return ReservacionMapaAdministrativaService::liberarAsignacion($reservationId, $opciones);
            }
            return ReservacionMapaAdministrativaService::guardarAsignacion(
                $reservationId,
                $mesaIds,
                $opciones
            );
        })(),
        'pos_start' => PuntoVentaReservacionService::comenzar($reservationId, 1, null),
        'cancel' => ReservacionAdministrativaService::cancelar(
            $reservationId,
            1,
            'Carrera controlada Etapa 9.5'
        ),
        'public_hold' => ReservacionPublicaService::crearRetencion([
            'nombre' => (string)($args['name'] ?? 'Etapa 9.5 publica'),
            'tipo_contacto' => 'email',
            'contacto' => (string)($args['contact'] ?? ''),
            'fecha' => (string)($args['date'] ?? ''),
            'hora' => (string)($args['hour'] ?? ''),
            'personas' => 2,
            'notas' => 'Carrera controlada Etapa 9.5',
            'request_token' => (string)($args['token'] ?? ''),
        ]),
        'admin_create' => ReservacionAdministrativaService::crear([
            'nombre' => (string)($args['name'] ?? 'Etapa 9.5 administrativa'),
            'contacto_tipo' => 'ninguno',
            'contacto' => '',
            'fecha' => (string)($args['date'] ?? ''),
            'hora' => (string)($args['hour'] ?? ''),
            'comensales' => 2,
            'nota' => 'Carrera controlada Etapa 9.5',
            'comentario_admin' => '',
            'request_token' => (string)($args['token'] ?? ''),
            'asignar_automaticamente' => '1',
            'confirmaciones' => [ReservacionAdministrativaService::SIN_CONTACTO],
        ], 1),
        'confirm_replacement' => ReservacionPublicaService::confirmarReemplazo(
            [
                'request_token' => (string)($args['token'] ?? ''),
                'codigo' => (string)($args['otp'] ?? ''),
            ],
            [
                'contacto_tipo' => 'email',
                'contacto' => (string)($args['contact'] ?? ''),
            ]
        ),
        default => throw new RuntimeException('Worker desconocido: ' . $kind),
    };

    if (is_array($resultado)) {
        unset($resultado['preview_code']);
    }
    echo json_encode([
        'ok_proceso' => true,
        'scenario' => $scenario,
        'kind' => $kind,
        'resultado' => $resultado,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    echo json_encode([
        'ok_proceso' => false,
        'scenario' => $scenario,
        'kind' => $kind,
        'error' => $error->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
