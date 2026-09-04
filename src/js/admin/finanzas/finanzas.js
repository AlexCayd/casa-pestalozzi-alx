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
     * La descomposición del ingreso. El dibujo lo hace sankey.js en SVG; aquí
     * queda lo que es de finanzas: qué color le toca a cada nodo y con qué
     * formato se escribe el dinero.
     *
     * Se cayó chartjs-chart-sankey. Dibujaba bien los caudales pero pintaba las
     * etiquetas dentro del canvas sin medirlas, así que las de la última
     * columna se cortaban contra el borde y quedaban tres barras sin rótulo.
     * Con eso se fueron también el mapa fijo de columnas y el de prioridades:
     * el módulo nuevo calcula las dos cosas.
     */
    function renderSankey(data, pal) {
        var contenedor = document.getElementById('flujoFinanciero');
        var flujos = data.sankey || [];
        if (!contenedor || !flujos.length || !window.AdminSankey) {
            return;
        }

        // El color del nodo dice qué es: lo que entra en verde, lo que se
        // consume en naranja, lo que se tira en rojo, lo que sobrevive en oro.
        // Las categorías de gasto comparten tono a propósito: en un Sankey el
        // ancho ya es el dato, y darle color a cada una haría creer que la
        // identidad importa (para eso está la dona de al lado).
        var colores = {
            'Ingresos': pal.ingreso,
            'Utilidad bruta': pal.ingreso,
            'Costo de insumos': pal.costo,
            'Merma': pal.merma,
            'Utilidad neta': pal.oro,
            // El tramo que el diagrama antes no dibujaba: cuando los gastos
            // fijos se comen la utilidad bruta, el faltante es un caudal más y
            // se pinta en el rojo de la merma.
            'Faltante': pal.merma
        };

        window.AdminSankey.render(contenedor, flujos, {
            color: function (nombre) { return colores[nombre] || pal.gasto; },
            texto: pal.texto,
            moneda: money
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

    /*
     * El Sankey no necesita Chart.js: lo dibuja sankey.js en SVG. Va antes de
     * la guarda de Chart, para que un fallo del vendor de gráficas no se lleve
     * también la descomposición del ingreso.
     */
    function renderAll() {
        var data = window.AdminFinanzasData;
        if (!data) {
            return;
        }

        var pal = palette();
        renderSankey(data, pal);

        if (typeof window.Chart !== 'undefined') {
            renderGastos(data, pal);
        }
    }

    function init() {
        renderAll();
        document.addEventListener('admin:themechange', renderAll);

        /*
         * El SVG se calcula contra el ancho real del contenedor —es lo que
         * permite medir las etiquetas—, así que al cambiar de tamaño hay que
         * rehacerlo. Con debounce: durante un arrastre de ventana se disparan
         * decenas de eventos y cada uno reconstruye el diagrama entero.
         */
        var pendiente = null;
        window.addEventListener('resize', function () {
            clearTimeout(pendiente);
            pendiente = setTimeout(renderAll, 180);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
