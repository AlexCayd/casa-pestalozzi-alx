/**
 * Consultas rápidas: la barra de Tickets, Análisis, Menú e Inventario.
 *
 * Responde mientras se escribe (con espera de 280 ms y sólo desde tres
 * letras), con Enter al momento, y deja cambiar el periodo sin reescribir la
 * pregunta. La interpretación vive en el servidor
 * (Services\Analytics\ConsultasRapidas); aquí sólo se pinta.
 *
 * `/` enfoca la barra desde cualquier parte del módulo salvo que ya se esté
 * escribiendo en otro campo; Escape la limpia.
 */
(function () {
    'use strict';

    var ESPERA = 280;

    function esc(texto) {
        return String(texto === null || texto === undefined ? '' : texto)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function icono(nombre, tam) {
        return window.AdminIcons ? window.AdminIcons.get(nombre, tam || 16) : '';
    }

    var RUBROS = {
        ventas_producto: 'Ventas',
        ventas_categoria: 'Ventas por familia',
        ventas_total: 'Ventas',
        top: 'Ranking',
        stock: 'Almacén',
        bajo_stock: 'Almacén',
        sin_resultado: 'Sin resultado'
    };
    var CON_PERIODO = { ventas_producto: true, ventas_categoria: true, ventas_total: true, top: true };

    function iniciar(raiz) {
        var form = raiz.querySelector('[data-consulta-form]');
        var input = raiz.querySelector('[data-consulta-input]');
        var salida = raiz.querySelector('[data-consulta-resultado]');
        var anuncio = raiz.querySelector('[data-consulta-anuncio]');
        var ejemplos = raiz.querySelector('[data-consulta-ejemplos]');
        var ambito = raiz.getAttribute('data-ambito') || 'todo';
        if (!form || !input || !salida) return;

        var temporizador = null;
        var peticion = null;
        var ultima = '';
        var periodoForzado = '';
        // Tras repintar, el botón que se pulsó ya no existe: el foco caía al
        // <body> y con teclado había que volver a tabular desde arriba.
        var focoTras = '';

        // Sólo la frase, no la tarjeta: ver el comentario del parcial.
        function anunciar(texto) {
            if (anuncio) anuncio.textContent = texto || '';
        }

        function pintarCargando() {
            raiz.classList.add('is-busy');
            salida.setAttribute('aria-busy', 'true');
        }

        function cerrar() {
            if (peticion) peticion.abort();
            raiz.classList.remove('is-busy', 'has-result');
            salida.hidden = true;
            salida.innerHTML = '';
            salida.removeAttribute('aria-busy');
            ultima = '';
            periodoForzado = '';
            focoTras = '';
            anunciar('');
            if (ejemplos) ejemplos.hidden = false;
        }

        function consultar(texto) {
            texto = String(texto || '').trim();
            if (texto.length < 2) {
                cerrar();
                return;
            }
            var clave = texto + '|' + periodoForzado;
            if (clave === ultima && !salida.hidden) return;
            ultima = clave;

            if (peticion) peticion.abort();
            peticion = new AbortController();
            pintarCargando();

            var url = '/admin/api/consultas?' + new URLSearchParams({ q: texto, ambito: ambito, periodo: periodoForzado }).toString();
            fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: peticion.signal })
                .then(function (r) { return r.json(); })
                .then(function (datos) {
                    raiz.classList.remove('is-busy');
                    salida.removeAttribute('aria-busy');
                    if (!datos || !datos.ok) {
                        pintarError((datos && datos.mensaje) || 'No fue posible responder la consulta.');
                        return;
                    }
                    pintar(datos);
                })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') return;
                    raiz.classList.remove('is-busy');
                    salida.removeAttribute('aria-busy');
                    pintarError('Sin conexión con el servidor. Vuelve a intentarlo.');
                });
        }

        function pintarError(texto) {
            salida.hidden = false;
            raiz.classList.add('has-result');
            salida.innerHTML = '<div class="admin-consulta__card admin-consulta__card--error">' +
                '<p class="admin-consulta__frase">' + esc(texto) + '</p></div>';
            anunciar(texto);
        }

        function pintar(datos) {
            var r = datos.respuesta;
            if (!r) {
                cerrar();
                return;
            }
            salida.hidden = false;
            raiz.classList.add('has-result');
            if (ejemplos) ejemplos.hidden = true;

            var html = '<article class="admin-consulta__card admin-consulta__card--' + esc(r.tipo) + '">';
            html += '<header class="admin-consulta__head">' +
                '<span class="admin-consulta__rubro">' + esc(RUBROS[r.tipo] || 'Consulta') + '</span>';
            if (CON_PERIODO[r.tipo] && datos.periodos) {
                html += '<div class="admin-consulta__periodos" role="group" aria-label="Periodo">';
                Object.keys(datos.periodos).forEach(function (clave) {
                    var on = datos.periodo && datos.periodo.clave === clave;
                    html += '<button type="button" class="admin-consulta__periodo' + (on ? ' is-on' : '') + '" aria-pressed="' + (on ? 'true' : 'false') + '" data-consulta-periodo="' + esc(clave) + '">' + esc(datos.periodos[clave]) + '</button>';
                });
                html += '</div>';
            }
            html += '<button type="button" class="admin-consulta__cerrar" data-consulta-cerrar aria-label="Cerrar la respuesta">' + icono('cerrar', 14) + '</button>';
            html += '</header>';

            html += '<div class="admin-consulta__cuerpo">';
            if (r.cifra !== undefined && r.cifra !== null && r.cifra !== '') {
                html += '<div class="admin-consulta__dato' + (r.estado ? ' is-' + esc(r.estado) : '') + '">' +
                    '<span class="admin-consulta__cifra admin-num">' + esc(r.cifra) + '</span>' +
                    (r.unidad ? '<span class="admin-consulta__unidad">' + esc(r.unidad) + '</span>' : '') +
                    '</div>';
            }
            html += '<div class="admin-consulta__texto">' +
                '<h3 class="admin-consulta__titulo">' + esc(r.titulo) + '</h3>' +
                '<p class="admin-consulta__frase">' + esc(r.frase) + '</p>';
            if (r.comparacion) {
                var t = r.comparacion.tendencia;
                html += '<p class="admin-consulta__comparacion is-' + esc(t) + '">' +
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
                    var etiqueta = d.q
                        ? '<button type="button" class="admin-consulta__item-enlace" data-consulta-ejemplo="' + esc(d.q) + '">' + esc(d.etiqueta) + '</button>'
                        : '<span>' + esc(d.etiqueta) + '</span>';
                    html += '<li class="admin-consulta__item">' + etiqueta +
                        '<span class="admin-consulta__item-valor admin-num">' + esc(d.valor) + '</span>' +
                        (numerico ? '<span class="admin-consulta__barra-dato" aria-hidden="true"><span style="width:' + ancho + '%"></span></span>' : '') +
                        '</li>';
                });
                html += '</ul></div>';
            }

            var pie = '';
            if (datos.alternativas && datos.alternativas.length) {
                pie += '<div class="admin-consulta__alternativas"><span>También:</span>';
                datos.alternativas.forEach(function (a) {
                    pie += '<button type="button" class="admin-consulta__alternativa" data-consulta-ejemplo="' + esc(a.q) + '">' +
                        icono(a.tipo === 'stock' ? 'inventario' : 'tickets', 13) + '<span>' + esc(a.nombre) + '</span></button>';
                });
                pie += '</div>';
            }
            if (r.enlace) {
                pie += '<a class="admin-consulta__enlace" href="' + esc(r.enlace.href) + '">' + esc(r.enlace.texto) + icono('flecha-derecha', 14) + '</a>';
            }
            if (pie) html += '<footer class="admin-consulta__pie">' + pie + '</footer>';
            html += '</article>';
            salida.innerHTML = html;
            anunciar([r.titulo, r.frase].filter(Boolean).join('. '));

            if (focoTras) {
                var destino = salida.querySelector(focoTras);
                focoTras = '';
                if (destino) destino.focus();
            }
        }

        input.addEventListener('input', function () {
            window.clearTimeout(temporizador);
            periodoForzado = '';
            var texto = input.value;
            if (texto.trim().length < 3) {
                if (!texto.trim()) cerrar();
                return;
            }
            temporizador = window.setTimeout(function () { consultar(texto); }, ESPERA);
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                if (input.value || !salida.hidden) {
                    e.preventDefault();
                    input.value = '';
                    cerrar();
                }
            }
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            window.clearTimeout(temporizador);
            ultima = '';
            consultar(input.value);
        });

        raiz.addEventListener('click', function (e) {
            var ejemplo = e.target.closest('[data-consulta-ejemplo]');
            if (ejemplo) {
                input.value = ejemplo.getAttribute('data-consulta-ejemplo');
                periodoForzado = '';
                ultima = '';
                consultar(input.value);
                input.focus();
                return;
            }
            var periodo = e.target.closest('[data-consulta-periodo]');
            if (periodo) {
                periodoForzado = periodo.getAttribute('data-consulta-periodo');
                focoTras = '[data-consulta-periodo="' + periodoForzado + '"]';
                ultima = '';
                consultar(input.value);
                return;
            }
            if (e.target.closest('[data-consulta-cerrar]')) {
                input.value = '';
                cerrar();
                input.focus();
            }
        });
    }

    function init(contexto) {
        (contexto || document).querySelectorAll('[data-consulta-rapida]:not([data-consulta-lista])').forEach(function (raiz) {
            raiz.setAttribute('data-consulta-lista', '');
            iniciar(raiz);
        });
    }

    // «/» enfoca la primera barra de la página si no se está escribiendo ya.
    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        var activo = document.activeElement;
        if (activo && (activo.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(activo.tagName))) return;
        if (document.querySelector('.admin-modal.is-open, .confirmation-modal.is-open')) return;
        var barra = document.querySelector('[data-consulta-input]');
        if (!barra) return;
        e.preventDefault();
        barra.focus();
        barra.select();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }

    window.AdminConsultas = { init: init };
})();
