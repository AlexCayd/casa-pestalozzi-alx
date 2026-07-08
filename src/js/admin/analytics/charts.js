/**
 * Configura las gráficas Chart.js del dashboard de analytics.
 * La paleta es theme-aware: lee tokens del panel y define series claras/oscuras,
 * y re-renderiza al recibir el evento `admin:themechange`.
 */
(function () {
    var instances = {};
    var lastData = null;
    var filters = { range: 7, service: 'todos', source: 'todas' };

    // Aplica los filtros del panel a los datos antes de graficar.
    // El rango recorta genuinamente las series temporales a los últimos N días.
    function applyFiltersToData(data) {
        var clone = JSON.parse(JSON.stringify(data));
        var n = filters.range;

        ['salesByDay', 'reservationsByDay'].forEach(function (key) {
            if (clone[key] && Array.isArray(clone[key].labels)) {
                clone[key].labels = clone[key].labels.slice(-n);
                clone[key].values = clone[key].values.slice(-n);
            }
        });

        return clone;
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

        if (dark) {
            return {
                green: '#8bbf7e', greenDark: '#5f7d56', gold: '#e0c184',
                terracotta: '#e87060', extra: '#9dc0e0',
                muted: muted, surface: surface,
                grid: 'rgba(237, 233, 223, 0.12)', tooltipBg: '#0b0c0d',
                fillGreen: 'rgba(139, 191, 126, 0.18)', fillTerra: 'rgba(232, 112, 96, 0.16)'
            };
        }

        return {
            green: '#476f58', greenDark: '#2f4d3d', gold: '#b9863f',
            terracotta: '#b95043', extra: '#8b7d68',
            muted: muted, surface: surface,
            grid: 'rgba(118, 111, 101, 0.18)', tooltipBg: '#211f1b',
            fillGreen: 'rgba(71, 111, 88, 0.14)', fillTerra: 'rgba(185, 80, 67, 0.12)'
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
