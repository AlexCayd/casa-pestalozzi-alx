<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\ContactoAccesoService;
use Services\DisponibilidadReservacionService;
use Services\Integrations\N8nClient;
use Services\ReservacionNotificacionConfigService;
use Services\ReservacionPublicaService;
use Services\ReservationAccessTokenService;
use Services\ReservationManagementAccessService;
use Services\Reservations\Notifications\ReservationNotificationContract;
use Services\Reservations\Notifications\ReservationNotificationResultService;
use Services\Reservations\Notifications\ReservationReminderService;
use Services\Reservations\Notifications\ScheduleChangeNotificationService;

function communicationsDbAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{token:string,hash:string} */
function tokenFromManagementUrl(string $url): array
{
    $query = [];
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $query);
    $token = (string)($query['access'] ?? '');
    communicationsDbAssert(ReservationAccessTokenService::formatoValido($token), 'el lote no devolvió un token temporal válido');
    return ['token' => $token, 'hash' => ReservationAccessTokenService::hash($token)];
}

function insertCommunicationReservation(
    mysqli $db,
    string $name,
    string $contactType,
    ?string $contact,
    int $guests,
    string $status = 'confirmada',
    ?int $rootId = null
): int {
    $requestToken = bin2hex(random_bytes(32));
    $stmt = $db->prepare(
        'INSERT INTO reservaciones
          (nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen,
           estado, request_token, reemplaza_reservacion_id, estado_changed_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $date = '2037-01-15';
    $time = '13:00:00';
    $note = 'Fixture automatizado sin datos personales';
    $origin = 'landing';
    $changedAt = '2037-01-14 12:00:00';
    $stmt->bind_param(
        'sssssissssis',
        $name,
        $contactType,
        $contact,
        $date,
        $time,
        $guests,
        $note,
        $origin,
        $status,
        $requestToken,
        $rootId,
        $changedAt
    );
    communicationsDbAssert($stmt->execute(), 'no se pudo crear fixture de reservación');
    $id = (int)$db->insert_id;
    $stmt->close();
    return $id;
}

$db = ActiveRecord::getDB();
$reservationIds = [];
$impactId = 0;
$impactItemId = 0;
$retryImpactItemId = 0;
$standaloneContacts = [
    'test.accepted.fixture@example.test',
    'test.failed.fixture@example.test',
];
$oldEnvironment = $_ENV['APP_ENV'] ?? null;
$oldNow = $_ENV['RESERVATION_TEST_NOW'] ?? null;
$oldBaseUrl = $_ENV['RESERVATION_PUBLIC_BASE_URL'] ?? null;
$configResult = $db->query('SELECT recordatorio_dia_anterior_activo, hora_recordatorio, updated_by FROM configuracion_reservaciones WHERE id = 1');
communicationsDbAssert($configResult !== false, 'no se pudo respaldar la configuración');
$oldConfig = $configResult->fetch_assoc() ?: ['recordatorio_dia_anterior_activo' => 0, 'hora_recordatorio' => '18:00:00', 'updated_by' => null];
$configResult->free();

try {
    $_ENV['APP_ENV'] = 'test';
    $_ENV['RESERVATION_PUBLIC_BASE_URL'] = 'http://localhost';
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 17:59:00';

    $saved = ReservacionNotificacionConfigService::guardar([
        'recordatorio_dia_anterior_activo' => true,
        'hora_recordatorio' => '18:00',
    ], null);
    communicationsDbAssert(($saved['ok'] ?? false) === true, 'no se pudo habilitar el recordatorio de fixture');
    $reloaded = ReservacionNotificacionConfigService::obtener();
    communicationsDbAssert(($reloaded['recordatorio_dia_anterior_activo'] ?? false) === true, 'la configuración activa no se recargó');
    communicationsDbAssert(($reloaded['hora_recordatorio'] ?? '') === '18:00', 'la hora persistida no coincide');

    $confirmationPayload = null;
    $confirmationClient = new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static function (string $url, string $secret, string $json) use (&$confirmationPayload): array {
            $confirmationPayload = json_decode($json, true);
            return ['status' => 200, 'body' => '{"ok":true,"accepted":true,"channel":"email"}'];
        }
    );
    $externalConfirmation = ContactoAccesoService::solicitarCodigo(
        'email',
        $standaloneContacts[0],
        $confirmationClient
    );
    communicationsDbAssert(($externalConfirmation['ok'] ?? false) === true, 'confirmación test no aceptó respuesta síncrona');
    communicationsDbAssert(($externalConfirmation['notification_delivery_status'] ?? '') === 'accepted', 'confirmación test no quedó accepted');
    communicationsDbAssert(!array_key_exists('development_confirmation_code', $externalConfirmation), 'test expuso el código development');
    communicationsDbAssert(
        preg_match('/^\d{6}$/', (string)($confirmationPayload['data']['confirmation_code'] ?? '')) === 1
            && !str_contains((string)json_encode($externalConfirmation), (string)$confirmationPayload['data']['confirmation_code']),
        'test no confinó el código al payload privado de n8n'
    );

    $failedConfirmationClient = new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static fn(): array => ['status' => 500, 'body' => '{"ok":false,"accepted":false}']
    );
    $failedConfirmation = ContactoAccesoService::solicitarCodigo(
        'email',
        $standaloneContacts[1],
        $failedConfirmationClient
    );
    communicationsDbAssert(($failedConfirmation['ok'] ?? true) === false && ($failedConfirmation['codigo'] ?? '') === 'OTP_ENVIO_FALLIDO', 'fallo de confirmación test no se informó');
    $failedVerificationResult = $db->query(
        "SELECT COUNT(*) AS total FROM verificaciones_contacto WHERE reservacion_id IS NULL AND contacto = '" . $db->real_escape_string($standaloneContacts[1]) . "'"
    );
    $failedVerificationCount = $failedVerificationResult ? (int)($failedVerificationResult->fetch_assoc()['total'] ?? 0) : 0;
    if ($failedVerificationResult) {
        $failedVerificationResult->free();
    }
    communicationsDbAssert($failedVerificationCount === 1, 'el fallo de transporte revirtió el hash de confirmación');

    $eligibleId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones elegible',
        'email',
        'reservation.fixture@example.test',
        4
    );
    $reservationIds[] = $eligibleId;

    $beforeTime = ReservationReminderService::preparar();
    communicationsDbAssert(($beforeTime['due'] ?? true) === false, 'el recordatorio corrió antes de la hora configurada');

    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:00:00';
    $firstBatch = ReservationReminderService::preparar();
    communicationsDbAssert(($firstBatch['due'] ?? false) === true, 'el recordatorio no quedó listo a la hora configurada');
    communicationsDbAssert(count($firstBatch['notifications'] ?? []) === 1, 'el primer lote debe contener sólo el fixture elegible');
    $first = $firstBatch['notifications'][0];
    communicationsDbAssert(($first['reservation_id'] ?? 0) === $eligibleId, 'el lote preparó una reservación distinta');
    $firstToken = tokenFromManagementUrl((string)($first['data']['management_url'] ?? ''));

    $rowResult = $db->query(
        'SELECT id, reservacion_raiz_id, dedup_key, access_token_hash, notification_delivery_status
         FROM reservacion_recordatorios WHERE reservacion_id = ' . $eligibleId . ' LIMIT 1'
    );
    communicationsDbAssert($rowResult !== false, 'no se pudo leer el recordatorio persistido');
    $firstRow = $rowResult->fetch_assoc() ?: [];
    $rowResult->free();
    communicationsDbAssert((int)($firstRow['reservacion_raiz_id'] ?? 0) === $eligibleId, 'la raíz inicial no coincide');
    communicationsDbAssert(($firstRow['dedup_key'] ?? '') === 'dia_anterior|' . $eligibleId . '|2037-01-15', 'dedup_key no sigue tipo|raíz|fecha');
    communicationsDbAssert(($firstRow['access_token_hash'] ?? '') === $firstToken['hash'], 'la base no guarda el hash esperado');
    communicationsDbAssert(($firstRow['access_token_hash'] ?? '') !== $firstToken['token'], 'la base guardó el token plano');
    communicationsDbAssert(($firstRow['notification_delivery_status'] ?? '') === 'pending', 'el recordatorio debe iniciar pending');

    $management = ReservationManagementAccessService::validarToken($firstToken['token']);
    communicationsDbAssert(is_array($management), 'el acceso de recordatorio no se pudo resolver');
    communicationsDbAssert(($management['source_type'] ?? '') === 'reminder_next_day', 'el acceso perdió la fuente recordatorio');
    communicationsDbAssert(($management['reservation_id'] ?? 0) === $eligibleId, 'el acceso perdió la reservación autorizada');
    communicationsDbAssert(($management['can_modify'] ?? false) === true && ($management['can_cancel'] ?? false) === true, 'reservación ordinaria no conserva ambas capacidades');

    $repeat = ReservationReminderService::preparar();
    communicationsDbAssert(count($repeat['notifications'] ?? []) === 0, 'una segunda ejecución duplicó el recordatorio');

    foreach ($firstBatch['notifications'] ?? [] as $notification) {
        ReservationReminderService::reclamar((int)$notification['source_id'], (int)$notification['attempt'], $notification['contact']['type']);
    }
    $accepted = ReservationNotificationResultService::registrar(
        ReservationNotificationContract::EVENT_REMINDER,
        (int)$firstRow['id'],
        1,
        'email',
        'accepted'
    );
    communicationsDbAssert(($accepted['ok'] ?? false) === true, 'callback accepted no se registró');
    $acceptedAgain = ReservationNotificationResultService::registrar(
        ReservationNotificationContract::EVENT_REMINDER,
        (int)$firstRow['id'],
        1,
        'email',
        'accepted'
    );
    communicationsDbAssert(($acceptedAgain['codigo'] ?? '') === 'NOTIFICACION_CALLBACK_IDEMPOTENTE', 'callback repetido no fue idempotente');

    communicationsDbAssert($db->query("UPDATE reservaciones SET estado = 'reemplazada' WHERE id = {$eligibleId}") !== false, 'no se pudo convertir la raíz en reemplazada');
    $replacementId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones reemplazo',
        'email',
        'reservation.fixture@example.test',
        4,
        'confirmada',
        $eligibleId
    );
    $reservationIds[] = $replacementId;
    $rootRepeat = ReservationReminderService::preparar();
    communicationsDbAssert(count($rootRepeat['notifications'] ?? []) === 0, 'un reemplazo duplicó el recordatorio de la raíz');

    $modifyId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones modificación',
        'email',
        'modify.fixture@example.test',
        2
    );
    $reservationIds[] = $modifyId;
    $modifyBatch = ReservationReminderService::preparar();
    communicationsDbAssert(count($modifyBatch['notifications'] ?? []) === 1, 'no se preparó el acceso de modificación');
    $modifyToken = tokenFromManagementUrl((string)($modifyBatch['notifications'][0]['data']['management_url'] ?? ''));
    $modifyContext = ReservationManagementAccessService::validarToken($modifyToken['token']);
    communicationsDbAssert(is_array($modifyContext) && ($modifyContext['can_modify'] ?? false) === true, 'el acceso no permitió modificar');
    $newDate = '';
    $newTime = '';
    for ($offset = 1; $offset <= 10 && $newTime === ''; $offset++) {
        $candidateDate = (new DateTimeImmutable('2037-01-14', new DateTimeZone('America/Mexico_City')))
            ->modify("+{$offset} day")
            ->format('Y-m-d');
        $availability = DisponibilidadReservacionService::consultar(
            $candidateDate,
            2,
            $modifyId,
            null,
            ['fecha' => '2037-01-15', 'hora' => '13:00:00']
        );
        foreach ((array)($availability['horarios'] ?? []) as $slot) {
            if (($slot['disponible'] ?? false) === true
                && ($candidateDate !== '2037-01-15' || (string)($slot['hora'] ?? '') !== '13:00')
            ) {
                $newDate = $candidateDate;
                $newTime = (string)$slot['hora'];
                break;
            }
        }
    }
    communicationsDbAssert($newDate !== '' && $newTime !== '', 'no se encontró un horario canónico para probar modificación');
    communicationsDbAssert((int)($modifyContext['source_id'] ?? 0) > 0, 'el contexto perdió source_id');
    communicationsDbAssert((int)($modifyContext['reservation_id'] ?? 0) === $modifyId, 'el contexto perdió reservation_id');
    communicationsDbAssert(in_array((string)($modifyContext['source_type'] ?? ''), ['schedule_change', 'reminder_next_day'], true), 'el contexto perdió source_type');
    communicationsDbAssert(\Services\HorarioReservacionService::normalizarHoraSql($newTime) !== '', 'el horario elegido no es canónico');
    $modified = ReservacionPublicaService::crearReemplazoConAccesoTemporal([
        'fecha' => $newDate,
        'hora' => $newTime,
        'personas' => 2,
        'nota' => 'Fixture modificado',
    ], $modifyContext);
    communicationsDbAssert(($modified['ok'] ?? false) === true, 'la modificación temporal falló: ' . json_encode($modified));
    $modifiedReplacementId = (int)($modified['reservation']['id'] ?? 0);
    communicationsDbAssert($modifiedReplacementId > 0, 'la modificación no devolvió reemplazo');
    $reservationIds[] = $modifiedReplacementId;
    communicationsDbAssert(ReservationManagementAccessService::validarToken($modifyToken['token']) === null, 'la modificación no invalidó el acceso');

    $cancelId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones cancelación',
        'email',
        'cancel.fixture@example.test',
        3
    );
    $reservationIds[] = $cancelId;
    $cancelBatch = ReservationReminderService::preparar();
    communicationsDbAssert(count($cancelBatch['notifications'] ?? []) === 1, 'no se preparó el acceso de cancelación');
    $cancelToken = tokenFromManagementUrl((string)($cancelBatch['notifications'][0]['data']['management_url'] ?? ''));
    $cancelContext = ReservationManagementAccessService::validarToken($cancelToken['token']);
    communicationsDbAssert(is_array($cancelContext) && ($cancelContext['can_cancel'] ?? false) === true, 'el acceso no permitió cancelar');
    $cancelled = ReservacionPublicaService::cancelarConAccesoTemporal($cancelId, $cancelContext);
    communicationsDbAssert(($cancelled['ok'] ?? false) === true, 'la cancelación temporal falló');
    communicationsDbAssert(ReservationManagementAccessService::validarToken($cancelToken['token']) === null, 'la cancelación no invalidó el acceso');
    $cancelledAgain = ReservacionPublicaService::cancelar($cancelId, [
        'contacto_tipo' => 'email',
        'contacto' => 'cancel.fixture@example.test',
    ]);
    communicationsDbAssert(($cancelledAgain['ok'] ?? false) === true && ($cancelledAgain['idempotente'] ?? false) === true, 'la cancelación canónica repetida no fue idempotente');

    $largeId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones grupo',
        'telefono',
        '+525500000001',
        13
    );
    $reservationIds[] = $largeId;
    $noContactId = insertCommunicationReservation($db, 'FIXTURE comunicaciones sin contacto', 'ninguno', null, 2);
    $reservationIds[] = $noContactId;
    $cancelledId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones cancelada',
        'email',
        'cancelled.fixture@example.test',
        2,
        'cancelada'
    );
    $reservationIds[] = $cancelledId;
    $affectedId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones afectada',
        'email',
        'affected.fixture@example.test',
        2
    );
    $reservationIds[] = $affectedId;
    $impactDedup = hash('sha256', 'fixture-impact-' . bin2hex(random_bytes(8)));
    $stmt = $db->prepare("INSERT INTO horario_impactos (tipo_origen, estado, dedup_key) VALUES ('fixture', 'pendiente', ?)");
    $stmt->bind_param('s', $impactDedup);
    communicationsDbAssert($stmt->execute(), 'no se pudo crear impacto de fixture');
    $impactId = (int)$db->insert_id;
    $stmt->close();
    communicationsDbAssert($db->query(
        "INSERT INTO horario_impacto_reservaciones (impacto_id, reservacion_id, estado)
         VALUES ({$impactId}, {$affectedId}, 'pendiente_notificacion')"
    ) !== false, 'no se pudo vincular el impacto de fixture');
    $impactItemId = (int)$db->insert_id;

    $acceptedClient = new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static fn(string $url, string $secret, string $json): array => [
            'status' => 202,
            'body' => '{"ok":true,"accepted":true}',
        ]
    );
    $scheduleDispatch = ScheduleChangeNotificationService::dispatchItem($impactItemId, false, $acceptedClient);
    communicationsDbAssert(($scheduleDispatch['accepted'] ?? false) === true, 'el dispatcher no persistió accepted');
    $scheduleAccepted = ReservationNotificationResultService::registrar(
        'reservation.schedule_change',
        $impactItemId,
        (int)($scheduleDispatch['attempt'] ?? 0),
        'email',
        'accepted'
    );
    communicationsDbAssert(($scheduleAccepted['ok'] ?? false) === true, 'callback accepted de cambio de horario falló');
    $scheduleRowResult = $db->query(
        'SELECT estado, notification_delivery_status FROM horario_impacto_reservaciones WHERE id = ' . $impactItemId
    );
    $scheduleRow = $scheduleRowResult ? ($scheduleRowResult->fetch_assoc() ?: []) : [];
    if ($scheduleRowResult) {
        $scheduleRowResult->free();
    }
    communicationsDbAssert(($scheduleRow['estado'] ?? '') === 'notificacion_preparada', 'accepted resolvió indebidamente el dominio');
    communicationsDbAssert(($scheduleRow['notification_delivery_status'] ?? '') === 'accepted', 'accepted no quedó persistido');

    $retryReservationId = insertCommunicationReservation(
        $db,
        'FIXTURE comunicaciones reenvío',
        'email',
        'retry.fixture@example.test',
        2
    );
    $reservationIds[] = $retryReservationId;
    communicationsDbAssert($db->query(
        "INSERT INTO horario_impacto_reservaciones (impacto_id, reservacion_id, estado)
         VALUES ({$impactId}, {$retryReservationId}, 'pendiente_notificacion')"
    ) !== false, 'no se pudo crear el caso de reenvío');
    $retryImpactItemId = (int)$db->insert_id;

    $attemptOnePayload = null;
    $failingClient = new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static function (string $url, string $secret, string $json) use (&$attemptOnePayload): array {
            $attemptOnePayload = json_decode($json, true);
            return ['status' => 500, 'body' => '{"ok":false,"accepted":false}'];
        }
    );
    $attemptOne = ScheduleChangeNotificationService::dispatchItem($retryImpactItemId, false, $failingClient);
    communicationsDbAssert(($attemptOne['accepted'] ?? true) === false && ($attemptOne['attempt'] ?? 0) === 1, 'attempt 1 automático no registró el fallo');
    $attemptOneToken = tokenFromManagementUrl((string)($attemptOnePayload['data']['management_url'] ?? ''));
    communicationsDbAssert(ReservationManagementAccessService::validarToken($attemptOneToken['token']) === null, 'el fallo de attempt 1 no invalidó el acceso anterior');

    $attemptTwoPayload = null;
    $acceptedRetryClient = new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static function (string $url, string $secret, string $json) use (&$attemptTwoPayload): array {
            $attemptTwoPayload = json_decode($json, true);
            return ['status' => 202, 'body' => '{"ok":true,"accepted":true}'];
        }
    );
    $attemptTwo = ScheduleChangeNotificationService::resendManually(
        $impactId,
        $retryImpactItemId,
        $acceptedRetryClient
    );
    communicationsDbAssert(($attemptTwo['accepted'] ?? false) === true && ($attemptTwo['attempt'] ?? 0) === 2, 'attempt 2 manual no fue aceptado');
    $attemptTwoToken = tokenFromManagementUrl((string)($attemptTwoPayload['data']['management_url'] ?? ''));
    communicationsDbAssert($attemptTwoToken['hash'] !== $attemptOneToken['hash'], 'attempt 2 reutilizó el token anterior');
    communicationsDbAssert(ReservationManagementAccessService::validarToken($attemptOneToken['token']) === null, 'attempt 2 reactivó el token anterior');
    communicationsDbAssert(is_array(ReservationManagementAccessService::validarToken($attemptTwoToken['token'])), 'attempt 2 no dejó vigente el token nuevo');

    $staleCallback = ReservationNotificationResultService::registrar(
        ReservationNotificationContract::EVENT_SCHEDULE_CHANGE,
        $retryImpactItemId,
        1,
        'email',
        'accepted'
    );
    communicationsDbAssert(($staleCallback['codigo'] ?? '') === 'NOTIFICACION_CALLBACK_STALE', 'callback de attempt anterior sobrescribió el vigente');
    $attemptTwoFailed = ReservationNotificationResultService::registrar(
        ReservationNotificationContract::EVENT_SCHEDULE_CHANGE,
        $retryImpactItemId,
        2,
        'email',
        'failed'
    );
    communicationsDbAssert(($attemptTwoFailed['ok'] ?? false) === true, 'callback failed de attempt 2 no se persistió');
    communicationsDbAssert(ReservationManagementAccessService::validarToken($attemptTwoToken['token']) === null, 'callback failed no invalidó el token vigente');
    $attemptThree = ScheduleChangeNotificationService::resendManually(
        $impactId,
        $retryImpactItemId,
        $acceptedRetryClient
    );
    communicationsDbAssert(($attemptThree['codigo'] ?? '') === 'AVISO_REENVIO_NO_DISPONIBLE', 'se permitió un attempt 3');
    $reservationStatusResult = $db->query('SELECT estado FROM reservaciones WHERE id = ' . $retryReservationId);
    $reservationStatus = $reservationStatusResult ? (string)($reservationStatusResult->fetch_assoc()['estado'] ?? '') : '';
    if ($reservationStatusResult) {
        $reservationStatusResult->free();
    }
    communicationsDbAssert($reservationStatus === 'confirmada', 'un fallo de transporte cambió la reservación');

    $largeBatch = ReservationReminderService::preparar();
    communicationsDbAssert(count($largeBatch['notifications'] ?? []) === 1, 'exclusiones o grupo grande no respetaron el lote esperado');
    $large = $largeBatch['notifications'][0];
    communicationsDbAssert(($large['reservation_id'] ?? 0) === $largeId, 'el lote no corresponde al grupo grande');
    $largeToken = tokenFromManagementUrl((string)($large['data']['management_url'] ?? ''));
    $largeContext = ReservationManagementAccessService::validarToken($largeToken['token']);
    communicationsDbAssert(is_array($largeContext), 'el acceso del grupo grande no se resolvió');
    communicationsDbAssert(($largeContext['can_modify'] ?? true) === false, 'un grupo de más de 12 pudo modificarse');
    communicationsDbAssert(($largeContext['can_cancel'] ?? false) === true, 'un grupo de más de 12 perdió la cancelación');

    $largeRowResult = $db->query('SELECT id FROM reservacion_recordatorios WHERE reservacion_id = ' . $largeId . ' LIMIT 1');
    $largeRow = $largeRowResult ? ($largeRowResult->fetch_assoc() ?: []) : [];
    if ($largeRowResult) {
        $largeRowResult->free();
    }
    communicationsDbAssert($db->query(
        "UPDATE reservacion_recordatorios SET access_expires_at = '2037-01-14 17:59:00' WHERE id = " . (int)($largeRow['id'] ?? 0)
    ) !== false, 'no se pudo preparar el caso de expiración');
    communicationsDbAssert(ReservationManagementAccessService::validarToken($largeToken['token']) === null, 'un acceso expirado siguió vigente');
    ReservationReminderService::reclamar((int)$largeRow['id'], 1, 'whatsapp');
    $failed = ReservationNotificationResultService::registrar(
        ReservationNotificationContract::EVENT_REMINDER,
        (int)($largeRow['id'] ?? 0),
        1,
        'whatsapp',
        'failed'
    );
    communicationsDbAssert(($failed['ok'] ?? false) === true, 'callback failed no se registró');
    communicationsDbAssert(ReservationManagementAccessService::validarToken($largeToken['token']) === null, 'failed no invalidó el acceso');

    $countResult = $db->query('SELECT COUNT(*) AS total FROM reservacion_recordatorios WHERE reservacion_id IN (' . implode(',', $reservationIds) . ')');
    $total = $countResult ? (int)($countResult->fetch_assoc()['total'] ?? 0) : -1;
    if ($countResult) {
        $countResult->free();
    }
    communicationsDbAssert($total === 4, 'se prepararon recordatorios para estados/contactos/impactos excluidos');

    $disabled = ReservacionNotificacionConfigService::guardar([
        'recordatorio_dia_anterior_activo' => false,
        'hora_recordatorio' => '18:00',
    ], null);
    communicationsDbAssert(($disabled['ok'] ?? false) === true, 'no se pudo deshabilitar el recordatorio');
    $disabledBatch = ReservationReminderService::preparar();
    communicationsDbAssert(($disabledBatch['due'] ?? true) === false && ($disabledBatch['notifications'] ?? []) === [], 'configuración deshabilitada preparó mensajes');

    echo json_encode([
        'ok' => true,
        'configuracion' => 'persistida_y_recargada',
        'confirmacion_test' => ['accepted' => true, 'failed_without_rollback' => true, 'code_hidden' => true],
        'recordatorios_preparados' => $total,
        'deduplicacion_raiz' => true,
        'modificacion' => true,
        'cancelacion' => ['exitosa' => true, 'idempotente' => true],
        'grupo_mayor_12' => ['can_modify' => false, 'can_cancel' => true],
        'expiracion' => true,
        'callbacks' => ['schedule_accepted' => true, 'reminder_accepted' => true, 'failed' => true, 'idempotente' => true],
        'intentos_cambio_horario' => ['automatico' => 1, 'manual' => 2, 'tercero' => false, 'stale' => true],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($reservationIds !== []) {
        $ids = implode(',', array_map('intval', $reservationIds));
        $impactItemIds = array_values(array_filter([$impactItemId, $retryImpactItemId]));
        if ($impactItemIds !== []) {
            $db->query("DELETE FROM buzon_notificaciones WHERE entidad_tipo = 'horario_impacto_reservacion' AND entidad_id IN (" . implode(',', $impactItemIds) . ')');
        }
        $db->query("DELETE FROM horario_impacto_reservaciones WHERE reservacion_id IN ({$ids})");
        if ($impactId > 0) {
            $db->query("DELETE FROM horario_impactos WHERE id = {$impactId}");
        }
        $db->query("DELETE FROM reservacion_recordatorios WHERE reservacion_id IN ({$ids}) OR reservacion_raiz_id IN ({$ids})");
        foreach (array_reverse($reservationIds) as $reservationId) {
            $db->query('DELETE FROM reservaciones WHERE id = ' . (int)$reservationId);
        }
    }
    foreach ($standaloneContacts as $standaloneContact) {
        $db->query(
            "DELETE FROM verificaciones_contacto WHERE reservacion_id IS NULL AND contacto = '" . $db->real_escape_string($standaloneContact) . "'"
        );
    }
    $oldActive = (int)($oldConfig['recordatorio_dia_anterior_activo'] ?? 0);
    $oldTime = $db->real_escape_string((string)($oldConfig['hora_recordatorio'] ?? '18:00:00'));
    $oldUpdatedBy = isset($oldConfig['updated_by']) ? (int)$oldConfig['updated_by'] : null;
    $updatedBySql = $oldUpdatedBy === null ? 'NULL' : (string)$oldUpdatedBy;
    $db->query(
        "UPDATE configuracion_reservaciones
         SET recordatorio_dia_anterior_activo = {$oldActive},
             hora_recordatorio = '{$oldTime}', updated_by = {$updatedBySql}
         WHERE id = 1"
    );
    if ($oldEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $oldEnvironment;
    if ($oldNow === null) unset($_ENV['RESERVATION_TEST_NOW']); else $_ENV['RESERVATION_TEST_NOW'] = $oldNow;
    if ($oldBaseUrl === null) unset($_ENV['RESERVATION_PUBLIC_BASE_URL']); else $_ENV['RESERVATION_PUBLIC_BASE_URL'] = $oldBaseUrl;
}
