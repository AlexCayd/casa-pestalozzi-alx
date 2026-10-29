(function () {
    function init() {
        document.querySelectorAll('[data-casa-receta]').forEach(function (root) {
            const list = root.querySelector('[data-casa-filas]');
            const add = root.querySelector('[data-casa-agregar]');
            if (!list || !add) return;
            const prototype = list.querySelector('[data-casa-fila]').cloneNode(true);
            prototype.querySelectorAll('.admin-select').forEach(function (node) { node.replaceWith(node.querySelector('select')); });
            prototype.querySelectorAll('select').forEach(function (node) { node.hidden = false; node.classList.remove('admin-select__native'); node.removeAttribute('data-enhanced'); node.removeAttribute('aria-hidden'); node.removeAttribute('tabindex'); });
            prototype.querySelectorAll('label[id]').forEach(function (node) { node.removeAttribute('id'); });
            let next = list.children.length;
            add.addEventListener('click', function () {
                if (list.children.length >= 100) return;
                const row = prototype.cloneNode(true);
                row.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace(/\[\d+\]/, '[' + next + ']'); input.value = input.tagName === 'SELECT' ? '0' : ''; });
                next++; list.appendChild(row); if (window.AdminSelect) window.AdminSelect.init(row); (row.querySelector('.admin-select__trigger') || row.querySelector('select')).focus();
            });
            list.addEventListener('click', function (event) {
                const button = event.target.closest('[data-casa-quitar]');
                if (!button) return;
                const row = button.closest('[data-casa-fila]');
                if (list.children.length === 1) { row.querySelectorAll('input').forEach(function (input) { input.value = ''; }); row.querySelector('select').value = '0'; row.querySelector('select').dispatchEvent(new Event('change', { bubbles: true })); }
                else row.remove();
                add.focus();
            });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
