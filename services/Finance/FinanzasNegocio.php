<?php

declare(strict_types=1);

/**
 * Las cifras financieras de UN negocio vendedor (Restaurante o Panadería),
 * acotadas por su unidad.
 *
 * Es el SQL que Finanzas de Restaurante tenía en su controlador, sacado aquí
 * sin cambiar una sola regla para que lo compartan tres pantallas: Finanzas de
 * Restaurante, Finanzas de Panadería y el consolidado de Global, que lee a los
 * dos negocios con estas mismas consultas. Una cifra que el dueño ve en Global
 * es, por construcción, la misma que ve el negocio en su pantalla.
 *
 * Las ventas se cuentan como en el resto del panel: tickets cerrados, sin
 * renglones cancelados, fechados por COALESCE(hora_cierre, hora_apertura). El
 * costo de insumos es el que el ticket congeló al venderse (costo_unitario);
 * lo vendido sin costo se separa para avisarlo, no se estima.
 *
 * Sólo lee. Quien llama ya autorizó el ámbito; aquí no se decide permiso.
 */

namespace Services\Finance;

use Services\Inventory\InventarioError;
use Services\Shared\RangoPeriodo;

final class FinanzasNegocio
{
    /** Los negocios que venden. Global no tiene finanzas propias que leer. */
    public const NEGOCIOS = ['restaurante', 'panaderia'];

    private const CIERRE = 'COALESCE(t.hora_cierre,t.hora_apertura)';

