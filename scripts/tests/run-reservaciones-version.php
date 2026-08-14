<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\PosReservacionSerializer;
use Services\ReservacionAsignacionVersionService;
use Services\ReservacionConfig;

function assertAssignmentVersion(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$marca = '2026-08-11 12:00:00';
$version72 = ReservacionAsignacionVersionService::calcular($marca, [7, 2]);
$version27 = ReservacionAsignacionVersionService::calcular($marca, [2, 7]);
$version91011 = ReservacionAsignacionVersionService::calcular($marca, [11, 10, 9]);
$version91110 = ReservacionAsignacionVersionService::calcular($marca, [9, 11, 10]);

assertAssignmentVersion($version72 === $version27, '[7,2] y [2,7] comparten version');
assertAssignmentVersion($version91011 === $version91110, '[11,10,9] y [9,11,10] comparten version');
assertAssignmentVersion(
    $version72 !== ReservacionAsignacionVersionService::calcular($marca, [7, 9]),
    '[7,2] y [7,9] tienen versiones distintas'
);

$snapshot = PosReservacionSerializer::reservacion(
    [
        'id' => 25,
        'estado' => 'confirmada',
        'fecha' => '2026-08-11',
        'hora' => '13:00:00',
        'nombre' => 'Version canonicalizada',
        'comensales' => 4,
        'mesa_ids' => [7, 2],
        'updated_at' => $marca,
    ],
    null,
    [],
    new DateTimeImmutable('2026-08-11 12:00:00', ReservacionConfig::timezone()),
    ['incluir_contexto_administrativo' => true]
);
assertAssignmentVersion(
    $snapshot['version'] === $version27,
    'serializer y helper comparten la version canonicalizada'
);
assertAssignmentVersion(
    $snapshot['assignment_snapshot']['version'] === $snapshot['version'],
    'el snapshot conserva la version canonicalizada'
);

fwrite(STDOUT, "Reservaciones: version de asignacion canonicalizada OK\n");
