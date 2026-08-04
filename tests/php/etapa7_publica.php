<?php

declare(strict_types=1);

/**
 * Pruebas deterministas del acceso por contacto y de la gestión pública de
 * reservaciones. Todos los fixtures se revierten al finalizar.
 */

$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
putenv('APP_ENV=testing');
$_ENV['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
$_SERVER['RESERVATION_TEST_NOW'] = '2026-11-01 12:00:00';
putenv('RESERVATION_TEST_NOW=2026-11-01 12:00:00');
$_ENV['CONTACT_OTP_PREVIEW'] = 'true';
putenv('CONTACT_OTP_PREVIEW=true');
ini_set('session.save_path', dirname(__DIR__));

require dirname(__DIR__, 2) . '/includes/app.php';

use Model\ActiveRecord;
use Model\Reservacion;
use Model\ReservacionMesa;
use Services\ContactoAccesoService;
use Services\ContactoService;
use Services\HorarioReservacionService;
use Services\ReservacionConfig;
use Services\ReservacionPublicaService;
use Services\ReservationClientSession;

$db = ActiveRecord::getDB();
if (!$db instanceof mysqli) {
    fwrite(STDERR, "No hay conexión MySQL para la suite de Etapa 7.\n");
    exit(2);
}

// Las operaciones bajo prueba son transaccionales por sí mismas; limpiamos
// únicamente los contactos sintéticos de esta suite antes y después.
$db->query("DELETE FROM verificaciones_contacto WHERE contacto LIKE 'etapa7-%@example.test'");
$db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN (SELECT id FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test')");
$db->query("DELETE FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test' AND reemplaza_reservacion_id IS NOT NULL");
$db->query("DELETE FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test'");

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
$rowByToken = static function (string $token) use ($query, $escape): ?array {
    $token = $escape($token);
    $result = $query("SELECT * FROM reservaciones WHERE request_token = '{$token}' LIMIT 1");
    $row = $result->fetch_assoc() ?: null;
    $result->free();
    return $row;
};
$count = static function (string $table, string $where) use ($query): int {
    $result = $query("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}");
    $row = $result->fetch_assoc();
    $result->free();
    return (int)($row['total'] ?? 0);
};
$freeDates = static function () use ($query, $escape): array {
    $dates = [];
    for ($offset = 5; $offset <= 80 && count($dates) < 6; $offset++) {
        $date = (new DateTimeImmutable('2026-11-01', ReservacionConfig::timezone()))
            ->modify('+' . $offset . ' days')->format('Y-m-d');
        $safe = $escape($date);
        $result = $query("SELECT COUNT(*) AS total FROM reservaciones WHERE fecha = '{$safe}'");
        $row = $result->fetch_assoc();
        $result->free();
        $calendar = HorarioReservacionService::resolverFecha($date, ReservacionConfig::ahora());
        if ((int)($row['total'] ?? 0) === 0 && ($calendar['reservable'] ?? false) === true) {
            $dates[] = $date;
        }
    }
    if (count($dates) < 6) {
        throw new RuntimeException('No se encontraron fechas libres para Etapa 7.');
    }
    return $dates;
};
$pickHour = static function (string $date): string {
    $calendar = HorarioReservacionService::resolverFecha($date, ReservacionConfig::ahora());
    $hour = substr((string)($calendar['horarios_candidatos'][4] ?? ''), 0, 5);
    if ($hour === '') {
        throw new RuntimeException("No hay horario de prueba para {$date}.");
    }
    return $hour;
};
$payload = static function (string $contact, string $date, string $hour, string $token, string $name = 'Etapa 7'): array {
    return [
        'nombre' => $name,
        'tipo_contacto' => 'email',
        'contacto' => $contact,
        'fecha' => $date,
        'hora' => $hour,
        'personas' => 2,
        'notas' => 'fixture',
        'request_token' => $token,
    ];
};

$dates = [];
try {
    $db->begin_transaction();
    $dates = $freeDates();
    $dateOriginal = $dates[0];
    $dateChanged = $dates[1];
    $dateExpired = $dates[2];
    $dateCancelled = $dates[3];
    $hourOriginal = $pickHour($dateOriginal);
    $hourChanged = $pickHour($dateChanged);
    $hourExpired = $pickHour($dateExpired);
    $hourCancelled = $pickHour($dateCancelled);

    // Acceso: la solicitud es genérica y el OTP se guarda separado de los
    // desafíos de creación o modificación.
    $accessContact = 'etapa7-acceso@example.test';
    $access = ContactoAccesoService::solicitarCodigo('email', $accessContact);
    $accessOtpCount = $count('verificaciones_contacto', "contacto = '" . $escape($accessContact) . "' AND reservacion_id IS NULL");
    $assert(($access['ok'] ?? false) === true, '7.1: solicitud de acceso responde sin enumerar');
    $assert($accessOtpCount === 1, '7.1: OTP de acceso con reservacion_id NULL');
    $assert(isset($access['preview_code']), '7.1: OTP de acceso disponible en preview de testing');
    $accessVerified = ContactoAccesoService::verificarCodigo('email', $accessContact, (string)$access['preview_code']);
    $assert(($accessVerified['ok'] ?? false) === true, '7.1: OTP de acceso correcto crea sesión');
    $assert(!isset($accessVerified['verified_contact']) || !str_contains((string)$accessVerified['verified_contact'], $accessContact), '7.1: respuesta de acceso no expone contacto completo');

    // Creación y confirmación de una reservación original.
    $contact = 'etapa7-gestion@example.test';
    $originalPayload = $payload($contact, $dateOriginal, $hourOriginal, 'ETAPA7_ORIGINAL_' . bin2hex(random_bytes(8)), 'Original');
    $pendingOriginal = ReservacionPublicaService::crearRetencion($originalPayload);
    $originalRow = $rowByToken($originalPayload['request_token']);
    $originalId = (int)($originalRow['id'] ?? 0);
    $confirmedOriginal = ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email',
        'contacto' => $contact,
        'codigo' => (string)($pendingOriginal['preview_code'] ?? ''),
        'request_token' => $originalPayload['request_token'],
    ]);
    $originalBefore = $rowByToken($originalPayload['request_token']);
    $originalMesaCount = $count('reservacion_mesas', 'reservacion_id = ' . $originalId);
    $assert(($confirmedOriginal['ok'] ?? false) === true && ($originalBefore['estado'] ?? '') === 'confirmada', '7.2: original confirmada');
    $assert($originalMesaCount > 0, '7.2: original conserva asignación');

    // La cuenta de acceso es independiente del OTP de la reservación.
    $accessAgain = ContactoAccesoService::solicitarCodigo('email', $contact);
    $accessOtp = $count('verificaciones_contacto', "contacto = '" . $escape($contact) . "' AND reservacion_id IS NULL");
    $linkedOtp = $count('verificaciones_contacto', 'reservacion_id = ' . $originalId);
    $assert(($accessAgain['ok'] ?? false) === true && $accessOtp === 1 && $linkedOtp === 1, '7.2: OTP de acceso no invalida OTP ligado');
    ContactoAccesoService::verificarCodigo('email', $contact, (string)$accessAgain['preview_code']);

    // Creación de reemplazo: la original queda confirmada y el hold tiene sus
    // propias mesas y su propio request_token, sin segundo OTP.
    $replacementToken = 'ETAPA7_REEMPLAZO_' . bin2hex(random_bytes(8));
    $replacementPayload = [
        'reservacion_id' => $originalId,
        'fecha' => $dateExpired,
        'hora' => $hourExpired,
        'personas' => 3,
        'notas' => 'nota nueva',
        'request_token' => $replacementToken,
    ];
    $replacement = ReservacionPublicaService::crearReemplazo($replacementPayload, [
        'contacto_tipo' => 'email',
        'contacto' => $contact,
    ]);
    $replacementRow = $rowByToken($replacementToken);
    $replacementId = (int)($replacementRow['id'] ?? 0);
    $assert(($replacement['ok'] ?? false) === true && ($replacement['codigo'] ?? '') === ReservacionPublicaService::REEMPLAZO_CREADO, '7.3: crea reemplazo pendiente');
    $assert(($replacement['original']['id'] ?? 0) === $originalId && ($replacement['original']['estado_label'] ?? '') !== '', '7.3: respuesta incluye reservación actual pública');
    $assert(($replacement['propuesta']['id'] ?? 0) === $replacementId && ($replacement['propuesta']['fecha'] ?? '') === $dateExpired, '7.3: respuesta incluye propuesta pública');
    $assert(($replacement['hold_minutes'] ?? 0) === ReservacionConfig::VIGENCIA_HOLD_MINUTOS && !array_key_exists('preview_code', $replacement['propuesta'] ?? []), '7.3: contrato expone hold sin segundo OTP');
    $replacementOtpCount = $count('verificaciones_contacto', 'reservacion_id = ' . $replacementId);
    $assert(($originalBefore['estado'] ?? '') === 'confirmada' && ($rowByToken($originalPayload['request_token'])['estado'] ?? '') === 'confirmada', '7.3: original intacta antes de confirmar');
    $assert($replacementId > 0 && (int)$replacementRow['reemplaza_reservacion_id'] === $originalId, '7.3: relación de reemplazo');
    $assert($count('reservacion_mesas', 'reservacion_id = ' . $replacementId) > 0, '7.3: reemplazo tiene asignación propia');
    $assert(!array_key_exists('preview_code', $replacement) && !array_key_exists('otp_expires_at', $replacement) && $replacementOtpCount === 0, '7.3: modificación no crea un segundo OTP');
    $ahoraConsulta = ReservacionConfig::ahora();
    $activeCountDuringReplacement = Reservacion::contarActivasPorContacto(
        'email',
        $contact,
        $ahoraConsulta->format('Y-m-d'),
        $ahoraConsulta->format('H:i:s')
    );
    $assert($activeCountDuringReplacement === 1, '7.3: reemplazo pendiente no duplica el límite de cinco (actual=' . $activeCountDuringReplacement . ')');

    $repeatReplacement = ReservacionPublicaService::crearReemplazo($replacementPayload, [
        'contacto_tipo' => 'email',
        'contacto' => $contact,
    ]);
    $assert(($repeatReplacement['ok'] ?? false) === true && ($repeatReplacement['idempotente'] ?? false) === true, '7.3: creación del reemplazo idempotente');

    $confirmedReplacement = ReservacionPublicaService::confirmarReemplazo([
        'request_token' => $replacementToken,
        'codigo' => (string)($replacement['preview_code'] ?? ''),
    ], [
        'contacto_tipo' => 'email',
        'contacto' => $contact,
    ]);
    $originalAfter = $rowByToken($originalPayload['request_token']);
    $replacementAfter = $rowByToken($replacementToken);
    $assert(($confirmedReplacement['ok'] ?? false) === true && ($replacementAfter['estado'] ?? '') === 'confirmada', '7.4: sesión verificada confirma reemplazo');
    $assert(($originalAfter['estado'] ?? '') === 'reemplazada', '7.4: original pasa a reemplazada');
    $repeatConfirmation = ReservacionPublicaService::confirmarReemplazo([
        'request_token' => $replacementToken,
        'codigo' => (string)($replacement['preview_code'] ?? ''),
    ], [
        'contacto_tipo' => 'email',
        'contacto' => $contact,
    ]);
    $assert(($repeatConfirmation['ok'] ?? false) === true && ($repeatConfirmation['idempotente'] ?? false) === true, '7.4: confirmación de reemplazo idempotente');

    // Expiración conserva la original y las filas históricas de mesas.
    $expiredPayload = $payload($contact, $dateChanged, $hourChanged, 'ETAPA7_EXPIRADO_' . bin2hex(random_bytes(8)), 'Expiración');
    $expiredPending = ReservacionPublicaService::crearRetencion($expiredPayload);
    $expiredOriginalRow = $rowByToken($expiredPayload['request_token']);
    $expiredOriginalId = (int)$expiredOriginalRow['id'];
    ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email', 'contacto' => $contact,
        'codigo' => (string)$expiredPending['preview_code'],
        'request_token' => $expiredPayload['request_token'],
    ]);
    $expiredReplacementToken = 'ETAPA7_REEMPLAZO_EXP_' . bin2hex(random_bytes(8));
    $expiredReplacement = ReservacionPublicaService::crearReemplazo([
        'reservacion_id' => $expiredOriginalId,
        'fecha' => $dateCancelled,
        'hora' => $hourCancelled,
        'personas' => 2,
        'notas' => 'se vence',
        'request_token' => $expiredReplacementToken,
    ], ['contacto_tipo' => 'email', 'contacto' => $contact]);
    $expiredReplacementRow = $rowByToken($expiredReplacementToken);
    $expiredReplacementId = (int)$expiredReplacementRow['id'];
    $expiredMesaCount = $count('reservacion_mesas', 'reservacion_id = ' . $expiredReplacementId);
    $query("UPDATE reservaciones SET hold_expires_at = '2026-11-01 11:59:59' WHERE id = {$expiredReplacementId}");
    $expiredResult = ReservacionPublicaService::confirmarReemplazo([
        'request_token' => $expiredReplacementToken,
        'codigo' => (string)($expiredReplacement['preview_code'] ?? ''),
    ], ['contacto_tipo' => 'email', 'contacto' => $contact]);
    $expiredOriginalAfter = $rowByToken($expiredPayload['request_token']);
    $expiredReplacementAfter = $rowByToken($expiredReplacementToken);
    $assert(($expiredResult['codigo'] ?? '') === ReservacionPublicaService::RETENCION_EXPIRADA, '7.5: reemplazo vencido rechaza confirmación');
    $assert(($expiredOriginalAfter['estado'] ?? '') === 'confirmada' && ($expiredReplacementAfter['estado'] ?? '') === 'expirada', '7.5: original sigue confirmada al expirar');
    $assert($expiredMesaCount === $count('reservacion_mesas', 'reservacion_id = ' . $expiredReplacementId), '7.5: mesas del reemplazo quedan como historial');

    // Cancelación con reemplazo pendiente invalida ambos desafíos y es
    // idempotente sin volver a tocar estado_changed_at.
    $cancelPayload = $payload($contact, $dateCancelled, $hourCancelled, 'ETAPA7_CANCELAR_' . bin2hex(random_bytes(8)), 'Cancelar');
    $cancelPending = ReservacionPublicaService::crearRetencion($cancelPayload);
    $cancelOriginalRow = $rowByToken($cancelPayload['request_token']);
    $cancelOriginalId = (int)$cancelOriginalRow['id'];
    ReservacionPublicaService::confirmarRetencion([
        'tipo' => 'email', 'contacto' => $contact,
        'codigo' => (string)$cancelPending['preview_code'],
        'request_token' => $cancelPayload['request_token'],
    ]);
    $cancelReplacementToken = 'ETAPA7_REEMPLAZO_CANCEL_' . bin2hex(random_bytes(8));
    $cancelReplacement = ReservacionPublicaService::crearReemplazo([
        'reservacion_id' => $cancelOriginalId,
        'fecha' => $dateChanged,
        'hora' => $hourChanged,
        'personas' => 2,
        'notas' => 'se cancela',
        'request_token' => $cancelReplacementToken,
    ], ['contacto_tipo' => 'email', 'contacto' => $contact]);
    $cancelReplacementRow = $rowByToken($cancelReplacementToken);
    $cancelReplacementId = (int)$cancelReplacementRow['id'];
    $cancelResult = ReservacionPublicaService::cancelar($cancelOriginalId, ['contacto_tipo' => 'email', 'contacto' => $contact]);
    $cancelOriginalAfter = $rowByToken($cancelPayload['request_token']);
    $cancelReplacementAfter = $rowByToken($cancelReplacementToken);
    $cancelOtpInvalidated = $count('verificaciones_contacto', 'reservacion_id = ' . $cancelReplacementId . ' AND invalidated_at IS NOT NULL');
    $cancelChangedAt = (string)($cancelOriginalAfter['estado_changed_at'] ?? '');
    $cancelRepeat = ReservacionPublicaService::cancelar($cancelOriginalId, ['contacto_tipo' => 'email', 'contacto' => $contact]);
    $cancelOriginalRepeat = $rowByToken($cancelPayload['request_token']);
    $assert(($cancelResult['ok'] ?? false) === true && ($cancelOriginalAfter['estado'] ?? '') === 'cancelada', '7.6: cancelación pública cambia estado');
    $assert(($cancelReplacementAfter['estado'] ?? '') === 'expirada' && $cancelOtpInvalidated === 0, '7.6: cancelación expira reemplazo sin OTP adicional');
    $assert(($cancelRepeat['ok'] ?? false) === true && ($cancelRepeat['idempotente'] ?? false) === true && $cancelChangedAt === ($cancelOriginalRepeat['estado_changed_at'] ?? ''), '7.6: cancelación repetida idempotente');

    // La consulta visible no devuelve estados técnicos ni datos de operación.
    $visible = Reservacion::buscarActivasPorContacto('email', $contact, ReservacionConfig::fechaActual(), ReservacionConfig::horaActual(), 10);
    $assert(count($visible) === 2 && isset($visible[0]['fecha'], $visible[0]['hora']), '7.7: consulta pública ordenada y limitada a vigentes');
    $assert($visible[0]['fecha'] <= $visible[1]['fecha'], '7.7: consulta pública ordenada por fecha');

    $db->query("DELETE FROM verificaciones_contacto WHERE contacto LIKE 'etapa7-%@example.test'");
    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN (SELECT id FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test')");
    $db->query("DELETE FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test' AND reemplaza_reservacion_id IS NOT NULL");
    $db->query("DELETE FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test'");
    $db->commit();
    echo json_encode([
        'ok' => $failed === [],
        'passed' => $passed,
        'failed' => $failed,
        'fixtures_cleaned' => true,
        'clock' => ReservacionConfig::ahora()->format('Y-m-d H:i:s'),
        'timezone' => ReservacionConfig::timezone()->getName(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($failed === [] ? 0 : 1);
} catch (Throwable $e) {
    $db->rollback();
    $db->query("DELETE FROM verificaciones_contacto WHERE contacto LIKE 'etapa7-%@example.test'");
    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN (SELECT id FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test')");
    $db->query("DELETE FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test' AND reemplaza_reservacion_id IS NOT NULL");
    $db->query("DELETE FROM reservaciones WHERE contacto LIKE 'etapa7-%@example.test'");
    fwrite(STDERR, "ETAPA7_FAIL: {$e->getMessage()}\n{$e->getTraceAsString()}\n");
    exit(1);
}
