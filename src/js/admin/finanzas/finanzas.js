/**
 * Gráficas del módulo de finanzas: descomposición del ingreso (Sankey) y
 * reparto del gasto fijo (dona).
 *
 * Los datos llegan desde PHP como window.AdminFinanzasData (ver
 * views/admin/finanzas/index.php). Se re-renderizan al cambiar el tema, igual
 * que las de analíticas, escuchando admin:themechange.
 */
(function () {
    var charts = {};

    function readToken(name, fallback) {
        var host = document.querySelector('.admin-body') || document.body;
        var value = getComputedStyle(host).getPropertyValue(name).trim();
        return value || fallback;
    }

    /**
     * Misma paleta validada que analytics/charts.js. Las categorías de gasto
     * usan la serie categórica porque ahí la identidad ES el dato; los caudales
     * del Sankey van en un solo tono con la escala de intensidad del flujo.
     */
    function palette() {
        var dark = document.documentElement.getAttribute('data-admin-theme') === 'dark';
        return {
            dark: dark,
            muted: readToken('--admin-muted', dark ? 'rgba(237,233,223,0.58)' : '#766f65'),
            surface: readToken('--admin-surface', dark ? '#15181a' : '#fdfcfb'),
            tooltipBg: dark ? '#0b0c0d' : '#211f1b',
            serie: dark
                ? ['#17A2AD', '#E05A18', '#3A86FF', '#C93DB2', '#34A853']
                : ['#0895A2', '#CC4E12', '#2A73E8', '#B32F9E', '#2A9247'],
            ingreso: dark ? '#34A853' : '#2A9247',
            costo: dark ? '#E05A18' : '#CC4E12',
            neutro: dark ? 'rgba(237,233,223,0.30)' : 'rgba(118,111,101,0.34)'
        };
    }

    function money(n) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency', currency: 'MXN', maximumFractionDigits: 0
        }).format(n || 0);
    }

    function makeChart(id, config) {
        var canvas = document.getElementById(id);
        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }
        if (charts[id]) {
            charts[id].destroy();
        }
        charts[id] = new window.Chart(canvas, config);
    }

    /**
     * El plugin de Sankey se registra sobre el Chart global desde
     * /build/js/vendor. getController() LANZA cuando el tipo no existe, así que
     * la comprobación va en try/catch: si el vendor no cargó, el panel se queda
     * vacío en vez de tumbar el resto de las gráficas.
     */
    function haySankey() {
        try {
            return !!window.Chart.registry.getController('sankey');
        } catch (e) {
            return false;
        }
    }

    function renderSankey(data, pal) {
        var flujos = data.sankey || [];
        if (!flujos.length || !haySankey()) {
            return;
        }

        // El color del nodo dice qué es: lo que entra en verde, lo que se
        // consume en naranja, lo que sobrevive al final en dorado.
        var colores = {
            'Ingresos': pal.ingreso,
            'Costo de insumos': pal.costo,
            'Utilidad bruta': pal.ingreso,
            'Utilidad neta': readToken('--admin-gold', '#cca352')
        };
        function colorDe(nombre) {
            return colores[nombre] || pal.neutro;
        }

        makeChart('flujoFinancieroChart', {
            type: 'sankey',
            data: {
                datasets: [{
                    data: flujos,
                    colorFrom: function (ctx) { return colorDe(ctx.raw.from); },
                    colorTo: function (ctx) { return colorDe(ctx.raw.to); },
                    colorMode: 'gradient',
                    borderWidth: 0,
                    // Espacio entre nodos para que los caudales no se toquen.
                    nodeWidth: 12,
                    nodePadding: 18,
                    labels: {},
                    font: { color: pal.muted }
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: pal.tooltipBg,
                        padding: 12,
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        callbacks: {
                            label: function (ctx) {
                                return ctx.raw.from + ' → ' + ctx.raw.to + ': ' + money(ctx.raw.flow);
                            }
                        }
                    }
                }
            }
        });
    }

    function renderGastos(data, pal) {
        var gastos = data.gastos || { labels: [], values: [] };
        if (!gastos.labels.length) {
            return;
        }

        makeChart('gastosCategoriaChart', {
            type: 'doughnut',
            data: {
                labels: gastos.labels,
                datasets: [{
                    data: gastos.values,
                    backgroundColor: pal.serie.slice(0, gastos.labels.length),
                    // Anillo del color de la superficie: separa los segmentos
                    // sin introducir una línea que parezca dato.
                    borderColor: pal.surface,
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    // La lista de al lado ya nombra cada categoría con su monto;
                    // repetirla en la leyenda sólo le quita sitio a la dona.
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: pal.tooltipBg,
                        padding: 12,
                        titleColor: '#fff',
                        bodyColor: '#fff',
                        callbacks: {
                            label: function (ctx) {
                                return ctx.label + ': ' + money(ctx.parsed);
                            }
                        }
                    }
                }
            }
        });
    }

    function renderAll() {
        var data = window.AdminFinanzasData;
        if (!data || typeof window.Chart === 'undefined') {
            return;
        }
        var pal = palette();
        renderSankey(data, pal);
        renderGastos(data, pal);
    }

    function init() {
        renderAll();
        document.addEventListener('admin:themechange', renderAll);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
