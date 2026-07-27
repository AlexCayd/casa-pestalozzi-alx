/**
 * Configura las gráficas Chart.js del dashboard de analytics.
 * La paleta es theme-aware: lee tokens del panel y define series claras/oscuras,
 * y re-renderiza al recibir el evento `admin:themechange`.
 */
(function () {
    var instances = {};
    var lastData = null;
    var filters = { range: 14, service: 'todos', source: 'todas' };

    // El rango ahora lo aplica el servidor (recarga por GET); aquí solo se
    // clona el dataset ya filtrado para no mutar el original.
    function applyFiltersToData(data) {
        return JSON.parse(JSON.stringify(data));
    }

    function readToken(name, fallback) {
        var host = document.querySelector('.admin-body') || document.body;
        var value = getComputedStyle(host).getPropertyValue(name).trim();
        return value || fallback;
    }

    function themePalette() {
        var dark = document.documentElement.getAttribute('data-admin-theme') === 'dark';
        var muted = readToken('--admin-muted', dark ? 'rgba(237,233,223,0.58)' : '#766f65');
        var surface = readToken('--admin-surface', dark ? '#15181a' : '#f4f1eb');

        // Paleta categórica de alto contraste (menos dorado apagado, hues más
        // distintos entre sí) para que las series se diferencien con claridad.
        if (dark) {
            return {
                green: '#6cc24a', greenDark: '#2f8f4e', gold: '#f2b134',
                terracotta: '#f2673f', extra: '#5aa9e6',
                muted: muted, surface: surface,
                grid: 'rgba(237, 233, 223, 0.12)', tooltipBg: '#0b0c0d',
                fillGreen: 'rgba(108, 194, 74, 0.20)', fillTerra: 'rgba(242, 103, 63, 0.18)'
            };
        }

        return {
            green: '#3f8f4f', greenDark: '#256b39', gold: '#c98a1f',
            terracotta: '#c1462e', extra: '#2f6db3',
            muted: muted, surface: surface,
            grid: 'rgba(118, 111, 101, 0.18)', tooltipBg: '#211f1b',
            fillGreen: 'rgba(63, 143, 79, 0.16)', fillTerra: 'rgba(193, 70, 46, 0.14)'
        };
    }

    function baseOptions(palette, extraOptions) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: { color: palette.muted, boxWidth: 12, boxHeight: 12 }
                },
                tooltip: {
                    backgroundColor: palette.tooltipBg,
                    padding: 12,
                    titleColor: '#fff',
                    bodyColor: '#fff'
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: palette.muted }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: palette.grid },
                    ticks: { color: palette.muted }
                }
            }
        }, extraOptions || {});
    }

    function createChart(id, config) {
        var canvas = document.getElementById(id);

        if (!canvas || typeof window.Chart === 'undefined') {
            return null;
        }

        if (instances[id]) {
            instances[id].destroy();
        }

        instances[id] = new window.Chart(canvas, config);
        return instances[id];
    }

    function renderCharts(rawData) {
        var data = applyFiltersToData(rawData);
        var palette = themePalette();

        createChart('salesByDayChart', {
            type: 'line',
            data: {
                labels: data.salesByDay.labels,
                datasets: [{
                    label: 'Ventas',
                    data: data.salesByDay.values,
                    borderColor: palette.green,
                    backgroundColor: palette.fillGreen,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: baseOptions(palette)
        });

        createChart('salesByCategoryChart', {
            type: 'bar',
            data: {
                labels: data.salesByCategory.labels,
                datasets: [{
                    label: 'Ventas',
                    data: data.salesByCategory.values,
                    backgroundColor: [palette.green, palette.gold, palette.terracotta, palette.greenDark, palette.extra],
                    borderRadius: 6
                }]
            },
            options: baseOptions(palette)
        });

        createChart('paymentMethodsChart', {
            type: 'doughnut',
            data: {
                labels: data.paymentMethods.labels,
                datasets: [{
                    data: data.paymentMethods.values,
                    backgroundColor: [palette.green, palette.gold, palette.terracotta],
                    borderColor: palette.surface,
                    borderWidth: 4
                }]
            },
            options: baseOptions(palette, { cutout: '68%', scales: {} })
        });

        createChart('topProductsChart', {
            type: 'bar',
            data: {
                labels: data.topProducts.labels,
                datasets: [{
                    label: 'Unidades',
                    data: data.topProducts.values,
                    backgroundColor: palette.gold,
                    borderRadius: 6
                }]
            },
            options: baseOptions(palette, { indexAxis: 'y' })
        });

        createChart('reservationsByDayChart', {
            type: 'line',
            data: {
                labels: data.reservationsByDay.labels,
                datasets: [{
                    label: 'Reservaciones',
                    data: data.reservationsByDay.values,
                    borderColor: palette.terracotta,
                    backgroundColor: palette.fillTerra,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: baseOptions(palette)
        });

        createChart('reservationSourcesChart', {
            type: 'doughnut',
            data: {
                labels: data.reservationSources.labels,
                datasets: [{
                    data: data.reservationSources.values,
                    backgroundColor: [palette.green, palette.gold, palette.terracotta, palette.greenDark],
                    borderColor: palette.surface,
                    borderWidth: 4
                }]
            },
            options: baseOptions(palette, { cutout: '62%', scales: {} })
        });
    }

    function initAnalyticsCharts(data) {
        lastData = data;
        renderCharts(data);
    }

    function applyFilters(state) {
        filters = Object.assign({}, filters, state);
        if (lastData) {
            renderCharts(lastData);
        }
    }

    // Re-renderiza con la nueva paleta al cambiar el tema.
    window.addEventListener('admin:themechange', function () {
        if (lastData) {
            renderCharts(lastData);
        }
    });

    window.AdminAnalyticsCharts = {
        init: initAnalyticsCharts,
        applyFilters: applyFilters
    };
})();
