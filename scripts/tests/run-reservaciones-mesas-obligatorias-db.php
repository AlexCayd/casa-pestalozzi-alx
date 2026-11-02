<?php

declare(strict_types=1);

/*
 * Toda reservación confirmada tiene mesas. Contra una base real:
 * - el alta asigna aunque se pida no hacerlo;
 * - un grupo mayor al máximo sin mesas elegidas no se crea;
 * - con mesas elegidas, sí;
 * - un cambio que deja sin lugar no se guarda y conserva las mesas de antes.
 */

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\Reservations\ReservacionAdministrativaService as Admin;
use Services\Reservations\ReservacionConfig;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar las mesas obligatorias.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$fecha = ReservacionConfig::ahora()->modify('+5 days')->format('Y-m-d');
$hora = '13:00';
$creadas = [];

$alta = static function (array $extra) use ($fecha, $hora, &$creadas): array {
    $resultado = Admin::crear(array_merge([
        'nombre' => 'Mesas Obligatorias',
        'contacto_tipo' => 'email',
        'contacto' => 'mesas.obligatorias@example.test',
        'fecha' => $fecha,
        'hora' => $hora,
        'comensales' => 2,
        'request_token' => 'fx-mesas-' . bin2hex(random_bytes(8)),
    ], $extra));
    if (!empty($resultado['id'])) {
        $creadas[] = (int)$resultado['id'];
    }
    return $resultado;
};

try {
    notificationsTestAuthenticateStaff();
    // Pedir «no asignar» ya no deja una reservación sin mesas.
    $sinPedir = $alta(['asignar_automaticamente' => '0']);
    $assert(($sinPedir['ok'] ?? false) === true && !empty($sinPedir['id']), 'El alta normal no se creó: ' . json_encode($sinPedir['codigo'] ?? null));
    $assert(ReservacionMesa::obtenerIdsPorReservacion((int)$sinPedir['id']) !== [], 'Un alta con «asignar_automaticamente=0» quedó sin mesas.');

    // Un grupo mayor al máximo sin mesas elegidas: no se crea.
    $grande = ReservacionConfig::maxComensalesPublico() + 2;
    $sinMesas = $alta(['nombre' => 'Grupo Sin Mesas', 'comensales' => $grande]);
    $assert(($sinMesas['ok'] ?? true) === false && ($sinMesas['codigo'] ?? '') === Admin::MESAS_REQUERIDAS, 'Un grupo grande sin mesas se creó o falló con otro código: ' . json_encode($sinMesas['codigo'] ?? null));
    $assert(($sinMesas['requiere_seleccion_mesas'] ?? false) === true, 'El rechazo no dice que hay que elegir mesas.');
    $huerfana = $db->query("SELECT COUNT(*) AS n FROM reservaciones WHERE nombre = 'Grupo Sin Mesas'")->fetch_assoc();
    $assert((int)$huerfana['n'] === 0, 'El rechazo dejó una reservación guardada.');

    // Con sus mesas elegidas, sí.
    $conMesas = $alta(['nombre' => 'Grupo Con Mesas', 'comensales' => $grande, 'mesa_ids' => [8, 9, 10, 11]]);
    $assert(($conMesas['ok'] ?? false) === true, 'El grupo con mesas elegidas no se creó: ' . json_encode($conMesas['codigo'] ?? null));
    $idsGrupo = ReservacionMesa::obtenerIdsPorReservacion((int)$conMesas['id']);
    sort($idsGrupo);
    $assert($idsGrupo === [8, 9, 10, 11], 'El grupo no quedó en las mesas elegidas.');

    // Un cambio a una hora sin lugar no se guarda y conserva las mesas.
    $antes = ReservacionMesa::obtenerIdsPorReservacion((int)$sinPedir['id']);
    $llenar = [];
    foreach ([1, 2, 3, 4, 5, 6, 7] as $mesa) {
        $r = $alta(['nombre' => 'Relleno ' . $mesa, 'hora' => '15:00', 'comensales' => 4, 'mesa_ids' => [$mesa]]);
        $assert(($r['ok'] ?? false) === true, 'No se pudo llenar el interior para la prueba: ' . json_encode($r['codigo'] ?? null));
        $llenar[] = $r;
    }
    // El exterior ya lo ocupa otro grupo a las 15:00.
    $alta(['nombre' => 'Relleno Exterior', 'hora' => '15:00', 'comensales' => $grande, 'mesa_ids' => [8, 9, 10, 11]]);
    $cambio = Admin::actualizar((int)$sinPedir['id'], [
        'nombre' => 'Mesas Obligatorias',
        'contacto_tipo' => 'email',
        'contacto' => 'mesas.obligatorias@example.test',
        'fecha' => $fecha,
        'hora' => '15:00',
        'comensales' => 2,
    ]);
    $assert(($cambio['ok'] ?? true) === false && ($cambio['codigo'] ?? '') === Admin::MESAS_REQUERIDAS, 'Un cambio sin lugar se guardó: ' . json_encode($cambio['codigo'] ?? null));
    $assert(ReservacionMesa::obtenerIdsPorReservacion((int)$sinPedir['id']) === $antes, 'El cambio rechazado tocó las mesas de antes.');
    $horaGuardada = $db->query('SELECT hora FROM reservaciones WHERE id = ' . (int)$sinPedir['id'])->fetch_assoc();
    $assert(substr((string)$horaGuardada['hora'], 0, 5) === $hora, 'El cambio rechazado movió la hora.');

    echo "Reservaciones: toda reservación confirmada tiene mesas OK\n";
} finally {
    $ids = $creadas !== [] ? implode(',', array_map('intval', $creadas)) : '0';
    $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$ids})");
    $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$ids})");
    $db->query("DELETE FROM reservacion_reubicaciones WHERE reservacion_id IN ({$ids}) OR causa_reservacion_id IN ({$ids})");
    $db->query("DELETE FROM buzon_notificaciones WHERE entidad_tipo = 'reservacion' AND entidad_id IN ({$ids})");
    foreach ($creadas as $id) {
        $db->query('DELETE FROM reservaciones WHERE id = ' . (int)$id);
    }
}
