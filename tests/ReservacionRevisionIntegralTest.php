<?php

/**
 * Regresión integral de disponibilidad, edición, operación y asignación.
 *
 * Sólo utiliza una base de datos desechable y no modifica el esquema del
 * proyecto ni los datos de desarrollo.
 */

declare(strict_types=1);

use Controllers\AdminReservacionController;
use Controllers\ReservacionController;
use Controllers\ReservacionOperacionController;
use Dotenv\Dotenv;
use Model\ActiveRecord;
use MVC\Router;
use Services\AsignacionMesasService;
use Services\DisponibilidadReservacionService;
use Services\ReservationClientSession;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;
use Services\ReservacionService;
use Services\ReservacionVigenciaService;

require __DIR__ . '/../vendor/autoload.php';
Dotenv::createImmutable(__DIR__ . '/../includes')->safeLoad();
date_default_timezone_set(ReservacionConfig::TIMEZONE);
ini_set('session.save_path', __DIR__ . '/.sessions');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$databaseName = 'casa_pestalozzi_revision_integral_test';
$tests = 0;
$failures = [];

function riAssert(string $name, bool $condition): void
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $name;
    }
}

function riSame(string $name, mixed $actual, mixed $expected): void
{
    riAssert(
        $name . ': esperado ' . var_export($expected, true)
            . ', recibido ' . var_export($actual, true),
        $actual === $expected
    );
}

function riSqlFile(mysqli $db, string $path): void
{
    $sql = file_get_contents($path);
    if (!is_string($sql)) {
        throw new RuntimeException('No fue posible leer ' . $path);
    }
    $db->multi_query($sql);
    do {
        if ($result = $db->store_result()) {
            $result->free();
        }
    } while ($db->more_results() && $db->next_result());
}

function riClock(mysqli $db, string $now): void
{
    $_ENV['APP_ENV'] = 'testing';
    $_ENV['RESERVATION_TEST_NOW'] = $now;
    putenv('APP_ENV=testing');
    putenv('RESERVATION_TEST_NOW=' . $now);
    $db->query("SET timestamp = UNIX_TIMESTAMP('" . $db->real_escape_string($now) . "')");
}

function riTableId(mysqli $db, int $number): int
{
    $row = $db->query("SELECT id FROM mesas WHERE numero={$number} LIMIT 1")->fetch_assoc();
    return (int)($row['id'] ?? 0);
}

function riReservation(
    mysqli $db,
    string $date,
    string $time,
    int $people = 2,
    string $state = 'confirmada',
    string $contactType = 'email',
    string $contact = 'revision@example.test',
    array $tableNumbers = []
): int {
    $suffix = bin2hex(random_bytes(4));
    $name = $db->real_escape_string('REVISION-' . $suffix);
    $type = $db->real_escape_string($contactType);
    $contactSql = $db->real_escape_string($contact);
    $token = $db->real_escape_string('revision-' . $suffix . '-' . bin2hex(random_bytes(4)));
    $confirmedAt = $state === 'confirmada' ? 'NOW()' : 'NULL';
    $db->query(
        "INSERT INTO reservaciones
            (nombre, contacto_tipo, contacto, fecha, hora, comensales, estado,
             request_token, confirmed_at)
         VALUES
            ('{$name}', '{$type}', '{$contactSql}', '{$date}', '{$time}',
             {$people}, '{$state}', '{$token}', {$confirmedAt})"
    );
    $reservationId = (int)$db->insert_id;
    foreach (array_values($tableNumbers) as $index => $number) {
        $tableId = riTableId($db, (int)$number);
        $order = $index + 1;
        $db->query(
            "INSERT INTO reservacion_mesas (reservacion_id, mesa_id, orden)
             VALUES ({$reservationId}, {$tableId}, {$order})"
        );
    }
    return $reservationId;
}

function riTicket(mysqli $db, array $tableNumbers, ?string $openedAt = null): int
{
    $openedSql = $openedAt !== null
        ? "'" . $db->real_escape_string($openedAt) . "'"
        : 'NOW()';
    $db->query(
        "INSERT INTO tickets (comensales, nombre, estado, hora_apertura)
         VALUES (2, 'REVISION-TICKET', 'abierto', {$openedSql})"
    );
    $ticketId = (int)$db->insert_id;
    foreach (array_values($tableNumbers) as $index => $number) {
        $tableId = riTableId($db, (int)$number);
        $order = $index + 1;
        $db->query(
            "INSERT INTO ticket_mesas (ticket_id, mesa_id, orden)
             VALUES ({$ticketId}, {$tableId}, {$order})"
        );
    }
    return $ticketId;
}

