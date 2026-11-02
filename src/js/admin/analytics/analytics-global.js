/**
 * Análisis de datos de Global: la venta diaria de los dos negocios en una
 * misma gráfica (views/admin/analytics-global.php).
 *
 * Viaja en el bundle de Análisis (analytics.js) junto a las gráficas de cada
 * negocio, así que sólo se monta si su raíz está en la página. Lee
 * window.AdminAnalyticsGlobal: { etiquetas, series: [{slug, nombre, serie,
 * valores}] }. La vista ya dejó fuera al negocio sin registros, así que aquí
 * no se decide nada de datos: sólo se dibuja.
 *
 * Aquí la identidad de la serie ES el dato (qué negocio vendió qué), por eso
 * cada negocio lleva un color categórico de la paleta validada
 * (--admin-chart-1 Restaurante, --admin-chart-2 Panadería: la pareja
 * adyacente de mayor separación bajo daltonismo) y además su nombre en la
 * leyenda y el tooltip. Los mismos tokens pintan las muestras de color de las
 * tarjetas, la barra de participación y la tabla.
 */
(function () {
    var grafica = null;

    // La hoja de estilos ya cargó cuando esto corre; si un token faltara, se
    // cae al color de texto calculado, nunca a un literal escrito aquí.
    function readToken(name, host) {
        var estilo = getComputedStyle(host);
        var valor = estilo.getPropertyValue(name).trim();
        return valor || estilo.color;
    }

    function dinero(valor) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency',
            currency: 'MXN',
            maximumFractionDigits: 0
        }).format(valor || 0);
    }

    function paleta() {
        var host = document.querySelector('.admin-body') || document.body;
        return {
            serie: {
                1: readToken('--admin-chart-1', host),
                2: readToken('--admin-chart-2', host)
            },
            muted: readToken('--admin-muted', host),
            grid: readToken('--admin-chart-grid', host),
            surface: readToken('--admin-surface', host),
            tooltipBg: readToken('--admin-chart-tooltip', host),
            texto: readToken('--admin-text-inverse', host)
        };
    }

    function dibujar(datos) {
        var canvas = document.getElementById('orgSalesByDayChart');
        if (!canvas || typeof window.Chart === 'undefined') {
            return;
        }
        var pal = paleta();

        // Dos líneas con el mismo grosor y trazo continuo: ninguna es «la
        // principal». No se puntea ninguna porque en el panel una línea
        // punteada significa promedio o periodo anterior; las separan los dos
        // tonos validados para daltonismo, la leyenda y el tooltip con nombre.
        var datasets = (datos.series || []).map(function (s) {
            var color = pal.serie[s.serie] || pal.muted;
            return {
                label: s.nombre,
                data: s.valores,
                borderColor: color,
                backgroundColor: color,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBorderWidth: 2,
                pointHoverBorderColor: pal.surface,
                pointHoverBackgroundColor: color,
                fill: false,
                tension: 0.3
            };
        });

        if (grafica) {
            grafica.destroy();
        }

        grafica = new window.Chart(canvas, {
            type: 'line',
            data: { labels: datos.etiquetas || [], datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                // Por columna y sin exigir intersección, como las gráficas de
                // cada negocio: basta con estar sobre el día para leer los dos.
                interaction: { mode: 'index', intersect: false, axis: 'x' },
                hover: { mode: 'index', intersect: false },
                plugins: {
                    // Con dos negocios la leyenda no es opcional: es lo que
                    // dice qué color es cuál.
                    legend: {
                        display: datasets.length > 1,
                        position: 'bottom',
                        labels: { color: pal.muted, boxWidth: 12, boxHeight: 12 }
                    },
                    tooltip: {
                        backgroundColor: pal.tooltipBg,
                        padding: 12,
                        titleColor: pal.texto,
                        bodyColor: pal.texto,
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + dinero(ctx.parsed.y);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: pal.muted } },
                    y: {
                        beginAtZero: true,
                        grid: { color: pal.grid },
                        ticks: {
                            color: pal.muted,
                            callback: function (v) { return dinero(v); }
                        }
                    }
                }
            }
        });
    }

    function init() {
        var raiz = document.querySelector('[data-admin-analytics-global]');
        var datos = window.AdminAnalyticsGlobal;
        if (!raiz || !datos) {
            return;
        }
        dibujar(datos);

        // Los colores del canvas no siguen al CSS: se repinta con la paleta
        // del tema nuevo.
        window.addEventListener('admin:themechange', function () {
            dibujar(datos);
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
