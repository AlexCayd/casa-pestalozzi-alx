<?php

declare(strict_types=1);

/**
 * Pruebas deterministas de fecha, horarios y capacidad para Etapa 6.2.
 * Todos los fixtures se crean dentro de una transacción y se revierten.
 */

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\DisponibilidadReservacionService;
use Services\HorarioReservacionService;
use Services\OcupacionMesasService;
use Services\ReservacionConfig;
use Services\ReservacionService;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexión MySQL para Etapa 6.2.\n");
    exit(2);
}

$passed = 0;
$failed = [];
$assert = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        return;
    }
    $failed[] = $message;
};

$query = static function (string $sql) use ($db): mysqli_result|bool {
    $result = $db->query($sql);
    if ($result === false) {
        throw new RuntimeException($db->error . ' — ' . $sql);
    }
    return $result;
};

$escape = static fn(string $value): string => $db->real_escape_string($value);

$findFreeDate = static function (int $weekday, array $avoid = []) use ($query, $escape): string {
    for ($offset = 2; $offset <= 85; $offset++) {
        $date = (new DateTimeImmutable('2026-11-01', ReservacionConfig::timezone()))
            ->modify('+' . $offset . ' days');
        if ((int)$date->format('w') !== $weekday) {
            continue;
        }
        $value = $date->format('Y-m-d');
        if (in_array($value, $avoid, true)) {
            continue;
        }
        $sqlDate = $escape($value);
        $result = $query("SELECT
            (SELECT COUNT(*) FROM reservaciones WHERE fecha = '{$sqlDate}') AS reservations,
            (SELECT COUNT(*) FROM excepciones_operacion WHERE fecha = '{$sqlDate}') AS exceptions");
        $row = $result->fetch_assoc() ?: [];
        $result->free();
        if ((int)($row['reservations'] ?? 0) === 0 && (int)($row['exceptions'] ?? 0) === 0) {
            return $value;
        }
    }

    throw new RuntimeException('No se encontró una fecha libre para Etapa 6.2.');
};

$insertException = static function (string $date, string $type, string $opening, string $closing) use ($query, $escape): int {
    $date = $escape($date);
    $type = $escape($type);
    $opening = $escape($opening);
    $closing = $escape($closing);
    $query("INSERT INTO excepciones_operacion
        (fecha, tipo, motivo, hora_apertura, hora_cierre, activo)
        VALUES ('{$date}', '{$type}', 'ETAPA6_2', '{$opening}', '{$closing}', 1)");
    return (int)ActiveRecord::getDB()->insert_id;
};

$mesaIds = [];
$result = $query('SELECT id, numero FROM mesas WHERE activo = 1 AND reservable = 1 AND tipo = \'mesa\' ORDER BY numero, id');
while ($row = $result->fetch_assoc()) {
    $mesaIds[(int)$row['numero']] = (int)$row['id'];
}
$result->free();
if ($mesaIds === []) {
    fwrite(STDERR, "No hay mesas reservables para Etapa 6.2.\n");
    exit(2);
}

$insertReservation = static function (
    string $name,
    string $date,
    string $hour,
    string $state,
    ?string $hold,
    string $token
) use ($query, $escape): int {
    $name = $escape($name);
    $date = $escape($date);
    $hour = $escape($hour);
    $state = $escape($state);
    $holdSql = $hold === null ? 'NULL' : "'" . $escape($hold) . "'";
    $token = $escape($token);
    $query("INSERT INTO reservaciones
        (nombre, contacto_tipo, contacto, fecha, hora, comensales, origen, estado,
         hold_expires_at, request_token)
        VALUES ('{$name}', 'ninguno', NULL, '{$date}', '{$hour}', 2, 'admin', '{$state}',
                {$holdSql}, '{$token}')");
    return (int)ActiveRecord::getDB()->insert_id;
};

$assign = static function (int $reservationId, array $ids) use ($query): void {
    foreach (array_values($ids) as $order => $mesaId) {
        $query(sprintf(
            'INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden) VALUES (%d, %d, %d)',
            $reservationId,
            (int)$mesaId,
            $order + 1
        ));
    }
};

$insertTicket = static function (string $name, string $opening, int $mesaId) use ($query, $escape): int {
    $name = $escape($name);
    $opening = $escape($opening);
    $query("INSERT INTO tickets (comensales, nombre, hora_apertura, estado, reservacion_id)
        VALUES (2, '{$name}', '{$opening}', 'abierto', NULL)");
    $ticketId = (int)ActiveRecord::getDB()->insert_id;
    $query("INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES ({$ticketId}, " . (int)$mesaId . ", 1)");
    return $ticketId;
};

$now = new DateTimeImmutable('2026-11-01 12:00:00', ReservacionConfig::timezone());
$lunes = $findFreeDate(1);
$martes = $findFreeDate(2, [$lunes]);
$miercoles = $findFreeDate(3, [$lunes, $martes]);
$lunesEspecial = $findFreeDate(1, [$lunes, $martes, $miercoles]);

try {
    $db->begin_transaction();

    // La semana tiene cuatro resultados deliberadamente diferentes.
    $query("UPDATE horarios_operacion SET abierto = 0, hora_apertura = NULL, hora_cierre = NULL");
    $query("UPDATE horarios_operacion SET abierto = 1, hora_apertura = '09:00:00', hora_cierre = '18:00:00' WHERE dia_semana = 1");
    $query("UPDATE horarios_operacion SET abierto = 1, hora_apertura = '12:00:00', hora_cierre = '22:00:00' WHERE dia_semana = 2");
    $query("UPDATE horarios_operacion SET abierto = 1, hora_apertura = '10:00:00', hora_cierre = '20:00:00' WHERE dia_semana = 0");
    $insertException($lunesEspecial, 'horario_especial', '14:00:00', '20:00:00');

    $lunesCalendar = HorarioReservacionService::resolverFecha($lunes, $now);
    $martesCalendar = HorarioReservacionService::resolverFecha($martes, $now);
    $miercolesCalendar = HorarioReservacionService::resolverFecha($miercoles, $now);
    $specialCalendar = HorarioReservacionService::resolverFecha($lunesEspecial, $now);
    $todayCalendar = HorarioReservacionService::resolverFecha('2026-11-01', $now);

    $assert(($lunesCalendar['horarios_candidatos'][0] ?? '') === '09:00', '6.2: lunes usa su horario semanal');
    $assert(($martesCalendar['horarios_candidatos'][0] ?? '') === '12:00', '6.2: martes usa otro horario semanal');
    $assert(($lunesCalendar['horarios_candidatos'] ?? []) !== ($martesCalendar['horarios_candidatos'] ?? []), '6.2: días distintos producen listas distintas');
    $assert(($miercolesCalendar['reservable'] ?? true) === false, '6.2: miércoles cerrado no produce horarios');
    $assert(($specialCalendar['hora_apertura'] ?? '') === '14:00' && ($specialCalendar['hora_cierre'] ?? '') === '20:00', '6.2: excepción sólo aplica a su fecha');
    $assert(($todayCalendar['horarios_candidatos'][0] ?? '') === '13:00', '6.2: hoy usa su horario semanal y anticipación mínima');
    $assert(($todayCalendar['horarios_candidatos'][0] ?? '') !== '09:00', '6.2: hoy no hereda el horario del lunes futuro');

    // Reservación de fecha A: todas las mesas quedan ocupadas en el mismo intervalo.
    $allMesaIds = array_values($mesaIds);
    $reservationA = $insertReservation('ETAPA6_2_RESERVA_A', $lunes, '13:00:00', 'confirmada', null, 'ETAPA62_A_' . bin2hex(random_bytes(8)));
    $assign($reservationA, $allMesaIds);

    // Hold separado en fecha B y ticket físico sólo en el día actual.
    $holdB = $insertReservation('ETAPA6_2_HOLD_B', $martes, '13:00:00', 'pendiente_verificacion', '2026-11-01 12:15:00', 'ETAPA62_HOLD_' . bin2hex(random_bytes(8)));
    $holdMesaId = $allMesaIds[0];
    $assign($holdB, [$holdMesaId]);
    $ticketCurrent = $insertTicket('ETAPA6_2_TICKET_HOY', '2026-11-01 11:30:00', $allMesaIds[1] ?? $holdMesaId);

    $publicA = DisponibilidadReservacionService::consultar($lunes, 2, 0, '13:00');
    $publicB = DisponibilidadReservacionService::consultar($martes, 2, 0, '13:00');
    $adminB = DisponibilidadReservacionService::consultarAdministrativa($martes, 2, 0, '13:00');
    $adminSpecial = DisponibilidadReservacionService::consultarAdministrativa($lunesEspecial, 2, 0);

    $assert(($publicA['fecha'] ?? '') === $lunes && ($publicA['disponible'] ?? true) === false, '6.2: fecha A ocupada no disponible');
    $assert(($publicB['fecha'] ?? '') === $martes && ($publicB['disponible'] ?? false) === true, '6.2: fecha B conserva capacidad propia');
    $assert(($publicB['hora'] ?? '') === '13:00', '6.2: respuesta pública puntual devuelve fecha y hora solicitadas');
    $assert(($adminB['fecha'] ?? '') === $martes && ($adminB['hora'] ?? '') === '13:00', '6.2: respuesta administrativa devuelve fecha y hora solicitadas');
    $assert(isset($adminB['detalle_horarios']['13:00']['capacidad_estimada_horario']), '6.2: administración expone capacidad por horario');
    $assert(($adminSpecial['fecha'] ?? '') === $lunesEspecial && ($adminSpecial['detalle_horario']['es_excepcion'] ?? false) === true, '6.2: administración conserva excepción de la fecha seleccionada');

    $currentOccupancy = OcupacionMesasService::evaluarHorario('2026-11-01', '12:00', 0, false, null, $now);
    $futureOccupancy = OcupacionMesasService::evaluarHorario($martes, '12:00', 0, false, null, $now);
    $currentMesaState = $currentOccupancy['mesas'][$allMesaIds[1] ?? $holdMesaId] ?? [];
    $futureMesaState = $futureOccupancy['mesas'][$allMesaIds[1] ?? $holdMesaId] ?? [];
    $assert(($currentMesaState['fuente'] ?? '') === 'ticket_abierto', '6.2: ticket actual bloquea el día actual');
    $assert(($futureMesaState['fuente'] ?? '') !== 'ticket_abierto', '6.2: ticket actual no bloquea fecha futura');
    $holdState = $futureOccupancy['mesas'][$holdMesaId] ?? [];
    $assert(($holdState['fuente'] ?? '') === 'hold', '6.2: hold sólo ocupa su fecha');
    $assert(($currentOccupancy['mesas'][$holdMesaId]['fuente'] ?? '') !== 'hold', '6.2: hold de fecha futura no aparece en hoy');
    $assert($ticketCurrent > 0, '6.2: fixture de ticket actual creado');

    // A→B con hora conservada: la hora válida para el martes se rechaza en un miércoles cerrado.
    $revalidation = ReservacionService::crearAdministrativa([
        'nombre' => 'ETAPA6_2_REVALIDACION',
        'contacto_tipo' => 'ninguno',
        'contacto' => '',
        'fecha' => $miercoles,
        'hora' => '13:00',
        'comensales' => 2,
        'nota' => '',
        'comentario_admin' => '',
        'request_token' => 'ETAPA62_REVALIDACION_' . bin2hex(random_bytes(8)),
        'confirmar_sin_contacto' => '1',
        'asignar_automaticamente' => '0',
    ]);
    $assert(($revalidation['ok'] ?? true) === false, '6.2: creación rechaza hora conservada al cambiar a fecha B inválida');
    $assert(in_array((string)($revalidation['codigo_horario'] ?? ''), [
        HorarioReservacionService::DIA_INACTIVO,
        HorarioReservacionService::HORARIO_INVALIDO,
        HorarioReservacionService::FECHA_PASADA,
    ], true), '6.2: rechazo de creación identifica la fecha/hora inválida');
} catch (Throwable $error) {
    $failed[] = '6.2: excepción no controlada: ' . $error->getMessage();
} finally {
    $db->rollback();
}

echo json_encode([
    'ok' => $failed === [],
    'passed' => $passed,
    'failed' => $failed,
    'fixtures_rolled_back' => true,
    'clock' => $now->format('Y-m-d H:i:s'),
    'timezone' => ReservacionConfig::timezone()->getName(),
    'dates' => [
        'lunes' => $lunes,
        'martes' => $martes,
        'miercoles' => $miercoles,
        'lunes_especial' => $lunesEspecial,
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

exit($failed === [] ? 0 : 1);
