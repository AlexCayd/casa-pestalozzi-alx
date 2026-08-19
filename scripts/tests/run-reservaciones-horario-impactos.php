<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\BuzonNotificacionesService;
use Services\HorarioOperacionImpactoService;
use Services\ReservacionBuzonService;
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
$migration = file_get_contents($root . '/database/migrations/2026_08_19_buzon_notificaciones.sql');
$legacyMigration = file_get_contents($root . '/database/migrations/2026_08_18_simplificar_afectaciones_horario.sql');
impactoAssert(is_string($ddl) && is_string($migration) && is_string($legacyMigration), 'se pudieron leer esquema y migraciones forward');
impactoAssert(str_contains($ddl, 'CREATE TABLE IF NOT EXISTS buzon_notificaciones'), 'DDL crea el buzón genérico');
foreach (['visible_from', 'leida_at', 'cerrada_at', 'cierre_motivo', 'dedup_key', 'uq_buzon_notificaciones_dedup', 'idx_buzon_notificaciones_visibles'] as $column) {
    impactoAssert(str_contains($ddl, $column), "DDL contiene {$column}");
}
foreach (['notification_prepared_at', 'access_token_hash', 'access_expires_at', 'access_invalidated_at', 'resolved_by', 'resolved_at'] as $column) {
    impactoAssert(str_contains($ddl, $column), "DDL contiene {$column}");
}
impactoAssert(!str_contains($ddl, 'CREATE TABLE IF NOT EXISTS reservacion_notificaciones'), 'DDL final no crea outbox específica');
impactoAssert(!str_contains($ddl, 'CREATE TABLE IF NOT EXISTS reservacion_magic_links'), 'DDL final no crea magic links');
impactoAssert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS buzon_notificaciones'), 'migración crea el buzón genérico');
impactoAssert(str_contains($migration, 'DROP TABLE IF EXISTS reservacion_notificaciones'), 'migración elimina outbox anterior');
impactoAssert(str_contains($migration, 'DROP TABLE IF EXISTS reservacion_magic_links'), 'migración elimina links anteriores');
impactoAssert(str_contains($legacyMigration, 'estado = \'notificacion_preparada\''), 'forward previo conserva estado preparado');

foreach (['AVISO_PREPARADO', 'AVISOS_PREPARADOS', 'AVISOS_PARCIALES', 'AFECTACION_COORDINADA_EXTERNAMENTE', 'ACCESO_CAMBIO_HORARIO_INVALIDO', 'ACCESO_CAMBIO_HORARIO_EXPIRADO'] as $code) {
    impactoAssert(ReservacionErrorCatalog::has($code), "{$code} está catalogado");
    impactoAssert(ReservacionErrorCatalog::presentar($code)['mensaje'] !== '', "{$code} tiene mensaje");
}
impactoAssert(BuzonNotificacionesService::PRIORIDAD_ALTA === 'alta', 'el buzón admite prioridad alta');
impactoAssert(ReservacionBuzonService::TIPO_HORARIO_AFECTADO === 'reservacion_horario_afectado', 'tipo de horario afectado');
impactoAssert(ReservacionBuzonService::TIPO_GRUPO_GRANDE === 'reservacion_grupo_grande', 'tipo de grupo grande');

