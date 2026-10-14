<?php

declare(strict_types=1);

/*
 * Límites de la reserva en línea contra una base real: el número guardado es
 * el que rige, y los dos formularios que comparten la fila de
 * configuracion_reservaciones (límites y recordatorio) no se pisan entre sí.
 */

use Model\ConfiguracionReservaciones;
use Services\Notifications\ReservacionNotificacionConfigService;
use Services\Reservations\ReservacionAjustesService;
use Services\Reservations\ReservacionConfig;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar los límites de reservación.\n");
    exit(1);
}

require $root . '/includes/app.php';

// La base temporal la comparten todas las suites de la corrida: al salir, la
// fila vuelve a los valores de ddl.sql pase lo que pase. Sin esto, el máximo de
// 9 que se prueba aquí convertía a los grupos de 10 de la suite siguiente en
// «asignación manual».
register_shutdown_function(static function (): void {
    $db = \Model\ActiveRecord::getDB();
    if ($db) {
        $db->query(
            "UPDATE configuracion_reservaciones
                SET max_comensales_publico = 12, whatsapp_reservas = NULL,
                    recordatorio_dia_anterior_activo = 0, hora_recordatorio = '18:00:00'
              WHERE id = 1"
        );
    }
});

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

// La siembra de ddl.sql deja los valores históricos.
ReservacionAjustesService::limpiarCache();
$assert(ReservacionConfig::maxComensalesPublico() === 12, 'La base recién creada no arrancó con el máximo de 12.');
$assert(ReservacionAjustesService::whatsappReservas() === null, 'La base recién creada trae un WhatsApp configurado.');

// El recordatorio se guarda primero: los límites no deben borrarlo.
$recordatorio = ReservacionNotificacionConfigService::guardar([
    'recordatorio_dia_anterior_activo' => true,
    'hora_recordatorio' => '17:30',
], null);
$assert(($recordatorio['ok'] ?? false) === true, 'No se pudo guardar el recordatorio de prueba.');

$limites = new ConfiguracionReservaciones([
    'max_comensales_publico' => 9,
    'whatsapp_reservas' => '525500000001',
]);
$assert($limites->validarLimites(
    ReservacionConfig::MAX_COMENSALES_PUBLICO_MINIMO,
    ReservacionConfig::MAX_COMENSALES_PUBLICO_TOPE
) === [], 'Los límites de prueba no pasaron la validación.');
$assert($limites->guardarLimites(), 'No se pudieron guardar los límites.');
ReservacionAjustesService::limpiarCache();

$assert(ReservacionConfig::maxComensalesPublico() === 9, 'El máximo guardado no es el que rige.');
$assert(ReservacionConfig::umbralGrupoGrande() === 9, 'El umbral de grupo grande no siguió al máximo.');
$assert(ReservacionConfig::whatsappNumero() === '525500000001', 'El WhatsApp guardado no es el que rige.');
$assert(
    ReservacionConfig::whatsappUrl('Hola') === 'https://wa.me/525500000001?text=Hola',
    'El enlace de catas no usa el WhatsApp guardado.'
);
$assert(ReservacionConfig::contactoPublico()['max_comensales'] === 9, 'La landing no recibe el máximo guardado.');

$tras = ReservacionNotificacionConfigService::obtener();
$assert($tras['recordatorio_dia_anterior_activo'] === true, 'Guardar los límites apagó el recordatorio.');
$assert($tras['hora_recordatorio'] === '17:30', 'Guardar los límites cambió la hora del recordatorio.');

// Y al revés: el recordatorio no debe tocar los límites.
$otra = ReservacionNotificacionConfigService::guardar([
    'recordatorio_dia_anterior_activo' => false,
    'hora_recordatorio' => '19:00',
], null);
$assert(($otra['ok'] ?? false) === true, 'No se pudo volver a guardar el recordatorio.');
ReservacionAjustesService::limpiarCache();
$assert(ReservacionConfig::maxComensalesPublico() === 9, 'Guardar el recordatorio pisó el máximo.');
$assert(ReservacionConfig::whatsappNumero() === '525500000001', 'Guardar el recordatorio pisó el WhatsApp.');

// Vaciar el WhatsApp devuelve el número de respaldo.
$sinWhatsapp = new ConfiguracionReservaciones(['max_comensales_publico' => 9, 'whatsapp_reservas' => '']);
$assert($sinWhatsapp->guardarLimites(), 'No se pudo vaciar el WhatsApp.');
ReservacionAjustesService::limpiarCache();
$assert(ReservacionConfig::whatsappNumero() === ReservacionConfig::whatsappRespaldo(), 'Sin WhatsApp no rige el de respaldo.');

// El CHECK de la tabla respalda la validación del formulario.
$db = \Model\ActiveRecord::getDB();
$rechazado = false;
try {
    $rechazado = $db->query('UPDATE configuracion_reservaciones SET max_comensales_publico = 30 WHERE id = 1') === false;
} catch (\mysqli_sql_exception $e) {
    $rechazado = true;
}
$assert($rechazado, 'La base aceptó un máximo fuera del rango 7–16.');

fwrite(STDOUT, "run-reservaciones-ajustes-db.php: PASS\n");
