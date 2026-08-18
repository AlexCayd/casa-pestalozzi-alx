<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\HorarioReservacionService;
use Services\ReservacionConfig;

/** @param mixed $condition */
function assertMapSchedule($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$ahora = new DateTimeImmutable('2026-08-18 22:00:00', ReservacionConfig::timezone());
$horarios = ['13:00', '13:30', '18:30', '21:00'];

$futuroSinHora = HorarioReservacionService::resolverHorarioMapa(
    '2026-08-19',
    '',
    $horarios,
    $ahora
);
assertMapSchedule($futuroSinHora['hora_resuelta'] === '13:00', 'fecha futura sin hora usa el primer horario');

$futuroConHora = HorarioReservacionService::resolverHorarioMapa(
    '2026-08-19',
    '18:30',
    $horarios,
    $ahora
);
assertMapSchedule($futuroConHora['hora_resuelta'] === '18:30', 'hora explícita válida se conserva en fecha futura');

$hoySinHora = HorarioReservacionService::resolverHorarioMapa(
    '2026-08-18',
    '',
    $horarios,
    $ahora
);
assertMapSchedule($hoySinHora['hora_resuelta'] === '21:00', 'día actual conserva la selección operativa vigente');

fwrite(STDOUT, "Reservaciones: resolución inicial del mapa OK\n");
