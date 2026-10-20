/**
 * Consultas rápidas: el motor del Asistente IA, del lado del navegador.
 *
 * Cada respuesta vuelve con la pregunta dicha de vuelta y sus variables como
 * HUECOS —«[Canela ▾] en almacén», «Ventas de [Cappuccino ▾] · [Ayer ▾]»—, y
 * cada hueco abre un selector (con buscador si la lista es larga) que vuelve a
 * consultar al elegir. Se manda siempre la consulta ESTRUCTURADA (intención +
 * entidad + opción + periodo), nunca una frase: el cliente retiró el texto
 * libre, y el resultado no depende de que el intérprete adivine. La
 * interpretación vive en el servidor (Services\Analytics\ConsultasRapidas);
 * aquí sólo se pinta.
 *
 * Hubo también una barra de pregunta en Tickets, Análisis, Menú e Inventario;
 * se retiró para concentrar todo en el Asistente, y con ella su campo de texto.
 *
 * Dos piezas:
 *   - Selector: el desplegable de un hueco. Uno por página, portado a <body>
 *     con position: fixed porque `.admin-card { overflow: hidden }` y
 *     compañía recortarían cualquier desplegable absoluto.
 *   - Resultado: la tarjeta con sus huecos, montable en CUALQUIER contenedor.
 *
 * API pública en window.ConsultasRapidas (ver el final del archivo y
 * CLAUDE.md).
 */
