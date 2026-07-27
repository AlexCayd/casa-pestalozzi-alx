/**
 * Constructor de recetas del admin (Productos y Subrecetas).
 * Agrega y quita filas de componentes clonando el <template> del formulario,
 * y monta un buscador inteligente (combobox) para elegir ingredientes/subrecetas
 * cuando el catálogo es grande.
 */
(function () {
    'use strict';

    function readOptions(form) {
        var node = form.querySelector('[data-recipe-options]');
        if (!node) {
            return [];
        }
        try {
            var parsed = JSON.parse(node.textContent || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function initCombo(combo, options) {
        if (!combo || combo.dataset.comboReady === '1') {
            return;
        }
        combo.dataset.comboReady = '1';

        var search = combo.querySelector('[data-combo-search]');
        var hidden = combo.querySelector('[data-combo-value]');
        var list = combo.querySelector('[data-combo-list]');
        if (!search || !hidden || !list) {
            return;
        }

        var current = [];
        var activeIndex = -1;

        function render() {
            var q = (search.value || '').trim().toLowerCase();
            current = options.filter(function (o) {
                return !q || String(o.label).toLowerCase().indexOf(q) !== -1;
            });
            list.innerHTML = '';
            activeIndex = -1;

            if (!current.length) {
                var empty = document.createElement('li');
                empty.className = 'admin-combo__empty';
                empty.textContent = 'Sin coincidencias';
                list.appendChild(empty);
                return;
            }

            var lastGroup = null;
            current.forEach(function (o) {
                if (o.group && o.group !== lastGroup) {
                    lastGroup = o.group;
                    var head = document.createElement('li');
                    head.className = 'admin-combo__group';
                    head.textContent = o.group;
                    list.appendChild(head);
                }
                var li = document.createElement('li');
                li.className = 'admin-combo__option';
                li.setAttribute('role', 'option');
                li.dataset.value = o.value;
                li.textContent = o.label;
                list.appendChild(li);
            });
        }

        function open() {
            render();
            list.hidden = false;
        }

        function close() {
            list.hidden = true;
            activeIndex = -1;
        }

        function choose(opt) {
            hidden.value = opt.value;
            search.value = opt.label;
            close();
        }

        function optionEls() {
            return list.querySelectorAll('.admin-combo__option');
        }

        function highlight() {
            var items = optionEls();
            Array.prototype.forEach.call(items, function (el, i) {
                el.classList.toggle('is-active', i === activeIndex);
            });
            if (activeIndex >= 0 && items[activeIndex]) {
                items[activeIndex].scrollIntoView({ block: 'nearest' });
            }
        }

        search.addEventListener('focus', open);

        search.addEventListener('input', function () {
            hidden.value = '';
            open();
        });

        search.addEventListener('keydown', function (event) {
            if (list.hidden && event.key === 'ArrowDown') {
                open();
                return;
            }
            if (event.key === 'ArrowDown') {
                activeIndex = Math.min(activeIndex + 1, current.length - 1);
                highlight();
                event.preventDefault();
            } else if (event.key === 'ArrowUp') {
                activeIndex = Math.max(activeIndex - 1, 0);
                highlight();
                event.preventDefault();
            } else if (event.key === 'Enter') {
                if (activeIndex >= 0 && current[activeIndex]) {
                    choose(current[activeIndex]);
                    event.preventDefault();
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });

        list.addEventListener('mousedown', function (event) {
            var opt = event.target.closest('.admin-combo__option');
            if (!opt) {
                return;
            }
            event.preventDefault();
            var value = String(opt.dataset.value);
            var found = options.filter(function (o) {
                return String(o.value) === value;
            })[0];
            if (found) {
                choose(found);
            }
        });

        search.addEventListener('blur', function () {
            window.setTimeout(close, 120);
            var text = (search.value || '').trim();
            if (!text) {
                hidden.value = '';
                return;
            }
            if (!hidden.value) {
                var exact = options.filter(function (o) {
                    return String(o.label).toLowerCase() === text.toLowerCase();
                })[0];
                if (exact) {
                    hidden.value = exact.value;
                    search.value = exact.label;
                }
            }
        });
    }

    function initBuilder(form) {
        var rows = form.querySelector('[data-recipe-rows]');
        var template = form.querySelector('[data-recipe-template]');
        var addBtn = form.querySelector('[data-recipe-add]');
        var options = readOptions(form);

        // Monta los comboboxes ya presentes (filas guardadas).
        Array.prototype.forEach.call(form.querySelectorAll('[data-combo]'), function (combo) {
            initCombo(combo, options);
        });

        if (!rows || !template || !addBtn) {
            return;
        }

        function addRow() {
            var clone = template.content
                ? template.content.firstElementChild.cloneNode(true)
                : template.firstElementChild.cloneNode(true);
            rows.appendChild(clone);

            Array.prototype.forEach.call(clone.querySelectorAll('[data-combo]'), function (combo) {
                initCombo(combo, options);
            });

            var control = clone.querySelector('[data-combo-search], select, input');
            if (control) {
                control.focus();
            }
        }

        addBtn.addEventListener('click', addRow);

        rows.addEventListener('click', function (event) {
            var remove = event.target.closest('[data-recipe-remove]');
            if (remove) {
                var row = remove.closest('[data-recipe-row]');
                if (row) {
                    row.remove();
                }
            }
        });
    }

    function init() {
        var forms = document.querySelectorAll('[data-recipe-builder]');
        Array.prototype.forEach.call(forms, initBuilder);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
