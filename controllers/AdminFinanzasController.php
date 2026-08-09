<?php
/**
 * Módulo financiero del panel admin.
 * Muestra la situación del negocio en el periodo elegido: ingresos por ventas,
 * costo de insumos (según recetas y costo de ingredientes), utilidad bruta,
 * gastos fijos (renta, luz, nómina…), utilidad neta y rotación de inventarios.
 * Permite administrar los gastos fijos.
 */

namespace Controllers;

use Model\GastoFijo;
use Model\Producto;
use MVC\Router;
use Services\Inventario;
use Services\RangoPeriodo;

class AdminFinanzasController
{
    private const PATH = '/admin/finanzas';
    private const CSS = '/build/css/admin/finanzas.css';
    private const JS = '/build/js/admin/finanzas.js';
    private const CHART_JS = '/build/js/vendor/chart.umd.min.js';
    private const SANKEY_JS = '/build/js/vendor/chartjs-chart-sankey.min.js';

    public static function index(Router $router): void
    {
        $rango = RangoPeriodo::resolver($_GET);

        $datos = [
            'ingresos' => 0.0,
            'propinas' => 0.0,
            'tickets' => 0,
            'cogs' => 0.0,
        ];
        $previo = ['ingresos' => 0.0, 'cogs' => 0.0];
        $gastos = [];
        $gastosPorCategoria = [];
        $totalGastos = 0.0;
        $rentabilidad = [];
        $inventarioValor = 0.0;
        $costoCache = [];

        try {
            $db = Producto::getDB();

            // Mapa nombre → producto (para enlazar ventas con recetas por nombre).
            $productosPorNombre = [];
            $resProd = $db->query("SELECT id, nombre, precio FROM productos");
            if ($resProd) {
                while ($row = $resProd->fetch_assoc()) {
                    $productosPorNombre[$row['nombre']] = [
                        'id' => (int) $row['id'],
                        'precio' => (float) $row['precio'],
                    ];
                }
            }

            $datos = self::agregadosDelPeriodo(
                $db,
                (string) $rango['start'],
                (string) $rango['end'],
                $productosPorNombre,
                $costoCache
            );

            if (!empty($rango['comparar'])) {
                $previo = self::agregadosDelPeriodo(
                    $db,
                    (string) $rango['prevStart'],
                    (string) $rango['prevEnd'],
                    $productosPorNombre,
                    $costoCache
                );
            }

            // Gastos fijos. Son un monto MENSUAL recurrente (gastos_fijos no
            // tiene columna de fecha), así que con un periodo arbitrario se
            // prorratean a 30 días; la vista lo dice al pie del estado.
            $factorGastos = ((int) $rango['dias']) / 30;
            $gastos = GastoFijo::todos();
            foreach ($gastos as $g) {
                if (!$g->activo) {
                    continue;
                }
                $monto = (float) $g->monto * $factorGastos;
                $totalGastos += $monto;
                $gastosPorCategoria[$g->categoria] = ($gastosPorCategoria[$g->categoria] ?? 0.0) + $monto;
            }

            // Valor del inventario a hoy, para la rotación. Es un dato puntual:
            // no hay histórico de stock ni costo al momento del movimiento.
            $resInv = $db->query(
                "SELECT COALESCE(SUM(stock * costo), 0) AS valor
                   FROM ingredientes WHERE activo = 1"
            );
            if ($resInv && ($row = $resInv->fetch_assoc())) {
                $inventarioValor = (float) $row['valor'];
            }

            // Rentabilidad por producto (solo los que tienen receta).
            $resReceta = $db->query(
                "SELECT p.id, p.nombre, p.precio
                   FROM productos p
                   JOIN (SELECT DISTINCT producto_id FROM producto_componentes) pc ON pc.producto_id = p.id
                  ORDER BY p.nombre ASC"
            );
            if ($resReceta) {
                while ($row = $resReceta->fetch_assoc()) {
                    $pid = (int) $row['id'];
                    $precio = (float) $row['precio'];
                    if (!isset($costoCache[$pid])) {
                        $costoCache[$pid] = Inventario::costoDeProducto($pid);
                    }
                    $costo = $costoCache[$pid];
                    $rentabilidad[] = [
                        'nombre' => $row['nombre'],
                        'precio' => $precio,
                        'costo' => $costo,
                        'margen' => $precio - $costo,
                        'margen_pct' => $precio > 0 ? (($precio - $costo) / $precio) * 100 : 0,
                    ];
                }
            }
        } catch (\Throwable $e) {
            GastoFijo::setAlerta('error', 'No se pudieron cargar los datos financieros. ¿Ya corriste la migración de la BD?');
        }

        $ingresos = $datos['ingresos'];
        $cogs = $datos['cogs'];
        $utilidadBruta = $ingresos - $cogs;
        $utilidadNeta = $utilidadBruta - $totalGastos;
        $utilidadBrutaPrev = $previo['ingresos'] - $previo['cogs'];

        // Rotación: cuántas veces se consumió el inventario en el periodo.
        // El denominador es el inventario ACTUAL, no el promedio: no existe
        // histórico de stock del que sacar uno. La vista lo advierte.
        $rotacion = $inventarioValor > 0 ? $cogs / $inventarioValor : null;
        $diasInventario = ($rotacion !== null && $rotacion > 0)
            ? ((int) $rango['dias']) / $rotacion
            : null;

        $rangoCortes = ['start' => (string) $rango['start'], 'end' => (string) $rango['end']];
        $cortes = [];
        try {
            $cortes = self::cortes($rangoCortes['start'], $rangoCortes['end']);
        } catch (\Throwable $e) {
            error_log('AdminFinanzasController::cortes - ' . $e->getMessage());
        }

        self::render('finanzas/index', [
            'title' => 'Finanzas',
            'topbarSection' => 'Finanzas',
            'rango' => $rango,
            'cortes' => $cortes,
            'rangoCortes' => $rangoCortes,
            'ingresos' => $ingresos,
            'propinas' => $datos['propinas'],
            'tickets' => $datos['tickets'],
            'ticketPromedio' => $datos['tickets'] > 0 ? $ingresos / $datos['tickets'] : 0.0,
            'cogs' => $cogs,
            'utilidadBruta' => $utilidadBruta,
            'margenBruto' => $ingresos > 0 ? ($utilidadBruta / $ingresos) * 100 : 0,
            'totalGastos' => $totalGastos,
            'utilidadNeta' => $utilidadNeta,
            // Faltaba: utilidadNeta se calculaba pero nunca se expresaba como
            // porcentaje de los ingresos, que es como se compara entre periodos.
            'margenNeto' => $ingresos > 0 ? ($utilidadNeta / $ingresos) * 100 : 0,
            'deltaIngresos' => RangoPeriodo::delta($ingresos, $previo['ingresos']),
            'deltaUtilidadBruta' => RangoPeriodo::delta($utilidadBruta, $utilidadBrutaPrev),
            'inventarioValor' => $inventarioValor,
            'rotacion' => $rotacion,
            'diasInventario' => $diasInventario,
            'gastos' => $gastos,
            'gastosPorCategoria' => $gastosPorCategoria,
            'categorias' => GastoFijo::CATEGORIAS,
            'rentabilidad' => $rentabilidad,
            'alertas' => GastoFijo::getAlertas(),
        ]);
    }

