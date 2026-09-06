<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Este test solo se ejecuta desde CLI.\n");
}

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Services\DisponibilidadReservacionService;
use Services\ReservacionNotificacionConfigService;
use Services\OperationalNotificationProvider;
use Services\ReservacionPublicaService;
use Services\ReservationAccessTokenService;
use Services\ReservationManagementAccessService;
use Services\ReservationNotificationDispatcher;
use Services\ReservationNotificationResultService;
use Services\ReservationReminderService;
use Services\ReservationConfirmationService;

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
$oldEnvironment = $_ENV['APP_ENV'] ?? null;
$oldNow = $_ENV['RESERVATION_TEST_NOW'] ?? null;
$oldBaseUrl = $_ENV['RESERVATION_PUBLIC_BASE_URL'] ?? null;
$configResult = $db->query('SELECT recordatorio_dia_anterior_activo, hora_recordatorio, updated_by FROM configuracion_reservaciones WHERE id = 1');
communicationsDbAssert($configResult !== false, 'no se pudo respaldar la configuración');
$oldConfig = $configResult->fetch_assoc() ?: ['recordatorio_dia_anterior_activo' => 0, 'hora_recordatorio' => '18:00:00', 'updated_by' => null];
$configResult->free();

try {
    $_ENV['APP_ENV'] = 'testing';
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
    $firstToken = tokenFromManagementUrl((string)$first['management_url']);

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

    $delivered = ReservationNotificationResultService::registrar(
        'reservation.reminder_next_day',
        (int)$firstRow['id'],
        1,
        'delivered'
    );
    communicationsDbAssert(($delivered['ok'] ?? false) === true, 'callback delivered no se registró');
    $deliveredAgain = ReservationNotificationResultService::registrar(
        'reservation.reminder_next_day',
        (int)$firstRow['id'],
        1,
        'delivered'
    );
    communicationsDbAssert(($deliveredAgain['codigo'] ?? '') === 'NOTIFICACION_CALLBACK_IDEMPOTENTE', 'callback repetido no fue idempotente');

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
    $modifyToken = tokenFromManagementUrl((string)$modifyBatch['notifications'][0]['management_url']);
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
    $cancelToken = tokenFromManagementUrl((string)$cancelBatch['notifications'][0]['management_url']);
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

    $acceptedProvider = new class implements OperationalNotificationProvider {
        public function sendReservationsEvent(string $event, array $notifications): array
        {
            return [
                'ok' => $event === 'reservation.schedule_change' && count($notifications) === 1,
                'accepted' => $event === 'reservation.schedule_change' && count($notifications) === 1,
                'codigo' => 'NOTIFICACION_ACEPTADA',
                'http_status' => 202,
            ];
        }
    };
    $scheduleDispatch = ReservationNotificationDispatcher::dispatchScheduleChangeItem($impactItemId, $acceptedProvider);
    communicationsDbAssert(($scheduleDispatch['accepted'] ?? false) === true, 'el dispatcher no persistió accepted');
    $scheduleDelivered = ReservationNotificationResultService::registrar(
        'reservation.schedule_change',
        $impactItemId,
        (int)($scheduleDispatch['attempt'] ?? 0),
        'delivered'
    );
    communicationsDbAssert(($scheduleDelivered['ok'] ?? false) === true, 'callback delivered de cambio de horario falló');
    $scheduleRowResult = $db->query(
        'SELECT estado, notification_delivery_status FROM horario_impacto_reservaciones WHERE id = ' . $impactItemId
    );
    $scheduleRow = $scheduleRowResult ? ($scheduleRowResult->fetch_assoc() ?: []) : [];
    if ($scheduleRowResult) {
        $scheduleRowResult->free();
    }
    communicationsDbAssert(($scheduleRow['estado'] ?? '') === 'notificacion_preparada', 'delivered resolvió indebidamente el dominio');
    communicationsDbAssert(($scheduleRow['notification_delivery_status'] ?? '') === 'delivered', 'delivered no quedó persistido');

    $largeBatch = ReservationReminderService::preparar();
    communicationsDbAssert(count($largeBatch['notifications'] ?? []) === 1, 'exclusiones o grupo grande no respetaron el lote esperado');
    $large = $largeBatch['notifications'][0];
    communicationsDbAssert(($large['reservation_id'] ?? 0) === $largeId, 'el lote no corresponde al grupo grande');
    $largeToken = tokenFromManagementUrl((string)$large['management_url']);
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
    $failed = ReservationNotificationResultService::registrar(
        'reservation.reminder_next_day',
        (int)($largeRow['id'] ?? 0),
        1,
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

    // Confirmaciones comparten persistencia, nunca la dedup de recordatorios.
    $captureProvider = new class implements OperationalNotificationProvider {
        public array $notifications = [];
        public bool $fail = false;
        public function sendReservationsEvent(string $event, array $notifications): array
        {
            $observer = new mysqli($_ENV['DB_HOST'], $_ENV['DB_USER'], $_ENV['DB_PASS'], $_ENV['DB_NAME']);
            try {
                $id = (int)$notifications[0]['source_id'];
                communicationsDbAssert((bool)$observer->query("SELECT id FROM reservacion_recordatorios WHERE id = {$id}")->fetch_assoc(), 'provider recibió intento no confirmado');
                $observer->query('SET innodb_lock_wait_timeout = 1');
                $observer->begin_transaction();
                $observer->query("SELECT id FROM reservacion_recordatorios WHERE id = {$id} FOR UPDATE");
                $observer->rollback();
                communicationsDbAssert(\Services\HorarioConfigLock::adquirir($observer, 0), 'lock global activo en provider');
                \Services\HorarioConfigLock::liberar($observer);
                communicationsDbAssert(\Services\FechaOperacionLock::adquirir($observer, $notifications[0]['reservation_date'], 0), 'lock fecha activo en provider');
                \Services\FechaOperacionLock::liberar($observer, $notifications[0]['reservation_date']);
            } finally {
                $observer->close();
            }
            communicationsDbAssert($event === ReservationConfirmationService::EVENT, 'evento de confirmación incorrecto');
            $this->notifications = array_merge($this->notifications, $notifications);
            return ['ok' => !$this->fail, 'accepted' => !$this->fail];
        }
    };
    foreach (['email' => 'confirmation.fixture@example.test', 'telefono' => '+525500000002'] as $type => $contact) {
        $id = insertCommunicationReservation($db, 'FIXTURE confirmación', $type, $contact, 4);
        $reservationIds[] = $id;
        $dispatch = ReservationNotificationDispatcher::dispatchConfirmation($id, $captureProvider);
        communicationsDbAssert($dispatch['accepted'] ?? false, 'confirmación no aceptada para ' . $type);
        $notification = end($captureProvider->notifications);
        $token = tokenFromManagementUrl($notification['management_url']);
        $context = ReservationManagementAccessService::validarToken($token['token']);
        communicationsDbAssert(($context['source_type'] ?? '') === 'confirmation', 'token no resuelve confirmation');
        communicationsDbAssert($context['can_modify'] && $context['can_cancel'], 'permisos canónicos de confirmación');
        $db->begin_transaction();
        communicationsDbAssert(ReservationManagementAccessService::accesoValidoEnTransaccion($db, $context, 'modify'), 'confirmation no revalida modificación');
        communicationsDbAssert(ReservationManagementAccessService::accesoValidoEnTransaccion($db, $context, 'cancel'), 'confirmation no revalida cancelación');
        communicationsDbAssert(ReservationManagementAccessService::finalizarEnTransaccion($db, $context), 'confirmation no finaliza fuente');
        $db->rollback();

        $source = (int)$dispatch['source_id'];
        $row = $db->query("SELECT * FROM reservacion_recordatorios WHERE id = {$source}")->fetch_assoc();
        communicationsDbAssert($row['tipo'] === 'confirmacion' && $row['dedup_key'] === 'confirmacion|' . $id, 'tipo/dedup de confirmación');
        communicationsDbAssert($row['notification_delivery_status'] === 'accepted' && $row['access_token_hash'] === $token['hash'], 'accepted y hash persistidos');
        $count = count($captureProvider->notifications);
        ReservationNotificationDispatcher::dispatchConfirmation($id, $captureProvider);
        communicationsDbAssert(count($captureProvider->notifications) === $count, 'retry reenvió confirmación');
        communicationsDbAssert((int)$db->query("SELECT COUNT(*) AS n FROM reservacion_recordatorios WHERE dedup_key = 'confirmacion|{$id}'")->fetch_assoc()['n'] === 1, 'dedup creó segunda fila');
        communicationsDbAssert(!ReservationNotificationResultService::registrar('reservation.reminder_next_day', $source, 1, 'delivered')['ok'], 'callback aceptó tipo de otro evento');
        communicationsDbAssert(ReservationNotificationResultService::registrar('reservation.confirmed', $source, 1, 'delivered')['ok'], 'callback de confirmación');
        communicationsDbAssert(ReservationNotificationResultService::registrar('reservation.confirmed', $source, 1, 'failed')['idempotente'] ?? false, 'callback terminal repetido cambió estado');
        communicationsDbAssert($db->query("SELECT notification_delivery_status AS s FROM reservacion_recordatorios WHERE id = {$source}")->fetch_assoc()['s'] === 'delivered', 'delivered no persistido');
    }
    communicationsDbAssert(!ReservationNotificationResultService::registrar('reservation.confirmed', 2147483647, 1, 'delivered')['ok'], 'callback aceptó source inexistente');
    communicationsDbAssert(ReservationManagementAccessService::validarToken(str_repeat('a', 64)) === null, 'token desconocido aceptado');
    foreach ([[$noContactId, null], [$cancelledId, null]] as [$id, $expected]) {
        communicationsDbAssert(ReservationConfirmationService::preparar($id) === $expected, 'confirmación de estado/contacto excluido');
    }
    $invalidId = insertCommunicationReservation($db, 'FIXTURE contacto inválido', 'email', 'invalid', 2);
    $reservationIds[] = $invalidId;
    communicationsDbAssert(ReservationConfirmationService::preparar($invalidId) === null, 'contacto inválido preparado');
    $largeConfirmation = ReservationConfirmationService::preparar($largeId);
    communicationsDbAssert(is_array($largeConfirmation), 'confirmación de grupo no preparada');
    $largeConfirmationToken = tokenFromManagementUrl($largeConfirmation['management_url']);
    $largeConfirmationContext = ReservationManagementAccessService::validarToken($largeConfirmationToken['token']);
    communicationsDbAssert(!$largeConfirmationContext['can_modify'] && $largeConfirmationContext['can_cancel'], 'confirmación grupo >12');
    $source = (int)$largeConfirmation['source_id'];
    communicationsDbAssert($db->query("SELECT notification_delivery_status AS s FROM reservacion_recordatorios WHERE id = {$source}")->fetch_assoc()['s'] === 'pending', 'preparación inicia pending');
    $db->query("UPDATE reservacion_recordatorios SET access_expires_at = '2037-01-14 17:59:00' WHERE id = {$source}");
    communicationsDbAssert(ReservationManagementAccessService::validarToken($largeConfirmationToken['token']) === null, 'confirmation expirado aceptado');
    communicationsDbAssert(ReservationNotificationResultService::registrar('reservation.confirmed', $source, 1, 'failed')['ok'], 'failed de confirmación');

    $failedId = insertCommunicationReservation($db, 'FIXTURE fallo externo', 'email', 'failed.fixture@example.test', 2);
    $reservationIds[] = $failedId;
    $captureProvider->fail = true;
    $failure = ReservationNotificationDispatcher::dispatchConfirmation($failedId, $captureProvider);
    communicationsDbAssert(!($failure['accepted'] ?? true), 'provider failed aceptado');
    $source = (int)$failure['source_id'];
    $row = $db->query("SELECT notification_delivery_status, access_invalidated_at FROM reservacion_recordatorios WHERE id = {$source}")->fetch_assoc();
    communicationsDbAssert($row['notification_delivery_status'] === 'failed' && $row['access_invalidated_at'] !== null, 'fallo no invalidó acceso');
    communicationsDbAssert($db->query("SELECT estado FROM reservaciones WHERE id = {$failedId}")->fetch_assoc()['estado'] === 'confirmada', 'fallo externo alteró dominio');

    foreach (['pending', 'accepted'] as $status) {
        $id = insertCommunicationReservation($db, 'FIXTURE reconciliación', 'email', 'stale.fixture@example.test', 2);
        $reservationIds[] = $id;
        $prepared = ReservationConfirmationService::preparar($id);
        $source = (int)$prepared['source_id'];
        $db->query("UPDATE reservacion_recordatorios SET notification_delivery_status = '{$status}', notification_delivery_updated_at = DATE_SUB(NOW(), INTERVAL 6 MINUTE) WHERE id = {$source}");
        $off = ReservationReminderService::preparar();
        communicationsDbAssert(!$off['due'] && $off['notifications'] === [], 'D-1 apagado preparó lote');
        $row = $db->query("SELECT notification_delivery_status, access_invalidated_at FROM reservacion_recordatorios WHERE id = {$source}")->fetch_assoc();
        communicationsDbAssert($row['notification_delivery_status'] === 'failed' && $row['access_invalidated_at'] !== null, 'reconciliación apagada omitió confirmación');
    }

    echo json_encode([
        'ok' => true,
        'configuracion' => 'persistida_y_recargada',
        'recordatorios_preparados' => $total,
        'confirmaciones' => ['contactos' => true, 'dedup' => true, 'post_commit' => true, 'fallos' => true, 'reconciliacion_apagada' => true],
        'deduplicacion_raiz' => true,
        'modificacion' => true,
        'cancelacion' => ['exitosa' => true, 'idempotente' => true],
        'grupo_mayor_12' => ['can_modify' => false, 'can_cancel' => true],
        'expiracion' => true,
        'callbacks' => ['schedule_delivered' => true, 'reminder_delivered' => true, 'failed' => true, 'idempotente' => true],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    if ($reservationIds !== []) {
        $ids = implode(',', array_map('intval', $reservationIds));
        $db->query("DELETE FROM buzon_notificaciones WHERE entidad_tipo = 'horario_impacto_reservacion' AND entidad_id = {$impactItemId}");
        $db->query("DELETE FROM horario_impacto_reservaciones WHERE reservacion_id IN ({$ids})");
        if ($impactId > 0) {
            $db->query("DELETE FROM horario_impactos WHERE id = {$impactId}");
        }
        $db->query("DELETE FROM reservacion_recordatorios WHERE reservacion_id IN ({$ids}) OR reservacion_raiz_id IN ({$ids})");
        foreach (array_reverse($reservationIds) as $reservationId) {
            $db->query('DELETE FROM reservaciones WHERE id = ' . (int)$reservationId);
        }
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
