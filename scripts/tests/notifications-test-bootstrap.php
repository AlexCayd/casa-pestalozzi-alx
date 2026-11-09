<?php
// Sólo usado por el runner aislado; jamás cambia includes/.env.
$notificationTestDb = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($notificationTestDb) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $notificationTestDb)) {
    throw new RuntimeException('Base aislada de notificaciones requerida.');
}
$testContactEncryptionKey = getenv('CP_TEST_CONTACT_ENCRYPTION_KEY');
$testContactLookupKey = getenv('CP_TEST_CONTACT_LOOKUP_KEY');
$decodedEncryptionKey = is_string($testContactEncryptionKey) ? base64_decode($testContactEncryptionKey, true) : false;
$decodedLookupKey = is_string($testContactLookupKey) ? base64_decode($testContactLookupKey, true) : false;
if (!is_string($decodedEncryptionKey) || strlen($decodedEncryptionKey) !== 32
    || !is_string($decodedLookupKey) || strlen($decodedLookupKey) !== 32
    || hash_equals($decodedEncryptionKey, $decodedLookupKey)) {
    throw new RuntimeException('Claves aleatorias de fixtures aislados requeridas.');
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__, 2) . '/includes')->safeLoad();
$_ENV['CONTACT_ENCRYPTION_KEY'] = $testContactEncryptionKey;
$_ENV['CONTACT_LOOKUP_KEY'] = $testContactLookupKey;
putenv('CONTACT_ENCRYPTION_KEY=' . $testContactEncryptionKey);
putenv('CONTACT_LOOKUP_KEY=' . $testContactLookupKey);
require_once __DIR__ . '/contacto-fixture-helper.php';
$_ENV['DB_NAME'] = $notificationTestDb;
$_ENV['APP_ENV'] = 'test';
$_ENV['N8N_BASE_URL'] = 'http://n8n.invalid';
$_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET'] = '';
$_ENV['N8N_RESERVATIONS_CALLBACK_SECRET'] = '';
