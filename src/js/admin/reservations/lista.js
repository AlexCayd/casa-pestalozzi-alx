/**
 * Agenda de reservaciones (/admin/reservaciones): «No llegó».
 *
 * Es la única acción que la Agenda resuelve sin salir de la lista —cerrar la
 * ausencia de una reserva cuya tolerancia venció y que la lectura automática
 * no pudo registrar—. Todo lo demás son enlaces (detalle, POS) o lo atienden
 * los filtros reactivos de admin.js (buscar, elegir estado).
 *
 * Antes este archivo alternaba la lista con una «vista de agenda» por horas;
 * esa segunda vista se retiró porque la lista ya escribe la hora una vez por
 * franja y cuelga de ella las reservas que la comparten, que era lo único que
 * la agenda contaba.
 *
 * El botón es un formulario de verdad (POST /admin/reservaciones/estado y
 * vuelta a la lista): sin JS funciona igual. Aquí se intercepta para
 * confirmar con el componente, publicar en JSON y repintar SÓLO la lista, que
 * conserva la posición y deja ver el aviso de «hecho».
 *
 * Se escucha `submit` en CAPTURA sobre el documento y se detiene la
 * propagación: admin.js engancha su diálogo genérico en el propio formulario
 * (data-confirm-delete) y, en el elemento destino, los oyentes corren por
 * orden de registro. Capturando arriba, éste va primero; y si este archivo no
 * llega a cargar, el genérico sigue preguntando. Además, el repintado parcial
 * crea formularios nuevos que el genérico ya no conoce: la delegación sí.
 */
(function () {
    'use strict';

    var URL_ESTADO = '/admin/api/reservaciones/estado';

    function aviso(texto, variante) {
        if (window.AppNotice && typeof window.AppNotice.show === 'function') {
            window.AppNotice.show({ text: texto, variant: variante || 'info' });
        }
    }

    function mensajeDe(datos, respaldo) {
        var texto = datos && (datos.mensaje || datos.message);
        // Un mensaje vacío del servidor no debe producir una caja en blanco.
        return texto && String(texto).trim() !== '' ? String(texto) : respaldo;
    }

    function campo(form, nombre) {
        var input = form.querySelector('input[name="' + nombre + '"]');
        return input ? input.value : '';
    }

    /*
     * apiEstado lee $_POST, así que viaja como formulario y no como JSON. El
     * token es el mismo campo oculto que usa el envío sin JS.
     */
    function publicar(form) {
        var cuerpo = new URLSearchParams();
        ['id', 'estado', 'motivo', 'admin_csrf'].forEach(function (nombre) {
            cuerpo.set(nombre === 'id' ? 'reservation_id' : nombre, campo(form, nombre));
        });

        return fetch(URL_ESTADO, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                'X-CSRF-Token': campo(form, 'admin_csrf')
            },
            body: cuerpo
        }).then(function (respuesta) {
            return respuesta.json().catch(function () { return {}; }).then(function (datos) {
                if (!respuesta.ok || datos.ok === false) {
                    throw new Error(mensajeDe(datos, 'No fue posible registrar la ausencia.'));
                }
                return datos;
            });
        });
    }

    /*
     * Repinta la lista con la misma dirección, a través de los filtros
     * reactivos: así se conservan la búsqueda, el estado y el periodo, y las
     * cifras de la tira se ponen al día. Sin ellos, recarga entera.
     */
    function repintar(id) {
        var form = document.querySelector('.reservations-buscador[data-reactive-filters]');
        var instancia = form && window.AdminReactiveFilters ? window.AdminReactiveFilters.init(form) : null;
        if (!instancia || typeof instancia.request !== 'function') {
            window.location.reload();
            return;
        }

        // El foco estaba en un botón que el repintado destruye: se devuelve a
        // la misma reserva si sigue en la lista (ahora «No llegó») y, si el
        // filtro la sacó, a la tira de estados, que dice cuántas quedan.
        var alTerminar = function () {
            document.removeEventListener('admin:reactive-updated', alTerminar);
            var fila = document.querySelector('.reservations-fila[data-reservacion-id="' + id + '"] .reservations-fila__nombre');
            var destino = fila || document.querySelector('.reservations-estados');
            if (destino) {
                destino.focus({ preventScroll: true });
            }
        };
        document.addEventListener('admin:reactive-updated', alTerminar);
        instancia.request(window.location.href);
    }

    function confirmarNoLlego(form) {
        var nombre = form.getAttribute('data-nombre') || 'La reservación';
        var boton = form.querySelector('button[type="submit"]');

        if (!window.ConfirmationModal) {
            // Sin el componente no se registra nada: nunca sin confirmar.
            aviso('No se pudo abrir la confirmación. Recarga la página.', 'error');
            return;
        }

        window.ConfirmationModal.get().open({
            variant: 'warning',
            eyebrow: form.getAttribute('data-confirm-eyebrow') || 'Reservaciones',
            title: form.getAttribute('data-confirm-title') || 'Registrar que no llegó',
            description: form.getAttribute('data-confirm-description') || '',
            consequence: form.getAttribute('data-confirm-consequence') || '',
            secondaryLabel: 'Cancelar',
            primaryLabel: form.getAttribute('data-confirm-primary') || 'Registrar que no llegó',
            onPrimary: function () {
                if (boton) {
                    boton.disabled = true;
                    boton.setAttribute('aria-busy', 'true');
                }
                return publicar(form).then(function () {
                    aviso('Se registró que ' + nombre + ' no llegó.', 'success');
                    repintar(campo(form, 'id'));
                }).catch(function (error) {
                    if (boton) {
                        boton.disabled = false;
                        boton.removeAttribute('aria-busy');
                    }
                    aviso(error && error.message ? error.message : 'No fue posible registrar la ausencia.', 'error');
                    throw error;
                });
            }
        });
    }

    document.addEventListener('submit', function (evento) {
        var form = evento.target;
        if (!form || !form.matches || !form.matches('form[data-reservacion-no-llego]')) {
            return;
        }
        evento.preventDefault();
        evento.stopPropagation();
        confirmarNoLlego(form);
    }, true);
})();
