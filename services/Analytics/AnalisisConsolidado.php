<?php

declare(strict_types=1);

/**
 * Análisis de datos de Global: los dos negocios comparados, para el dueño.
 *
 * Global no vende: lee a Restaurante y a Panadería con las mismas consultas
 * de sus pantallas (AnalisisNegocio), así que una cifra de aquí es la misma
 * que ve cada negocio. Entrega:
 *
 *   - por negocio: ventas, tickets, ticket promedio, piezas y piezas por
 *     ticket, contra el periodo anterior, su venta por día y lo más vendido;
 *   - el total de la organización y la parte de cada negocio en la venta.
 *
 * Las analíticas diagnósticas (ingeniería de menú, horas, qué se pide junto)
 * no se repiten aquí: son de cada menú y mezclarlas no dice nada.
 *
 * El permiso es «Análisis de datos» en Global (ver), la misma regla de lectura
 * consolidada que Almacenes y Finanzas.
 */

namespace Services\Analytics;

use Services\Security\ContextoNegocio;
use Services\Security\Contextos;
use Services\Shared\RangoPeriodo;

final class AnalisisConsolidado
{
    private const TOP = 5;

    /**
     * @param array<string, mixed> $rango RangoPeriodo::resolver()
     * @return array<string, mixed>
     */
    public static function armar(array $rango): array
    {
        ContextoNegocio::exigir('analytics', 'ver', 'global');
        $db = ContextoNegocio::db();
        $start = (string) $rango['start'];
        $end = (string) $rango['end'];

        $negocios = [];
        $etiquetas = [];
        foreach (AnalisisNegocio::NEGOCIOS as $slug) {
            $unidad = ContextoNegocio::unidad($slug);
            $actual = AnalisisNegocio::totales($db, $unidad, $start, $end);
            $previo = AnalisisNegocio::totales($db, $unidad, (string) $rango['prevStart'], (string) $rango['prevEnd']);
            $porDia = AnalisisNegocio::ventasPorDia($db, $unidad, $start, $end);
            if ($etiquetas === []) {
                $etiquetas = array_map(static fn (string $d): string => date('d/m', strtotime($d)), array_keys($porDia));
            }
            $negocios[$slug] = [
                'slug' => $slug,
                'nombre' => Contextos::NOMBRES[$slug],
                'ventas' => $actual['ventas'],
                'tickets' => $actual['tickets'],
                'piezas' => $actual['piezas'],
                'ticketPromedio' => $actual['tickets'] > 0 ? $actual['ventas'] / $actual['tickets'] : null,
                'piezasPorTicket' => $actual['tickets'] > 0 ? $actual['piezas'] / $actual['tickets'] : null,
                'previo' => [
                    'ventas' => $previo['ventas'],
                    'tickets' => $previo['tickets'],
                    'piezas' => $previo['piezas'],
                    'ticketPromedio' => $previo['tickets'] > 0 ? $previo['ventas'] / $previo['tickets'] : null,
                    'piezasPorTicket' => $previo['tickets'] > 0 ? $previo['piezas'] / $previo['tickets'] : null,
                ],
                'deltaVentas' => RangoPeriodo::delta($actual['ventas'], $previo['ventas']),
                'deltaTickets' => RangoPeriodo::delta((float) $actual['tickets'], (float) $previo['tickets']),
                // Sin tickets en uno de los dos periodos no hay razón que comparar.
                'deltaPiezasPorTicket' => $actual['tickets'] > 0 && $previo['tickets'] > 0
                    ? RangoPeriodo::delta($actual['piezas'] / $actual['tickets'], $previo['piezas'] / $previo['tickets'])
                    : null,
                'porDia' => array_map(static fn ($v): float => round($v, 2), array_values($porDia)),
                'masVendidos' => AnalisisNegocio::masVendidos($db, $unidad, $start, $end, self::TOP),
            ];
        }

        return [
            'negocios' => $negocios,
            'etiquetasDia' => $etiquetas,
            'total' => self::total($negocios),
        ];
    }

    /** @return array<string, mixed> */
    private static function total(array $negocios): array
    {
        $t = ['ventas' => 0.0, 'tickets' => 0, 'piezas' => 0, 'previo' => ['ventas' => 0.0, 'tickets' => 0]];
        foreach ($negocios as $n) {
            $t['ventas'] += $n['ventas'];
            $t['tickets'] += $n['tickets'];
            $t['piezas'] += $n['piezas'];
            $t['previo']['ventas'] += $n['previo']['ventas'];
            $t['previo']['tickets'] += $n['previo']['tickets'];
        }
        // El promedio del total sale de los totales: promediar los dos tickets
        // promedio pesaría igual al negocio que vende diez veces menos.
        $t['ticketPromedio'] = $t['tickets'] > 0 ? $t['ventas'] / $t['tickets'] : null;
        $t['deltaVentas'] = RangoPeriodo::delta($t['ventas'], $t['previo']['ventas']);
        $t['participacion'] = [];
        foreach ($negocios as $slug => $n) {
            $t['participacion'][$slug] = $t['ventas'] > 0 ? $n['ventas'] / $t['ventas'] * 100 : null;
        }

        return $t;
    }

}
