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
 *   - el total de la organización y la parte de cada negocio en la venta;
 *   - los PRODUCTOS DE LA CASA (producto_estandares) lado a lado: el precio
 *     de oferta vigente en cada negocio y lo que cada uno vendió de él. Se
 *     une por producto_id, que es la identidad comercial; nunca por nombre.
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
        $unidades = [];
        $etiquetas = [];
        foreach (AnalisisNegocio::NEGOCIOS as $slug) {
            $unidad = ContextoNegocio::unidad($slug);
            $unidades[$slug] = $unidad;
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
            'productosCasa' => self::productosCasa($db, $unidades, $start, $end),
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

    /**
     * Cada producto de la casa con su oferta en cada negocio (precio vigente y
     * si está activa) y lo vendido en el periodo. Un negocio que no lo ofrece
     * sale como null: no es lo mismo que ofrecerlo y no venderlo.
     *
     * @param array<string, int> $unidades
     * @return list<array<string, mixed>>
     */
    private static function productosCasa(\mysqli $db, array $unidades, string $start, string $end): array
    {
        $productos = self::filas($db, 'SELECT p.id, p.nombre FROM producto_estandares pe JOIN productos p ON p.id = pe.producto_id ORDER BY p.nombre', []);
        if ($productos === []) {
            return [];
        }
        $porProducto = [];
        foreach ($productos as $p) {
            $porProducto[(int) $p['id']] = ['id' => (int) $p['id'], 'nombre' => (string) $p['nombre'], 'negocios' => array_fill_keys(array_keys($unidades), null)];
        }
        $slugDe = array_flip($unidades);
        $ids = array_keys($porProducto);
        $en = implode(', ', array_fill(0, count($ids), '?'));
        $enUnidades = implode(', ', array_fill(0, count($unidades), '?'));

        foreach (self::filas($db, "SELECT o.producto_id, o.unidad_id, o.precio, o.activo FROM ofertas_producto o
                WHERE o.producto_id IN ({$en}) AND o.unidad_id IN ({$enUnidades})", array_merge($ids, array_values($unidades))) as $o) {
            $porProducto[(int) $o['producto_id']]['negocios'][$slugDe[(int) $o['unidad_id']]] = [
                'precio' => (float) $o['precio'],
                'activo' => (int) $o['activo'] === 1,
                'piezas' => 0,
                'ventas' => 0.0,
                'precioCobrado' => null,
            ];
        }

        $cierre = 'COALESCE(t.hora_cierre, t.hora_apertura)';
        foreach (self::filas($db, "SELECT ti.producto_id, ti.unidad_id, SUM(ti.cantidad) AS piezas, SUM(ti.precio * ti.cantidad) AS ventas
                FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id AND t.unidad_id = ti.unidad_id
               WHERE ti.producto_id IN ({$en}) AND ti.unidad_id IN ({$enUnidades}) AND t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                 AND {$cierre} >= ? AND {$cierre} <= ?
               GROUP BY ti.producto_id, ti.unidad_id", array_merge($ids, array_values($unidades), [$start . ' 00:00:00', $end . ' 23:59:59'])) as $v) {
            $slug = $slugDe[(int) $v['unidad_id']];
            $celda = &$porProducto[(int) $v['producto_id']]['negocios'][$slug];
            // Vendido sin oferta vigente (se retiró después): se cuenta igual.
            $celda ??= ['precio' => null, 'activo' => false, 'piezas' => 0, 'ventas' => 0.0, 'precioCobrado' => null];
            $celda['piezas'] = (int) $v['piezas'];
            $celda['ventas'] = (float) $v['ventas'];
            $celda['precioCobrado'] = (int) $v['piezas'] > 0 ? (float) $v['ventas'] / (int) $v['piezas'] : null;
            unset($celda);
        }

        return array_values($porProducto);
    }

    private static function filas(\mysqli $db, string $sql, array $args): array
    {
        $s = $db->prepare($sql);
        $s->execute($args);

        return $s->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
