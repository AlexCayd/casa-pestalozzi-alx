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

    function readToken(name, fallback, selector) {
        var host = (selector && document.querySelector(selector)) ||
            document.querySelector('.admin-body') || document.body;
        var value = getComputedStyle(host).getPropertyValue(name).trim();
        return value || fallback;
    }

    function palette() {
        var dark = document.documentElement.getAttribute('data-admin-theme') === 'dark';
        var muted = readToken('--admin-muted', dark ? 'rgba(237,233,223,0.58)' : '#766f65');
        // El verde del mapa de calor se declara en .admin-nivel1, no en
        // .admin-body, así que hay que leerlo desde ese contenedor. Las barras
        // del RevPASH diario lo reutilizan: mismo indicador, mismo color.
        var heat = readToken(
            '--n1-heat-rgb', dark ? '108, 194, 74' : '63, 143, 79', '[data-admin-nivel1]'
        );
        // Los cuadrantes salen de los tokens --admin-n1-* de _globals.scss. Los
        // literales que quedan son sólo respaldo por si la hoja aún no pintó.
        return {
            heat: 'rgba(' + heat + ', 0.85)',
            heatBorde: 'rgb(' + heat + ')',
            dark: dark,
            muted: muted,
            grid: readToken('--admin-chart-grid', dark ? 'rgba(237,233,223,0.12)' : 'rgba(118,111,101,0.18)'),
            tooltipBg: readToken('--admin-chart-tooltip', dark ? '#0b0c0d' : '#211f1b'),
            texto: readToken('--admin-text-inverse', '#ede9df'),
            estrella: readToken('--admin-n1-estrella', dark ? '#6cc24a' : '#3f8f4f'),
            vaca: readToken('--admin-n1-vaca', dark ? '#f2b134' : '#c98a1f'),
            incognita: readToken('--admin-n1-incognita', dark ? '#5aa9e6' : '#2f6db3'),
            perro: readToken('--admin-n1-perro', dark ? '#f2673f' : '#c1462e')
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
                        backgroundColor: pal.tooltipBg, padding: 12, titleColor: pal.texto, bodyColor: pal.texto,
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

    // ── §3.2 RevPASH · tooltip de celda ──────────────────────────────────
    /**
     * El mapa es una <table>, no un canvas, así que Chart.js no le pone
     * tooltip: hay que montarlo a mano. Cada celda guarda sus cifras en data-*
     * y un único globo las lee al pasar el cursor.
     *
     * Se monta en <body> con position:fixed a propósito. El mapa vive dentro de
     * .admin-card, que recorta con overflow:hidden, y además su contenedor tiene
     * overflow-x:auto para las siete columnas: un globo absoluto dentro de la
     * tabla quedaba cortado en los bordes y en las celdas de la última fila.
     */
    var ESTADOS_HEAT = {
        abierto:  'Abierto y vendiendo',
        sinventa: 'Abierto y sin vender · el hueco de desempeño',
        fuera:    'Venta fuera del horario declarado · otra base de cálculo',
        cerrado:  'Cerrado · es calendario, no desempeño'
    };

    var heatTip = null;      // el globo, montado una sola vez
    var heatCelda = null;    // celda activa, para reposicionar al hacer scroll

    /** Cifras de la celda como atributos data-*, que es lo que lee el globo. */
    function heatDatos(d, hora, c, valor, ratio, estado) {
        return 'data-heat-dia="' + d.largo + '"' +
            ' data-heat-hora="' + hora + '"' +
            ' data-heat-valor="' + (valor || 0) + '"' +
            ' data-heat-ing="' + (c.ing || 0) + '"' +
            ' data-heat-dias="' + (c.dias || 0) + '"' +
            ' data-heat-ratio="' + (ratio || 0) + '"' +
            ' data-heat-estado="' + estado + '"';
    }

    function heatTipMostrar(td) {
        if (!heatTip) {
            heatTip = document.createElement('div');
            heatTip.className = 'admin-n1-tip';
            heatTip.setAttribute('role', 'presentation');
            heatTip.hidden = true;
            document.body.appendChild(heatTip);
        }

        var estado = td.getAttribute('data-heat-estado');
        var dias = parseInt(td.getAttribute('data-heat-dias'), 10) || 0;
        var valor = parseFloat(td.getAttribute('data-heat-valor')) || 0;
        var ratio = parseFloat(td.getAttribute('data-heat-ratio')) || 0;
        var filas = '';

        if (estado !== 'cerrado') {
            filas += heatFila(
                'Ingreso de la franja',
                money(parseFloat(td.getAttribute('data-heat-ing')) || 0)
            );
            filas += heatFila(
                dias === 1 ? 'Día que la sostiene' : 'Días que la sostienen',
                dias + (estado === 'fuera' ? ' con venta' : ' abierto' + (dias === 1 ? '' : 's'))
            );
            // La saturación es relativa a la celda más fuerte del periodo, no
            // absoluta. Sin este dato el color no se puede interpretar.
            filas += heatFila('Intensidad del color', Math.round(ratio * 100) + ' % del máximo');
        }

        heatTip.innerHTML =
            '<p class="admin-n1-tip__title">' +
                td.getAttribute('data-heat-dia') + ' · ' + td.getAttribute('data-heat-hora') +
            '</p>' +
            (estado === 'cerrado'
                ? ''
                : '<p class="admin-n1-tip__value">' + money2(valor) +
                  '<span>por asiento</span></p>') +
            (filas ? '<dl class="admin-n1-tip__rows">' + filas + '</dl>' : '') +
            '<p class="admin-n1-tip__state is-' + estado + '">' + ESTADOS_HEAT[estado] + '</p>';

        heatTip.hidden = false;
        heatCelda = td;
        heatTipPosicionar();
    }

    function heatFila(etiqueta, valor) {
        return '<div><dt>' + etiqueta + '</dt><dd>' + valor + '</dd></div>';
    }

    /** Encima de la celda y centrado; si no cabe arriba, debajo. */
    function heatTipPosicionar() {
        if (!heatTip || heatTip.hidden || !heatCelda) {
            return;
        }

        var margen = 8;
        var celda = heatCelda.getBoundingClientRect();
        var ancho = heatTip.offsetWidth;
        var alto = heatTip.offsetHeight;

        var top = celda.top - alto - margen;
        if (top < margen) {
            top = celda.bottom + margen;   // no cabe arriba: se voltea
        }

        var left = celda.left + (celda.width - ancho) / 2;
        var maximo = document.documentElement.clientWidth - ancho - margen;
        if (left > maximo) { left = maximo; }
        if (left < margen) { left = margen; }

        heatTip.style.top = Math.round(top) + 'px';
        heatTip.style.left = Math.round(left) + 'px';
    }

    function heatTipOcultar() {
        if (heatTip) {
            heatTip.hidden = true;
        }
        heatCelda = null;
    }

    /**
     * Delegación sobre el contenedor, que sobrevive a los redibujados: al
     * cambiar de tema renderRevpash() reemplaza el innerHTML entero y un
     * listener por celda se habría perdido con cada cambio.
     */
    function heatTipDelegar(host) {
        if (host.dataset.tipListo === '1') {
            return;
        }
        host.dataset.tipListo = '1';

        host.addEventListener('mouseover', function (e) {
            var td = e.target.closest ? e.target.closest('.admin-n1-heat__cell') : null;
            if (!td) {
                // La tabla separa las celdas con border-spacing, y esos 3px son
                // del <table>: sin esto el globo se quedaba describiendo la
                // celda anterior mientras el cursor estaba en el hueco.
                heatTipOcultar();
                return;
            }
            if (td !== heatCelda) {
                heatTipMostrar(td);
            }
        });
        host.addEventListener('mouseleave', heatTipOcultar);

        // El globo va en <body>, así que no se mueve solo con la página ni con
        // el scroll horizontal de la tabla: hay que recolocarlo. En captura,
        // para enterarse también del scroll del contenedor interno.
        window.addEventListener('scroll', heatTipPosicionar, true);
        window.addEventListener('resize', heatTipPosicionar);
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

        // La celda que estuviera bajo el cursor deja de existir al reescribir
        // la tabla; sin esto el globo se queda flotando apuntando a la nada.
        heatTipOcultar();

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
                        heatDatos(d, hora, c, 0, 0, 'cerrado') +
                        ' aria-label="' + cerrado + '">·</td>';
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

                var estado = !c.abierto ? 'fuera' : (valor > 0 ? 'abierto' : 'sinventa');

                // aria-label se conserva porque las celdas no reciben foco: es
                // lo único que lee un lector de pantalla. El tooltip visual sale
                // de los data-* de heatDatos(), no de un title nativo — dejar los
                // dos encima mostraba el globo del navegador sobre el nuestro.
                var etiqueta = d.largo + ' ' + hora + ' · ' + money2(valor) + ' por asiento' +
                    ' · ingreso ' + money(c.ing) + ' · ' + c.dias +
                    (c.dias === 1 ? ' día' : ' días') + (c.abierto ? ' abierto' : ' con venta');

                return '<td class="' + cls + '" style="--heat: ' + ratio.toFixed(3) + '"' +
                    ' ' + heatDatos(d, hora, c, valor, ratio, estado) +
                    ' aria-label="' + etiqueta + '">' +
                    (valor > 0 ? '$' + Math.round(valor) : '0') + '</td>';
            }).join('');

            return '<tr><th scope="row">' + hora + '</th>' + tds + '</tr>';
        }).join('');

        host.innerHTML = '<table class="admin-n1-heat__table">' + head + '<tbody>' + body + '</tbody></table>';
        heatTipDelegar(host);

        if (scale) {
            scale.hidden = max <= 0;
            setText('[data-n1-revpash-max]', money2(max) + ' / asiento');
        }
    }

    // ── §3.2 bis · RevPASH por día completo ──────────────────────────────
    /**
     * Misma métrica que el mapa, otra unidad: el día entero. El denominador son
     * todas las horas que el restaurante abrió ese día (acumuladas sobre las
     * fechas del rango), no las veces que abrió una franja suelta, así que las
     * horas muertas también pesan.
     *
     * Se dibuja aparte del mapa a propósito: son dos denominadores distintos y
     * meterlos en un mismo eje invitaría a comparar números que no comparan.
     */
    function renderRevpashDiario(pal) {
        var rp = data.revpash || {};
        var dias = rp.porDia || [];
        var prom = rp.promedioDia || 0;
        var noteEl = document.querySelector('[data-n1-revpash-daily-note]');

        var conDato = dias.filter(function (d) { return d.valor > 0; });
        if (!conDato.length) {
            if (noteEl) {
                noteEl.textContent = '';
            }
            makeChart('revpashDailyChart', {
                type: 'bar', data: { labels: [], datasets: [] },
                options: { responsive: true, maintainAspectRatio: false }
            });
            return;
        }

        var mejor = conDato.reduce(function (a, b) { return b.valor > a.valor ? b : a; });
        var peor = conDato.reduce(function (a, b) { return b.valor < a.valor ? b : a; });

        if (noteEl) {
            noteEl.innerHTML =
                'Día más fuerte: <strong>' + mejor.largo + '</strong> (' + money2(mejor.valor) +
                '/asiento) · Más flojo: <strong>' + peor.largo + '</strong> (' +
                money2(peor.valor) + '/asiento) · Promedio del periodo: <strong>' +
                money2(prom) + '/asiento</strong>';
        }

        var datasets = [{
            label: 'RevPASH del día',
            data: dias.map(function (d) { return d.valor; }),
            backgroundColor: pal.heat,
            borderColor: pal.heatBorde,
            borderWidth: 1,
            borderRadius: 5,
            // El panel ocupa el ancho completo: sin tope las barras se vuelven
            // bloques, y con el tope de media anchura quedaban siete palillos.
            maxBarThickness: 90
        }];

        // Referencia del periodo, con el mismo trazo punteado que el corte de
        // margen de §3.1: en el panel una línea así siempre significa "promedio".
        if (prom > 0) {
            datasets.push({
                label: 'Promedio del periodo',
                type: 'line',
                data: dias.map(function () { return prom; }),
                borderColor: pal.muted,
                borderDash: [6, 6],
                borderWidth: 1,
                pointRadius: 0,
                fill: false
            });
        }

        makeChart('revpashDailyChart', {
            type: 'bar',
            data: {
                labels: dias.map(function (d) { return d.corto; }),
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: pal.tooltipBg, padding: 12,
                        titleColor: pal.texto, bodyColor: pal.texto,
                        callbacks: {
                            title: function (ctx) {
                                return dias[ctx[0].dataIndex].largo;
                            },
                            label: function (ctx) {
                                var d = dias[ctx.dataIndex];
                                if (ctx.dataset.type === 'line') {
                                    return 'Promedio del periodo: ' + money2(prom);
                                }
                                return money2(d.valor) + ' por asiento';
                            },
                            afterLabel: function (ctx) {
                                var d = dias[ctx.dataIndex];
                                if (ctx.dataset.type === 'line') {
                                    return null;
                                }
                                return 'Ingreso ' + money(d.ingreso) + ' · ' + d.horas +
                                    (d.horas === 1 ? ' hora' : ' horas') +
                                    (d.abierto ? ' de operación' : ' con venta, sin horario declarado');
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { color: pal.muted } },
                    y: {
                        title: { display: true, text: 'Ingreso por asiento ($)', color: pal.muted },
                        grid: { color: pal.grid },
                        ticks: { color: pal.muted },
                        beginAtZero: true
                    }
                }
            }
        });
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
            /*
             * Los dos platillos se pintan iguales: la regla es simétrica —el
             * servicio calcula la confianza con min(soporteA, soporteB)— y el
             * par «principal + acompañamiento» que sugería la jerarquía
             * anterior no existe en el dato.
             */
            tbody.innerHTML = items.map(function (it) {
                return '<tr>' +
                    '<td><span class="admin-nivel1-pair">' +
                        '<span class="admin-nivel1-pair__item">' + it.a + '</span>' +
                        '<span class="admin-nivel1-pair__plus" aria-hidden="true">+</span>' +
                        '<span class="admin-nivel1-pair__item">' + it.b + '</span>' +
                    '</span></td>' +
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
        renderRevpashDiario(pal);
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
