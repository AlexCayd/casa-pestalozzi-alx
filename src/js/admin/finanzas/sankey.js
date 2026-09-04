/**
 * Sankey en SVG, escrito a mano.
 *
 * Sustituye a chartjs-chart-sankey. El plugin dibujaba bien los caudales pero
 * pinta las etiquetas de nodo DENTRO del canvas y sin medirlas: en la última
 * columna, "Nómina · $12,345" se comía contra el borde y quedaban tres barras
 * grises sin rótulo. No era un problema de padding —subirlo encoge el diagrama
 * hasta que los caudales delgados son una línea— sino de que el plugin no deja
 * decidir de qué lado del nodo va el texto.
 *
 * Lo que se gana además de las etiquetas: resaltar la cadena completa al pasar
 * por encima, aislar un nodo al hacer clic, recorrer los nodos con el teclado y
 * dibujar el tramo negativo en vez de explicarlo con una nota al pie.
 *
 * Todo el movimiento va por `opacity`, que es lo que el compositor resuelve sin
 * repintar (la regla de _finishes.scss). El color se lee de los tokens
 * --admin-* con readToken(), igual que el resto de las gráficas, y se vuelve a
 * dibujar en `admin:themechange`.
 *
 * API: window.AdminSankey.render(contenedor, flujos, opciones)
 *   flujos   [{from, to, flow}]
 *   opciones { color(nombre) -> css, texto, muted, tooltipBg, moneda(n) -> str }
 */