$publicView = file_get_contents($root . '/views/reservaciones/cambio-horario.php');
$publicJs = file_get_contents($root . '/src/js/modules/schedule-change-access.js');
$accessService = file_get_contents($root . '/services/ScheduleChangeAccessService.php');
$accessSession = file_get_contents($root . '/services/ScheduleChangeAccessSession.php');
$accessController = file_get_contents($root . '/controllers/ScheduleChangeAccessController.php');
$impactService = file_get_contents($root . '/services/HorarioOperacionImpactoService.php');
$buzonRules = file_get_contents($root . '/services/ReservacionBuzonService.php');
$impactView = file_get_contents($root . '/views/admin/configuration/hours.php');
$layout = file_get_contents($root . '/views/admin/layout.php');
$inboxView = file_get_contents($root . '/views/admin/partials/_buzon.php');
$inboxJs = file_get_contents($root . '/src/js/admin/buzon.js');
$routes = file_get_contents($root . '/public/index.php');
impactoAssert(is_string($publicView) && is_string($publicJs) && is_string($accessService) && is_string($accessSession), 'se pudo leer el acceso público');
foreach (['contacto', 'correo', 'telefono', 'email', 'phone'] as $privateWord) {
    impactoAssert(!preg_match('/' . preg_quote($privateWord, '/') . '/i', $publicView), "formulario público no expone {$privateWord}");
    impactoAssert(!preg_match('/' . preg_quote($privateWord, '/') . '/i', $publicJs), "JS público no expone {$privateWord}");
}
impactoAssert(str_contains($publicView, 'date-picker.php') && str_contains($publicView, 'time-picker.php'), 'formulario público reutiliza componentes canónicos');
impactoAssert(!str_contains($publicView, 'type="date"') && !str_contains($publicView, '<select'), 'formulario público no duplica controles de fecha/hora');
foreach (['createReservationDatePicker', 'createReservationTimePicker', 'requestTimeoutMs', 'Reintentar', 'finally'] as $fragment) {
    impactoAssert(str_contains($publicJs, $fragment), "JS público contiene {$fragment}");
}
impactoAssert(str_contains($accessService, "hash('sha256', \$token)"), 'el acceso sólo compara hashes SHA-256');
impactoAssert(is_string($impactService) && str_contains($impactService, 'bin2hex(random_bytes(32))'), 'el token temporal tiene 32 bytes aleatorios');
impactoAssert(str_contains($accessService, 'access_invalidated_at') && str_contains($accessService, 'access_expires_at'), 'el acceso revalida vigencia');
impactoAssert(str_contains($accessService, 'puedeModificarPublicamente'), 'el acceso revalida editabilidad');
impactoAssert(!str_contains($accessService, 'ReservationClientSession'), 'el acceso no reutiliza sesión pública general');
impactoAssert(str_contains($accessSession, 'impacto_reservacion_id') && str_contains($accessSession, 'reservacion_id'), 'sesión limitada sólo guarda ids');
impactoAssert(is_string($accessController) && str_contains($accessController, "['GET', 'POST']"), 'disponibilidad acepta GET y POST');
impactoAssert(str_contains($accessController, "\$_SERVER['REQUEST_METHOD'] === 'GET' ? \$_GET : \$_POST"), 'disponibilidad lee la fuente correcta de parámetros');
impactoAssert(is_string($routes) && str_contains($routes, '/api/reservaciones/cambio-horario/modificar'), 'ruta pública directa de modificación');
impactoAssert(is_string($routes) && str_contains($routes, '/api/reservaciones/cambio-horario/disponibilidad'), 'ruta GET de disponibilidad pública');
impactoAssert(is_string($routes) && !str_contains($routes, '/reservaciones/acceso-cambio-horario'), 'rutas del puente anterior retiradas');

impactoAssert(is_string($impactView) && !str_contains($impactView, 'admin-modal__dialog--workflow'), 'horarios no abre workflow bloqueante');
impactoAssert(!str_contains($impactView, 'data-schedule-impact'), 'horarios no conserva atributos del modal bloqueante');
impactoAssert(is_string($layout) && str_contains($layout, '_buzon.php'), 'layout incluye el buzón persistente');
impactoAssert(!str_contains($layout, '_schedule-impact-alert.php'), 'layout retiró la alerta antigua');
impactoAssert(is_string($inboxView) && str_contains($inboxView, 'data-inbox-drawer'), 'buzón tiene drawer');
impactoAssert(is_string($inboxJs) && str_contains($inboxJs, 'markItemRead'), 'buzón marca leído al abrir el detalle');
impactoAssert(!str_contains($inboxJs, 'Marcar leída'), 'buzón no muestra una acción separada de lectura');
impactoAssert(str_contains($inboxJs, 'Mantener reservación') && str_contains($inboxJs, 'Coordinar'), 'buzón ofrece acciones de resolución');
impactoAssert(str_contains($impactService, 'clasificarSeguimientosEnTransaccion'), 'impacto clasifica seguimiento al persistir');
impactoAssert(str_contains($buzonRules, 'visible_from') && str_contains($impactService, 'MAX_COMENSALES_PUBLICO'), 'impacto aplica acceso diferido y umbral');

if ($previousEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $previousEnvironment;
if ($previousTtl === null) unset($_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES']); else $_ENV['SCHEDULE_CHANGE_ACCESS_TTL_MINUTES'] = $previousTtl;
if ($previousBaseUrl === null) unset($_ENV['RESERVATION_PUBLIC_BASE_URL']); else $_ENV['RESERVATION_PUBLIC_BASE_URL'] = $previousBaseUrl;

fwrite(STDOUT, "Reservaciones: contrato final de buzón y afectaciones OK\n");
