<?php

declare(strict_types=1);

/** Contrato de asignacion manual, advertencias y liberacion del mapa compartido. */
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\AsignacionMesasService;
use Services\ReservacionAdministrativaService;
use Services\ReservacionMapaAdministrativaService;
use Services\ReservacionService;
use Services\PosReservacionQueryService;

$db = ActiveRecord::getDB();
$options = getopt('', ['db:']);
if (!empty($options['db']) && preg_match('/^[A-Za-z0-9_]+$/', (string)$options['db']) === 1) {
    $db->select_db((string)$options['db']);
    ActiveRecord::setDB($db);
}

$prefix = 'ETAPA9_MAPA_' . bin2hex(random_bytes(4));
$fecha = '2026-11-01';
$fallos = [];
$pasadas = 0;
$assert = static function (bool $condicion, string $mensaje) use (&$fallos, &$pasadas): void {
    if ($condicion) {
        $pasadas++;
        return;
    }
    $fallos[] = $mensaje;
};

$fila = static function (int $id) use ($db): ?array {
    $resultado = $db->query("SELECT * FROM reservaciones WHERE id = {$id} LIMIT 1");
    return $resultado ? ($resultado->fetch_assoc() ?: null) : null;
};

$contexto = static function (int $id) use ($fila): array {
    $reservacion = $fila($id) ?: [];
    $mesaIds = ReservacionMesa::obtenerIdsPorReservacion($id);
    sort($mesaIds, SORT_NUMERIC);
    return [
        'version_esperada' => hash(
            'sha256',
            (string)($reservacion['updated_at'] ?: $reservacion['created_at'])
                . '|' . implode(',', $mesaIds)
        ),
        'fecha_esperada' => (string)($reservacion['fecha'] ?? ''),
        'hora_esperada' => (string)($reservacion['hora'] ?? ''),
        'mesa_ids_actuales' => $mesaIds,
        'validar_contexto' => true,
        'contexto_completo' => true,
    ];
};

