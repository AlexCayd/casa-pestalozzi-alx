<?php

declare(strict_types=1);

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\HorarioReservacionService;
use Services\ReservacionService;

$options = getopt('', ['db:', 'name:', 'token:', 'contact:']);
$db = ActiveRecord::getDB();
if (!empty($options['db'])) {
    $db->select_db((string)$options['db']);
    ActiveRecord::setDB($db);
}
$fecha = '2026-11-02';
$calendar = HorarioReservacionService::resolverFecha($fecha);
$hora = (string)($calendar['horarios_candidatos'][0] ?? '13:00');
$resultado = ReservacionService::crearAdministrativa([
    'nombre' => (string)($options['name'] ?? 'ETAPA8_CONCURRENCIA'),
    'contacto_tipo' => 'email',
    'contacto' => (string)($options['contact'] ?? 'etapa8-concurrency@example.test'),
    'fecha' => $fecha,
    'hora' => $hora,
    'comensales' => 2,
    'nota' => '',
    'comentario_admin' => '',
    'request_token' => (string)($options['token'] ?? ('ETAPA8_WORKER_' . bin2hex(random_bytes(6)))),
    'asignar_automaticamente' => '1',
]);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit(($resultado['ok'] ?? false) ? 0 : 1);