function riClearTickets(mysqli $db): void
{
    $db->query("DELETE FROM tickets WHERE nombre='REVISION-TICKET'");
}

function riVersion(mysqli $db, int $reservationId): string
{
    $row = $db->query(
        "SELECT created_at, updated_at
         FROM reservaciones
         WHERE id={$reservationId}"
    )->fetch_assoc();
    $ids = [];
    $result = $db->query(
        "SELECT mesa_id
         FROM reservacion_mesas
         WHERE reservacion_id={$reservationId}
         ORDER BY orden"
    );
    while ($item = $result->fetch_assoc()) {
        $ids[] = (int)$item['mesa_id'];
    }
    $result->free();

    return hash(
        'sha256',
        (string)($row['updated_at'] ?: $row['created_at']) . '|' . implode(',', $ids)
    );
}

/**
 * @return array{status: int, body: array<string, mixed>, raw: string}
 */
function riControllerResponse(callable $callback): array
{
    http_response_code(200);
    ob_start();
    $callback();
    $raw = (string)ob_get_clean();
    $decoded = json_decode($raw, true);

    return [
        'status' => http_response_code(),
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $raw,
    ];
}

function riAdminPayload(
    int $id,
    string $date,
    string $time,
    int $people,
    string $contactType,
    string $contact
): array {
    return [
        'id' => $id,
        'nombre' => 'Cliente revisión administrativa',
        'contacto_tipo' => $contactType,
        'contacto' => $contact,
        'fecha' => $date,
        'hora' => $time,
        'comensales' => $people,
        'comentario_admin' => 'Revisión integral',
        'response_format' => 'json',
        'return_to' => '/admin/reservations/show?id=' . $id,
    ];
}

