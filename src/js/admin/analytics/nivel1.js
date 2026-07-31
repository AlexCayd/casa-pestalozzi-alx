/**
 * Analíticas diagnósticas de Nivel 1 (ANALITICAS.md §3). Renderiza las cuatro
 * secciones a partir de window.AdminAnalyticsMock.nivel1 (Services\Analiticas):
 *   §3.1 Ingeniería de menú  — matriz Kasavana-Smith (scatter + tabla).
 *   §3.2 RevPASH             — ingreso por asiento disponible por hora (barras).
 *   §3.3 Varianza inventario — ranking de merma en pesos (tabla).
 *   §3.4 Reglas de asociación — pares por lift (tabla).
 * Es independiente de charts.js/analytics-page.js: se autoinicia y vuelve a
 * dibujar sus gráficas al cambiar el tema (evento admin:themechange).
 */
(function () {
    var charts = {};
    var data = null;

    // Clases de la matriz: color, etiqueta y emoji.
    var CLASES = {
        estrella:  { label: 'Estrella',  emoji: '⭐' },
        vaca:      { label: 'Vaca',      emoji: '🐎' },
        incognita: { label: 'Incógnita', emoji: '❓' },
        perro:     { label: 'Perro',     emoji: '🐕' }
    };

    function money(n) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency', currency: 'MXN', maximumFractionDigits: 0
        }).format(n || 0);
    }

    function money2(n) {
        return new Intl.NumberFormat('es-MX', {
            style: 'currency', currency: 'MXN', minimumFractionDigits: 2, maximumFractionDigits: 2
        }).format(n || 0);
    }

    function readToken(name, fallback) {
        var host = document.querySelector('.admin-body') || document.body;
        var value = getComputedStyle(host).getPropertyValue(name).trim();
        return value || fallback;
    }

    function palette() {
        var dark = document.documentElement.getAttribute('data-admin-theme') === 'dark';
        var muted = readToken('--admin-muted', dark ? 'rgba(237,233,223,0.58)' : '#766f65');
        return {
            dark: dark,
            muted: muted,
            grid: dark ? 'rgba(237,233,223,0.12)' : 'rgba(118,111,101,0.18)',
            tooltipBg: dark ? '#0b0c0d' : '#211f1b',
            estrella: dark ? '#6cc24a' : '#3f8f4f',
            vaca: dark ? '#f2b134' : '#c98a1f',
            incognita: dark ? '#5aa9e6' : '#2f6db3',
            perro: dark ? '#f2673f' : '#c1462e'
        };
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

    function setText(selector, text) {
        var el = document.querySelector(selector);
        if (el) {
            el.textContent = text;
        }
    }

    function emptyRow(selector, cols, msg) {
        var el = document.querySelector(selector);
        if (el) {
            el.innerHTML = '<tr><td colspan="' + cols + '" class="admin-nivel1-empty">' + msg + '</td></tr>';
        }
    }

    // ── §3.1 Ingeniería de menú ──────────────────────────────────────────
    function renderMenu(pal) {
        var menu = data.ingenieria || {};
        var items = menu.items || [];
        var resumen = menu.resumen || {};

        var summaryEl = document.querySelector('[data-n1-menu-summary]');
        if (summaryEl) {
            summaryEl.innerHTML = Object.keys(CLASES).map(function (k) {
                return '<span class="admin-nivel1-chip admin-nivel1-chip--' + k + '">' +
                    CLASES[k].emoji + ' ' + CLASES[k].label +
                    '<strong>' + (resumen[k] || 0) + '</strong></span>';
            }).join('');
        }

        if (!items.length) {
            emptyRow('[data-n1-menu-table]', 5, 'Sin ventas en el periodo para clasificar el menú.');
            makeChart('menuEngineeringChart', { type: 'scatter', data: { datasets: [] }, options: { responsive: true, maintainAspectRatio: false } });
            return;
        }

        var tbody = document.querySelector('[data-n1-menu-table]');
        if (tbody) {
            tbody.innerHTML = items.map(function (it) {
                return '<tr>' +
                    '<td>' + it.nombre + '</td>' +
                    '<td><span class="admin-nivel1-badge admin-nivel1-badge--' + it.clase + '">' +
                        CLASES[it.clase].emoji + ' ' + it.claseLabel + '</span></td>' +
                    '<td>' + it.unidades + '</td>' +
                    '<td>' + money2(it.margen) + ' <small>(' + it.margenPct + '%)</small></td>' +
                    '<td>' + it.categoria + '</td>' +
                    '</tr>';
            }).join('');
        }

        // Scatter: un dataset por clase (popularidad % en X, margen $ en Y).
        var scatter = menu.scatter || [];
        var byClass = { estrella: [], vaca: [], incognita: [], perro: [] };
        scatter.forEach(function (p) {
            (byClass[p.clase] || byClass.perro).push({ x: p.x, y: p.y, label: p.label });
        });
        var datasets = Object.keys(CLASES).map(function (k) {
            return {
                label: CLASES[k].emoji + ' ' + CLASES[k].label,
                data: byClass[k],
                backgroundColor: pal[k],
                pointRadius: 5,
                pointHoverRadius: 7
            };
        });

        // Línea de corte de margen (average CM de Kasavana-Smith).
        var corte = (menu.cortes && menu.cortes.margen) || 0;
        var maxX = 0;
        scatter.forEach(function (p) { if (p.x > maxX) { maxX = p.x; } });
        datasets.push({
            label: 'Corte de margen',
            type: 'line',
            data: [{ x: 0, y: corte }, { x: Math.ceil(maxX) + 1, y: corte }],
            borderColor: pal.muted,
            borderDash: [6, 6],
            borderWidth: 1,
            pointRadius: 0,
            fill: false
        });

        makeChart('menuEngineeringChart', {
            type: 'scatter',
            data: { datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { labels: { color: pal.muted, boxWidth: 12, boxHeight: 12 } },
                    tooltip: {
                        backgroundColor: pal.tooltipBg, padding: 12, titleColor: '#fff', bodyColor: '#fff',
                        callbacks: {
                            label: function (ctx) {
                                var p = ctx.raw;
                                if (p.label === undefined) { return null; }
                                return p.label + ' · pop. ' + p.x + '% · margen ' + money(p.y);
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        title: { display: true, text: 'Popularidad (% de unidades)', color: pal.muted },
                        grid: { color: pal.grid }, ticks: { color: pal.muted }, beginAtZero: true
                    },
                    y: {
                        title: { display: true, text: 'Margen de contribución ($)', color: pal.muted },
                        grid: { color: pal.grid }, ticks: { color: pal.muted }, beginAtZero: true
                    }
                }
            }
        });
    }

    // ── §3.2 RevPASH ─────────────────────────────────────────────────────
    function renderRevpash(pal) {
        var rp = data.revpash || {};
        var labels = rp.labels || [];

        setText('[data-n1-revpash-seats]', (rp.asientos || 0) + ' asientos');

        var noteEl = document.querySelector('[data-n1-revpash-note]');
        if (noteEl) {
            if (rp.mejor && rp.peor) {
                noteEl.innerHTML = 'Mejor franja: <strong>' + rp.mejor.hora + '</strong> (' + money2(rp.mejor.valor) +
                    '/asiento) · Más floja: <strong>' + rp.peor.hora + '</strong> (' + money2(rp.peor.valor) + '/asiento)';
            } else {
                noteEl.textContent = '';
            }
        }

        if (!labels.length) {
            makeChart('revpashChart', { type: 'bar', data: { labels: [], datasets: [] }, options: { responsive: true, maintainAspectRatio: false } });
            return;
        }

        var best = rp.mejor ? rp.mejor.valor : null;
        var colors = (rp.values || []).map(function (v) {
            return best !== null && v === best ? pal.estrella : pal.incognita;
        });

        makeChart('revpashChart', {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'RevPASH',
                    data: rp.values || [],
                    backgroundColor: colors,
                    borderRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: pal.tooltipBg, padding: 12, titleColor: '#fff', bodyColor: '#fff',
                        callbacks: {
                            label: function (ctx) {
                                var ing = (rp.ingresos && rp.ingresos[ctx.dataIndex]) || 0;
                                return money2(ctx.parsed.y) + ' / asiento · ingreso ' + money(ing);
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: pal.muted } },
                    y: { beginAtZero: true, grid: { color: pal.grid }, ticks: { color: pal.muted } }
                }
            }
        });
    }

    // ── §3.3 Varianza de inventario ──────────────────────────────────────
    function renderVarianza() {
        var v = data.varianza || {};
        var items = (v.items || []).filter(function (it) { return it.mermaValor > 0 || it.teoricoValor > 0; });

        setText('[data-n1-varianza-total]', 'Merma total: ' + money2(v.totalMerma || 0));

        if (!items.length) {
            emptyRow('[data-n1-varianza-table]', 3, 'Sin consumo ni ajustes de inventario en el periodo.');
            return;
        }

        var tbody = document.querySelector('[data-n1-varianza-table]');
        if (tbody) {
            tbody.innerHTML = items.map(function (it) {
                var merma = it.mermaValor > 0
                    ? '<span class="admin-nivel1-merma">' + money2(it.mermaValor) + '</span>'
                    : '<span class="admin-nivel1-ok">—</span>';
                return '<tr>' +
                    '<td>' + it.ingrediente + '</td>' +
                    '<td>' + it.teoricoQty + ' ' + it.unidad + ' <small>(' + money2(it.teoricoValor) + ')</small></td>' +
                    '<td>' + merma + '</td>' +
                    '</tr>';
            }).join('');
        }
    }

    // ── §3.4 Reglas de asociación ────────────────────────────────────────
    function renderAsociacion() {
        var a = data.asociacion || {};
        var items = a.items || [];

        setText('[data-n1-asociacion-tickets]', (a.tickets || 0) + ' tickets analizados');

        if (!items.length) {
            emptyRow('[data-n1-asociacion-table]', 5, 'No hay pares con afinidad significativa (lift > 1) en el periodo.');
            return;
        }

        var tbody = document.querySelector('[data-n1-asociacion-table]');
        if (tbody) {
            tbody.innerHTML = items.map(function (it) {
                return '<tr>' +
                    '<td>' + it.a + '</td>' +
                    '<td>' + it.b + '</td>' +
                    '<td>' + it.coocurrencias + '</td>' +
                    '<td>' + it.confianzaPct + '%</td>' +
                    '<td><span class="admin-nivel1-lift">×' + it.lift + '</span></td>' +
                    '</tr>';
            }).join('');
        }
    }

    function renderAll() {
        var pal = palette();
        renderMenu(pal);
        renderRevpash(pal);
        renderVarianza();
        renderAsociacion();
    }

    function init() {
        var host = document.querySelector('[data-admin-nivel1]');
        if (!host || !window.AdminAnalyticsMock || !window.AdminAnalyticsMock.nivel1) {
            return;
        }
        data = window.AdminAnalyticsMock.nivel1;
        renderAll();

        // Re-dibuja las gráficas con la paleta del nuevo tema.
        window.addEventListener('admin:themechange', function () {
            if (data) {
                renderAll();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
