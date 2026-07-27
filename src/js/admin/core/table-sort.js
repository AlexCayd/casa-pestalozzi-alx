/**
 * Ordenador de tablas reutilizable del panel admin.
 * Realza cualquier <table class="admin-table" data-sortable> con encabezados
 * clicables. Cada <th> ordenable declara data-sort-type="text|number"; un
 * <th data-sort-disabled> queda excluido (p.ej. la columna de acciones).
 * Cicla asc → desc y refleja el estado con aria-sort para accesibilidad y CSS.
 */
(function () {
    'use strict';

    function cellValue(row, index, type) {
        var cell = row.children[index];
        if (!cell) return type === 'number' ? 0 : '';
        var raw = (cell.getAttribute('data-sort-value') || cell.textContent || '').trim();
        if (type === 'number') {
            var num = parseFloat(raw.replace(/[^0-9.\-]/g, ''));
            return isNaN(num) ? -Infinity : num;
        }
        return raw.toLowerCase();
    }

    function initTable(table) {
        if (table.dataset.sortReady === '1') return;
        table.dataset.sortReady = '1';

        var thead = table.tHead;
        var tbody = table.tBodies[0];
        if (!thead || !tbody) return;

        var headers = thead.rows[0] ? Array.prototype.slice.call(thead.rows[0].cells) : [];

        headers.forEach(function (th, index) {
            if (th.hasAttribute('data-sort-disabled')) return;
            var type = th.getAttribute('data-sort-type') || 'text';
            th.classList.add('is-sortable');
            th.setAttribute('role', 'button');
            th.setAttribute('tabindex', '0');
            if (!th.hasAttribute('aria-sort')) th.setAttribute('aria-sort', 'none');

            function sort() {
                var current = th.getAttribute('aria-sort');
                var dir = current === 'ascending' ? 'descending' : 'ascending';

                headers.forEach(function (h) {
                    if (!h.hasAttribute('data-sort-disabled')) h.setAttribute('aria-sort', 'none');
                });
                th.setAttribute('aria-sort', dir);

                var rows = Array.prototype.slice.call(tbody.rows);
                var factor = dir === 'ascending' ? 1 : -1;
                rows.sort(function (a, b) {
                    var va = cellValue(a, index, type);
                    var vb = cellValue(b, index, type);
                    if (type === 'number') return (va - vb) * factor;
                    return va.localeCompare(vb, 'es') * factor;
                });
                rows.forEach(function (r) { tbody.appendChild(r); });
            }

            th.addEventListener('click', sort);
            th.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sort();
                }
            });
        });
    }

    function init() {
        var tables = document.querySelectorAll('table.admin-table[data-sortable]');
        Array.prototype.forEach.call(tables, initTable);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