try {
    $db = mysqli_connect(
        (string)($_ENV['DB_HOST'] ?? 'localhost'),
        (string)($_ENV['DB_USER'] ?? ''),
        (string)($_ENV['DB_PASS'] ?? '')
    );
    if (!$db) {
        throw new RuntimeException('No fue posible conectar con MySQL.');
    }
    $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
    $db->query(
        "CREATE DATABASE `{$databaseName}`
         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $db->select_db($databaseName);
    $db->query("SET time_zone = '-06:00'");
    riClock($db, '2026-12-04 12:00:00');
    riSqlFile($db, __DIR__ . '/../database/ddl.sql');
    riSqlFile($db, __DIR__ . '/../database/dml.sql');
    $db->query('DELETE FROM feedback_tokens');
    $db->query('DELETE FROM ticket_pagos');
    $db->query('DELETE FROM ticket_items');
    $db->query('DELETE FROM ticket_mesas');
    $db->query('DELETE FROM tickets');
    $db->query('DELETE FROM verificaciones_contacto');
    $db->query('DELETE FROM reservacion_mesas');
    $db->query('DELETE FROM reservaciones');
    ActiveRecord::setDB($db);

    // Disponibilidad pública de 4 a 12 y límite real de tres mesas.
    foreach ([4 => 1, 5 => 2, 8 => 2, 10 => 3, 11 => 3, 12 => 3] as $people => $tableCount) {
        $result = DisponibilidadReservacionService::evaluarHorario(
            '2026-12-04',
            '18:00',
            $people
        );
        riAssert("{$people} personas tienen disponibilidad", (bool)($result['ok'] ?? false));
        riSame(
            "{$people} personas usan {$tableCount} mesa(s)",
            count($result['mesa_ids'] ?? []),
            $tableCount
        );
    }

    $oneLargeTable = [(object)[
        'id' => 90,
        'numero' => 90,
        'tipo' => 'mesa',
        'capacidad' => 12,
        'activo' => 1,
        'reservable' => 1,
    ]];
    riSame(
        'grupos de diez requieren un trío aunque exista una mesa grande',
        count(AsignacionMesasService::seleccionarMesasPublicas($oneLargeTable, 10)),
        0
    );
    $authorizedPair = [
        (object)['id' => 2, 'numero' => 2, 'capacidad' => 4],
        (object)['id' => 4, 'numero' => 4, 'capacidad' => 4],
    ];
    riSame(
        'combinación pública de dos mesas',
        count(AsignacionMesasService::seleccionarMesasPublicas($authorizedPair, 5)),
        2
    );
    $authorizedTriple = [
        ...$authorizedPair,
        (object)['id' => 5, 'numero' => 5, 'capacidad' => 4],
    ];
    riSame(
        'combinación pública de tres mesas',
        count(AsignacionMesasService::seleccionarMesasPublicas($authorizedTriple, 10)),
        3
    );
    riTicket($db, [2, 5, 8], '2026-12-04 17:00:00');
    $ticketBlocked = DisponibilidadReservacionService::evaluarHorario(
        '2026-12-04',
        '18:00',
        12
    );
    riSame(
        'tickets abiertos invalidan todas las combinaciones de tres mesas',
        $ticketBlocked['codigo'] ?? '',
        DisponibilidadReservacionService::SIN_DISPONIBILIDAD
    );
    riClearTickets($db);
    riSame(
        'capacidad realmente insuficiente no genera cuatro mesas',
        AsignacionMesasService::seleccionarMesasPublicas($authorizedPair, 12),
        []
    );

    // Edición administrativa: selector, contacto, horario, capacidad y HTTP.
    $adminId = riReservation(
        $db,
        '2026-12-05',
        '17:00:00',
        2,
        'confirmada',
        'email',
        'admin.revision@example.test',
        [1]
    );
    $adminEdit = ReservacionService::actualizarDatos(
        $adminId,
        riAdminPayload(
            $adminId,
            '2026-12-05',
            '17:30',
            4,
            'telefono',
            '+52 55 1234 5678'
        ),
        1
    );
    riAssert(
        'edición administrativa correcta ' . json_encode($adminEdit, JSON_UNESCAPED_UNICODE),
        (bool)($adminEdit['ok'] ?? false)
    );
    $adminRow = $db->query(
        "SELECT contacto_tipo, contacto, hora, comensales
         FROM reservaciones WHERE id={$adminId}"
    )->fetch_assoc();
    riSame('selector persiste teléfono', $adminRow['contacto_tipo'] ?? '', 'telefono');
    riSame('teléfono queda normalizado', $adminRow['contacto'] ?? '', '+525512345678');
    riSame('hora editada queda persistida', $adminRow['hora'] ?? '', '17:30:00');
    riSame('comensales editados quedan persistidos', (int)($adminRow['comensales'] ?? 0), 4);

    $invalidContact = ReservacionService::actualizarDatos(
        $adminId,
        riAdminPayload(
            $adminId,
            '2026-12-05',
            '17:30',
            4,
            'telefono',
            'correo@example.test'
        ),
        1
    );
    riSame(
        'backend valida contacto según selector',
        $invalidContact['field_codes']['contacto'][0] ?? '',
        'CONTACTO_INVALIDO'
    );

    riClock($db, '2026-12-05 16:30:00');
    riTicket($db, range(1, 11), '2026-12-05 16:30:00');
    $withoutCapacity = ReservacionService::actualizarDatos(
        $adminId,
        riAdminPayload(
            $adminId,
            '2026-12-05',
            '18:00',
            5,
            'telefono',
            '+52 55 1234 5678'
        ),
        1
    );
    riSame(
        'edición administrativa rechaza capacidad ocupada por tickets',
        $withoutCapacity['codigo'] ?? '',
        ReservacionService::SIN_DISPONIBILIDAD
    );
    riAssert(
        'edición sin capacidad devuelve errores por campo',
        !empty($withoutCapacity['errors']['hora'])
            && !empty($withoutCapacity['errors']['comensales'])
    );

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_POST = riAdminPayload(
        $adminId,
        '2026-12-05',
        '18:00',
        5,
        'telefono',
        '+52 55 1234 5678'
    );
    $adminCapacityHttp = riControllerResponse(
        static fn() => AdminReservacionController::update(new Router())
    );
    riSame('HTTP edición sin capacidad responde 409', $adminCapacityHttp['status'], 409);
    riSame(
        'HTTP edición expone código de capacidad',
        $adminCapacityHttp['body']['codigo'] ?? '',
        ReservacionService::SIN_DISPONIBILIDAD
    );
    riClearTickets($db);
    riClock($db, '2026-12-04 12:00:00');

    $_POST = riAdminPayload(
        $adminId,
        '2026-12-05',
        '18:00',
        5,
        'email',
        'cambio.admin@example.test'
    );
    $adminSuccessHttp = riControllerResponse(
        static fn() => AdminReservacionController::update(new Router())
    );
    riSame('HTTP edición válida responde 200', $adminSuccessHttp['status'], 200);
    riAssert(
        'HTTP edición válida confirma visualmente',
        ($adminSuccessHttp['body']['success'] ?? false) === true
            && str_contains(
                (string)($adminSuccessHttp['body']['mensaje'] ?? ''),
                'guardados'
            )
    );

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [
        'fecha' => '2026-12-05',
        'personas' => '5',
        'reservation_id' => (string)$adminId,
    ];
    $adminAvailabilityHttp = riControllerResponse(
        static fn() => AdminReservacionController::disponibilidad()
    );
    riSame('HTTP disponibilidad administrativa responde 200', $adminAvailabilityHttp['status'], 200);
    riAssert(
        'HTTP disponibilidad administrativa entrega slots con capacidad',
        ($adminAvailabilityHttp['body']['disponible'] ?? false) === true
    );

    $db->query("UPDATE reservaciones SET estado='completada' WHERE id={$adminId}");
    $finalEdit = ReservacionService::actualizarDatos(
        $adminId,
        riAdminPayload(
            $adminId,
            '2026-12-05',
            '18:00',
            5,
            'email',
            'cambio.admin@example.test'
        ),
        1
    );
    riSame(
        'reservación final no es editable',
        $finalEdit['codigo'] ?? '',
        ReservacionService::ESTADO_NO_EDITABLE
    );

    // Modificación pública: exclusión propia, revalidación final y HTTP.
    $publicContact = 'public.revision@example.test';
    $publicId = riReservation(
        $db,
        '2026-12-06',
        '15:00:00',
        2,
        'confirmada',
        'email',
        $publicContact,
        [1]
    );
    ReservationClientSession::crear('email', $publicContact);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_POST = [
        'reservacion_id' => $publicId,
        'nombre' => 'Cliente público modificado',
        'fecha' => '2026-12-06',
        'hora' => '16:00',
        'personas' => 5,
        'notas' => 'Cambio público',
    ];
    $publicSuccessHttp = riControllerResponse(
        static fn() => ReservacionController::modificarPublica(new Router())
    );
    riSame('HTTP modificación pública válida responde 200', $publicSuccessHttp['status'], 200);
    riSame(
        'HTTP modificación pública confirma el cambio',
        $publicSuccessHttp['body']['codigo'] ?? '',
        ReservacionPublicaService::RESERVACION_MODIFICADA
    );

    riClock($db, '2026-12-06 15:00:00');
    riTicket($db, range(1, 11), '2026-12-06 15:00:00');
    $_POST['hora'] = '17:00';
    $_POST['personas'] = 8;
    $publicBlockedHttp = riControllerResponse(
        static fn() => ReservacionController::modificarPublica(new Router())
    );
    riSame('HTTP modificación pública sin capacidad responde 409', $publicBlockedHttp['status'], 409);
    riAssert(
        'HTTP modificación pública devuelve errores de hora y personas',
        !empty($publicBlockedHttp['body']['errors']['hora'])
            && !empty($publicBlockedHttp['body']['errors']['personas'])
    );
    riClearTickets($db);
    riClock($db, '2026-12-04 12:00:00');

    // La lista operativa conserva sólo el bloque anterior, actual y posteriores.
    $operational = ReservacionVigenciaService::filtrarPendientesOperacion(
        [
            ['id' => 1, 'hora' => '13:00:00', 'estado' => 'confirmada'],
            ['id' => 2, 'hora' => '13:30:00', 'estado' => 'confirmada'],
            ['id' => 3, 'hora' => '14:00:00', 'estado' => 'llego'],
            ['id' => 4, 'hora' => '14:30:00', 'estado' => 'confirmada'],
            ['id' => 5, 'hora' => '18:00:00', 'estado' => 'en_curso'],
            ['id' => 6, 'hora' => '14:30:00', 'estado' => 'no_show'],
            ['id' => 7, 'hora' => '14:30:00', 'estado' => 'cancelada'],
            ['id' => 8, 'hora' => '22:00:00', 'estado' => 'confirmada'],
        ],
        '2026-12-04',
        // Simula la respuesta orientada al cliente, que ya filtró las horas
        // vencidas. El servicio debe reconstruir la jornada operativa.
        ['14:30', '15:00', '15:30', '16:00', '18:00'],
        new DateTimeImmutable('2026-12-04 14:10:00', ReservacionConfig::timezone())
    );
    riSame(
        'lista operativa incluye anterior, actual y posteriores hasta cierre',
        array_column($operational, 'id'),
        [2, 3, 4, 5]
    );

    $httpAssignId = riReservation(
        $db,
        '2026-12-04',
        '18:00:00',
        2,
        'confirmada',
        'email',
        'http.assign.revision@example.test',
        [7]
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'reservation_id' => $httpAssignId,
        'mesa_ids' => [riTableId($db, 8)],
    ];
    $incompleteAssignmentHttp = riControllerResponse(
        static fn() => ReservacionOperacionController::apiAssignTables()
    );
    riSame(
        'HTTP asignación incompleta responde 422',
        $incompleteAssignmentHttp['status'],
        422
    );
    riSame(
        'HTTP asignación incompleta conserva código específico',
        $incompleteAssignmentHttp['body']['codigo'] ?? '',
        AsignacionMesasService::DATOS_INCOMPLETOS
    );

    $httpAssignVersion = riVersion($db, $httpAssignId);
    $_POST = [
        'reservation_id' => $httpAssignId,
        'fecha' => '2026-12-04',
        'hora' => '18:00',
        'version_esperada' => $httpAssignVersion,
        'mesa_ids_actuales_presentes' => '1',
        'mesa_ids_actuales' => [riTableId($db, 7)],
        'mesa_ids' => [riTableId($db, 8)],
    ];
    $validAssignmentHttp = riControllerResponse(
        static fn() => ReservacionOperacionController::apiAssignTables()
    );
    riSame('HTTP reasignación válida responde 200', $validAssignmentHttp['status'], 200);
    riSame(
        'HTTP reasignación válida conserva código específico',
        $validAssignmentHttp['body']['codigo'] ?? '',
        AsignacionMesasService::ASIGNACION_GUARDADA
    );

    $_POST['mesa_ids_actuales'] = [riTableId($db, 8)];
    $_POST['mesa_ids'] = [riTableId($db, 9)];
    $staleAssignmentHttp = riControllerResponse(
        static fn() => ReservacionOperacionController::apiAssignTables()
    );
    riSame('HTTP versión desactualizada responde 409', $staleAssignmentHttp['status'], 409);
    riSame(
        'HTTP versión desactualizada no se confunde con mesa ocupada',
        $staleAssignmentHttp['body']['codigo'] ?? '',
        AsignacionMesasService::VERSION_DESACTUALIZADA
    );

    // Reasignación: contexto completo, versión, ocupación y tickets aceptados.
    $assignId = riReservation(
        $db,
        '2026-12-04',
        '20:00:00',
        2,
        'confirmada',
        'email',
        'assign.revision@example.test',
        [1]
    );
    $table1 = riTableId($db, 1);
    $table2 = riTableId($db, 2);
    $table3 = riTableId($db, 3);
    $table4 = riTableId($db, 4);
    $version1 = riVersion($db, $assignId);
    $baseContext = [
        'validar_contexto' => true,
        'contexto_completo' => true,
        'fecha_esperada' => '2026-12-04',
        'hora_esperada' => '20:00',
        'mesa_ids_actuales' => [$table1],
        'version_esperada' => $version1,
        'usuario_id' => 1,
    ];
    $validAssignment = AsignacionMesasService::asignarManual(
        $assignId,
        [$table2],
        false,
        true,
        $baseContext
    );
    riSame(
        'reasignación válida se guarda',
        $validAssignment['codigo'] ?? '',
        AsignacionMesasService::ASIGNACION_GUARDADA
    );

    $staleAssignment = AsignacionMesasService::asignarManual(
        $assignId,
        [$table4],
        false,
        true,
        $baseContext + ['mesa_ids_actuales' => [$table2]]
    );
    riSame(
        'versión desactualizada se distingue de concurrencia real',
        $staleAssignment['codigo'] ?? '',
        AsignacionMesasService::VERSION_DESACTUALIZADA
    );
    $incompleteAssignment = AsignacionMesasService::asignarManual(
        $assignId,
        [$table4],
        false,
        true,
        ['validar_contexto' => true, 'contexto_completo' => false]
    );
    riSame(
        'contexto incompleto se distingue',
        $incompleteAssignment['codigo'] ?? '',
        AsignacionMesasService::DATOS_INCOMPLETOS
    );

    $occupiedId = riReservation(
        $db,
        '2026-12-04',
        '20:00:00',
        2,
        'confirmada',
        'email',
        'occupied.revision@example.test',
        [3]
    );
    $version2 = riVersion($db, $assignId);
    $currentContext = [
        'validar_contexto' => true,
        'contexto_completo' => true,
        'fecha_esperada' => '2026-12-04',
        'hora_esperada' => '20:00',
        'mesa_ids_actuales' => [$table2],
        'version_esperada' => $version2,
    ];
    $occupiedAssignment = AsignacionMesasService::asignarManual(
        $assignId,
        [$table3],
        false,
        true,
        $currentContext
    );
    riSame(
        'mesa ocupada se distingue',
        $occupiedAssignment['codigo'] ?? '',
        AsignacionMesasService::MESA_OCUPADA
    );
    $db->query("UPDATE reservaciones SET estado='cancelada' WHERE id={$occupiedId}");

    $ticketId = riTicket($db, [4], '2026-12-04 19:00:00');
    $unauthorizedOverlay = AsignacionMesasService::asignarManual(
        $assignId,
        [$table4],
        false,
        true,
        $currentContext
    );
    riSame(
        'superposición fuera del mapa se rechaza',
        $unauthorizedOverlay['codigo'] ?? '',
        AsignacionMesasService::SUPERPOSICION_NO_AUTORIZADA
    );
    $mapContext = $currentContext + ['permitir_superposicion_ticket_abierto' => true];
    $ticketPreview = AsignacionMesasService::asignarManual(
        $assignId,
        [$table4],
        false,
        true,
        $mapContext
    );
    riSame(
        'mapa solicita confirmación explícita por ticket',
        $ticketPreview['codigo'] ?? '',
        AsignacionMesasService::CONFLICTO_TICKETS_ABIERTOS
    );
    $ticketAccepted = AsignacionMesasService::asignarManual(
        $assignId,
        [$table4],
        false,
        true,
        $mapContext + [
            'ticket_ids_aceptados' => [$ticketId],
            'conflicto_token' => (string)($ticketPreview['conflicto_token'] ?? ''),
        ]
    );
    riSame(
        'mapa permite superposición confirmada',
        $ticketAccepted['codigo'] ?? '',
        AsignacionMesasService::ASIGNACION_GUARDADA
    );

    $version3 = riVersion($db, $assignId);
    $ticket5 = riTicket($db, [5], '2026-12-04 19:00:00');
    $table5 = riTableId($db, 5);
    $realConflictContext = [
        'validar_contexto' => true,
        'contexto_completo' => true,
        'fecha_esperada' => '2026-12-04',
        'hora_esperada' => '20:00',
        'mesa_ids_actuales' => [$table4],
        'version_esperada' => $version3,
        'permitir_superposicion_ticket_abierto' => true,
    ];
    $realConflictPreview = AsignacionMesasService::asignarManual(
        $assignId,
        [$table5],
        false,
        true,
        $realConflictContext
    );
    $db->query(
        'UPDATE ticket_mesas SET mesa_id=' . riTableId($db, 6)
            . " WHERE ticket_id={$ticket5}"
    );
    $realConflict = AsignacionMesasService::asignarManual(
        $assignId,
        [$table5],
        false,
        true,
        $realConflictContext + [
            'ticket_ids_aceptados' => [$ticket5],
            'conflicto_token' => (string)($realConflictPreview['conflicto_token'] ?? ''),
        ]
    );
    riSame(
        'cambio real de ocupación se reporta como concurrencia',
        $realConflict['codigo'] ?? '',
        AsignacionMesasService::CONFLICTO_CONCURRENTE
    );
    riClearTickets($db);

    $db->query("UPDATE reservaciones SET estado='completada' WHERE id={$assignId}");
    $finalAssignment = AsignacionMesasService::asignarManual($assignId, [$table1]);
    riSame(
        'reservación finalizada no permite reasignación',
        $finalAssignment['codigo'] ?? '',
        AsignacionMesasService::RESERVACION_NO_EDITABLE
    );
} catch (Throwable $error) {
    $failures[] = 'Excepción no controlada: ' . $error->getMessage();
} finally {
    ReservationClientSession::cerrar();
    if (isset($db) && $db instanceof mysqli) {
        try {
            $db->query("DROP DATABASE IF EXISTS `{$databaseName}`");
        } catch (Throwable $ignored) {
        }
        $db->close();
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "OK: {$tests} comprobaciones de revisión integral." . PHP_EOL;
