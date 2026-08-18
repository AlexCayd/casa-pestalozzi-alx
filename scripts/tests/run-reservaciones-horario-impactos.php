<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\HorarioOperacionImpactoService;
use Services\ReservacionConfig;
use Services\ReservacionErrorCatalog;

function impactoAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);
$previousEnvironment = $_ENV['APP_ENV'] ?? null;
$previousTtl = $_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES'] ?? null;
$previousBaseUrl = $_ENV['RESERVATION_PUBLIC_BASE_URL'] ?? null;

$_ENV['APP_ENV'] = 'development';
$_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES'] = '60';
unset($_ENV['RESERVATION_PUBLIC_BASE_URL']);
impactoAssert(ReservacionConfig::scheduleChangeAccessTtlMinutes() === 60, 'TTL predeterminado de acceso temporal');
$_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES'] = '1';
impactoAssert(ReservacionConfig::scheduleChangeAccessTtlMinutes() === 15, 'TTL mínimo de acceso temporal');
$_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES'] = '999';
impactoAssert(ReservacionConfig::scheduleChangeAccessTtlMinutes() === 180, 'TTL máximo de acceso temporal');
impactoAssert(ReservacionConfig::reservationPublicBaseUrl() === 'http://localhost', 'fallback local de URL pública');

$antes = [
    'semanal' => [0 => ['abierto' => true, 'hora_apertura' => '10:00:00', 'hora_cierre' => '18:00:00']],
    'excepciones' => [],
];
$despues = [
    'semanal' => [0 => ['abierto' => true, 'hora_apertura' => '12:00:00', 'hora_cierre' => '18:00:00']],
    'excepciones' => [],
];
impactoAssert(HorarioOperacionImpactoService::horarioValidoEnSnapshot('2026-08-23', '10:30:00', $antes), 'horario válido en snapshot anterior');
impactoAssert(!HorarioOperacionImpactoService::horarioValidoEnSnapshot('2026-08-23', '10:30:00', $despues), 'horario fuera del snapshot nuevo');

$ddl = file_get_contents($root . '/database/ddl.sql');
$migration = file_get_contents($root . '/database/migrations/2026_08_18_simplificar_afectaciones_horario.sql');
impactoAssert(is_string($ddl) && is_string($migration), 'se pudo leer el esquema final y la migración forward');
impactoAssert(str_contains($ddl, "'notificacion_preparada'"), 'DDL usa el estado preparado');
foreach (['notification_prepared_at', 'access_token_hash', 'access_expires_at', 'access_invalidated_at', 'resolved_by', 'resolved_at'] as $column) {
    impactoAssert(str_contains($ddl, $column), "DDL contiene {$column}");
}
impactoAssert(!str_contains($ddl, 'CREATE TABLE IF NOT EXISTS reservacion_notificaciones'), 'DDL final no crea outbox');
impactoAssert(!str_contains($ddl, 'CREATE TABLE IF NOT EXISTS reservacion_magic_links'), 'DDL final no crea magic links');
impactoAssert(str_contains($migration, 'UPDATE reservacion_magic_links'), 'forward invalida magic links anteriores');
impactoAssert(str_contains($migration, 'DROP TABLE IF EXISTS reservacion_notificaciones'), 'forward elimina outbox anterior');
impactoAssert(str_contains($migration, "estado = 'notificacion_preparada'"), 'forward migra estado preparado');
impactoAssert(str_contains($migration, 'notification_prepared_at'), 'forward migra timestamp de preparación');

foreach (['AVISO_PREPARADO', 'AVISOS_PREPARADOS', 'AVISOS_PARCIALES', 'ACCESO_CAMBIO_HORARIO_INVALIDO', 'ACCESO_CAMBIO_HORARIO_EXPIRADO'] as $code) {
    impactoAssert(ReservacionErrorCatalog::has($code), "{$code} está catalogado");
    impactoAssert(ReservacionErrorCatalog::presentar($code)['mensaje'] !== '', "{$code} tiene mensaje");
}