(function () {
    var NS = 'http://www.w3.org/2000/svg';

    // Geometría. El ancho del nodo y el hueco entre nodos son los mismos que
    // usaba el plugin; el resto sale de medir el texto.
    var ANCHO_NODO = 14;
    var HUECO_NODO = 22;
    var MARGEN_Y = 14;
    var GAP_ETIQUETA = 10;
    // Un caudal por debajo de esto deja de ser señalable con el ratón. Se
    // dibuja más grueso de lo que le tocaría: miente sobre la magnitud, pero
    // la alternativa es un gasto que no se puede consultar.
    var GROSOR_MINIMO = 2;

    function crear(tag, atributos) {
        var nodo = document.createElementNS(NS, tag);
        Object.keys(atributos || {}).forEach(function (clave) {
            nodo.setAttribute(clave, atributos[clave]);
        });
        return nodo;
    }

    /**
     * Columna de cada nodo por CAMINO MÁS LARGO desde un origen. El plugin
     * necesitaba un mapa fijo ({'Costo de insumos': 1, 'Merma': 1}) porque
     * mandaba las hojas a la última columna, y eso hacía leer el costo de
     * insumos como si compitiera con la nómina. Calculado, el diagrama aguanta
     * que mañana aparezca otro nodo sin tocar el JS.
     */
    function columnas(flujos, nodos) {
        var salidas = {};
        var grado = {};
        nodos.forEach(function (n) { salidas[n] = []; grado[n] = 0; });
        flujos.forEach(function (f) {
            salidas[f.from].push(f.to);
            grado[f.to] += 1;
        });

        var col = {};
        var cola = [];
        nodos.forEach(function (n) {
            col[n] = 0;
            if (grado[n] === 0) cola.push(n);
        });

        // Orden topológico. Los datos vienen de un árbol de descomposición, así
        // que no hay ciclos; el tope de vueltas es una red por si algún día los
        // hubiera y para no colgar la pantalla de finanzas por ello.
        var vueltas = 0;
        var tope = nodos.length * nodos.length + 10;
        while (cola.length && vueltas++ < tope) {
            var actual = cola.shift();
            salidas[actual].forEach(function (destino) {
                if (col[destino] < col[actual] + 1) col[destino] = col[actual] + 1;
                if (--grado[destino] === 0) cola.push(destino);
            });
        }
        return col;
    }

    /**
     * Cuánto vale cada nodo. Devuelve DOS medidas, y la distinción importa:
     *
     *   · tamano   = max(entra, sale). Es el alto de la caja, y tiene que dar
     *                cabida al mayor de los dos lados o los caudales se salen
     *                del nodo.
     *   · etiqueta = lo que entra, o lo que sale si el nodo es un origen. Es
     *                la cifra que se escribe al lado.
     *
     * Son el mismo número casi siempre, y por eso al principio había una sola
     * función. Se separan por el caso del periodo en déficit: ahí «Utilidad
     * bruta» reparte más de lo que recibe —la diferencia es el «Faltante»— y
     * con max() la etiqueta decía $62,097 cuando la utilidad bruta real era
     * $25,260. El nodo sí tiene que MEDIR lo que reparte (y que el hueco entre
     * lo que entra y lo que sale se vea es justamente lo que dibuja el
     * déficit), pero la cifra que se lee es la que entró.
     */
    function totales(flujos) {
        var entra = {};
        var sale = {};
        flujos.forEach(function (f) {
            entra[f.to] = (entra[f.to] || 0) + f.flow;
            sale[f.from] = (sale[f.from] || 0) + f.flow;
        });
        var tamano = {};
        var etiqueta = {};
        Object.keys(entra).concat(Object.keys(sale)).forEach(function (n) {
            tamano[n] = Math.max(entra[n] || 0, sale[n] || 0);
            etiqueta[n] = entra[n] !== undefined ? entra[n] : sale[n];
        });
        return { tamano: tamano, etiqueta: etiqueta };
    }

    /**
     * Curva entre dos nodos. Bézier cúbica con los tiradores a media distancia
     * horizontal: es lo que da el caudal que se lee como una cinta y no como
     * una diagonal. Se dibuja como área cerrada (borde superior, borde derecho,
     * borde inferior de vuelta) para poder rellenarla con un degradado.
     */
    function cinta(x1, y1a, y1b, x2, y2a, y2b) {
        var cx = (x1 + x2) / 2;
        return 'M' + x1 + ',' + y1a +
               'C' + cx + ',' + y1a + ' ' + cx + ',' + y2a + ' ' + x2 + ',' + y2a +
               'L' + x2 + ',' + y2b +
               'C' + cx + ',' + y2b + ' ' + cx + ',' + y1b + ' ' + x1 + ',' + y1b +
               'Z';
    }

    function render(contenedor, flujos, opciones) {
        if (!contenedor) return;
        contenedor.innerHTML = '';
        if (!flujos || !flujos.length) return;

        var opts = opciones || {};
        var moneda = opts.moneda || function (n) { return String(n); };
        var colorDe = opts.color || function () { return 'currentColor'; };

        var ancho = contenedor.clientWidth || 900;
        var alto = contenedor.clientHeight || 380;
        if (ancho < 80 || alto < 80) return;

        var nombres = [];
        flujos.forEach(function (f) {
            if (nombres.indexOf(f.from) === -1) nombres.push(f.from);
            if (nombres.indexOf(f.to) === -1) nombres.push(f.to);
        });

        var col = columnas(flujos, nombres);
        var medidas = totales(flujos);
        // `valor` es el ALTO de cada nodo; `cifra`, lo que se escribe al lado.
        var valor = medidas.tamano;
        var cifra = medidas.etiqueta;
        var maxCol = 0;
        nombres.forEach(function (n) { if (col[n] > maxCol) maxCol = col[n]; });

        var svg = crear('svg', {
            width: '100%',
            height: '100%',
            viewBox: '0 0 ' + ancho + ' ' + alto,
            preserveAspectRatio: 'xMidYMid meet',
            role: 'img',
            'aria-label': 'Descomposición del ingreso del periodo'
        });
        svg.style.overflow = 'visible';
        contenedor.appendChild(svg);

        // ── Medida del texto ────────────────────────────────────────
        //
        // Se mide DE VERDAD, con getComputedTextLength sobre un <text> que ya
        // está en el árbol: es la única forma de saber si "Nómina · $12,345"
        // cabe a la derecha del nodo o hay que ponerlo a su izquierda. Estimar
        // por número de caracteres era lo que fallaba con la tipografía
        // proporcional de las etiquetas.
        var regla = crear('text', { x: -9999, y: -9999 });
        regla.setAttribute('font-size', '12');
        regla.setAttribute('font-weight', '700');
        svg.appendChild(regla);
        function medir(texto) {
            regla.textContent = texto;
            return regla.getComputedTextLength();
        }

        var etiqueta = {};
        nombres.forEach(function (n) {
            etiqueta[n] = n + ' · ' + moneda(cifra[n]);
        });

        // Las etiquetas de la primera columna van a la derecha del nodo y las
        // de la última a la izquierda; las de en medio, a la derecha salvo que
        // no quepan. Con eso el diagrama nunca desborda el contenedor.
        var anchoIzq = 0;
        var anchoDer = 0;
        nombres.forEach(function (n) {
            var w = medir(etiqueta[n]);
            if (col[n] === maxCol) {
                if (w > anchoDer) anchoDer = w;
            } else if (col[n] === 0) {
                if (w > anchoIzq) anchoIzq = w;
            }
        });

        var padIzq = 4;
        var padDer = anchoDer + GAP_ETIQUETA + 4;
        var util = ancho - padIzq - padDer - ANCHO_NODO;
        // Con etiquetas larguísimas y un panel estrecho el espacio útil se
        // puede quedar en nada; ahí manda un mínimo y el SVG desborda a un
        // scroll horizontal en vez de dibujar los nodos encima unos de otros.
        if (util < 160) util = 160;
        var pasoX = maxCol > 0 ? util / maxCol : 0;

        // ── Posición vertical ───────────────────────────────────────
        //
        // Por columna: se reparte el alto disponible en proporción al caudal.
        // El orden dentro de la columna es por magnitud descendente, que es lo
        // que evita que los caudales se crucen y el diagrama parezca un nudo.
        var porColumna = {};
        nombres.forEach(function (n) {
            (porColumna[col[n]] = porColumna[col[n]] || []).push(n);
        });

        var escala = Infinity;
        Object.keys(porColumna).forEach(function (c) {
            var lista = porColumna[c];
            var suma = 0;
            lista.forEach(function (n) { suma += valor[n]; });
            var libre = alto - 2 * MARGEN_Y - (lista.length - 1) * HUECO_NODO;
            if (suma > 0 && libre > 0) escala = Math.min(escala, libre / suma);
        });
        if (!isFinite(escala) || escala <= 0) escala = 1;

        var caja = {};
        Object.keys(porColumna).forEach(function (c) {
            var lista = porColumna[c].sort(function (a, b) { return valor[b] - valor[a]; });
            var altoTotal = 0;
            lista.forEach(function (n) { altoTotal += Math.max(valor[n] * escala, GROSOR_MINIMO); });
            altoTotal += (lista.length - 1) * HUECO_NODO;

            var y = (alto - altoTotal) / 2;
            lista.forEach(function (n) {
                var h = Math.max(valor[n] * escala, GROSOR_MINIMO);
                caja[n] = {
                    x: padIzq + col[n] * pasoX,
                    y: y,
                    h: h,
                    // Cursores para ir apilando los caudales que entran y los
                    // que salen sin que se solapen dentro del nodo.
                    salida: y,
                    entrada: y
                };
                y += h + HUECO_NODO;
            });
        });

        // ── Degradados ──────────────────────────────────────────────
        var defs = crear('defs', {});
        svg.appendChild(defs);

        var capaCintas = crear('g', {});
        var capaNodos = crear('g', {});
        svg.appendChild(capaCintas);
        svg.appendChild(capaNodos);

        // Grafo de vecinos, para resaltar la cadena completa desde un nodo.
        var vecinos = {};
        nombres.forEach(function (n) { vecinos[n] = { arriba: [], abajo: [] }; });
        flujos.forEach(function (f) {
            vecinos[f.from].abajo.push(f.to);
            vecinos[f.to].arriba.push(f.from);
        });

        var cintas = [];

        flujos.forEach(function (f, i) {
            var a = caja[f.from];
            var b = caja[f.to];
            if (!a || !b) return;

            var grosor = Math.max(f.flow * escala, GROSOR_MINIMO);
            var x1 = a.x + ANCHO_NODO;
            var x2 = b.x;
            var y1 = a.salida;
            var y2 = b.entrada;
            a.salida += grosor;
            b.entrada += grosor;

            var id = 'sankey-grad-' + i;
            var grad = crear('linearGradient', {
                id: id, x1: '0', x2: '1', y1: '0', y2: '0'
            });
            var p1 = crear('stop', { offset: '0', 'stop-color': colorDe(f.from) });
            var p2 = crear('stop', { offset: '1', 'stop-color': colorDe(f.to) });
            grad.appendChild(p1);
            grad.appendChild(p2);
            defs.appendChild(grad);

            var path = crear('path', {
                d: cinta(x1, y1, y1 + grosor, x2, y2, y2 + grosor),
                fill: 'url(#' + id + ')',
                'fill-opacity': '0.62'
            });
            path.classList.add('admin-sankey__cinta');
            path.dataset.from = f.from;
            path.dataset.to = f.to;

            capaCintas.appendChild(path);
            cintas.push({ nodo: path, from: f.from, to: f.to, flow: f.flow });
        });

        // ── Nodos y etiquetas ───────────────────────────────────────
        var rects = [];
        nombres.forEach(function (n) {
            var c = caja[n];
            var g = crear('g', {});

            var rect = crear('rect', {
                x: c.x, y: c.y, width: ANCHO_NODO, height: c.h,
                rx: Math.min(4, ANCHO_NODO / 2),
                fill: colorDe(n),
                tabindex: '0',
                role: 'button',
                'aria-label': etiqueta[n] + '. Pulsa para aislar sus flujos.'
            });
            rect.classList.add('admin-sankey__nodo');
            rect.dataset.nombre = n;

            // La última columna escribe a la izquierda del nodo: es lo que
            // impedía el plugin y por lo que se cortaban los rótulos.
            var alaIzquierda = col[n] === maxCol;
            var texto = crear('text', {
                x: alaIzquierda ? c.x - GAP_ETIQUETA : c.x + ANCHO_NODO + GAP_ETIQUETA,
                y: c.y + c.h / 2,
                'dominant-baseline': 'middle',
                'text-anchor': alaIzquierda ? 'end' : 'start',
                fill: opts.texto || 'currentColor'
            });
            texto.classList.add('admin-sankey__rotulo');
            texto.textContent = etiqueta[n];

            g.appendChild(rect);
            g.appendChild(texto);
            capaNodos.appendChild(g);
            rects.push({ grupo: g, rect: rect, nombre: n });
        });

        svg.removeChild(regla);

        // ── Tooltip ─────────────────────────────────────────────────
        //
        // Propio y no el <title> nativo del SVG: el del navegador tarda un
        // segundo largo en aparecer, no se puede estilar y en el tema oscuro
        // sale con el cromo del sistema. Va en el contenedor —que es
        // position:relative— y no en el <body>, así que se mueve con el panel
        // al hacer scroll sin escuchar nada.
        var tip = document.createElement('div');
        tip.className = 'admin-sankey__tip';
        tip.hidden = true;
        contenedor.appendChild(tip);

        var ingresoTotal = cifra['Ingresos'] || 0;

        function mostrarTip(evento, texto) {
            tip.textContent = texto;
            tip.hidden = false;
            var caja = contenedor.getBoundingClientRect();
            var x = evento.clientX - caja.left;
            var y = evento.clientY - caja.top;
            // Cerca del borde derecho el globo se voltea al otro lado del
            // cursor, o se saldría del panel.
            var volteado = x > caja.width - tip.offsetWidth - 24;
            tip.style.left = (volteado ? x - tip.offsetWidth - 14 : x + 14) + 'px';
            tip.style.top = (y + 14) + 'px';
        }

        function ocultarTip() { tip.hidden = true; }

        cintas.forEach(function (c) {
            c.nodo.addEventListener('mousemove', function (evento) {
                var texto = c.from + ' → ' + c.to + ': ' + moneda(c.flow);
                if (ingresoTotal > 0) {
                    texto += ' (' + ((c.flow / ingresoTotal) * 100).toFixed(1) + '% del ingreso)';
                }
                mostrarTip(evento, texto);
            });
            c.nodo.addEventListener('mouseleave', ocultarTip);
        });

        // ── Resaltado ───────────────────────────────────────────────
        //
        // La cadena de un nodo es todo lo que llega hasta él y todo lo que sale
        // de él, recursivamente: en un Sankey de descomposición eso responde a
        // "¿de dónde salió este dinero y a dónde acabó yendo?", que es la única
        // pregunta que la gente le hace al diagrama.
        function cadena(inicio) {
            var vistos = {};
            function recorrer(n, direccion) {
                if (vistos[n + direccion]) return;
                vistos[n + direccion] = true;
                vistos[n] = true;
                vecinos[n][direccion].forEach(function (v) { recorrer(v, direccion); });
            }
            recorrer(inicio, 'arriba');
            recorrer(inicio, 'abajo');
            return vistos;
        }

        var fijado = null;

        function pintar(activo) {
            var conjunto = activo ? cadena(activo) : null;
            cintas.forEach(function (c) {
                var dentro = !conjunto || (conjunto[c.from] && conjunto[c.to]);
                c.nodo.style.opacity = dentro ? '1' : '0.14';
            });
            rects.forEach(function (r) {
                var dentro = !conjunto || conjunto[r.nombre];
                r.grupo.style.opacity = dentro ? '1' : '0.22';
            });
        }

        rects.forEach(function (r) {
            function entrar() { if (!fijado) pintar(r.nombre); }
            function salir() { if (!fijado) pintar(null); ocultarTip(); }

            r.rect.addEventListener('mousemove', function (evento) {
                mostrarTip(evento, etiqueta[r.nombre]);
            });
            r.rect.addEventListener('mouseenter', entrar);
            r.rect.addEventListener('mouseleave', salir);
            r.rect.addEventListener('focus', entrar);
            r.rect.addEventListener('blur', salir);

            function alternar(evento) {
                evento.preventDefault();
                fijado = fijado === r.nombre ? null : r.nombre;
                pintar(fijado);
            }
            r.rect.addEventListener('click', alternar);
            r.rect.addEventListener('keydown', function (evento) {
                if (evento.key === 'Enter' || evento.key === ' ') alternar(evento);
                // Escape suelta el nodo fijado sin tener que buscarlo otra vez.
                if (evento.key === 'Escape' && fijado) { fijado = null; pintar(null); }
            });
        });

        // Un clic en el hueco suelta el nodo fijado. Sin esto hay que acertar
        // otra vez sobre una barra de 14px para volver a ver el diagrama entero.
        svg.addEventListener('click', function (evento) {
            if (evento.target === svg && fijado) { fijado = null; pintar(null); }
        });

        // ── Entrada ─────────────────────────────────────────────────
        if (!window.matchMedia || !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            capaCintas.style.opacity = '0';
            capaCintas.style.transition = 'opacity 420ms cubic-bezier(0.22, 0.61, 0.36, 1)';
            requestAnimationFrame(function () { capaCintas.style.opacity = '1'; });
        }
    }

    window.AdminSankey = { render: render };
})();
