<?php

declare(strict_types=1);

/*
 * Reubicación por ticket abierto contra una base real: una reserva por llegar
 * (dentro de su hora) cuya mesa sigue ocupada se mueve sola a una mesa libre y
 * queda registrada; una que aún está lejos no se toca; si no hay a dónde, se
 * queda donde estaba y nunca sin mesas.
 */

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionReubicacionPorTicketService as PorTicket;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar la reubicación por ticket.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$ahora = ReservacionConfig::ahora();
$creadas = [];
$tickets = [];

$sembrar = static function (string $nombre, DateTimeImmutable $inicio, array $mesas) use ($db, &$creadas): int {
    $token = 'fx-reub-ticket-' . substr(md5($nombre . $inicio->format('c')), 0, 16);
    $fecha = $inicio->format('Y-m-d');
    $hora = $inicio->format('H:i:00');
    $stmt = $db->prepare(
        "INSERT INTO reservaciones
           (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token)
         VALUES (?, 'ninguno', NULL, ?, ?, 2, '', 'admin', 'confirmada', ?)"
    );
    $stmt->bind_param('ssss', $nombre, $fecha, $hora, $token);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    ReservacionMesa::reemplazarAsignacion($id, $mesas);
    $creadas[] = $id;
    return $id;
};

$ocupar = static function (array $mesas) use ($db, &$tickets): int {
    $db->query("INSERT INTO tickets (comensales, estado) VALUES (2, 'abierto')");
    $id = (int)$db->insert_id;
    $orden = 1;
    foreach ($mesas as $mesa) {
        $db->query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$id}, " . (int)$mesa . ', ' . $orden++ . ')');
    }
    $tickets[] = $id;
    return $id;
};

try {
    // El día de hoy tiene que quedar dentro del horario: la prueba no depende
    // de la hora real a la que corra, sólo de minutos relativos.
    $cercana = $sembrar('Reubicar Cercana', $ahora->modify('+40 minutes'), [1]);
    $lejana = $sembrar('Reubicar Lejana', $ahora->modify('+3 hours'), [2]);
    $ocupar([1]);
    $ocupar([2]);

    $resultado = PorTicket::procesar($ahora);
    $movidas = array_column($resultado['reubicadas'], 'id');

    $assert(in_array($cercana, $movidas, true), 'La reserva por llegar con su mesa ocupada no se reubicó.');
    $despues = ReservacionMesa::obtenerIdsPorReservacion($cercana);
    $assert($despues !== [] && !in_array(1, $despues, true) && !in_array(2, $despues, true), 'La reserva se movió a una mesa ocupada o se quedó sin mesas: ' . json_encode($despues));
    $bitacora = $db->query("SELECT motivo, mesas_antes FROM reservacion_reubicaciones WHERE reservacion_id = {$cercana}")->fetch_assoc();
    $assert(($bitacora['motivo'] ?? '') === 'ticket_abierto', 'La reubicación no quedó registrada con su motivo.');

    $assert(!in_array($lejana, $movidas, true), 'Se movió una reserva que todavía está lejos.');
    $assert(ReservacionMesa::obtenerIdsPorReservacion($lejana) === [2], 'La reserva lejana perdió su mesa.');

    // Idempotente: ya en una mesa libre, otra pasada no la mueve.
    $segunda = PorTicket::procesar($ahora);
    $assert(!in_array($cercana, array_column($segunda['reubicadas'], 'id'), true), 'La segunda pasada volvió a mover la reserva.');

    // Sin a dónde ir: todas las mesas ocupadas. Se queda donde estaba.
    $atrapada = $sembrar('Reubicar Sin Lugar', $ahora->modify('+30 minutes'), [3]);
    $resto = [];
    foreach ($db->query("SELECT id FROM mesas WHERE tipo = 'mesa' AND id NOT IN (1, 2)")->fetch_all(MYSQLI_ASSOC) as $fila) {
        $resto[] = (int)$fila['id'];
    }
    foreach ($resto as $mesa) {
        $ocupar([$mesa]);
    }
    $tercera = PorTicket::procesar($ahora);
    $assert(!in_array($atrapada, array_column($tercera['reubicadas'], 'id'), true), 'Sin mesas libres se reportó una reubicación.');
    $assert(ReservacionMesa::obtenerIdsPorReservacion($atrapada) === [3], 'Sin lugar, la reserva perdió su mesa.');

    echo "Reservaciones: reubicación por ticket abierto OK\n";
} finally {
    foreach ($tickets as $id) {
        $db->query('DELETE FROM tickets WHERE id = ' . (int)$id);
    }
    foreach ($creadas as $id) {
        $db->query('DELETE FROM reservaciones WHERE id = ' . (int)$id);
    }
}
