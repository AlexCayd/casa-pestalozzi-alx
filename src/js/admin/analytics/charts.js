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

    /*
     * Paletas verificadas con el validador de la skill `dataviz` contra la
     * superficie de cada tema (banda de luminosidad, piso de croma, separación
     * bajo daltonismo, contraste). La anterior fallaba: en oscuro el verde
     * #6cc24a y el dorado #f2b134 quedaban a ΔE 1.7 bajo protanopia, es decir,
     * indistinguibles para un daltónico rojo-verde.
     *
     * `serie` (categórica) solo se usa donde la identidad de la serie ES el
     * dato: las dos donas. Las gráficas de magnitud van a un solo tono.
     */
    function themePalette() {
        var dark = document.documentElement.getAttribute('data-admin-theme') === 'dark';
        var muted = readToken('--admin-muted', dark ? 'rgba(237,233,223,0.58)' : '#766f65');
        var surface = readToken('--admin-surface', dark ? '#15181a' : '#fdfcfb');

        if (dark) {
            return {
                // ΔE mín. adyacente: 10.1 (protan) · 19.4 (visión normal)
                serie: ['#4a8fd0', '#d4552e', '#0f8f70', '#bf8a20', '#9b62c8'],
                ventas: '#0f8f70',
                reservas: '#d4552e',
                muted: muted, surface: surface,
                grid: 'rgba(237, 233, 223, 0.12)', tooltipBg: '#0b0c0d',
                fillVentas: 'rgba(15, 143, 112, 0.20)',
                fillReservas: 'rgba(212, 85, 46, 0.18)'
            };
        }

        return {
            // ΔE mín. adyacente: 8.9 (protan) · 18.4 (visión normal)
            serie: ['#3480c9', '#c94a30', '#0f8266', '#b07d1c', '#8c57b8'],
            ventas: '#0f8266',
            reservas: '#c94a30',
            muted: muted, surface: surface,
            grid: 'rgba(118, 111, 101, 0.18)', tooltipBg: '#211f1b',
            fillVentas: 'rgba(15, 130, 102, 0.16)',
            fillReservas: 'rgba(201, 74, 48, 0.14)'
        };
    }

    // Una sola serie no necesita caja de leyenda: el título de la tarjeta ya la
    // nombra. La leyenda solo aporta cuando hay que distinguir varias.
    function baseOptions(palette, extraOptions) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false,
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

        // Tendencia de una sola serie: línea fina de 2px, marcador visible al
        // pasar el cursor y relleno tenue del mismo tono.
        createChart('salesByDayChart', {
            type: 'line',
            data: {
                labels: data.salesByDay.labels,
                datasets: [{
                    label: 'Ventas',
                    data: data.salesByDay.values,
                    borderColor: palette.ventas,
                    backgroundColor: palette.fillVentas,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBorderWidth: 2,
                    pointHoverBorderColor: palette.surface,
                    pointHoverBackgroundColor: palette.ventas,
                    fill: true,
                    tension: 0.35
                }]
            },
            options: baseOptions(palette)
        });

        // Magnitud, no identidad: un solo tono. Pintar cada barra de un color
        // distinto sugiere categorías sin relación y entierra la comparación.
        createChart('salesByCategoryChart', {
            type: 'bar',
            data: {
                labels: data.salesByCategory.labels,
                datasets: [{
                    label: 'Ventas',
                    data: data.salesByCategory.values,
                    backgroundColor: palette.ventas,
                    // Solo se redondea el extremo del dato; la base queda anclada.
                    borderRadius: { topLeft: 4, topRight: 4 },
                    borderSkipped: 'bottom'
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
                    backgroundColor: palette.serie.slice(0, 3),
                    borderColor: palette.surface,
                    borderWidth: 2
                }]
            },
            options: baseOptions(palette, {
                cutout: '68%',
                scales: {},
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { color: palette.muted, boxWidth: 12, boxHeight: 12 } },
                    tooltip: { backgroundColor: palette.tooltipBg, padding: 12, titleColor: '#fff', bodyColor: '#fff' }
                }
            })
        });

        createChart('topProductsChart', {
            type: 'bar',
            data: {
                labels: data.topProducts.labels,
                datasets: [{
                    label: 'Unidades',
                    data: data.topProducts.values,
                    backgroundColor: palette.ventas,
                    borderRadius: { topRight: 4, bottomRight: 4 },
                    borderSkipped: 'left'
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
                    borderColor: palette.reservas,
                    backgroundColor: palette.fillReservas,
                    borderWidth: 2,
                    pointRadius: 0,
                    pointHoverRadius: 5,
                    pointHoverBorderWidth: 2,
                    pointHoverBorderColor: palette.surface,
                    pointHoverBackgroundColor: palette.reservas,
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
                    backgroundColor: palette.serie.slice(0, 4),
                    borderColor: palette.surface,
                    borderWidth: 2
                }]
            },
            options: baseOptions(palette, {
                cutout: '62%',
                scales: {},
                plugins: {
                    legend: { display: true, position: 'bottom', labels: { color: palette.muted, boxWidth: 12, boxHeight: 12 } },
                    tooltip: { backgroundColor: palette.tooltipBg, padding: 12, titleColor: '#fff', bodyColor: '#fff' }
                }
            })
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
