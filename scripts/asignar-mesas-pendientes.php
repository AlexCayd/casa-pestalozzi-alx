<?php

declare(strict_types=1);

/*
 * Toda reservación confirmada tiene mesas. El servidor ya no guarda una sin
 * ellas, pero las que se crearon antes de esa regla pueden seguir vacías. Este
 * script les asigna mesas con la asignación automática de siempre, de hoy en
 * adelante, y lista las que no pudo resolver (un grupo mayor al máximo, o una
 * hora sin lugar): ésas se resuelven en el POS con «Asignar».
 *
 *   php scripts/asignar-mesas-pendientes.php            # aplica
 *   php scripts/asignar-mesas-pendientes.php --simular  # sólo lista
 */

require __DIR__ . '/../includes/app.php';

use Model\ActiveRecord;
use Services\Reservations\AsignacionMesasService;
use Services\Reservations\ReservacionConfig;

$simular = in_array('--simular', $argv, true);
$hoy = ActiveRecord::escaparString(ReservacionConfig::fechaActual());
$resultado = ActiveRecord::getDB()->query(
    "SELECT r.id, r.nombre, r.fecha, r.hora, r.comensales
     FROM reservaciones r
     WHERE r.estado = 'confirmada'
       AND r.fecha >= '{$hoy}'
       AND NOT EXISTS (SELECT 1 FROM reservacion_mesas rm WHERE rm.reservacion_id = r.id)
     ORDER BY r.fecha, r.hora, r.id"
);
$pendientes = $resultado ? $resultado->fetch_all(MYSQLI_ASSOC) : [];

$asignadas = 0;
$sinResolver = [];
foreach ($pendientes as $fila) {
    $etiqueta = sprintf('#%d %s · %s %s · %d pers.', $fila['id'], $fila['nombre'], $fila['fecha'], substr($fila['hora'], 0, 5), $fila['comensales']);
    if ((int)$fila['comensales'] > ReservacionConfig::maxComensalesPublico()) {
        $sinResolver[] = $etiqueta . ' — grupo mayor al máximo: elige sus mesas en el POS';
        continue;
    }
    if ($simular) {
        fwrite(STDOUT, "[simular] {$etiqueta}\n");
        continue;
    }
    $asignacion = AsignacionMesasService::asignarAutomaticamente((int)$fila['id']);
    if (($asignacion['ok'] ?? false) === true) {
        $asignadas++;
        fwrite(STDOUT, "asignada   {$etiqueta} → mesas " . implode(', ', (array)($asignacion['mesa_ids'] ?? [])) . "\n");
    } else {
        $sinResolver[] = $etiqueta . ' — ' . (string)($asignacion['codigo'] ?? 'sin lugar');
    }
}

fwrite(STDOUT, sprintf("\nSin mesas: %d · asignadas ahora: %d · por resolver: %d\n", count($pendientes), $asignadas, count($sinResolver)));
foreach ($sinResolver as $linea) {
    fwrite(STDOUT, "  pendiente  {$linea}\n");
}
