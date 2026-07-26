<?php

/**
 * Materializa retenciones públicas vencidas.
 *
 * Uso:
 *   php scripts/expire_reservation_holds.php
 *   php scripts/expire_reservation_holds.php --limit=250
 *   php scripts/expire_reservation_holds.php --dry-run
 */

declare(strict_types=1);

use Services\ReservacionService;

require __DIR__ . '/../includes/app.php';

$limite = 100;
$simulacion = in_array('--dry-run', $argv ?? [], true);
foreach ($argv ?? [] as $argumento) {
    if (preg_match('/^--limit=(\d+)$/', (string)$argumento, $coincidencia) === 1) {
        $limite = (int)$coincidencia[1];
    }
}

$resultado = ReservacionService::expirarRetenciones($limite, $simulacion);
echo json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($resultado['ok'] ?? false) ? 0 : 1);
