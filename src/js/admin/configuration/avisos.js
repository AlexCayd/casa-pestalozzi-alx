/**
 * Configuración → Avisos de la carta (views/admin/configuration/avisos.php).
 *
 * La pantalla funciona sin esto: el contenido se escribe con sus marcas y
 * cada acción es un POST. Aquí se suma lo que hace cómodo escribir un aviso:
 *   · la barra de formato (Subtítulo, Negritas) y sus atajos —Ctrl/⌘+B,
 *     Ctrl/⌘+Mayús+2 y Ctrl/⌘+Enter para guardar—. Cada botón alterna: sobre
 *     un subtítulo lo quita, sobre unas negritas las quita. Escribe con
 *     execCommand('insertText') para que Ctrl+Z deshaga el formato;
 *   · la vista previa en vivo, con las MISMAS reglas que
 *     Services\Configuration\AvisosCarta::bloques() (si cambian allí, aquí);
 *   · contadores, validación con mensajes junto al campo y la guarda de
 *     cambios sin guardar (al salir, al abrir otro aviso o al usar las
 *     acciones de la lista), con el diálogo de confirmación del panel.
 *
 * Ctrl/⌘+Mayús+2 y no Ctrl+Alt+2 (el de los procesadores de texto): en un
 * teclado español de Windows, Ctrl+Alt es AltGr y AltGr+2 escribe «@».
 *
 * Viaja en configuration.js (paths.adminConfigurationJs) y arranca solo
 * cuando la página trae [data-aviso-form].
 */
