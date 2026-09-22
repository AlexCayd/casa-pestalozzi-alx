<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Controllers\N8nReservationsController;
use MVC\Router;
use Services\Security\AdminCsrfService;
use Services\Integrations\N8nClient;
use Services\Notifications\NotificationConfig;
use Services\Reservations\ReservacionErrorCatalog;
use Services\Reservations\ReservationAccessTokenService;
use Services\Reservations\ReservacionNotificacionConfigService;
use Services\Reservations\ReservationNotificationContract;
use Services\Reservations\ReservationNotificationResultService;
use Services\Reservations\ScheduleChangeNotificationService;

function communicationsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$previousEnvironment = $_ENV['APP_ENV'] ?? null;
$previousBaseUrl = $_ENV['N8N_BASE_URL'] ?? null;
$previousSecret = $_ENV['N8N_RESERVATIONS_CALLBACK_SECRET'] ?? null;

foreach ([
    'development' => [false, true, true, 'text'],
    'test' => [true, false, false, 'text'],
    'production' => [true, false, false, 'template'],
] as $environment => [$external, $showCode, $showLinks, $whatsappMode]) {
    $_ENV['APP_ENV'] = $environment;
    communicationsAssert(NotificationConfig::environment() === $environment, "entorno {$environment}");
    communicationsAssert(NotificationConfig::usesExternalTransport() === $external, "transporte {$environment}");
    communicationsAssert(NotificationConfig::showConfirmationCode() === $showCode, "código {$environment}");
    communicationsAssert(NotificationConfig::showManagementDebugLinks() === $showLinks, "links {$environment}");
    communicationsAssert(NotificationConfig::whatsappMode() === $whatsappMode, "modo WhatsApp {$environment}");
}
$_ENV['APP_ENV'] = 'testing';
try {
    NotificationConfig::environment();
    communicationsAssert(false, 'testing heredado debe rechazarse');
} catch (RuntimeException) {
    communicationsAssert(true, 'APP_ENV inválido rechazado');
}
$_ENV['APP_ENV'] = 'development';

$functional = ReservacionNotificacionConfigService::validar([
    'recordatorio_dia_anterior_activo' => '1',
    'hora_recordatorio' => '18:00',
]);
communicationsAssert(($functional['ok'] ?? false) === true, 'configuración funcional continúa en BD');
communicationsAssert(ReservacionNotificacionConfigService::horaValida('23:59'), 'hora funcional válida');
communicationsAssert(!ReservacionNotificacionConfigService::horaValida('24:00'), 'hora funcional inválida');

$token = ReservationAccessTokenService::generar();
communicationsAssert(strlen($token['token']) === 64 && strlen($token['hash']) === 64, 'token seguro');
communicationsAssert($token['token'] !== $token['hash'], 'sólo se persiste hash');

$_ENV['APP_ENV'] = 'test';
$emailPayload = ReservationNotificationContract::build(
    ReservationNotificationContract::EVENT_REMINDER,
    1,
    2,
    1,
    'email',
    'CLIENTE@EXAMPLE.TEST',
    'Cliente',
    '2037-01-15',
    '18:00',
    2,
    ['management_url' => 'https://example.test/access']
);
communicationsAssert(($emailPayload['contact']['type'] ?? '') === 'email', 'canal email canónico');
communicationsAssert(($emailPayload['contact']['value'] ?? '') === 'cliente@example.test', 'email normalizado');
communicationsAssert(($emailPayload['transport']['whatsapp_mode'] ?? '') === 'text', 'contrato TEST selecciona WhatsApp Text');
$_ENV['APP_ENV'] = 'production';
$whatsappPayload = ReservationNotificationContract::build(
    ReservationNotificationContract::EVENT_SCHEDULE_CHANGE,
    3,
    4,
    2,
    'telefono',
    '+52 55 1234 5678',
    'Cliente',
    '2037-01-15',
    '18:00',
    2,
    []
);
communicationsAssert(($whatsappPayload['contact']['type'] ?? '') === 'whatsapp', 'teléfono se convierte a whatsapp');
communicationsAssert(($whatsappPayload['contact']['value'] ?? '') === '+525512345678', 'WhatsApp normalizado');
communicationsAssert(($whatsappPayload['transport']['whatsapp_mode'] ?? '') === 'template', 'contrato production selecciona WhatsApp Template');
$_ENV['APP_ENV'] = 'development';
try {
    ReservationNotificationContract::build(
        ReservationNotificationContract::EVENT_SCHEDULE_CHANGE,
        1,
        2,
        3,
        'email',
        'fixture@example.test',
        'Cliente',
        '2037-01-15',
        '18:00',
        2
    );
    communicationsAssert(false, 'contrato PHP permitió attempt 3');
} catch (InvalidArgumentException) {
    communicationsAssert(true, 'attempt 3 rechazado por PHP');
}

