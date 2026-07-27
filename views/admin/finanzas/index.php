<?php
    $mes = (string) ($mes ?? '');
    $ingresos = (float) ($ingresos ?? 0);
    $propinas = (float) ($propinas ?? 0);
    $tickets = (int) ($tickets ?? 0);
    $ticketPromedio = (float) ($ticketPromedio ?? 0);
    $cogs = (float) ($cogs ?? 0);
    $utilidadBruta = (float) ($utilidadBruta ?? 0);
    $margenBruto = (float) ($margenBruto ?? 0);
    $totalGastos = (float) ($totalGastos ?? 0);
    $utilidadNeta = (float) ($utilidadNeta ?? 0);
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
?>
<section class="admin-finanzas admin-page">
    <header class="admin-page__header">
        <div class="admin-page__intro">
            <span class="admin-page__eyebrow">Finanzas</span>
            <h2 class="admin-page__title">Situación financiera</h2>
            <p class="admin-page__subtitle">Rubro del negocio en <?php echo htmlspecialchars($mes); ?>: ingresos por ventas, costo de insumos (según recetas), gastos fijos y utilidad neta.</p>
        </div>
        <div class="admin-actions">
            <a class="admin-btn admin-btn--secondary" href="/admin/inventario">Inventario</a>
            <a class="admin-btn admin-btn--secondary" href="/admin/productos">Productos</a>
        </div>
    </header>

    <?php include __DIR__ . '/../partials/alertas.php'; ?>

    <div class="admin-stat-strip">
        <div class="admin-stat-card">
            <span class="admin-stat-card__label">Ingresos del mes</span>
            <span class="admin-stat-card__value"><?php echo $money($ingresos); ?></span>
            <span class="admin-stat-card__sub"><?php echo $tickets; ?> tickets · prom. <?php echo $money($ticketPromedio); ?></span>
        </div>
        <div class="admin-stat-card">
            <span class="admin-stat-card__label">Costo de insumos</span>
            <span class="admin-stat-card__value"><?php echo $money($cogs); ?></span>
            <span class="admin-stat-card__sub">Según recetas vendidas</span>
        </div>
        <div class="admin-stat-card admin-stat-card--gold">
            <span class="admin-stat-card__label">Utilidad bruta</span>
            <span class="admin-stat-card__value"><?php echo $money($utilidadBruta); ?></span>
            <span class="admin-stat-card__sub">Margen <?php echo number_format($margenBruto, 1); ?>%</span>
        </div>
        <div class="admin-stat-card">
            <span class="admin-stat-card__label">Gastos fijos</span>
            <span class="admin-stat-card__value"><?php echo $money($totalGastos); ?></span>
            <span class="admin-stat-card__sub">Mensuales</span>
        </div>
        <div class="admin-stat-card <?php echo $utilidadNeta >= 0 ? 'admin-stat-card--good' : 'admin-stat-card--bad'; ?>">
            <span class="admin-stat-card__label">Utilidad neta estimada</span>
            <span class="admin-stat-card__value"><?php echo $money($utilidadNeta); ?></span>
            <span class="admin-stat-card__sub">Bruta − gastos fijos</span>
        </div>
    </div>

    <div class="admin-finanzas__grid">
        <!-- Cascada de resultados -->
        <section class="admin-panel admin-card">
            <div class="admin-panel-head"><div><h3>Estado de resultados</h3><p><?php echo htmlspecialchars($mes); ?> · ingresos a la fecha vs. gastos fijos del mes</p></div></div>
            <ul class="admin-pnl">
                <li class="admin-pnl__row"><span>Ingresos por ventas</span><strong class="is-pos"><?php echo $money($ingresos); ?></strong></li>
                <li class="admin-pnl__row"><span>(−) Costo de insumos</span><strong class="is-neg">-<?php echo $money($cogs); ?></strong></li>
                <li class="admin-pnl__row admin-pnl__row--total"><span>= Utilidad bruta</span><strong><?php echo $money($utilidadBruta); ?></strong></li>
                <li class="admin-pnl__row"><span>(−) Gastos fijos</span><strong class="is-neg">-<?php echo $money($totalGastos); ?></strong></li>
                <li class="admin-pnl__row admin-pnl__row--grand <?php echo $utilidadNeta >= 0 ? 'is-pos' : 'is-neg'; ?>"><span>= Utilidad neta</span><strong><?php echo $money($utilidadNeta); ?></strong></li>
            </ul>
            <p class="admin-finanzas__note">Las propinas del mes (<?php echo $money($propinas); ?>) no se incluyen en la utilidad; corresponden al personal.</p>
        </section>

        <!-- Gastos fijos por categoría -->
        <section class="admin-panel admin-card">
            <div class="admin-panel-head"><div><h3>Gastos por categoría</h3><p>Distribución mensual</p></div></div>
            <?php if (empty($gastosPorCategoria)) : ?>
                <p class="admin-empty">Sin gastos registrados.</p>
            <?php else : ?>
                <div class="admin-finanzas__bars">
                    <?php foreach ($gastosPorCategoria as $cat => $monto) : ?>
                        <?php $pct = $totalGastos > 0 ? ($monto / $totalGastos) * 100 : 0; ?>
                        <div class="admin-finanzas__bar-row">
                            <span class="admin-finanzas__bar-label"><?php echo htmlspecialchars($etiquetaCat[$cat] ?? $cat); ?></span>
                            <div class="admin-finanzas__bar"><span style="width:<?php echo number_format($pct, 1); ?>%"></span></div>
                            <span class="admin-finanzas__bar-val"><?php echo $money($monto); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <!-- Cortes de caja por día -->
    <section class="admin-panel admin-card admin-cortes">
        <div class="admin-panel-head">
            <div><h3>Cortes de caja</h3><p>Resumen diario de tickets cerrados en el rango seleccionado.</p></div>
            <form class="admin-cortes__filter" method="GET" action="/admin/finanzas" data-cortes-filter>
                <label class="admin-cortes__field">
                    <span>Desde</span>
                    <input type="date" name="desde" max="<?php echo $hoyIso; ?>" value="<?php echo htmlspecialchars((string) $rangoCortes['start']); ?>">
                </label>
                <label class="admin-cortes__field">
                    <span>Hasta</span>
                    <input type="date" name="hasta" max="<?php echo $hoyIso; ?>" value="<?php echo htmlspecialchars((string) $rangoCortes['end']); ?>">
                </label>
                <button type="submit" class="admin-btn admin-btn--primary admin-btn--small">Aplicar</button>
            </form>
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
                                    <span class="admin-table__cell-main"><?php echo htmlspecialchars($fechaBonita($c['fecha'])); ?></span>
                                    <?php if ($c['fecha'] === $hoyIso) : ?><span class="admin-badge admin-badge--success">Hoy</span><?php endif; ?>
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
            <input type="number" name="monto" step="0.01" min="0" placeholder="Monto mensual" required aria-label="Monto">
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
                        <form method="POST" action="/admin/finanzas/gasto/eliminar" class="admin-gasto-card__delete" onsubmit="return confirm('¿Eliminar &quot;<?php echo htmlspecialchars($g->nombre, ENT_QUOTES); ?>&quot;?');">
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
