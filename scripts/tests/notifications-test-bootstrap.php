<?php
// Sólo usado por el runner aislado; jamás cambia includes/.env.
$notificationTestDb = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($notificationTestDb) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $notificationTestDb)) {
    throw new RuntimeException('Base aislada de notificaciones requerida.');
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
$_ENV['DB_NAME'] = $notificationTestDb;
$_ENV['APP_ENV'] = 'test';
$_ENV['N8N_BASE_URL'] = 'http://n8n.invalid';
$_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET'] = '';
$_ENV['N8N_RESERVATIONS_CALLBACK_SECRET'] = '';
