<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Este seeder sólo se ejecuta por CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\Reservations\HorarioReservacionService;
use Services\Reservations\ReservacionConfig;
use Services\Scheduling\HorarioOperacionService;

function mapSeedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dbHost = strtolower(trim((string)($_ENV['DB_HOST'] ?? '')));
$dbName = strtolower(trim((string)($_ENV['DB_NAME'] ?? '')));
$environment = strtolower(trim(ReservacionConfig::appEnvironment()));
mapSeedAssert(
    in_array($dbHost, ['127.0.0.1', 'localhost', '::1'], true)
        && $dbName === 'casa-pestalozzi'
        && in_array($environment, ['development', 'local'], true),
    'Se detuvo: sólo se permiten DB_HOST local, DB_NAME=casa-pestalozzi y APP_ENV development/local.'
);

$db = ActiveRecord::getDB();
$ahora = ReservacionConfig::ahora();
$fechaHoy = $ahora->format('Y-m-d');
$horaHoy = $ahora->format('H:i:s');
$horarioHoy = HorarioOperacionService::obtenerHorarioEfectivo($fechaHoy);
$cierreHoy = HorarioReservacionService::normalizarHoraSql((string)($horarioHoy['hora_cierre'] ?? ''));
mapSeedAssert(!empty($horarioHoy['abierto']) && $cierreHoy !== '', 'El día actual no tiene un cierre operativo válido para proyectar un ticket local.');
$cierreDateTime = new DateTimeImmutable($fechaHoy . ' ' . $cierreHoy, ReservacionConfig::timezone());
$duracionProyeccionTicket = ReservacionConfig::DURACION_ESTIMADA_TICKET_MINUTOS
    + ReservacionConfig::RETRASO_ESTIMADO_TICKET_MINUTOS;
$ultimoFinProyectable = $cierreDateTime->modify('-5 minutes');
$ultimoInicioProyectable = $ultimoFinProyectable->modify('-' . $duracionProyeccionTicket . ' minutes');
$inicioTicketProyectado = $ahora->modify('-17 minutes');
$inicioTicketProyectado = $inicioTicketProyectado->setTime(
    (int)$inicioTicketProyectado->format('H'),
    (int)$inicioTicketProyectado->format('i'),
    0
);
if ($inicioTicketProyectado->modify('+' . $duracionProyeccionTicket . ' minutes') > $ultimoFinProyectable) {
    $inicioTicketProyectado = $ultimoInicioProyectable;
}
mapSeedAssert(
    $inicioTicketProyectado < $ahora
        && $inicioTicketProyectado->modify('+' . $duracionProyeccionTicket . ' minutes') > $ahora,
    'La hora actual no deja una ventana futura del mismo día para revisar visualmente la liberación estimada del ticket.'
);
$ticketStart = $inicioTicketProyectado->format('Y-m-d H:i:s');

$fechaReferencia = null;
for ($dias = 1; $dias <= 14; $dias++) {
    $candidata = $ahora->modify('+' . $dias . ' days')->format('Y-m-d');
    $horarios = HorarioReservacionService::horariosOperativosConfigurados($candidata);
    if (in_array('12:00:00', $horarios, true)) {
        $fechaReferencia = $candidata;
        break;
    }
}
mapSeedAssert($fechaReferencia !== null, 'No se encontró un día operativo con horario reservable 12:00 dentro de los próximos 14 días.');

$horarioReferencia = HorarioOperacionService::obtenerHorarioEfectivo($fechaReferencia);
$apertura = HorarioReservacionService::normalizarHoraSql((string)($horarioReferencia['hora_apertura'] ?? ''));
mapSeedAssert($apertura !== '' && $apertura <= '10:30:00', 'El día de referencia debe permitir la consulta R-90 a las 10:30.');

