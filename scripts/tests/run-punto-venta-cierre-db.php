<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\PuntoVentaReservacionService;
use Services\ReservacionErrorCatalog;

function assertDbClosure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function insertAuditTicket(mysqli $db, string $nombre, array $mesaIds, ?int $reservacionId, array $items): int
{
    $nombreSql = $db->real_escape_string($nombre);
    $reservacionSql = $reservacionId === null ? 'NULL' : (string)$reservacionId;
    assertDbClosure($db->query(
        "INSERT INTO tickets (comensales, nombre, estado, metodo_pago, propina, reservacion_id)
         VALUES (2, '{$nombreSql}', 'abierto', NULL, 0, {$reservacionSql})"
    ) !== false, 'no se pudo crear el ticket de prueba');
    $ticketId = (int)$db->insert_id;

    foreach ($mesaIds as $orden => $mesaId) {
        $ordenReal = $orden + 1;
        assertDbClosure($db->query(
            "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden)
             VALUES ({$ticketId}, " . (int)$mesaId . ", {$ordenReal})"
        ) !== false, 'no se pudo vincular la mesa del ticket de prueba');
    }

    foreach ($items as $item) {
        $nombreItem = $db->real_escape_string((string)$item['nombre']);
        $precio = number_format((float)$item['precio'], 2, '.', '');
        $cantidad = (int)$item['cantidad'];
        $estado = $db->real_escape_string((string)($item['estado'] ?? 'entregado'));
        assertDbClosure($db->query(
            "INSERT INTO ticket_items
                (ticket_id, nombre, precio, categoria, area_id, cantidad, estado)
             VALUES
                ({$ticketId}, '{$nombreItem}', {$precio}, 'Desayunos', 3, {$cantidad}, '{$estado}')"
        ) !== false, 'no se pudo crear el item del ticket de prueba');
    }

    return $ticketId;
}

function insertAuditReservation(mysqli $db, string $token): int
{
    $tokenSql = $db->real_escape_string($token);
    assertDbClosure($db->query(
        "INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen,
             estado, request_token, estado_changed_at)
         VALUES
            ('AUDIT FIX reservación cierre', 'ninguno', NULL, CURDATE(),
             TIME_FORMAT(NOW(), '%H:%i:00'), 2, '', 'admin', 'en_curso',
             '{$tokenSql}', NOW())"
    ) !== false, 'no se pudo crear la reservación de prueba');
    $reservacionId = (int)$db->insert_id;
    assertDbClosure($db->query(
        "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
         VALUES ({$reservacionId}, 1, 1)"
    ) !== false, 'no se pudo asignar la mesa a la reservación de prueba');

    return $reservacionId;
}

function assertClosedContract(mysqli $db, int $ticketId, float $propinaEsperada): array
{
    $filaResult = $db->query(
        "SELECT estado, closed_at, hora_cierre, metodo_pago, propina, reservacion_id
         FROM tickets WHERE id = {$ticketId} LIMIT 1"
    );
    assertDbClosure($filaResult !== false, 'no se pudo verificar el ticket cerrado');
    $fila = $filaResult->fetch_assoc();
    $filaResult->free();
    assertDbClosure(is_array($fila), 'el ticket cerrado no existe');
    assertDbClosure($fila['estado'] === 'cerrado', 'el ticket no quedó cerrado');
    assertDbClosure((string)$fila['closed_at'] !== '', 'closed_at quedó vacío');
    assertDbClosure((string)$fila['hora_cierre'] !== '', 'hora_cierre quedó vacía');
    assertDbClosure(abs((float)$fila['propina'] - $propinaEsperada) < 0.001, 'la propina persistida no coincide');

    $contract = ReservacionErrorCatalog::enriquecer([
        'ok' => true,
        'codigo' => 'TICKET_CERRADO',
    ], ['superficie' => 'pos']);
    assertDbClosure($contract['ok'] === true, 'el contrato de cierre no confirma ok=true');
    assertDbClosure($contract['tipo'] === 'exito', 'el contrato de cierre no confirma tipo=exito');
    assertDbClosure($contract['codigo'] === 'TICKET_CERRADO', 'el contrato de cierre perdió TICKET_CERRADO');
    assertDbClosure($contract['commit'] === true, 'el contrato de cierre no confirma commit=true');

    return $fila;
}

$db = ActiveRecord::getDB();
$ticketIds = [];
$reservacionIds = [];

