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

    /** Reordena el <tbody> por una columna y una dirección ya decididas. */
    function ordenarPor(table, index, type, dir) {
        var tbody = table.tBodies[0];
        if (!tbody) return;

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

    /**
     * Vuelve a aplicar el orden vigente.
     *
     * Hace falta cuando el <tbody> se vuelve a pintar por debajo: los filtros
     * reactivos sustituyen el listado entero y las gráficas de analíticas lo
     * generan desde JS. El <th> conserva su aria-sort, pero las filas llegan en
     * el orden del servidor, así que sin esto la flecha mentía.
     */
    function reaplicar(table) {
        if (!table) return;
        var thead = table.tHead;
        if (!thead || !thead.rows[0]) return;

        var headers = Array.prototype.slice.call(thead.rows[0].cells);
        for (var i = 0; i < headers.length; i++) {
            var dir = headers[i].getAttribute('aria-sort');
            if (dir === 'ascending' || dir === 'descending') {
                ordenarPor(table, i, headers[i].getAttribute('data-sort-type') || 'text', dir);
                return;
            }
        }
    }

    function initTable(table) {
        if (!table) return;
        if (table.dataset.sortReady === '1') {
            // Ya estaba enganchada: lo único que puede haber cambiado son las
            // filas, así que se re-aplica el orden en vez de salir sin más.
            reaplicar(table);
            return;
        }
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

                ordenarPor(table, index, type, dir);
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

    function init(raiz) {
        var ambito = (raiz && raiz.querySelectorAll) ? raiz : document;
        var tables = ambito.querySelectorAll('table.admin-table[data-sortable]');
        Array.prototype.forEach.call(tables, initTable);
    }

    /*
     * API pública.
     *
     * El auto-arranque en DOMContentLoaded sólo alcanza a las tablas que ya
     * están en el HTML. Las de menú y usuarios las sustituyen los filtros
     * reactivos, y la de ingeniería de menú la pinta nivel1.js después: las tres
     * necesitan poder re-enganchar o re-ordenar a mano.
     */
    window.AdminTableSort = {
        init: init,
        initTable: initTable,
        reaplicar: reaplicar
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }

    // Los filtros reactivos reemplazan el contenedor de resultados entero: la
    // tabla nueva no tiene los listeners y llega en el orden del servidor.
    document.addEventListener('admin:reactive-updated', function () { init(); });
})();
