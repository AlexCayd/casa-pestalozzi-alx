<?php

declare(strict_types=1);

/*
 * Registra como ausencia (no_show) las reservaciones confirmadas cuya
 * tolerancia de llegada ya venció, y libera sus mesas.
 *
 * La aplicación ya lo hace sola en cada lectura del POS y del panel; esto es
 * para un cron, por si un día nadie abre ninguna pantalla:
 *
 *   php scripts/procesar-ausencias.php
 */

require __DIR__ . '/../includes/app.php';

$resultado = \Services\Reservations\ReservacionAusenciaAutomaticaService::procesar();
fwrite(STDOUT, 'Ausencias registradas: ' . $resultado['procesadas']
    . ($resultado['ids'] ? ' (' . implode(', ', $resultado['ids']) . ')' : '') . PHP_EOL);
