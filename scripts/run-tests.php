<?php

/** Ejecuta las suites reproducibles sin ocultar el código de salida. */

declare(strict_types=1);

$suites = [
    __DIR__ . '/../tests/ReservacionContactoEtapa1Test.php',
    __DIR__ . '/../tests/ReservacionPublicaEtapa2Test.php',
    __DIR__ . '/../tests/ReservacionEtapa3Test.php',
];

foreach ($suites as $suite) {
    $comando = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($suite);
    passthru($comando, $status);
    if ($status !== 0) {
        exit($status);
    }
}
