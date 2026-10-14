/**
 * «Nueva reservación» desde el POS.
 *
 * Personas → horas disponibles para esas personas (las da el servidor) → al
 * elegir una, la vista previa dice en qué mesas quedaría y a quién movería.
 * Un grupo mayor al máximo de la página no tiene asignación automática: se
 * crea y las mesas se eligen en el mapa a continuación.
 *
 * Las advertencias del servidor (sin contacto, sin mesas, capacidad) llegan
 * como decisiones y se confirman con el diálogo de la casa; el reenvío lleva el
 * MISMO request_token, así un doble toque no crea dos reservaciones.
 */
(function (window, document) {
    'use strict';

    var V = window.PosReservaVista;

    function token() {
        var base = 'pos';
        var bytes = window.crypto && window.crypto.getRandomValues
            ? window.crypto.getRandomValues(new Uint8Array(16))
            : null;
        for (var i = 0; i < 16; i++) {
            var n = bytes ? bytes[i] : Math.floor(Math.random() * 256);
            base += ('0' + n.toString(16)).slice(-2);
        }
        return base;
    }

    function crear(opciones) {
        opciones = opciones || {};
        var dialogo = document.getElementById('pos-alta');
        if (!dialogo || !V) return null;
        var form = dialogo.querySelector('[data-alta-form]');
        var nombre = dialogo.querySelector('[data-alta-nombre]');
        var personasEl = dialogo.querySelector('[data-alta-personas]');
        var cupoEl = dialogo.querySelector('[data-alta-cupo]');
        var menosBtn = dialogo.querySelector('[data-alta-paso="-1"]');
        var masBtn = dialogo.querySelector('[data-alta-paso="1"]');
        var horasEl = dialogo.querySelector('[data-alta-horas]');
        var horasEstado = dialogo.querySelector('[data-alta-horas-estado]');
        var previa = dialogo.querySelector('[data-alta-previa]');
        var errorEl = dialogo.querySelector('[data-alta-error]');
        var crearBtn = dialogo.querySelector('[data-alta-crear]');
        var fechaEl = dialogo.querySelector('[data-alta-fecha]');
        var estado = { fecha: '', personas: 2, hora: '', detalle: {}, token: '', enviando: false, consulta: 0 };

        function maximo() {
            return parseInt(opciones.maxComensales && opciones.maxComensales(), 10) || 12;
        }

        function mostrarError(texto) {
            errorEl.textContent = texto || '';
            errorEl.hidden = !texto;
        }

        // El servidor dice QUÉ campo falla (field_codes); su mensaje genérico
        // («Revisa los datos enviados…») no. Sin esto el anfitrión no sabía
        // que lo que faltaba era el teléfono.
        var MENSAJES_CAMPO = {
            NOMBRE_REQUERIDO: { campo: 'nombre', texto: 'Escribe el nombre de la reservación.' },
            CONTACTO_REQUERIDO: { campo: 'contacto', texto: 'Escribe el teléfono o el correo, o elige «Sin contacto».' },
            CONTACTO_INVALIDO: { campo: 'contacto', texto: 'Ese teléfono o correo no es válido. Corrígelo o elige «Sin contacto».' },
            NOTA_DEMASIADO_LARGA: { campo: 'nota', texto: 'La nota es demasiado larga.' }
        };

        function errorDeCampo(resultado) {
            // El alta del POS responde `fieldErrors` ({campo: [texto]}, ya
            // redactado por el catálogo); otros caminos traen `field_codes`.
            var textos = (resultado && resultado.fieldErrors) || {};
            var conTexto = Object.keys(textos).filter(function (c) { return [].concat(textos[c] || []).length; });
            if (conTexto.length) return { campo: conTexto[0], texto: String([].concat(textos[conTexto[0]])[0]) };
            var codigos = (resultado && resultado.field_codes) || {};
            var campos = Object.keys(codigos);
            for (var i = 0; i < campos.length; i++) {
                var lista = [].concat(codigos[campos[i]] || []);
                for (var j = 0; j < lista.length; j++) {
                    if (MENSAJES_CAMPO[lista[j]]) return MENSAJES_CAMPO[lista[j]];
                }
            }
            return null;
        }

        function enfocarCampo(campo) {
            var el = campo === 'nombre' ? nombre
                : campo === 'contacto' ? form.querySelector('[data-alta-contacto]')
                : campo === 'nota' ? form.querySelector('[data-alta-nota]') : null;
            if (el && !el.hidden) el.focus();
        }

        // Lo que se puede saber sin preguntar al servidor, antes de salir del
        // diálogo: un grupo grande pasa al mapa y un error descubierto allí
        // ya no tiene a la vista el campo que lo causa.
        function contactoFaltante() {
            var tipo = form.querySelector('input[name="contacto_tipo"]:checked');
            var campo = form.querySelector('[data-alta-contacto]');
            return !!(tipo && campo && tipo.value !== 'ninguno' && !campo.value.trim());
        }

        // Lugares que quedan a la hora elegida con las reservas y los tickets
        // proyectados; sin hora, los de la hora más holgada del día (más no
        // cabe a ninguna). Es el techo del contador: ofrecer 30 personas donde
        // caben 12 sólo aplazaba el «no» al final.
        function cupo() {
            var d = estado.hora ? estado.detalle[estado.hora] : null;
            if (d && d.capacidad_real_disponible != null) return Math.max(0, parseInt(d.capacidad_real_disponible, 10) || 0);
            var horas = Object.keys(estado.detalle);
            if (!horas.length) return 44;
            return horas.reduce(function (max, h) {
                var dh = estado.detalle[h];
                if (!dh || dh.horario_valido === false) return max;
                var n = dh.capacidad_real_disponible != null
                    ? parseInt(dh.capacidad_real_disponible, 10) || 0
                    : parseInt(dh.capacidad_total, 10) || 0;
                return Math.max(max, n);
            }, 1);
        }

        function pintarPersonas() {
            personasEl.textContent = String(estado.personas);
            var tope = cupo();
            menosBtn.disabled = estado.personas <= 1;
            masBtn.disabled = estado.personas >= tope;
            // Sólo el cupo: es lo que topa el contador y por eso vive bajo él.
            // Lo de las mesas de un grupo grande va en la vista previa, junto
            // a la propuesta; las dos frases juntas y centradas eran un
            // párrafo de dos ideas que no se leía de un golpe.
            var h = '';
            if (estado.hora && estado.detalle[estado.hora]) {
                h = tope > 0
                    ? 'A las ' + V.esc(V.hhmm(estado.hora)) + ' quedan <strong class="pos-alta__cupo-cifra">' + V.esc(V.plural(tope, 'lugar', 'lugares')) + '</strong>.'
                    : 'A las ' + V.esc(V.hhmm(estado.hora)) + ' no queda ningún lugar.';
            } else if (esGrande()) {
                h = 'Más de ' + maximo() + ' personas: al elegir la hora te proponemos mesas.';
            }
            cupoEl.innerHTML = h;
            cupoEl.hidden = !h;
        }

        function pintarPrevia() {
            var d = estado.detalle[estado.hora];
            if (!estado.hora || !d) {
                previa.hidden = true;
                previa.innerHTML = '';
                return;
            }
            var h = '<p class="pos-alta__previa-titulo">' + V.icono('plano', 16) + '<span>';
            // Un grupo grande no tiene asignación automática, pero sí una
            // propuesta: con ella el mapa se abre ya marcado y sólo se ajusta.
            var mesas = esGrande()
                ? (Array.isArray(d.mesa_ids_sugeridas) ? d.mesa_ids_sugeridas : [])
                : (Array.isArray(d.mesa_ids) ? d.mesa_ids : []);
            if (mesas.length && esGrande()) {
                h += 'Te proponemos ' + V.esc(V.nombresMesas(mesas, opciones.mesaPorId)) + '.';
            } else if (mesas.length) {
                h += 'Quedaría en ' + V.esc(V.nombresMesas(mesas, opciones.mesaPorId)) + '.';
            } else {
                // Toda reservación tiene mesas: sin lugar, esa hora no sirve.
                h += 'No hay mesas libres para ' + V.esc(estado.personas) + ' a esa hora: elige otra.';
            }
            h += '</span></p>';
            if (mesas.length && esGrande()) {
                h += '<p class="pos-alta__previa-nota">Más de ' + maximo() + ' personas: las ajustas en el mapa antes de crear.</p>';
            }
            var mover = Array.isArray(d.reubicaciones_previstas) ? d.reubicaciones_previstas : [];
            if (mover.length) {
                h += '<p class="pos-alta__previa-nota">Para darle el exterior se mueven ' + V.plural(mover.length, 'reservación', 'reservaciones') + ' al interior:</p><ul>';
                mover.forEach(function (r) {
                    // «de … a …» en palabras, como «Reubicada de … a …» en la
                    // ficha: una flecha tipográfica es un glifo haciendo de
                    // icono y en voz se lee «flecha derecha».
                    h += '<li>' + V.esc(V.hhmm(r.hora)) + ' · ' + V.esc(r.comensales) + ' pers.: de '
                        + V.esc(V.nombresMesas(r.mesas_antes || [], opciones.mesaPorId)) + ' a '
                        + V.esc(V.nombresMesas(r.mesas_despues || [], opciones.mesaPorId)) + '</li>';
                });
                h += '</ul>';
            }
            previa.innerHTML = h;
            previa.hidden = false;
        }

        // Lugares que quedan a una hora; null si el servidor no lo dijo.
        function lugaresA(hora) {
            var d = estado.detalle[hora];
            return d && d.capacidad_real_disponible != null ? parseInt(d.capacidad_real_disponible, 10) || 0 : null;
        }

        // Una hora sirve si es válida y caben las personas. Antes una hora con
        // menos lugares se podía elegir y bajaba el contador a lo que cabía:
        // el anfitrión pedía 36, tocaba las 19:00 y la reserva salía para 30
        // sin que nadie lo hubiera decidido.
        function horaSirve(hora) {
            var lugares = lugaresA(hora.hora);
            return !!hora.disponible && (lugares === null || lugares >= estado.personas);
        }

        function pintarHoras(respuesta) {
            var horas = respuesta && Array.isArray(respuesta.horarios) ? respuesta.horarios : [];
            estado.detalle = (respuesta && respuesta.detalle_horarios) || {};
            if (estado.hora && !horas.some(function (h) { return h.hora === estado.hora && horaSirve(h); })) {
                estado.hora = '';
            }
            var h = '';
            // Botones con aria-pressed y no role="radio": un radiogroup ARIA
            // promete flechas y una sola parada de tabulación, y esta rejilla
            // no las implementa. Doce botones pulsables dicen la verdad.
            horas.forEach(function (hora) {
                var elegida = hora.hora === estado.hora;
                // Sin lugar para estas personas no se puede elegir; la voz dice
                // cuántos quedan, que es lo que explica el tachado.
                var lugares = lugaresA(hora.hora);
                var corta = hora.disponible && lugares !== null && lugares < estado.personas;
                h += '<button type="button" class="pos-alta__hora' + (elegida ? ' is-elegida' : '') + '"'
                    + ' data-alta-hora="' + V.esc(hora.hora) + '" aria-pressed="' + (elegida ? 'true' : 'false') + '"'
                    + (corta ? ' aria-label="' + V.esc(hora.hora) + ', ' + (lugares > 0 ? 'sólo ' + V.esc(V.plural(lugares, 'lugar', 'lugares')) : 'sin lugares') + '"' : '')
                    + (horaSirve(hora) ? '' : ' disabled') + '>' + V.esc(hora.hora) + '</button>';
            });
            horasEl.innerHTML = h;
            var libres = horas.filter(horaSirve).length;
            var cortas = horas.filter(function (x) { return x.disponible && !horaSirve(x); }).length;
            horasEstado.textContent = !horas.length
                ? ((respuesta && respuesta.mensaje) || 'No hay horario de reservaciones ese día.')
                : (!libres
                    ? 'No queda ninguna hora con lugar para ' + V.plural(estado.personas, 'persona', 'personas') + '.'
                    : (cortas ? 'Tachadas: sin lugar para ' + V.plural(estado.personas, 'persona', 'personas') + '.' : ''));
            pintarPersonas();
            pintarPrevia();
            actualizarBoton();
        }

        function consultar() {
            var numero = ++estado.consulta;
            horasEstado.classList.remove('is-error');
            horasEstado.textContent = 'Buscando horas…';
            return opciones.disponibilidad(estado.fecha, estado.personas).then(function (respuesta) {
                if (numero !== estado.consulta) return;
                pintarHoras(respuesta);
            }, function () {
                if (numero !== estado.consulta) return;
                horasEl.innerHTML = '';
                horasEstado.textContent = 'No se pudieron cargar las horas. Revisa la conexión.';
            });
        }

        // Sólo se bloquea mientras viaja la petición. Apagado hasta tener
        // nombre y hora, el botón no decía qué faltaba y los avisos del
        // submit («Escribe el nombre…», «Elige una hora.») no salían nunca.
        function actualizarBoton() {
            crearBtn.disabled = estado.enviando;
            crearBtn.textContent = estado.enviando
                ? 'Creando…'
                : (esGrande() ? 'Elegir mesas' : 'Crear reservación');
        }

        // Un grupo mayor al máximo no tiene asignación automática, y una
        // reservación no existe sin mesas: primero se eligen y después se crea.
        function esGrande() {
            return estado.personas > maximo();
        }

        function elegirMesas(confirmaciones) {
            if (typeof opciones.elegirMesas !== 'function') return false;
            cerrar();
            // La propuesta va aparte del alta: sólo sirve para que el mapa abra
            // ya marcado, y el servidor no tiene por qué recibirla.
            opciones.elegirMesas(datos(confirmaciones), ((estado.detalle[estado.hora] || {}).mesa_ids_sugeridas || []).slice());
            return true;
        }

        // Sin contacto no hay campo que llenar; con teléfono o correo, el
        // teclado de la tablet es el que toca.
        function pintarContacto() {
            var campo = form.querySelector('[data-alta-contacto]');
            var tipo = form.querySelector('input[name="contacto_tipo"]:checked');
            if (!campo) return;
            var valor = tipo ? tipo.value : 'ninguno';
            campo.hidden = valor === 'ninguno';
            campo.type = valor === 'email' ? 'email' : 'text';
            campo.setAttribute('inputmode', valor === 'email' ? 'email' : 'tel');
            campo.setAttribute('aria-label', valor === 'email' ? 'Correo' : 'Teléfono');
        }

        function abrir(fecha) {
            estado = { fecha: fecha, personas: 2, hora: '', detalle: {}, token: token(), enviando: false, consulta: estado.consulta };
            form.reset();
            mostrarError('');
            fechaEl.textContent = 'Para el ' + V.fechaLarga(fecha).toLowerCase();
            pintarPersonas();
            pintarContacto();
            previa.hidden = true;
            if (typeof dialogo.showModal === 'function') dialogo.showModal();
            nombre.focus();
            consultar();
            actualizarBoton();
        }

        function cerrar() {
            if (dialogo.open) dialogo.close();
        }

        // Un <dialog> modal deja inerte todo lo que no está en la capa
        // superior, incluido el diálogo de confirmación de la casa. Mientras se
        // confirma, el alta se aparta y vuelve si el mesero regresa o falla.
        function reabrir() {
            if (!dialogo.open && typeof dialogo.showModal === 'function') dialogo.showModal();
        }

        function datos(confirmaciones) {
            var tipo = form.querySelector('input[name="contacto_tipo"]:checked');
            var contacto = form.querySelector('[data-alta-contacto]');
            return {
                nombre: nombre.value.trim(),
                comensales: estado.personas,
                fecha: estado.fecha,
                hora: estado.hora,
                contacto_tipo: tipo ? tipo.value : 'ninguno',
                contacto: contacto && tipo && tipo.value !== 'ninguno' ? contacto.value.trim() : null,
                nota: (dialogo.querySelector('[data-alta-nota]').value || '').trim(),
                request_token: estado.token,
                confirmaciones: confirmaciones || []
            };
        }

        function enviar(confirmaciones) {
            if (estado.enviando) return Promise.resolve();
            estado.enviando = true;
            mostrarError('');
            actualizarBoton();
            return opciones.crear(datos(confirmaciones)).then(function (resultado) {
                estado.enviando = false;
                actualizarBoton();
                if (resultado && resultado.ok === true && resultado.commit === true) {
                    cerrar();
                    if (typeof opciones.alCrear === 'function') opciones.alCrear(resultado, datos(confirmaciones));
                    return;
                }
                var decisiones = (resultado && (resultado.confirmaciones_requeridas || resultado.warnings)) || [];
                if (decisiones.length && window.ConfirmationModal) {
                    var decision = decisiones[0];
                    var principal = (decision.acciones || []).find(function (a) { return a.tipo === 'primary'; });
                    if (dialogo.open) dialogo.close();
                    var confirmacion = window.ConfirmationModal.get().open({
                        variant: 'warning',
                        eyebrow: 'Nueva reservación',
                        title: decision.titulo || decision.mensaje || 'Confirma el alta',
                        description: decision.mensaje || '',
                        consequence: decision.consecuencia || '',
                        secondaryLabel: 'Volver',
                        primaryLabel: principal && principal.label ? principal.label : 'Crear de todas formas',
                        onPrimary: function () {
                            var codigo = decision.codigo_canonico || decision.codigo;
                            return enviar((confirmaciones || []).concat([codigo]));
                        }
                    });
                    if (confirmacion && typeof confirmacion.then === 'function') {
                        confirmacion.then(function (r) {
                            if (!r || r.action !== 'primary') reabrir();
                        });
                    }
                    return;
                }
                // El servidor no encontró mesas (alguien ocupó la hora mientras
                // tanto): un grupo grande las elige; uno normal, otra hora.
                if (resultado && resultado.requiere_seleccion_mesas === true && elegirMesas(confirmaciones)) return;
                reabrir();
                var deCampo = errorDeCampo(resultado);
                mostrarError(deCampo ? deCampo.texto : ((resultado && resultado.mensaje) || 'No se pudo crear la reservación.'));
                if (deCampo) enfocarCampo(deCampo.campo);
                if (resultado && resultado.codigo === 'MESAS_REQUERIDAS') consultar();
                if (resultado && resultado.codigo === 'HORARIO_NO_DISPONIBLE') consultar();
            }, function () {
                estado.enviando = false;
                actualizarBoton();
                reabrir();
                mostrarError('No se pudo crear la reservación. Revisa la conexión.');
            });
        }

        dialogo.addEventListener('click', function (event) {
            var boton = event.target.closest('button');
            if (!boton || boton.disabled) return;
            if (boton.hasAttribute('data-alta-cerrar')) {
                cerrar();
            } else if (boton.hasAttribute('data-alta-paso')) {
                var siguiente = estado.personas + parseInt(boton.getAttribute('data-alta-paso'), 10);
                // Hasta los lugares que quedan a esa hora (o el salón, sin hora).
                estado.personas = Math.max(1, Math.min(cupo(), siguiente));
                pintarPersonas();
                consultar();
            } else if (boton.hasAttribute('data-alta-hora')) {
                estado.hora = boton.getAttribute('data-alta-hora');
                Array.prototype.forEach.call(horasEl.querySelectorAll('[data-alta-hora]'), function (b) {
                    var elegida = b === boton;
                    b.classList.toggle('is-elegida', elegida);
                    b.setAttribute('aria-pressed', elegida ? 'true' : 'false');
                });
                if (horasEstado.classList.contains('is-error')) {
                    horasEstado.textContent = '';
                    horasEstado.classList.remove('is-error');
                }
                pintarPersonas();
                pintarPrevia();
                actualizarBoton();
            }
        });
        nombre.addEventListener('input', function () {
            if (nombre.value.trim() && errorEl.textContent === 'Escribe el nombre de la reservación.') mostrarError('');
        });
        form.addEventListener('change', function (event) {
            if (event.target && event.target.name === 'contacto_tipo') pintarContacto();
        });
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!nombre.value.trim()) {
                mostrarError('Escribe el nombre de la reservación.');
                nombre.focus();
                return;
            }
            if (!estado.hora) {
                // Junto a la rejilla, no al pie del formulario: el aviso tiene
                // que estar donde está lo que falta.
                horasEstado.textContent = 'Elige una hora.';
                horasEstado.classList.add('is-error');
                var primera = horasEl.querySelector('[data-alta-hora]:not([disabled])');
                if (primera) primera.focus();
                return;
            }
            if (contactoFaltante()) {
                mostrarError(MENSAJES_CAMPO.CONTACTO_REQUERIDO.texto);
                enfocarCampo('contacto');
                return;
            }
            if (esGrande() && elegirMesas([])) return;
            enviar([]);
        });

        /*
         * Vuelta desde el mapa: el alta de un grupo grande se rechazó por un
         * dato del formulario. El diálogo conserva lo escrito (cerrar no lo
         * borra), así que se reabre tal cual con el campo señalado.
         * Devuelve false si el error no es de un campo y lo resuelve el mapa.
         */
        function retomar(resultado) {
            var deCampo = errorDeCampo(resultado);
            if (!deCampo) return false;
            reabrir();
            mostrarError(deCampo.texto);
            enfocarCampo(deCampo.campo);
            return true;
        }

        return { abrir: abrir, cerrar: cerrar, retomar: retomar };
    }

    window.PosAlta = { crear: crear };
})(window, document);
