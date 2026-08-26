/**
 * Módulo de Catering del panel.
 *
 * Mismo patrón que catas: el <select> de estado envía su formulario al cambiar
 * para poder avanzar varias solicitudes seguidas desde la bandeja. El botón de
 * respaldo vive en <noscript>.
 */
(function () {
    'use strict';

    function initEstadoSolicitud() {
        var selects = document.querySelectorAll('[data-catering-estado]');
        if (!selects.length) return;

        Array.prototype.forEach.call(selects, function (select) {
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
        document.addEventListener('DOMContentLoaded', initEstadoSolicitud);
    } else {
        initEstadoSolicitud();
    }
})();
