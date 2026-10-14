<?php

declare(strict_types=1);

/*
 * Límites de la reserva en línea: máximo de comensales y WhatsApp del
 * restaurante. Prueba pura: sin conexión, el lector tolerante responde con los
 * valores históricos, y el override de test fija el máximo sin tocar la base.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Model\ConfiguracionReservaciones;
use Services\Reservations\ReservacionAjustesService;
use Services\Reservations\ReservacionConfig;
use Services\Reservations\ReservacionErrorCatalog;

/** @param mixed $condition */
function assertAjustes($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__, 2);

// ── Rango y normalización ────────────────────────────────────────────
assertAjustes(ReservacionAjustesService::acotarMaxComensales(3) === 7, 'el máximo no baja del piso de 7');
assertAjustes(ReservacionAjustesService::acotarMaxComensales(40) === 16, 'el máximo no pasa del bloque exterior (16)');
assertAjustes(ReservacionAjustesService::acotarMaxComensales(12) === 12, 'un máximo válido se respeta');
assertAjustes(ReservacionAjustesService::normalizarWhatsapp('+52 56 1481-8297') === '525614818297', 'el WhatsApp se reduce a dígitos');
assertAjustes(ReservacionAjustesService::normalizarWhatsapp('  ') === null, 'un WhatsApp vacío es null');

// ── Lectura tolerante sin conexión ───────────────────────────────────
putenv('APP_ENV=production');
$_ENV['APP_ENV'] = 'production';
ReservacionAjustesService::limpiarCache();
assertAjustes(ReservacionConfig::maxComensalesPublico() === ReservacionConfig::MAX_COMENSALES_PUBLICO_PREDETERMINADO, 'sin base rige el máximo histórico');
assertAjustes(ReservacionConfig::umbralGrupoGrande() === ReservacionConfig::maxComensalesPublico(), 'el umbral de grupo grande es el mismo máximo');
assertAjustes(ReservacionAjustesService::whatsappReservas() === null, 'sin base no hay WhatsApp configurado');
assertAjustes(ReservacionConfig::whatsappNumero() === ReservacionConfig::whatsappRespaldo(), 'sin WhatsApp configurado rige el de respaldo');
assertAjustes(ReservacionConfig::contactoPublico()['max_comensales'] === 12, 'la landing recibe el máximo efectivo');

// ── El override sólo existe en test ──────────────────────────────────
putenv('RESERVATION_TEST_MAX_COMENSALES=8');
$_ENV['RESERVATION_TEST_MAX_COMENSALES'] = '8';
ReservacionAjustesService::limpiarCache();
assertAjustes(ReservacionConfig::maxComensalesPublico() === 12, 'fuera de test el override se ignora');

putenv('APP_ENV=test');
$_ENV['APP_ENV'] = 'test';
ReservacionAjustesService::limpiarCache();
assertAjustes(ReservacionConfig::maxComensalesPublico() === 8, 'en test el override fija el máximo');

$manual = ReservacionErrorCatalog::presentar('ASIGNACION_MANUAL_REQUERIDA');
assertAjustes(
    $manual['descripcion'] === 'Las reservaciones de más de 8 personas requieren asignar las mesas manualmente.',
    'el catálogo interpola el máximo configurado cuando no se le pasa'
);
$admin = ReservacionErrorCatalog::presentar('ASIGNACION_MANUAL_REQUERIDA', ['max_comensales' => 44]);
assertAjustes(str_contains($admin['descripcion'], 'más de 44 personas'), 'un máximo explícito gana al configurado');

putenv('RESERVATION_TEST_MAX_COMENSALES=30');
$_ENV['RESERVATION_TEST_MAX_COMENSALES'] = '30';
ReservacionAjustesService::limpiarCache();
assertAjustes(ReservacionConfig::maxComensalesPublico() === 16, 'el override también se acota al bloque exterior');

