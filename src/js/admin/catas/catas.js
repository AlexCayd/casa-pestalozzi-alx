/**
 * Módulo de Catas del panel.
 *
 * Lo único que necesita JS es el interruptor de CUPO de la agenda: al cambiarlo
 * envía su formulario, sin botón intermedio. El marcado trae el botón dentro de
 * <noscript>, así que sin este archivo el módulo sigue funcionando; también
 * actualiza la etiqueta del switch en el formulario de alta y edición, donde no
 * hay envío que disparar.
 *
 * El interruptor no decide la visibilidad: eso lo decide la fecha. Las etiquetas
 * tienen que decir «cupo» y no «landing», o prometen algo que no hacen.
 */
(function () {
    'use strict';

    var TEXTO_ENCENDIDO = 'Con cupo';
    var TEXTO_APAGADO = 'Sin cupo';

    function etiquetaDe(input) {
        var contenedor = input.closest('.admin-switch');
        return contenedor ? contenedor.querySelector('[data-cata-switch-label]') : null;
    }

    function pintarEtiqueta(input) {
        var etiqueta = etiquetaDe(input);
        if (etiqueta) etiqueta.textContent = input.checked ? TEXTO_ENCENDIDO : TEXTO_APAGADO;
    }

    /* La agenda: cada ficha lleva su propio formulario y el cambio se envía solo. */
    function initInterruptores() {
        var switches = document.querySelectorAll('[data-cata-switch]');
        if (!switches.length) return;

        Array.prototype.forEach.call(switches, function (input) {
            input.addEventListener('change', function () {
                var form = input.closest('form');
                if (!form || form.dataset.enviando === '1') return;

                // El envío recarga la página, pero entre el clic y la recarga
                // hay margen para un segundo clic que dispararía dos POST con
                // valores contrarios. La marca lo corta sin deshabilitar el
                // checkbox: deshabilitado no viajaría en el formulario.
                form.dataset.enviando = '1';
                pintarEtiqueta(input);
                form.submit();
            });
        });
    }

    /* El formulario de alta y edición: aquí el switch es un campo más y sólo
       tiene que decir en qué posición está. */
    function initEtiquetaFormulario() {
        var input = document.querySelector('.admin-catas__publicacion input[name="disponible"]');
        if (!input) return;

        input.addEventListener('change', function () { pintarEtiqueta(input); });
    }

    function init() {
        initInterruptores();
        initEtiquetaFormulario();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
