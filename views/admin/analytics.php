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

        <?php
            $presets = [3 => 'Últimos 3 días', 7 => 'Últimos 7 días', 30 => 'Últimos 30 días',
                        60 => 'Últimos 60 días', 365 => 'Último año'];
            $mesesCortos = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
            $bonita = static function (string $iso) use ($mesesCortos): string {
                $ts = strtotime($iso);
                return $ts ? ((int) date('j', $ts) . ' ' . $mesesCortos[(int) date('n', $ts)]) : $iso;
            };
            $resumenRango = $bonita((string) $rango['start']) . ' – ' . $bonita((string) $rango['end'])
                          . ' ' . date('Y', strtotime((string) $rango['end']));
        ?>
        <div class="admin-range" data-analytics-range-picker
             data-start="<?php echo htmlspecialchars((string) $rango['start']); ?>"
             data-end="<?php echo htmlspecialchars((string) $rango['end']); ?>"
             data-today="<?php echo $hoyIso; ?>"
             data-preset="<?php echo $rangoPreset; ?>">

            <span class="admin-range__caption">Periodo</span>

            <button type="button" class="admin-range__trigger" data-range-trigger
                    aria-expanded="false" aria-haspopup="dialog">
                <svg class="admin-range__icon" viewBox="0 0 24 24" aria-hidden="true">
                    <rect x="3" y="4.5" width="18" height="17" rx="2.5"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/>
                </svg>
                <span class="admin-range__trigger-text">
                    <span class="admin-range__trigger-label" data-range-label><?php echo htmlspecialchars($rango['label']); ?></span>
                    <span class="admin-range__trigger-dates" data-range-dates><?php echo htmlspecialchars($resumenRango); ?></span>
                </span>
                <svg class="admin-range__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
            </button>

            <div class="admin-range__pop" data-range-pop hidden role="dialog" aria-label="Elegir periodo">
                <div class="admin-range__presets" role="group" aria-label="Periodos rápidos">
                    <?php foreach ($presets as $dias => $etiqueta) : ?>
                        <button type="button" class="admin-range__preset <?php echo $rangoPreset === $dias ? 'is-active' : ''; ?>"
                                data-range-preset="<?php echo $dias; ?>"><?php echo htmlspecialchars($etiqueta); ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="admin-range__preset <?php echo $esCustom ? 'is-active' : ''; ?>"
                            data-range-preset="custom">Personalizado</button>
                </div>

                <div class="admin-range__cal">
                    <div class="admin-range__nav">
                        <button type="button" class="admin-range__nav-btn" data-range-prev aria-label="Mes anterior">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                        </button>
                        <span class="admin-range__month-title" data-range-title-a></span>
                        <span class="admin-range__month-title admin-range__month-title--b" data-range-title-b></span>
                        <button type="button" class="admin-range__nav-btn" data-range-next aria-label="Mes siguiente">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                        </button>
                    </div>

                    <div class="admin-range__months">
                        <div class="admin-range__month">
                            <div class="admin-range__weekdays" aria-hidden="true"><span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sa</span></div>
                            <div class="admin-range__grid" data-range-grid-a></div>
                        </div>
                        <div class="admin-range__month admin-range__month--b">
                            <div class="admin-range__weekdays" aria-hidden="true"><span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sa</span></div>
                            <div class="admin-range__grid" data-range-grid-b></div>
                        </div>
                    </div>

                    <div class="admin-range__foot">
                        <span class="admin-range__summary" data-range-summary aria-live="polite"></span>
                        <div class="admin-range__foot-actions">
                            <button type="button" class="admin-btn admin-btn--ghost admin-btn--small" data-range-cancel>Cancelar</button>
                            <button type="button" class="admin-btn admin-btn--primary admin-btn--small" data-range-apply>Aplicar</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <?php
    /*
     * Tres niveles de lectura, en vez de seis cajas del mismo peso:
     *   1. titular  → ventas del periodo (tarjeta) + la gráfica diaria al lado
     *   2. soporte  → ticket promedio, propinas y comensales
     *   3. contexto → platillos y reservaciones, en una franja compacta
     */
    ?>
    <section class="admin-metrics-section" aria-label="Indicadores principales">
        <?php /* La cifra y la gráfica comparten fila: la tendencia se lee junto
                 al número del que habla, no tres pantallas más abajo. */ ?>
        <div class="admin-metrics-lead">
            <div class="admin-metrics-hero" data-admin-metrics-hero></div>
            <article class="admin-panel admin-chart-card admin-chart-card--lead">
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
        </div>
        <div class="admin-metrics-grid admin-metrics-grid--support" data-admin-metrics-primary></div>
        <div class="admin-metrics-strip" data-admin-metrics-secondary></div>
    </section>

    <div class="admin-chart-grid">
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
                        <th>Mesa</th>
                        <th>Fecha</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Propina</th>
                        <th>Pago</th>
                    </tr>
                </thead>
                <tbody data-admin-summary></tbody>
            </table>
        </div>
    </article>
</section>