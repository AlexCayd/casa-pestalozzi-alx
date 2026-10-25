<?php

declare(strict_types=1);

/*
 * Ausencias automáticas contra una base real: al vencer la tolerancia, una
 * reserva confirmada sin ticket pasa sola a no_show y su mesa queda libre. La
 * que todavía está en tolerancia, la que ya tiene ticket y la de mañana, no.
 */

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\Reservations\ReservacionAusenciaAutomaticaService as Ausencias;
use Services\Reservations\ReservacionConfig;
use Services\Tables\OcupacionMesasService;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar las ausencias automáticas.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$restaurantUnitId = NOTIFICATIONS_TEST_RESTAURANT_UNIT_ID;
$ahora = ReservacionConfig::ahora();
$tolerancia = ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS;
$creadas = [];
$tickets = [];

$sembrar = static function (string $nombre, DateTimeImmutable $inicio, int $mesaId) use ($db, &$creadas, $restaurantUnitId): int {
    $token = 'fx-ausencia-' . substr(md5($nombre . $inicio->format('c')), 0, 16);
    $fecha = $inicio->format('Y-m-d');
    $hora = $inicio->format('H:i:s');
    $stmt = $db->prepare(
        "INSERT INTO reservaciones
           (unidad_id, unidad_tipo, nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token)
         VALUES ({$restaurantUnitId}, 'negocio', ?, 'ninguno', NULL, ?, ?, 2, '', 'admin', 'confirmada', ?)"
    );
    $stmt->bind_param('ssss', $nombre, $fecha, $hora, $token);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    ReservacionMesa::reemplazarAsignacion($id, [$mesaId]);
    $creadas[] = $id;
    return $id;
};

$estado = static function (int $id) use ($db): string {
    $fila = $db->query("SELECT estado FROM reservaciones WHERE id = {$id}")->fetch_assoc();
    return (string)($fila['estado'] ?? '');
};

try {
    notificationsTestAuthenticateStaff();
    // Los minutos se toman lejos de los bordes para no depender de cuánto
    // tarde la prueba entre sembrar y procesar.
    $vencida = $sembrar('Ausencia Vencida', $ahora->modify('-' . ($tolerancia + 20) . ' minutes'), 1);
    $enTolerancia = $sembrar('Ausencia En Tolerancia', $ahora->modify('-' . max(1, $tolerancia - 10) . ' minutes'), 2);
    $conTicket = $sembrar('Ausencia Con Ticket', $ahora->modify('-' . ($tolerancia + 20) . ' minutes'), 3);
    $ayer = $sembrar('Ausencia De Ayer', $ahora->modify('-1 day'), 4);
    $manana = $sembrar('Ausencia De Mañana', $ahora->modify('+1 day'), 5);

    // El cliente de esta sí llegó: tiene ticket abierto ligado a la reserva.
    $restaurantUnitId = NOTIFICATIONS_TEST_RESTAURANT_UNIT_ID;
    $db->query("INSERT INTO tickets (unidad_id, unidad_tipo, comensales, estado, reservacion_id) VALUES ({$restaurantUnitId}, 'negocio', 2, 'abierto', {$conTicket})");
    $ticketId = (int)$db->insert_id;
    $tickets[] = $ticketId;
    $db->query("INSERT INTO ticket_mesas (unidad_id, ticket_id, mesa_id, orden) VALUES ({$restaurantUnitId}, {$ticketId}, 3, 1)");

    $resultado = Ausencias::procesar();

    $assert($estado($vencida) === 'no_show', 'La reserva con la tolerancia vencida no quedó como ausencia.');
    $assert($estado($ayer) === 'no_show', 'Una reserva de ayer que nadie resolvió no quedó como ausencia.');
    $assert($estado($enTolerancia) === 'confirmada', 'Se registró como ausencia una reserva aún en tolerancia.');
    $assert($estado($conTicket) === 'confirmada', 'Se registró como ausencia una reserva cuyo cliente tiene ticket abierto.');
    $assert($estado($manana) === 'confirmada', 'Se tocó una reserva futura.');
    $assert(in_array($vencida, $resultado['ids'], true) && in_array($ayer, $resultado['ids'], true), 'El resultado no lista las ausencias registradas.');

    // La mesa queda libre para el intervalo de la reserva que no llegó.
    $evaluacion = OcupacionMesasService::evaluarHorario(
        $ahora->format('Y-m-d'),
        $ahora->modify('-' . ($tolerancia + 20) . ' minutes')->format('H:i:s')
    );
    $assert(!in_array(1, array_map('intval', (array)($evaluacion['mesa_ids_bloqueadas'] ?? [])), true), 'La mesa de la ausencia sigue bloqueada.');

    // Idempotente: una segunda pasada no vuelve a registrar nada.
    $segunda = Ausencias::procesar();
    $assert(!in_array($vencida, $segunda['ids'], true), 'La segunda pasada volvió a registrar la misma ausencia.');

    echo "Reservaciones: ausencias automáticas al vencer la tolerancia OK\n";
} finally {
    $creadasSql = $creadas !== [] ? implode(',', array_map('intval', $creadas)) : '0';
    foreach ($tickets as $id) {
        $db->query('DELETE FROM ticket_items WHERE ticket_id = ' . (int)$id);
        $db->query('DELETE FROM ticket_pagos WHERE ticket_id = ' . (int)$id);
        $db->query('DELETE FROM ticket_mesas WHERE ticket_id = ' . (int)$id);
        $db->query('DELETE FROM tickets WHERE id = ' . (int)$id);
    }
    if ($creadas !== []) {
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$creadasSql})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$creadasSql})");
    }
    foreach ($creadas as $id) {
        $db->query('DELETE FROM reservaciones WHERE id = ' . (int)$id);
    }
}
