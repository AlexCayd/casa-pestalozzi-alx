<?php
/**
 * Módulo financiero del panel admin.
 * Muestra la situación del negocio del mes en curso: ingresos por ventas,
 * costo de insumos (según recetas y costo de ingredientes), utilidad bruta,
 * gastos fijos (renta, luz, nómina…) y utilidad neta. Permite administrar los
 * gastos fijos.
 */

namespace Controllers;

use Model\GastoFijo;
use Model\Producto;
use MVC\Router;
use Services\Inventario;

class AdminFinanzasController
{
    private const PATH = '/admin/finanzas';
    private const CSS = '/build/css/admin/finanzas.css';

    public static function index(Router $router): void
    {
        $datos = [
            'ingresos' => 0.0,
            'propinas' => 0.0,
            'tickets' => 0,
            'cogs' => 0.0,
        ];
        $gastos = [];
        $gastosPorCategoria = [];
        $totalGastos = 0.0;
        $rentabilidad = [];

        try {
            $db = Producto::getDB();

            // Las ventas se atribuyen al día del COBRO. COALESCE cubre los
            // tickets cerrados antes de que existiera hora_cierre.
            $cierre = 'COALESCE(t.hora_cierre, t.hora_apertura)';

            // Ingresos y tickets cerrados del mes en curso.
            $resIng = $db->query(
                "SELECT COALESCE(SUM(ti.precio * ti.cantidad), 0) AS ingresos
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                        AND YEAR({$cierre}) = YEAR(CURDATE())
                        AND MONTH({$cierre}) = MONTH(CURDATE())"
            );
            if ($resIng && ($row = $resIng->fetch_assoc())) {
                $datos['ingresos'] = (float) $row['ingresos'];
            }

            $resTk = $db->query(
                "SELECT COUNT(*) AS n, COALESCE(SUM(t.propina), 0) AS propinas
                   FROM tickets t
                  WHERE t.estado = 'cerrado'
                        AND YEAR({$cierre}) = YEAR(CURDATE())
                        AND MONTH({$cierre}) = MONTH(CURDATE())"
            );
            if ($resTk && ($row = $resTk->fetch_assoc())) {
                $datos['tickets'] = (int) $row['n'];
                $datos['propinas'] = (float) $row['propinas'];
            }

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

            // Costo de insumos (COGS) del mes: unidades vendidas × costo de receta.
            $costoCache = [];
            $resVend = $db->query(
                "SELECT ti.nombre, SUM(ti.cantidad) AS unidades
                   FROM ticket_items ti
                   JOIN tickets t ON t.id = ti.ticket_id
                  WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado'
                        AND YEAR({$cierre}) = YEAR(CURDATE())
                        AND MONTH({$cierre}) = MONTH(CURDATE())
                  GROUP BY ti.nombre"
            );
            if ($resVend) {
                while ($row = $resVend->fetch_assoc()) {
                    $prod = $productosPorNombre[$row['nombre']] ?? null;
                    if (!$prod) {
                        continue;
                    }
                    $pid = $prod['id'];
                    if (!isset($costoCache[$pid])) {
                        $costoCache[$pid] = Inventario::costoDeProducto($pid);
                    }
                    $datos['cogs'] += $costoCache[$pid] * (float) $row['unidades'];
                }
            }

            // Gastos fijos.
            $gastos = GastoFijo::todos();
            foreach ($gastos as $g) {
                if (!$g->activo) {
                    continue;
                }
                $monto = (float) $g->monto;
                $totalGastos += $monto;
                $gastosPorCategoria[$g->categoria] = ($gastosPorCategoria[$g->categoria] ?? 0.0) + $monto;
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

        $rangoCortes = self::rangoCortes();
        $cortes = [];
        try {
            $cortes = self::cortes($rangoCortes['start'], $rangoCortes['end']);
        } catch (\Throwable $e) {
            error_log('AdminFinanzasController::cortes - ' . $e->getMessage());
        }

        self::render('finanzas/index', [
            'title' => 'Finanzas',
            'topbarSection' => 'Finanzas',
            'mes' => self::nombreMes(),
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
            'gastos' => $gastos,
            'gastosPorCategoria' => $gastosPorCategoria,
            'categorias' => GastoFijo::CATEGORIAS,
            'rentabilidad' => $rentabilidad,
            'alertas' => GastoFijo::getAlertas(),
        ]);
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
     * Resuelve el rango de fechas de los cortes desde $_GET (?desde=&hasta=).
     * Por defecto, los últimos 7 días. Devuelve ['start','end'].
     */
    private static function rangoCortes(): array
    {
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        $desde = trim((string) ($_GET['desde'] ?? ''));
        $hasta = trim((string) ($_GET['hasta'] ?? ''));
        if (preg_match($re, $desde) && preg_match($re, $hasta) && $desde <= $hasta) {
            return ['start' => $desde, 'end' => $hasta];
        }
        return ['start' => date('Y-m-d', strtotime('-6 days')), 'end' => date('Y-m-d')];
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

    private static function nombreMes(): string
    {
        $meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return ucfirst($meses[(int) date('n')]) . ' ' . date('Y');
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'finanzas',
            'styles' => [self::CSS],
            'scripts' => [],
        ], $data));
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
