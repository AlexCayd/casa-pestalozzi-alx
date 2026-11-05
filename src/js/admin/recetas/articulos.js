(function () {
    'use strict';
    // Los enlaces de origen también pueden apuntar a información plegada.
    function abrirSeccion() {
        var id = window.location.hash.slice(1);
        if (!id) return;
        var seccion = document.getElementById(id);
        if (seccion && seccion.tagName === 'DETAILS') seccion.open = true;
    }
    abrirSeccion();
    window.addEventListener('hashchange', abrirSeccion);
    document.querySelectorAll('[data-m2-builder]').forEach(function (builder) {
        var rows = builder.querySelector('[data-m2-rows]');
        var template = builder.querySelector('[data-m2-template]');
        var status = builder.querySelector('[data-m2-status]');
        var nextIndex = rows.children.length;
        function sync(row, changed) {
            var article = row.querySelector('[data-m2-articulo]');
            var mode = row.querySelector('[data-m2-modalidad]');
            var version = row.querySelector('[data-m2-version]');
            var unit = row.querySelector('[data-m2-unidad]');
            if (changed === article && article.selectedOptions[0] && article.selectedOptions[0].dataset.unidad) { unit.value = article.selectedOptions[0].dataset.unidad; unit.dispatchEvent(new Event('change', {bubbles:true})); }
            Array.from(version.options).forEach(function (op) { op.hidden = !!op.value && op.dataset.articulo !== article.value; });
            if (changed === article || mode.value === 'almacenado') version.value = '';
            version.disabled = mode.value === 'almacenado';
            version.required = mode.value === 'desglosar';
        }
        rows.querySelectorAll('[data-m2-row]').forEach(function (row) { sync(row, null); });
        builder.addEventListener('change', function (event) {
            var row = event.target.closest('[data-m2-row]');
            if (row) sync(row, event.target);
        });
        builder.addEventListener('click', function (event) {
            var add = event.target.closest('[data-m2-agregar]');
            var remove = event.target.closest('[data-m2-quitar]');
            if (add) {
                if (rows.children.length >= 100) { status.textContent = 'La elaboración admite hasta cien componentes.'; return; }
                var holder = document.createElement('template');
                holder.innerHTML = template.innerHTML.replace(/__INDEX__/g, String(nextIndex++));
                rows.appendChild(holder.content.cloneNode(true));
                var added = rows.lastElementChild;
                sync(added, null);
                if (window.AdminSelect) window.AdminSelect.init(added);
                (added.querySelector('.admin-select__trigger') || added.querySelector('select')).focus();
                status.textContent = 'Componente agregado.';
            }
            if (remove) {
                if (rows.children.length === 1) { status.textContent = 'Conserva al menos un componente.'; return; }
                var row = remove.closest('[data-m2-row]');
                var focus = row.nextElementSibling || row.previousElementSibling;
                row.remove();
                if (focus) focus.querySelector('select').focus();
                status.textContent = 'Componente retirado del borrador.';
            }
            rows.querySelectorAll('[data-m2-numero]').forEach(function (n, i) { n.textContent = String(i + 1); });
        });
        var form = builder.closest('form');
        if (form) form.addEventListener('submit', function () { form.setAttribute('aria-busy', 'true'); });
    });
    // Editor de reventa (un solo artículo): elegir el artículo propone su
    // unidad, igual que en el editor general. Se puede cambiar después (750 ml
    // de un vino guardado en l, por ejemplo); el servidor convierte.
    document.querySelectorAll('[data-m2-reventa]').forEach(function (bloque) {
        var articulo = bloque.querySelector('[data-m2-reventa-articulo]');
        var unidad = bloque.querySelector('[data-m2-reventa-unidad]');
        if (!articulo || !unidad) return;
        articulo.addEventListener('change', function () {
            var op = articulo.selectedOptions[0];
            if (!op || !op.dataset.unidad) return;
            unidad.value = op.dataset.unidad;
            unidad.dispatchEvent(new Event('change', {bubbles:true}));
        });
        var form = bloque.closest('form');
        if (form) form.addEventListener('submit', function () { form.setAttribute('aria-busy', 'true'); });
    });
    var accionCapacidades = document.querySelector('input[name="accion"][value="configurar"]');
    var composicion = document.querySelector('[data-m2-builder]');
    if (accionCapacidades && accionCapacidades.form) {
        var capacidadesForm = accionCapacidades.form;
        var dependenciaError = document.querySelector('[data-m2-capacidad-error]');
        capacidadesForm.addEventListener('submit', function (event) {
            var previa = document.querySelector('[data-m2-capacidad="produccion_previa"]');
            var elaborable = document.querySelector('[data-m2-capacidad="elaborable"]');
            var almacenable = document.querySelector('[data-m2-capacidad="almacenable"]');
            if (!previa || !elaborable || !almacenable || !previa.checked || (elaborable.checked && almacenable.checked)) {
                if (dependenciaError) dependenciaError.hidden = true;
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            if (dependenciaError) {
                dependenciaError.textContent = 'Para elaborar antes de vender, activa también elaboración propia y almacenamiento.';
                dependenciaError.hidden = false;
            }
            (elaborable.checked ? almacenable : elaborable).focus();
        });
    }
    if (accionCapacidades && composicion) {
        var capacidades = accionCapacidades.form;
        var receta = composicion.closest('form');
        var inicial = JSON.stringify(Array.from(new FormData(receta).entries()));
        capacidades.addEventListener('submit', function (event) {
            if (capacidades.dataset.descarteConfirmado === '1' || (!receta.hasAttribute('data-m2-pendiente') && inicial === JSON.stringify(Array.from(new FormData(receta).entries())))) return;
            event.preventDefault();
            if (!window.ConfirmationModal) return;
            window.ConfirmationModal.get().open({
                variant: 'warning', eyebrow: 'Cambios pendientes', title: 'La elaboración tiene cambios sin publicar',
                description: 'Guardar capacidades recarga la ficha y descarta los cambios pendientes de elaboración. Cancela para seguir editándolos.',
                consequence: 'La receta no se guardará ni publicará al cambiar capacidades.',
                secondaryLabel: 'Seguir editando', primaryLabel: 'Descartar y guardar capacidades',
                onPrimary: function () { capacidades.dataset.descarteConfirmado = '1'; capacidades.requestSubmit(); }
            });
        });
    }
    document.querySelectorAll('[data-m2-draft-status]').forEach(function (status) {
        var form = status.closest('form');
        var saved = status.textContent;
        var initial = JSON.stringify(Array.from(new FormData(form).entries()));
        function markDraft() {
            status.textContent = form.hasAttribute('data-m2-pendiente') || initial !== JSON.stringify(Array.from(new FormData(form).entries()))
                ? 'Cambios pendientes de publicar. La revisión no guarda la receta.' : saved;
        }
        form.addEventListener('input', markDraft);
        form.addEventListener('change', markDraft);
        form.addEventListener('click', function (event) {
            if (event.target.closest('[data-m2-agregar], [data-m2-quitar]')) window.requestAnimationFrame(markDraft);
        });
    });
})();
