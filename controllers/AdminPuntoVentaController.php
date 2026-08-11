<?php

namespace Controllers;

use MVC\Router;
use Services\HorarioReservacionService;
use Services\PosReservacionQueryService;

/**
 * El Punto de Venta vive en /punto-de-venta (vista standalone del POS). Este
 * modulo del admin publica la tarjeta que lo lanza y el listado de mesas con su
 * estado, por eso no tiene API propia: el POS habla directo con
 * PuntoVentaController via /api/*.
 */
class AdminPuntoVentaController
{
    public static function index(Router $router): void
    {
        AdminController::render('punto-de-venta/index', [
            'activeModule' => 'pdv',
            'title' => 'Punto de Venta',
            'topbarTitle' => 'Punto de Venta',
            'topbarSection' => 'Punto de Venta',
            'styles' => ['/build/css/admin/punto-de-venta.css'],
            'scripts' => [],
            'mesas' => self::mesas(),
        ]);
    }

    /**
     * Foto del piso al momento de cargar la página. Misma fuente que alimenta
     * el mapa del POS, así que el estado no puede discrepar entre pantallas.
     * Un fallo de lectura devuelve lista vacía: la tarjeta de lanzamiento es lo
     * importante de este módulo y no debe caerse con ella.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function mesas(): array
    {
        try {
            $fecha = HorarioReservacionService::fechaSeguraGet('');
            $lectura = PosReservacionQueryService::paraFecha($fecha, '', [
                'incluir_inactivas' => false,
                'calcular_conflictos' => false,
            ]);

            if (!($lectura['ok'] ?? false)) {
                return [];
            }

            $mesas = (array)($lectura['mesas_estado'] ?? []);
            usort($mesas, static function (array $a, array $b): int {
                // Las áreas operativas (Caja, Llevar, barras) al final: no son
                // mesas que el mesero abra, sólo destinos de ticket.
                $orden = ((int)($b['reservable'] ?? 0)) <=> ((int)($a['reservable'] ?? 0));
                return $orden !== 0
                    ? $orden
                    : ((int)($a['numero'] ?? 0)) <=> ((int)($b['numero'] ?? 0));
            });

            return $mesas;
        } catch (\Throwable $e) {
            error_log('AdminPuntoVentaController::mesas - ' . $e->getMessage());
            return [];
        }
    }
}