    /**
     * Ingresos, propinas, tickets y COGS de un tramo de fechas. Se extrajo del
     * cuerpo de index() para poder pedir también el periodo anterior sin
     * duplicar las tres consultas.
     *
     * $costoCache se pasa por referencia: costoDeProducto() explota la receta
     * completa y repetirla por periodo multiplica las consultas.
     *
     * @param array<string, array{id: int, precio: float}> $productosPorNombre
     * @param array<int, float> $costoCache
     * @return array{ingresos: float, propinas: float, tickets: int, cogs: float}
     */
    private static function agregadosDelPeriodo(
        \mysqli $db,
        string $start,
        string $end,
        array $productosPorNombre,
        array &$costoCache
    ): array {
        // Las ventas se atribuyen al día del COBRO. COALESCE cubre los tickets
        // cerrados antes de que existiera hora_cierre.
        $cierre = 'COALESCE(t.hora_cierre, t.hora_apertura)';
        $filtro = "AND {$cierre} >= '{$start} 00:00:00' AND {$cierre} <= '{$end} 23:59:59'";

        $agregados = ['ingresos' => 0.0, 'propinas' => 0.0, 'tickets' => 0, 'cogs' => 0.0];

        $res = $db->query(
            "SELECT COALESCE(SUM(ti.precio * ti.cantidad), 0) AS ingresos
               FROM ticket_items ti
               JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$filtro}"
        );
        if ($res && ($row = $res->fetch_assoc())) {
            $agregados['ingresos'] = (float) $row['ingresos'];
        }