putenv('RESERVATION_TEST_MAX_COMENSALES');
unset($_ENV['RESERVATION_TEST_MAX_COMENSALES']);
ReservacionAjustesService::limpiarCache();

// ── Validación del formulario de límites ─────────────────────────────
$minimo = ReservacionConfig::MAX_COMENSALES_PUBLICO_MINIMO;
$tope = ReservacionConfig::MAX_COMENSALES_PUBLICO_TOPE;
$valido = new ConfiguracionReservaciones(['max_comensales_publico' => 10, 'whatsapp_reservas' => '525614818297']);
assertAjustes($valido->validarLimites($minimo, $tope) === [], 'límites válidos no generan errores');
$sinWhatsapp = new ConfiguracionReservaciones(['max_comensales_publico' => 12, 'whatsapp_reservas' => '']);
assertAjustes($sinWhatsapp->whatsapp_reservas === null, 'un WhatsApp vacío se guarda como NULL');
assertAjustes($sinWhatsapp->validarLimites($minimo, $tope) === [], 'el WhatsApp es opcional');
$bajo = new ConfiguracionReservaciones(['max_comensales_publico' => 6]);
assertAjustes(!empty($bajo->validarLimites($minimo, $tope)['error']), 'un máximo bajo el piso se rechaza');
$alto = new ConfiguracionReservaciones(['max_comensales_publico' => 17]);
assertAjustes(!empty($alto->validarLimites($minimo, $tope)['error']), 'un máximo sobre el tope se rechaza');
$corto = new ConfiguracionReservaciones(['max_comensales_publico' => 12, 'whatsapp_reservas' => '5512']);
assertAjustes(!empty($corto->validarLimites($minimo, $tope)['error']), 'un WhatsApp sin lada se rechaza');

// ── Contratos de superficie ──────────────────────────────────────────
$ddl = (string)file_get_contents($root . '/database/ddl.sql');
assertAjustes(str_contains($ddl, 'max_comensales_publico              TINYINT UNSIGNED NOT NULL DEFAULT 12'), 'el DDL declara el máximo con su default');
assertAjustes(str_contains($ddl, 'CHECK (max_comensales_publico BETWEEN 7 AND 16)'), 'el DDL acota el máximo');
assertAjustes(str_contains($ddl, 'whatsapp_reservas                   VARCHAR(20) NULL'), 'el DDL declara el WhatsApp');

$rutas = (string)file_get_contents($root . '/public/index.php');
assertAjustes(str_contains($rutas, "'/admin/configuracion/reservaciones/limites'"), 'existe la ruta de guardado de límites');

$vista = (string)file_get_contents($root . '/views/admin/configuration/reservations.php');
assertAjustes(str_contains($vista, 'action="/admin/configuracion/reservaciones/limites"'), 'el panel de límites tiene su propio formulario');
assertAjustes(str_contains($vista, 'name="max_comensales_publico"') && str_contains($vista, 'name="whatsapp_reservas"'), 'el panel envía los dos campos');

$landing = (string)file_get_contents($root . '/views/home/_reserva.php');
assertAjustes(str_contains($landing, 'data-large-party-whatsapp'), 'el enlace de grupo grande se puede prellenar');
assertAjustes(!preg_match('/más de 12 personas/u', $landing), 'la landing ya no fija el 12 en el texto');

$formJs = (string)file_get_contents($root . '/src/js/modules/form.js');
assertAjustes(str_contains($formJs, '"Más de " + maxGuests'), 'el stepper de la landing rotula el máximo real');
assertAjustes(str_contains($formJs, 'encodeURIComponent('), 'el WhatsApp de grupo grande lleva el mensaje prellenado');

$buzonJs = (string)file_get_contents($root . '/src/js/admin/buzon.js');
assertAjustes(str_contains($buzonJs, 'item.grupo_grande === true'), 'el buzón toma el umbral del servidor');

fwrite(STDOUT, "Reservaciones: límites configurables de la reserva en línea OK\n");
