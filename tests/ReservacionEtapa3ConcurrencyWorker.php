<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Model\ActiveRecord;
use Services\HorarioOperacionService;
use Services\PuntoVentaReservacionService;
use Services\ReservacionService;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set('America/Mexico_City');

[$script, $database, $payload64, $ready, $go, $result] = $argv;
$payload = json_decode((string)base64_decode($payload64, true), true);
$db = mysqli_connect(
    (string)($_ENV['DB_HOST'] ?? 'localhost'),
    (string)($_ENV['DB_USER'] ?? ''),
    (string)($_ENV['DB_PASS'] ?? ''),
    $database
);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db->query("SET time_zone = '-06:00'");
ActiveRecord::setDB($db);
file_put_contents($ready, 'ready');
while (!is_file($go)) {
    usleep(5000);
}

$mode = (string)($payload['mode'] ?? '');
$response = match ($mode) {
    'begin' => PuntoVentaReservacionService::comenzar(
        (int)$payload['reservacion_id'],
        (int)$payload['usuario_id']
    ),
    'walkin' => PuntoVentaReservacionService::abrirWalkIn(
        [
            'mesa_ids' => [(int)$payload['mesa_id']],
            'comensales' => 2,
            'nombre' => 'Carrera Etapa 3',
        ],
        (int)$payload['usuario_id']
    ),
    'close' => PuntoVentaReservacionService::cerrarTicket(
        (int)$payload['ticket_id'],
        'efectivo',
        0,
        [],
        (int)$payload['usuario_id']
    ),
    'no_show' => PuntoVentaReservacionService::noShow(
        (int)$payload['reservacion_id'],
        (int)$payload['usuario_id'],
        false,
        false
    ),
    'cancel' => PuntoVentaReservacionService::cancelar(
        (int)$payload['reservacion_id'],
        (int)$payload['usuario_id'],
        'Carrera'
    ),
    'schedule_close' => (static function () use ($payload): array {
        $horario = HorarioOperacionService::obtenerHorarioSemanal();
        $dia = (int)$payload['dia_semana'];
        $horario[$dia]['abierto'] = false;
        $horario[$dia]['hora_apertura'] = '';
        $horario[$dia]['hora_cierre'] = '';
        return HorarioOperacionService::guardarHorarioSemanal($horario, (int)$payload['usuario_id'], true);
    })(),
    'reserve' => ReservacionService::crearAdministrativa([
        'nombre' => 'Carrera horario y reservación',
        'email' => (string)$payload['email'],
        'fecha' => (string)$payload['fecha'],
        'hora' => (string)$payload['hora'],
        'comensales' => 2,
        'request_token' => (string)$payload['request_token'],
    ]),
    default => ['ok' => false, 'codigo' => 'MODO_INVALIDO'],
};

file_put_contents($result, json_encode($response, JSON_UNESCAPED_UNICODE));