$okTransport = static fn(string $url, string $secret, string $json): array => [
    'status' => 202,
    'body' => '{"ok":true,"accepted":true}',
    'error' => '',
];
$client = new N8nClient('http://n8n.invalid/', 'fixture-secret', $okTransport);
$accepted = $client->post('/webhook/reservaciones/confirmacion', $emailPayload);
communicationsAssert(($accepted['accepted'] ?? false) === true, 'cliente acepta HTTP 202 contractual');
foreach ([403, 422, 500] as $status) {
    $failure = (new N8nClient(
        'http://n8n.invalid',
        'fixture-secret',
        static fn(): array => ['status' => $status, 'body' => '{"ok":false,"accepted":false}', 'error' => '']
    ))->post('/webhook/reservaciones/cambio-horario', $whatsappPayload);
    communicationsAssert(($failure['accepted'] ?? true) === false && ($failure['http_status'] ?? 0) === $status, "HTTP {$status} rechazado");
}
$invalidJson = (new N8nClient(
    'http://n8n.invalid',
    'fixture-secret',
    static fn(): array => ['status' => 202, 'body' => 'not-json', 'error' => '']
))->post('/webhook/reservaciones/confirmacion', $emailPayload);
communicationsAssert(($invalidJson['codigo'] ?? '') === 'NOTIFICACION_RESPUESTA_INVALIDA', 'JSON inválido rechazado');
$timeout = (new N8nClient(
    'http://n8n.invalid',
    'fixture-secret',
    static function (): array { throw new RuntimeException('fixture timeout'); }
))->post('/webhook/reservaciones/confirmacion', $emailPayload);
communicationsAssert(($timeout['codigo'] ?? '') === 'NOTIFICACION_CONEXION_FALLIDA', 'timeout redactado');
communicationsAssert((new N8nClient('', 'secret', $okTransport))->post('/webhook/reservaciones/confirmacion', [])['codigo'] === 'NOTIFICACION_URL_FALTANTE', 'URL requerida');
communicationsAssert((new N8nClient('http://n8n.invalid', '', $okTransport))->post('/webhook/reservaciones/confirmacion', [])['codigo'] === 'NOTIFICACION_SECRET_FALTANTE', 'secret requerido');
communicationsAssert($client->post('/webhook/../otro', [])['codigo'] === 'NOTIFICACION_RUTA_INVALIDA', 'ruta relativa insegura rechazada');

$invalidCallback = ReservationNotificationResultService::registrar(
    'evento.desconocido',
    1,
    1,
    'whatsapp',
    'accepted'
);
communicationsAssert(($invalidCallback['codigo'] ?? '') === 'NOTIFICACION_CALLBACK_INVALIDO', 'callback valida evento');
communicationsAssert(ScheduleChangeNotificationService::MAX_ATTEMPTS === 2, 'no existe attempt 3');
ini_set('session.save_path', sys_get_temp_dir());
communicationsAssert(AdminCsrfService::validar('fixture-csrf-invalido') === false, 'admin rechaza CSRF inválido');

$previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$previousHeader = $_SERVER['HTTP_X_N8N_CALLBACK_SECRET'] ?? null;
$previousWebhookSecret = $_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET'] ?? null;
$_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET'] = 'fixture-outgoing-secret';
$_ENV['N8N_RESERVATIONS_CALLBACK_SECRET'] = 'fixture-independent-secret';
$auth = new ReflectionMethod(N8nReservationsController::class, 'secretValido');
foreach (['' => false, 'fixture-outgoing-secret' => false, 'fixture-independent-secret' => true] as $header => $valid) {
    $_SERVER['HTTP_X_N8N_CALLBACK_SECRET'] = $header;
    communicationsAssert($auth->invoke(null) === $valid, 'autenticación separa direcciones y rechaza header ausente');
}
$_ENV['N8N_RESERVATIONS_CALLBACK_SECRET'] = 'fixture-outgoing-secret';
$_SERVER['HTTP_X_N8N_CALLBACK_SECRET'] = 'fixture-outgoing-secret';
communicationsAssert($auth->invoke(null) === false, 'secretos iguales fallan cerrado');
if ($previousWebhookSecret === null) unset($_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET']); else $_ENV['N8N_RESERVATIONS_WEBHOOK_SECRET'] = $previousWebhookSecret;
$_ENV['N8N_RESERVATIONS_CALLBACK_SECRET'] = 'fixture-independent-secret';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_N8N_CALLBACK_SECRET'] = 'fixture-wrong-secret';
ob_start();
N8nReservationsController::prepararRecordatorios(new Router());
$wrongSecretOutput = json_decode((string)ob_get_clean(), true);
communicationsAssert(http_response_code() === 403 && ($wrongSecretOutput['codigo'] ?? '') === 'N8N_SECRET_INVALIDO', 'endpoint rechaza secret incorrecto');
$_SERVER['HTTP_X_N8N_CALLBACK_SECRET'] = 'fixture-independent-secret';
ob_start();
N8nReservationsController::notificacionResultado(new Router());
$invalidPayloadOutput = json_decode((string)ob_get_clean(), true);
communicationsAssert(http_response_code() === 422 && ($invalidPayloadOutput['codigo'] ?? '') === 'NOTIFICACION_CALLBACK_INVALIDO', 'endpoint rechaza payload inválido');

foreach (['NOTIFICACION_CALLBACK_INVALIDO', 'NOTIFICACION_SOURCE_NO_ENCONTRADO', 'NOTIFICACION_CONEXION_FALLIDA'] as $code) {
    communicationsAssert(ReservacionErrorCatalog::has($code), "catálogo contiene {$code}");
}

$ddl = file_get_contents($root . '/database/ddl.sql');
$routes = file_get_contents($root . '/public/index.php');
communicationsAssert(is_string($ddl) && is_string($routes), 'esquema y rutas legibles');
foreach (['configuracion_reservaciones', 'reservacion_recordatorios', 'notification_delivery_status', 'notification_delivery_updated_at'] as $fragment) {
    communicationsAssert(str_contains($ddl, $fragment), "esquema contiene {$fragment}");
}
communicationsAssert(str_contains($ddl, "ENUM('pending', 'accepted', 'failed')"), 'estados de transporte explícitos');
foreach (['/recordatorios/preparar', '/notificacion-resultado'] as $route) {
    communicationsAssert(str_contains($routes, $route), "ruta registrada {$route}");
}

$expectedWorkflows = [
    'reservaciones-confirmacion.json' => ['Reservaciones - Confirmación', 'Webhook confirmación', 'reservaciones/confirmacion'],
    'reservaciones-recordatorio.json' => ['Reservaciones - Recordatorio', 'Cada cinco minutos', 'minutesInterval'],
    'reservaciones-cambio-horario.json' => ['Reservaciones - Cambio de horario', 'Webhook cambio de horario', 'reservaciones/cambio-horario'],
];
foreach ($expectedWorkflows as $filename => [$name, $trigger, $entry]) {
    $raw = file_get_contents($root . '/n8n/' . $filename);
    $workflow = json_decode((string)$raw, true);
    communicationsAssert(is_array($workflow) && ($workflow['name'] ?? '') === $name, "{$filename} importable");
    $nodeNames = array_column($workflow['nodes'] ?? [], 'name');
    foreach ([$trigger, 'Switch channel', 'Enviar Email', 'Resolver modo WhatsApp', 'Enviar WhatsApp Text', 'Enviar WhatsApp Template'] as $nodeName) {
        communicationsAssert(in_array($nodeName, $nodeNames, true), "{$filename} contiene {$nodeName}");
    }
    communicationsAssert(str_contains((string)$raw, $entry), "{$filename} conserva entrada");
    communicationsAssert(str_contains((string)$raw, 'email') && str_contains((string)$raw, 'whatsapp'), "{$filename} soporta ambos canales");
    communicationsAssert(!str_contains((string)$raw, '"credentials"') && !str_contains((string)$raw, '"pinData"'), "{$filename} sin credenciales ni pinData");
    communicationsAssert(!preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string)$raw), "{$filename} sin email real");
    communicationsAssert(!preg_match('/"(?:value|contact|phone|telefono)"\s*:\s*"\+?\d{10,}"/i', (string)$raw), "{$filename} sin teléfono real");
}
$confirmationRaw = file_get_contents($root . '/n8n/reservaciones-confirmacion.json');
$scheduleRaw = file_get_contents($root . '/n8n/reservaciones-cambio-horario.json');
communicationsAssert(str_contains((string)$confirmationRaw, 'headerAuth') && str_contains((string)$confirmationRaw, 'Responder 200'), 'confirmación autentica y espera proveedor');
communicationsAssert(str_contains((string)$scheduleRaw, "[1, 2].includes"), 'cambio horario bloquea attempt mayor a 2');
$exporter = file_get_contents($root . '/n8n/exportar.js');
foreach (array_keys($expectedWorkflows) as $filename) {
    communicationsAssert(str_contains((string)$exporter, $filename), "exportador reconoce {$filename}");
}
communicationsAssert(str_contains((string)$exporter, 'delete limpio.credentials'), 'exportador elimina referencias de credenciales');

if ($previousEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $previousEnvironment;
if ($previousBaseUrl === null) unset($_ENV['N8N_BASE_URL']); else $_ENV['N8N_BASE_URL'] = $previousBaseUrl;
if ($previousSecret === null) unset($_ENV['N8N_RESERVATIONS_CALLBACK_SECRET']); else $_ENV['N8N_RESERVATIONS_CALLBACK_SECRET'] = $previousSecret;
if ($previousMethod === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $previousMethod;
if ($previousHeader === null) unset($_SERVER['HTTP_X_N8N_CALLBACK_SECRET']); else $_SERVER['HTTP_X_N8N_CALLBACK_SECRET'] = $previousHeader;

fwrite(STDOUT, "Reservaciones: configuración, contrato y tres workflows n8n OK\n");
