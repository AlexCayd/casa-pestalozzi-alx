<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !getenv('CP_NOTIFICATION_TEST_DATABASE')) exit('Usar run-notifications-isolated.php');
require dirname(__DIR__, 2) . '/includes/app.php';
use Model\ActiveRecord;
use Services\ContactoAccesoService;
use Services\Shared\ContactoOperacionLock;
use Services\Reservations\DisponibilidadReservacionService;
use Services\Integrations\N8nClient;
use Services\ReservacionPublicaService;
use Services\Reservations\ReservationConfirmationService;
function holdAssert(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
}
$_ENV['APP_ENV'] = 'test';
$_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 12:00:00';
$_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET'] = ''; // fallo sin HTTP externo
$db = ActiveRecord::getDB();
$id = 0;
$contact = 'hold.fixture@example.test';
$token = bin2hex(random_bytes(32));
$identity = ['tipo' => 'email', 'contacto' => $contact, 'request_token' => $token];
try {
    $slots = DisponibilidadReservacionService::consultar('2037-01-15', 2);
    $slot = array_values(array_filter($slots['horarios'] ?? [], static fn($s) => !empty($s['disponible'])))[0] ?? null;
    holdAssert($slot !== null, 'Fixture sin horario disponible.');
    $input = ['nombre' => 'FIXTURE hold', 'tipo_contacto' => 'email', 'contacto' => $contact,
        'fecha' => '2037-01-15', 'hora' => $slot['hora'], 'personas' => 2,
        'notas' => 'Fixture aislado', 'request_token' => $token];
    $failed = ReservacionPublicaService::crearRetencion($input);
    $stmt = $db->prepare('SELECT id, estado, hold_expires_at FROM reservaciones WHERE request_token = ?');
    $stmt->bind_param('s', $token); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $id = (int)($row['id'] ?? 0);
    holdAssert($failed['codigo'] === 'OTP_ENVIO_FALLIDO' && !$failed['ok'] && $id > 0, 'Fallo no conserva retención.');
    holdAssert($failed['send_count'] === 0 && $row['estado'] === 'pendiente_verificacion', 'Fallo consumió cupo o alteró dominio.');
    $repeat = ReservacionPublicaService::crearRetencion($input);
    holdAssert($repeat['send_count'] === 0 && $repeat['request_token'] === $token, 'Idempotencia no conserva política.');
    $cooldown = ReservacionPublicaService::reenviarOtpRetencion($identity);
    holdAssert($cooldown['codigo'] === 'REENVIO_EN_COOLDOWN', 'API pública omitió cooldown.');
    $client = new N8nClient('http://n8n.invalid', 'fixture', static function (): array {
        return ['status' => 200, 'body' => '{"ok":true,"accepted":true,"channel":"email"}'];
    });
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 12:0' . $attempt . ':01';
        holdAssert(ContactoOperacionLock::adquirir($db, 'email', $contact), 'Lock no disponible.');
        try {
            $db->begin_transaction();
            $prepared = ContactoAccesoService::emitirCodigoEnTransaccion('email', $contact, $id);
            $db->commit();
        } finally { ContactoOperacionLock::liberar($db, 'email', $contact); }
        $accepted = ReservationConfirmationService::finalize($prepared, $client);
        holdAssert($accepted['ok'] && $accepted['send_count'] === $attempt, 'Conteo de aceptación por retención.');
    }
    $_SESSION = [];
    $limit = ReservacionPublicaService::reenviarOtpRetencion($identity);
    holdAssert($limit['codigo'] === 'LIMITE_REENVIOS_ALCANZADO', 'API pública omitió límite.');
    $state = ReservacionPublicaService::estadoOtp($identity);
    holdAssert($state['send_count'] === 3 && $state['remaining_resends'] === 0, 'Estado de retención después de nueva sesión.');
    holdAssert(!ReservacionPublicaService::estadoOtp(array_replace($identity, ['contacto' => 'wrong@example.test']))['ok'], 'Consulta aceptó contacto ajeno.');
    $after = $db->query('SELECT estado, hold_expires_at FROM reservaciones WHERE id = ' . $id)->fetch_assoc();
    holdAssert($after['estado'] === $row['estado'] && $after['hold_expires_at'] === $row['hold_expires_at'], 'Notificación alteró hold.');
    echo "Retención: fallo conservado, idempotencia, tres aceptaciones, cooldown, límite y propiedad PASS.\n";
} finally {
    if ($id > 0) $db->query('DELETE FROM reservaciones WHERE id = ' . $id);
}
