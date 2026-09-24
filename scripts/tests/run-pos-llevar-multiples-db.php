<?php

declare(strict_types=1);

use Model\ActiveRecord;
use Services\Pos\PuntoVentaReservacionService;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar pedidos para llevar.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$primero = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [14],
    'comensales' => 2,
    'nombre' => 'Fixture Llevar 1',
], 0);
$segundo = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [14],
    'comensales' => 3,
    'nombre' => 'Fixture Llevar 2',
], 0);
$assert(($primero['ok'] ?? false) === true, 'No se pudo abrir el primer ticket para Llevar.');
$assert(($segundo['ok'] ?? false) === true, 'Un ticket abierto bloqueó el siguiente pedido para Llevar.');
$assert((int)$primero['ticket_id'] !== (int)$segundo['ticket_id'], 'Los pedidos para Llevar reutilizaron el mismo ticket.');

$db = ActiveRecord::getDB();
$ids = [(int)$primero['ticket_id'], (int)$segundo['ticket_id']];
$resultado = $db->query(
    'SELECT COUNT(*) AS total FROM tickets t
     INNER JOIN ticket_mesas tm ON tm.ticket_id = t.id
     WHERE t.estado = \'abierto\' AND tm.mesa_id = 14
       AND t.id IN (' . implode(',', $ids) . ')'
);
$assert($resultado !== false, 'No se pudieron contar los tickets abiertos para Llevar.');
$fila = $resultado->fetch_assoc();
$resultado->free();
$assert((int)($fila['total'] ?? 0) === 2, 'No hay dos pedidos abiertos simultáneamente en Llevar.');

$sala = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [1],
    'comensales' => 2,
    'nombre' => 'Fixture Mesa salón',
], 0);
$assert(($sala['ok'] ?? false) === true, 'No se pudo abrir el ticket de control en una mesa del salón.');
$salaDuplicada = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [1],
    'comensales' => 2,
    'nombre' => 'Fixture Mesa salón duplicada',
    'allow_multiple' => true,
], 0);
$assert(
    ($salaDuplicada['codigo'] ?? '') === PuntoVentaReservacionService::TICKET_ABIERTO,
    'allow_multiple permitió duplicar un ticket en una mesa física.'
);

$mezcla = PuntoVentaReservacionService::abrirWalkIn([
    'mesa_ids' => [1, 14],
    'comensales' => 2,
    'nombre' => 'Fixture Llevar y salón',
], 0);
$assert(
    ($mezcla['codigo'] ?? '') === PuntoVentaReservacionService::DATOS_INVALIDOS,
    'Un pedido para Llevar aceptó una mesa física adicional.'
);

fwrite(STDOUT, "PASS: dos pedidos simultáneos para Llevar; protección de mesa física y mezcla de mesas.\n");
