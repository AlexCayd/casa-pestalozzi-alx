<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !getenv('CP_NOTIFICATION_TEST_DATABASE')) exit('Usar run-notifications-isolated.php');
require dirname(__DIR__, 2) . '/includes/app.php';
use Model\ActiveRecord;
use Services\ReservacionNotificacionConfigService;
use Services\Reservations\Notifications\ReservationReminderService as Reminder;
use Services\Reservations\Notifications\ReservationNotificationResultService as Result;
function reminderAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$db = ActiveRecord::getDB();
$_ENV['APP_ENV'] = 'test'; $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:00:00';
$_ENV['RESERVATION_PUBLIC_BASE_URL'] = 'https://example.test';
$config = ReservacionNotificacionConfigService::obtener();
$ids = [];
try {
    ReservacionNotificacionConfigService::guardar(['recordatorio_dia_anterior_activo' => true, 'hora_recordatorio' => '18:00'], null);
    foreach (['email', 'telefono'] as $channel) {
        $contact = $channel === 'email' ? 'reminder.recovery@example.test' : '+525500000098';
        $stmt = $db->prepare("INSERT INTO reservaciones (nombre,contacto_tipo,contacto,fecha,hora,comensales,origen,estado) VALUES ('FIXTURE recovery',?,?,'2037-01-15','13:00',2,'landing','confirmada')");
        $stmt->bind_param('ss', $channel, $contact); $stmt->execute(); $ids[] = $db->insert_id; $stmt->close();
    }
    $first = Reminder::preparar()['notifications']; reminderAssert(count($first) === 2, 'preparación dos canales');
    reminderAssert(Reminder::preparar()['notifications'] === [], 'scheduler duplicado');
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:06:00';
    $retry = Reminder::preparar()['notifications']; reminderAssert(count($retry) === 2, 'caída después prepare recuperada');
    foreach ($retry as $i => $n) {
        reminderAssert($n['source_id'] === $first[$i]['source_id'] && $n['attempt'] === 2, 'misma fuente funcional, intento nuevo');
        reminderAssert($n['data']['management_url'] !== $first[$i]['data']['management_url'], 'rota enlace al recuperar');
        reminderAssert(!Reminder::reclamar($n['source_id'], 1, $n['contact']['type'])['claimed'], 'claim viejo rechazado');
        reminderAssert(Reminder::reclamar($n['source_id'], 2, $n['contact']['type'])['claimed'], 'claim vigente');
        reminderAssert(!Reminder::reclamar($n['source_id'], 2, $n['contact']['type'])['claimed'], 'claim duplicado rechazado');
    }
    $a = $retry[0]; $b = $retry[1];
    Result::registrar(Reminder::EVENT, $a['source_id'], 2, $a['contact']['type'], 'accepted');
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:12:00';
    reminderAssert(Reminder::preparar()['notifications'] === [], 'accepted y claim sin callback no se duplican');
    $unknown = $db->query('SELECT notification_delivery_status FROM reservacion_recordatorios WHERE id = ' . $b['source_id'])->fetch_row()[0];
    reminderAssert($unknown === 'pending', 'ausencia callback no inventa rechazo');
    Result::registrar(Reminder::EVENT, $b['source_id'], 2, $b['contact']['type'], 'failed', true);
    $db->query("UPDATE reservacion_recordatorios SET notification_delivery_updated_at = '2037-01-14 18:12:00' WHERE id = " . $b['source_id']);
    reminderAssert(Reminder::preparar()['notifications'] === [], 'backoff rechazo');
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:18:00';
    $third = Reminder::preparar()['notifications']; reminderAssert(count($third) === 1 && $third[0]['attempt'] === 3, 'fallo confirmado reintentado');
    reminderAssert(Result::registrar(Reminder::EVENT, $b['source_id'], 2, $b['contact']['type'], 'accepted')['stale'] ?? false, 'callback viejo no pisa');
    Reminder::reclamar($b['source_id'], 3, $b['contact']['type']);
    Result::registrar(Reminder::EVENT, $b['source_id'], 3, $b['contact']['type'], 'failed', true);
    $_ENV['RESERVATION_TEST_NOW'] = '2037-01-14 18:30:00';
    reminderAssert(Reminder::preparar()['notifications'] === [], 'máximo3 intentos');
    reminderAssert((int)$db->query('SELECT COUNT(*) FROM reservacion_recordatorios WHERE reservacion_id IN (' . implode(',', $ids) . ')')->fetch_row()[0] === 2, 'una fila funcional por reserva/fecha');
    echo "Recordatorios:prepare-caída,reconciliación,reintento,claim,dedup,accepted e incierto OK\n";
} finally {
    if ($ids) {
        $db->query('DELETE FROM reservacion_recordatorios WHERE reservacion_id IN (' . implode(',', $ids) . ')');
        $db->query('DELETE FROM reservaciones WHERE id IN (' . implode(',', $ids) . ')');
    }
    ReservacionNotificacionConfigService::guardar($config, null);
}