$tableSpecs = [
    'reference' => ['[MAP TEST] R12', true, true],
    'ticket_projection' => ['[MAP TEST] T-PROY', true, true],
    'pos_free' => ['[MAP TEST] LIBRE', true, true],
    'pos_warning' => ['[MAP TEST] AVISO', true, true],
    'pos_block' => ['[MAP TEST] +30', true, true],
    'pos_start' => ['[MAP TEST] INICIO', true, true],
    'pos_tolerance' => ['[MAP TEST] TOL', true, true],
    'pos_absence' => ['[MAP TEST] AUSENCIA', true, true],
    'pos_no_show' => ['[MAP TEST] NOSHOW', true, true],
    'pos_ticket_recent' => ['[MAP TEST] TICKET', true, true],
    'pos_ticket_old' => ['[MAP TEST] >90', true, true],
    'pos_ticket_warning' => ['[MAP TEST] T+AVISO', true, true],
    'pos_ticket_active' => ['[MAP TEST] T+RES', true, true],
    'pos_hold' => ['[MAP TEST] HOLD', true, true],
    'unusable' => ['[MAP TEST] V17', false, false],
];

/** @return array<string, mixed> */
function mapSeedTable(
    mysqli $db,
    string $name,
    bool $active,
    bool $reservable,
    int $position,
    array $previousNames = []
): array
{
    $existing = null;
    foreach (array_unique([$name, ...$previousNames]) as $lookupName) {
        $find = $db->prepare('SELECT id, numero FROM mesas WHERE nombre = ? ORDER BY id LIMIT 1');
        $find->bind_param('s', $lookupName);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        $find->close();
        if ($existing !== null) {
            break;
        }
    }

    $x = 8 + (($position % 5) * 18);
    $y = 18 + ((int)floor($position / 5) * 24);
    if ($existing) {
        $id = (int)$existing['id'];
        $number = (int)$existing['numero'];
        $update = $db->prepare('UPDATE mesas SET nombre = ?, tipo = \'mesa\', capacidad = 4, pos_x = ?, pos_y = ?, activo = ?, reservable = ? WHERE id = ?');
        $update->bind_param('sddiii', $name, $x, $y, $active, $reservable, $id);
        $update->execute();
        $update->close();
        return ['id' => $id, 'numero' => $number, 'nombre' => $name];
    }

    $number = 9700 + $position;
    while (true) {
        $check = $db->prepare('SELECT id FROM mesas WHERE numero = ? LIMIT 1');
        $check->bind_param('i', $number);
        $check->execute();
        $occupied = $check->get_result()->fetch_assoc() !== null;
        $check->close();
        if (!$occupied) {
            break;
        }
        $number++;
        mapSeedAssert($number < 9900, 'No hay números de mesa disponibles en el rango local reservado para los fixtures.');
    }

    $insert = $db->prepare(
        'INSERT INTO mesas (numero, nombre, tipo, capacidad, pos_x, pos_y, activo, reservable)
         VALUES (?, ?, \'mesa\', 4, ?, ?, ?, ?)'
    );
    $insert->bind_param('isddii', $number, $name, $x, $y, $active, $reservable);
    $insert->execute();
    $id = (int)$db->insert_id;
    $insert->close();
    return ['id' => $id, 'numero' => $number, 'nombre' => $name];
}

/** @return array<string, mixed> */
function mapSeedReservation(
    mysqli $db,
    string $token,
    string $name,
    string $date,
    string $time,
    string $status = 'confirmada',
    ?string $holdExpiresAt = null
): array {
    $contactType = 'ninguno';
    $contact = null;
    $guests = 2;
    $note = '[MAP TEST] Fixture visual local persistente. No eliminar al terminar la revisión.';
    $origin = 'admin';
    $changedAt = ReservacionConfig::ahora()->format('Y-m-d H:i:s');
    $stmt = $db->prepare(
        'INSERT INTO reservaciones
          (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen,
           estado, request_token, hold_expires_at, estado_changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), fecha = VALUES(fecha),
           hora = VALUES(hora), comensales = VALUES(comensales), nota = VALUES(nota),
           estado = VALUES(estado), hold_expires_at = VALUES(hold_expires_at),
           estado_changed_at = VALUES(estado_changed_at)'
    );
    $stmt->bind_param(
        'sssssissssss',
        $name,
        $contactType,
        $contact,
        $date,
        $time,
        $guests,
        $note,
        $origin,
        $status,
        $token,
        $holdExpiresAt,
        $changedAt
    );
    $stmt->execute();
    $stmt->close();

    $find = $db->prepare('SELECT id FROM reservaciones WHERE request_token = ? LIMIT 1');
    $find->bind_param('s', $token);
    $find->execute();
    $row = $find->get_result()->fetch_assoc();
    $find->close();
    mapSeedAssert($row !== null, 'No se pudo recuperar la reservación fixture ' . $token);
    return ['id' => (int)$row['id'], 'nombre' => $name, 'fecha' => $date, 'hora' => $time, 'estado' => $status, 'token' => $token];
}

function mapSeedAssign(mysqli $db, int $reservationId, int $tableId): void
{
    $stmt = $db->prepare(
        'INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE orden = VALUES(orden)'
    );
    $stmt->bind_param('ii', $reservationId, $tableId);
    $stmt->execute();
    $stmt->close();
}

/** @return array<string, mixed> */
function mapSeedTicket(
    mysqli $db,
    string $name,
    string $openedAt,
    int $tableId,
    ?string $previousName = null
): array
{
    $previousName = $previousName ?? $name;
    $find = $db->prepare('SELECT id FROM tickets WHERE nombre = ? OR nombre = ? ORDER BY id LIMIT 1');
    $find->bind_param('ss', $name, $previousName);
    $find->execute();
    $existing = $find->get_result()->fetch_assoc();
    $find->close();

    $guests = 2;
    if ($existing) {
        $id = (int)$existing['id'];
        $update = $db->prepare(
            "UPDATE tickets SET comensales = ?, nombre = ?, hora_apertura = ?, closed_at = NULL,
             hora_cierre = NULL, estado = 'abierto', reservacion_id = NULL WHERE id = ?"
        );
        $update->bind_param('issi', $guests, $name, $openedAt, $id);
        $update->execute();
        $update->close();
    } else {
        $insert = $db->prepare(
            "INSERT INTO tickets (comensales, nombre, hora_apertura, closed_at, hora_cierre, estado, reservacion_id)
             VALUES (?, ?, ?, NULL, NULL, 'abierto', NULL)"
        );
        $insert->bind_param('iss', $guests, $name, $openedAt);
        $insert->execute();
        $id = (int)$db->insert_id;
        $insert->close();
    }

    $link = $db->prepare(
        'INSERT INTO ticket_mesas (ticket_id, mesa_id, orden) VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE orden = VALUES(orden)'
    );
    $link->bind_param('ii', $id, $tableId);
    $link->execute();
    $link->close();
    return ['id' => $id, 'nombre' => $name, 'hora_apertura' => $openedAt, 'estado' => 'abierto'];
}

function mapSeedTime(DateTimeImmutable $base, int $minutes): string
{
    return $base->modify(($minutes >= 0 ? '+' : '') . $minutes . ' minutes')->format('Y-m-d H:i:s');
}

function mapSeedClock(DateTimeImmutable $base, int $minutes): string
{
    return substr(mapSeedTime($base, $minutes), 11, 8);
}

function mapSeedUrl(string $date, string $time): string
{
    return 'http://localhost:3000/admin/reservaciones/operacion?fecha='
        . rawurlencode($date) . '&hora=' . rawurlencode(substr($time, 0, 5));
}

try {
    $tables = [];
    $index = 0;
    $previousTableNames = [
        'reference' => ['TEST R12', '[MAP TEST] R12:00 referencia'],
        'ticket_projection' => ['TEST TICKET', '[MAP TEST] Ticket T11:00', '[MAP TEST] Ticket proyección local'],
        'pos_free' => ['POS LIBRE', '[MAP TEST] POS-LIBRE'],
        'pos_warning' => ['POS AVISO', '[MAP TEST] POS-WARNING'],
        'pos_block' => ['POS +30', '[MAP TEST] POS-BLOCK'],
        'pos_start' => ['POS INICIO', '[MAP TEST] POS-INICIO'],
        'pos_tolerance' => ['POS TOLERANCIA', '[MAP TEST] POS-TOLERANCIA'],
        'pos_absence' => ['POS AUSENCIA', '[MAP TEST] POS-AUSENCIA'],
        'pos_no_show' => ['POS NOSHOW', '[MAP TEST] POS-NOSHOW'],
        'pos_ticket_recent' => ['POS TICKET', '[MAP TEST] POS-TICKET'],
        'pos_ticket_old' => ['POS TICKET >90', '[MAP TEST] POS-TICKET-ANTIGUO'],
        'pos_ticket_warning' => ['TICKET AVISO', '[MAP TEST] POS-TICKET-WARNING'],
        'pos_ticket_active' => ['TICKET RESERVA', '[MAP TEST] POS-TICKET-ACTIVA'],
        'pos_hold' => ['POS HOLD', '[MAP TEST] POS-HOLD'],
        'unusable' => ['V17 OFF', '[MAP TEST] V17-NO-UTILIZABLE'],
    ];
    foreach ($tableSpecs as $key => [$name, $active, $reservable]) {
        $tables[$key] = mapSeedTable($db, $name, $active, $reservable, $index++, $previousTableNames[$key] ?? []);
    }

    $reservations = [];
    $tickets = [];
    $reservations['reference'] = mapSeedReservation(
        $db,
        'MAPTEST43-RES-REF12',
        '[MAP TEST] V23-V29 reserva referencia 12:00',
        $fechaReferencia,
        '12:00:00'
    );
    mapSeedAssign($db, $reservations['reference']['id'], $tables['reference']['id']);

    $ticketStartTime = new DateTimeImmutable($ticketStart, ReservacionConfig::timezone());
    $ticketName = '[MAP TEST] ticket proyección local';
    $tickets['projection'] = mapSeedTicket(
        $db,
        $ticketName,
        $ticketStart,
        $tables['ticket_projection']['id'],
        '[MAP TEST] ticket proyección T11:00'
    );

    $posDefinitions = [
        ['pos_warning', 'WARNING', 45, 'confirmada'],
        ['pos_block', 'BLOCK', 15, 'confirmada'],
        ['pos_start', 'INICIO', 0, 'confirmada'],
        ['pos_tolerance', 'TOLERANCIA', -10, 'confirmada'],
        ['pos_absence', 'AUSENCIA', -20, 'confirmada'],
        ['pos_no_show', 'NOSHOW', -20, 'no_show'],
        ['pos_ticket_warning', 'TICKET-WARNING', 45, 'confirmada'],
        ['pos_ticket_active', 'TICKET-ACTIVA', -5, 'confirmada'],
    ];
    foreach ($posDefinitions as [$tableKey, $label, $offset, $status]) {
        $reservations[$label] = mapSeedReservation(
            $db,
            'MAPTEST43-RES-' . $label,
            '[MAP TEST] POS-' . $label,
            $fechaHoy,
            mapSeedClock($ahora, $offset),
            $status
        );
        mapSeedAssign($db, $reservations[$label]['id'], $tables[$tableKey]['id']);
    }

    $holdExpiry = $ahora->modify('+' . ReservacionConfig::VIGENCIA_HOLD_MINUTOS . ' minutes')->format('Y-m-d H:i:s');
    $reservations['HOLD'] = mapSeedReservation(
        $db,
        'MAPTEST43-RES-HOLD',
        '[MAP TEST] POS-HOLD pendiente vigente',
        $fechaHoy,
        mapSeedClock($ahora, 45),
        'pendiente_verificacion',
        $holdExpiry
    );
    mapSeedAssign($db, $reservations['HOLD']['id'], $tables['pos_hold']['id']);

    $tickets['recent'] = mapSeedTicket(
        $db,
        '[MAP TEST] POS-TICKET reciente abierto',
        mapSeedTime($ahora, -20),
        $tables['pos_ticket_recent']['id']
    );
    $tickets['old'] = mapSeedTicket(
        $db,
        '[MAP TEST] POS-TICKET-ANTIGUO abierto',
        mapSeedTime($ahora, -120),
        $tables['pos_ticket_old']['id']
    );
    $tickets['warning'] = mapSeedTicket(
        $db,
        '[MAP TEST] POS-TICKET-WARNING abierto',
        mapSeedTime($ahora, -20),
        $tables['pos_ticket_warning']['id']
    );
    $tickets['active_reservation'] = mapSeedTicket(
        $db,
        '[MAP TEST] POS-TICKET-ACTIVA abierto',
        mapSeedTime($ahora, -20),
        $tables['pos_ticket_active']['id']
    );

    $rDate = $fechaReferencia;
    $rTime = new DateTimeImmutable($rDate . ' 12:00:00', ReservacionConfig::timezone());
    $ticketReleaseTime = $ticketStartTime->modify('+'
        . (ReservacionConfig::DURACION_ESTIMADA_TICKET_MINUTOS
            + ReservacionConfig::RETRASO_ESTIMADO_TICKET_MINUTOS) . ' minutes');
    $rCases = [
        ['R-90', $rTime->modify('-90 minutes'), 'verde, Disponible; asignable'],
        ['R-75', $rTime->modify('-75 minutes'), 'verde, Disponible; no asignable por solapamiento'],
        ['R-60', $rTime->modify('-60 minutes'), 'verde + advertencia; no asignable'],
        ['R-45', $rTime->modify('-45 minutes'), 'verde + advertencia; no asignable'],
        ['R-30', $rTime->modify('-30 minutes'), 'azul, Reservación próxima'],
        ['R-1', $rTime->modify('-1 minute'), 'azul, Reservación próxima'],
        ['R', $rTime, 'rojo, Ocupada por reservación'],
        ['R+30', $rTime->modify('+30 minutes'), 'rojo, Ocupada por reservación'],
        ['R+89', $rTime->modify('+89 minutes'), 'rojo, Ocupada por reservación'],
        ['R+90', $rTime->modify('+90 minutes'), 'verde, Disponible; límite final exclusivo'],
    ];

    echo "MAP TEST — fixtures visuales locales persistentes (no se borran al terminar)\n";
    echo 'Base local: ' . $dbName . ' | ambiente: ' . $environment . PHP_EOL;
    echo 'Reloj de ReservacionConfig::ahora(): ' . $ahora->format('Y-m-d H:i:s T') . PHP_EOL;
    echo 'Referencia R: ' . $rDate . ' 12:00:00 | duración: '
        . ReservacionConfig::DURACION_RESERVACION_MINUTOS . " min\n";
    echo 'Ticket de proyección T: ' . $ticketStart . ' | liberación estimada: '
        . $ticketReleaseTime->format('Y-m-d H:i:s') . ' | duración ticket: '
        . ReservacionConfig::DURACION_ESTIMADA_TICKET_MINUTOS . " min\n\n";

    echo "## Reservación de referencia (V23–V29)\n";
    echo 'Mesa #' . $tables['reference']['numero'] . ' (ID ' . $tables['reference']['id'] . ') — '
        . $tables['reference']['nombre'] . PHP_EOL;
    echo 'Reservación ID ' . $reservations['reference']['id'] . ' — '
        . $reservations['reference']['nombre'] . PHP_EOL;
    foreach ($rCases as [$case, $when, $expected]) {
        $url = mapSeedUrl($rDate, $when->format('H:i:s'));
        echo $case . ' | ' . $when->format('Y-m-d H:i:s') . ' | esperado: ' . $expected . ' | ' . $url . PHP_EOL;
    }

    echo "\n## Ticket proyectado (minutos desde apertura T)\n";
    echo 'Mesa #' . $tables['ticket_projection']['numero'] . ' (ID ' . $tables['ticket_projection']['id'] . ') — '
        . $tables['ticket_projection']['nombre'] . PHP_EOL;
    echo 'Ticket ID ' . $tickets['projection']['id'] . ' — ' . $tickets['projection']['nombre']
        . ' | abrió ' . $tickets['projection']['hora_apertura'] . PHP_EOL;
    foreach ([30, 89, 90, 91] as $offset) {
        $when = $ticketStartTime->modify('+' . $offset . ' minutes');
        $expected = $offset < (ReservacionConfig::DURACION_ESTIMADA_TICKET_MINUTOS
            + ReservacionConfig::RETRASO_ESTIMADO_TICKET_MINUTOS)
            ? 'rojo, ticket proyectado activo'
            : 'verde, liberación estimada exclusiva';
        echo 'T+' . $offset . ' | ' . $when->format('Y-m-d H:i:s') . ' | esperado: '
            . $expected . ' | ' . mapSeedUrl($ticketStartTime->format('Y-m-d'), $when->format('H:i:s')) . PHP_EOL;
    }

    echo "\n## POS — fixtures relativos al reloj al ejecutar el seeder\n";
    echo 'POS URL: http://localhost:3000/punto-de-venta' . PHP_EOL;
    echo 'Mapa a hora POS actual: ' . mapSeedUrl($fechaHoy, $horaHoy) . PHP_EOL;
    $posRows = [
        ['POS-LIBRE', 'pos_free', null, null, 'verde, Disponible'],
        ['POS-WARNING', 'pos_warning', 'WARNING', null, 'verde con advertencia'],
        ['POS-BLOCK', 'pos_block', 'BLOCK', null, 'azul, walk-in bloqueado'],
        ['POS-INICIO', 'pos_start', 'INICIO', null, 'azul, esperando cliente'],
        ['POS-TOLERANCIA', 'pos_tolerance', 'TOLERANCIA', null, 'azul, tolerancia'],
        ['POS-AUSENCIA', 'pos_absence', 'AUSENCIA', null, 'ausencia pendiente'],
        ['POS-NOSHOW', 'pos_no_show', 'NOSHOW', null, 'libre tras no-show'],
        ['POS-TICKET', 'pos_ticket_recent', null, 'recent', 'rojo, ticket abierto reciente'],
        ['POS-TICKET-ANTIGUO', 'pos_ticket_old', null, 'old', 'rojo POS aunque supere 90 min'],
        ['POS-TICKET-WARNING', 'pos_ticket_warning', 'TICKET-WARNING', 'warning', 'rojo + advertencia secundaria'],
        ['POS-TICKET-ACTIVA', 'pos_ticket_active', 'TICKET-ACTIVA', 'active_reservation', 'ticket rojo + reserva activa'],
        ['POS-HOLD', 'pos_hold', 'HOLD', null, 'rojo, retención vigente'],
        ['V17-NO-UTILIZABLE', 'unusable', null, null, 'neutro, fuera de servicio'],
    ];
    foreach ($posRows as [$label, $tableKey, $reservationKey, $ticketKey, $expected]) {
        $table = $tables[$tableKey];
        $reservation = $reservationKey !== null ? $reservations[$reservationKey] : null;
        $ticket = $ticketKey !== null ? $tickets[$ticketKey] : null;
        $ids = [];
        if ($reservation !== null) {
            $ids[] = 'res=' . $reservation['id'] . '@' . $reservation['fecha'] . ' ' . $reservation['hora'] . '[' . $reservation['estado'] . ']';
        }
        if ($ticket !== null) {
            $ids[] = 'ticket=' . $ticket['id'] . '@' . $ticket['hora_apertura'];
        }
        echo $label . ' | mesa #' . $table['numero'] . ' ID ' . $table['id']
            . ' | esperado: ' . $expected
            . ($ids !== [] ? ' | ' . implode(' | ', $ids) : ' | sin reserva ni ticket') . PHP_EOL;
    }

    echo "\nNo se ejecutó cleanup, DELETE, TRUNCATE ni rollback. Los fixtures quedan en la base local.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'El seeder se detuvo: ' . $error->getMessage() . PHP_EOL);
    fwrite(STDERR, "No se ejecutó cleanup; cualquier registro que alcanzó a guardarse permanece y se reutiliza al reintentarlo.\n");
    exit(1);
}
