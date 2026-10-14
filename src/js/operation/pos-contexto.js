/**
 * Contexto del mapa del POS (centro de la barra operativa): «Ahora» o «Vista
 * de las 20:00», los lugares libres a esa hora y la próxima llegada.
 *
 * Sólo pinta lo que le dan. Quién proyecta a qué hora lo decide
 * punto-de-venta.js; aquí los botones devuelven la intención por callbacks.
 * Los ‹ › avanzan de media hora en media hora dentro del horario del día (y,
 * hoy, nunca hacia el pasado). Escoger una hora concreta es trabajo del cajón.
 */
(function (window, document) {
    'use strict';

    // El paso de los horarios de reservación.
    var PASO_MINUTOS = 30;

    var V = window.PosReservaVista;

    function crear(opciones) {
        opciones = opciones || {};
        var raiz = opciones.raiz || document.querySelector('[data-pos-contexto]');
        if (!raiz || !V) return null;
        var modoEl = raiz.querySelector('[data-contexto-modo]');
        var datosEl = raiz.querySelector('[data-contexto-datos]');
        var anuncioEl = raiz.querySelector('[data-contexto-anuncio]');
        var ultimoAnuncio = '';
        var ultimaClave = '';

        // Apagado, el botón dice POR QUÉ: hoy, una hora atrás ya pasó y lo
        // enseña el mapa en vivo, no el horario.
        function botonPaso(direccion, hora, motivo) {
            var etiqueta = hora
                ? (direccion < 0 ? 'Media hora antes: ' : 'Media hora después: ') + hora
                : motivo === 'pasado'
                    ? 'Media hora antes ya pasó: el mapa en vivo está en Ahora'
                    : (direccion < 0 ? 'No hay media hora antes dentro del horario' : 'No hay media hora después dentro del horario');
            return '<button type="button" class="pos-btn-redondo pos-btn-redondo--icono" data-contexto-paso="' + V.esc(hora || '') + '"'
                + ' data-contexto-dir="' + (direccion < 0 ? 'antes' : 'despues') + '"'
                + (hora ? '' : ' disabled') + ' aria-label="' + V.esc(etiqueta) + '">'
                + V.icono(direccion < 0 ? 'chevron-izquierda' : 'chevron-derecha', 18) + '</button>';
        }

        function aHora(minutos) {
            return ('0' + Math.floor(minutos / 60)).slice(-2) + ':' + ('0' + (minutos % 60)).slice(-2);
        }

        /*
         * Los ‹ › avanzan de media hora en media hora —el paso de los
         * horarios de reservación—, dentro del horario del día. Antes saltaban
         * de un horario con reservas al siguiente, y las horas intermedias,
         * donde se ve cómo se vacía el salón, no existían. Con media hora
         * también se alcanza una apertura a las 08:30. Hoy no se proyecta al
         * pasado: el mapa en vivo ya lo enseña.
         */
        function vecinas(estado) {
            var actual = V.minutosDe(estado.hora);
            if (actual === null) return { antes: '', despues: '', motivoAntes: '' };
            var horario = estado.horario || {};
            var apertura = V.minutosDe(horario.hora_apertura);
            var cierre = V.minutosDe(horario.hora_cierre);
            var ahora = V.minutosDe(estado.ahoraHM);
            var antes = actual - PASO_MINUTOS;
            var despues = actual + PASO_MINUTOS;
            var dentroDelHorario = antes >= (apertura === null ? 0 : apertura);
            var yaPaso = estado.esHoy && ahora !== null && antes <= ahora;
            var despuesValida = despues < (cierre === null || cierre === 0 ? 24 * 60 : cierre);
            return {
                antes: dentroDelHorario && !yaPaso ? aHora(antes) : '',
                despues: despuesValida ? aHora(despues) : '',
                motivoAntes: dentroDelHorario && yaPaso ? 'pasado' : ''
            };
        }

        // «Mar 13 oct» con el día de la semana en su propia caja: es lo
        // primero que se pliega cuando a la barra le falta ancho.
        function fechaProyectada(iso) {
            var corta = String(V.fechaCorta(iso) || '');
            var corte = corta.indexOf(' ');
            if (corte <= 0) return V.esc(corta);
            return '<span class="pos-contexto__dia">' + V.esc(corta.slice(0, corte)) + ' </span>'
                + V.esc(corta.slice(corte + 1));
        }

        // Qué botón del modo tenía el foco antes de repintar. El repintado es
        // innerHTML, así que sin esto cada ‹ › pulsado con teclado tiraba el
        // foco al <body> y había que volver a tabular hasta la barra para dar
        // el siguiente paso.
        function botonEnfocado() {
            var activo = document.activeElement;
            if (!activo || !modoEl.contains(activo)) return '';
            if (activo.hasAttribute('data-contexto-dir')) return '[data-contexto-dir="' + activo.getAttribute('data-contexto-dir') + '"]';
            if (activo.hasAttribute('data-contexto-ahora')) return '[data-contexto-ahora]';
            return '';
        }

        function devolverFoco(selector) {
            if (!selector) return;
            var boton = modoEl.querySelector(selector);
            // Al llegar al borde del horario el mismo botón nace apagado: el
            // foco se queda en la barra, que anuncia la hora nueva.
            if (boton && !boton.disabled) boton.focus();
            else if (typeof raiz.focus === 'function') raiz.focus();
        }

        function pintarModo(estado) {
            var foco = botonEnfocado();
            var h = '';
            // El modo viaja también en la raíz: el CSS pliega con umbrales
            // distintos en vivo y en proyección, que no piden el mismo ancho.
            raiz.setAttribute('data-modo', estado.modo === 'proyeccion' ? 'proyeccion' : 'ahora');
            if (estado.modo !== 'proyeccion') {
                h += '<span class="pos-contexto__reloj">'
                    + '<span>Ahora</span><time data-contexto-reloj>' + V.esc(estado.ahoraHM || '') + '</time></span>';
            } else {
                var paso = vecinas(estado);
                h += botonPaso(-1, paso.antes, paso.motivoAntes);
                h += '<span class="pos-contexto__vista">' + V.icono('reloj', 16)
                    + '<span>' + (estado.esHoy ? 'Vista de las' : fechaProyectada(estado.fecha) + ' ·') + '</span>'
                    + '<time>' + V.esc(estado.hora) + '</time></span>';
                h += botonPaso(1, paso.despues);
                // [Hoy] ocupa el sitio exacto de [Ahora]: la salida al presente
                // está siempre en el mismo lugar.
                h += '<button type="button" class="pos-btn-redondo" data-contexto-ahora aria-label="'
                    + (estado.esHoy ? 'Volver a Ahora: mapa en vivo' : 'Volver a hoy: mapa en vivo') + '">'
                    + V.icono('ahora', 18) + '<span>' + (estado.esHoy ? 'Ahora' : 'Hoy') + '</span></button>';
            }
            modoEl.innerHTML = h;
            devolverFoco(foco);
        }

        function pintarDatos(estado) {
            var h = '';
            var codigo = estado.horario && estado.horario.codigo;
            if (codigo === 'sin_configurar') {
                h += '<div class="pos-contexto__aviso" role="status">' + V.icono('alerta', 16)
                    + '<span>Horario no configurado: la página no ofrece reservaciones.</span>'
                    + (estado.esAdmin
                        ? '<a href="/admin/configuracion/horarios">Configurar</a>'
                        : '<span>Avisa a un administrador.</span>')
                    + '</div>';
                datosEl.innerHTML = h;
                return;
            }
            /*
             * Una sola cifra: «38/44 libres». La próxima llegada salió de la
             * barra: el plano la pinta de azul una hora antes y el cajón la
             * lista por horario, así que en la barra sólo repetía.
             */
            var celda = '';
            if (codigo === 'cerrado') {
                // «Cerrado» SUSTITUYE a la cifra: un día sin servicio no tiene
                // lugares libres que contar.
                celda = '<span class="pos-contexto__dato">' + (estado.esHoy ? 'Cerrado hoy' : 'Cerrado ese día') + '</span>';
            } else if (estado.capacidad && estado.capacidad.total > 0) {
                var libres = Math.max(0, estado.capacidad.libre);
                var aLas = estado.modo === 'proyeccion' ? ' a las ' + estado.hora : '';
                var etiquetaCupo = libres + ' de ' + estado.capacidad.total + ' lugares libres' + aLas;
                celda = '<span class="pos-contexto__dato" role="img" aria-label="' + V.esc(etiquetaCupo) + '" title="' + V.esc(etiquetaCupo) + '">'
                    // «38/44» es UNA cifra: va junta en su propia caja, sin el
                    // gap del flex, que la partía en «38 /44».
                    + '<span class="pos-contexto__fraccion"><b>' + libres + '</b>/' + estado.capacidad.total + '</span>'
                    + '<span>libres</span></span>';
            }
            // No hay aviso «sin mesa»: toda reservación nace con mesas (el
            // servidor no guarda una sin ellas), así que no hay nada que señalar.
            // Sin celda no queda una pastilla vacía: el contenedor se queda
            // vacío y el CSS lo retira (:empty).
            if (celda) h += '<div class="pos-contexto__pastilla">' + celda + '</div>';
            datosEl.innerHTML = h;
        }

        function anunciar(estado) {
            var clave = estado.modo + '|' + estado.fecha + '|' + estado.hora;
            if (clave === ultimaClave) return;
            var primera = ultimaClave === '';
            ultimaClave = clave;
            // Al cargar no se anuncia nada que nadie pidió.
            if (primera || !anuncioEl) return;
            var texto;
            if (estado.modo !== 'proyeccion') {
                texto = 'Mapa en vivo.';
            } else {
                var en = estado.enHora || { reservas: 0 };
                texto = (estado.esHoy ? 'Mapa a las ' + estado.hora : V.fechaLarga(estado.fecha) + ': mapa a las ' + estado.hora)
                    + ', sólo consulta. ' + (en.reservas > 0
                        ? V.plural(en.reservas, 'reserva', 'reservas') + ' a esta hora.'
                        : 'Ninguna reserva a esta hora.');
            }
            if (texto !== ultimoAnuncio) {
                anuncioEl.textContent = texto;
                ultimoAnuncio = texto;
            }
        }

        raiz.addEventListener('click', function (event) {
            var objetivo = event.target.closest('button');
            if (!objetivo || !raiz.contains(objetivo) || objetivo.disabled) return;
            if (objetivo.hasAttribute('data-contexto-ahora')) {
                if (typeof opciones.onAhora === 'function') opciones.onAhora();
            } else if (objetivo.hasAttribute('data-contexto-paso')) {
                var hora = objetivo.getAttribute('data-contexto-paso');
                if (hora && typeof opciones.onHora === 'function') opciones.onHora(hora);
            }
        });

        return {
            pintar: function (estado) {
                pintarModo(estado);
                pintarDatos(estado);
                anunciar(estado);
            },
            // El reloj de Ahora se mueve cada minuto sin anunciarse.
            reloj: function (texto) {
                var el = raiz.querySelector('[data-contexto-reloj]');
                if (el && el.textContent !== texto) el.textContent = texto;
            },
            // Tocaron una mesa en proyección: el botón Ahora llama dos veces.
            llamarAhora: function () {
                var boton = raiz.querySelector('[data-contexto-ahora]');
                if (!boton) return;
                boton.classList.remove('is-llamando');
                void boton.offsetWidth;
                boton.classList.add('is-llamando');
                window.setTimeout(function () { boton.classList.remove('is-llamando'); }, 1300);
            },
            enfocar: function () {
                if (typeof raiz.focus === 'function') raiz.focus();
            }
        };
    }

    window.PosContexto = { crear: crear };
})(window, document);