        $res = $db->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(t.propina), 0) AS propinas
               FROM tickets t
              WHERE t.estado = 'cerrado' {$filtro}"
        );
        if ($res && ($row = $res->fetch_assoc())) {
            $agregados['tickets'] = (int) $row['n'];
            $agregados['propinas'] = (float) $row['propinas'];
        }

        // COGS: unidades vendidas × costo de receta.
        $res = $db->query(
            "SELECT ti.nombre, SUM(ti.cantidad) AS unidades
               FROM ticket_items ti
               JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$filtro}
              GROUP BY ti.nombre"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $prod = $productosPorNombre[$row['nombre']] ?? null;
                if (!$prod) {
                    continue;
                }
                $pid = $prod['id'];
                if (!isset($costoCache[$pid])) {
                    $costoCache[$pid] = Inventario::costoDeProducto($pid);
                }
                $agregados['cogs'] += $costoCache[$pid] * (float) $row['unidades'];
            }
        }

        return $agregados;
    }

    public static function guardarGasto(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::PATH);
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $gasto = $id ? GastoFijo::find($id) : new GastoFijo();
        if (!$gasto) {
            $gasto = new GastoFijo();
        }

        $gasto->sincronizar($_POST);
        $gasto->activo = isset($_POST['activo']) ? 1 : 0;
        $alertas = $gasto->validar();

        if (empty($alertas)) {
            $gasto->guardar();
            GastoFijo::setAlerta('exito', $id ? 'Gasto actualizado' : 'Gasto agregado');
        }

        self::index($router);
    }

    public static function eliminarGasto(Router $router): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::PATH);
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $gasto = $id ? GastoFijo::find($id) : null;
        if ($gasto && $gasto->eliminar()) {
            GastoFijo::setAlerta('exito', 'Gasto eliminado');
        }

        self::index($router);
    }

    /**
     * Corte de caja por día en el rango dado (mismos indicadores que el modal
     * de "Caja" del punto de venta): tickets, ventas (consumo), propinas,
     * desglose efectivo/tarjeta y total recibido. Solo tickets cerrados.
     */
    private static function cortes(string $start, string $end): array
    {
        $db = Producto::getDB();
        $dias = [];
        // El corte del día es el del cobro, no el de la apertura de la mesa.
        $cierre = 'COALESCE(t.hora_cierre, t.hora_apertura)';
        $fTk = "AND {$cierre} >= '{$start} 00:00:00' AND {$cierre} <= '{$end} 23:59:59'";

        // Consumo + propina por ticket, atribuido al método de pago.
        $resTickets = $db->query(
            "SELECT DATE({$cierre}) AS dia, t.metodo_pago,
                    COALESCE(t.propina, 0) AS propina,
                    COALESCE((SELECT SUM(ti.precio * ti.cantidad)
                              FROM ticket_items ti
                              WHERE ti.ticket_id = t.id AND ti.estado <> 'cancelado'), 0) AS total
               FROM tickets t
              WHERE t.estado = 'cerrado' {$fTk}"
        );
        if ($resTickets) {
            while ($row = $resTickets->fetch_assoc()) {
                $dia = $row['dia'];
                if (!isset($dias[$dia])) {
                    $dias[$dia] = ['fecha' => $dia, 'tickets' => 0, 'ventas' => 0.0,
                        'propinas' => 0.0, 'efectivo' => 0.0, 'tarjeta' => 0.0];
                }
                $total = (float) $row['total'];
                $propina = (float) $row['propina'];
                $dias[$dia]['tickets']++;
                $dias[$dia]['ventas'] += $total;
                $dias[$dia]['propinas'] += $propina;
                if ($row['metodo_pago'] === 'efectivo') {
                    $dias[$dia]['efectivo'] += $total + $propina;
                } elseif ($row['metodo_pago'] === 'tarjeta') {
                    $dias[$dia]['tarjeta'] += $total + $propina;
                }
            }
        }

        // Desglose de cuentas divididas por método (ticket_pagos).
        $resPagos = $db->query(
            "SELECT DATE({$cierre}) AS dia, tp.metodo_pago,
                    COALESCE(SUM(tp.monto), 0) AS monto
               FROM ticket_pagos tp
               JOIN tickets t ON t.id = tp.ticket_id
              WHERE t.estado = 'cerrado' AND t.metodo_pago = 'dividido' {$fTk}
              GROUP BY dia, tp.metodo_pago"
        );
        if ($resPagos) {
            while ($row = $resPagos->fetch_assoc()) {
                $dia = $row['dia'];
                if (!isset($dias[$dia])) {
                    continue;
                }
                if ($row['metodo_pago'] === 'efectivo') {
                    $dias[$dia]['efectivo'] += (float) $row['monto'];
                } elseif ($row['metodo_pago'] === 'tarjeta') {
                    $dias[$dia]['tarjeta'] += (float) $row['monto'];
                }
            }
        }

        foreach ($dias as &$d) {
            $d['total'] = $d['ventas'] + $d['propinas'];
        }
        unset($d);

        // Más reciente primero.
        krsort($dias);
        return array_values($dias);
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'finanzas',
            'styles' => [self::CSS],
            'scripts' => [self::CHART_JS, self::SANKEY_JS, self::JS],
        ], $data));
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
