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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
