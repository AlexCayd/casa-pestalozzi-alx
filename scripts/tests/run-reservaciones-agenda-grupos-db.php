<?php

declare(strict_types=1);

/*
 * Agenda de grupos grandes (fase 6) contra una base real.
 *
 * El bloque exterior por franjas de 30 minutos —libre, parcial, tomada— con
 * intervalos semiabiertos de 90 minutos, y la lista de lo que usa el exterior
 * o es de 9 o más. Lo del interior no aparece.
 */

use Model\ActiveRecord;
use Model\ReservacionMesa;
use Services\Reservations\ReservacionAgendaGruposService as Agenda;
use Services\Reservations\ReservacionConfig;
use Services\Scheduling\HorarioOperacionService;

$root = dirname(__DIR__, 2);
$database = getenv('CP_NOTIFICATION_TEST_DATABASE');
if (!is_string($database) || !preg_match('/^cp_notifications_test_[a-f0-9]{12}$/D', $database)) {
    fwrite(STDERR, "Base aislada requerida para probar la agenda de grupos.\n");
    exit(1);
}

require $root . '/includes/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = ActiveRecord::getDB();
$restaurantUnitId = NOTIFICATIONS_TEST_RESTAURANT_UNIT_ID;
$creadas = [];

// Un día abierto de 12:00 a 20:00 al menos, para no depender del día de la semana.
$fecha = null;
$dia = ReservacionConfig::ahora()->modify('+8 days');
for ($i = 0; $i < 14 && $fecha === null; $i++, $dia = $dia->modify('+1 day')) {
    $horario = HorarioOperacionService::obtenerHorarioEfectivo($dia->format('Y-m-d'));
    if (!empty($horario['abierto'])
        && (string)$horario['hora_apertura'] <= '12:00:00'
        && (string)$horario['hora_cierre'] >= '20:00:00') {
        $fecha = $dia->format('Y-m-d');
    }
}
$assert($fecha !== null, 'deploy.sql no trae ningún día abierto de 12:00 a 20:00 en dos semanas.');

$sembrar = static function (string $nombre, int $comensales, string $hora, array $mesaIds) use ($db, $fecha, &$creadas, $restaurantUnitId): int {
    $token = 'fx-agenda-' . substr(md5($nombre . $hora), 0, 16);
    $stmt = $db->prepare(
        "INSERT INTO reservaciones
           (unidad_id, unidad_tipo, nombre, contacto_tipo, contacto, fecha, hora, comensales, nota, origen, estado, request_token)
         VALUES ({$restaurantUnitId}, 'negocio', ?, 'ninguno', NULL, ?, ?, ?, '', 'admin', 'confirmada', ?)"
    );
    $stmt->bind_param('sssis', $nombre, $fecha, $hora, $comensales, $token);
    $stmt->execute();
    $id = (int)$db->insert_id;
    $stmt->close();
    ReservacionMesa::reemplazarAsignacion($id, $mesaIds);
    $creadas[] = $id;
    return $id;
};

$franja = static function (array $diaAgenda, string $hora): ?array {
    foreach ($diaAgenda['franjas'] as $franja) {
        if ($franja['hora'] === $hora) {
            return $franja;
        }
    }
    return null;
};

try {
    notificationsTestAuthenticateStaff();
    $grupo = $sembrar('Agenda Grupo', 10, '14:00:00', [8, 9, 10, 11]);
    $parejaExterior = $sembrar('Agenda Pareja Fuera', 2, '18:00:00', [9]);
    $parejaInterior = $sembrar('Agenda Pareja Dentro', 2, '15:00:00', [2]);

    $agenda = Agenda::rango($fecha, $fecha);
    $assert(count($agenda['dias']) === 1 && count($agenda['bloque']) === 4, 'La agenda no trae el día o el bloque de cuatro mesas.');
    $diaAgenda = $agenda['dias'][0];
    $assert($diaAgenda['abierto'] === true, 'El día elegido no se presenta abierto.');

    $assert(($franja($diaAgenda, '14:00')['estado'] ?? '') === Agenda::TOMADA, 'Con el grupo en las cuatro mesas, las 14:00 no salen tomadas.');
    $assert(($franja($diaAgenda, '13:00')['estado'] ?? '') === Agenda::TOMADA, 'Una entrada a las 13:00 choca con el grupo y no sale tomada.');
    $assert(($franja($diaAgenda, '12:30')['estado'] ?? '') === Agenda::LIBRE, 'Tocarse en el borde (12:30–14:00) se contó como choque.');
    $assert(($franja($diaAgenda, '15:30')['estado'] ?? '') === Agenda::LIBRE, 'El bloque no se libera al terminar el grupo.');
    $parcial = $franja($diaAgenda, '18:00');
    $assert(($parcial['estado'] ?? '') === Agenda::PARCIAL && $parcial['mesas_ocupadas'] === 1,
        'Una pareja en la Mesa 9 no deja la franja parcial.');

    $ids = array_column($diaAgenda['grupos'], 'id');
    $assert(in_array($grupo, $ids, true) && in_array($parejaExterior, $ids, true), 'Falta en la agenda algo que usa el exterior.');
    $assert(!in_array($parejaInterior, $ids, true), 'Una pareja del interior salió en la agenda de grupos.');
    $assert($agenda['resumen']['grupos'] === 1 && $agenda['resumen']['personas'] === 10, 'El resumen no cuenta sólo los grupos grandes.');

    $largo = Agenda::rango($fecha, (new DateTimeImmutable($fecha))->modify('+200 days')->format('Y-m-d'));
    $assert(count($largo['dias']) === Agenda::MAX_DIAS, 'La agenda no acota un rango demasiado largo.');
} finally {
    if ($creadas !== []) {
        $ids = implode(',', array_map('intval', $creadas));
        $db->query("DELETE FROM verificaciones_contacto WHERE reservacion_id IN ({$ids})");
        $db->query("DELETE FROM reservacion_mesas WHERE reservacion_id IN ({$ids})");
        $db->query("DELETE FROM reservacion_reubicaciones WHERE reservacion_id IN ({$ids}) OR causa_reservacion_id IN ({$ids})");
        $db->query("DELETE FROM buzon_notificaciones WHERE entidad_tipo = 'reservacion' AND entidad_id IN ({$ids})");
        $db->query('DELETE FROM reservaciones WHERE id IN (' . implode(',', array_map('intval', $creadas)) . ')');
    }
}

fwrite(STDOUT, "PASS: franjas del bloque (libre, parcial, tomada) con bordes semiabiertos, grupos y resumen.\n");
