(function () {
    'use strict';
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
})();