    /**
     * Ingresos, propinas, tickets, costo de insumos y lo vendido sin costo.
     *
     * @return array{ingresos: float, propinas: float, tickets: int, cogs: float, sinCosto: float}
     */
    public static function agregados(\mysqli $db, int $unidad, string $start, string $end): array
    {
        [$desde, $hasta] = self::limites($start, $end);
        $cierre = self::CIERRE;
        $r = ['ingresos' => 0.0, 'propinas' => 0.0, 'tickets' => 0, 'cogs' => 0.0, 'sinCosto' => 0.0];

        $f = self::fila($db, "SELECT COALESCE(SUM(ti.precio*ti.cantidad),0) ingresos,
            COALESCE(SUM(CASE WHEN ti.costo_unitario IS NULL THEN ti.precio*ti.cantidad ELSE 0 END),0) sin_costo,
            COALESCE(SUM(ti.costo_unitario*ti.cantidad),0) cogs
            FROM tickets t JOIN ticket_items ti ON ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id
            WHERE t.unidad_id=? AND t.estado='cerrado' AND ti.estado<>'cancelado' AND {$cierre}>=? AND {$cierre}<=?", [$unidad, $desde, $hasta]);
        $r['ingresos'] = (float) ($f['ingresos'] ?? 0);
        $r['sinCosto'] = (float) ($f['sin_costo'] ?? 0);
        $r['cogs'] = (float) ($f['cogs'] ?? 0);

        $f = self::fila($db, "SELECT COUNT(*) n,COALESCE(SUM(t.propina),0) propinas FROM tickets t
            WHERE t.unidad_id=? AND t.estado='cerrado' AND {$cierre}>=? AND {$cierre}<=?", [$unidad, $desde, $hasta]);
        $r['tickets'] = (int) ($f['n'] ?? 0);
        $r['propinas'] = (float) ($f['propinas'] ?? 0);

        return $r;
    }

    /** Valor de la merma del tramo, con el costo que el movimiento congeló. */
    public static function merma(\mysqli $db, int $unidad, string $start, string $end): float
    {
        [$desde, $hasta] = self::limites($start, $end);
        $f = self::fila($db, "SELECT COALESCE(SUM(ABS(delta)*costo_unitario),0) valor FROM movimientos_inventario
            WHERE unidad_id=? AND tipo='merma' AND costo_unitario IS NOT NULL AND fecha>=? AND fecha<=?", [$unidad, $desde, $hasta]);

        return (float) ($f['valor'] ?? 0);
    }

    /**
     * Valor de las existencias de HOY, al costo del proveedor preferente (o del
     * único). Es un dato puntual: no hay histórico de stock.
     */
    public static function inventarioValor(\mysqli $db, int $unidad): float
    {
        $f = self::fila($db, 'SELECT COALESCE(SUM(aa.saldo * COALESCE(ip.costo,0)),0) valor
            FROM almacen_articulos aa JOIN almacenes a ON a.id=aa.almacen_id AND a.unidad_id=aa.unidad_id
            LEFT JOIN (SELECT unidad_id,ingrediente_id,CASE WHEN SUM(preferente=1)=1 THEN MAX(CASE WHEN preferente=1 THEN costo END)
                       WHEN SUM(preferente=1)=0 AND COUNT(*)=1 THEN MAX(costo) ELSE NULL END costo
                       FROM ingrediente_proveedores GROUP BY unidad_id,ingrediente_id) ip
                   ON ip.unidad_id=aa.unidad_id AND ip.ingrediente_id=aa.articulo_id
            WHERE aa.unidad_id=? AND aa.activo=1 AND a.activo=1', [$unidad]);

        return (float) ($f['valor'] ?? 0);
    }

    /**
     * Margen por producto vendido en el tramo, sólo de lo que se vendió con
     * costo completo: un promedio con huecos mentiría el margen.
     *
     * @return list<array{nombre: string, precio: float, costo: float, margen: float, margen_pct: float}>
     */
    public static function rentabilidad(\mysqli $db, int $unidad, string $start, string $end): array
    {
        [$desde, $hasta] = self::limites($start, $end);
        $filas = self::filas($db, "SELECT ti.oferta_id, MAX(ti.nombre) nombre,
                SUM(ti.precio*ti.cantidad)/NULLIF(SUM(ti.cantidad),0) precio,
                SUM(ti.costo_unitario*ti.cantidad)/NULLIF(SUM(ti.cantidad),0) costo,
                SUM(ti.cantidad) unidades, SUM(CASE WHEN ti.costo_unitario IS NULL THEN ti.cantidad ELSE 0 END) sin_costo
            FROM ticket_items ti JOIN tickets t ON t.id=ti.ticket_id AND t.unidad_id=ti.unidad_id
            WHERE ti.unidad_id=? AND t.estado='cerrado' AND ti.estado<>'cancelado' AND ti.created_at>=? AND ti.created_at<=?
            GROUP BY ti.oferta_id HAVING unidades>0 ORDER BY nombre", [$unidad, $desde, $hasta]);
        $rentabilidad = [];
        foreach ($filas as $row) {
            if ((float) $row['sin_costo'] > 0 || $row['costo'] === null) continue;
            $precio = (float) $row['precio'];
            $costo = (float) $row['costo'];
            $rentabilidad[] = ['nombre' => (string) $row['nombre'], 'precio' => $precio, 'costo' => $costo, 'margen' => $precio - $costo,
                'margen_pct' => $precio > 0 ? (($precio - $costo) / $precio) * 100 : 0];
        }

        return $rentabilidad;
    }

    /**
     * Corte de caja por día del cobro, más reciente primero: tickets, ventas,
     * propinas y lo recibido en efectivo y en tarjeta (un pago dividido se
     * reparte por sus pagos).
     *
     * @return list<array{fecha: string, tickets: int, ventas: float, propinas: float, efectivo: float, tarjeta: float, total: float}>
     */
    public static function cortes(\mysqli $db, int $unidad, string $start, string $end): array
    {
        [$desde, $hasta] = self::limites($start, $end);
        $cierre = self::CIERRE;
        $dias = [];
        $filas = self::filas($db, "SELECT DATE({$cierre}) dia,t.metodo_pago,t.propina,
                COALESCE((SELECT SUM(ti.precio*ti.cantidad) FROM ticket_items ti WHERE ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id AND ti.estado<>'cancelado'),0) total
            FROM tickets t WHERE t.unidad_id=? AND t.estado='cerrado' AND {$cierre}>=? AND {$cierre}<=?", [$unidad, $desde, $hasta]);
        foreach ($filas as $row) {
            $d = $row['dia'];
            $dias[$d] ??= ['fecha' => $d, 'tickets' => 0, 'ventas' => 0.0, 'propinas' => 0.0, 'efectivo' => 0.0, 'tarjeta' => 0.0];
            $v = (float) $row['total'];
            $p = (float) $row['propina'];
            $dias[$d]['tickets']++;
            $dias[$d]['ventas'] += $v;
            $dias[$d]['propinas'] += $p;
            if ($row['metodo_pago'] === 'efectivo') $dias[$d]['efectivo'] += $v + $p;
            elseif ($row['metodo_pago'] === 'tarjeta') $dias[$d]['tarjeta'] += $v + $p;
        }
        $pagos = self::filas($db, "SELECT DATE({$cierre}) dia,tp.metodo_pago,SUM(tp.monto) monto
            FROM ticket_pagos tp JOIN tickets t ON t.id=tp.ticket_id AND t.unidad_id=tp.unidad_id
            WHERE tp.unidad_id=? AND t.estado='cerrado' AND t.metodo_pago='dividido' AND {$cierre}>=? AND {$cierre}<=?
            GROUP BY dia,tp.metodo_pago", [$unidad, $desde, $hasta]);
        foreach ($pagos as $row) {
            if (!isset($dias[$row['dia']])) continue;
            if ($row['metodo_pago'] === 'efectivo') $dias[$row['dia']]['efectivo'] += (float) $row['monto'];
            elseif ($row['metodo_pago'] === 'tarjeta') $dias[$row['dia']]['tarjeta'] += (float) $row['monto'];
        }
        foreach ($dias as &$d) {
            $d['total'] = $d['ventas'] + $d['propinas'];
        }
        unset($d);
        krsort($dias);

        return array_values($dias);
    }

    /**
     * Gastos fijos activos: el total prorrateado a los días del periodo (son
     * un monto MENSUAL sin fecha) y el reparto por categoría, prorrateado y
     * mensual.
     *
     * @return array{total: float, porCategoria: array<string, float>, mensuales: array<string, float>}
     */
    public static function gastosFijos(\mysqli $db, int $unidad, int $dias): array
    {
        $factor = $dias / 30;
        $r = ['total' => 0.0, 'porCategoria' => [], 'mensuales' => []];
        foreach (self::filas($db, 'SELECT categoria, monto FROM gastos_fijos WHERE unidad_id=? AND activo=1', [$unidad]) as $g) {
            $monto = (float) $g['monto'] * $factor;
            $r['total'] += $monto;
            $r['porCategoria'][$g['categoria']] = ($r['porCategoria'][$g['categoria']] ?? 0.0) + $monto;
            $r['mensuales'][$g['categoria']] = ($r['mensuales'][$g['categoria']] ?? 0.0) + (float) $g['monto'];
        }
        arsort($r['porCategoria']);
        arsort($r['mensuales']);

        return $r;
    }

    /**
     * El estado de resultados del periodo y su comparación con el anterior:
     * lo que Global pone lado a lado para cada negocio. Los márgenes y el
     * diagnóstico salen de DiagnosticoFinanciero con el giro del negocio, así
     * que el veredicto de Global es el mismo que el de la pantalla del negocio.
     *
     * @param array<string, mixed> $rango RangoPeriodo::resolver()
     * @return array<string, mixed>
     */
    public static function resumen(\mysqli $db, string $negocio, int $unidad, array $rango): array
    {
        if (!in_array($negocio, self::NEGOCIOS, true)) {
            throw new InventarioError('AMBITO_NO_VENDEDOR', 'Sólo los negocios vendedores tienen estado de resultados.');
        }
        $start = (string) $rango['start'];
        $end = (string) $rango['end'];
        $dias = max(1, (int) $rango['dias']);
        $datos = self::agregados($db, $unidad, $start, $end);
        $previo = self::agregados($db, $unidad, (string) $rango['prevStart'], (string) $rango['prevEnd']);
        $merma = self::merma($db, $unidad, $start, $end);
        $mermaPrev = self::merma($db, $unidad, (string) $rango['prevStart'], (string) $rango['prevEnd']);
        $gastos = self::gastosFijos($db, $unidad, $dias);
        $inventario = self::inventarioValor($db, $unidad);

        $bruta = $datos['ingresos'] - $datos['cogs'] - $merma;
        $neta = $bruta - $gastos['total'];
        // Los gastos fijos no tienen fecha: el periodo anterior repite el mismo
        // prorrateo, igual que en la pantalla del negocio.
        $brutaPrev = $previo['ingresos'] - $previo['cogs'] - $mermaPrev;
        $netaPrev = $brutaPrev - $gastos['total'];
        $consumo = $datos['cogs'] + $merma;

        $diagnostico = DiagnosticoFinanciero::armar([
            'giro' => $negocio,
            'ingresos' => $datos['ingresos'],
            'cogs' => $datos['cogs'],
            'merma' => $merma,
            'gastos' => $gastos['total'],
            'gastosPorCategoria' => $gastos['porCategoria'],
            'dias' => $dias,
            'tickets' => $datos['tickets'],
            'propinas' => $datos['propinas'],
            'inventarioValor' => $inventario,
            'ingresosSinCosto' => $datos['sinCosto'],
            'comparando' => true,
            'periodoAnterior' => (string) ($rango['prevLabel'] ?? ''),
            'previo' => ['ingresos' => $previo['ingresos'], 'cogs' => $previo['cogs'], 'merma' => $mermaPrev, 'tickets' => $previo['tickets']],
        ]);
        $pct = static fn (float $parte, float $todo): ?float => $todo > 0 ? $parte / $todo * 100 : null;

        return [
            'negocio' => $negocio,
            'ingresos' => $datos['ingresos'],
            'tickets' => $datos['tickets'],
            'propinas' => $datos['propinas'],
            'ticketPromedio' => $datos['tickets'] > 0 ? $datos['ingresos'] / $datos['tickets'] : null,
            'cogs' => $datos['cogs'],
            'sinCosto' => $datos['sinCosto'],
            'merma' => $merma,
            'utilidadBruta' => $bruta,
            'gastos' => $gastos['total'],
            'utilidadNeta' => $neta,
            'margenBruto' => $pct($bruta, $datos['ingresos']),
            'margenNeto' => $pct($neta, $datos['ingresos']),
            'insumosPct' => $pct($datos['cogs'], $datos['ingresos']),
            'mermaPct' => $pct($merma, $consumo),
            'inventarioValor' => $inventario,
            'diasInventario' => ($inventario > 0 && $consumo > 0) ? $inventario / ($consumo / $dias) : null,
            'previo' => [
                'ingresos' => $previo['ingresos'],
                'tickets' => $previo['tickets'],
                'ticketPromedio' => $previo['tickets'] > 0 ? $previo['ingresos'] / $previo['tickets'] : null,
                'cogs' => $previo['cogs'],
                'merma' => $mermaPrev,
                'utilidadBruta' => $brutaPrev,
                'utilidadNeta' => $netaPrev,
            ],
            'diagnostico' => $diagnostico,
        ];
    }

    /** Documento gerencial construido con la misma autoridad que el panel. */
    public static function informe(\mysqli $db, int $unidad, array $rango, string $generadoEn): array
    {
        $r = self::resumen($db, 'restaurante', $unidad, $rango);
        $anterior = $r['previo'];
        $deltaIngresos = RangoPeriodo::delta($r['ingresos'], $anterior['ingresos']);
        $deltaNeta = RangoPeriodo::delta($r['utilidadNeta'], $anterior['utilidadNeta']);
        $resumen = $r['tickets'] === 0
            ? 'No hubo tickets cerrados en el periodo.'
            : 'El informe reúne las ventas y los costos de los tickets cerrados del periodo.';
        $resumen .= ' La utilidad neta considera la merma con costo conocido y el prorrateo de gastos fijos.';
        if ($r['sinCosto'] > 0) {
            $resumen .= ' Hay ventas sin costo conocido; las utilidades no incluyen ese costo faltante.';
        }
        $notas = [
            'Las ventas corresponden a partidas no canceladas de tickets cerrados, por fecha de cierre o apertura si falta el cierre. Las propinas quedan fuera de los ingresos.',
            'La utilidad bruta resta de los ingresos el costo de insumos y la merma con costo conocido; la utilidad neta resta además los gastos fijos.',
            'Los gastos fijos son importes mensuales vigentes sin fecha, prorrateados por días del periodo entre 30; no son egresos históricos diarios y el mismo prorrateo se aplica al periodo anterior.',
            'El costo de insumos usa el costo congelado en la venta; las partidas sin costo no se consideran costo cero conocido. La merma sin costo queda fuera de su importe.',
            'Las cifras son una instantánea al generar el documento y pueden cambiar si se actualizan los hechos de origen.',
        ];
        return [
            'titulo' => 'Informe de Finanzas', 'contexto' => 'Restaurante',
            'periodo' => ['inicio' => $rango['start'], 'fin' => $rango['end'],
                'comparacion' => ['inicio' => $rango['prevStart'], 'fin' => $rango['prevEnd']]],
            'generadoEn' => $generadoEn, 'corteDatos' => $generadoEn, 'resumen' => $resumen,
            'kpis' => [
                ['etiqueta' => 'Ingresos por ventas', 'valor' => $r['ingresos'], 'formato' => 'moneda', 'comparacion' => $deltaIngresos],
                ['etiqueta' => 'Tickets cerrados', 'valor' => $r['tickets'], 'formato' => 'entero'],
                ['etiqueta' => 'Ticket promedio', 'valor' => $r['ticketPromedio'], 'formato' => 'moneda'],
                ['etiqueta' => 'Costo de insumos', 'valor' => $r['cogs'], 'formato' => 'moneda'],
                ['etiqueta' => 'Utilidad bruta', 'valor' => $r['utilidadBruta'], 'formato' => 'moneda'],
                ['etiqueta' => 'Utilidad neta', 'valor' => $r['utilidadNeta'], 'formato' => 'moneda', 'comparacion' => $deltaNeta],
            ],
            'secciones' => [
                ['titulo' => 'Balance del periodo', 'tipo' => 'tabla', 'columnas' => ['Concepto', 'Importe'],
                    'formatos' => ['texto', 'moneda'], 'filas' => [
                        ['Ingresos por ventas', $r['ingresos']], ['Costo de insumos', $r['cogs']],
                        ['Merma con costo conocido', $r['merma']], ['Gastos fijos prorrateados', $r['gastos']],
                        ['Utilidad neta', $r['utilidadNeta']], ['Ventas sin costo conocido', $r['sinCosto']],
                    ]],
                ['titulo' => 'Comparación con el periodo anterior', 'tipo' => 'tabla',
                    'columnas' => ['Concepto', $rango['prevStart'] . ' a ' . $rango['prevEnd'], $rango['start'] . ' a ' . $rango['end']],
                    'formatos' => ['texto', 'moneda', 'moneda'], 'filas' => [
                        ['Ingresos por ventas', $anterior['ingresos'], $r['ingresos']],
                        ['Utilidad bruta', $anterior['utilidadBruta'], $r['utilidadBruta']],
                        ['Utilidad neta', $anterior['utilidadNeta'], $r['utilidadNeta']],
                    ]],
            ],
            'notas' => $notas,
        ];
    }

    /**
     * El pulso de HOY de un negocio: lo cobrado, los tickets cerrados, las
     * cuentas que siguen abiertas con lo que llevan consumido y lo cobrado el
     * mismo día de la semana pasada hasta esta misma hora, que es la única
     * comparación justa a media jornada.
     *
     * @return array{ventas: float, tickets: int, abiertas: int, enCurso: float, ventasSemanaPasada: float, ticketPromedio: float|null}
     */
    public static function pulsoHoy(\mysqli $db, int $unidad, ?\DateTimeImmutable $ahora = null): array
    {
        $ahora ??= new \DateTimeImmutable();
        $cierre = self::CIERRE;
        $cobrado = static function (\DateTimeImmutable $hasta) use ($db, $unidad, $cierre): array {
            $f = self::fila($db, "SELECT COUNT(DISTINCT t.id) tickets, COALESCE(SUM(ti.precio*ti.cantidad),0) ventas
                FROM tickets t JOIN ticket_items ti ON ti.ticket_id=t.id AND ti.unidad_id=t.unidad_id
                WHERE t.unidad_id=? AND t.estado='cerrado' AND ti.estado<>'cancelado' AND {$cierre}>=? AND {$cierre}<=?",
                [$unidad, $hasta->format('Y-m-d') . ' 00:00:00', $hasta->format('Y-m-d H:i:s')]);
            return ['tickets' => (int) ($f['tickets'] ?? 0), 'ventas' => (float) ($f['ventas'] ?? 0)];
        };
        $hoy = $cobrado($ahora);
        $pasada = $cobrado($ahora->modify('-7 days'));
        $abiertas = self::fila($db, "SELECT COUNT(DISTINCT t.id) n,
                COALESCE((SELECT SUM(ti.precio*ti.cantidad) FROM ticket_items ti JOIN tickets t2 ON t2.id=ti.ticket_id AND t2.unidad_id=ti.unidad_id
                          WHERE t2.unidad_id=? AND t2.estado='abierto' AND ti.estado<>'cancelado'),0) en_curso
            FROM tickets t WHERE t.unidad_id=? AND t.estado='abierto'", [$unidad, $unidad]);

        return [
            'ventas' => $hoy['ventas'],
            'tickets' => $hoy['tickets'],
            'ticketPromedio' => $hoy['tickets'] > 0 ? $hoy['ventas'] / $hoy['tickets'] : null,
            'abiertas' => (int) ($abiertas['n'] ?? 0),
            'enCurso' => (float) ($abiertas['en_curso'] ?? 0),
            'ventasSemanaPasada' => $pasada['ventas'],
        ];
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
        if ($s === false || !$s->execute($args)) throw new \RuntimeException('No se pudo consultar Finanzas.');
        $resultado = $s->get_result();
        if ($resultado === false) throw new \RuntimeException('No se pudo leer Finanzas.');
        return $resultado->fetch_all(MYSQLI_ASSOC);
    }

    private static function fila(\mysqli $db, string $sql, array $args): ?array
    {
        return self::filas($db, $sql, $args)[0] ?? null;
    }
}
