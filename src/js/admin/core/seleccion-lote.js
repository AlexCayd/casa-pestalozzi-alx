/**
 * Selección en lote dentro de un formulario: «seleccionar todos», recuento
 * anunciado y envío vacío bloqueado.
 *
 * Nació para confirmar el tipo de venta sugerido en Menú (?tipo=por_confirmar),
 * pero no sabe nada de Menú: cualquier <form data-seleccion-lote> con casillas
 * [data-seleccion-item] lo usa. Las casillas son nativas y el formulario es un
 * POST normal, así que sin JS todo funciona marcando una por una; esto sólo
 * añade el atajo y el recuento.
 *
 * Los listeners van delegados en document: el listado de Menú llega por la
 * recarga parcial (reactive-filters.js) y reemplaza el formulario entero, y así
 * no hay que volver a enlazar nada. Sólo el «todos», que nace oculto para que
 * sin JS no prometa algo que no hace, se muestra al llegar el contenido.
 *
 * Marcado:
 * - form[data-seleccion-lote] con data-seleccion-singular / -plural opcionales.
 * - input[type=checkbox][data-seleccion-item] por fila.
 * - [data-seleccion-todos-envoltura][hidden] > input[data-seleccion-todos].
 * - [data-seleccion-estado] (role="status") para el recuento.
 */
(function () {
    'use strict';

    function items(form) {
        return Array.prototype.slice.call(form.querySelectorAll('input[data-seleccion-item]:not(:disabled)'));
    }

    function texto(form, n) {
        var singular = form.getAttribute('data-seleccion-singular') || 'elemento';
        var plural = form.getAttribute('data-seleccion-plural') || 'elementos';
        if (n === 0) return 'Ningún ' + singular + ' seleccionado.';
        return n + ' ' + (n === 1 ? singular + ' seleccionado.' : plural + ' seleccionados.');
    }

    // anunciar=false al preparar: el texto inicial del servidor ya explica qué
    // hacer, y repetir «Ningún elemento seleccionado» al cargar sería ruido.
    function sincronizar(form, anunciar) {
        var lista = items(form);
        var marcados = lista.filter(function (c) { return c.checked; }).length;
        var todos = form.querySelector('input[data-seleccion-todos]');
        if (todos) {
            todos.checked = lista.length > 0 && marcados === lista.length;
            todos.indeterminate = marcados > 0 && marcados < lista.length;
        }
        var estado = form.querySelector('[data-seleccion-estado]');
        if (estado && (anunciar || marcados > 0)) {
            estado.textContent = texto(form, marcados);
        }
    }

    function preparar(raiz) {
        var forms = (raiz || document).querySelectorAll('form[data-seleccion-lote]');
        Array.prototype.forEach.call(forms, function (form) {
            var envoltura = form.querySelector('[data-seleccion-todos-envoltura]');
            if (envoltura) envoltura.hidden = false;
            // Al volver con «Atrás» el navegador restaura las casillas: el
            // «todos» y el recuento tienen que reflejarlo.
            sincronizar(form, false);
        });
    }

    document.addEventListener('change', function (evento) {
        var objetivo = evento.target;
        if (!objetivo || !objetivo.closest) return;
        var form = objetivo.closest('form[data-seleccion-lote]');
        if (!form) return;

        if (objetivo.matches('input[data-seleccion-todos]')) {
            items(form).forEach(function (c) { c.checked = objetivo.checked; });
            sincronizar(form, true);
        } else if (objetivo.matches('input[data-seleccion-item]')) {
            sincronizar(form, true);
        }
    });

    document.addEventListener('submit', function (evento) {
        var form = evento.target;
        if (!form || !form.matches || !form.matches('form[data-seleccion-lote]')) return;

        if (form.getAttribute('aria-busy') === 'true') {
            evento.preventDefault();
            return;
        }

        var lista = items(form);
        if (!lista.some(function (c) { return c.checked; })) {
            // El servidor también lo rechaza; aquí se evita el viaje y se dice
            // qué hacer en el mismo sitio, sin diálogos nativos.
            evento.preventDefault();
            var estado = form.querySelector('[data-seleccion-estado]');
            if (estado) estado.textContent = 'Marca al menos un ' + (form.getAttribute('data-seleccion-singular') || 'elemento') + ' para continuar.';
            if (lista[0]) lista[0].focus();
            return;
        }

        form.setAttribute('aria-busy', 'true');
    });

    // Una página restaurada desde la caché de «Atrás» conserva aria-busy del
    // envío anterior y dejaría el botón muerto.
    window.addEventListener('pageshow', function () {
        Array.prototype.forEach.call(document.querySelectorAll('form[data-seleccion-lote][aria-busy]'), function (form) {
            form.removeAttribute('aria-busy');
        });
    });

    document.addEventListener('DOMContentLoaded', function () { preparar(document); });
    document.addEventListener('admin:reactive-updated', function (evento) {
        preparar((evento.detail && evento.detail.target) || document);
    });
})();
