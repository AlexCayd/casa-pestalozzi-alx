<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\ReservacionConfig;

/** @param mixed $condition */
function assertStaticContract($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$config = file_get_contents(dirname(__DIR__, 2) . '/services/ReservacionConfig.php');
assertStaticContract(is_string($config), 'se pudo leer configuración');
assertStaticContract(str_contains($config, 'BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS'), 'existe límite POS canónico');
assertStaticContract(str_contains($config, 'INICIO_SERVICIO_ANTICIPADO_MINUTOS'), 'existe anticipación de servicio canónica');
assertStaticContract(!str_contains($config, 'MINUTOS_PREVIOS_BLOQUEO'), 'no existe alias de bloqueo ambiguo');
assertStaticContract(!str_contains($config, 'LLEGADA_ANTICIPADA_MINUTOS'), 'no existe alias de llegada ambiguo');
assertStaticContract(ReservacionConfig::DURACION_RESERVACION_MINUTOS === 90, 'duración de producción permanece en 90');
assertStaticContract(ReservacionConfig::AVISO_RESERVACION_PROXIMA_MINUTOS === 60, 'aviso de producción permanece en 60');
assertStaticContract(ReservacionConfig::BLOQUEO_WALKIN_ANTES_RESERVACION_MINUTOS === 30, 'bloqueo de producción permanece en 30');
assertStaticContract(ReservacionConfig::TOLERANCIA_LLEGADA_MINUTOS === 15, 'tolerancia de producción permanece en 15');

$fuentes = [
    'services/MesaEstadoService.php',
    'services/ReservacionVigenciaService.php',
    'services/ReservacionPoliticaPosService.php',
    'services/PuntoVentaReservacionService.php',
    'services/PosMesaProjectionPresenter.php',
    'services/PosReservacionSerializer.php',
    'src/js/modules/punto-de-venta.js',
    'src/js/admin/reservations/operation.js',
];
foreach ($fuentes as $fuente) {
    $contenido = file_get_contents(dirname(__DIR__, 2) . '/' . $fuente);
    assertStaticContract(is_string($contenido), "se pudo leer {$fuente}");
    assertStaticContract(!str_contains($contenido, 'MINUTOS_PREVIOS_BLOQUEO'), "sin alias viejo en {$fuente}");
    assertStaticContract(!str_contains($contenido, 'LLEGADA_ANTICIPADA_MINUTOS'), "sin alias viejo en {$fuente}");
    if (str_starts_with($fuente, 'src/js/')) {
        assertStaticContract(!str_contains($contenido, '0_30'), "sin ventana local 0_30 en {$fuente}");
        assertStaticContract(!str_contains($contenido, '30_60'), "sin ventana local 30_60 en {$fuente}");
    }
}

fwrite(STDOUT, "Reservaciones: configuración estática OK\n");
