<?php
    $rango = is_array($rango ?? null) ? $rango : [];
    $periodoLabel = (string) ($rango['label'] ?? 'Últimos 30 días');
    $ingresos = (float) ($ingresos ?? 0);
    $propinas = (float) ($propinas ?? 0);
    $tickets = (int) ($tickets ?? 0);
    $ticketPromedio = (float) ($ticketPromedio ?? 0);
    $cogs = (float) ($cogs ?? 0);
    $merma = (float) ($merma ?? 0);
    $deltaMerma = isset($deltaMerma) ? $deltaMerma : null;
    $utilidadBruta = (float) ($utilidadBruta ?? 0);
    $margenBruto = (float) ($margenBruto ?? 0);
    $totalGastos = (float) ($totalGastos ?? 0);
    $utilidadNeta = (float) ($utilidadNeta ?? 0);
    $margenNeto = (float) ($margenNeto ?? 0);
    $deltaIngresos = isset($deltaIngresos) ? $deltaIngresos : null;
    $deltaUtilidadBruta = isset($deltaUtilidadBruta) ? $deltaUtilidadBruta : null;
    $deltaCogs = isset($deltaCogs) ? $deltaCogs : null;
    $deltaUtilidadNeta = isset($deltaUtilidadNeta) ? $deltaUtilidadNeta : null;
    $comparando = (bool) ($comparando ?? false);
    // Cifras del periodo anterior, para que el porcentaje diga desde dónde.
    $previoCifras = is_array($previo ?? null) ? $previo : [];
    $previoCifras += [
        'ingresos' => 0.0, 'cogs' => 0.0, 'merma' => 0.0,
        'utilidadBruta' => 0.0, 'utilidadNeta' => 0.0,
    ];
    $inventarioValor = (float) ($inventarioValor ?? 0);
    $rotacion = isset($rotacion) ? $rotacion : null;
    $diasInventario = isset($diasInventario) ? $diasInventario : null;
    $gastos = isset($gastos) && is_iterable($gastos) ? $gastos : [];
    $gastosPorCategoria = is_array($gastosPorCategoria ?? null) ? $gastosPorCategoria : [];
    $categorias = isset($categorias) && is_iterable($categorias) ? $categorias : ['renta', 'servicios', 'nomina', 'insumos', 'otros'];
    $rentabilidad = isset($rentabilidad) && is_iterable($rentabilidad) ? $rentabilidad : [];
    $cortes = isset($cortes) && is_iterable($cortes) ? $cortes : [];
    $money = static fn($v): string => '$' . number_format((float) $v, 2);
    $etiquetaCat = [
        'renta' => 'Renta', 'servicios' => 'Servicios', 'nomina' => 'Nómina',
        'insumos' => 'Insumos', 'otros' => 'Otros',
    ];
    $mesesCortos = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    $diasSemana = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    $fechaBonita = static function (string $iso) use ($mesesCortos, $diasSemana): string {
        $ts = strtotime($iso);
        if (!$ts) {
            return $iso;
        }
        return ucfirst($diasSemana[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts) . ' ' . $mesesCortos[(int) date('n', $ts)];
    };
    $hoyIso = date('Y-m-d');
    $rangoCortes = is_array($rangoCortes ?? null) ? $rangoCortes : ['start' => date('Y-m-d', strtotime('-6 days')), 'end' => $hoyIso];

    // El badge de variación lo arma ahora views/admin/partials/_stat-card.php,
    // que además lleva la flecha y la cifra del periodo anterior.

    // Descomposición para el Sankey: de dónde viene el dinero y en qué se va.
    // Los flujos negativos no se representan: un Sankey sólo entiende caudales,
    // así que una utilidad neta en rojo se anota aparte en vez de dibujarse.
    $sankey = [];
    if ($ingresos > 0) {
        if ($cogs > 0) {
            $sankey[] = ['from' => 'Ingresos', 'to' => 'Costo de insumos', 'flow' => round($cogs, 2)];
        }
        if ($merma > 0) {
            $sankey[] = ['from' => 'Ingresos', 'to' => 'Merma', 'flow' => round($merma, 2)];
        }
        if ($utilidadBruta > 0) {
            $sankey[] = ['from' => 'Ingresos', 'to' => 'Utilidad bruta', 'flow' => round($utilidadBruta, 2)];
            foreach ($gastosPorCategoria as $cat => $monto) {
                if ($monto > 0) {
                    $sankey[] = [
                        'from' => 'Utilidad bruta',
                        'to' => $etiquetaCat[$cat] ?? (string) $cat,
                        'flow' => round((float) $monto, 2),
                    ];
                }
            }
            if ($utilidadNeta > 0) {
                $sankey[] = ['from' => 'Utilidad bruta', 'to' => 'Utilidad neta', 'flow' => round($utilidadNeta, 2)];
            }
        }
    }

    $gastosChart = ['labels' => [], 'values' => []];
    foreach ($gastosPorCategoria as $cat => $monto) {
        $gastosChart['labels'][] = $etiquetaCat[$cat] ?? (string) $cat;
        $gastosChart['values'][] = round((float) $monto, 2);
    }
?>
<script>
    window.AdminFinanzasData = <?php echo json_encode([
        'sankey' => $sankey,
        'gastos' => $gastosChart,
        'utilidadNetaNegativa' => $utilidadNeta < 0,
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<section class="admin-finanzas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Finanzas</span>
            <h2 class="admin-page__title">Situación financiera</h2>
            <p class="admin-page__subtitle"><?php echo htmlspecialchars($periodoLabel); ?>: ingresos por ventas, costo de insumos (según recetas), merma, gastos fijos y utilidad neta.</p>
        </div>
        <?php /* El selector de periodo se queda solo en el encabezado: mide 82px
                 contra los 44 de un botón y, compartiendo fila, se los comía.
                 Las acciones bajan a su propia barra, ya a tamaño completo. */ ?>
        <div class="admin-actions">
            <?php include __DIR__ . '/../partials/_range-picker.php'; ?>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-toolbar admin-finanzas__toolbar">
        <a class="admin-btn admin-btn--secondary" href="/admin/inventario">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M21 8 12 3 3 8v8l9 5 9-5Z"/><path d="m3 8 9 5 9-5"/><path d="M12 13v8"/></svg>
            Inventario
        </a>
        <a class="admin-btn admin-btn--secondary" href="/admin/recetas">
            <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16v16H4z"/><path d="M8 9h8M8 13h8M8 17h5"/></svg>
            Recetas
        </a>
    </div>

    <div class="admin-stat-strip">
        <?php
            // La utilidad neta abre la franja y a doble ancho: es la cifra que
            // contesta «¿cómo vamos?» y antes quedaba huérfana en la segunda fila.
            $statLabel = 'Utilidad neta estimada';
            $statValue = $money($utilidadNeta);
            $statSub = 'Margen ' . number_format($margenNeto, 1) . '% · ingresos menos insumos, merma y gastos fijos';
            $statDelta = $deltaUtilidadNeta ?? null;
            $statPrev = $comparando ? $money($previoCifras['utilidadNeta']) : '';
            $statTone = $utilidadNeta >= 0 ? 'good' : 'bad';
            $statLead = true;
            include __DIR__ . '/../partials/_stat-card.php';

            $statLabel = 'Ingresos del periodo';
            $statValue = $money($ingresos);
            $statSub = $tickets . ' tickets · prom. ' . $money($ticketPromedio);
            $statDelta = $deltaIngresos ?? null;
            $statPrev = $comparando ? $money($previoCifras['ingresos']) : '';
            include __DIR__ . '/../partials/_stat-card.php';

            $statLabel = 'Costo de insumos';
            $statValue = $money($cogs);
            $statSub = 'Según recetas vendidas';
            $statDelta = $deltaCogs ?? null;
            $statPrev = $comparando ? $money($previoCifras['cogs']) : '';
            // Que el costo suba no es una buena noticia aunque suba la venta.
            $statInverso = true;
            include __DIR__ . '/../partials/_stat-card.php';

            $statLabel = 'Merma';
            $statValue = $money($merma);
            $statSub = 'Insumos pagados que no se vendieron'
                . ($ingresos > 0 ? ' · ' . number_format(($merma / $ingresos) * 100, 1) . '% del ingreso' : '');
            $statDelta = $deltaMerma ?? null;
            $statPrev = $comparando ? $money($previoCifras['merma']) : '';
            $statTone = $merma > 0 ? 'bad' : '';
            $statInverso = true;
            include __DIR__ . '/../partials/_stat-card.php';

            $statLabel = 'Utilidad bruta';
            $statValue = $money($utilidadBruta);
            $statSub = 'Margen ' . number_format($margenBruto, 1) . '%';
            $statDelta = $deltaUtilidadBruta ?? null;
            $statPrev = $comparando ? $money($previoCifras['utilidadBruta']) : '';
            $statTone = 'gold';
            include __DIR__ . '/../partials/_stat-card.php';

            // Sin comparativo a propósito: gastos_fijos no guarda fecha, así que
            // el periodo anterior devuelve exactamente el mismo prorrateo.
            $statLabel = 'Gastos fijos';
            $statValue = $money($totalGastos);
            $statSub = 'Montos mensuales prorrateados al periodo · no se comparan entre periodos';
            include __DIR__ . '/../partials/_stat-card.php';

            // Denominador puntual: no hay histórico de stock del que sacar un
            // inventario promedio, y decirlo evita que se lea como el ratio
            // contable estricto.
            $statLabel = 'Rotación de inventarios';
            $statValue = $rotacion === null ? '—' : number_format($rotacion, 2) . '×';
            $statSub = $rotacion === null
                ? 'Sin inventario valorizado'
                : number_format((float) $diasInventario, 0) . ' días de inventario · insumos vendidos y mermados sobre existencias de hoy (' . $money($inventarioValor) . ')';
            include __DIR__ . '/../partials/_stat-card.php';
        ?>
    </div>

    <div class="admin-finanzas__grid">
        <!-- Cascada de resultados -->
        <section class="admin-panel admin-card">
            <div class="admin-panel-head"><div><h3>Estado de resultados</h3><p><?php echo htmlspecialchars($periodoLabel); ?></p></div></div>
            <ul class="admin-pnl">
                <li class="admin-pnl__row"><span>Ingresos por ventas</span><strong class="is-pos"><?php echo $money($ingresos); ?></strong></li>
                <li class="admin-pnl__row"><span>(−) Costo de insumos</span><strong class="is-neg">-<?php echo $money($cogs); ?></strong></li>
                <li class="admin-pnl__row"><span>(−) Merma</span><strong class="is-neg">-<?php echo $money($merma); ?></strong></li>
                <li class="admin-pnl__row admin-pnl__row--total">
                    <span>= Utilidad bruta <small class="admin-pnl__margen">margen <?php echo number_format($margenBruto, 1); ?>%</small></span>
                    <strong><?php echo $money($utilidadBruta); ?></strong>
                </li>
                <li class="admin-pnl__row"><span>(−) Gastos fijos</span><strong class="is-neg">-<?php echo $money($totalGastos); ?></strong></li>
                <li class="admin-pnl__row admin-pnl__row--grand <?php echo $utilidadNeta >= 0 ? 'is-pos' : 'is-neg'; ?>">
                    <span>= Utilidad neta <small class="admin-pnl__margen">margen <?php echo number_format($margenNeto, 1); ?>%</small></span>
                    <strong><?php echo $money($utilidadNeta); ?></strong>
                </li>
            </ul>
            <p class="admin-finanzas__note">
                Las propinas del periodo (<?php echo $money($propinas); ?>) no se incluyen en la utilidad; corresponden al personal.
                La merma se valoriza al costo que tenía el ingrediente el día en que se registró.
                Los gastos fijos son montos mensuales, prorrateados a los días del periodo.
            </p>
        </section>

        <!-- Gastos fijos por categoría -->
        <section class="admin-panel admin-card">
            <div class="admin-panel-head"><div><h3>Gastos por categoría</h3><p>Reparto del gasto fijo del periodo</p></div></div>
            <?php if (empty($gastosPorCategoria)) : ?>
                <p class="admin-empty">Sin gastos registrados.</p>
            <?php else : ?>
                <?php /* Dona más tabla: el color identifica la categoría, pero
                         una dona no comunica montos exactos y aquí sí importan. */ ?>
                <div class="admin-finanzas__donut-wrap">
                    <div class="admin-finanzas__donut"><canvas id="gastosCategoriaChart"></canvas></div>
                    <ul class="admin-finanzas__donut-list">
                        <?php foreach ($gastosPorCategoria as $cat => $monto) : ?>
                            <?php $pct = $totalGastos > 0 ? ($monto / $totalGastos) * 100 : 0; ?>
                            <li>
                                <span class="admin-finanzas__donut-label"><?php echo htmlspecialchars($etiquetaCat[$cat] ?? $cat); ?></span>
                                <span class="admin-finanzas__donut-val"><?php echo $money($monto); ?></span>
                                <span class="admin-finanzas__donut-pct"><?php echo number_format($pct, 1); ?>%</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Descomposición del ingreso -->
    <section class="admin-panel admin-card admin-finanzas__sankey-panel">
        <div class="admin-panel-head">
            <div>
                <h3>A dónde va cada peso</h3>
                <p>Del ingreso del periodo al costo de insumos, la merma, los gastos fijos y lo que queda.</p>
            </div>
        </div>
        <?php if (empty($sankey)) : ?>
            <p class="admin-empty">Sin ingresos en el periodo para descomponer.</p>
        <?php else : ?>
            <div class="admin-finanzas__sankey"><canvas id="flujoFinancieroChart"></canvas></div>
            <?php if ($utilidadNeta < 0) : ?>
                <p class="admin-finanzas__note">
                    La utilidad neta es negativa (<?php echo $money($utilidadNeta); ?>): los gastos fijos
                    superan a la utilidad bruta, así que el diagrama no dibuja ese tramo.
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Cortes de caja por día -->
    <section class="admin-panel admin-card admin-cortes">
        <div class="admin-panel-head">
            <?php /* Ya no lleva su propio formulario de fechas: el selector de
                     periodo del encabezado manda sobre toda la pantalla, y dos
                     rangos distintos en la misma página se contradecían. */ ?>
            <div><h3>Cortes de caja</h3><p>Resumen diario de tickets cerrados en el periodo seleccionado.</p></div>
        </div>
        <?php if (empty($cortes)) : ?>
            <p class="admin-empty">Sin ventas cerradas en el rango seleccionado.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table admin-cortes__table">
                    <thead>
                        <tr>
                            <th>Día</th>
                            <th>Tickets</th>
                            <th>Ventas</th>
                            <th>Propinas</th>
                            <th>Efectivo</th>
                            <th>Tarjeta</th>
                            <th>Total recibido</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cortes as $c) : ?>
                            <tr<?php echo $c['fecha'] === $hoyIso ? ' class="is-today"' : ''; ?>>
                                <td>
                                    <div class="admin-cortes__day">
                                        <span class="admin-table__cell-main"><?php echo htmlspecialchars($fechaBonita($c['fecha'])); ?></span>
                                        <?php if ($c['fecha'] === $hoyIso) : ?><span class="admin-badge admin-badge--success">Hoy</span><?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="admin-table__cell-sub"><?php echo (int) $c['tickets']; ?></span></td>
                                <td><?php echo $money($c['ventas']); ?></td>
                                <td><?php echo $money($c['propinas']); ?></td>
                                <td><?php echo $money($c['efectivo']); ?></td>
                                <td><?php echo $money($c['tarjeta']); ?></td>
                                <td><strong class="admin-cortes__total"><?php echo $money($c['total']); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Gastos fijos: administración -->
    <section class="admin-panel admin-card">
        <div class="admin-panel-head"><div><h3>Gastos fijos</h3><p>Renta, servicios, nómina y demás costos mensuales.</p></div></div>

        <form class="admin-finanzas__add" method="POST" action="/admin/finanzas/gasto/guardar">
            <input type="text" name="nombre" placeholder="Nombre del gasto" maxlength="120" required aria-label="Nombre del gasto">
            <select name="categoria" aria-label="Categoría">
                <?php foreach ($categorias as $c) : ?>
                    <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($etiquetaCat[$c] ?? $c); ?></option>
                <?php endforeach; ?>
            </select>
            <?php /* Mismo envoltorio que el input de edición: sin él, el gasto
                     nuevo era el único campo de dinero sin su símbolo. */ ?>
            <div class="admin-finanzas__money">
                <span>$</span>
                <input type="number" name="monto" step="0.01" min="0" placeholder="Monto mensual" required aria-label="Monto mensual">
            </div>
            <button type="submit" class="admin-btn admin-btn--primary">Agregar</button>
        </form>

        <?php if (empty($gastos)) : ?>
            <p class="admin-empty">No hay gastos fijos registrados.</p>
        <?php else : ?>
            <div class="admin-gasto-list">
                <?php foreach ($gastos as $g) : ?>
                    <article class="admin-gasto-card <?php echo $g->activo ? '' : 'is-inactive'; ?>">
                        <form method="POST" action="/admin/finanzas/gasto/guardar" class="admin-gasto-card__form">
                            <input type="hidden" name="id" value="<?php echo (int) $g->id; ?>">
                            <div class="admin-gasto-card__field admin-gasto-card__field--name">
                                <label class="admin-gasto-card__label">Gasto</label>
                                <input type="text" name="nombre" value="<?php echo htmlspecialchars($g->nombre, ENT_QUOTES); ?>" maxlength="120" required aria-label="Nombre del gasto">
                            </div>
                            <div class="admin-gasto-card__field">
                                <label class="admin-gasto-card__label">Categoría</label>
                                <select name="categoria" aria-label="Categoría">
                                    <?php foreach ($categorias as $c) : ?>
                                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $g->categoria === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($etiquetaCat[$c] ?? $c); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="admin-gasto-card__field">
                                <label class="admin-gasto-card__label">Monto mensual</label>
                                <div class="admin-finanzas__money">
                                    <span>$</span>
                                    <input type="number" name="monto" step="0.01" min="0" value="<?php echo htmlspecialchars((string) $g->monto, ENT_QUOTES); ?>" required aria-label="Monto mensual">
                                </div>
                            </div>
                            <label class="admin-switch admin-gasto-card__switch">
                                <input type="checkbox" name="activo" value="1" <?php echo $g->activo ? 'checked' : ''; ?>>
                                <span class="admin-switch__track"><span class="admin-switch__thumb"></span></span>
                                <span class="admin-switch__label">Activo</span>
                            </label>
                            <div class="admin-gasto-card__actions">
                                <button type="submit" class="admin-btn admin-btn--primary admin-btn--small">
                                    <svg class="admin-btn__icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 6 9 17l-5-5"/></svg>
                                    Guardar
                                </button>
                            </div>
                        </form>
                        <form method="POST" action="/admin/finanzas/gasto/eliminar" class="admin-gasto-card__delete"
                              data-confirm-delete
                              data-confirm-eyebrow="Eliminar gasto fijo"
                              data-confirm-title="¿Eliminar «<?php echo htmlspecialchars($g->nombre, ENT_QUOTES); ?>»?"
                              data-confirm-description="Dejará de restarse de la utilidad neta en todos los periodos."
                              data-confirm-consequence="Esta acción no se puede deshacer.">
                            <input type="hidden" name="id" value="<?php echo (int) $g->id; ?>">
                            <button type="submit" class="admin-icon-button admin-icon-button--danger" title="Eliminar" aria-label="Eliminar gasto">
                                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/></svg>
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Rentabilidad por producto -->
    <section class="admin-panel admin-card">
        <div class="admin-panel-head"><div><h3>Rentabilidad por producto</h3><p>Precio de venta vs. costo de insumos de cada producto con receta.</p></div></div>
        <?php if (empty($rentabilidad)) : ?>
            <p class="admin-empty">Aún no hay productos con receta. Define recetas en Productos para ver su margen.</p>
        <?php else : ?>
            <div class="admin-table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Producto</th><th>Precio</th><th>Costo insumos</th><th>Margen</th><th>Margen %</th></tr></thead>
                    <tbody>
                        <?php foreach ($rentabilidad as $r) : ?>
                            <tr>
                                <td><span class="admin-table__cell-main"><?php echo htmlspecialchars($r['nombre']); ?></span></td>
                                <td><?php echo $money($r['precio']); ?></td>
                                <td><?php echo $money($r['costo']); ?></td>
                                <td><?php echo $money($r['margen']); ?></td>
                                <td>
                                    <span class="admin-badge admin-badge--<?php echo $r['margen_pct'] >= 65 ? 'success' : ($r['margen_pct'] >= 40 ? 'warning' : 'danger'); ?>">
                                        <?php echo number_format($r['margen_pct'], 1); ?>%
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
