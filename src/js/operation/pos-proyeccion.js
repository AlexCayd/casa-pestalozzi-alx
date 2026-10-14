/**
 * Movimiento del POS al cambiar de hora o abrir la ficha.
 *
 * View Transitions de mismo documento con TIPO propio (pos-proyeccion,
 * pos-ficha), así las reglas no tocan la transición del tema ni la de
 * navegación. Los nombres del plano y de la ficha se ponen sólo mientras dura
 * la transición —con nombre fijo tendrían grupo propio en todas, la del tema
 * incluida— y se quitan al terminar. Sin la API o con movimiento reducido, el
 * cambio es en seco. Nunca se usa en el sondeo.
 */
(function (window, document) {
    'use strict';

    var reducido = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: true };
    var enCurso = false;

    function transicion(tipo, actualizar) {
        var plano = document.querySelector('.pos-map .mesas-map__floor');
        var ficha = document.getElementById('pos-ficha');
        if (reducido.matches || typeof document.startViewTransition !== 'function') {
            actualizar();
            return Promise.resolve();
        }
        if (plano) plano.style.viewTransitionName = 'pos-plano';
        if (ficha) ficha.style.viewTransitionName = 'pos-ficha';
        enCurso = true;
        var vt;
        try {
            vt = document.startViewTransition({ update: actualizar, types: [tipo] });
        } catch (error) {
            // Chrome 111-124: sólo la firma de callback, sin tipos.
            vt = document.startViewTransition(actualizar);
        }
        return vt.finished.catch(function () {}).then(function () {
            enCurso = false;
            if (plano) plano.style.viewTransitionName = '';
            if (ficha) ficha.style.viewTransitionName = '';
        });
    }

    /*
     * La «llamada»: un anillo que sale dos veces de las mesas de la reserva
     * enfocada, para que el ojo las encuentre en el plano. Se retira sola.
     */
    function llamar(elementos) {
        (elementos || []).forEach(function (el) {
            if (!el || !el.classList) return;
            el.classList.remove('pos-llamada');
            // Reinicia la animación si ya estaba puesta.
            void el.offsetWidth;
            el.classList.add('pos-llamada');
            var quitar = function () {
                el.classList.remove('pos-llamada');
                el.removeEventListener('animationend', alTerminar);
            };
            var vueltas = 0;
            function alTerminar() {
                vueltas += 1;
                if (vueltas >= 2) quitar();
            }
            el.addEventListener('animationend', alTerminar);
            // Con movimiento reducido no hay animationend: se retira igual.
            window.setTimeout(quitar, 1400);
        });
    }

    window.PosTransicion = {
        ejecutar: transicion,
        llamar: llamar,
        enCurso: function () { return enCurso; }
    };
})(window, document);
