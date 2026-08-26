<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Controllers\N8nReservationsController;
use MVC\Router;
use Services\AdminCsrfService;
use Services\DevelopmentOperationalNotificationProvider;
use Services\N8nNotificationClient;
use Services\N8nOperationalNotificationProvider;
use Services\ReservacionErrorCatalog;
use Services\ReservacionNotificacionConfigService;
use Services\ReservationAccessTokenService;
use Services\ReservationNotificationResultService;

function communicationsAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

$default = ReservacionNotificacionConfigService::validar([
    'recordatorio_dia_anterior_activo' => '0',
    'hora_recordatorio' => '18:00',
]);
communicationsAssert(($default['ok'] ?? false) === true, 'configuración predeterminada válida');
communicationsAssert(($default['configuracion']['recordatorio_dia_anterior_activo'] ?? true) === false, 'recordatorio desactivado por omisión');
foreach (['00:00', '18:00', '23:59'] as $hora) {
    communicationsAssert(ReservacionNotificacionConfigService::horaValida($hora), "hora válida {$hora}");
}
foreach (['', '8:00', '24:00', '18:60', '18:00:00'] as $hora) {
    communicationsAssert(!ReservacionNotificacionConfigService::horaValida($hora), "hora rechazada {$hora}");
}
$invalid = ReservacionNotificacionConfigService::validar([
    'recordatorio_dia_anterior_activo' => 'quizá',
    'hora_recordatorio' => '25:90',
]);
communicationsAssert(($invalid['ok'] ?? true) === false && count($invalid['errors'] ?? []) === 2, 'configuración inválida conserva errores');

$token = ReservationAccessTokenService::generar();
communicationsAssert(strlen($token['token']) === 64 && strlen($token['hash']) === 64, 'token y hash tienen longitud segura');
communicationsAssert($token['token'] !== $token['hash'], 'el token plano no coincide con el hash persistible');
communicationsAssert(ReservationAccessTokenService::hash($token['token']) === $token['hash'], 'hash SHA-256 reproducible');
communicationsAssert(!ReservationAccessTokenService::formatoValido('token-corto'), 'formato de token estricto');

$okTransport = static fn(string $url, string $secret, string $json): array => [
    'status' => 202,
    'body' => '{"ok":true,"accepted":true}',
    'error' => '',
];
$client = new N8nNotificationClient('http://n8n.invalid/webhook/reservaciones', 'fixture-secret', $okTransport);
$accepted = $client->send(['event' => 'reservation.schedule_change', 'notifications' => [['source_id' => 1]]]);
communicationsAssert(($accepted['accepted'] ?? false) === true && ($accepted['http_status'] ?? 0) === 202, 'cliente acepta sólo 202 contractual');

$missingUrl = (new N8nNotificationClient('', 'fixture-secret', $okTransport))->send(['event' => 'reservation.schedule_change']);
communicationsAssert(($missingUrl['codigo'] ?? '') === 'NOTIFICACION_URL_FALTANTE', 'falla segura sin URL');
$missingSecret = (new N8nNotificationClient('http://n8n.invalid', '', $okTransport))->send(['event' => 'reservation.schedule_change']);
communicationsAssert(($missingSecret['codigo'] ?? '') === 'NOTIFICACION_SECRET_FALTANTE', 'falla segura sin secret');
$serverError = (new N8nNotificationClient(
    'http://n8n.invalid',
    'fixture-secret',
    static fn(): array => ['status' => 500, 'body' => '{"ok":false,"accepted":false}', 'error' => '']
))->send(['event' => 'reservation.schedule_change']);
communicationsAssert(($serverError['codigo'] ?? '') === 'NOTIFICACION_NO_ACEPTADA', 'HTTP 500 no se confunde con aceptación');
$invalidJson = (new N8nNotificationClient(
    'http://n8n.invalid',
    'fixture-secret',
    static fn(): array => ['status' => 202, 'body' => 'not-json', 'error' => '']
))->send(['event' => 'reservation.schedule_change']);
communicationsAssert(($invalidJson['codigo'] ?? '') === 'NOTIFICACION_RESPUESTA_INVALIDA', 'respuesta no JSON se rechaza');
$timeout = (new N8nNotificationClient(
    'http://n8n.invalid',
    'fixture-secret',
    static function (): array { throw new RuntimeException('fixture timeout'); }
))->send(['event' => 'reservation.schedule_change']);
communicationsAssert(($timeout['codigo'] ?? '') === 'NOTIFICACION_CONEXION_FALLIDA', 'timeout se redacta y falla seguro');

