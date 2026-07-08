/**
 * Select custom del panel admin.
 * Mejora progresivamente los <select> nativos con un desplegable totalmente
 * estilizable (tema oscuro de marca), manteniendo el <select> oculto como
 * fuente de verdad para el envío de formularios. La lista se monta en <body>
 * (portal) y se posiciona fija para no ser recortada por tarjetas con overflow.
 *
 * Se excluyen las vistas operativas (mapa/áreas/operación) y cualquier select
 * marcado con [data-native].
 */
(function () {
    var uid = 0;
    var EXCLUDE = '.mapa-page, .area-page, .admin-reservation-operation';
    var registry = [];

    function enhance(select) {
        if (select.dataset.enhanced || select.multiple || select.closest(EXCLUDE)) {
            return;
        }
        select.dataset.enhanced = '1';

        var id = 'admin-select-' + (++uid);

        var wrapper = document.createElement('div');
        wrapper.className = 'admin-select';
        if (select.disabled) {
            wrapper.classList.add('is-disabled');
        }
        select.parentNode.insertBefore(wrapper, select);
        wrapper.appendChild(select);
        select.classList.add('admin-select__native');
        select.setAttribute('tabindex', '-1');
        select.setAttribute('aria-hidden', 'true');

        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'admin-select__trigger';
        trigger.setAttribute('aria-haspopup', 'listbox');
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-controls', id);
        if (select.disabled) {
            trigger.disabled = true;
        }
        trigger.innerHTML =
            '<span class="admin-select__value"></span>' +
            '<svg class="admin-select__chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" ' +
            'stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
            '<path d="m6 9 6 6 6-6"/></svg>';
        wrapper.appendChild(trigger);

        var list = document.createElement('ul');
        list.className = 'admin-select__list';
        list.id = id;
        list.setAttribute('role', 'listbox');
        list.hidden = true;

        var valueEl = trigger.querySelector('.admin-select__value');
        var options = [];
        var activeLi = null;
        var open = false;

        function build() {
            list.innerHTML = '';
            options = Array.prototype.map.call(select.options, function (opt, i) {
                var li = document.createElement('li');
                li.className = 'admin-select__option';
                li.id = id + '-opt-' + i;
                li.setAttribute('role', 'option');
                li.dataset.index = String(i);
                li.textContent = opt.textContent;
                if (opt.disabled) {
                    li.setAttribute('aria-disabled', 'true');
                }
                list.appendChild(li);
                return li;
            });
            syncValue();
        }

        function syncValue() {
            var opt = select.options[select.selectedIndex];
            valueEl.textContent = opt ? opt.textContent : '';
            options.forEach(function (li, i) {
                var isSel = i === select.selectedIndex;
                li.setAttribute('aria-selected', isSel ? 'true' : 'false');
                li.classList.toggle('is-selected', isSel);
            });
        }

        function setActive(li) {
            if (activeLi) {
                activeLi.classList.remove('is-active');
            }
            activeLi = li;
            if (li) {
                li.classList.add('is-active');
                trigger.setAttribute('aria-activedescendant', li.id);
                li.scrollIntoView({ block: 'nearest' });
            }
        }

        function position() {
            var r = trigger.getBoundingClientRect();
            // La lista crece al contenido, con al menos el ancho del trigger.
            list.style.minWidth = r.width + 'px';
            var listW = list.offsetWidth;
            var left = Math.min(r.left, window.innerWidth - listW - 8);
            list.style.left = Math.max(8, left) + 'px';

            var below = window.innerHeight - r.bottom;
            var listH = Math.min(list.scrollHeight, 264);
            if (below < listH + 12 && r.top > below) {
                list.style.top = (r.top - listH - 6) + 'px';
            } else {
                list.style.top = (r.bottom + 6) + 'px';
            }
        }

        function onScroll() {
            if (open) {
                position();
            }
        }

        function openList() {
            if (select.disabled || open) {
                return;
            }
            registry.forEach(function (other) {
                if (other !== instance) {
                    other.close();
                }
            });
            open = true;
            if (list.parentNode !== document.body) {
                document.body.appendChild(list);
            }
            list.hidden = false;
            position();
            wrapper.classList.add('is-open');
            list.classList.add('is-open');
            trigger.setAttribute('aria-expanded', 'true');
            setActive(list.querySelector('.is-selected') || options[0]);
            window.addEventListener('scroll', onScroll, true);
            window.addEventListener('resize', onScroll);
        }

        function closeList() {
            if (!open) {
                return;
            }
            open = false;
            list.hidden = true;
            list.classList.remove('is-open');
            wrapper.classList.remove('is-open');
            trigger.setAttribute('aria-expanded', 'false');
            trigger.removeAttribute('aria-activedescendant');
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onScroll);
        }

        function choose(i) {
            if (!select.options[i] || select.options[i].disabled) {
                return;
            }
            if (select.selectedIndex !== i) {
                select.selectedIndex = i;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            syncValue();
            closeList();
            trigger.focus();
        }

        function moveActive(dir) {
            var start = activeLi ? parseInt(activeLi.dataset.index, 10) : select.selectedIndex;
            var i = start;
            for (var c = 0; c < options.length; c++) {
                i = (i + dir + options.length) % options.length;
                if (!select.options[i].disabled) {
                    setActive(options[i]);
                    break;
                }
            }
        }

        trigger.addEventListener('click', function () {
            open ? closeList() : openList();
        });

        trigger.addEventListener('keydown', function (event) {
            if (['ArrowDown', 'ArrowUp', 'Enter', ' '].indexOf(event.key) !== -1) {
                event.preventDefault();
                if (!open) {
                    openList();
                } else if (event.key === 'ArrowDown') {
                    moveActive(1);
                } else if (event.key === 'ArrowUp') {
                    moveActive(-1);
                } else if (activeLi) {
                    choose(parseInt(activeLi.dataset.index, 10));
                }
            } else if (event.key === 'Escape' && open) {
                event.preventDefault();
                closeList();
            }
        });

        list.addEventListener('click', function (event) {
            var li = event.target.closest('.admin-select__option');
            if (li && li.getAttribute('aria-disabled') !== 'true') {
                choose(parseInt(li.dataset.index, 10));
            }
        });

        list.addEventListener('mousemove', function (event) {
            var li = event.target.closest('.admin-select__option');
            if (li) {
                setActive(li);
            }
        });

        document.addEventListener('click', function (event) {
            if (open && !wrapper.contains(event.target) && !list.contains(event.target)) {
                closeList();
            }
        });

        // Sincroniza si el valor cambia por código (u otro listener).
        select.addEventListener('change', syncValue);

        var instance = { close: closeList };
        registry.push(instance);

        build();
    }

    function initCustomSelects(root) {
        (root || document).querySelectorAll('select:not([data-native])').forEach(enhance);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initCustomSelects(document); });
    } else {
        initCustomSelects(document);
    }

    window.AdminSelect = { init: initCustomSelects };
})();
