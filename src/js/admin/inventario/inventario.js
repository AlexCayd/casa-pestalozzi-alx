/**
 * Inventario: alta de merma desde la tabla de existencias.
 *
 * El modal es uno solo para toda la tabla; cada botón de fila lo rellena con
 * sus data-*. La apertura y el cierre los maneja initAdminModals() de admin.js
 * (alterna [hidden] y .is-open); aquí solo se copian los datos del ingrediente.
 */
(function () {
    function init() {
        var form = document.querySelector('[data-merma-form]');
        if (!form) {
            return;
        }

        var idInput = form.querySelector('[data-merma-id]');
        var cantidad = form.querySelector('[data-merma-cantidad]');
        var modal = form.closest('[data-admin-modal]');
        var nombre = modal ? modal.querySelector('[data-merma-nombre]') : null;
        var stock = modal ? modal.querySelector('[data-merma-stock]') : null;
        var unidad = modal ? modal.querySelector('[data-merma-unidad]') : null;

        document.addEventListener('click', function (event) {
            var boton = event.target.closest('[data-merma-open]');
            if (!boton) {
                return;
            }

            idInput.value = boton.getAttribute('data-id') || '';
            if (nombre) {
                nombre.textContent = boton.getAttribute('data-nombre') || 'este ingrediente';
            }
            if (stock) {
                stock.textContent = boton.getAttribute('data-stock') || '—';
            }
            if (unidad) {
                unidad.textContent = boton.getAttribute('data-unidad') || '';
            }

            // La cantidad no se hereda de la merma anterior: cada registro es
            // un hecho distinto y arrastrarla invita a guardar el número de otro
            // ingrediente sin darse cuenta.
            form.reset();
            idInput.value = boton.getAttribute('data-id') || '';

            // El modal se abre en el mismo clic vía data-admin-modal-open; el
            // foco se pide después para no pelearse con esa transición.
            window.requestAnimationFrame(function () {
                if (cantidad) {
                    cantidad.focus();
                }
            });
        });

        form.addEventListener('submit', function (event) {
            var valor = parseFloat(cantidad ? cantidad.value : '');
            if (!(valor > 0)) {
                event.preventDefault();
                if (window.AppNotice) {
                    window.AppNotice.show({
                        text: 'Escribe cuánto se mermó: debe ser mayor a cero.',
                        variant: 'error'
                    });
                }
                if (cantidad) {
                    cantidad.focus();
                }
            }
        });
    }

    /*
     * Tabla repetible de proveedores en la ficha del ingrediente.
     *
     * El <select> del proveedor se clona de un <template> que PHP ya pintó con
     * el catálogo completo: así no hay que serializar la lista a JavaScript ni
     * mantenerla en dos sitios.
     *
     * El "preferente" es un radio con el índice de fila como valor, y por eso
     * hay que renumerarlo cada vez que se agrega o se quita una: un radio con
     * valores repetidos manda el primero que encuentra, no el que se marcó.
     */
    function initProveedores() {
        var raiz = document.querySelector('[data-proveedores]');
        if (!raiz) {
            return;
        }

        var filas = raiz.querySelector('[data-proveedores-filas]');
        var plantilla = raiz.querySelector('[data-proveedor-plantilla]');
        var agregar = raiz.querySelector('[data-proveedor-agregar]');
        var vacio = raiz.querySelector('[data-proveedores-vacio]');

        if (!filas || !plantilla || !agregar) {
            return;
        }

        function renumerar() {
            var todas = filas.querySelectorAll('.admin-proveedores__fila');
            for (var i = 0; i < todas.length; i++) {
                var radio = todas[i].querySelector('input[name="proveedor_preferente"]');
                if (radio) {
                    radio.value = String(i);
                }
            }
            if (vacio) {
                vacio.hidden = todas.length > 0;
            }
            // Sin preferente marcado, el reabastecimiento no tendría a quién
            // proponer: se marca la primera, que es la que el orden de la ficha
            // ya presenta como principal.
            if (todas.length && !filas.querySelector('input[name="proveedor_preferente"]:checked')) {
                var primero = todas[0].querySelector('input[name="proveedor_preferente"]');
                if (primero) {
                    primero.checked = true;
                }
            }
        }

        agregar.addEventListener('click', function () {
            filas.appendChild(plantilla.content.cloneNode(true));
            renumerar();
            if (window.AdminScrollLock) {
                window.AdminScrollLock.remedir();
            }
        });

        raiz.addEventListener('click', function (event) {
            if (!event.target.closest('[data-proveedor-quitar]')) {
                return;
            }
            var fila = event.target.closest('.admin-proveedores__fila');
            if (fila) {
                fila.remove();
                renumerar();
            }
        });

        renumerar();
    }

    function boot() {
        init();
        initProveedores();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
