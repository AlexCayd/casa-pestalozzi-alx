<?php

declare(strict_types=1);

/**
 * El tablero descriptivo de Análisis de datos («qué pasó») de UN negocio
 * vendedor, acotado por su unidad: indicadores de cabecera, gráficas y los
 * últimos tickets cobrados.
 *
 * Era el cuerpo de AdminController::construirAnalytics(), escrito para
 * Restaurante. Aquí se conserva consulta a consulta para Restaurante (la
 * pantalla sale igual) y se adapta a Panadería, que vende en CAJA, café y pan
 * para llevar: sin propinas, comensales, mesas ni reservaciones, y con lo que
 * importa en mostrador (piezas por ticket). AnalisisConsolidado lee a los dos
 * negocios con estas mismas consultas.
 *
 * Las ventas se cuentan como en el resto del panel: tickets cerrados, sin
 * renglones cancelados, en el día del COBRO (COALESCE(hora_cierre,
 * hora_apertura)). Sólo lee; quien llama ya autorizó el ámbito.
 */

namespace Services\Analytics;

use Services\Inventory\InventarioError;
use Services\Shared\RangoPeriodo;

final class AnalisisNegocio
{
    public const NEGOCIOS = ['restaurante', 'panaderia'];

    private const CIERRE = 'COALESCE(t.hora_cierre, t.hora_apertura)';

