<?php

declare(strict_types=1);

// Entrega anticipada desde el POS: el mesero puede marcar 'entregado' un ítem
// en enviado, en_preparacion o listo, sin esperar a que el tablero de área lo
// avance. Ejecuta el controlador real (CSRF incluido) contra la base.

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

ob_start();
require dirname(__DIR__, 2) . '/includes/app.php';

use Controllers\PuntoVentaController;
use Model\ActiveRecord;
use MVC\Router;
use Services\Security\StaffCsrfService;

function assertEntrega(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function crearTicketEntrega(mysqli $db, string $estadoTicket, array $estados): array
{
    $closed = $estadoTicket === 'cerrado' ? 'NOW()' : 'NULL';
    assertEntrega($db->query(
        "INSERT INTO tickets (comensales, nombre, estado, metodo_pago, propina, closed_at)
         VALUES (2, 'AUDIT FIX entrega', '{$estadoTicket}', NULL, 0, {$closed})"
    ) !== false, 'no se pudo crear el ticket de prueba: ' . $db->error);
    $ticketId = (int)$db->insert_id;
    assertEntrega($db->query(
        "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$ticketId}, 14, 1)"
    ) !== false, 'no se pudo vincular la mesa');

    $items = [];
    foreach ($estados as $estado) {
        assertEntrega($db->query(
            "INSERT INTO ticket_items (ticket_id, nombre, precio, categoria, area_id, cantidad, estado)
             VALUES ({$ticketId}, 'AUDIT FIX platillo', 100.00, 'Desayunos', 3, 1, '{$estado}')"
        ) !== false, 'no se pudo crear el ítem');
        $items[$estado] = (int)$db->insert_id;
    }
    return [$ticketId, $items];
}

function entregar(int $itemId): array
{
    $_POST = ['item_id' => $itemId, 'csrf_token' => StaffCsrfService::token()];
    ob_start();
    PuntoVentaController::entregarItem(new Router());
    $json = json_decode((string)ob_get_clean(), true);
    assertEntrega(is_array($json), 'respuesta no JSON');
    return $json;
}

function estadoItem(mysqli $db, int $itemId): string
{
    $r = $db->query("SELECT estado FROM ticket_items WHERE id = {$itemId}");
    return (string)$r->fetch_row()[0];
}

$db = ActiveRecord::getDB();
$ticketIds = [];

try {
    // 1. Abierto: los tres estados vivos se entregan; los terminales no.
    [$abierto, $items] = crearTicketEntrega($db, 'abierto',
        ['enviado', 'en_preparacion', 'listo', 'entregado', 'cancelado']);
    $ticketIds[] = $abierto;

    foreach (['enviado', 'en_preparacion', 'listo'] as $estado) {
        $res = entregar($items[$estado]);
        assertEntrega(($res['ok'] ?? false) === true, "{$estado}: no respondió ok — " . json_encode($res));
        assertEntrega(estadoItem($db, $items[$estado]) === 'entregado', "{$estado}: no quedó entregado");
        echo "ok   {$estado} -> entregado\n";
    }

    // Doble toque sobre el ya entregado: rechazo explícito, no un falso ok.
    $res = entregar($items['enviado']);
    assertEntrega(($res['ok'] ?? true) === false && ($res['codigo'] ?? '') === 'ITEM_NO_ENTREGABLE',
        'segunda entrega no se rechazó: ' . json_encode($res));
    echo "ok   repetir entrega -> ITEM_NO_ENTREGABLE\n";

    $res = entregar($items['cancelado']);
    assertEntrega(($res['codigo'] ?? '') === 'ITEM_NO_ENTREGABLE', 'cancelado se aceptó');
    assertEntrega(estadoItem($db, $items['cancelado']) === 'cancelado', 'cancelado cambió de estado');
    echo "ok   cancelado -> rechazado, sin cambio\n";

    $res = entregar(999999999);
    assertEntrega(($res['codigo'] ?? '') === 'ITEM_NO_ENTREGABLE', 'ítem inexistente se aceptó');
    echo "ok   ítem inexistente -> rechazado\n";

    // 2. Ticket cerrado: su historia no se reescribe.
    [$cerrado, $itemsCerrado] = crearTicketEntrega($db, 'cerrado', ['enviado']);
    $ticketIds[] = $cerrado;
    $res = entregar($itemsCerrado['enviado']);
    assertEntrega(($res['codigo'] ?? '') === 'ITEM_NO_ENTREGABLE', 'ticket cerrado se aceptó');
    assertEntrega(estadoItem($db, $itemsCerrado['enviado']) === 'enviado', 'ítem de ticket cerrado cambió');
    echo "ok   ticket cerrado -> rechazado, sin cambio\n";

    // 3. Sin CSRF no se escribe nada.
    [$csrf, $itemsCsrf] = crearTicketEntrega($db, 'abierto', ['listo']);
    $ticketIds[] = $csrf;
    $_POST = ['item_id' => $itemsCsrf['listo'], 'csrf_token' => 'x'];
    ob_start();
    PuntoVentaController::entregarItem(new Router());
    $res = json_decode((string)ob_get_clean(), true);
    assertEntrega(($res['codigo'] ?? '') === 'CSRF_INVALIDO', 'CSRF no se validó');
    assertEntrega(estadoItem($db, $itemsCsrf['listo']) === 'listo', 'sin CSRF cambió el estado');
    echo "ok   sin CSRF -> rechazado\n";

    echo "TODO OK\n";
} finally {
    foreach ($ticketIds as $ticketId) {
        $db->query("DELETE FROM ticket_items WHERE ticket_id = {$ticketId}");
        $db->query("DELETE FROM ticket_mesas WHERE ticket_id = {$ticketId}");
        $db->query("DELETE FROM tickets WHERE id = {$ticketId}");
    }
}