(function (window, document) {
    'use strict';

    var API = '/admin/api/consultas';
    var CABECERAS = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var CAMPOS = ['intencion', 'id', 'opcion', 'periodo'];
    var uid = 0;

    function esc(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icono(nombre, tam) {
        return window.AdminIcons ? window.AdminIcons.get(nombre, tam || 16) : '';
    }

    // Sin acentos ni mayúsculas, carácter por carácter: cada letra conserva
    // su posición, y así el resaltado puede marcar el nombre original.
    var SIN_ACENTO = {
        'á': 'a', 'à': 'a', 'ä': 'a', 'â': 'a', 'é': 'e', 'è': 'e', 'ë': 'e', 'ê': 'e',
        'í': 'i', 'ì': 'i', 'ï': 'i', 'î': 'i', 'ó': 'o', 'ò': 'o', 'ö': 'o', 'ô': 'o',
        'ú': 'u', 'ù': 'u', 'ü': 'u', 'û': 'u', 'ñ': 'n', 'ç': 'c'
    };

    function normalizar(texto) {
        var t = String(texto || '').toLowerCase();
        var s = '';
        for (var i = 0; i < t.length; i++) {
            var c = t.charAt(i);
            s += SIN_ACENTO[c] || c;
        }
        return s;
    }

    function mezclar() {
        var salida = {};
        for (var i = 0; i < arguments.length; i++) {
            var o = arguments[i];
            if (!o) continue;
            for (var k in o) {
                if (Object.prototype.hasOwnProperty.call(o, k)) salida[k] = o[k];
            }
        }
        return salida;
    }

    function emitir(el, nombre, detalle, cancelable) {
        var ev;
        try {
            ev = new CustomEvent(nombre, { bubbles: true, cancelable: !!cancelable, detail: detalle });
        } catch (e) {
            ev = document.createEvent('CustomEvent');
            ev.initCustomEvent(nombre, true, !!cancelable, detalle);
        }
        return el.dispatchEvent(ev);
    }

    // ── Red ─────────────────────────────────────────────────────

    function aParametros(consulta, ambito) {
        var p = new URLSearchParams();
        if (ambito) p.set('ambito', ambito);
        if (consulta && consulta.intencion) {
            CAMPOS.forEach(function (k) {
                var v = consulta[k];
                if (v !== null && v !== undefined && v !== '') p.set(k, String(v));
            });
        } else {
            p.set('q', String((consulta && consulta.q) || ''));
            if (consulta && consulta.periodo) p.set('periodo', String(consulta.periodo));
        }
        return p.toString();
    }

    // Un 400 trae JSON con `ok: false` y se entrega tal cual; lo que no es
    // JSON (la sesión caducó y llegó el HTML del login) es un fallo.
    function pedirJson(url, signal) {
        return fetch(url, { headers: CABECERAS, credentials: 'same-origin', signal: signal })
            .then(function (r) {
                return r.json().catch(function () { return null; });
            })
            .then(function (datos) {
                if (!datos) throw new Error('La respuesta no es JSON.');
                return datos;
            });
    }

    /**
     * Pide una consulta sin pintar nada.
     * `consulta`: `{intencion, id?, opcion?, periodo?}`. Una frase o `{q,
     * periodo?}` sólo llega de lo guardado antes de retirar el texto libre.
     */
    function consultar(consulta, opciones) {
        opciones = opciones || {};
        if (typeof consulta === 'string') consulta = { q: consulta };
        return pedirJson(API + '?' + aParametros(consulta, opciones.ambito || 'todo'), opciones.signal);
    }

    // El catálogo y las capacidades cambian poco y se piden una vez por
    // página; si fallan, se olvida la promesa para poder reintentar.
    var cache = {};
    function pedirUnaVez(clave, url) {
        if (!cache[clave]) {
            cache[clave] = pedirJson(url).then(function (d) {
                if (!d.ok) throw new Error(d.mensaje || 'No fue posible cargar la lista.');
                return d;
            });
            cache[clave].catch(function () { delete cache[clave]; });
        }
        return cache[clave];
    }

    function catalogo() {
        return pedirUnaVez('catalogo', API + '/catalogo');
    }

    function capacidades() {
        return pedirUnaVez('capacidades', API + '/capacidades');
    }

    // ── Selector de un hueco ────────────────────────────────────
    //
    // Con buscador (platillos, categorías, ingredientes) es un diálogo con un
    // combobox dentro: el foco se queda en el campo y la opción activa viaja
    // por aria-activedescendant. Sin buscador (periodo, opción) el foco va a
    // la propia lista. En los dos: flechas, Re/Av Pág, Enter, Escape (cierra
    // y devuelve el foco al hueco) y Tab (cierra y sigue desde el hueco).
    var Selector = (function () {
        var pop = null;
        var buscador = null;
        var campo = null;
        var lista = null;
        var aviso = null;
        var abierto = null;
        var estado = null;
        var tAviso = null;
        var letras = '';
        var tLetras = null;

        function montar() {
            if (pop) return;
            pop = document.createElement('div');
            pop.className = 'admin-consulta-pop';
            pop.id = 'admin-consulta-pop';
            pop.hidden = true;
            pop.innerHTML =
                '<div class="admin-consulta-pop__buscar">' +
                    '<span class="admin-consulta-pop__lupa" aria-hidden="true">' + icono('buscar', 16) + '</span>' +
                    '<input type="text" class="admin-consulta-pop__campo" role="combobox" aria-autocomplete="list"' +
                    ' aria-expanded="true" aria-controls="admin-consulta-pop-lista" autocomplete="off"' +
                    ' autocapitalize="off" spellcheck="false" enterkeyhint="go">' +
                '</div>' +
                '<p class="admin-consulta-pop__estado" role="status"></p>' +
                '<div class="admin-consulta-pop__lista" id="admin-consulta-pop-lista" role="listbox" data-scrollable></div>';
            document.body.appendChild(pop);
            buscador = pop.querySelector('.admin-consulta-pop__buscar');
            campo = pop.querySelector('.admin-consulta-pop__campo');
            lista = pop.querySelector('.admin-consulta-pop__lista');
            aviso = pop.querySelector('.admin-consulta-pop__estado');

            campo.addEventListener('input', function () {
                if (!abierto) return;
                filtrar();
                reposicionar();
            });
            campo.addEventListener('keydown', function (e) { teclas(e, true); });
            lista.addEventListener('keydown', function (e) {
                if (abierto && !abierto.buscar) teclas(e, false);
            });
            // Que pulsar una opción no le robe el foco al buscador: si no, el
            // teclado virtual de la tablet se cierra y se vuelve a abrir.
            lista.addEventListener('mousedown', function (e) { e.preventDefault(); });
            lista.addEventListener('click', function (e) {
                var el = e.target.closest('[role="option"]');
                if (el) elegir(parseInt(el.getAttribute('data-k'), 10));
            });
            lista.addEventListener('mousemove', function (e) {
                var el = e.target.closest('[role="option"]');
                if (el) {
                    var k = parseInt(el.getAttribute('data-k'), 10);
                    if (k !== estado.activa) activar(k, false);
                }
            });

            document.addEventListener('pointerdown', function (e) {
                if (!abierto) return;
                if (pop.contains(e.target) || abierto.trigger.contains(e.target)) return;
                cerrar(false);
            }, true);
            window.addEventListener('resize', reposicionar);
            window.addEventListener('scroll', function (e) {
                if (!abierto || e.target === lista) return;
                reposicionar();
            }, true);
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', reposicionar);
            }
        }

        function abrir(cfg) {
            montar();
            if (abierto) cerrar(false);
            abierto = cfg;
            estado = { opciones: [], visibles: [], activa: -1, cargando: false, error: '', q: '' };

            buscador.hidden = !cfg.buscar;
            pop.classList.toggle('admin-consulta-pop--buscar', !!cfg.buscar);
            // Opciones con una descripción de verdad (las preguntas base del
            // Asistente): ancho fijo y el detalle en varios renglones; con el
            // ancho del contenido la lista crecía hasta media pantalla.
            pop.classList.toggle('admin-consulta-pop--envolver', !!cfg.ancho);
            lista.setAttribute('aria-label', cfg.titulo);
            if (cfg.buscar) {
                pop.setAttribute('role', 'dialog');
                pop.setAttribute('aria-label', 'Elegir ' + cfg.titulo.toLowerCase());
                campo.value = cfg.filtro || '';
                campo.placeholder = cfg.placeholder || 'Buscar…';
                campo.setAttribute('aria-label', 'Buscar ' + cfg.titulo.toLowerCase());
                lista.removeAttribute('tabindex');
            } else {
                pop.removeAttribute('role');
                pop.removeAttribute('aria-label');
                lista.setAttribute('tabindex', '-1');
            }
            cfg.trigger.setAttribute('aria-expanded', 'true');
            cfg.trigger.setAttribute('aria-controls', cfg.buscar ? pop.id : lista.id);
            pop.hidden = false;
            pop.classList.add('is-open');

            if (cfg.opciones && typeof cfg.opciones.then === 'function') {
                estado.cargando = true;
                pintar();
                avisar('');
                cfg.opciones.then(function (ops) {
                    if (abierto !== cfg) return;
                    estado.cargando = false;
                    cargar(ops || []);
                }, function () {
                    if (abierto !== cfg) return;
                    estado.cargando = false;
                    estado.error = 'No se pudo cargar la lista. Ciérrala y vuelve a abrirla.';
                    pintar();
                    avisar('');
                    reposicionar();
                });
            } else {
                cargar(cfg.opciones || []);
            }
            reposicionar();
            (cfg.buscar ? campo : lista).focus({ preventScroll: true });
        }

        function cargar(ops) {
            var grupos = {};
            var n = 0;
            estado.opciones = ops.map(function (op) {
                var g = op.grupo || '';
                if (!(g in grupos)) grupos[g] = n++;
                return mezclar(op, { _n: normalizar(op.etiqueta), _nd: normalizar(op.detalle), _g: grupos[g] });
            });
            filtrar();
            reposicionar();
            // Otra vez, ya con la altura de la lista medida contra la pantalla.
            centrarActiva();
        }

        // Al abrir (o al vaciar el buscador) la opción vigente va al CENTRO
        // de la lista, no pegada al borde: así se ven sus vecinas de los dos
        // lados, y la primera fila visible no queda cortada bajo el buscador.
        function centrarActiva() {
            var el = estado.activa >= 0 ? document.getElementById('admin-consulta-pop-op' + estado.activa) : null;
            if (!el) return;
            lista.scrollTop = Math.max(0, el.offsetTop - (lista.clientHeight - el.offsetHeight) / 2);
        }

        // Todas las palabras tienen que aparecer en el nombre o en el
        // detalle («leche» encuentra los lattes por su categoría, pero detrás de
        // los que la llevan en el nombre). Gana lo que EMPIEZA por lo escrito.
        function puntuar(op, q, palabras) {
            var todo = op._n + ' ' + op._nd;
            for (var i = 0; i < palabras.length; i++) {
                if (todo.indexOf(palabras[i]) === -1) return 0;
            }
            for (var j = 0; j < palabras.length; j++) {
                if (op._n.indexOf(palabras[j]) === -1) return 1;
            }
            if (op._n.indexOf(q) === 0) return 4;
            if ((' ' + op._n).indexOf(' ' + palabras[0]) !== -1) return 3;
            return 2;
        }

        function filtrar() {
            var crudo = abierto.buscar ? campo.value.replace(/\s+/g, ' ').trim() : '';
            var q = normalizar(crudo);
            var palabras = q ? q.split(' ') : [];
            var visibles = [];
            estado.q = q;
            estado.opciones.forEach(function (op, i) {
                var nota = palabras.length ? puntuar(op, q, palabras) : 1;
                if (nota > 0) visibles.push({ op: op, nota: nota, i: i });
            });
            if (palabras.length) {
                visibles.sort(function (a, b) {
                    return (a.op._g - b.op._g) || (b.nota - a.nota) || (a.i - b.i);
                });
            }
            // Si nada coincide, se dice (ver avisar) y no se ofrece nada más:
            // hubo un «Preguntar por «…»» que mandaba lo tecleado al
            // intérprete, y era texto libre por la puerta de atrás.
            estado.visibles = visibles;

            var activa = 0;
            if (!palabras.length) {
                for (var k = 0; k < visibles.length; k++) {
                    if (visibles[k].op.valor === abierto.valor) {
                        activa = k;
                        break;
                    }
                }
            }
            pintar();
            activar(visibles.length ? activa : -1, !!palabras.length);
            if (!palabras.length) centrarActiva();
            avisar(crudo);
        }

        function resaltar(etiqueta, q) {
            var texto = String(etiqueta || '');
            if (!q) return esc(texto);
            var pos = normalizar(texto).indexOf(q);
            if (pos === -1) return esc(texto);
            return esc(texto.slice(0, pos)) + '<mark>' + esc(texto.slice(pos, pos + q.length)) + '</mark>' + esc(texto.slice(pos + q.length));
        }

        function pintar() {
            var html = '';
            var grupo = null;
            var abiertoGrupo = false;
            estado.visibles.forEach(function (v, k) {
                var op = v.op;
                var g = op.grupo || '';
                if (g !== grupo) {
                    if (abiertoGrupo) html += '</div>';
                    abiertoGrupo = false;
                    grupo = g;
                    if (g) {
                        var gid = 'admin-consulta-pop-g' + k;
                        html += '<div class="admin-consulta-pop__grupo" role="group" aria-labelledby="' + gid + '">' +
                            '<div class="admin-consulta-pop__grupo-titulo" id="' + gid + '" role="presentation">' + esc(g) + '</div>';
                        abiertoGrupo = true;
                    }
                }
                var sel = op.valor === abierto.valor;
                html += '<div class="admin-consulta-pop__op"' +
                    ' role="option" id="admin-consulta-pop-op' + k + '" data-k="' + k + '" aria-selected="' + (sel ? 'true' : 'false') + '">' +
                    // Un icono propio por opción (las preguntas base del
                    // Asistente traen el de su tipo). Ya viene como SVG del
                    // catálogo de iconos del servidor.
                    (op.icono ? '<span class="admin-consulta-pop__op-icono" aria-hidden="true">' + op.icono + '</span>' : '') +
                    '<span class="admin-consulta-pop__op-texto">' +
                        '<span class="admin-consulta-pop__op-nombre">' + resaltar(op.etiqueta, estado.q) + '</span>' +
                        (op.detalle ? '<span class="admin-consulta-pop__op-detalle">' + esc(op.detalle) + '</span>' : '') +
                    '</span>' +
                    (sel ? '<span class="admin-consulta-pop__op-check" aria-hidden="true">' + icono('check', 16) + '</span>' : '') +
                    '</div>';
            });
            if (abiertoGrupo) html += '</div>';
            lista.innerHTML = html;
            lista.hidden = !estado.visibles.length;
        }

        // El estado visible (cargando, error, nada coincide) y, para el
        // lector, cuántos resultados quedan tras teclear: con espera, para no
        // dictar un número por letra.
        function avisar(crudo) {
            window.clearTimeout(tAviso);
            aviso.classList.remove('is-visible');
            if (estado.cargando) {
                aviso.textContent = 'Cargando la lista…';
                aviso.classList.add('is-visible');
                return;
            }
            if (estado.error) {
                aviso.textContent = estado.error;
                aviso.classList.add('is-visible');
                return;
            }
            var reales = estado.visibles.length;
            if (crudo && !reales) {
                aviso.textContent = 'Nada en la lista coincide con «' + crudo + '».';
                aviso.classList.add('is-visible');
                return;
            }
            if (!crudo) {
                aviso.textContent = '';
                return;
            }
            tAviso = window.setTimeout(function () {
                aviso.textContent = reales === 1 ? '1 resultado' : reales + ' resultados';
            }, 450);
        }

        function activar(k, desplazar) {
            var previa = lista.querySelector('.is-activa');
            if (previa) previa.classList.remove('is-activa');
            estado.activa = k;
            var duenio = abierto.buscar ? campo : lista;
            var el = k >= 0 ? document.getElementById('admin-consulta-pop-op' + k) : null;
            if (!el) {
                duenio.removeAttribute('aria-activedescendant');
                return;
            }
            el.classList.add('is-activa');
            duenio.setAttribute('aria-activedescendant', el.id);
            if (desplazar) asegurarVisible(el);
        }

        // A mano y no con scrollIntoView: éste también desplaza la página si
        // le parece, y la lista vive en un panel fijo.
        function asegurarVisible(el) {
            var arriba = el.offsetTop;
            var previo = el.previousElementSibling;
            if (previo && previo.classList.contains('admin-consulta-pop__grupo-titulo')) {
                arriba = previo.offsetTop;
            }
            var abajo = el.offsetTop + el.offsetHeight;
            if (arriba < lista.scrollTop) {
                lista.scrollTop = arriba - 4;
            } else if (abajo > lista.scrollTop + lista.clientHeight) {
                lista.scrollTop = abajo - lista.clientHeight + 4;
            }
        }

        function mover(delta, envolver) {
            var n = estado.visibles.length;
            if (!n) return;
            var k = estado.activa < 0 ? (delta > 0 ? -1 : n) : estado.activa;
            k += delta;
            if (envolver) {
                k = (k + n) % n;
            } else {
                k = Math.max(0, Math.min(n - 1, k));
            }
            activar(k, true);
        }

        // Teclear con la lista enfocada salta a la opción que empiece así. Una
        // letra sola busca desde la SIGUIENTE (repetirla recorre las que
        // empiezan igual); varias seguidas, desde la actual.
        function porLetra(letra) {
            window.clearTimeout(tLetras);
            letras += normalizar(letra);
            tLetras = window.setTimeout(function () { letras = ''; }, 600);
            var n = estado.visibles.length;
            var inicio = letras.length === 1 ? estado.activa + 1 : Math.max(estado.activa, 0);
            for (var d = 0; d < n; d++) {
                var k = (inicio + d) % n;
                if (estado.visibles[k].op._n.indexOf(letras) === 0) {
                    activar(k, true);
                    return;
                }
            }
        }

        function teclas(e, enCampo) {
            if (!abierto || e.isComposing) return;
            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    mover(1, true);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (e.altKey) {
                        cerrar(true);
                        return;
                    }
                    mover(-1, true);
                    break;
                case 'PageDown':
                    e.preventDefault();
                    mover(8, false);
                    break;
                case 'PageUp':
                    e.preventDefault();
                    mover(-8, false);
                    break;
                case 'Home':
                case 'End':
                    // En el buscador, Inicio y Fin mueven el cursor del texto.
                    if (enCampo) return;
                    e.preventDefault();
                    activar(e.key === 'Home' ? 0 : estado.visibles.length - 1, true);
                    break;
                case 'Enter':
                    e.preventDefault();
                    if (estado.activa >= 0) elegir(estado.activa);
                    break;
                case ' ':
                    if (enCampo) return;
                    e.preventDefault();
                    if (estado.activa >= 0) elegir(estado.activa);
                    break;
                case 'Escape':
                    e.preventDefault();
                    e.stopPropagation();
                    cerrar(true);
                    break;
                case 'Tab':
                    // Sin preventDefault: el foco vuelve al hueco y el Tab
                    // sigue desde ahí, que es donde el usuario lo esperaba.
                    cerrar(true);
                    break;
                default:
                    if (!enCampo && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                        porLetra(e.key);
                    }
            }
        }

        function elegir(k) {
            var v = estado.visibles[k];
            if (!v) return;
            var cfg = abierto;
            var op = v.op;
            cerrar(true);
            if (op.valor === cfg.valor) return;
            cfg.alElegir(op);
        }

        function cerrar(devolverFoco) {
            if (!abierto) return;
            var cfg = abierto;
            abierto = null;
            window.clearTimeout(tAviso);
            pop.hidden = true;
            pop.classList.remove('is-open', 'is-arriba');
            lista.innerHTML = '';
            aviso.textContent = '';
            aviso.classList.remove('is-visible');
            campo.removeAttribute('aria-activedescendant');
            lista.removeAttribute('aria-activedescendant');
            cfg.trigger.setAttribute('aria-expanded', 'false');
            cfg.trigger.removeAttribute('aria-controls');
            if (devolverFoco && document.body.contains(cfg.trigger)) {
                cfg.trigger.focus({ preventScroll: true });
            }
        }

        // Debajo del hueco si cabe; si no, encima. La altura de la lista se
        // mide contra lo que queda de pantalla —el viewport VISUAL, que en la
        // tablet encoge cuando sale el teclado—.
        function reposicionar() {
            if (!abierto) return;
            var t = abierto.trigger;
            if (!document.body.contains(t)) {
                cerrar(false);
                return;
            }
            var r = t.getBoundingClientRect();
            var vv = window.visualViewport;
            var limite = vv ? Math.min(window.innerHeight, vv.offsetTop + vv.height) : window.innerHeight;
            if (r.bottom < 0 || r.top > limite) {
                cerrar(false);
                return;
            }
            var ancho = document.documentElement.clientWidth;
            var margen = 8;
            var anchoFijo = abierto.buscar ? 360 : (abierto.ancho || 0);
            pop.style.width = anchoFijo ? Math.min(anchoFijo, ancho - margen * 2) + 'px' : '';
            pop.style.minWidth = anchoFijo ? '' : Math.max(r.width, 180) + 'px';

            var abajo = limite - r.bottom - margen - 6;
            var arriba = r.top - margen - 6;
            var haciaAbajo = abajo >= 240 || abajo >= arriba;
            var espacio = haciaAbajo ? abajo : arriba;
            var fijo = (abierto.buscar ? buscador.offsetHeight : 0) +
                (aviso.classList.contains('is-visible') ? aviso.offsetHeight : 0) + 14;
            lista.style.maxHeight = Math.max(120, Math.min(340, espacio - fijo)) + 'px';

            var w = pop.offsetWidth;
            var h = pop.offsetHeight;
            pop.style.left = Math.max(margen, Math.min(r.left, ancho - w - margen)) + 'px';
            pop.style.top = (haciaAbajo ? r.bottom + 6 : Math.max(margen, r.top - 6 - h)) + 'px';
            pop.classList.toggle('is-arriba', !haciaAbajo);
        }

        return {
            abrir: abrir,
            cerrar: cerrar,
            abiertoPara: function (trigger) { return !!abierto && abierto.trigger === trigger; },
            dentroDe: function (el) { return !!abierto && el.contains(abierto.trigger); },
            contiene: function (el) { return !!pop && pop.contains(el); }
        };
    })();

    // ── Tarjeta ─────────────────────────────────────────────────

    var VERBO = {
        stock: 'Ver existencia de ',
        consumo: 'Ver consumo de ',
        ventas_producto: 'Ver ventas de ',
        ventas_categoria: 'Ver ventas de '
    };

    function etiquetaIr(consulta, nombre) {
        return (consulta && VERBO[consulta.intencion] ? VERBO[consulta.intencion] : 'Consultar ') + nombre;
    }

    function plantillaHtml(partes) {
        var html = '<div class="admin-consulta__plantilla" role="group" aria-label="Consulta interpretada">';
        partes.forEach(function (p, i) {
            if (p.tipo === 'hueco') {
                html += '<button type="button" class="admin-consulta__hueco"' +
                    ' data-hueco="' + i + '" data-hueco-campo="' + esc(p.campo) + '"' +
                    ' aria-haspopup="' + (p.catalogo ? 'dialog' : 'listbox') + '" aria-expanded="false"' +
                    // El valor visible primero y el rótulo después: el lector
                    // dice lo mismo que se ve, y luego qué es.
                    ' aria-label="' + esc(p.etiqueta + ', ' + String(p.rotulo).toLowerCase()) + '">' +
                    '<span class="admin-consulta__hueco-valor">' + esc(p.etiqueta) + '</span>' +
                    '<span class="admin-consulta__hueco-chevron" aria-hidden="true">' + icono('chevron-abajo', 14) + '</span>' +
                    '</button>';
            } else if (p.texto === '·') {
                html += '<span class="admin-consulta__plantilla-sep" aria-hidden="true">·</span>';
            } else {
                html += '<span class="admin-consulta__plantilla-texto">' + esc(p.texto) + '</span>';
            }
        });
        return html + '</div>';
    }

    /**
     * ¿El título ya está dicho en la frase con huecos? «Cappuccino» bajo
     * «Ventas de [Cappuccino ▾] · [Ayer ▾]», «Lo más vendido» bajo «Lo más
     * vendido · [Esta semana ▾]» o «Canela» bajo «[Canela ▾] en almacén»: el
     * nombre salía dos veces a un palmo. Cuenta como dicho si cada palabra del
     * título aparece en la plantilla.
     */
    function tituloRepetido(titulo, plantilla) {
        if (!titulo || !plantilla || !plantilla.length) return false;
        var dicho = ' ' + normalizar(plantilla.map(function (p) {
            return p.tipo === 'hueco' ? p.etiqueta : p.texto;
        }).join(' ')).replace(/[^a-z0-9]+/g, ' ') + ' ';
        var palabras = normalizar(titulo).replace(/[^a-z0-9]+/g, ' ').trim().split(' ');
        for (var i = 0; i < palabras.length; i++) {
            if (palabras[i] && dicho.indexOf(' ' + palabras[i] + ' ') === -1) return false;
        }
        return true;
    }

    function tarjeta(datos, o) {
        var r = datos.respuesta;
        var idTitulo = o.id + '-titulo';
        var nivel = o.nivelTitulo >= 2 && o.nivelTitulo <= 6 ? o.nivelTitulo : 3;
        var conCifra = r.cifra !== undefined && r.cifra !== null && r.cifra !== '';
        // Repetido, el título se queda sólo para el lector de pantalla: sigue
        // siendo el nombre de la tarjeta (aria-labelledby) y el encabezado por
        // el que se navega.
        var claseTitulo = 'admin-consulta__titulo' + (tituloRepetido(r.titulo, datos.plantilla) ? ' admin-visually-hidden' : '');
        var html = '<article class="admin-consulta__card admin-consulta__card--' + esc(r.tipo) + (o.primera ? ' is-entrando' : '') + '"' +
            ' aria-labelledby="' + idTitulo + '">';

        if (datos.plantilla && datos.plantilla.length) {
            html += '<header class="admin-consulta__head">' + plantillaHtml(datos.plantilla) + '</header>';
        }

        html += '<div class="admin-consulta__cuerpo' + (conCifra ? '' : ' admin-consulta__cuerpo--sin-cifra') + '">';
        if (conCifra) {
            html += '<div class="admin-consulta__dato' + (r.estado ? ' is-' + esc(r.estado) : '') + '">' +
                '<span class="admin-consulta__cifra admin-num">' + esc(r.cifra) + '</span>' +
                (r.unidad ? '<span class="admin-consulta__unidad">' + esc(r.unidad) + '</span>' : '') +
                '</div>';
        }
        html += '<div class="admin-consulta__texto">' +
            '<h' + nivel + ' class="' + claseTitulo + '" id="' + idTitulo + '">' + esc(r.titulo) + '</h' + nivel + '>' +
            '<p class="admin-consulta__frase">' + esc(r.frase) + '</p>';
        if (r.comparacion) {
            var t = r.comparacion.tendencia;
            // La flecha dice la dirección y el color lo que significa: más
            // cancelaciones SUBEN y son malas. Sin `lectura` (respuestas
            // guardadas antes de que existiera) se lee como antes: subir, bien.
            var lectura = r.comparacion.lectura || (t === 'sube' ? 'bien' : (t === 'baja' ? 'mal' : 'igual'));
            html += '<p class="admin-consulta__comparacion is-' + esc(t) + ' is-' + esc(lectura) + '">' +
                (t === 'sube' ? icono('sube', 13) : (t === 'baja' ? icono('baja', 13) : '')) +
                '<span>' + esc(r.comparacion.texto) + '</span></p>';
        }
        html += '</div></div>';

        if (r.detalle && r.detalle.length) {
            var max = 0;
            r.detalle.forEach(function (d) { if (typeof d.valor === 'number' && d.valor > max) max = d.valor; });
            html += '<div class="admin-consulta__detalle">' +
                (r.detalle_titulo ? '<p class="admin-consulta__detalle-titulo">' + esc(r.detalle_titulo) + '</p>' : '') +
                '<ul class="admin-consulta__lista">';
            r.detalle.forEach(function (d) {
                var numerico = typeof d.valor === 'number';
                var ancho = numerico && max > 0 ? Math.max(4, Math.round((d.valor / max) * 100)) : 0;
                // `valor` numérico mide la barra; `valor_texto` es cómo se
                // lee («$12,340.00 · 34 tickets»). Mono sólo si es una cifra:
                // la categoría de un platillo sin venta es una palabra.
                var mostrado = d.valor_texto !== undefined && d.valor_texto !== null ? d.valor_texto : d.valor;
                var esCifra = /\d/.test(String(mostrado));
                var etiqueta = d.consulta
                    ? '<button type="button" class="admin-consulta__item-enlace" data-consulta-ir="' + esc(JSON.stringify(d.consulta)) + '" data-origen="detalle"' +
                        ' aria-label="' + esc(etiquetaIr(d.consulta, d.etiqueta)) + '">' + esc(d.etiqueta) + '</button>'
                    : '<span>' + esc(d.etiqueta) + '</span>';
                html += '<li class="admin-consulta__item">' + etiqueta +
                    '<span class="admin-consulta__item-valor' + (esCifra ? ' admin-num' : ' admin-consulta__item-valor--texto') + '">' + esc(mostrado) + '</span>' +
                    (numerico ? '<span class="admin-consulta__barra-dato" aria-hidden="true"><span style="width:' + ancho + '%"></span></span>' : '') +
                    '</li>';
            });
            html += '</ul></div>';
        }

        var pie = '';
        var alternativas = (datos.alternativas || []).filter(function (a) { return a && a.consulta; });
        if (alternativas.length) {
            pie += '<div class="admin-consulta__alternativas"><span>También:</span>';
            alternativas.forEach(function (a) {
                pie += '<button type="button" class="admin-consulta__alternativa"' +
                    ' data-consulta-ir="' + esc(JSON.stringify(a.consulta)) + '" data-origen="alternativa"' +
                    ' aria-label="' + esc(etiquetaIr(a.consulta, a.nombre)) + '">' +
                    icono(a.tipo === 'stock' ? 'inventario' : 'tickets', 13) + '<span>' + esc(a.nombre) + '</span></button>';
            });
            pie += '</div>';
        }
        if (datos.entendida === false && datos.sugerencias && datos.sugerencias.length) {
            var parecidos = datos.sugerencias.filter(function (s) { return s.motivo === 'parecido'; });
            var lista = (parecidos.length ? parecidos : datos.sugerencias).slice(0, 3);
            pie += '<div class="admin-consulta__alternativas"><span>' + (parecidos.length ? '¿Buscabas…?' : 'Prueba:') + '</span>';
            lista.forEach(function (s) {
                pie += '<button type="button" class="admin-consulta__alternativa"' +
                    ' data-consulta-ir="' + esc(JSON.stringify(s.consulta)) + '" data-origen="sugerencia"' +
                    (s.motivo === 'parecido' ? ' aria-label="' + esc(s.texto) + '"' : '') + '>' +
                    icono(s.tipo === 'stock' ? 'inventario' : 'tickets', 13) + '<span>' + esc(s.etiqueta) + '</span></button>';
            });
            pie += '</div>';
        }
        if (r.enlace && o.enlace !== false) {
            pie += '<a class="admin-consulta__enlace" href="' + esc(r.enlace.href) + '">' + esc(r.enlace.texto) + icono('flecha-derecha', 14) + '</a>';
        }
        if (pie) html += '<footer class="admin-consulta__pie">' + pie + '</footer>';
        return html + '</article>';
    }

    // `role="alert"` sólo cuando nadie más lo va a decir: con una región viva
    // el error se oía dos veces; un anfitrión con `anunciar: false` (el
    // Asistente) no anuncia los errores de un hueco, y ahí es la única voz.
    function tarjetaError(texto, conAlerta) {
        return '<div class="admin-consulta__card admin-consulta__card--error"' + (conAlerta ? ' role="alert"' : '') + '>' +
            '<p class="admin-consulta__frase">' + esc(texto) + '</p></div>';
    }

    // Región viva compartida por los resultados que no traen la suya
    // (`anunciar: true`).
    var anuncioGlobal = null;
    function anunciarGlobal(texto) {
        if (!anuncioGlobal) {
            anuncioGlobal = document.createElement('p');
            anuncioGlobal.className = 'admin-visually-hidden';
            anuncioGlobal.setAttribute('aria-live', 'polite');
            document.body.appendChild(anuncioGlobal);
        }
        anuncioGlobal.textContent = texto || '';
    }

    // ── Resultado: la tarjeta con huecos, en cualquier contenedor ──

    /**
     * @param {Element} contenedor  Donde se pinta la tarjeta (se reemplaza su contenido).
     * @param {Object}  opciones
     *   ambito         'ventas' | 'productos' | 'todo' (desempata las frases).
     *   alCambiar      fn(consulta, info) al elegir un hueco, un renglón o una
     *                  sugerencia. Devolver false impide la reconsulta: el
     *                  anfitrión la hace a su manera (p. ej. un mensaje nuevo).
     *   alPintar       fn(datos|null, error|null, info) tras pintar.
     *   alOcupado      fn(bool) al empezar y acabar una petición.
     *   alLimpiar      fn() al vaciar.
     *   anunciar       true (región viva propia) | false | fn(texto).
     *   enlace         false para no pintar el enlace al módulo.
     *   nivelTitulo    2-6, el nivel del encabezado de la respuesta (3).
     */
    function Resultado(contenedor, opciones) {
        if (!(this instanceof Resultado)) return new Resultado(contenedor, opciones);
        var self = this;
        this.contenedor = contenedor;
        this.opciones = opciones || {};
        this.datos = null;
        this.clave = '';
        this.peticion = null;
        this.info = null;
        this.focoTras = '';
        this.id = 'admin-consulta-r' + (++uid);
        this._clic = function (e) { self._alClic(e); };
        this._tecla = function (e) { self._alTeclear(e); };
        contenedor.addEventListener('click', this._clic);
        contenedor.addEventListener('keydown', this._tecla);
    }

    Resultado.prototype.consultar = function (consulta, info) {
        if (typeof consulta === 'string') consulta = { q: consulta };
        info = info || {};
        var clave = JSON.stringify(consulta);
        if (!info.forzar && clave === this.clave && this.datos) return Promise.resolve(this.datos);
        this.clave = clave;
        this.info = info;
        if (this.peticion) this.peticion.abort();
        var ctrl = new AbortController();
        this.peticion = ctrl;
        this._ocupado(true);
        var self = this;
        return consultar(consulta, { ambito: this.opciones.ambito, signal: ctrl.signal }).then(function (datos) {
            if (self.peticion !== ctrl) return datos;
            self.peticion = null;
            self._ocupado(false);
            if (!datos.ok) {
                self.error(datos.mensaje || 'No fue posible responder la consulta.');
            } else {
                self.pintar(datos);
            }
            return datos;
        }, function (e) {
            if ((e && e.name === 'AbortError') || self.peticion !== ctrl) return null;
            self.peticion = null;
            self._ocupado(false);
            self.error('Sin conexión con el servidor. Vuelve a intentarlo.');
            return null;
        });
    };

    Resultado.prototype.pintar = function (datos) {
        var c = this.contenedor;
        if (Selector.dentroDe(c)) Selector.cerrar(false);
        if (!datos || !datos.respuesta) {
            this.limpiar();
            return;
        }
        var primera = !c.querySelector('.admin-consulta__card');
        this.datos = datos;
        c.innerHTML = tarjeta(datos, {
            id: this.id,
            primera: primera,
            enlace: this.opciones.enlace,
            nivelTitulo: this.opciones.nivelTitulo
        });
        if (this.opciones.alPintar) this.opciones.alPintar(datos, null, this.info || {});
        emitir(c, 'consulta-rapida:respuesta', { datos: datos, error: null });

        var r = datos.respuesta;
        this._anunciar([r.titulo, r.frase].filter(Boolean).join('. '));

        // El hueco que se usó ya no existe tras repintar: sin esto, el foco
        // caía al <body> y con teclado había que volver desde arriba.
        if (this.focoTras) {
            var destino = c.querySelector(this.focoTras);
            this.focoTras = '';
            if (destino) destino.focus({ preventScroll: true });
        }

        // El primer hueco de entidad se abre al instante si la lista ya está.
        var conCatalogo = (datos.plantilla || []).some(function (p) { return !!p.catalogo; });
        if (conCatalogo) window.setTimeout(function () { catalogo().catch(function () {}); }, 300);
    };

    Resultado.prototype.error = function (texto) {
        var c = this.contenedor;
        if (Selector.dentroDe(c)) Selector.cerrar(false);
        this.datos = null;
        this.clave = '';
        c.innerHTML = tarjetaError(texto, this.opciones.anunciar === false);
        if (this.opciones.alPintar) this.opciones.alPintar(null, texto, this.info || {});
        emitir(c, 'consulta-rapida:respuesta', { datos: null, error: texto });
        this._anunciar(texto);
    };

    Resultado.prototype.limpiar = function () {
        if (this.peticion) this.peticion.abort();
        this.peticion = null;
        this._ocupado(false);
        if (Selector.dentroDe(this.contenedor)) Selector.cerrar(false);
        this.contenedor.innerHTML = '';
        this.datos = null;
        this.clave = '';
        this.focoTras = '';
        this._anunciar('');
        if (this.opciones.alLimpiar) this.opciones.alLimpiar();
    };

    Resultado.prototype.destruir = function () {
        this.limpiar();
        this.contenedor.removeEventListener('click', this._clic);
        this.contenedor.removeEventListener('keydown', this._tecla);
    };

    Resultado.prototype.ultima = function () {
        return this.datos;
    };

    /**
     * Pide otra consulta a partir de un hueco, un renglón o una sugerencia.
     * Avisa antes con `consulta-rapida:cambio` (cancelable) y `alCambiar`.
     */
    Resultado.prototype.cambiar = function (consulta, origen, foco) {
        var info = { consulta: consulta, origen: origen, anterior: this.datos };
        var seguir = emitir(this.contenedor, 'consulta-rapida:cambio', info, true);
        if (seguir && this.opciones.alCambiar && this.opciones.alCambiar(consulta, info) === false) seguir = false;
        if (!seguir) return null;
        this.focoTras = foco || '';
        return this.consultar(consulta, { origen: origen });
    };

    // Lo que se está viendo, como consulta estructurada: la base sobre la
    // que se aplica lo que cambie un hueco.
    Resultado.prototype._base = function () {
        var d = this.datos || {};
        var i = d.interpretacion || {};
        return {
            intencion: i.intencion || null,
            id: i.id === undefined ? null : i.id,
            opcion: i.opcion === undefined ? null : i.opcion,
            periodo: i.periodo || (d.periodo && d.periodo.clave) || null
        };
    };

    Resultado.prototype._ocupado = function (si) {
        if (si) {
            this.contenedor.setAttribute('aria-busy', 'true');
        } else {
            this.contenedor.removeAttribute('aria-busy');
        }
        if (this.opciones.alOcupado) this.opciones.alOcupado(si);
    };

    Resultado.prototype._anunciar = function (texto) {
        var a = this.opciones.anunciar;
        if (typeof a === 'function') {
            a(texto);
        } else if (a !== false) {
            anunciarGlobal(texto);
        }
    };

    Resultado.prototype._alClic = function (e) {
        var hueco = e.target.closest('[data-hueco]');
        if (hueco && this.contenedor.contains(hueco)) {
            this._abrirHueco(hueco);
            return;
        }
        var ir = e.target.closest('[data-consulta-ir]');
        if (ir && this.contenedor.contains(ir)) {
            var patch = null;
            try {
                patch = JSON.parse(ir.getAttribute('data-consulta-ir'));
            } catch (err) {
                return;
            }
            this.cambiar(mezclar(this._base(), { opcion: null }, patch), ir.getAttribute('data-origen') || 'detalle', '[data-hueco-campo="entidad"]');
        }
    };

    // Teclado sobre un hueco, además de Enter y Espacio (que ya son del
    // botón): flecha abajo lo abre, como un <select>, y con buscador una letra
    // lo abre ya filtrada por ella: «Tab, lec» en vez de «Tab, Enter, lec».
    Resultado.prototype._alTeclear = function (e) {
        var hueco = e.target.closest && e.target.closest('[data-hueco]');
        if (!hueco || !this.contenedor.contains(hueco) || e.isComposing) return;
        var tecla = teclaDeHueco(e, hueco);
        if (tecla === null) return;
        e.preventDefault();
        this._abrirHueco(hueco, tecla);
    };

    /**
     * Qué hace una tecla sobre un hueco: null si nada (que siga su curso), ''
     * si lo abre (flecha abajo, con o sin Alt) o la letra con la que se abre
     * filtrado (sólo los huecos con buscador). La comparten la tarjeta y el
     * compositor del Asistente, para que un hueco responda igual en los dos.
     */
    function teclaDeHueco(e, hueco) {
        if (e.ctrlKey || e.metaKey) return null;
        if (e.key === 'ArrowDown') return Selector.abiertoPara(hueco) ? null : '';
        if (e.altKey || e.key.length !== 1 || e.key === ' ') return null;
        return hueco.getAttribute('aria-haspopup') === 'dialog' ? e.key : null;
    }

    Resultado.prototype._abrirHueco = function (boton, filtro) {
        if (Selector.abiertoPara(boton)) {
            Selector.cerrar(true);
            return;
        }
        var parte = this.datos && this.datos.plantilla ? this.datos.plantilla[parseInt(boton.getAttribute('data-hueco'), 10)] : null;
        if (!parte) return;
        var self = this;
        // Por campo y no por posición: pasar de «todo el restaurante» a un
        // platillo con opciones añade un hueco y corre a los demás.
        var foco = '[data-hueco-campo="' + parte.campo + '"]';
        elegir(boton, parte, function (op) {
            self.cambiar(mezclar(self._base(), consultaDeOpcion(parte, op)), 'hueco', foco);
        }, filtro);
    };

    /**
     * Lo que una opción elegida le cambia a la consulta. Un hueco puede fijar
     * su intención: las opciones de la lista de ingredientes traen la consulta
     * de existencia, y en «Consumo de [Leche ▾]» elegir otro ingrediente tiene
     * que seguir preguntando por el consumo.
     */
    function consultaDeOpcion(parte, op) {
        var consulta = mezclar(op && op.consulta);
        if (parte && parte.intencion && consulta.intencion) consulta.intencion = parte.intencion;
        return consulta;
    }

    /**
     * El selector de un hueco, con o sin tarjeta: el compositor del Asistente
     * arma la pregunta y elige cada variable con este mismo selector
     * (buscador, teclado, lector), sin consultar nada hasta que se envía.
     *
     * `parte` tiene la forma de un hueco de `plantilla`: {rotulo, valor,
     * catalogo?: 'ventas'|'ingredientes', opciones?: [...], placeholder?,
     * buscar?, ancho? (px fijos: el detalle pasa a varios renglones)}. Las
     * opciones pueden traer `icono` (SVG del catálogo del
     * servidor). `alElegir(op)` recibe la opción elegida, con su `consulta`;
     * no se llama si se elige la que ya estaba. Pulsar otra vez el mismo botón
     * con el selector abierto lo cierra.
     */
    function elegir(boton, parte, alElegir, filtro) {
        if (!boton || !parte) return;
        if (Selector.abiertoPara(boton)) {
            Selector.cerrar(true);
            return;
        }
        var opciones = parte.catalogo
            ? catalogo().then(function (c) { return c[parte.catalogo] || []; })
            : (parte.opciones || []);
        var buscar = parte.buscar !== undefined
            ? !!parte.buscar
            : !!parte.catalogo || (parte.opciones && parte.opciones.length > 12);
        Selector.abrir({
            trigger: boton,
            titulo: parte.rotulo || 'Opción',
            buscar: buscar,
            ancho: parte.ancho || 0,
            placeholder: parte.placeholder || (parte.catalogo === 'ingredientes' ? 'Buscar ingrediente…' : 'Buscar platillo o categoría…'),
            valor: parte.valor,
            opciones: opciones,
            filtro: filtro || '',
            alElegir: function (op) { if (alElegir) alElegir(op); }
        });
    }

    /**
     * API para el Asistente (y para cualquier otro módulo que quiera montar una
     * respuesta con huecos):
     *
     *   ConsultasRapidas.consultar(consulta, {ambito, signal}) → Promise<datos>
     *     consulta: {intencion, id, opcion, periodo} (o {q} para lo guardado
     *     antes de retirar el texto libre)
     *   ConsultasRapidas.montar(contenedor, opciones) → Resultado
     *     .consultar(consulta) .pintar(datos) .limpiar() .destruir() .ultima()
     *     Eventos en el contenedor (burbujean):
     *       consulta-rapida:cambio     detail {consulta, origen, anterior}; cancelable
     *       consulta-rapida:respuesta  detail {datos, error}
     *   ConsultasRapidas.capacidades() → Promise<{intenciones, periodos}>
     *   ConsultasRapidas.catalogo()    → Promise<{ventas, ingredientes}>
     *   ConsultasRapidas.elegir(boton, parte, alElegir(op), filtro?) → abre el
     *     selector de un hueco sobre `boton` sin tarjeta ni consulta
     *   ConsultasRapidas.consultaDeOpcion(parte, op) → lo que la opción le
     *     cambia a la consulta, con la intención fija del hueco si la tiene
     *   ConsultasRapidas.teclaDeHueco(evento, hueco) → null | '' | letra
     *   ConsultasRapidas.selectorAbierto(boton?) → si el selector está abierto
     *     (para ese botón, si se da)
     *   ConsultasRapidas.cerrarSelector()
     */
    window.ConsultasRapidas = {
        consultar: consultar,
        montar: function (contenedor, opciones) { return new Resultado(contenedor, opciones); },
        capacidades: capacidades,
        catalogo: catalogo,
        elegir: elegir,
        consultaDeOpcion: consultaDeOpcion,
        teclaDeHueco: teclaDeHueco,
        selectorAbierto: function (boton) {
            return boton ? Selector.abiertoPara(boton) : Selector.contiene(document.activeElement);
        },
        cerrarSelector: function () { Selector.cerrar(false); }
    };
}(window, document));
