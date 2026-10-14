/**
 * Cajón de reservaciones del POS, agrupado por horario.
 *
 * Cada cabecera de horario proyecta el mapa a esa hora; cada tarjeta además
 * abre la ficha. La cabecera de un horario que ya llegó (hoy) no es botón:
 * proyectar al pasado enseña un salón que ya no existe, y el mapa en vivo es
 * su mejor representación. Las canceladas, las que no llegaron y las atendidas
 * van al final, plegadas.
 *
 * Sólo pinta. Las intenciones salen por callbacks a punto-de-venta.js.
 */
(function (window, document) {
    'use strict';

    var V = window.PosReservaVista;

    // Dentro de un horario: primero lo que pide algo.
    function rango(reserva) {
        var t = V.senalTiempo(reserva);
        // «sin-llegar» dejó de existir: la tolerancia vencida es «vencida».
        if (t && t.variante === 'vencida') return 0;
        if (V.enMesa(reserva)) return 3;
        if (String(reserva.estado) === 'pendiente_verificacion') return 2;
        return 1;
    }

    function crear(opciones) {
        opciones = opciones || {};
        var lista = opciones.lista;
        var contador = opciones.contador;
        if (!lista || !V) return null;

        function tarjeta(reserva, estado) {
            var id = parseInt(reserva.id, 10);
            var enfocada = id === estado.enfocadaId;
            var mesas = V.nombresMesas(V.ids(reserva), estado.mesaPorId);
            var tiempo = V.senalTiempo(reserva);
            var resuelta = V.estaResuelta(reserva) || V.enMesa(reserva);
            var h = '<li><article class="pos-reserva' + (enfocada ? ' is-enfocada' : '') + (resuelta ? ' pos-reserva--resuelta' : '') + '" data-id="' + id + '">';
            h += '<button type="button" class="pos-reserva__boton" data-cierra-cajon data-reservacion="' + id + '"' + (enfocada ? ' aria-current="true"' : '') + '>';
            if (resuelta) {
                h += '<span class="pos-reserva__linea"><b>' + V.esc(reserva.nombre || 'Sin nombre') + '</b> · ' + V.esc(reserva.comensales)
                    + (mesas ? ' · ' + V.esc(mesas) : '') + '</span>';
                h += V.senalHtml(tiempo);
            } else {
                // Dos filas con oficio distinto. Arriba quién y en qué está —el
                // nombre y la señal de tiempo, como el pin dice mesa y estado—;
                // abajo los hechos en tinta neutra: mesas, personas y lo que
                // las matiza. La señal es lo único que puede llevar color, y
                // vive siempre en la misma esquina para leerse en vertical.
                h += '<span class="pos-reserva__fila"><span class="pos-reserva__nombre">' + V.esc(reserva.nombre || 'Sin nombre') + '</span>'
                    + V.senalHtml(tiempo) + '</span>';
                h += '<span class="pos-reserva__datos">';
                if (mesas) {
                    h += '<span class="pos-reserva__mesa">' + V.esc(mesas) + '</span>';
                    if (reserva.reubicada === true && reserva.reubicacion) {
                        h += '<span class="pos-reserva__antes">antes <s>' + V.esc(V.nombresMesas(reserva.reubicacion.mesas_antes || [], estado.mesaPorId)) + '</s></span>';
                    }
                } else {
                    h += '<span class="pos-reserva__sin-mesa">' + V.icono('alerta', 14) + 'Sin mesa asignada</span>';
                }
                h += '<span class="pos-reserva__personas">' + V.icono('grupo', 14) + V.esc(reserva.comensales)
                    + '<span class="operational-visually-hidden"> personas</span></span>';
                h += V.senalesExtra(reserva, { maxComensales: estado.maxComensales, esBloque: estado.esBloque })
                    .map(V.senalHtml).join('');
                h += '</span>';
                if (reserva.nota) {
                    h += '<span class="pos-reserva__nota">' + V.icono('nota', 14) + '<span>' + V.esc(reserva.nota) + '</span></span>';
                }
                // El contacto nunca llega al mesero (lo quita el servidor); aquí
                // tampoco se pide si quien mira no es admin.
                if (estado.esAdmin && reserva.contacto) {
                    h += '<span class="pos-reserva__contacto">' + V.esc(reserva.contacto) + '</span>';
                }
            }
            h += '</button></article></li>';
            return h;
        }

        function resumen(grupo) {
            var activas = grupo.filter(function (r) { return !V.enMesa(r); });
            var personas = grupo.reduce(function (acc, r) { return acc + (parseInt(r.comensales, 10) || 0); }, 0);
            if (!activas.length) return V.plural(grupo.length, 'en mesa', 'en mesa');
            return V.plural(grupo.length, 'reserva', 'reservas') + ' · ' + personas + ' pers.';
        }

        function pintar(estado) {
            var reservas = (estado.reservas || []).slice();
            var vivas = reservas.filter(function (r) { return !V.estaResuelta(r); });
            var resueltas = reservas.filter(V.estaResuelta);
            if (contador) contador.textContent = String(vivas.length);

            var porHora = {};
            vivas.forEach(function (r) {
                var hora = V.hhmm(r.hora);
                (porHora[hora] = porHora[hora] || []).push(r);
            });
            var horas = Object.keys(porHora).sort();
            var proyectada = estado.contexto && estado.contexto.modo === 'proyeccion' ? V.hhmm(estado.contexto.hora) : '';

            var h = '<div class="pos-cajon" data-pos-cajon>';
            if (estado.esHoy) {
                h += '<button type="button" class="pos-cajon__ahora" data-cierra-cajon data-cajon-ahora'
                    + (proyectada ? '' : ' aria-current="true"') + '>' + V.icono('ahora', 16) + '<span>Ahora</span>'
                    + '<time>' + V.esc(estado.ahoraHM || '') + '</time></button>';
            }
            if (!horas.length && !resueltas.length) {
                h += '<div class="mapa-empty-state"><span class="mapa-empty-icon" aria-hidden="true">' + V.icono('reloj', 24) + '</span>'
                    + '<span class="mapa-empty-title">' + (estado.esHoy ? 'Sin reservaciones para hoy' : 'Sin reservaciones para el ' + V.esc(V.fechaLarga(estado.fechaIso).toLowerCase())) + '</span>'
                    + (estado.esHoy ? '<span class="mapa-empty-hint">Las que lleguen por la página aparecerán aquí.</span>' : '')
                    + '</div>';
            }
            horas.forEach(function (hora) {
                var grupo = porHora[hora].slice().sort(function (a, b) {
                    return (rango(a) - rango(b)) || (parseInt(a.id, 10) - parseInt(b.id, 10));
                });
                var idCab = 'pos-horario-' + hora.replace(':', '');
                var pasada = estado.esHoy && hora <= (estado.ahoraHM || '');
                var actual = hora === proyectada;
                var textoResumen = resumen(grupo);
                h += '<section class="pos-horario" aria-labelledby="' + idCab + '"><h3 class="pos-horario__cab">';
                if (pasada) {
                    h += '<span class="pos-horario__boton" id="' + idCab + '"><time class="pos-horario__hora">' + hora + '</time>'
                        + '<span class="pos-horario__resumen">' + V.esc(textoResumen) + '</span>'
                        + '<span class="pos-horario__ver">En vivo</span></span>';
                } else {
                    h += '<button type="button" class="pos-horario__boton" id="' + idCab + '" data-cierra-cajon data-horario="' + hora + '"'
                        + ' aria-current="' + (actual ? 'true' : 'false') + '"'
                        + ' aria-label="' + V.esc('Ver el mapa a las ' + hora + ': ' + textoResumen) + '">'
                        + '<time class="pos-horario__hora">' + hora + '</time>'
                        + '<span class="pos-horario__resumen">' + V.esc(textoResumen) + '</span>'
                        + '<span class="pos-horario__ver" aria-hidden="true">' + V.icono('plano', 16) + (actual ? 'En el mapa' : 'Ver') + '</span></button>';
                }
                h += '</h3><ul class="pos-horario__lista" role="list">';
                grupo.forEach(function (r) { h += tarjeta(r, estado); });
                h += '</ul></section>';
            });
            if (resueltas.length) {
                h += '<details class="pos-cajon__cerradas"' + (resueltas.some(function (r) { return parseInt(r.id, 10) === estado.enfocadaId; }) ? ' open' : '') + '>'
                    // «Resueltas» y no «Canceladas»: aquí caen también las
                    // atendidas, que ya pasaron por la mesa.
                    + '<summary>Ya resueltas · ' + resueltas.length + '</summary><ul class="pos-horario__lista" role="list">';
                resueltas.sort(function (a, b) { return String(a.hora).localeCompare(String(b.hora)); })
                    .forEach(function (r) { h += tarjeta(r, estado); });
                h += '</ul></details>';
            }
            h += '</div>';
            lista.innerHTML = h;
        }

        lista.addEventListener('click', function (event) {
            var boton = event.target.closest('button');
            if (!boton || !lista.contains(boton)) return;
            if (boton.hasAttribute('data-cajon-ahora')) {
                if (typeof opciones.onAhora === 'function') opciones.onAhora();
            } else if (boton.hasAttribute('data-horario')) {
                if (typeof opciones.onHorario === 'function') opciones.onHorario(boton.getAttribute('data-horario'));
            } else if (boton.hasAttribute('data-reservacion')) {
                var id = parseInt(boton.getAttribute('data-reservacion'), 10);
                if (id && typeof opciones.onReserva === 'function') opciones.onReserva(id);
            } else if (boton.hasAttribute('data-cajon-reintentar')) {
                if (typeof opciones.onReintentar === 'function') opciones.onReintentar();
            }
        });

        return {
            pintar: pintar,
            cargando: function () {
                lista.innerHTML = '<div class="mapa-empty-state"><span class="mapa-empty-icon" aria-hidden="true"><span class="mapa-empty-spinner"></span></span>'
                    + '<span class="mapa-empty-title">Cargando reservaciones…</span></div>';
            },
            // Si ya había lista se conserva debajo: datos de hace 30 s valen más
            // que una pantalla vacía.
            error: function () {
                var previo = lista.querySelector('[data-pos-cajon]');
                var aviso = '<div class="pos-cajon__error" role="alert"><p>No se pudo cargar la lista.</p>'
                    + '<button type="button" class="mmodal-btn mmodal-btn--ghost" data-cajon-reintentar>Reintentar</button></div>';
                var existente = lista.querySelector('.pos-cajon__error');
                if (existente) existente.parentNode.removeChild(existente);
                if (previo) {
                    previo.insertAdjacentHTML('beforebegin', aviso);
                } else {
                    lista.innerHTML = aviso;
                }
            },
            // Al abrir: hasta el horario proyectado o el primero con algo pendiente.
            enfocarActual: function () {
                var destino = lista.querySelector('.pos-horario__boton[aria-current="true"]')
                    || lista.querySelector('.pos-reserva.is-enfocada .pos-reserva__boton')
                    || lista.querySelector('.pos-reserva:not(.pos-reserva--resuelta) .pos-reserva__boton');
                if (!destino) return;
                // Sólo se desplaza la lista: scrollIntoView movía también el
                // contenedor del cajón (overflow:hidden, pero desplazable por
                // código) y la fila de la fecha desaparecía por arriba.
                var reducido = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                var destinoTop = destino.getBoundingClientRect().top - lista.getBoundingClientRect().top + lista.scrollTop;
                var arriba = Math.max(0, destinoTop - 4);
                if (typeof lista.scrollTo === 'function') {
                    lista.scrollTo({ top: arriba, behavior: reducido ? 'auto' : 'smooth' });
                } else {
                    lista.scrollTop = arriba;
                }
                // El foco sigue al desplazamiento: quien navega con teclado
                // aterriza en el horario que el mapa enseña, no en la
                // hamburguesa que abrió el cajón.
                try {
                    destino.focus({ preventScroll: true });
                } catch (e) {
                    destino.focus();
                }
            }
        };
    }

    window.PosCajon = { crear: crear };
})(window, document);
