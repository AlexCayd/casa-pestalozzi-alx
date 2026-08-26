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

    // Sin decimales: en un tooltip de ventas diarias los centavos no cambian
    // ninguna decisión y alargan la caja.
    function dinero(valor) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN',
            maximumFractionDigits: 0
        }).format(valor || 0);
    }

    /*
     * Paleta de acento de la marca (ver CLAUDE.md), en pasos ajustados a la
     * superficie de cada tema y verificados con el validador de la skill
     * `dataviz`: banda de luminosidad, piso de croma, separación bajo
     * daltonismo, piso de visión normal y contraste.
     *
     * El orden NO es arbitrario: alterna cálidos y fríos para que ninguna
     * pareja adyacente colapse bajo deuteranopia. Turquesa y magenta juntos
     * caían a ΔE 4.8, y verde y naranja juntos a 5.7. Al reordenar, la peor
     * pareja adyacente queda en 13.5. Si se cambia un tono o el orden, hay que
     * volver a correr el validador.
     *
     * `serie` (categórica) solo se usa donde la identidad de la serie ES el
     * dato: las dos donas. Las gráficas de magnitud van a un solo tono.
     */
    function themePalette() {
        var dark = document.documentElement.getAttribute('data-admin-theme') === 'dark';

        // Los tonos ya no viven aquí: son los tokens --admin-chart-* de
        // `_globals.scss`, que cada tema redeclara. Este archivo era la cuarta
        // copia de la paleta y se desincronizaba en cada cambio de marca. Los
        // respaldos se conservan por si la hoja aún no ha pintado.
        var serie = [
            readToken('--admin-chart-1', dark ? '#17A2AD' : '#0895A2'),
            readToken('--admin-chart-2', dark ? '#E05A18' : '#CC4E12'),
            readToken('--admin-chart-3', dark ? '#3A86FF' : '#2A73E8'),
            readToken('--admin-chart-4', dark ? '#C93DB2' : '#B32F9E'),
            readToken('--admin-chart-5', dark ? '#34A853' : '#2A9247')
        ];
        var ventas = readToken('--admin-chart-ventas', dark ? '#34A853' : '#2A9247');
        var reservas = readToken('--admin-chart-reservas', dark ? '#E05A18' : '#CC4E12');
        var bajoPromedio = readToken('--admin-chart-bajo', dark ? '#E51022' : '#C40E1D');

        return {
            serie: serie,
            ventas: ventas,
            reservas: reservas,
            // Polo bajo del divergente respecto al promedio.
            bajoPromedio: bajoPromedio,
            fillBajoPromedio: conAlfa(bajoPromedio, dark ? 0.18 : 0.14),
            fillVentas: conAlfa(ventas, dark ? 0.20 : 0.16),
            fillReservas: conAlfa(reservas, dark ? 0.18 : 0.14),
            muted: readToken('--admin-muted', dark ? 'rgba(237,233,223,0.58)' : '#766f65'),
            surface: readToken('--admin-surface', dark ? '#15181a' : '#fdfcfb'),
            grid: readToken('--admin-chart-grid', dark ? 'rgba(237,233,223,0.12)' : 'rgba(118,111,101,0.18)'),
            tooltipBg: readToken('--admin-chart-tooltip', dark ? '#0b0c0d' : '#211f1b'),
            // El tooltip va sobre un fondo oscuro en los dos temas, así que su
            // texto es siempre el rol inverso.
            texto: readToken('--admin-text-inverse', '#ede9df')
        };
    }

    /*
     * Chart.js necesita un color con alfa y no entiende color-mix(), así que
     * los rellenos se arman aquí a partir del tono sólido del token.
     */
    function conAlfa(color, alfa) {
        var hex = String(color).trim();
        if (hex.charAt(0) !== '#') return hex;

        if (hex.length === 4) {
            hex = '#' + hex[1] + hex[1] + hex[2] + hex[2] + hex[3] + hex[3];
        }
        if (hex.length !== 7) return hex;

        var n = parseInt(hex.slice(1), 16);
        return 'rgba(' + ((n >> 16) & 255) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ', ' + alfa + ')';
    }

    // Una sola serie no necesita caja de leyenda: el título de la tarjeta ya la
    // nombra. La leyenda solo aporta cuando hay que distinguir varias.
    function baseOptions(palette, extraOptions) {
        return Object.assign({
            responsive: true,
            maintainAspectRatio: false,
            /*
             * Sin esto Chart.js usa su modo 'nearest' con intersect: true, y
             * como todas las series de línea van con pointRadius: 0 el área
             * sensible de cada dato queda en el hitRadius por omisión (1px):
             * había que clavar el cursor en el píxel exacto para ver el tooltip.
             * Por columna y sin exigir intersección, basta con estar sobre el
             * día.
             */
            interaction: { mode: 'index', intersect: false, axis: 'x' },
            hover: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: false,
                    labels: { color: palette.muted, boxWidth: 12, boxHeight: 12 }
                },
                tooltip: {
                    backgroundColor: palette.tooltipBg,
                    padding: 12,
                    titleColor: palette.texto,
                    bodyColor: palette.texto
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

        /*
         * Ventas por día contra el promedio del periodo.
         *
         * Deja de ser una serie de magnitud y pasa a ser divergente: lo que se
         * lee es si el día quedó por encima o por debajo de la media. Por eso
         * hay dos polos (verde / rojo) y un punto neutro (la línea punteada).
         *
         * El par verde–rojo separa ΔE 6.5 bajo deuteranopia, dentro de la banda
         * que solo es admisible con codificación secundaria. La aporta la
         * propia línea de promedio: estar arriba o abajo de ella es una lectura
         * de posición que no depende del color, y el tooltip dice la cifra.
         */
        var ventasDia = data.salesByDay.values || [];
        var promedioVentas = ventasDia.length
            ? ventasDia.reduce(function (total, valor) { return total + valor; }, 0) / ventasDia.length
            : 0;

        function tonoPorPromedio(valor) {
            return valor >= promedioVentas ? palette.ventas : palette.bajoPromedio;
        }

        // Periodo anterior: misma magnitud en otro tramo, así que no estrena
        // color categórico. Se distingue por el trazo punteado y la leyenda,
        // que es la codificación secundaria que exige el sistema.
        var seriesVentas = [];
        if (data.salesByDay.compare && data.salesByDay.compare.values) {
            seriesVentas.push({
                label: data.salesByDay.compare.label || 'Periodo anterior',
                data: data.salesByDay.compare.values,
                borderColor: palette.grid,
                backgroundColor: palette.grid,
                borderWidth: 2,
                borderDash: [5, 4],
                pointRadius: 0,
                pointHoverRadius: 4,
                fill: false,
                tension: 0.35
            });
        }

        createChart('salesByDayChart', {
            type: 'line',
            data: {
                labels: data.salesByDay.labels,
                datasets: seriesVentas.concat([
                    {
                        label: 'Promedio del periodo',
                        data: ventasDia.map(function () { return promedioVentas; }),
                        borderColor: palette.muted,
                        borderWidth: 1.5,
                        borderDash: [6, 4],
                        pointRadius: 0,
                        pointHoverRadius: 0,
                        fill: false,
                        tension: 0
                    },
                    {
                        label: 'Ventas',
                        data: ventasDia,
                        borderColor: palette.ventas,
                        borderWidth: 2,
                        // Cada tramo toma el color del extremo más bajo: un
                        // tramo que cruza la media se pinta en rojo, que es la
                        // lectura prudente.
                        segment: {
                            borderColor: function (ctx) {
                                var desde = ctx.p0.parsed.y;
                                var hasta = ctx.p1.parsed.y;
                                return tonoPorPromedio(Math.min(desde, hasta));
                            }
                        },
                        pointRadius: 0,
                        pointHoverRadius: 5,
                        pointHoverBorderWidth: 2,
                        pointHoverBorderColor: palette.surface,
                        pointHoverBackgroundColor: function (ctx) {
                            return tonoPorPromedio(ctx.parsed ? ctx.parsed.y : 0);
                        },
                        fill: {
                            target: { value: promedioVentas },
                            above: palette.fillVentas,
                            below: palette.fillBajoPromedio
                        },
                        tension: 0.35
                    }
                ])
            },
            // Dos series: la leyenda deja de ser opcional. Es lo que nombra la
            // línea punteada, sin la cual el color no significa nada.
            options: baseOptions(palette, {
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            color: palette.muted,
                            boxWidth: 12,
                            boxHeight: 12,
                            usePointStyle: false
                        }
                    },
                    tooltip: {
                        backgroundColor: palette.tooltipBg,
                        padding: 12,
                        titleColor: palette.texto,
                        bodyColor: palette.texto,
                        // El promedio es el mismo número en los 30 días: como
                        // renglón del tooltip solo repite ruido, y la línea
                        // punteada ya lo dice mejor.
                        filter: function (item) {
                            return item.dataset.label !== 'Promedio del periodo';
                        },
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + dinero(ctx.parsed.y);
                            },
                            afterBody: function (items) {
                                if (!items.length || !promedioVentas) {
                                    return '';
                                }
                                var venta = items[0].parsed.y;
                                var signo = venta >= promedioVentas ? '+' : '−';
                                var diferencia = Math.abs(venta - promedioVentas);
                                return 'Promedio del periodo: ' + dinero(promedioVentas)
                                    + ' (' + signo + dinero(diferencia) + ')';
                            }
                        }
                    }
                }
            })
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
                    tooltip: { backgroundColor: palette.tooltipBg, padding: 12, titleColor: palette.texto, bodyColor: palette.texto }
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
