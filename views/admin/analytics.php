<?php

/**
 * Vista principal de analytics del panel de administración.
 * Presenta filtros, métricas, gráficas y el resumen operativo con datos mock.
 */
?>
<section class="admin-analytics" data-admin-analytics>
    <header class="admin-page-header">
        <div class="admin-page-header__intro">
            <div class="admin-page-title-row">
                <h2>Análisis de datos</h2>
            </div>
            <p class="admin-page-summary">
                Resumen operativo <span aria-hidden="true">·</span> Últimos 7 días
            </p>
        </div>

        <div class="admin-filter-bar" aria-label="Filtros visuales no funcionales">
            <label>
                Rango
                <select disabled>
                    <option>Últimos 7 días</option>
                </select>
            </label>
            <label>
                Servicio
                <select disabled>
                    <option>Todos</option>
                </select>
            </label>
            <label>
                Fuente
                <select disabled>
                    <option>Todas</option>
                </select>
            </label>
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
                    <p>Pico de ventas el 14 de junio</p>
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
                <h3>Reservaciones por fuente</h3>
                <span>Canal</span>
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
            <span>Integración de analítica pendiente</span>
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