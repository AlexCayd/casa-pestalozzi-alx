/**
 * Ficha de una reservación en el POS, acoplada al mapa.
 *
 * Orden fijo, de lo que decide a lo que se consulta: quién → cuándo, cuántos,
 * dónde → qué pasa ahora → qué cambió → qué pidieron → qué sabe el equipo →
 * cómo contactar (sólo admin) → salida destructiva. Abajo, fuera del scroll,
 * una sola decisión (Llegó / Llegó + No llegó / Abrir ticket).
 *
 * No es modal: el mapa sigue tocable para saltar de una reserva a otra. Las
 * banderas puede_* del servidor mandan siempre; aquí sólo se elige el texto.
 * Las peticiones las hace punto-de-venta.js: la ficha recibe funciones.
 */
(function (window, document) {
    'use strict';

    var V = window.PosReservaVista;

    var MOTIVOS = [
        'Avisó que no viene',
        'Cambió de fecha u hora',
        'Reservación duplicada',
        'Error al registrarla'
    ];

    function crear(opciones) {
        opciones = opciones || {};
        var raiz = opciones.raiz || document.getElementById('pos-ficha');
        if (!raiz || !V) return null;
        var cuerpo = raiz.querySelector('[data-ficha-cuerpo]');
        var pie = raiz.querySelector('[data-ficha-acciones]');
        var acciones = opciones.acciones || {};
        var actual = null;          // reserva en pantalla
        var contexto = {};          // opciones de la última apertura
        var editando = false;       // comentario en edición: el sondeo no repinta
        var confirmando = false;    // un diálogo de confirmación abierto

        function hhmmDe(valor) {
            return V.hhmm(valor);
        }

        function mesasTexto(reserva) {
            return V.nombresMesas(V.ids(reserva), contexto.mesaPorId);
        }

        function sinTicket(reserva) {
            return !V.enMesa(reserva);
        }

        function puedeCambiar(reserva) {
            return String(reserva.estado) === 'confirmada' && sinTicket(reserva);
        }

        // Hora en que «Llegó» se habilita: la de la reserva menos el inicio
        // anticipado que permite la configuración.
        function horaHabilita(reserva) {
            var minutos = V.minutosDe(V.hhmm(reserva.hora));
            var anticipo = parseInt((contexto.temporal || {}).inicio_servicio_anticipado_minutos, 10) || 0;
            if (minutos === null) return '';
            var total = Math.max(0, minutos - anticipo);
            return ('0' + Math.floor(total / 60)).slice(-2) + ':' + ('0' + (total % 60)).slice(-2);
        }

        function frasesEstado(reserva) {
            var estado = String(reserva.estado);
            var frases = [];
            var mesas = mesasTexto(reserva);
            var tiempo = V.senalTiempo(reserva);
            if (estado === 'cancelada') {
                frases.push(reserva.motivo_cancelacion ? 'Motivo: ' + reserva.motivo_cancelacion + '.' : 'Cancelada.');
            } else if (estado === 'no_show') {
                frases.push('Se registró que no llegó.');
            } else if (estado === 'completada') {
                frases.push('Atendida.');
            } else if (V.enMesa(reserva)) {
                if (reserva.ticket_id) frases.push('Ticket #' + reserva.ticket_id + '.');
            } else if (estado === 'pendiente_verificacion') {
                var libera = hhmmDe(reserva.hold_expires_at);
                frases.push('Esperando que el cliente confirme.' + (libera ? ' Si no lo hace, se libera a las ' + libera + '.' : ''));
            } else if (tiempo && tiempo.variante === 'vencida') {
                // La ausencia se registra sola (ReservacionAusenciaAutomaticaService)
                // en la siguiente lectura: aquí sólo se dice qué va a pasar.
                frases.push('Venció la tolerancia: se registra sola como ausencia'
                    + (mesas ? ' y ' + mesas + (V.ids(reserva).length === 1 ? ' queda libre' : ' quedan libres') : '') + '.');
            } else if (String(reserva.ventana_pos) === 'tolerancia') {
                var hasta = hhmmDe(reserva.tolerancia_hasta);
                frases.push(hasta ? 'Tienen tolerancia hasta las ' + hasta + '.' : 'Están dentro de la tolerancia.');
            } else if (reserva.puede_iniciar_servicio === true) {
                frases.push('Cuando lleguen, toca «Llegó»' + (mesas ? ': se abre el ticket en ' + mesas + '.' : '.'));
            }
            // Cuándo se habilita «Llegó» lo dice SÓLO la pista del pie, debajo
            // del botón al que se refiere. Lo decían los dos sitios —««Llegó» se
            // habilita a las 19:30» aquí y «Se habilita a las 19:30» abajo—, y
            // el día de una reserva futura ya va en la celda de la hora.
            if (reserva.fuera_horario_operacion === true) {
                frases.push('Fuera de horario de operación: un cambio de horario la dejó fuera; revisa su seguimiento en el buzón.');
            }
            if (estado === 'confirmada' && !V.ids(reserva).length) {
                frases.push('Sin mesa asignada: asígnale mesas antes de que llegue.');
            }
            // «Grupo de N: sus mesas se eligen a mano» se retiró: el servidor
            // ya propone mesas para los grupos mayores al máximo y se ajustan
            // en el mapa con «Cambiar». Que es grupo grande lo dice la celda
            // de las mesas (matizMesas).
            return frases;
        }

        // La línea muda bajo las mesas: grupo grande, exterior completo o los
        // dos. Es la misma palabra que la tarjeta del cajón.
        function matizMesas(reserva) {
            var maximo = parseInt(contexto.maxComensales, 10) || 0;
            var grande = maximo > 0 && (parseInt(reserva.comensales, 10) || 0) > maximo;
            var bloque = Boolean(contexto.esBloque && contexto.esBloque(V.ids(reserva)));
            if (grande && bloque) return 'Grupo grande · exterior completo';
            return grande ? 'Grupo grande' : (bloque ? 'Exterior completo' : '');
        }

        // La pista de «Llegó» apagado: por qué y cuándo, con el día escrito
        // («el día de la reservación» obligaba a buscar cuál era).
        function motivoLlegoApagado(reserva) {
            if (!V.ids(reserva).length) return 'Primero asigna mesas';
            if (contexto.esHoy) {
                var habilita = horaHabilita(reserva);
                return habilita ? 'Se habilita a las ' + habilita : 'Se habilita poco antes de su hora';
            }
            return reserva.fecha
                ? 'Se habilita el ' + V.fechaLarga(String(reserva.fecha).substring(0, 10)).toLowerCase()
                : 'Se habilita el día de la reservación';
        }

        function causaReubicacion(reserva) {
            var causaId = parseInt(reserva.reubicacion && reserva.reubicacion.causa_reservacion_id, 10);
            if (!causaId) return '';
            var causa = (contexto.reservasDia || []).find(function (r) { return parseInt(r.id, 10) === causaId; });
            return causa ? 'Por el grupo de ' + causa.comensales + ' de las ' + V.hhmm(causa.hora) + ' en el exterior.' : '';
        }

        function pintar(reserva) {
            var id = parseInt(reserva.id, 10);
            var estado = String(reserva.estado);
            var mesas = mesasTexto(reserva);
            var tiempo = V.senalTiempo(reserva);
            var h = '';

            h += '<header class="pos-ficha__cab"><p class="pos-ficha__rotulo">Reservación <span class="admin-num">#' + id + '</span></p>'
                + '<button type="button" class="pos-ficha__cerrar" data-ficha-cerrar aria-label="Cerrar ficha">' + V.icono('cerrar', 18) + '</button>'
                + '<h2 class="pos-ficha__nombre" id="pos-ficha-nombre" tabindex="-1">' + V.esc(reserva.nombre || 'Sin nombre') + '</h2></header>';

            // Otro día: la fecha corta junto a la hora. Es el dato que faltaba
            // en la ficha y que la frase de estado suplía de rebote.
            var otroDia = !contexto.esHoy && reserva.fecha
                ? '<small class="pos-ficha__dia">' + V.esc(V.fechaCorta(String(reserva.fecha).substring(0, 10))) + '</small>'
                : '';
            var matiz = matizMesas(reserva);
            h += '<dl class="pos-ficha__datos">'
                + '<div><dt>Hora</dt><dd><time>' + V.esc(V.hhmm(reserva.hora)) + '</time>' + otroDia + '</dd></div>'
                + '<div><dt>Personas</dt><dd>' + V.esc(reserva.comensales) + '</dd></div>'
                + '<div><dt>Mesas</dt><dd class="pos-ficha__mesas-dd' + (mesas ? '' : ' is-vacio') + '"><span>' + V.esc(mesas || 'Sin asignar') + '</span>';
            if (puedeCambiar(reserva) && typeof acciones.cambiarMesas === 'function') {
                h += '<button type="button" class="pos-ficha__inline" data-ficha-accion="cambiar-mesas">' + (mesas ? 'Cambiar' : 'Asignar') + '</button>';
            }
            if (matiz) {
                h += '<span class="pos-ficha__matiz">' + V.esc(matiz) + '</span>';
            }
            h += '</dd></div></dl>';

            // El estado es una ETIQUETA, no una pastilla: la pastilla con
            // contorno es la forma de las acciones de esta ficha («Cambiar»,
            // «Agregar»), y «Por llegar» con la misma silueta se leía como
            // un botón más. Neutra salvo cuando el pin de la mesa está azul.
            h += '<div class="pos-ficha__estado" data-ficha-estado>';
            h += tiempo ? V.senalHtml(tiempo) : V.senalHtml({ variante: '', icono: 'reloj', html: 'Por llegar', texto: 'Por llegar' });
            frasesEstado(reserva).forEach(function (frase) {
                h += '<p class="pos-ficha__frase">' + V.esc(frase) + '</p>';
            });
            h += '</div>';

            // Mesa libre AHORA con una reserva a 60–30 min: sólo si la ficha se
            // abrió desde el pin, en vivo. Desde el cajón no se sabe de qué
            // mesa se habla.
            if (contexto.allowWalkIn && contexto.mesa && !contexto.proyeccion) {
                h += '<section class="pos-ficha__aviso" aria-labelledby="pos-ficha-mesa">'
                    + '<strong id="pos-ficha-mesa">' + V.esc(contexto.mesa.nombre) + ' está libre ahora</strong>'
                    + '<p>Esta reservación llega en ' + V.esc(reserva.minutos_para_inicio || '') + ' min. Puedes sentar a alguien si la mesa queda libre antes.</p>'
                    + '<button type="button" class="mmodal-btn mmodal-btn--outline" data-ficha-accion="walk-in">Abrir ticket de todas formas</button>'
                    + '</section>';
            }

            if (reserva.reubicada === true && reserva.reubicacion) {
                var antes = V.nombresMesas(reserva.reubicacion.mesas_antes || [], contexto.mesaPorId);
                var despues = V.nombresMesas(reserva.reubicacion.mesas_despues || [], contexto.mesaPorId);
                var causa = causaReubicacion(reserva);
                h += '<section class="pos-ficha__aviso" aria-labelledby="pos-ficha-reub">'
                    + '<strong id="pos-ficha-reub">' + V.icono('reubicar', 16) + 'Reubicada de ' + V.esc(antes || 'otra mesa') + ' a ' + V.esc(despues || 'otra mesa') + '</strong>'
                    + (causa ? '<p>' + V.esc(causa) + '</p>' : '')
                    + '<button type="button" class="pos-ficha__inline" data-ficha-accion="revisar-reubicacion" data-reubicacion="' + V.esc(reserva.reubicacion.id) + '">Marcar revisada</button>'
                    + '</section>';
            }

            if (reserva.nota) {
                h += '<section class="pos-ficha__seccion"><p class="pos-ficha__etiqueta">Nota del cliente</p>'
                    + '<p class="pos-ficha__texto pos-ficha__nota">' + V.esc(reserva.nota) + '</p></section>';
            }

            // Comentario interno: el mesero lo lee si existe (decisión del
            // dueño); sólo administración lo edita, y lo exige el servidor.
            var comentario = String(reserva.comentario_admin || '');
            if (contexto.esAdmin || comentario) {
                h += '<section class="pos-ficha__seccion" data-ficha-comentario><p class="pos-ficha__etiqueta"><span>Comentario interno</span>';
                if (contexto.esAdmin && typeof acciones.comentario === 'function') {
                    h += '<button type="button" class="pos-ficha__inline" data-ficha-accion="editar-comentario">' + (comentario ? 'Editar' : 'Agregar') + '</button>';
                }
                h += '</p><p class="pos-ficha__texto" data-ficha-comentario-texto>' + (comentario ? V.esc(comentario) : '<span class="pos-ficha__frase">Sin comentario.</span>') + '</p></section>';
            }

            if (contexto.esAdmin && reserva.contacto) {
                h += '<section class="pos-ficha__seccion"><p class="pos-ficha__etiqueta">Contacto</p>'
                    + '<div class="pos-ficha__contacto"><span>' + V.esc(reserva.contacto) + '</span>'
                    + '<small>' + (reserva.origen_alta === 'admin' ? 'Registrada por el personal' : 'Reservó en la página') + '</small></div></section>';
            }

            var llegoHabilitado = estado === 'confirmada' && reserva.puede_iniciar_servicio === true && V.ids(reserva).length > 0;
            h += '<div class="pos-ficha__mesero" data-mesero-slot></div>';

            if (puedeCambiar(reserva) && typeof acciones.cancelar === 'function') {
                h += '<div class="pos-ficha__pie"><button type="button" class="mmodal-btn mmodal-btn--ghost" data-ficha-accion="cancelar">Cancelar reservación</button></div>';
            }
            cuerpo.innerHTML = h;
            pie.innerHTML = pintarPie(reserva, llegoHabilitado);

            var slot = cuerpo.querySelector('[data-mesero-slot]');
            if (slot && llegoHabilitado && typeof acciones.montarMesero === 'function') {
                acciones.montarMesero(slot);
            }
        }

        function pintarPie(reserva, llegoHabilitado) {
            var estado = String(reserva.estado);
            var h = '';
            if (V.enMesa(reserva) && reserva.ticket_id) {
                if (contexto.proyeccion) {
                    h += '<p class="pos-ficha__pista" id="pos-ficha-pista">Vuelve a «Ahora» para abrir el ticket.</p>';
                } else {
                    h += '<button type="button" class="mmodal-btn mmodal-btn--primary pos-ficha__ancho" data-ficha-accion="abrir-ticket">Abrir ticket</button>';
                }
                return h;
            }
            if (estado !== 'confirmada') return '';

            if (llegoHabilitado) {
                h += '<button type="button" class="mmodal-btn mmodal-btn--primary pos-ficha__ancho" data-ficha-accion="llego">Llegó</button>';
            } else {
                var motivo = motivoLlegoApagado(reserva);
                // --pending siempre junto a disabled: el borde discontinuo dice
                // «todavía no», y la pista dice por qué.
                h += '<button type="button" class="mmodal-btn mmodal-btn--primary mmodal-btn--pending pos-ficha__ancho" disabled aria-describedby="pos-ficha-pista">Llegó</button>';
            }
            // No hay «No llegó»: al vencer la tolerancia la ausencia se registra
            // sola y la mesa se libera. La pista va SIEMPRE que «Llegó» esté
            // apagado: el botón la cita en aria-describedby.
            if (!llegoHabilitado) h += '<p class="pos-ficha__pista" id="pos-ficha-pista">' + V.esc(motivo) + '</p>';
            return h;
        }

        function confirmarCancelacion(reserva) {
            if (!window.ConfirmationModal || typeof acciones.cancelar !== 'function') return;
            var modal = window.ConfirmationModal.get();
            var mesas = mesasTexto(reserva);
            var nodo = document.createElement('fieldset');
            nodo.className = 'admin-pills pos-motivos';
            var h = '<legend class="admin-pills__legend">Motivo</legend><div class="admin-pills__group">';
            MOTIVOS.concat(['otro']).forEach(function (motivo) {
                h += '<label class="admin-pill"><input type="radio" name="pos-motivo" value="' + V.esc(motivo) + '">'
                    + '<span class="admin-pill__body"><span class="admin-pill__title">' + (motivo === 'otro' ? 'Otro motivo' : V.esc(motivo)) + '</span></span></label>';
            });
            h += '</div><label class="pos-motivos__otro" hidden><span class="admin-pills__legend">Escribe el motivo</span>'
                + '<textarea rows="3" maxlength="500" placeholder="Explica brevemente el motivo"></textarea></label>';
            nodo.innerHTML = h;
            var otro = nodo.querySelector('.pos-motivos__otro');
            var texto = nodo.querySelector('textarea');
            nodo.addEventListener('change', function () {
                var elegido = nodo.querySelector('input[name="pos-motivo"]:checked');
                otro.hidden = !(elegido && elegido.value === 'otro');
                if (!otro.hidden) texto.focus();
            });
            confirmando = true;
            var resultado = modal.open({
                variant: 'danger',
                eyebrow: 'Cancelar reservación',
                title: (reserva.nombre || 'Reservación') + ' · ' + V.hhmm(reserva.hora) + ' · ' + V.plural(parseInt(reserva.comensales, 10) || 0, 'persona', 'personas'),
                description: (mesas ? mesas + (V.ids(reserva).length === 1 ? ' queda libre.' : ' quedan libres.') : 'La reservación no tiene mesas.')
                    + ' No se puede deshacer desde el POS.',
                customContent: nodo,
                secondaryLabel: 'Volver',
                primaryLabel: 'Cancelar reservación',
                initialFocus: nodo.querySelector('input'),
                onPrimary: function () {
                    var elegido = nodo.querySelector('input[name="pos-motivo"]:checked');
                    var motivo = elegido ? (elegido.value === 'otro' ? texto.value.trim() : elegido.value) : '';
                    if (!motivo) {
                        modal.setStatus(elegido ? 'Escribe el motivo.' : 'Elige el motivo de la cancelación.', true);
                        (elegido ? texto : nodo.querySelector('input')).focus();
                        return false;
                    }
                    var peticion = acciones.cancelar(reserva, motivo);
                    // El componente pone un mensaje genérico si la promesa
                    // falla; el del servidor se escribe después y gana.
                    peticion.catch(function (error) {
                        window.setTimeout(function () {
                            modal.setStatus((error && error.mensaje) || 'No se pudo cancelar la reservación.', true);
                        }, 0);
                    });
                    return peticion;
                }
            });
            if (resultado && typeof resultado.then === 'function') {
                resultado.then(function () { confirmando = false; }, function () { confirmando = false; });
            } else {
                confirmando = false;
            }
        }

        function editarComentario() {
            var seccion = cuerpo.querySelector('[data-ficha-comentario]');
            if (!seccion || !actual) return;
            editando = true;
            var original = String(actual.comentario_admin || '');
            var parrafo = seccion.querySelector('[data-ficha-comentario-texto]');
            var boton = seccion.querySelector('[data-ficha-accion="editar-comentario"]');
            if (boton) boton.hidden = true;
            var editor = document.createElement('div');
            editor.className = 'pos-ficha__editor';
            editor.innerHTML = '<label class="operational-visually-hidden" for="pos-ficha-comentario">Comentario interno</label>'
                + '<textarea id="pos-ficha-comentario" rows="3" maxlength="500"></textarea>'
                + '<div class="pos-ficha__editor-botones">'
                + '<button type="button" class="mmodal-btn mmodal-btn--secondary" data-editor="guardar">Guardar</button>'
                + '<button type="button" class="mmodal-btn mmodal-btn--ghost" data-editor="descartar">Descartar</button></div>';
            parrafo.replaceWith(editor);
            var campo = editor.querySelector('textarea');
            campo.value = original;
            campo.focus();
            campo.setSelectionRange(campo.value.length, campo.value.length);

            var cerrarEditor = function () {
                editando = false;
                pintar(actual);
                var otra = cuerpo.querySelector('[data-ficha-accion="editar-comentario"]');
                if (otra) otra.focus();
            };
            campo.addEventListener('keydown', function (event) {
                // Escape dentro del campo descarta; no cierra la ficha.
                if (event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    cerrarEditor();
                }
            });
            editor.addEventListener('click', function (event) {
                var b = event.target.closest('[data-editor]');
                if (!b) return;
                if (b.getAttribute('data-editor') === 'descartar') {
                    cerrarEditor();
                    return;
                }
                var previo = editor.querySelector('[role="alert"]');
                if (previo) previo.parentNode.removeChild(previo);
                b.disabled = true;
                b.textContent = 'Guardando…';
                acciones.comentario(actual, campo.value).then(function () {
                    actual.comentario_admin = campo.value.trim() || null;
                    cerrarEditor();
                }, function (error) {
                    b.disabled = false;
                    b.textContent = 'Guardar';
                    var alerta = document.createElement('p');
                    alerta.setAttribute('role', 'alert');
                    alerta.textContent = (error && error.mensaje) || 'No se pudo guardar el comentario.';
                    editor.appendChild(alerta);
                });
            });
        }

        raiz.addEventListener('click', function (event) {
            if (event.target.closest('[data-ficha-cerrar]')) {
                cerrar();
                return;
            }
            var boton = event.target.closest('[data-ficha-accion]');
            if (!boton || !actual || boton.disabled) return;
            var accion = boton.getAttribute('data-ficha-accion');
            if (accion === 'llego' && typeof acciones.llego === 'function') {
                acciones.llego(actual, boton);
            } else if (accion === 'cancelar') {
                confirmarCancelacion(actual);
            } else if (accion === 'cambiar-mesas' && typeof acciones.cambiarMesas === 'function') {
                acciones.cambiarMesas(actual);
            } else if (accion === 'editar-comentario') {
                editarComentario();
            } else if (accion === 'walk-in' && typeof acciones.walkIn === 'function') {
                acciones.walkIn(actual, contexto.mesa, boton);
            } else if (accion === 'abrir-ticket' && typeof acciones.abrirTicket === 'function') {
                acciones.abrirTicket(actual);
            } else if (accion === 'revisar-reubicacion' && typeof acciones.revisar === 'function') {
                boton.disabled = true;
                boton.textContent = 'Guardando…';
                acciones.revisar(parseInt(boton.getAttribute('data-reubicacion'), 10)).then(function () {
                    if (actual) {
                        actual.reubicada = false;
                        pintar(actual);
                    }
                }, function () {
                    boton.disabled = false;
                    boton.textContent = 'Marcar revisada';
                });
            }
        });

        raiz.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !editando && !confirmando) {
                event.preventDefault();
                cerrar();
            }
        });

        function abrir(reserva, opcionesApertura) {
            contexto = opcionesApertura || {};
            actual = reserva;
            editando = false;
            raiz.hidden = false;
            pintar(reserva);
        }

        function cerrar() {
            if (!actual && raiz.hidden) return;
            var anterior = actual;
            actual = null;
            editando = false;
            if (typeof opciones.onCerrar === 'function') {
                opciones.onCerrar(anterior);
            } else {
                raiz.hidden = true;
            }
        }

        return {
            abrir: abrir,
            cerrar: cerrar,
            // El sondeo repinta sólo si la reserva cambió y nunca a media
            // edición o con una confirmación abierta.
            refrescar: function (reserva, opcionesNuevas) {
                if (!actual || !reserva || editando || confirmando) return;
                if (String(reserva.version || '') === String(actual.version || '')
                    && String(reserva.estado) === String(actual.estado)
                    && String(reserva.ventana_pos) === String(actual.ventana_pos)
                    && reserva.minutos_para_inicio === actual.minutos_para_inicio
                    && reserva.reubicada === actual.reubicada
                    && !(opcionesNuevas && opcionesNuevas.allowWalkIn !== undefined
                        && Boolean(opcionesNuevas.allowWalkIn) !== Boolean(contexto.allowWalkIn))) return;
                var foco = document.activeElement && raiz.contains(document.activeElement)
                    ? document.activeElement.getAttribute('data-ficha-accion')
                    : null;
                var scroll = cuerpo.scrollTop;
                var estadoEl = cuerpo.querySelector('[data-ficha-estado]');
                if (estadoEl) estadoEl.setAttribute('role', 'status');
                if (opcionesNuevas) contexto = Object.assign({}, contexto, opcionesNuevas);
                actual = reserva;
                pintar(reserva);
                cuerpo.scrollTop = scroll;
                if (foco) {
                    var volver = raiz.querySelector('[data-ficha-accion="' + foco + '"]');
                    if (volver) volver.focus();
                }
            },
            reservaId: function () { return actual ? parseInt(actual.id, 10) : 0; },
            reserva: function () { return actual; },
            abierta: function () { return Boolean(actual); },
            ocupada: function () { return editando || confirmando; },
            enfocarTitulo: function () {
                var titulo = document.getElementById('pos-ficha-nombre');
                if (titulo) titulo.focus();
            }
        };
    }

    window.PosFicha = { crear: crear };
})(window, document);
