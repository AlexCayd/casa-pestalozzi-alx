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

        // Los tonos salen de los tokens --admin-chart-* de _globals.scss, los
        // mismos que lee analytics/charts.js. Antes este archivo guardaba su
        // propia copia de la serie y había que acordarse de tocar las dos.
        return {
            dark: dark,
            texto: readToken('--admin-text', dark ? '#ede9df' : '#211f1b'),
            muted: readToken('--admin-muted', dark ? 'rgba(237,233,223,0.58)' : '#766f65'),
            surface: readToken('--admin-surface', dark ? '#15181a' : '#fdfcfb'),
            tooltipBg: readToken('--admin-chart-tooltip', dark ? '#0b0c0d' : '#211f1b'),
            serie: [
                readToken('--admin-chart-1', dark ? '#17A2AD' : '#0895A2'),
                readToken('--admin-chart-2', dark ? '#E05A18' : '#CC4E12'),
                readToken('--admin-chart-3', dark ? '#3A86FF' : '#2A73E8'),
                readToken('--admin-chart-4', dark ? '#C93DB2' : '#B32F9E'),
                readToken('--admin-chart-5', dark ? '#34A853' : '#2A9247')
            ],
            ingreso: readToken('--admin-chart-ventas', dark ? '#34A853' : '#2A9247'),
            costo: readToken('--admin-chart-reservas', dark ? '#E05A18' : '#CC4E12'),
            merma: readToken('--admin-chart-bajo', dark ? '#E51022' : '#C40E1D'),
            oro: readToken('--admin-gold', '#cca352'),
            // Los gastos fijos van todos del mismo tono: en un Sankey el ancho
            // ya es el dato, y darle a cada categoría su color haría creer que
            // la identidad importa (para eso está la dona de al lado).
            gasto: readToken('--admin-faint', dark ? '#8d8577' : '#9a9084')
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

    /**
     * Nodos con nombre propio. El resto —las categorías de gasto fijo, que
     * dependen de lo que el usuario haya dado de alta— comparten tono.
     */
    var NODOS_FIJOS = ['Ingresos', 'Utilidad bruta', 'Costo de insumos', 'Merma', 'Utilidad neta'];

    /** Cuánto vale cada nodo: lo que entra, o lo que sale si es el origen. */
    function totalesPorNodo(flujos) {
        var entra = {};
        var sale = {};
        flujos.forEach(function (f) {
            entra[f.to] = (entra[f.to] || 0) + f.flow;
            sale[f.from] = (sale[f.from] || 0) + f.flow;
        });

        var totales = {};
        Object.keys(entra).concat(Object.keys(sale)).forEach(function (nombre) {
            totales[nombre] = Math.max(entra[nombre] || 0, sale[nombre] || 0);
        });
        return totales;
    }

    function renderSankey(data, pal) {
        var flujos = data.sankey || [];
        if (!flujos.length || !haySankey()) {
            return;
        }

        // El color del nodo dice qué es: lo que entra en verde, lo que se
        // consume en naranja, lo que se tira en rojo, lo que sobrevive en oro.
        var colores = {
            'Ingresos': pal.ingreso,
            'Utilidad bruta': pal.ingreso,
            'Costo de insumos': pal.costo,
            'Merma': pal.merma,
            'Utilidad neta': pal.oro
        };
        function colorDe(nombre) {
            return colores[nombre] || pal.gasto;
        }

        var totales = totalesPorNodo(flujos);

        /*
         * Costo de insumos y merma salen de Ingresos igual que la utilidad
         * bruta, así que les toca la columna intermedia. Sin fijarla, el plugin
         * los manda a la última —son hojas— y el diagrama se lee como si el
         * costo compitiera con la nómina.
         */
        var columnas = { 'Costo de insumos': 1, 'Merma': 1 };

        /*
         * Orden vertical dentro de cada columna: primero lo que sobrevive,
         * luego lo que se consume y, al final, los gastos de mayor a menor.
         * Sin esto los caudales se cruzan y el diagrama parece un nudo.
         */
        var prioridad = { 'Ingresos': 0, 'Utilidad bruta': 0, 'Costo de insumos': 1, 'Merma': 2, 'Utilidad neta': 0 };
        Object.keys(totales)
            .filter(function (nombre) { return NODOS_FIJOS.indexOf(nombre) === -1; })
            .sort(function (a, b) { return totales[b] - totales[a]; })
            .forEach(function (nombre, indice) { prioridad[nombre] = indice + 1; });

        // El monto va en la etiqueta: el grosor ordena, pero no dice cuánto.
        var etiquetas = {};
        Object.keys(totales).forEach(function (nombre) {
            etiquetas[nombre] = nombre + ' · ' + money(totales[nombre]);
        });

        var ingresoTotal = totales['Ingresos'] || 0;

        makeChart('flujoFinancieroChart', {
            type: 'sankey',
            data: {
                datasets: [{
                    data: flujos,
                    colorFrom: function (ctx) { return colorDe(ctx.raw.from); },
                    colorTo: function (ctx) { return colorDe(ctx.raw.to); },
                    colorMode: 'gradient',
                    // Por encima del 0.5 de fábrica: sobre el fondo oscuro del
                    // panel los caudales se leían como una mancha gris.
                    alpha: 0.62,
                    borderWidth: 0,
                    // Espacio entre nodos para que los caudales no se toquen.
                    nodeWidth: 14,
                    nodePadding: 22,
                    column: columnas,
                    priority: prioridad,
                    labels: etiquetas,
                    /*
                     * El color de la etiqueta se resuelve desde `nodeLabels`, no
                     * desde `font`: puesto en `font` el plugin lo ignora y cae a
                     * su negro por omisión, que en tema oscuro desaparecía
                     * sobre los propios caudales.
                     */
                    nodeLabels: {
                        color: pal.texto,
                        font: { size: 12, weight: 700 },
                        padding: 8
                    }
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // Sin holgura lateral, las etiquetas de la última columna se
                // cortan contra el borde del canvas.
                layout: { padding: { top: 6, right: 18, bottom: 6, left: 12 } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: pal.tooltipBg,
                        padding: 12,
                        titleColor: pal.texto,
                        bodyColor: pal.texto,
                        callbacks: {
                            label: function (ctx) {
                                var texto = ctx.raw.from + ' → ' + ctx.raw.to + ': ' + money(ctx.raw.flow);
                                if (ingresoTotal > 0) {
                                    texto += ' (' + ((ctx.raw.flow / ingresoTotal) * 100).toFixed(1) + '% del ingreso)';
                                }
                                return texto;
                            }
                        }
                    }
                }
            }
        });
    }

    /**
     * Total en el hueco del anillo. Ese espacio ya estaba vacío y la cifra que
     * la dona reparte no aparecía en ninguna parte del panel.
     */
    var totalEnElCentro = {
        id: 'totalEnElCentro',
        afterDraw: function (chart, args, opciones) {
            if (chart.config.type !== 'doughnut' || !opciones || !opciones.texto) {
                return;
            }

            var meta = chart.getDatasetMeta(0);
            if (!meta || !meta.data.length) {
                return;
            }

            var ctx = chart.ctx;
            var centro = meta.data[0];
            var base = Math.min(chart.width, chart.height);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';

            ctx.fillStyle = opciones.colorRotulo;
            ctx.font = '800 ' + Math.max(9, Math.round(base * 0.042)) + 'px system-ui, sans-serif';
            ctx.fillText(opciones.rotulo, centro.x, centro.y - base * 0.055);

            ctx.fillStyle = opciones.colorTexto;
            ctx.font = '600 ' + Math.max(16, Math.round(base * 0.1)) + 'px Fraunces, Georgia, serif';
            ctx.fillText(opciones.texto, centro.x, centro.y + base * 0.025);
            ctx.restore();
        }
    };

    function renderGastos(data, pal) {
        var gastos = data.gastos || { labels: [], values: [] };
        if (!gastos.labels.length) {
            return;
        }

        var total = gastos.values.reduce(function (suma, valor) { return suma + valor; }, 0);

        makeChart('gastosCategoriaChart', {
            type: 'doughnut',
            plugins: [totalEnElCentro],
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
                // Con la dona ya a tamaño grande, un hueco del 62% dejaba un
                // anillo delgado: se cierra hasta dejar sitio justo al total.
                cutout: '55%',
                plugins: {
                    totalEnElCentro: {
                        rotulo: 'TOTAL DEL PERIODO',
                        texto: money(total),
                        colorRotulo: pal.muted,
                        colorTexto: pal.texto
                    },
                    // La lista de abajo ya nombra cada categoría con su monto;
                    // repetirla en la leyenda sólo le quita sitio a la dona.
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: pal.tooltipBg,
                        padding: 12,
                        titleColor: pal.texto,
                        bodyColor: pal.texto,
                        callbacks: {
                            label: function (ctx) {
                                var pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) + '%' : '';
                                return ctx.label + ': ' + money(ctx.parsed) + (pct ? ' (' + pct + ')' : '');
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
