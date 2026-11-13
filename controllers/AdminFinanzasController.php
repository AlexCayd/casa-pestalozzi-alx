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
use Services\Finance\ConsolidadoFinanciero;
use Services\Finance\DiagnosticoFinanciero;
use Services\Finance\FinanzasNegocio;
use Services\Inventory\Inventario;
use Services\Shared\RangoPeriodo;
use Services\Shared\GeneradorPdf;
use Services\Security\ContextoNegocio;
use Services\Security\Contextos;

class AdminFinanzasController
{
    private const PATH = '/admin/finanzas';
    private const CSS = '/build/css/admin/finanzas.css';
    private const JS = '/build/js/admin/finanzas.js';
    /*
     * Ya no se carga Chart.js. Sólo lo usaba la dona del reparto del gasto
     * fijo, y eran 208 KB para un anillo cuyo total, además, se pintaba en
     * Georgia (pedía "Fraunces", que el panel ya no sirve). El reparto es ahora
     * una barra en HTML dentro de la sección de gastos fijos, y el Sankey lo
     * dibuja src/js/admin/finanzas/sankey.js en SVG.
     */

    /*
     * Días con venta por página en los cortes de caja. Catorce son dos semanas
     * cuando se abre todos los días: el ritmo de la semana se lee en una sola
     * página, y «Último año» no vuelca trescientas filas de golpe.
     */
    private const CORTES_POR_PAGINA = 14;