    /**
     * Ventas cobradas y tickets de un tramo: lo que comparan la cabecera y el
     * consolidado.
     *
     * @return array{ventas: float, tickets: int, comensales: int, piezas: int}
     */
    public static function totales(\mysqli $db, int $unidad, string $start, string $end): array
    {
        [$desde, $hasta] = self::limites($start, $end);
        $cierre = self::CIERRE;
        $r = self::fila($db, "SELECT COUNT(*) AS n, COUNT(sub.ticket_id) AS con_consumo, COALESCE(SUM(t.comensales), 0) AS comensales, COALESCE(SUM(sub.total), 0) AS ventas
               FROM tickets t
               LEFT JOIN (SELECT ticket_id, SUM(precio * cantidad) AS total FROM ticket_items
                           WHERE estado <> 'cancelado' AND unidad_id = ? GROUP BY ticket_id) sub ON sub.ticket_id = t.id
              WHERE t.estado = 'cerrado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ?", [$unidad, $unidad, $desde, $hasta]);
        $p = self::fila($db, "SELECT COALESCE(SUM(ti.cantidad), 0) AS u
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ?", [$unidad, $desde, $hasta]);

        return [
            'ventas' => (float) ($r['ventas'] ?? 0),
            'tickets' => (int) ($r['n'] ?? 0),
            'conConsumo' => (int) ($r['con_consumo'] ?? 0),
            'comensales' => (int) ($r['comensales'] ?? 0),
            'piezas' => (int) ($p['u'] ?? 0),
        ];
    }

    /**
     * Venta por día del tramo, con un cero por cada día sin venta para que la
     * serie tenga el largo del periodo.
     *
     * @return array<string, float> 'Y-m-d' => venta
     */
    public static function ventasPorDia(\mysqli $db, int $unidad, string $start, string $end): array
    {
        $dias = [];
        for ($c = strtotime($start), $l = strtotime($end); $c <= $l; $c = strtotime('+1 day', $c)) {
            $dias[date('Y-m-d', $c)] = 0.0;
        }
        [$desde, $hasta] = self::limites($start, $end);
        $cierre = self::CIERRE;
        foreach (self::filas($db, "SELECT DATE({$cierre}) AS d, SUM(ti.precio * ti.cantidad) AS total
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ?
              GROUP BY d", [$unidad, $desde, $hasta]) as $r) {
            if (isset($dias[$r['d']])) $dias[$r['d']] = (float) $r['total'];
        }

        return $dias;
    }

    /**
     * Lo más vendido por piezas.
     *
     * @return list<array{nombre: string, piezas: int, ventas: float}>
     */
    public static function masVendidos(\mysqli $db, int $unidad, string $start, string $end, int $tope = 7): array
    {
        [$desde, $hasta] = self::limites($start, $end);
        $cierre = self::CIERRE;
        $filas = self::filas($db, "SELECT ti.nombre, SUM(ti.cantidad) AS u, SUM(ti.precio * ti.cantidad) AS v
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ?
              GROUP BY ti.nombre ORDER BY u DESC LIMIT " . max(1, min(50, $tope)), [$unidad, $desde, $hasta]);

        return array_map(static fn (array $r): array => ['nombre' => (string) $r['nombre'], 'piezas' => (int) $r['u'], 'ventas' => (float) $r['v']], $filas);
    }

    /** Informe documental del negocio a partir de los agregados del tablero. */
    public static function informe(\mysqli $db, int $unidad, array $rango, string $generadoEn): array
    {
        $actual = self::totales($db, $unidad, $rango['start'], $rango['end']);
        $previo = self::totales($db, $unidad, $rango['prevStart'], $rango['prevEnd']);
        $porDia = self::ventasPorDia($db, $unidad, $rango['start'], $rango['end']);
        $productos = self::masVendidos($db, $unidad, $rango['start'], $rango['end'], 7);
        $serie = RangoPeriodo::agruparSerieInforme($porDia);
        $top = [];
        foreach ($productos as $producto) $top[] = [$producto['nombre'], $producto['piezas'], $producto['ventas']];
        $resumen = $actual['tickets'] === 0
            ? 'No hubo tickets cerrados en el periodo.'
            : 'Se cerraron ' . $actual['tickets'] . ' tickets; ' . $actual['conConsumo'] . ' tenían partidas no canceladas y ' . ($actual['tickets'] - $actual['conConsumo']) . ' no tenían.';
        if ($productos) $resumen .= ' El producto con más piezas fue ' . $productos[0]['nombre'] . ' (' . $productos[0]['piezas'] . ').';
        $variacion = RangoPeriodo::delta($actual['ventas'], $previo['ventas']);
        $resumen .= $variacion === null
            ? ' Las ventas no tienen base comparable en el periodo anterior.'
            : ' Las ventas variaron ' . number_format($variacion, 1) . ' % frente al periodo anterior.';
        return [
            'titulo' => 'Informe de desempeño comercial', 'contexto' => 'Restaurante',
            'periodo' => ['inicio' => $rango['start'], 'fin' => $rango['end'],
                'comparacion' => ['inicio' => $rango['prevStart'], 'fin' => $rango['prevEnd']]],
            'generadoEn' => $generadoEn, 'corteDatos' => $generadoEn, 'resumen' => $resumen,
            'kpis' => [
                ['etiqueta' => 'Ventas', 'valor' => $actual['ventas'], 'formato' => 'moneda',
                    'comparacion' => RangoPeriodo::delta($actual['ventas'], $previo['ventas'])],
                ['etiqueta' => 'Tickets cerrados', 'valor' => $actual['tickets'], 'formato' => 'entero'],
                ['etiqueta' => 'Ticket promedio', 'valor' => $actual['tickets'] > 0 ? $actual['ventas'] / $actual['tickets'] : null, 'formato' => 'moneda'],
                ['etiqueta' => 'Piezas vendidas', 'valor' => $actual['piezas'], 'formato' => 'entero'],
                ['etiqueta' => 'Comensales en tickets cerrados', 'valor' => $actual['comensales'], 'formato' => 'entero'],
                ['etiqueta' => 'Con consumo', 'valor' => $actual['conConsumo'], 'formato' => 'entero'],
                ['etiqueta' => 'Sin consumo', 'valor' => $actual['tickets'] - $actual['conConsumo'], 'formato' => 'entero'],
            ],
            'secciones' => [
                ['titulo' => 'Ventas por ' . $serie['modo'], 'tipo' => 'serie', 'columnas' => ['Periodo', 'Ventas'], 'formatos' => ['texto', 'moneda'], 'filas' => $serie['filas']],
                ['titulo' => 'Productos más vendidos por piezas', 'tipo' => 'tabla', 'columnas' => ['Producto', 'Piezas', 'Ventas'], 'formatos' => ['texto', 'entero', 'moneda'], 'filas' => $top],
                ['titulo' => 'Comparación de ventas', 'tipo' => 'tabla',
                    'columnas' => ['Anterior', 'Actual', 'Diferencia', 'Variación'],
                    'formatos' => ['moneda', 'moneda', 'moneda', 'porcentaje'], 'filas' => [[$previo['ventas'], $actual['ventas'], $actual['ventas'] - $previo['ventas'], RangoPeriodo::delta($actual['ventas'], $previo['ventas'])]]],
            ],
            'notas' => [
                'Ventas y piezas excluyen partidas canceladas y se atribuyen a tickets cerrados por fecha de cierre o apertura si falta el cierre; las propinas quedan fuera de ventas.',
                'Los comensales son los asociados a tickets cerrados; no representan reservas atendidas.',
                'Con consumo significa al menos una partida no cancelada, incluso de importe cero; sin consumo incluye tickets sin partidas vigentes.',
                'Las semanas son tramos consecutivos de hasta siete días desde el inicio del periodo; los meses siguen el calendario.',
                'Los productos se agrupan por el nombre guardado en la partida. No se incluye ocupación ni RevPASH.',
                'El documento es una instantánea al generarse y sus cifras pueden cambiar si cambian los hechos de origen.',
            ],
        ];
    }

    /**
     * El tablero completo del negocio, con la forma que la vista de Análisis
     * consume como window.AdminAnalyticsMock: {metrics, tickets, payments,
     * charts}.
     *
     * @param array<string, mixed> $rango RangoPeriodo::resolver()
     * @return array<string, mixed>
     */
    public static function descriptivo(\mysqli $db, string $negocio, int $unidad, array $rango): array
    {
        if (!in_array($negocio, self::NEGOCIOS, true)) {
            throw new InventarioError('AMBITO_NO_VENDEDOR', 'Sólo los negocios vendedores tienen análisis de ventas.');
        }
        $restaurante = $negocio === 'restaurante';
        $money = static fn ($n): string => '$' . number_format((float) $n, 0);
        $start = (string) $rango['start'];
        $end = (string) $rango['end'];
        [$desde, $hasta] = self::limites($start, $end);
        $cierre = self::CIERRE;
        $charts = [
            'salesByDay' => ['labels' => [], 'values' => []],
            'salesByCategory' => ['labels' => [], 'values' => []],
            'paymentMethods' => ['labels' => ['Efectivo', 'Tarjeta', 'Dividido'], 'values' => [0, 0, 0]],
            'topProducts' => ['labels' => [], 'values' => []],
            'reservationsByDay' => ['labels' => [], 'values' => []],
        ];

        // ── Cabecera ────────────────────────────────────────────
        $t = self::totales($db, $unidad, $start, $end);
        $ventasPrev = self::totales($db, $unidad, (string) $rango['prevStart'], (string) $rango['prevEnd'])['ventas'];
        $dias = (int) $rango['dias'];
        $ticketProm = $t['tickets'] > 0 ? $t['ventas'] / $t['tickets'] : 0.0;
        $destacada = [
            'label' => 'Ventas acumuladas',
            'value' => $money($t['ventas']),
            'detail' => $t['tickets'] . ($restaurante ? ' tickets cerrados' : ' tickets de caja'),
            'featured' => true,
            // Sin base de comparación no se inventa un «+100 %»: delta() da null.
            'delta' => RangoPeriodo::delta($t['ventas'], $ventasPrev),
            'delta_label' => 'vs. ' . $dias . ' días previos',
            'prev' => $money($ventasPrev),
        ];

        if ($restaurante) {
            $prop = self::fila($db, "SELECT COALESCE(SUM(propina), 0) AS total, SUM(CASE WHEN propina > 0 THEN 1 ELSE 0 END) AS con
                   FROM tickets t WHERE t.estado = 'cerrado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ?", [$unidad, $desde, $hasta]);
            $propTotal = (float) ($prop['total'] ?? 0);
            $propTickets = (int) ($prop['con'] ?? 0);
            $propProm = $propTickets > 0 ? $propTotal / $propTickets : 0.0;
            $reservas = (int) (self::fila($db, 'SELECT COUNT(*) AS n FROM reservaciones WHERE fecha >= ? AND fecha <= ? AND unidad_id = ?', [$start, $end, $unidad])['n'] ?? 0);
            $metrics = [
                $destacada,
                ['label' => 'Ticket promedio', 'value' => $money($ticketProm), 'detail' => 'Por cuenta cerrada'],
                ['label' => 'Propinas', 'value' => $money($propTotal), 'detail' => $propTickets ? ('prom. ' . $money($propProm)) : 'Sin propinas'],
                ['label' => 'Comensales atendidos', 'value' => number_format($t['comensales']), 'detail' => 'En tickets cerrados'],
                ['label' => 'Platillos vendidos', 'value' => number_format($t['piezas']), 'detail' => 'Unidades', 'priority' => 'secondary'],
                ['label' => 'Reservaciones', 'value' => number_format($reservas), 'detail' => 'Registradas', 'priority' => 'secondary'],
            ];
        } else {
            // En mostrador lo que crece la venta es el ticket: cuántas piezas
            // se llevan por compra (el café que se vuelve café con pan).
            $piezasPorTicket = $t['tickets'] > 0 ? $t['piezas'] / $t['tickets'] : 0.0;
            $metrics = [
                $destacada,
                ['label' => 'Ticket promedio', 'value' => $money($ticketProm), 'detail' => 'Por compra en caja'],
                ['label' => 'Piezas por ticket', 'value' => number_format($piezasPorTicket, 1), 'detail' => 'Lo que se lleva cada cliente'],
                ['label' => 'Piezas vendidas', 'value' => number_format($t['piezas']), 'detail' => 'Café, pan y demás', 'priority' => 'secondary'],
                ['label' => 'Venta diaria', 'value' => $money($dias > 0 ? $t['ventas'] / $dias : 0), 'detail' => 'Promedio del periodo', 'priority' => 'secondary'],
            ];
        }

        // ── Gráficas ────────────────────────────────────────────
        foreach (self::ventasPorDia($db, $unidad, $start, $end) as $d => $v) {
            $charts['salesByDay']['labels'][] = date('d/m', strtotime($d));
            $charts['salesByDay']['values'][] = round($v, 2);
        }
        // El periodo anterior, alineado por índice de día (día 1 con día 1):
        // por fecha no serviría, son tramos distintos.
        if (!empty($rango['comparar'])) {
            $charts['salesByDay']['compare'] = [
                'label' => 'Periodo anterior',
                'values' => array_map(static fn ($v): float => round($v, 2),
                    array_values(self::ventasPorDia($db, $unidad, (string) $rango['prevStart'], (string) $rango['prevEnd']))),
            ];
        }
        foreach (self::filas($db, "SELECT ti.categoria AS c, SUM(ti.precio * ti.cantidad) AS total
               FROM ticket_items ti JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ?
              GROUP BY ti.categoria ORDER BY total DESC LIMIT 6", [$unidad, $desde, $hasta]) as $r) {
            $charts['salesByCategory']['labels'][] = $r['c'];
            $charts['salesByCategory']['values'][] = round((float) $r['total'], 2);
        }
        $indice = ['efectivo' => 0, 'tarjeta' => 1, 'dividido' => 2];
        foreach (self::filas($db, "SELECT t.metodo_pago, COUNT(*) AS n FROM tickets t
              WHERE t.estado = 'cerrado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ? GROUP BY t.metodo_pago", [$unidad, $desde, $hasta]) as $r) {
            // metodo_pago es NULL en tickets cerrados sin cobro.
            $metodo = (string) ($r['metodo_pago'] ?? '');
            if (isset($indice[$metodo])) $charts['paymentMethods']['values'][$indice[$metodo]] = (int) $r['n'];
        }
        foreach (self::masVendidos($db, $unidad, $start, $end) as $r) {
            $charts['topProducts']['labels'][] = $r['nombre'];
            $charts['topProducts']['values'][] = $r['piezas'];
        }
        if ($restaurante) {
            $porDia = [];
            for ($c = strtotime($start), $l = strtotime($end); $c <= $l; $c = strtotime('+1 day', $c)) {
                $porDia[date('Y-m-d', $c)] = 0;
            }
            foreach (self::filas($db, 'SELECT fecha AS d, COUNT(*) AS n FROM reservaciones WHERE fecha >= ? AND fecha <= ? AND unidad_id = ? GROUP BY fecha', [$start, $end, $unidad]) as $r) {
                if (isset($porDia[$r['d']])) $porDia[$r['d']] = (int) $r['n'];
            }
            foreach ($porDia as $d => $v) {
                $charts['reservationsByDay']['labels'][] = date('d/m', strtotime($d));
                $charts['reservationsByDay']['values'][] = $v;
            }
        }

        // ── Últimos tickets cobrados del periodo ────────────────
        $tickets = [];
        $pagos = [];
        $estados = ['cerrado' => 'closed', 'abierto' => 'open', 'cancelado' => 'cancelled'];
        $metodos = ['efectivo' => 'Efectivo', 'tarjeta' => 'Tarjeta', 'dividido' => 'Dividido'];
        $filas = self::filas($db, "SELECT t.id, t.estado, t.metodo_pago, {$cierre} AS fecha_cobro, COALESCE(t.propina, 0) AS propina,
                    m.numero AS mesa_numero, m.nombre AS mesa_nombre, COALESCE(sub.total, 0) AS total
               FROM tickets t
               LEFT JOIN ticket_mesas tm ON tm.ticket_id = t.id AND tm.orden = 1
               LEFT JOIN mesas m ON m.id = tm.mesa_id
               LEFT JOIN (SELECT ticket_id, SUM(precio * cantidad) AS total FROM ticket_items
                           WHERE estado <> 'cancelado' AND unidad_id = ? GROUP BY ticket_id) sub ON sub.ticket_id = t.id
              WHERE t.estado = 'cerrado' AND t.unidad_id = ? AND {$cierre} >= ? AND {$cierre} <= ?
              ORDER BY fecha_cobro DESC, t.id DESC LIMIT 12", [$unidad, $unidad, $desde, $hasta]);
        foreach ($filas as $r) {
            $folio = 'T-' . str_pad((string) $r['id'], 4, '0', STR_PAD_LEFT);
            $total = (float) $r['total'];
            $propina = (float) $r['propina'];
            $mesa = trim((string) ($r['mesa_nombre'] ?? ''));
            if ($mesa === '' && $r['mesa_numero'] !== null) $mesa = 'Mesa ' . (int) $r['mesa_numero'];
            $fila = [
                'folio' => $folio,
                'created_at' => $r['fecha_cobro'],
                'status' => $estados[$r['estado']] ?? $r['estado'],
                'total' => $total,
            ];
            // La caja no tiene mesa ni propina: no se mandan para no pintar
            // columnas de guiones.
            if ($restaurante) {
                $fila += ['mesa' => $mesa !== '' ? $mesa : '—', 'propina' => $propina,
                    'propina_pct' => $total > 0 ? round(($propina / $total) * 100, 1) : null];
            }
            $tickets[] = $fila;
            $pagos[] = [
                'folio' => $folio,
                'metodo' => $r['estado'] === 'cerrado' ? ($metodos[(string) ($r['metodo_pago'] ?? '')] ?? '—') : 'Pendiente',
            ];
        }

        return ['metrics' => $metrics, 'tickets' => $tickets, 'payments' => $pagos, 'charts' => $charts];
    }

    // ── SQL ─────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} */
    private static function limites(string $start, string $end): array
    {
        return [$start . ' 00:00:00', $end . ' 23:59:59'];
    }

    private static function filas(\mysqli $db, string $sql, array $args): array
    {
        $s = $db->prepare($sql);
        if ($s === false || !$s->execute($args)) throw new \RuntimeException('No se pudo consultar Análisis.');
        $resultado = $s->get_result();
        if ($resultado === false) throw new \RuntimeException('No se pudo leer Análisis.');
        return $resultado->fetch_all(MYSQLI_ASSOC);
    }

    private static function fila(\mysqli $db, string $sql, array $args): ?array
    {
        return self::filas($db, $sql, $args)[0] ?? null;
    }
}