$crear = static function (string $nombre, int $personas = 2, bool $sinContacto = false) use ($prefix, $fecha): int {
    $datos = [
        'nombre' => $prefix . ' ' . $nombre,
        'contacto_tipo' => $sinContacto ? 'ninguno' : 'email',
        'contacto' => $sinContacto ? '' : strtolower($prefix . '.' . $nombre) . '@example.test',
        'fecha' => $fecha,
        'hora' => '13:00',
        'comensales' => $personas,
        'nota' => 'nota de prueba',
        'comentario_admin' => '',
        'request_token' => $prefix . '_' . strtoupper($nombre) . '_TOKEN',
        'asignar_automaticamente' => '0',
        'confirmaciones' => [ReservacionAdministrativaService::SIN_ASIGNACION],
    ];
    if ($sinContacto) {
        $datos['confirmaciones'][] = ReservacionAdministrativaService::SIN_CONTACTO;
    }
    $resultado = ReservacionService::crearAdministrativa($datos);
    if ((int)($resultado['id'] ?? 0) < 1) {
        throw new RuntimeException('Alta de prueba rechazada: ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));
    }
    return (int)$resultado['id'];
};

$asignar = static function (int $id, array $mesaIds, array $confirmaciones = [], array $extra = []) use ($contexto): array {
    return ReservacionMapaAdministrativaService::guardarAsignacion(
        $id,
        $mesaIds,
        array_merge($contexto($id), [
            'confirmaciones' => $confirmaciones,
            'permitir_superposicion_ticket_abierto' => true,
        ], $extra)
    );
};

$ticketIds = [];
$openTicketIds = [];
if ($resultadoTickets = $db->query("SELECT id FROM tickets WHERE estado = 'abierto' AND closed_at IS NULL")) {
    while ($ticket = $resultadoTickets->fetch_assoc()) {
        $openTicketIds[] = (int)$ticket['id'];
    }
    $resultadoTickets->free();
}
if ($openTicketIds !== []) {
    $db->query("UPDATE tickets SET estado = 'cerrado', closed_at = '2026-11-01 12:00:00' WHERE id IN (" . implode(',', $openTicketIds) . ")");
}
try {
    $arbitraria = $crear('arbitraria');
    $resultado = $asignar($arbitraria, [2, 3]);
    $assert(($resultado['ok'] ?? false) === true, '9.1: permite combinacion manual no canonica ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));
    $assert(($resultado['mesa_ids'] ?? []) === [2, 3], '9.1: conserva las mesas solicitadas ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));

    $lecturaMapa = PosReservacionQueryService::paraFecha($fecha, '13:00', [
        'incluir_inactivas' => true,
        'calcular_conflictos' => true,
    ]);
    $proyeccionMapa = ReservacionMapaAdministrativaService::proyectar(
        (array)($lecturaMapa['reservaciones'] ?? []),
        (array)($lecturaMapa['reservaciones'] ?? [])
    );
    $filaProyectada = array_values(array_filter(
        $proyeccionMapa['reservaciones_admin'] ?? [],
        static fn(array $fila): bool => (int)($fila['id'] ?? 0) === $arbitraria
    ))[0] ?? [];
    $assert(($filaProyectada['en_lista_operativa'] ?? false) === true, '9.1: la proyeccion administrativa conserva la lista operativa');
    $assert(array_key_exists('contacto_visible', $filaProyectada) && array_key_exists('origen_visible', $filaProyectada), '9.1: la proyeccion expone contexto admin sin cambiar el contrato base');

    $exacta = $crear('exacta', 4);
    $filaExacta = $fila($exacta) ?: [];
    $filaExacta['hora'] = '14:00:00';
    $db->query("UPDATE reservaciones SET hora = '14:00:00' WHERE id = {$exacta}");
    $resultado = $asignar($exacta, [4]);
    $assert(($resultado['ok'] ?? false) === true, '9.2: permite capacidad exacta ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));

    $insuficiente = $crear('insuficiente', 6);
    $db->query("UPDATE reservaciones SET hora = '14:00:00' WHERE id = {$insuficiente}");
    $resultado = $asignar($insuficiente, [7]);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::CAPACIDAD_INSUFICIENTE, '9.3: capacidad insuficiente pide confirmacion');
    $resultadoAceptado = $asignar($insuficiente, [7], [AsignacionMesasService::CAPACIDAD_INSUFICIENTE]);
    $assert(($resultadoAceptado['ok'] ?? false) === true, '9.3: capacidad insuficiente confirmada se guarda');

    $sinContacto = $crear('sin-contacto', 2, true);
    $db->query("UPDATE reservaciones SET hora = '15:00:00' WHERE id = {$sinContacto}");
    $resultado = $asignar($sinContacto, [8]);
    $assert(($resultado['ok'] ?? false) === true, '9.4: asignacion sin contacto se guarda');
    $assert(in_array(AsignacionMesasService::SIN_CONTACTO, (array)($resultado['advertencias'] ?? []), true), '9.4: expone advertencia SIN_CONTACTO');

    $ocupante = $crear('ocupante', 2);
    $db->query("UPDATE reservaciones SET hora = '16:00:00' WHERE id = {$ocupante}");
    $resultadoOcupante = $asignar($ocupante, [9]);
    $assert(($resultadoOcupante['ok'] ?? false) === true, '9.5: crea ocupacion de prueba ' . json_encode($resultadoOcupante, JSON_UNESCAPED_UNICODE));
    $objetivoOcupado = $crear('ocupado', 2);
    $db->query("UPDATE reservaciones SET hora = '16:00:00' WHERE id = {$objetivoOcupado}");
    $resultado = $asignar($objetivoOcupado, [9]);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::MESA_OCUPADA, '9.5: conflicto con otra confirmada es duro');

    $hold = $crear('hold', 2);
    $db->query("UPDATE reservaciones SET hora = '17:00:00', origen = 'landing', estado = 'pendiente_verificacion', hold_expires_at = '2026-11-01 16:45:00' WHERE id = {$hold}");
    ReservacionMesa::reemplazarAsignacion($hold, [4]);
    $objetivoHold = $crear('objetivo-hold', 2);
    $db->query("UPDATE reservaciones SET hora = '17:00:00' WHERE id = {$objetivoHold}");
    $resultado = $asignar($objetivoHold, [4]);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::MESA_OCUPADA, '9.6: hold vigente bloquea la mesa');

    $ticketObjetivo = $crear('ticket', 2);
    $db->query("UPDATE reservaciones SET hora = '14:00:00' WHERE id = {$ticketObjetivo}");
    $db->query("INSERT INTO tickets (comensales, nombre, hora_apertura, estado, reservacion_id) VALUES (2, '" . $db->real_escape_string($prefix . ' ticket') . "', '2026-11-01 12:00:00', 'abierto', NULL)");
    $ticketId = (int)$db->insert_id;
    $ticketIds[] = $ticketId;
    $db->query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$ticketId}, 11, 1)");
    $resultado = $asignar($ticketObjetivo, [11]);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::DEPENDE_LIBERACION_PROYECTADA, '9.7: liberacion proyectada requiere confirmacion ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));
    $resultadoAceptado = $asignar($ticketObjetivo, [11], [AsignacionMesasService::DEPENDE_LIBERACION_PROYECTADA]);
    $assert(($resultadoAceptado['ok'] ?? false) === true, '9.7: liberacion proyectada confirmada se guarda ' . json_encode($resultadoAceptado, JSON_UNESCAPED_UNICODE));

    $ticketActivoObjetivo = $crear('ticket-activo', 2);
    $db->query("UPDATE reservaciones SET hora = '13:00:00' WHERE id = {$ticketActivoObjetivo}");
    $db->query("INSERT INTO tickets (comensales, nombre, hora_apertura, estado, reservacion_id) VALUES (2, '" . $db->real_escape_string($prefix . ' ticket activo') . "', '2026-11-01 12:00:00', 'abierto', NULL)");
    $ticketActivoId = (int)$db->insert_id;
    $ticketIds[] = $ticketActivoId;
    $db->query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$ticketActivoId}, 6, 1)");
    $resultado = $asignar($ticketActivoObjetivo, [6]);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::CONFLICTO_TICKETS_ABIERTOS, '9.7: ticket abierto requiere confirmacion ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));
    $resultadoAceptado = $asignar($ticketActivoObjetivo, [6], [AsignacionMesasService::CONFLICTO_TICKET_ABIERTO], [
        'ticket_ids_aceptados' => [$ticketActivoId],
        'conflicto_token' => (string)($resultado['conflicto_token'] ?? ''),
    ]);
    $assert(($resultadoAceptado['ok'] ?? false) === true, '9.7: excepcion de ticket confirmada se guarda ' . json_encode($resultadoAceptado, JSON_UNESCAPED_UNICODE));
    $assert((int)$db->query("SELECT COUNT(*) AS total FROM ticket_mesas WHERE ticket_id = {$ticketActivoId}")->fetch_assoc()['total'] === 1, '9.7: la asignacion no modifica ticket_mesas');
    $assert((int)$db->query("SELECT COUNT(*) AS total FROM ticket_mesas WHERE ticket_id = {$ticketId}")->fetch_assoc()['total'] === 1, '9.7: la asignacion no modifica ticket_mesas');

    $linked = $crear('linked', 2);
    $db->query("UPDATE reservaciones SET hora = '18:00:00' WHERE id = {$linked}");
    $db->query("INSERT INTO tickets (comensales, nombre, hora_apertura, estado, reservacion_id) VALUES (2, '" . $db->real_escape_string($prefix . ' linked') . "', '2026-11-01 18:00:00', 'abierto', {$linked})");
    $linkedTicketId = (int)$db->insert_id;
    $ticketIds[] = $linkedTicketId;
    $db->query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$linkedTicketId}, 11, 1)");
    $resultado = $asignar($linked, [11]);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::RESERVACION_NO_EDITABLE, '9.8: reservacion con ticket vinculado no se reasigna');

    $limpiar = $arbitraria;
    $contextoLimpiar = $contexto($limpiar);
    $resultado = ReservacionMapaAdministrativaService::liberarAsignacion($limpiar, $contextoLimpiar);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL, '9.9: liberar exige confirmacion explicita');
    $resultado = ReservacionMapaAdministrativaService::liberarAsignacion($limpiar, $contextoLimpiar + [
        'confirmaciones' => [AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL],
    ]);
    $assert(($resultado['ok'] ?? false) === true && ReservacionMesa::obtenerIdsPorReservacion($limpiar) === [], '9.9: liberar administrativa deja la reservacion pendiente');

    $publica = $crear('publica', 2);
    $db->query("UPDATE reservaciones SET origen = 'landing', hora = '19:00:00' WHERE id = {$publica}");
    $resultadoPublica = $asignar($publica, [2]);
    $assert(($resultadoPublica['ok'] ?? false) === true, '9.10: reservacion publica puede reasignarse ' . json_encode($resultadoPublica, JSON_UNESCAPED_UNICODE));
    $resultado = ReservacionMapaAdministrativaService::liberarAsignacion($publica, $contexto($publica) + [
        'confirmaciones' => [AsignacionMesasService::LIBERAR_ASIGNACION_ACTUAL],
    ]);
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::LIBERACION_NO_AUTORIZADA, '9.10: reservacion publica no puede quedar sin mesas');

    $stale = $crear('stale', 2);
    $staleContext = $contexto($stale);
    $resultadoStaleBase = $asignar($stale, [2]);
    $assert(($resultadoStaleBase['ok'] ?? false) === true, '9.11: asignacion base para stale ' . json_encode($resultadoStaleBase, JSON_UNESCAPED_UNICODE));
    $resultado = ReservacionMapaAdministrativaService::guardarAsignacion($stale, [3], array_merge($staleContext, [
        'confirmaciones' => [],
        'permitir_superposicion_ticket_abierto' => true,
    ]));
    $assert(($resultado['codigo'] ?? '') === AsignacionMesasService::VERSION_DESACTUALIZADA, '9.11: version stale se rechaza ' . json_encode($resultado, JSON_UNESCAPED_UNICODE));
} catch (Throwable $error) {
    $fallos[] = '9: excepcion no controlada: ' . $error->getMessage();
} finally {
    $ids = [];
    $resultado = $db->query("SELECT id FROM reservaciones WHERE nombre LIKE '" . $db->real_escape_string($prefix) . "%'");
    if ($resultado) {
        while ($fila = $resultado->fetch_assoc()) $ids[] = (int)$fila['id'];
        $resultado->free();
    }
    if ($ticketIds !== []) {
        $db->query('DELETE FROM ticket_mesas WHERE ticket_id IN (' . implode(',', $ticketIds) . ')');
        $db->query('DELETE FROM tickets WHERE id IN (' . implode(',', $ticketIds) . ')');
    }
    if ($openTicketIds !== []) {
        $db->query("UPDATE tickets SET estado = 'abierto', closed_at = NULL WHERE id IN (" . implode(',', $openTicketIds) . ")");
    }
    if ($ids !== []) {
        $db->query('DELETE FROM reservacion_mesas WHERE reservacion_id IN (' . implode(',', $ids) . ')');
        $db->query('DELETE FROM reservaciones WHERE id IN (' . implode(',', $ids) . ')');
    }
}

echo json_encode([
    'ok' => $fallos === [],
    'passed' => $pasadas,
    'failed' => $fallos,
    'prefix' => $prefix,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($fallos === [] ? 0 : 1);