$publicView = file_get_contents($root . '/views/reservaciones/cambio-horario.php');
$publicJs = file_get_contents($root . '/src/js/modules/schedule-change-access.js');
$accessService = file_get_contents($root . '/services/ScheduleChangeAccessService.php');
$accessSession = file_get_contents($root . '/services/ScheduleChangeAccessSession.php');
$impactService = file_get_contents($root . '/services/HorarioOperacionImpactoService.php');
$impactView = file_get_contents($root . '/views/admin/configuration/hours.php');
$impactJs = file_get_contents($root . '/src/js/admin/configuration/impacto-horario.js');
$accessController = file_get_contents($root . '/controllers/ScheduleChangeAccessController.php');
$routes = file_get_contents($root . '/public/index.php');
$layout = file_get_contents($root . '/views/admin/layout.php');
$topbar = file_get_contents($root . '/views/admin/partials/_topbar.php');
impactoAssert(is_string($publicView) && is_string($publicJs) && is_string($accessService) && is_string($accessSession), 'se pudo leer el acceso público');
foreach (['contacto', 'correo', 'telefono', 'email', 'phone'] as $privateWord) {
    impactoAssert(!preg_match('/' . preg_quote($privateWord, '/') . '/i', $publicView), "formulario público no expone {$privateWord}");
    impactoAssert(!preg_match('/' . preg_quote($privateWord, '/') . '/i', $publicJs), "JS público no expone {$privateWord}");
}
impactoAssert(str_contains($accessService, "hash('sha256', \$token)"), 'el acceso sólo compara hashes SHA-256');
impactoAssert(is_string($impactService) && str_contains($impactService, 'bin2hex(random_bytes(32))'), 'el token temporal tiene 32 bytes aleatorios');
impactoAssert(str_contains($accessService, 'access_invalidated_at'), 'el acceso revalida invalidación');
impactoAssert(str_contains($accessService, 'access_expires_at'), 'el acceso revalida expiración');
impactoAssert(str_contains($accessService, 'puedeModificarPublicamente'), 'el acceso revalida editabilidad');
impactoAssert(!str_contains($accessService, 'ReservationClientSession'), 'el acceso no reutiliza sesión pública general');
impactoAssert(str_contains($accessSession, 'impacto_reservacion_id') && str_contains($accessSession, 'reservacion_id'), 'sesión limitada sólo guarda ids');
impactoAssert(is_string($accessController) && str_contains($accessController, "self::redirect('/reservaciones/cambio-horario', 303)"), 'GET válido limpia la URL con 303');
impactoAssert(is_string($routes) && str_contains($routes, '/api/reservaciones/cambio-horario/modificar'), 'ruta pública directa de modificación');
impactoAssert(is_string($routes) && !str_contains($routes, '/reservaciones/acceso-cambio-horario'), 'rutas del puente anterior retiradas');

impactoAssert(is_string($impactView) && str_contains($impactView, 'admin-modal__dialog--workflow'), 'modal de seguimiento usa variante workflow');
impactoAssert(!str_contains($impactView, 'data-admin-modal="'), 'seguimiento no anida un modal interno');
impactoAssert(!str_contains($impactView, 'Guardar de todas formas'), 'vista no duplica confirmación de servidor');
impactoAssert(is_string($impactJs) && str_contains($impactJs, 'preparar-disponibles'), 'seguimiento usa preparación masiva');
impactoAssert(str_contains($impactJs, 'AVISOS_PARCIALES'), 'seguimiento presenta preparación parcial');
impactoAssert(str_contains($impactJs, 'data-impact-contact-view'), 'contacto vive dentro del modal de workflow');
impactoAssert(str_contains($impactJs, 'stopImmediatePropagation'), 'modal pendiente bloquea Escape y backdrop');
impactoAssert(is_string($layout) && str_contains($layout, '_schedule-impact-alert.php'), 'alerta flotante está en layout');
impactoAssert(is_string($topbar) && !str_contains($topbar, 'schedule-impact'), 'alerta no está en topbar');

if ($previousEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $previousEnvironment;
if ($previousTtl === null) unset($_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES']); else $_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES'] = $previousTtl;
if ($previousBaseUrl === null) unset($_ENV['RESERVATION_PUBLIC_BASE_URL']); else $_ENV['RESERVATION_PUBLIC_BASE_URL'] = $previousBaseUrl;

fwrite(STDOUT, "Reservaciones: contrato final de afectaciones OK\n");