try {
    $casos = [
        ['nombre' => 'AUDIT FIX cierre normal', 'mesas' => [14], 'propina' => 0.0, 'pagos' => []],
        ['nombre' => 'AUDIT FIX cierre propina', 'mesas' => [14], 'propina' => 24.0, 'pagos' => []],
    ];

    foreach ($casos as $caso) {
        $ticketId = insertAuditTicket(
            $db,
            $caso['nombre'],
            $caso['mesas'],
            null,
            [['nombre' => 'AUDIT FIX platillo', 'precio' => 240.0, 'cantidad' => 1]]
        );
        $ticketIds[] = $ticketId;

        $resultado = PuntoVentaReservacionService::cerrarTicket(
            $ticketId,
            'efectivo',
            (float)$caso['propina'],
            $caso['pagos'],
            0
        );
        assertDbClosure(($resultado['ok'] ?? false) === true, 'fallo de cierre: ' . json_encode($resultado));
        $fila = assertClosedContract($db, $ticketId, (float)$caso['propina']);

        echo json_encode([
            'caso' => $caso['nombre'],
            'ticket_id' => $ticketId,
            'subtotal' => 240.0,
            'total' => 240.0,
            'recibido' => 240.0 + (float)$caso['propina'],
            'propina' => (float)$fila['propina'],
            'cambio' => 0.0,
            'estado' => $fila['estado'],
            'closed_at' => $fila['closed_at'],
            'resultado' => $resultado,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    $multimesaId = insertAuditTicket(
        $db,
        'AUDIT FIX cierre multimesa',
        [1, 11],
        null,
        [
            ['nombre' => 'AUDIT FIX platillo A', 'precio' => 180.0, 'cantidad' => 1],
            ['nombre' => 'AUDIT FIX platillo B', 'precio' => 180.0, 'cantidad' => 1],
        ]
    );
    $ticketIds[] = $multimesaId;
    $multimesaResultado = PuntoVentaReservacionService::cerrarTicket(
        $multimesaId,
        'dividido',
        0.0,
        [
            ['comensal' => 1, 'metodo' => 'efectivo', 'monto' => 180.0],
            ['comensal' => 2, 'metodo' => 'tarjeta', 'monto' => 180.0],
        ],
        0
    );
    assertDbClosure(($multimesaResultado['ok'] ?? false) === true, 'fallo de cierre multimesa');
    $filaMultimesa = assertClosedContract($db, $multimesaId, 0.0);
    $mesasResult = $db->query(
        "SELECT mesa_id FROM ticket_mesas WHERE ticket_id = {$multimesaId} ORDER BY orden"
    );
    assertDbClosure($mesasResult !== false, 'no se pudo leer la asignación histórica multimesa');
    $mesasPersistidas = [];
    while ($mesa = $mesasResult->fetch_assoc()) {
        $mesasPersistidas[] = (int)$mesa['mesa_id'];
    }
    $mesasResult->free();
    assertDbClosure($mesasPersistidas === [1, 11], 'el cierre eliminó o alteró ticket_mesas');
    assertDbClosure($filaMultimesa['metodo_pago'] === 'dividido', 'no persistió el método dividido');
    echo json_encode([
        'caso' => 'AUDIT FIX cierre multimesa',
        'ticket_id' => $multimesaId,
        'mesas_persistidas' => $mesasPersistidas,
        'propina' => (float)$filaMultimesa['propina'],
        'estado' => $filaMultimesa['estado'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    $reservacionId = insertAuditReservation(
        $db,
        'audit-reservation-close-' . bin2hex(random_bytes(8))
    );
    $reservacionIds[] = $reservacionId;
    $reservacionTicketId = insertAuditTicket(
        $db,
        'AUDIT FIX ticket de reservación',
        [1],
        $reservacionId,
        [['nombre' => 'AUDIT FIX platillo reservación', 'precio' => 240.0, 'cantidad' => 1]]
    );
    $ticketIds[] = $reservacionTicketId;
    $reservacionResultado = PuntoVentaReservacionService::cerrarTicket(
        $reservacionTicketId,
        'efectivo',
        0.0,
        [],
        0
    );
    assertDbClosure(($reservacionResultado['ok'] ?? false) === true, 'fallo de cierre de ticket asociado a reservación');
    $filaReservacion = assertClosedContract($db, $reservacionTicketId, 0.0);
    assertDbClosure((int)$filaReservacion['reservacion_id'] === $reservacionId, 'se perdió la asociación del ticket');

    $reservaResult = $db->query(
        "SELECT estado FROM reservaciones WHERE id = {$reservacionId} LIMIT 1"
    );
    assertDbClosure($reservaResult !== false, 'no se pudo leer la reservación cerrada');
    $reserva = $reservaResult->fetch_assoc();
    $reservaResult->free();
    assertDbClosure(($reserva['estado'] ?? '') === 'completada', 'la reservación no pasó a completada');

    $asignacionResult = $db->query(
        "SELECT COUNT(*) AS total FROM reservacion_mesas WHERE reservacion_id = {$reservacionId}"
    );
    assertDbClosure($asignacionResult !== false, 'no se pudo verificar reservacion_mesas');
    $asignacion = $asignacionResult->fetch_assoc();
    $asignacionResult->free();
    assertDbClosure((int)$asignacion['total'] === 1, 'el cierre alteró la asignación histórica de reservación');
    echo json_encode([
        'caso' => 'AUDIT FIX ticket de reservación',
        'ticket_id' => $reservacionTicketId,
        'reservacion_id' => $reservacionId,
        'reservacion_estado' => $reserva['estado'],
        'reservacion_mesas' => (int)$asignacion['total'],
        'estado' => $filaReservacion['estado'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    foreach ($ticketIds as $ticketId) {
        $db->query("DELETE FROM ticket_pagos WHERE ticket_id = {$ticketId}");
        $db->query("DELETE FROM ticket_items WHERE ticket_id = {$ticketId}");
        $db->query("DELETE FROM ticket_mesas WHERE ticket_id = {$ticketId}");
        $db->query("DELETE FROM tickets WHERE id = {$ticketId}");
    }
    foreach ($reservacionIds as $reservacionId) {
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id = {$reservacionId}");
        $db->query("DELETE FROM reservaciones WHERE id = {$reservacionId}");
    }
}
