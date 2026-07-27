<?php

/**
 * Vista principal de analytics del panel de administración.
 * Métricas, gráficas y resumen operativo con datos REALES de la BD
 * ($analytics, construido en AdminController::construirAnalytics).
 */
$analytics = is_array($analytics ?? null) ? $analytics : ['metrics' => [], 'tickets' => [], 'payments' => [], 'charts' => []];
$rango = is_array($rango ?? null) ? $rango : ['start' => date('Y-m-d', strtotime('-29 days')), 'end' => date('Y-m-d'), 'preset' => 30, 'label' => 'Últimos 30 días'];
$rangoPreset = (int) ($rango['preset'] ?? 0);
$esCustom = $rangoPreset === 0;
$hoyIso = date('Y-m-d');
?>
<script>
    window.AdminAnalyticsMock = <?php echo json_encode($analytics, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
</script>
<section class="admin-analytics" data-admin-analytics>
    <header class="admin-page-header">
        <div class="admin-page-header__intro">
            <div class="admin-page-title-row">
                <h2>Análisis de datos</h2>
            </div>
            <p class="admin-page-summary">
                Resumen operativo <span aria-hidden="true">·</span>
                <span data-analytics-caption><?php echo htmlspecialchars($rango['label']); ?></span>
            </p>
        </div>

        <div class="admin-filter-bar admin-analytics-filters" data-analytics-filters aria-label="Filtros del resumen">
            <label class="admin-analytics-filters__field">
                <span>Periodo</span>
                <select data-analytics-filter="range">
                    <option value="3" <?php echo $rangoPreset === 3 ? 'selected' : ''; ?>>Últimos 3 días</option>
                    <option value="7" <?php echo $rangoPreset === 7 ? 'selected' : ''; ?>>Últimos 7 días</option>
                    <option value="30" <?php echo $rangoPreset === 30 ? 'selected' : ''; ?>>Últimos 30 días</option>
                    <option value="60" <?php echo $rangoPreset === 60 ? 'selected' : ''; ?>>Últimos 60 días</option>
                    <option value="365" <?php echo $rangoPreset === 365 ? 'selected' : ''; ?>>Último año</option>
                    <option value="custom" <?php echo $esCustom ? 'selected' : ''; ?>>Personalizado…</option>
                </select>
            </label>
            <div class="admin-analytics-filters__range" data-analytics-range <?php echo $esCustom ? '' : 'hidden'; ?>>
                <label class="admin-analytics-filters__field">
                    <span>Desde</span>
                    <input type="date" data-analytics-desde max="<?php echo $hoyIso; ?>" value="<?php echo htmlspecialchars((string) $rango['start']); ?>">
                </label>
                <label class="admin-analytics-filters__field">
                    <span>Hasta</span>
                    <input type="date" data-analytics-hasta max="<?php echo $hoyIso; ?>" value="<?php echo htmlspecialchars((string) $rango['end']); ?>">
                </label>
                <button type="button" class="admin-btn admin-btn--primary admin-btn--small" data-analytics-apply>Aplicar</button>
            </div>
        </div>
    </header>

    <section class="admin-metrics-section" aria-label="Indicadores principales">
        <div class="admin-metrics-grid admin-metrics-grid--primary" data-admin-metrics-primary></div>
        <div class="admin-metrics-grid admin-metrics-grid--secondary" data-admin-metrics-secondary></div>
    </section>

    <div class="admin-chart-grid">
        <article class="admin-panel admin-chart-card">
            <header>
                <div>
                    <h3>Ventas diarias del periodo</h3>
                    <p>Tickets cerrados por día</p>
                </div>
                <span>MXN</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="salesByDayChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <div>
                    <h3>Ventas por categoría</h3>
                    <p>Distribución del ingreso por familia</p>
                </div>
                <span>Subtotal</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="salesByCategoryChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <h3>Métodos de pago</h3>
                <span>Pagos</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="paymentMethodsChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <h3>Productos más vendidos</h3>
                <span>Unidades</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="topProductsChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <h3>Reservaciones por día</h3>
                <span>Personas</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="reservationsByDayChart"></canvas>
            </div>
        </article>

        <article class="admin-panel admin-chart-card">
            <header>
                <h3>Reservaciones por estado</h3>
                <span>Estado</span>
            </header>
            <div class="admin-chart-card__canvas">
                <canvas id="reservationSourcesChart"></canvas>
            </div>
        </article>
    </div>

    <article class="admin-panel admin-table-panel">
        <header>
            <div>
                <p class="admin-page-eyebrow">Actividad operativa</p>
                <h3>Resumen reciente</h3>
            </div>
            <span>Últimos tickets</span>
        </header>

        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Fecha</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Pago</th>
                    </tr>
                </thead>
                <tbody data-admin-summary></tbody>
            </table>
        </div>
    </article>
</section>