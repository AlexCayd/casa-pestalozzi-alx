/**
 * Listado de reservaciones: alterna entre la lista y la agenda por horas.
 *
 * Los dos paneles llegan renderizados desde PHP dentro del bloque que sustituyen
 * los filtros reactivos, así que la agenda se rehace sola con cada búsqueda y
 * aquí sólo hace falta enseñar uno u otro.
 *
 * Delegado en document: `reactive-filters.js` reemplaza el HTML del listado por
 * innerHTML y un listener atado a los radios se perdería en la primera búsqueda.
 */
(function () {
    'use strict';

    var CLAVE = 'cp-admin-reservaciones-vista';

    function panelesDe(radio) {
        var tarjeta = radio.closest('.reservations-table-card');
        return tarjeta ? tarjeta.querySelectorAll('[data-reservations-panel]') : [];
    }

    function aplicar(radio) {
        var paneles = panelesDe(radio);
        for (var i = 0; i < paneles.length; i++) {
            paneles[i].hidden = paneles[i].dataset.reservationsPanel !== radio.value;
        }

        try {
            window.sessionStorage.setItem(CLAVE, radio.value);
        } catch (error) {
            // La vista sigue cambiando aunque no se pueda recordar la elección.
        }
    }

    function restaurar() {
        var elegida = null;
        try {
            elegida = window.sessionStorage.getItem(CLAVE);
        } catch (error) {
            elegida = null;
        }
        if (!elegida) {
            return;
        }

        var radio = document.querySelector('[data-reservations-view][value="' + elegida + '"]');
        if (radio && !radio.checked) {
            radio.checked = true;
            aplicar(radio);
        }
    }

    document.addEventListener('change', function (evento) {
        var radio = evento.target.closest('[data-reservations-view]');
        if (radio && radio.checked) {
            aplicar(radio);
        }
    });

    // Tras filtrar, el bloque vuelve con la lista por omisión: se reaplica la
    // vista que el usuario tenía elegida.
    document.addEventListener('admin:reactive-updated', restaurar);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restaurar);
    } else {
        restaurar();
    }
})();
