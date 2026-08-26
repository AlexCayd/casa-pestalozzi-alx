/**
 * Módulo de Catas del panel.
 *
 * Lo único que necesita JS es enviar el formulario de estado al elegir en el
 * <select>: durante el día de la cata se marcan asistencias en cadena y un
 * botón extra por fila sobra. El marcado trae el botón dentro de <noscript>,
 * así que sin este archivo el módulo sigue funcionando.
 */
(function () {
    'use strict';

    function initEstadoInscripcion() {
        var selects = document.querySelectorAll('[data-cata-estado]');
        if (!selects.length) return;

        Array.prototype.forEach.call(selects, function (select) {
            // Se guarda el valor de partida para no reenviar si se vuelve a él.
            select.dataset.valorPrevio = select.value;

            select.addEventListener('change', function () {
                if (select.value === select.dataset.valorPrevio) return;

                var form = select.closest('form');
                if (!form) return;

                select.disabled = true;
                form.submit();
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initEstadoInscripcion);
    } else {
        initEstadoInscripcion();
    }
})();
