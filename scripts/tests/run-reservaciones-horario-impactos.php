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
$previousTtl = $_ENV['SCHEDULE_CHANGE_LINK_TTL_HOURS'] ?? null;
$previousBaseUrl = $_ENV['RESERVATION_PUBLIC_BASE_URL'] ?? null;

unset($_ENV['SCHEDULE_CHANGE_LINK_TTL_HOURS']);
$_ENV['APP_ENV'] = 'development';
unset($_ENV['RESERVATION_PUBLIC_BASE_URL']);
impactoAssert(ReservacionConfig::scheduleChangeLinkTtlHours() === 72, 'TTL predeterminado de magic link');
impactoAssert(ReservacionConfig::reservationPublicBaseUrl() === 'http://localhost', 'fallback local de URL pública');

$antes = [
    'semanal' => [0 => ['abierto' => true, 'hora_apertura' => '10:00:00', 'hora_cierre' => '18:00:00']],
    'excepciones' => [],
];
$despues = [
    'semanal' => [0 => ['abierto' => true, 'hora_apertura' => '12:00:00', 'hora_cierre' => '18:00:00']],
    'excepciones' => [],
];
impactoAssert(
    HorarioOperacionImpactoService::horarioValidoEnSnapshot('2026-08-23', '10:30:00', $antes),
    'un horario dentro del snapshot anterior es válido'
);
impactoAssert(
    !HorarioOperacionImpactoService::horarioValidoEnSnapshot('2026-08-23', '10:30:00', $despues),
    'el mismo horario queda fuera del snapshot nuevo'
);

$schema = file_get_contents($root . '/database/migrations/2026_08_18_reservaciones_afectaciones_horario.sql');
impactoAssert(is_string($schema), 'se pudo leer la migración');
foreach (['horario_impactos', 'horario_impacto_reservaciones', 'reservacion_notificaciones', 'reservacion_magic_links'] as $table) {
    impactoAssert(str_contains($schema, 'CREATE TABLE IF NOT EXISTS ' . $table), "tabla {$table} en migración");
}
impactoAssert(str_contains($schema, 'UNIQUE KEY uq_reservacion_notificaciones_dedup'), 'outbox con deduplicación');
impactoAssert(str_contains($schema, 'token_hash'), 'magic link almacena hash');

foreach (['SEGUIMIENTO_HORARIO_PENDIENTE', 'MAGIC_LINK_INVALIDO', 'AVISO_ENCOLADO', 'ERROR_SEGUIMIENTO_HORARIO'] as $code) {
    impactoAssert(ReservacionErrorCatalog::has($code), "{$code} está catalogado");
    impactoAssert(ReservacionErrorCatalog::presentar($code)['mensaje'] !== '', "{$code} tiene mensaje");
}

$bridge = file_get_contents($root . '/views/reservaciones/acceso-cambio-horario.php');
$impactView = file_get_contents($root . '/views/admin/configuration/hours.php');
$impactJs = file_get_contents($root . '/src/js/admin/configuration/impacto-horario.js');
impactoAssert(is_string($bridge) && str_contains($bridge, 'history.replaceState'), 'el puente limpia el fragmento');
impactoAssert(is_string($bridge) && str_contains($bridge, "method: 'POST'"), 'el puente consume sólo por POST');
impactoAssert(is_string($impactView) && str_contains($impactView, 'data-impact-required'), 'el seguimiento tiene modal obligatorio');
impactoAssert(is_string($impactJs) && !str_contains($impactJs, 'localStorage'), 'el enlace de prueba no usa almacenamiento persistente');

if ($previousEnvironment === null) unset($_ENV['APP_ENV']); else $_ENV['APP_ENV'] = $previousEnvironment;
if ($previousTtl === null) unset($_ENV['SCHEDULE_CHANGE_LINK_TTL_HOURS']); else $_ENV['SCHEDULE_CHANGE_LINK_TTL_HOURS'] = $previousTtl;
if ($previousBaseUrl === null) unset($_ENV['RESERVATION_PUBLIC_BASE_URL']); else $_ENV['RESERVATION_PUBLIC_BASE_URL'] = $previousBaseUrl;

fwrite(STDOUT, "Reservaciones: impactos de horario OK\n");