$provider = new N8nOperationalNotificationProvider($client);
communicationsAssert(($provider->sendReservationsEvent('reservation.schedule_change', [['source_id' => 1]])['accepted'] ?? false) === true, 'provider n8n delega el batch');
communicationsAssert(($provider->sendReservationsEvent('evento.desconocido', [['source_id' => 1]])['codigo'] ?? '') === 'NOTIFICACION_EVENTO_INVALIDO', 'provider rechaza evento desconocido');
$development = new DevelopmentOperationalNotificationProvider();
communicationsAssert(($development->sendReservationsEvent('reservation.reminder_next_day', [
    ['source_id' => 1],
    ['source_id' => 2],
])['accepted'] ?? false) === true, 'provider development acepta un batch múltiple contractual');
communicationsAssert((ReservationNotificationResultService::registrar('evento.desconocido', 1, 1, 'delivered')['codigo'] ?? '') === 'NOTIFICACION_CALLBACK_INVALIDO', 'callback rechaza evento desconocido');
ini_set('session.save_path', sys_get_temp_dir());
communicationsAssert(AdminCsrfService::validar('fixture-csrf-invalido') === false, 'admin rechaza CSRF inválido');

$previousSecret = $_ENV['N8N_SECRET'] ?? null;
$previousMethod = $_SERVER['REQUEST_METHOD'] ?? null;
$previousHeader = $_SERVER['HTTP_X_N8N_SECRET'] ?? null;
$_ENV['N8N_SECRET'] = 'fixture-independent-secret';
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_N8N_SECRET'] = 'fixture-wrong-secret';
ob_start();
N8nReservationsController::prepararRecordatorios(new Router());
$wrongSecretOutput = json_decode((string)ob_get_clean(), true);
communicationsAssert(http_response_code() === 403 && ($wrongSecretOutput['codigo'] ?? '') === 'N8N_SECRET_INVALIDO', 'endpoint rechaza secret incorrecto');
$_SERVER['HTTP_X_N8N_SECRET'] = 'fixture-independent-secret';
ob_start();
N8nReservationsController::notificacionResultado(new Router());
$invalidPayloadOutput = json_decode((string)ob_get_clean(), true);
communicationsAssert(http_response_code() === 422 && ($invalidPayloadOutput['codigo'] ?? '') === 'NOTIFICACION_CALLBACK_INVALIDO', 'endpoint rechaza payload inválido');
if ($previousSecret === null) unset($_ENV['N8N_SECRET']); else $_ENV['N8N_SECRET'] = $previousSecret;
if ($previousMethod === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $previousMethod;
if ($previousHeader === null) unset($_SERVER['HTTP_X_N8N_SECRET']); else $_SERVER['HTTP_X_N8N_SECRET'] = $previousHeader;

foreach ([
    'ACCESO_GESTION_EXPIRADO',
    'CONFIGURACION_RESERVACIONES_INVALIDA',
    'NOTIFICACION_CALLBACK_INVALIDO',
    'NOTIFICACION_SOURCE_NO_ENCONTRADO',
    'NOTIFICACION_CONEXION_FALLIDA',
] as $code) {
    communicationsAssert(ReservacionErrorCatalog::has($code), "catálogo contiene {$code}");
}

$migration = file_get_contents($root . '/database/migrations/2026_08_22_reservaciones_comunicaciones_n8n.sql');
$ddl = file_get_contents($root . '/database/ddl.sql');
$routes = file_get_contents($root . '/public/index.php');
$management = file_get_contents($root . '/services/ReservationManagementAccessService.php');
$managementSession = file_get_contents($root . '/services/ReservationManagementAccessSession.php');
$publicView = file_get_contents($root . '/views/reservaciones/gestionar.php');
$workflowRaw = file_get_contents($root . '/n8n/reservaciones-comunicaciones.json');
$workflow = json_decode((string)$workflowRaw, true);
communicationsAssert(is_string($migration) && is_string($ddl), 'esquema de comunicaciones legible');
foreach (['configuracion_reservaciones', 'reservacion_recordatorios', 'notification_delivery_status', 'notification_delivery_updated_at'] as $fragment) {
    communicationsAssert(str_contains($migration, $fragment) && str_contains($ddl, $fragment), "esquema contiene {$fragment}");
}
communicationsAssert(str_contains($migration, "VALUES (1, 0, '18:00:00', NULL)"), 'migración crea singleton desactivado');
communicationsAssert(str_contains($migration, 'UNIQUE KEY uq_reservacion_recordatorios_dedup'), 'deduplicación respaldada por índice único');
communicationsAssert(str_contains($migration, "ENUM('pending', 'accepted', 'delivered', 'failed')"), 'estado de transporte explícito');

foreach ([
    '/reservaciones/gestionar',
    '/api/reservaciones/gestionar/disponibilidad',
    '/api/reservaciones/gestionar/modificar',
    '/api/reservaciones/gestionar/cancelar',
    '/api/integraciones/n8n/reservaciones/recordatorios/preparar',
    '/api/integraciones/n8n/reservaciones/notificacion-resultado',
    '/admin/configuracion/reservaciones',
] as $route) {
    communicationsAssert(str_contains((string)$routes, $route), "ruta registrada {$route}");
}
communicationsAssert(str_contains((string)$management, 'SOURCE_SCHEDULE_CHANGE') && str_contains((string)$management, 'SOURCE_REMINDER_NEXT_DAY'), 'acceso común reconoce ambas fuentes');
communicationsAssert(!str_contains((string)$management, 'contacto_tipo') && !str_contains((string)$managementSession, "'contacto'"), 'sesión de gestión no suplanta contacto');
communicationsAssert(str_contains((string)$publicView, 'Cancelar reservación') && str_contains((string)$publicView, '¿Cancelar esta reservación?'), 'vista pública incluye cancelación confirmada');
communicationsAssert(!preg_match('/\b(?:email|tel[eé]fono|correo)\b/i', (string)$publicView), 'vista de gestión no revela contacto');

communicationsAssert(is_array($workflow) && ($workflow['name'] ?? '') === 'Reservaciones - comunicaciones', 'workflow importable con nombre estable');
$nodeNames = array_column($workflow['nodes'] ?? [], 'name');
foreach (['Webhook reservaciones', 'Cada cinco minutos', 'Responder temprano', 'Switch event', 'Elegir canal', 'Registrar resultado'] as $nodeName) {
    communicationsAssert(in_array($nodeName, $nodeNames, true), "workflow contiene {$nodeName}");
}
communicationsAssert(str_contains((string)$workflowRaw, 'reservation.schedule_change') && str_contains((string)$workflowRaw, 'reservation.reminder_next_day'), 'workflow enruta ambos eventos');
communicationsAssert(str_contains((string)$workflowRaw, '202'), 'workflow responde aceptación temprana');
communicationsAssert(!str_contains((string)$workflowRaw, '"credentials"'), 'workflow no versiona referencias de credenciales');
communicationsAssert(!str_contains((string)$workflowRaw, '"pinData"'), 'workflow no versiona pinData');
communicationsAssert(!preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', (string)$workflowRaw), 'workflow no contiene correos de ejemplo');
communicationsAssert(!preg_match('/"(?:from|to|phone|telefono)"\s*:\s*"\+?\d{10,}"/i', (string)$workflowRaw), 'workflow no contiene teléfonos de ejemplo');

fwrite(STDOUT, "Reservaciones: contrato de comunicaciones y n8n OK\n");