    public static function index(Router $router): void
    {
        $rango = RangoPeriodo::resolver($_GET);
        // Global no vende: su pantalla es el consolidado de los dos negocios.
        if (ContextoNegocio::nuevo() && ContextoNegocio::slug() === 'global') {
            self::globalIndex($rango);
            return;
        }
        $ctx = ContextoNegocio::nuevo() ? ContextoNegocio::slug() : 'restaurante';

        $datos = [
            'ingresos' => 0.0,
            'propinas' => 0.0,
            'tickets' => 0,
            'cogs' => 0.0,
            'sinCosto' => 0.0,
        ];
        // Las mismas claves en los dos: con la comparación apagada el resto de
        // deltas leería índices inexistentes.
        $previo = ['ingresos' => 0.0, 'propinas' => 0.0, 'tickets' => 0, 'cogs' => 0.0, 'sinCosto' => 0.0];
        $merma = 0.0;
        $mermaPrev = 0.0;
        $gastos = [];
        $gastosPorCategoria = [];
        $gastosMensuales = [];
        $totalGastos = 0.0;
        $rentabilidad = [];
        $inventarioValor = 0.0;
        $costoCache = [];

        try {
            $db = Producto::getDB();

            if (ContextoNegocio::nuevo()) {
                if (!in_array($ctx, FinanzasNegocio::NEGOCIOS, true)) {
                    throw new \RuntimeException('Las finanzas son de cada negocio: Restaurante o Panadería.');
                }
                ContextoNegocio::exigir('finanzas', 'ver', $ctx);
                $unidad = ContextoNegocio::unidad($ctx);
                $datos = FinanzasNegocio::agregados($db, $unidad, (string)$rango['start'], (string)$rango['end']);
                if (!empty($rango['comparar'])) {
                    $previo = FinanzasNegocio::agregados($db, $unidad, (string)$rango['prevStart'], (string)$rango['prevEnd']);
                }
                $merma = FinanzasNegocio::merma($db, $unidad, (string)$rango['start'], (string)$rango['end']);
                if (!empty($rango['comparar'])) {
                    $mermaPrev = FinanzasNegocio::merma($db, $unidad, (string)$rango['prevStart'], (string)$rango['prevEnd']);
                }
                $factorGastos = ((int)$rango['dias']) / 30;
                $gastos = GastoFijo::todos();
                foreach ($gastos as $g) {
                    if (!(int)$g->activo) continue;
                    $monto = (float)$g->monto * $factorGastos;
                    $totalGastos += $monto;
                    $gastosPorCategoria[$g->categoria] = ($gastosPorCategoria[$g->categoria] ?? 0.0) + $monto;
                    $gastosMensuales[$g->categoria] = ($gastosMensuales[$g->categoria] ?? 0.0) + (float)$g->monto;
                }
                arsort($gastosPorCategoria);
                arsort($gastosMensuales);
                $inventarioValor = FinanzasNegocio::inventarioValor($db, $unidad);
                $rentabilidad = FinanzasNegocio::rentabilidad($db, $unidad, (string)$rango['start'], (string)$rango['end']);
            } else {

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

            // La merma es costo consumado: producto pagado que no llegó a
            // venderse. Va aparte del COGS para que se vea cuánto de la utilidad
            // se pierde en la barra y no en el plato.
            $merma = self::mermaDelPeriodo($db, (string) $rango['start'], (string) $rango['end']);
            if (!empty($rango['comparar'])) {
                $mermaPrev = self::mermaDelPeriodo($db, (string) $rango['prevStart'], (string) $rango['prevEnd']);
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
                // Sin prorratear: la sección de gastos fijos habla en la
                // unidad en la que se capturan, que es el mes.
                $gastosMensuales[$g->categoria] = ($gastosMensuales[$g->categoria] ?? 0.0) + (float) $g->monto;
            }
            arsort($gastosPorCategoria);
            arsort($gastosMensuales);

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
            }
        } catch (\Throwable $e) {
            GastoFijo::setAlerta('error', 'No se pudieron cargar los datos financieros. ¿Ya corriste la migración de la BD?');
        }

        $ingresos = $datos['ingresos'];
        $cogs = $datos['cogs'];
        $utilidadBruta = $ingresos - $cogs - $merma;
        $utilidadNeta = $utilidadBruta - $totalGastos;
        $utilidadBrutaPrev = $previo['ingresos'] - $previo['cogs'] - $mermaPrev;
        $ticketPromedio = $datos['tickets'] > 0 ? $ingresos / $datos['tickets'] : 0.0;
        $ticketPromedioPrev = $previo['tickets'] > 0 ? $previo['ingresos'] / $previo['tickets'] : 0.0;
        // Los gastos fijos son mensuales y el periodo previo mide lo mismo, así
        // que su prorrateo coincide: la utilidad neta anterior sale sin
        // consultar nada.
        $utilidadNetaPrev = $utilidadBrutaPrev - $totalGastos;
        $comparando = !empty($rango['comparar']);

        // Rotación: cuántas veces se consumió el inventario en el periodo.
        // Cuenta también la merma: salió del almacén aunque no se vendiera, y
        // dejarla fuera hacía parecer que el inventario se mueve menos de lo que
        // se mueve. El denominador es el inventario ACTUAL, no el promedio: no
        // existe histórico de stock del que sacar uno. La vista lo advierte.
        $inventarioConsumido = $cogs + $merma;
        $rotacion = $inventarioValor > 0 ? $inventarioConsumido / $inventarioValor : null;
        $diasInventario = ($rotacion !== null && $rotacion > 0)
            ? ((int) $rango['dias']) / $rotacion
            : null;

        $rangoCortes = ['start' => (string) $rango['start'], 'end' => (string) $rango['end']];
        $cortes = [];
        try {
            $cortes = ContextoNegocio::nuevo()
                ? FinanzasNegocio::cortes(Producto::getDB(), ContextoNegocio::unidad($ctx), $rangoCortes['start'], $rangoCortes['end'])
                : self::cortes($rangoCortes['start'], $rangoCortes['end']);
        } catch (\Throwable $e) {
            error_log('AdminFinanzasController::cortes - ' . $e->getMessage());
        }

        /*
         * Paginación de los cortes. Los totales se suman sobre TODOS los días
         * del periodo, antes de recortar: si se calcularan recorriendo las
         * filas de la página describirían la página, no el periodo (el error
         * que ya tuvo Tickets). Una página fuera de rango cae a la última en
         * vez de pintar una tabla vacía: pasa al acortar el periodo desde una
         * página alta con un enlace guardado.
         */
        $totalesCortes = ['dias' => count($cortes), 'tickets' => 0, 'ventas' => 0.0, 'propinas' => 0.0,
            'efectivo' => 0.0, 'tarjeta' => 0.0, 'total' => 0.0];
        foreach ($cortes as $c) {
            $totalesCortes['tickets'] += (int) $c['tickets'];
            foreach (['ventas', 'propinas', 'efectivo', 'tarjeta', 'total'] as $campo) {
                $totalesCortes[$campo] += (float) $c[$campo];
            }
        }
        $cortesPaginas = max(1, (int) ceil(count($cortes) / self::CORTES_POR_PAGINA));
        $cortesPagina = filter_var($_GET['cortes'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $cortesPagina = min($cortesPagina, $cortesPaginas);
        $cortesDesde = ($cortesPagina - 1) * self::CORTES_POR_PAGINA;
        $cortesVisibles = array_slice($cortes, $cortesDesde, self::CORTES_POR_PAGINA);

        $diagnostico = DiagnosticoFinanciero::armar([
            'giro' => $ctx,
            'ingresos' => $ingresos,
            'cogs' => $cogs,
            'merma' => $merma,
            'gastos' => $totalGastos,
            'gastosPorCategoria' => $gastosPorCategoria,
            'dias' => (int) $rango['dias'],
            'tickets' => $datos['tickets'],
            'propinas' => $datos['propinas'],
            'inventarioValor' => $inventarioValor,
            'ingresosSinCosto' => $datos['sinCosto'],
            'comparando' => $comparando,
            'periodoAnterior' => (string) ($rango['prevLabel'] ?? ''),
            'previo' => [
                'ingresos' => $previo['ingresos'],
                'cogs' => $previo['cogs'],
                'merma' => $mermaPrev,
                'tickets' => $previo['tickets'],
            ],
        ]);

        $informeHref = null;
        $informeAviso = null;
        if ($ctx === 'restaurante' && ContextoNegocio::nuevo()) {
            try {
                $periodoInforme = RangoPeriodo::resolverInforme(['desde' => $rango['start'], 'hasta' => $rango['end']]);
                $informeHref = self::PATH . '/informe.pdf?' . http_build_query([
                    'desde' => $periodoInforme['start'], 'hasta' => $periodoInforme['end'],
                ], '', '&', PHP_QUERY_RFC3986);
            } catch (\InvalidArgumentException $e) {
                $informeAviso = 'Seleccione un periodo válido de hasta 366 días para generar el informe.';
            }
        }
        self::render('finanzas/index', [
            'title' => 'Finanzas',
            'topbarSection' => 'Finanzas',
            // Lo que la vista dice distinto por negocio: a dónde mandan sus
            // formularios y enlaces, y si hay propinas (en la caja de
            // Panadería no las hay).
            'finAmbito' => $ctx,
            'finBase' => self::base($ctx),
            'finPropinas' => $ctx === 'restaurante',
            'rango' => $rango,
            'informeHref' => $informeHref,
            'informeAviso' => $informeAviso,
            'diagnostico' => $diagnostico,
            'cortes' => $cortesVisibles,
            'totalesCortes' => $totalesCortes,
            'cortesPagina' => $cortesPagina,
            'cortesPaginas' => $cortesPaginas,
            'cortesDesde' => $cortesDesde,
            'rangoCortes' => $rangoCortes,
            'gastosMensuales' => $gastosMensuales,
            'ingresos' => $ingresos,
            'propinas' => $datos['propinas'],
            'tickets' => $datos['tickets'],
            'ticketPromedio' => $ticketPromedio,
            'cogs' => $cogs,
            'merma' => $merma,
            'utilidadBruta' => $utilidadBruta,
            'margenBruto' => $ingresos > 0 ? ($utilidadBruta / $ingresos) * 100 : 0,
            'totalGastos' => $totalGastos,
            'utilidadNeta' => $utilidadNeta,
            // Faltaba: utilidadNeta se calculaba pero nunca se expresaba como
            // porcentaje de los ingresos, que es como se compara entre periodos.
            'margenNeto' => $ingresos > 0 ? ($utilidadNeta / $ingresos) * 100 : 0,

            /*
             * Comparativos contra el periodo anterior. Todos salen de $previo,
             * que ya se consultaba entero y del que sólo se leían dos claves.
             *
             * Los gastos fijos NO llevan comparativo: gastos_fijos no tiene
             * columna de fecha, así que el periodo anterior arroja exactamente
             * el mismo prorrateo y un "0%" fingiría una medición que no existe.
             */
            'comparando' => $comparando,
            'deltaIngresos' => RangoPeriodo::delta($ingresos, $previo['ingresos']),
            'deltaCogs' => RangoPeriodo::delta($cogs, $previo['cogs']),
            'deltaMerma' => RangoPeriodo::delta($merma, $mermaPrev),
            'deltaUtilidadBruta' => RangoPeriodo::delta($utilidadBruta, $utilidadBrutaPrev),
            'deltaUtilidadNeta' => RangoPeriodo::delta($utilidadNeta, $utilidadNetaPrev),
            'deltaTicketPromedio' => RangoPeriodo::delta($ticketPromedio, $ticketPromedioPrev),
            'deltaTickets' => RangoPeriodo::delta((float) $datos['tickets'], (float) $previo['tickets']),
            'deltaPropinas' => RangoPeriodo::delta($datos['propinas'], $previo['propinas']),
            'previo' => [
                'ingresos' => $previo['ingresos'],
                'cogs' => $previo['cogs'],
                'merma' => $mermaPrev,
                'utilidadBruta' => $utilidadBrutaPrev,
                'utilidadNeta' => $utilidadNetaPrev,
                'ticketPromedio' => $ticketPromedioPrev,
                'tickets' => $previo['tickets'],
                'propinas' => $previo['propinas'],
            ],

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

    /** Informe de Restaurante: autorización antes de leer cualquier dato financiero. */
    public static function informe(Router $router): void
    {
        try {
            if (!\Classes\Auth::check() || ContextoNegocio::nivelAuth() !== 'contrasena') {
                http_response_code(403);
                echo 'No tienes permiso para generar este informe.';
                return;
            }
            ContextoNegocio::exigir('finanzas', 'ver', 'restaurante');
            foreach (['unidad_id', 'negocio', 'slug'] as $parametro) {
                if (array_key_exists($parametro, $_GET)) throw new \InvalidArgumentException('Parámetro no admitido.');
            }
            $rango = RangoPeriodo::resolverInforme($_GET);
            $unidad = ContextoNegocio::vendedora('restaurante');
            $ahora = date('Y-m-d H:i:s');
            $db = ContextoNegocio::db();
            $documento = \Services\Shared\LecturaConsistente::ejecutar($db, static fn ($db) => FinanzasNegocio::informe($db, $unidad, $rango, $ahora));
            $archivo = 'informe-finanzas-restaurante-' . $rango['start'] . '_' . $rango['end'] . '.pdf';
            \Classes\Auth::liberarSesion();
            GeneradorPdf::emitir($documento, $archivo);
        } catch (\InvalidArgumentException $e) {
            http_response_code(400);
            echo 'Seleccione un periodo válido de hasta 366 días.';
        } catch (\Services\Inventory\InventarioError $e) {
            if (in_array($e->codigo, ['NO_AUTORIZADO', 'NIVEL_AUTH_REQUERIDO', 'AMBITO_DENEGADO', 'PERMISO_DENEGADO'], true)) {
                http_response_code(403);
                echo 'No tienes permiso para generar este informe.';
            } else {
                http_response_code(500);
                error_log('No se pudo generar el informe financiero.');
                echo 'No se pudo generar el informe financiero.';
            }
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log('No se pudo generar el informe financiero.');
            echo 'No se pudo generar el informe financiero.';
        }
    }

    /**
     * Valor de la merma registrada en el tramo.
     *
     * Se valoriza con el costo que el movimiento congeló al registrarse; el
     * COALESCE cubre los movimientos anteriores a que la bitácora guardara ese
     * costo, que caen al costo actual del ingrediente.
     */
    private static function mermaDelPeriodo(\mysqli $db, string $start, string $end): float
    {
        $res = $db->query(
            "SELECT COALESCE(SUM(ABS(mi.cantidad) * COALESCE(mi.costo_unitario, i.costo)), 0) AS valor
               FROM movimientos_inventario mi
               JOIN ingredientes i ON i.id = mi.ingrediente_id
              WHERE mi.tipo = 'merma'
                AND mi.created_at >= '{$start} 00:00:00'
                AND mi.created_at <= '{$end} 23:59:59'"
        );

        return ($res && ($row = $res->fetch_assoc())) ? (float) $row['valor'] : 0.0;
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
     * @param array<int|string, float> $costoCache por producto (int) o por
     *        producto + variante ("id|ids") en el COGS
     * @return array{ingresos: float, propinas: float, tickets: int, cogs: float, sinCosto: float}
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

        $agregados = ['ingresos' => 0.0, 'propinas' => 0.0, 'tickets' => 0, 'cogs' => 0.0, 'sinCosto' => 0.0];

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

        // COGS: unidades vendidas × costo de receta. Se agrupa también por
        // variante: un Cappuccino con almendra no cuesta lo mismo que el de la
        // carta, y la receta con la que se descontó el inventario es la que
        // debe costearse.
        //
        // Se suma además lo que se vendió SIN costo conocido —platillo que ya
        // no existe en el catálogo, o sin receta—: cuenta como $0 de insumos y
        // abarata el porcentaje sin que nadie lo note. El diagnóstico lo avisa
        // cuando pasa del 5 % de la venta.
        $res = $db->query(
            "SELECT ti.nombre, COALESCE(ti.variante_ids, '') AS variante_ids, SUM(ti.cantidad) AS unidades,
                    SUM(ti.precio * ti.cantidad) AS importe
               FROM ticket_items ti
               JOIN tickets t ON t.id = ti.ticket_id
              WHERE t.estado = 'cerrado' AND ti.estado <> 'cancelado' {$filtro}
              GROUP BY ti.nombre, COALESCE(ti.variante_ids, '')"
        );
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $prod = $productosPorNombre[$row['nombre']] ?? null;
                if (!$prod) {
                    $agregados['sinCosto'] += (float) $row['importe'];
                    continue;
                }
                $pid = $prod['id'];
                $ids = $row['variante_ids'] !== '' ? array_map('intval', explode(',', $row['variante_ids'])) : [];
                $clave = $pid . '|' . $row['variante_ids'];
                if (!isset($costoCache[$clave])) {
                    $costoCache[$clave] = Inventario::costoDeProducto($pid, $ids);
                }
                if ($costoCache[$clave] <= 0) {
                    $agregados['sinCosto'] += (float) $row['importe'];
                }
                $agregados['cogs'] += $costoCache[$clave] * (float) $row['unidades'];
            }
        }

        return $agregados;
    }

    public static function guardarGasto(Router $router): void
    {
        $ctx = self::negocioQueEdita();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::base($ctx));
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $gasto = $id ? GastoFijo::find($id) : new GastoFijo();
        if ($id && !$gasto && ContextoNegocio::nuevo()) {
            GastoFijo::setAlerta('error', 'El gasto no existe en ' . Contextos::NOMBRES[$ctx] . '.');
            self::index($router);
            return;
        }
        if (!$gasto) {
            $gasto = new GastoFijo();
        }

        $gasto->sincronizar($_POST);
        $gasto->activo = isset($_POST['activo']) ? 1 : 0;
        $alertas = $gasto->validar();

        if (empty($alertas)) {
            $guardado = $gasto->guardar();
            if (!ContextoNegocio::nuevo() || $guardado === true || (is_array($guardado) && ($guardado['resultado'] ?? false))) {
                GastoFijo::setAlerta('exito', $id ? 'Gasto actualizado' : 'Gasto agregado');
            } else {
                GastoFijo::setAlerta('error', 'No se confirmó el guardado del gasto.');
            }
        }

        self::index($router);
    }

    public static function eliminarGasto(Router $router): void
    {
        $ctx = self::negocioQueEdita();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            self::redirect(self::base($ctx));
        }

        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $gasto = $id ? GastoFijo::find($id) : null;
        if ($gasto && $gasto->eliminar()) {
            GastoFijo::setAlerta('exito', 'Gasto eliminado');
        } elseif (ContextoNegocio::nuevo()) {
            GastoFijo::setAlerta('error', 'No se confirmó la eliminación del gasto.');
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

    /**
     * Finanzas de Global: el pulso de hoy, el periodo lado a lado y el total
     * de la organización (ConsolidadoFinanciero). Sólo lectura.
     */
    private static function globalIndex(array $rango): void
    {
        $consolidado = null;
        try {
            $consolidado = ConsolidadoFinanciero::armar($rango);
        } catch (\Services\Inventory\InventarioError $e) {
            if (in_array($e->codigo, ['PERMISO_DENEGADO', 'AMBITO_DENEGADO'], true)) {
                http_response_code(403);
            }
            GastoFijo::setAlerta('error', 'No tienes permiso para ver las finanzas de la organización.');
        } catch (\Throwable $e) {
            error_log('AdminFinanzasController::globalIndex - ' . $e->getMessage());
            GastoFijo::setAlerta('error', 'No se pudieron cargar las finanzas de la organización.');
        }
        // A la pantalla de un negocio sólo se ofrece ir si la persona puede
        // abrirla: el consolidado no concede las finanzas de cada negocio.
        $enlaces = [];
        foreach (FinanzasNegocio::NEGOCIOS as $slug) {
            if (\Classes\Auth::puede('finanzas', 'ver', $slug)) {
                $enlaces[$slug] = self::base($slug) . '?' . http_build_query(array_intersect_key($_GET, array_flip(['rango', 'desde', 'hasta'])));
            }
        }

        self::render('finanzas/global', [
            'title' => 'Finanzas',
            'topbarSection' => 'Finanzas',
            'rango' => $rango,
            'consolidado' => $consolidado,
            'enlacesNegocio' => $enlaces,
            'alertas' => GastoFijo::getAlertas(),
        ]);
    }

    /** Ruta de Finanzas del ámbito. */
    private static function base(string $ctx): string
    {
        return $ctx === 'restaurante' ? self::PATH : Contextos::inicio($ctx) . '/finanzas';
    }

    /** El negocio cuyos gastos se editan: el de la ruta, con permiso de editar. */
    private static function negocioQueEdita(): string
    {
        if (!ContextoNegocio::nuevo()) return 'restaurante';
        $ctx = ContextoNegocio::slug();
        if (!in_array($ctx, FinanzasNegocio::NEGOCIOS, true)) {
            throw new \RuntimeException('Los gastos fijos son de cada negocio: Restaurante o Panadería.');
        }
        ContextoNegocio::exigir('finanzas', 'editar', $ctx);
        return $ctx;
    }

    private static function render(string $view, array $data = []): void
    {
        AdminController::render($view, array_merge([
            'activeModule' => 'finanzas',
            'styles' => [self::CSS],
            'scripts' => [self::JS],
        ], $data));
    }

    private static function redirect(string $url): void
    {
        header('Location: ' . $url, true, 302);
        exit;
    }
}
