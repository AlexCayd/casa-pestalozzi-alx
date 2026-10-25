<?php

declare(strict_types=1);

/*
 * RevPASH sólo del salón (database/ANALITICAS.md §3.2).
 *
 * Fija las dos mitades de la fórmula: el numerador cuenta lo vendido en mesas
 * del salón —barras, Llevar y Caja no ocupan asiento del comedor— y el
 * denominador son los asientos de las mesas activas y reservables, la misma
 * cuenta que el «N/44» del POS.
 */

use Model\ActiveRecord;
use Services\Analytics\Analiticas;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar el RevPASH.\n");
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
$ticketOffer = notificationsTestOfferForArea(NOTIFICATIONS_TEST_AREA_IDS['cafe']);
$consulta = static function (string $sql) use ($db): void {
    if ($db->query($sql) === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
};

// Un martes del pasado a las 14:00: la fecha no importa, sólo que caiga en un
// día y una hora identificables en el mapa de calor.
$fecha = '2026-09-01';
$dow = (int)date('w', strtotime($fecha));
$tickets = [];

$abrirCerrado = static function (?int $mesaId, float $importe, string $nombre) use ($consulta, $db, $fecha, &$tickets, $restaurantUnitId, $ticketOffer): int {
    $consulta(
        "INSERT INTO tickets (unidad_id, unidad_tipo, comensales, nombre, hora_apertura, hora_cierre, closed_at, estado, metodo_pago)
         VALUES ({$restaurantUnitId}, 'negocio', 2, '{$nombre}', '{$fecha} 14:10:00', '{$fecha} 15:00:00', '{$fecha} 15:00:00', 'cerrado', 'efectivo')"
    );
    $ticketId = (int)$db->insert_id;
    $tickets[] = $ticketId;
    if ($mesaId !== null) {
        $consulta("INSERT INTO ticket_mesas (unidad_id, ticket_id, mesa_id, orden) VALUES ({$restaurantUnitId}, {$ticketId}, {$mesaId}, 1)");
    }
    $consulta(
        "INSERT INTO ticket_items (unidad_id, ticket_id, oferta_id, producto_id, nombre, precio, categoria, categoria_id, area_id, cantidad, estado)
         VALUES ({$restaurantUnitId}, {$ticketId}, {$ticketOffer['oferta_id']}, {$ticketOffer['producto_id']}, 'Fixture RevPASH', {$importe}, 'Fixture', {$ticketOffer['categoria_id']}, " . (int)NOTIFICATIONS_TEST_AREA_IDS['cafe'] . ", 1, 'entregado')"
    );
    return $ticketId;
};

$celda = static function (array $revpash, int $hora, int $dow): ?array {
    $fila = array_search(sprintf('%02d:00', $hora), $revpash['horas'] ?? [], true);
    if ($fila === false) {
        return null;
    }
    foreach (($revpash['dias'] ?? []) as $columna => $dia) {
        if ((int)$dia['dow'] === $dow) {
            return $revpash['celdas'][$fila][$columna] ?? null;
        }
    }
    return null;
};

try {
    notificationsTestAuthenticateStaff();
    $asientosSalon = \Model\Mesa::capacidadReservableTotal();
    $assert($asientosSalon === 44, "El salón de deploy.sql debe sumar 44 asientos y suma {$asientosSalon}.");

    // Mesa 1 del salón, Barra Blanca (12) y Llevar (14), según deploy.sql.
    $abrirCerrado(1, 400.0, 'Fixture salón');
    $abrirCerrado(12, 900.0, 'Fixture barra');
    $abrirCerrado(14, 700.0, 'Fixture llevar');

    $revpash = Analiticas::nivel1($fecha, $fecha)['revpash'];
    $assert((int)$revpash['asientos'] === 44, 'El RevPASH no usa los asientos del salón.');
    $tarde = $celda($revpash, 14, $dow);
    $assert($tarde !== null, 'El mapa de calor no trae la franja de las 14:00.');
    $assert(abs((float)$tarde['ing'] - 400.0) < 0.01, 'Barra o Llevar entraron al ingreso del salón: ' . json_encode($tarde));

    // Un histórico sin ticket_mesas es de su época, cuando sólo existía el salón.
    $abrirCerrado(null, 100.0, 'Fixture historico');
    $tarde = $celda(Analiticas::nivel1($fecha, $fecha)['revpash'], 14, $dow);
    $assert(abs((float)$tarde['ing'] - 500.0) < 0.01, 'Un ticket histórico sin mesas dejó de contar como salón.');

    // Los asientos se derivan de las mesas: desactivar una (4 lugares) baja
    // el denominador sin tocar código. No hay historial de capacidad.
    $consulta("UPDATE mesas SET activo = 0 WHERE id = 1");
    $sinMesa = Analiticas::nivel1($fecha, $fecha)['revpash'];
    $assert((int)$sinMesa['asientos'] === 40, 'Desactivar una mesa no bajó los asientos a 40.');
} finally {
    $db->query('UPDATE mesas SET activo = 1 WHERE id = 1');
    if ($tickets !== []) {
        $db->query('DELETE FROM ticket_items WHERE ticket_id IN (' . implode(',', array_map('intval', $tickets)) . ')');
        $db->query('DELETE FROM ticket_mesas WHERE ticket_id IN (' . implode(',', array_map('intval', $tickets)) . ')');
        $db->query('DELETE FROM tickets WHERE id IN (' . implode(',', array_map('intval', $tickets)) . ')');
    }
}

fwrite(STDOUT, "PASS: RevPASH sólo del salón; barras y Llevar fuera del ingreso; asientos derivados de las mesas.\n");
