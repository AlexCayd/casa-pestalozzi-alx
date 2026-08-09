/**
 * Analíticas diagnósticas de Nivel 1 (ANALITICAS.md §3). Renderiza las tres
 * secciones a partir de window.AdminAnalyticsMock.nivel1 (Services\Analiticas):
 *   §3.1 Ingeniería de menú  — matriz Kasavana-Smith (scatter + tabla).
 *   §3.2 RevPASH             — mapa de calor hora × día (tabla, sin canvas).
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
            emptyRow('[data-n1-menu-table]', 3, 'Sin ventas en el periodo para clasificar el menú.');
            makeChart('menuEngineeringChart', { type: 'scatter', data: { datasets: [] }, options: { responsive: true, maintainAspectRatio: false } });
            return;
        }

        var tbody = document.querySelector('[data-n1-menu-table]');
        if (tbody) {
            tbody.innerHTML = items.map(function (it) {
                return '<tr>' +
                    '<td><span class="admin-table__cell-main">' + it.nombre + '</span>' +
                        '<span class="admin-table__cell-sub">' + it.categoria + '</span></td>' +
                    '<td><span class="admin-nivel1-badge admin-nivel1-badge--' + it.clase + '">' +
                        CLASES[it.clase].emoji + ' ' + it.claseLabel + '</span></td>' +
                    '<td class="admin-table__num">' +
                        '<span class="admin-table__cell-main">' + money2(it.margen) + '</span>' +
                        '<span class="admin-table__cell-sub">' + it.unidades + ' uds · ' + it.margenPct + '%</span>' +
                    '</td>' +
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

    // ── §3.2 RevPASH (mapa de calor hora × día) ──────────────────────────
    /**
     * El mapa no usa Chart.js: es una <table> con una variable CSS --heat por
     * celda (0-1, proporción contra la celda más fuerte). El color lo resuelve
     * la hoja de estilos con los tokens del tema, así que al cambiar de claro a
     * oscuro no hay que volver a pintar nada — a diferencia de un canvas.
     */
    function renderRevpash() {
        var rp = data.revpash || {};
        var horas = rp.horas || [];
        var dias = rp.dias || [];
        var celdas = rp.celdas || [];
        var max = rp.max || 0;

        setText('[data-n1-revpash-seats]', (rp.asientos || 0) + ' asientos');

        var noteEl = document.querySelector('[data-n1-revpash-note]');
        if (noteEl) {
            if (rp.mejor && rp.peor) {
                noteEl.innerHTML =
                    'Franja más fuerte: <strong>' + rp.mejor.dia + ' ' + rp.mejor.hora + '</strong> (' +
                    money2(rp.mejor.valor) + '/asiento) · Más floja: <strong>' +
                    rp.peor.dia + ' ' + rp.peor.hora + '</strong> (' + money2(rp.peor.valor) + '/asiento)';
            } else {
                noteEl.textContent = '';
            }
        }

        var host = document.querySelector('[data-n1-revpash-heat]');
        var scale = document.querySelector('[data-n1-revpash-scale]');
        if (!host) {
            return;
        }

        if (!horas.length || !dias.length) {
            host.innerHTML = '<p class="admin-nivel1-empty">Sin ventas ni horario definido en el periodo.</p>';
            if (scale) {
                scale.hidden = true;
            }
            return;
        }

        var head = '<thead><tr><th scope="col"><span class="admin-n1-heat__corner">Hora</span></th>' +
            dias.map(function (d) {
                return '<th scope="col" abbr="' + d.corto + '"><span title="' + d.largo + '">' + d.corto + '</span></th>';
            }).join('') + '</tr></thead>';

        var body = horas.map(function (hora, i) {
            var fila = celdas[i] || [];
            var tds = dias.map(function (d, j) {
                var c = fila[j] || { v: 0, ing: 0, dias: 0, abierto: false };
                var valor = c.v || 0;

                // Sin días abiertos y sin venta: el negocio no operó esa franja.
                // Es un hueco de calendario, no de desempeño; se marca distinto
                // para no confundirlo con "abierto y no vendió".
                if (!c.abierto && !c.ing) {
                    var cerrado = d.largo + ' ' + hora + ' · cerrado';
                    return '<td class="admin-n1-heat__cell is-closed" ' +
                        'title="' + cerrado + '" aria-label="' + cerrado + '">·</td>';
                }

                var ratio = max > 0 ? valor / max : 0;
                var cls = 'admin-n1-heat__cell';
                if (ratio >= 0.55) {
                    cls += ' is-strong';       // fondo saturado: texto oscuro
                }
                if (valor <= 0) {
                    cls += ' is-empty';        // abierto y sin vender: el hueco
                }
                if (!c.abierto) {
                    cls += ' is-offhours';     // vendió fuera del horario declarado
                }

                var tip = d.largo + ' ' + hora + ' · ' + money2(valor) + ' por asiento' +
                    ' · ingreso ' + money(c.ing) + ' · ' + c.dias +
                    (c.dias === 1 ? ' día' : ' días') + (c.abierto ? ' abierto' : ' con venta');

                return '<td class="' + cls + '" style="--heat: ' + ratio.toFixed(3) + '"' +
                    ' title="' + tip + '" aria-label="' + tip + '">' +
                    (valor > 0 ? '$' + Math.round(valor) : '0') + '</td>';
            }).join('');

            return '<tr><th scope="row">' + hora + '</th>' + tds + '</tr>';
        }).join('');

        host.innerHTML = '<table class="admin-n1-heat__table">' + head + '<tbody>' + body + '</tbody></table>';

        if (scale) {
            scale.hidden = max <= 0;
            setText('[data-n1-revpash-max]', money2(max) + ' / asiento');
        }
    }

    // ── §3.4 Reglas de asociación ────────────────────────────────────────
    function renderAsociacion() {
        var a = data.asociacion || {};
        var items = a.items || [];

        setText('[data-n1-asociacion-tickets]', (a.tickets || 0) + ' tickets analizados');

        if (!items.length) {
            emptyRow('[data-n1-asociacion-table]', 3, 'No hay pares con afinidad significativa (lift > 1) en el periodo.');
            return;
        }

        var tbody = document.querySelector('[data-n1-asociacion-table]');
        if (tbody) {
            tbody.innerHTML = items.map(function (it) {
                return '<tr>' +
                    '<td><span class="admin-table__cell-main">' + it.a + '</span>' +
                        '<span class="admin-table__cell-sub">+ ' + it.b + '</span></td>' +
                    '<td class="admin-table__num">' + it.coocurrencias + '</td>' +
                    '<td class="admin-table__num">' +
                        '<span class="admin-table__cell-main">' + it.confianzaPct + '%</span>' +
                        '<span class="admin-table__cell-sub"><span class="admin-nivel1-lift">×' + it.lift + '</span></span>' +
                    '</td>' +
                    '</tr>';
            }).join('');
        }
    }

    function renderAll() {
        var pal = palette();
        renderMenu(pal);
        renderRevpash();
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