(function () {
    'use strict';

    // ── Marcado: espejo de AvisosCarta::bloques() y ::html() ─────────

    function segmentos(linea) {
        var partes = linea.split('**');
        if (partes.length % 2 === 0) {
            var ultimo = partes.pop();
            partes[partes.length - 1] += '**' + ultimo;
        }
        var salida = [];
        partes.forEach(function (parte, i) {
            if (parte === '') return;
            salida.push({ texto: parte, negrita: i % 2 === 1 && parte.trim() !== '' });
        });
        return salida;
    }

    function bloques(contenido) {
        var texto = String(contenido).replace(/\r\n?/g, '\n');
        var resultado = [];
        var parrafo = [];
        function cerrar() {
            if (parrafo.length) {
                resultado.push({ tipo: 'p', renglones: parrafo });
                parrafo = [];
            }
        }
        texto.split('\n').forEach(function (linea) {
            var limpia = linea.trim();
            if (limpia === '') {
                cerrar();
                return;
            }
            var m = /^##(?!#)\s*(.*)$/.exec(limpia);
            if (m) {
                cerrar();
                var titulo = m[1].split('**').join('').trim();
                if (titulo !== '') resultado.push({ tipo: 'h2', texto: titulo });
                return;
            }
            parrafo.push(segmentos(limpia));
        });
        cerrar();
        return resultado;
    }

    function escapar(texto) {
        return String(texto)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function html(contenido, clases) {
        function attr(k) { return clases[k] ? ' class="' + clases[k] + '"' : ''; }
        return bloques(contenido).map(function (b) {
            if (b.tipo === 'h2') return '<h2' + attr('h2') + '>' + escapar(b.texto) + '</h2>';
            return '<p' + attr('p') + '>' + b.renglones.map(function (segs) {
                return segs.map(function (s) {
                    return s.negrita ? '<strong' + attr('b') + '>' + escapar(s.texto) + '</strong>' : escapar(s.texto);
                }).join('');
            }).join('<br>') + '</p>';
        }).join('');
    }

    // ── Edición del área de texto ────────────────────────────────────

    /**
     * Sustituye [desde, hasta) por `texto` y deja seleccionado [selDesde,
     * selHasta). Primero por execCommand, que entra a la pila de deshacer; si
     * el motor no lo aplica, a mano (se pierde sólo el deshacer de ese paso).
     */
    function reemplazar(ta, desde, hasta, texto, selDesde, selHasta) {
        var esperado = ta.value.slice(0, desde) + texto + ta.value.slice(hasta);
        ta.focus();
        ta.setSelectionRange(desde, hasta);
        try {
            if (texto === '') document.execCommand('delete', false);
            else document.execCommand('insertText', false, texto);
        } catch (e) { /* respaldo abajo */ }
        if (ta.value !== esperado) {
            ta.value = esperado;
            ta.dispatchEvent(new Event('input', { bubbles: true }));
        }
        ta.setSelectionRange(selDesde, selHasta);
    }

    function alternarNegrita(ta) {
        var v = ta.value;
        var s = ta.selectionStart;
        var e = ta.selectionEnd;

        if (s === e) {
            // Sin selección: la palabra bajo el cursor.
            var ini = s;
            var fin = e;
            while (ini > 0 && !/[\s*]/.test(v.charAt(ini - 1))) ini--;
            while (fin < v.length && !/[\s*]/.test(v.charAt(fin))) fin++;
            if (ini === fin) {
                reemplazar(ta, s, e, '**texto**', s + 2, s + 7);
                return;
            }
            s = ini;
            e = fin;
        }

        // Ya en negritas (las marcas por fuera o dentro de la selección): se quitan.
        if (v.slice(s - 2, s) === '**' && v.slice(e, e + 2) === '**') {
            reemplazar(ta, s - 2, e + 2, v.slice(s, e), s - 2, e - 2);
            return;
        }
        var sel = v.slice(s, e);
        if (sel.length > 4 && sel.slice(0, 2) === '**' && sel.slice(-2) === '**') {
            var dentro = sel.slice(2, -2);
            reemplazar(ta, s, e, dentro, s, s + dentro.length);
            return;
        }

        // Los espacios de las orillas se quedan fuera de las marcas.
        var lead = sel.length - sel.replace(/^\s+/, '').length;
        var trail = sel.length - sel.replace(/\s+$/, '').length;
        s += lead;
        e -= trail;
        sel = v.slice(s, e);
        if (!sel) return;

        // Las negritas no cruzan renglones: cada uno lleva las suyas. Los
        // subtítulos se saltan: la carta les quita las negritas.
        var envuelto = sel.split('\n').map(function (l) {
            return l.trim() && !/^\s*##(?!#)/.test(l) ? l.replace(/^(\s*)(.*?)(\s*)$/, '$1**$2**$3') : l;
        }).join('\n');
        if (sel.indexOf('\n') === -1) reemplazar(ta, s, e, envuelto, s + 2, s + 2 + sel.length);
        else reemplazar(ta, s, e, envuelto, s, s + envuelto.length);
    }

    function alternarSubtitulo(ta) {
        var v = ta.value;
        var s = ta.selectionStart;
        var e = ta.selectionEnd;
        var ini = v.lastIndexOf('\n', s - 1) + 1;
        var finSel = e > s && v.charAt(e - 1) === '\n' ? e - 1 : e;
        var fin = v.indexOf('\n', finSel);
        if (fin === -1) fin = v.length;

        var lineas = v.slice(ini, fin).split('\n');
        var esSubtitulo = function (l) { return /^\s*##(?!#)/.test(l); };
        var conTexto = lineas.filter(function (l) { return l.trim() !== ''; });
        var quitar = conTexto.length > 0 && conTexto.every(esSubtitulo);
        var texto = lineas.map(function (l) {
            if (quitar) return l.replace(/^\s*##(?!#)\s*/, '');
            if (lineas.length > 1 && !l.trim()) return l;
            return esSubtitulo(l) ? l : '## ' + l.replace(/^\s+/, '');
        }).join('\n');

        if (lineas.length === 1 && s === e) {
            var cursor = Math.max(ini, Math.min(ini + texto.length, s + (texto.length - (fin - ini))));
            reemplazar(ta, ini, fin, texto, cursor, cursor);
        } else {
            reemplazar(ta, ini, fin, texto, ini, ini + texto.length);
        }
    }

    /**
     * El formato que hay bajo el cursor, para encender los botones de la
     * barra: subtítulo si todos los renglones con texto de la selección lo
     * son; negritas si la selección cae, en un solo renglón que no es
     * subtítulo, dentro de un tramo entre ** (con las mismas reglas de
     * pareja que segmentos()).
     */
    function formatoEn(ta) {
        var v = ta.value;
        var s = ta.selectionStart;
        var e = ta.selectionEnd;
        var ini = v.lastIndexOf('\n', s - 1) + 1;
        var fin = v.indexOf('\n', e > s && v.charAt(e - 1) === '\n' ? e - 1 : e);
        if (fin === -1) fin = v.length;
        var lineas = v.slice(ini, fin).split('\n');
        var conTexto = lineas.filter(function (l) { return l.trim() !== ''; });
        var subtitulo = conTexto.length > 0 && conTexto.every(function (l) { return /^\s*##(?!#)/.test(l); });
        var unRenglon = lineas.length === 1;

        var negrita = false;
        if (unRenglon && !subtitulo) {
            var partes = lineas[0].split('**');
            if (partes.length % 2 === 0) {
                var ultimo = partes.pop();
                partes[partes.length - 1] += '**' + ultimo;
            }
            var desde = s - ini;
            var hasta = e - ini;
            var pos = 0;
            for (var i = 0; i < partes.length; i++) {
                var tope = pos + partes[i].length;
                if (i % 2 === 1 && partes[i].trim() !== '' && desde >= pos && hasta <= tope) {
                    negrita = true;
                    break;
                }
                pos = tope + 2;
            }
        }
        return { subtitulo: subtitulo, negrita: negrita, enSubtitulo: unRenglon && subtitulo };
    }

    // ── La pantalla ──────────────────────────────────────────────────

    function iniciar(form) {
        var campos = form.querySelector('.admin-aviso-form__campos');
        if (!campos || campos.disabled) return;

        var titulo = form.querySelector('[data-aviso-titulo]');
        var contenido = form.querySelector('[data-aviso-contenido]');
        var barra = form.querySelector('[data-aviso-barra]');
        var cartas = form.querySelector('[data-aviso-cartas]');
        var activo = form.querySelector('[data-aviso-activo]');
        var guardar = form.querySelector('[data-aviso-guardar]');
        var descartar = form.querySelector('[data-aviso-descartar]');
        var estado = document.querySelector('[data-aviso-estado]');
        var hoja = form.querySelector('[data-aviso-preview]');
        var previaTitulo = form.querySelector('[data-aviso-preview-titulo]');
        var previaCuerpo = form.querySelector('[data-aviso-preview-cuerpo]');
        var previaDonde = form.querySelector('[data-aviso-preview-donde]');
        if (!titulo || !contenido) return;

        var limiteTitulo = Number(form.getAttribute('data-limite-titulo')) || 80;
        var limiteContenido = Number(form.getAttribute('data-limite-contenido')) || 1500;
        var clasesPrevia = { h2: 'admin-aviso-preview__h2', p: 'admin-aviso-preview__p', b: 'admin-aviso-preview__b' };

        // Validación propia: mensajes junto al campo en vez de las burbujas
        // del navegador, que no se pueden leer en el tema del panel.
        form.noValidate = true;

        // ── Contadores ──
        function contar(campo, limite) {
            var nodo = form.querySelector('[data-contador="' + campo.id + '"]');
            if (!nodo) return;
            var n = campo.value.length;
            nodo.textContent = n + ' / ' + limite;
            nodo.classList.toggle('is-cerca', n >= limite * 0.9 && n < limite);
            nodo.classList.toggle('is-limite', n >= limite);
        }

        // ── Altura del área de texto ──
        // field-sizing: content la resuelve el CSS donde existe; aquí el
        // respaldo para los motores que aún no la tienen.
        var crecePorCss = window.CSS && CSS.supports && CSS.supports('field-sizing', 'content');
        function ajustarAltura() {
            if (crecePorCss) return;
            contenido.style.height = 'auto';
            contenido.style.height = Math.min(contenido.scrollHeight + 2, Math.round(window.innerHeight * 0.6)) + 'px';
        }

        // ── Vista previa ──
        var posiciones = { inicio: 'al inicio', final: 'al final' };
        function pintarPrevia() {
            var t = titulo.value.trim();
            previaTitulo.textContent = t || 'Título del aviso';
            previaTitulo.classList.toggle('is-vacio', !t);

            var cuerpo = html(contenido.value, clasesPrevia);
            previaCuerpo.innerHTML = cuerpo || '<p class="admin-aviso-preview__p">El contenido aparece aquí mientras escribes.</p>';
            previaCuerpo.classList.toggle('is-vacio', !cuerpo);

            var marcadas = cartas ? Array.prototype.filter.call(cartas.querySelectorAll('input[type="checkbox"]'), function (c) { return c.checked; })
                .map(function (c) { return c.closest('label').querySelector('.admin-pill__title').textContent.trim(); }) : [];
            var lugar = form.querySelector('input[name="posicion"]:checked');
            var pausado = activo && !activo.checked;
            previaDonde.innerHTML = escapar((marcadas.length ? marcadas.join(' · ') : 'Ninguna carta') + ' · ' + (posiciones[lugar ? lugar.value : 'inicio'] || 'al inicio'))
                + (pausado ? ' · <strong>pausado</strong>' : '');
            hoja.classList.toggle('is-pausado', pausado);
        }

        // ── Errores junto al campo ──
        function marcarError(contenedor, control, mensaje) {
            var nodo = contenedor.querySelector('.admin-field__error');
            contenedor.classList.toggle('has-error', !!mensaje);
            if (nodo) nodo.textContent = mensaje || '';
            if (control) {
                if (mensaje) control.setAttribute('aria-invalid', 'true');
                else control.removeAttribute('aria-invalid');
            }
        }

        function validar() {
            var primero = null;
            var campoTitulo = titulo.closest('.admin-field');
            var campoContenido = contenido.closest('.admin-field');
            if (!titulo.value.trim()) {
                marcarError(campoTitulo, titulo, 'Escribe un título: es lo primero que se lee del aviso.');
                primero = primero || titulo;
            } else {
                marcarError(campoTitulo, titulo, '');
            }
            if (!bloques(contenido.value).length) {
                marcarError(campoContenido, contenido, 'Escribe el contenido del aviso.');
                primero = primero || contenido;
            } else if (/<\/?[a-z][a-z0-9-]*(\s[^>]*)?\/?>/i.test(contenido.value)) {
                marcarError(campoContenido, contenido, 'Escribe sin etiquetas HTML: usa ## al inicio de un renglón para un subtítulo y **texto** para negritas.');
                primero = primero || contenido;
            } else {
                marcarError(campoContenido, contenido, '');
            }
            if (cartas) {
                var casillas = cartas.querySelectorAll('input[type="checkbox"]');
                var alguna = Array.prototype.some.call(casillas, function (c) { return c.checked; });
                marcarError(cartas, null, alguna ? '' : 'Elige al menos una carta: Menú, Maridaje o las dos.');
                if (!alguna) primero = primero || casillas[0];
            }
            return primero;
        }

        // ── Cambios sin guardar ──
        function serializar() {
            var datos = [];
            new FormData(form).forEach(function (valor, clave) {
                if (clave !== 'admin_csrf') datos.push(clave + '=' + valor);
            });
            return datos.join('&');
        }
        var inicial = serializar();
        var rechazado = form.hasAttribute('data-aviso-sin-guardar');
        var sucio = rechazado;
        var enviando = false;

        function actualizarEstado() {
            sucio = rechazado || serializar() !== inicial;
            if (estado) {
                estado.textContent = sucio ? 'Sin guardar' : '';
                estado.classList.toggle('is-pending', sucio);
            }
            if (descartar) descartar.hidden = !sucio;
        }

        // `volverA`: a dónde regresa el foco al cerrar el diálogo, cuando el
        // control que lo abrió ya no va a estar (Descartar se oculta).
        function confirmarDescarte(seguir, volverA) {
            if (!window.ConfirmationModal) {
                sucio = false;
                rechazado = false;
                seguir();
                return;
            }
            window.ConfirmationModal.get().open({
                variant: 'warning',
                eyebrow: 'Avisos de la carta',
                title: '¿Descartar los cambios?',
                description: 'El aviso que estás editando tiene cambios sin guardar.',
                consequence: 'Si continúas, se pierden.',
                secondaryLabel: 'Seguir editando',
                primaryLabel: 'Descartar cambios',
                returnFocus: volverA || undefined,
                onPrimary: function () {
                    sucio = false;
                    rechazado = false;
                    seguir();
                }
            });
        }

        function limpiarErrores() {
            marcarError(titulo.closest('.admin-field'), titulo, '');
            marcarError(contenido.closest('.admin-field'), contenido, '');
            if (cartas) marcarError(cartas, null, '');
        }

        function enviar() {
            if (typeof form.requestSubmit === 'function') form.requestSubmit(guardar || undefined);
            else if (!validar()) form.submit();
        }

        // ── La barra: estado, teclado y atajos ──
        var botones = barra ? Array.prototype.slice.call(barra.querySelectorAll('[data-formato]')) : [];
        var botonSubtitulo = barra ? barra.querySelector('[data-formato="h2"]') : null;
        var botonNegrita = barra ? barra.querySelector('[data-formato="negrita"]') : null;

        // Encendidos según el formato bajo el cursor, como en un procesador
        // de textos: el lector anuncia «Negritas, presionado» al pasar por un
        // tramo en negritas, y la vista lo dice sin tener que leer las marcas.
        function pintarBarra() {
            if (!botonSubtitulo || !botonNegrita) return;
            var f = formatoEn(contenido);
            botonSubtitulo.setAttribute('aria-pressed', f.subtitulo ? 'true' : 'false');
            botonNegrita.setAttribute('aria-pressed', f.negrita ? 'true' : 'false');
            if (f.enSubtitulo) botonNegrita.setAttribute('aria-disabled', 'true');
            else botonNegrita.removeAttribute('aria-disabled');
        }

        function aplicar(formato) {
            if (formato === 'h2') {
                alternarSubtitulo(contenido);
            } else if (!formatoEn(contenido).enSubtitulo) {
                alternarNegrita(contenido);
            }
            pintarBarra();
        }

        // Una sola parada de Tab para toda la barra (patrón toolbar de ARIA):
        // las flechas pasan de un botón a otro y el último visitado es el
        // que recibe el foco la próxima vez.
        function enfocarBoton(i) {
            botones.forEach(function (b, j) { b.tabIndex = j === i ? 0 : -1; });
            botones[i].focus();
        }

        // ⌘ en vez de Ctrl en los atajos que se leen (títulos y la ayuda de
        // la barra) cuando el teclado es de Mac.
        var plataforma = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
        if (barra && /Mac|iPhone|iPad|iPod/i.test(plataforma)) {
            botones.forEach(function (b) { b.title = b.title.replace('Ctrl', '⌘').replace('Mayús', '⇧'); });
            var tecla = barra.querySelector('[data-tecla-mod]');
            if (tecla) tecla.textContent = '⌘';
        }

        // ── Eventos ──
        function alEscribir() {
            contar(titulo, limiteTitulo);
            contar(contenido, limiteContenido);
            ajustarAltura();
            pintarPrevia();
            pintarBarra();
            actualizarEstado();
        }
        form.addEventListener('input', alEscribir);
        form.addEventListener('change', alEscribir);

        ['keyup', 'mouseup', 'select', 'focus'].forEach(function (tipo) {
            contenido.addEventListener(tipo, pintarBarra);
        });
        document.addEventListener('selectionchange', function () {
            if (document.activeElement === contenido) pintarBarra();
        });

        if (barra) {
            barra.hidden = false;
            barra.addEventListener('mousedown', function (e) {
                // El foco se queda en el texto: la selección es la que se formatea.
                if (e.target.closest('[data-formato]')) e.preventDefault();
            });
            barra.addEventListener('click', function (e) {
                var boton = e.target.closest('[data-formato]');
                if (!boton || boton.getAttribute('aria-disabled') === 'true') return;
                botones.forEach(function (b) { b.tabIndex = b === boton ? 0 : -1; });
                aplicar(boton.getAttribute('data-formato'));
            });
            barra.addEventListener('keydown', function (e) {
                var i = botones.indexOf(document.activeElement);
                if (i === -1) return;
                var destino = null;
                if (e.key === 'ArrowRight') destino = (i + 1) % botones.length;
                else if (e.key === 'ArrowLeft') destino = (i - 1 + botones.length) % botones.length;
                else if (e.key === 'Home') destino = 0;
                else if (e.key === 'End') destino = botones.length - 1;
                if (destino === null) return;
                e.preventDefault();
                enfocarBoton(destino);
            });
        }

        contenido.addEventListener('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
            if (!e.shiftKey && (e.key === 'b' || e.key === 'B')) {
                // También dentro de un subtítulo: el atajo del navegador
                // (marcadores) no debe saltar por estar en ese renglón.
                e.preventDefault();
                aplicar('negrita');
            } else if (e.shiftKey && e.code === 'Digit2') {
                e.preventDefault();
                alternarSubtitulo(contenido);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                enviar();
            }
        });
        titulo.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                enviar();
            }
        });

        form.addEventListener('submit', function (e) {
            var primero = validar();
            if (primero) {
                e.preventDefault();
                primero.focus();
                return;
            }
            enviando = true;
            if (guardar) {
                guardar.disabled = true;
                guardar.setAttribute('aria-busy', 'true');
                var etiqueta = guardar.lastChild;
                if (etiqueta && etiqueta.nodeType === 3) etiqueta.textContent = ' Guardando…';
            }
        });

        window.addEventListener('beforeunload', function (e) {
            if (!sucio || enviando) return;
            e.preventDefault();
            e.returnValue = '';
        });

        // Abrir otro aviso, usar una plantilla o volver a Configuración con
        // cambios pendientes pide confirmación. Los enlaces a otra pestaña
        // (los PDF) no salen de aquí y pasan sin preguntar.
        document.addEventListener('click', function (e) {
            if (!sucio || enviando || e.defaultPrevented) return;
            var enlace = e.target.closest ? e.target.closest('a[href]') : null;
            if (!enlace || enlace.target === '_blank' || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
            var destino = new URL(enlace.href, window.location.href);
            if (destino.pathname === window.location.pathname && destino.search === window.location.search && destino.hash) return;
            e.preventDefault();
            confirmarDescarte(function () { window.location.href = enlace.href; });
        }, true);

        // Las acciones de la lista (pausar, mover, eliminar) recargan la
        // página: con cambios pendientes también preguntan primero.
        document.addEventListener('submit', function (e) {
            var otro = e.target;
            if (otro === form || !sucio || enviando || otro.getAttribute('data-descarte-confirmado') === '1') return;
            e.preventDefault();
            e.stopImmediatePropagation();
            confirmarDescarte(function () {
                otro.setAttribute('data-descarte-confirmado', '1');
                if (typeof otro.requestSubmit === 'function') otro.requestSubmit();
                else otro.submit();
            });
        }, true);

        // ── Arranque ──
        alEscribir();
        // Tras un guardado rechazado, el foco va al primer campo con error.
        var conError = form.querySelector('.has-error input, .has-error textarea');
        if (conError) conError.focus();
    }

    function arrancar() {
        var form = document.querySelector('[data-aviso-form]');
        if (form) iniciar(form);
    }

    // Para la prueba de contrato (scripts/tests): las reglas del marcado sin DOM.
    window.AvisosCartaMarcado = { bloques: bloques, html: html, alternarNegrita: alternarNegrita, alternarSubtitulo: alternarSubtitulo };

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
    else arrancar();
})();
