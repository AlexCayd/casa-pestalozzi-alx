/**
 * Componente visual compartido del mapa de mesas.
 *
 * Renderiza y actualiza pines, pero no interpreta su seleccion. Cada
 * controlador de contexto escucha `mapa:mesa-click` y decide que hacer.
 */
(function () {
    var STATE_CLASSES = [
        'mesa-pin--libre',
        'mesa-pin--ocupada',
        'mesa-pin--reservacion-proxima',
        'mesa-pin--seleccionada',
        'mesa-pin--no-utilizable'
    ];

    var STATE_LABELS = {
        libre: 'disponible',
        ocupada: 'ocupada',
        'reservacion-proxima': 'con reservación próxima',
        seleccionada: 'seleccionada',
        'no-utilizable': 'no utilizable'
    };

    function toBoolean(value) {
        return value === true || value === 1 || value === '1' || value === 'true';
    }

    function numberOrNull(value) {
        var parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function clampPercent(value, fallback) {
        var parsed = numberOrNull(value);
        return parsed === null ? fallback : Math.max(0, Math.min(100, parsed));
    }

    function normalizeState(value) {
        var state = String(value || 'libre').toLowerCase();
        var aliases = {
            disponible: 'libre',
            no_reservable: 'no-utilizable',
            'no-reservable': 'no-utilizable',
            'no-utilizable': 'no-utilizable',
            proxima: 'reservacion-proxima',
            bloqueada: 'reservacion-proxima',
            'con-ticket': 'ocupada',
            zona: 'no-utilizable'
        };

        state = aliases[state] || state;
        return ['libre', 'ocupada', 'reservacion-proxima', 'seleccionada', 'no-utilizable'].indexOf(state) !== -1
            ? state
            : 'libre';
    }

    function normalizeClasses(value) {
        if (!Array.isArray(value)) {
            return [];
        }

        return value.filter(function (className) {
            return typeof className === 'string' && /^[a-zA-Z0-9_-]+$/.test(className);
        });
    }

    function normalizeAttributes(value) {
        var attributes = {};

        if (!value || typeof value !== 'object') {
            return attributes;
        }

        Object.keys(value).forEach(function (name) {
            if (/^(data-[a-z0-9_-]+|aria-[a-z0-9_-]+)$/i.test(name) && value[name] != null) {
                attributes[name] = String(value[name]);
            }
        });

        return attributes;
    }

    function normalizeTable(raw) {
        raw = raw || {};

        var id = parseInt(raw.id || '0', 10);
        var state = normalizeState(raw.estadoVisual || raw.estado_visual || raw.estado);
        var seleccionValida = raw.seleccionValida == null ? true : toBoolean(raw.seleccionValida);
        var selected = (toBoolean(raw.seleccionada) || state === 'seleccionada') && seleccionValida;
        var reservable = toBoolean(raw.reservable);

            return {
            id: id,
            nombre: String(raw.nombre || ('Mesa ' + id)),
            tipo: String(raw.tipo || 'mesa').toLowerCase().replace(/[^a-z0-9_-]/g, '') || 'mesa',
            estadoVisual: state,
            x: clampPercent(raw.x != null ? raw.x : raw.pos_x, 50),
            y: clampPercent(raw.y != null ? raw.y : raw.pos_y, 50),
            ancho: numberOrNull(raw.ancho != null ? raw.ancho : raw.width),
            alto: numberOrNull(raw.alto != null ? raw.alto : raw.height),
            reservable: reservable,
            capacidad: Math.max(0, parseInt(raw.capacidad || '0', 10) || 0),
            seleccionada: selected,
            interactivo: raw.interactivo == null ? reservable : toBoolean(raw.interactivo),
                titulo: String(raw.titulo || raw.title || raw.nombre || ('Mesa ' + id)),
                ariaLabel: String(raw.ariaLabel || raw.aria_label || ''),
            numero: raw.numero == null ? '' : String(raw.numero),
            estadoBase: String(raw.estadoBase || raw.estado_base || ''),
            modificadores: normalizeClasses(raw.modificadores),
            seleccionValida: seleccionValida,
            clasesEstado: normalizeClasses(raw.clasesEstado || raw.clases_estado),
            atributos: normalizeAttributes(raw.atributos)
        };
    }

    function createMapVisual(options) {
        options = options || {};

        var canvas = typeof options.canvas === 'string'
            ? document.querySelector(options.canvas)
            : options.canvas;

        if (!canvas) {
            return null;
        }

        var context = String(options.contexto || canvas.getAttribute('data-map-context') || 'mapa-mesas');
        var interactive = options.interactivo !== false;
        var multiple = options.seleccionMultiple === true;
        var tables = [];
        var tablesById = {};
        var resizeObserver = null;
        var lastSelectionKey = '';
        var card = canvas.closest('[data-map-component]');
        var structuredList = card ? card.querySelector('[data-map-structured-list]') : null;

        function accessibleTableLabel(table) {
            if (table.ariaLabel) {
                return table.ariaLabel + (table.seleccionada ? ', seleccionada' : '');
            }
            var parts = [table.titulo];
            if (table.capacidad > 0) {
                parts.push('capacidad ' + table.capacidad);
            }
            parts.push(STATE_LABELS[table.estadoVisual] || table.estadoVisual);
            if (table.seleccionada) {
                parts.push('seleccionada');
            }
            return parts.join(', ');
        }

        function dispatch(name, detail) {
            canvas.dispatchEvent(new CustomEvent(name, {
                bubbles: true,
                detail: detail
            }));
        }

        function selectionDetail() {
            return tables.filter(function (table) {
                return table.seleccionada;
            }).map(function (table) {
                return table.id;
            });
        }

        function emitSelectionIfChanged() {
            var selectedIds = selectionDetail();
            var selectionKey = selectedIds.join(',');

            if (selectionKey === lastSelectionKey) {
                return;
            }

            lastSelectionKey = selectionKey;
            dispatch('mapa:seleccion-cambiada', {
                contexto: context,
                mesasSeleccionadas: selectedIds,
                seleccionMultiple: multiple
            });
        }

        function applyState(pin, table) {
            var previousClasses = String(pin.getAttribute('data-state-classes') || '')
                .split(/\s+/)
                .filter(Boolean);
            previousClasses.forEach(function (className) {
                pin.classList.remove(className);
            });
            STATE_CLASSES.forEach(function (className) {
                pin.classList.remove(className);
            });

            pin.classList.remove('mesa-pin--highlight');
            pin.classList.remove('reservation-operation-pin--assigned');
            pin.classList.remove('reservation-operation-pin--selected');

            pin.classList.add('mesa-pin--' + table.estadoVisual);
            var stateClasses = table.clasesEstado.slice();
            table.modificadores.forEach(function (modifier) {
                stateClasses.push('mesa-pin--mod-' + modifier);
            });
            stateClasses = normalizeClasses(stateClasses);
            stateClasses.forEach(function (className) {
                pin.classList.add(className);
            });
            pin.setAttribute('data-state-classes', stateClasses.join(' '));

            if (table.seleccionada) {
                pin.classList.add('mesa-pin--seleccionada');
                pin.classList.add('mesa-pin--highlight');
            }

            pin.setAttribute('data-estado-visual', table.estadoVisual);
            pin.setAttribute('data-estado-base', table.estadoBase || table.estadoVisual);
            pin.setAttribute('data-modificadores', table.modificadores.join(' '));
            pin.setAttribute('aria-pressed', table.seleccionada ? 'true' : 'false');
            pin.setAttribute('aria-label', accessibleTableLabel(table));
        }

        function syncStructuredTable(table) {
            if (!structuredList) {
                return;
            }

            var button = structuredList.querySelector('[data-structured-mesa="' + table.id + '"]');
            if (!button) {
                return;
            }

            var state = button.querySelector('.operational-map__structured-state');
            button.disabled = !(interactive && table.interactivo);
            button.setAttribute('aria-pressed', table.seleccionada ? 'true' : 'false');
            button.setAttribute('aria-label', accessibleTableLabel(table));
            if (state) {
                state.textContent = (STATE_LABELS[table.estadoVisual] || table.estadoVisual)
                    + (table.capacidad > 0 ? ' · capacidad ' + table.capacidad : '');
            }
        }

        function renderStructuredList() {
            if (!structuredList) {
                return;
            }

            structuredList.innerHTML = '';
            tables.forEach(function (table) {
                var item = document.createElement('li');
                item.setAttribute('role', 'listitem');

                var button = document.createElement('button');
                button.type = 'button';
                button.setAttribute('data-structured-mesa', String(table.id));
                button.setAttribute('aria-pressed', table.seleccionada ? 'true' : 'false');
                button.disabled = !(interactive && table.interactivo);
                button.setAttribute('aria-label', accessibleTableLabel(table));

                var name = document.createElement('span');
                name.className = 'operational-map__structured-name';
                name.textContent = table.titulo;
                button.appendChild(name);

                var state = document.createElement('span');
                state.className = 'operational-map__structured-state';
                state.textContent = (STATE_LABELS[table.estadoVisual] || table.estadoVisual)
                    + (table.capacidad > 0 ? ' · capacidad ' + table.capacidad : '');
                button.appendChild(state);

                button.addEventListener('click', function () {
                    dispatchTableClick(table);
                });
                item.appendChild(button);
                structuredList.appendChild(item);
            });
        }

        function createPin(table) {
            var pin = document.createElement('button');
            var isInteractive = interactive && table.interactivo;

            pin.type = 'button';
            pin.className = 'mesa-pin mesa-pin--tipo-' + table.tipo;
            pin.style.left = table.x + '%';
            pin.style.top = table.y + '%';
            pin.title = table.titulo;
            pin.disabled = !isInteractive;
            pin.setAttribute('data-mapa-mesa', String(table.id));
            pin.setAttribute('data-reservable', table.reservable ? '1' : '0');
            pin.setAttribute('data-disabled', isInteractive ? '0' : '1');
            pin.setAttribute('aria-label', accessibleTableLabel(table));

            if (table.numero !== '') {
                pin.setAttribute('data-numero', table.numero);
            }

            if (table.ancho !== null && table.ancho > 0) {
                pin.style.width = table.ancho + 'px';
            }
            if (table.alto !== null && table.alto > 0) {
                pin.style.height = table.alto + 'px';
            }

            Object.keys(table.atributos).forEach(function (name) {
                pin.setAttribute(name, table.atributos[name]);
            });

            var label = document.createElement('span');
            label.className = 'mesa-pin__label';
            label.textContent = table.nombre;
            pin.appendChild(label);

            if (!table.reservable) {
                var typeLabel = document.createElement('span');
                typeLabel.className = 'mesa-pin__type-label';
                typeLabel.textContent = 'Área operativa';
                pin.appendChild(typeLabel);
            }

            applyState(pin, table);
            return pin;
        }

        function render(payload) {
            payload = payload || {};
            var visualTables = [];

            (payload.mesas || []).concat(payload.elementos || []).forEach(function (table) {
                var normalized = normalizeTable(table);
                if (normalized.id > 0) {
                    visualTables.push(normalized);
                }
            });

            tables = visualTables;
            tablesById = {};

            var fragment = document.createDocumentFragment();
            tables.forEach(function (table) {
                tablesById[table.id] = table;
                fragment.appendChild(createPin(table));
            });

            canvas.innerHTML = '';
            canvas.appendChild(fragment);
            canvas.setAttribute('data-map-ready', '1');
            renderStructuredList();
            emitSelectionIfChanged();
        }

        function clear(message) {
            tables = [];
            tablesById = {};
            canvas.removeAttribute('data-map-ready');
            canvas.innerHTML = '';
            if (structuredList) {
                structuredList.innerHTML = '';
            }

            if (message) {
                var empty = document.createElement('div');
                empty.className = 'mapa-empty-state';

                var icon = document.createElement('span');
                icon.className = 'mapa-empty-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.textContent = 'o';

                var copy = document.createElement('span');
                copy.textContent = message;

                empty.appendChild(icon);
                empty.appendChild(copy);
                canvas.appendChild(empty);
            }

            emitSelectionIfChanged();
        }

        function updateState(id, changes) {
            id = parseInt(id || '0', 10);
            changes = changes || {};

            var table = tablesById[id];
            var pin = canvas.querySelector('[data-mapa-mesa="' + id + '"]');
            if (!table || !pin) {
                return;
            }

            if (changes.estadoVisual != null) {
                table.estadoVisual = normalizeState(changes.estadoVisual);
            }
            if (changes.seleccionada != null) {
                table.seleccionada = toBoolean(changes.seleccionada) && table.seleccionValida !== false;
            }
            if (changes.clasesEstado != null) {
                table.clasesEstado = normalizeClasses(changes.clasesEstado);
            }
            if (changes.modificadores != null) {
                table.modificadores = normalizeClasses(changes.modificadores);
            }
            if (changes.titulo != null) {
                table.titulo = String(changes.titulo);
                pin.title = table.titulo;
                pin.setAttribute('aria-label', table.titulo);
            }

            applyState(pin, table);
            syncStructuredTable(table);

            if (changes.atributos) {
                var attributes = normalizeAttributes(changes.atributos);
                Object.keys(attributes).forEach(function (name) {
                    pin.setAttribute(name, attributes[name]);
                });
            }

            emitSelectionIfChanged();
        }

        function setSelected(ids) {
            var selected = {};
            (ids || []).forEach(function (id) {
                selected[parseInt(id, 10)] = true;
            });

            tables.forEach(function (table) {
                var nextSelected = Boolean(selected[table.id]) && table.seleccionValida !== false;
                if (table.seleccionada !== nextSelected) {
                    table.seleccionada = nextSelected;
                    var pin = canvas.querySelector('[data-mapa-mesa="' + table.id + '"]');
                    if (pin) {
                        applyState(pin, table);
                    }
                    syncStructuredTable(table);
                }
            });

            emitSelectionIfChanged();
        }

        function dispatchTableClick(table) {
            dispatch('mapa:mesa-click', {
                contexto: context,
                mesaId: table.id,
                estado: table.estadoVisual,
                reservable: table.reservable,
                seleccionada: table.seleccionada,
                capacidad: table.capacidad,
                modificadores: table.modificadores.slice(),
                seleccionMultiple: multiple
            });
        }

        function onClick(event) {
            var pin = event.target.closest('[data-mapa-mesa]');
            if (!pin || !canvas.contains(pin) || pin.disabled || pin.getAttribute('data-disabled') === '1') {
                return;
            }

            var table = tablesById[parseInt(pin.getAttribute('data-mapa-mesa') || '0', 10)];
            if (!table) {
                return;
            }

            dispatchTableClick(table);
        }

        function onResize(entries) {
            if (!entries || !entries.length) {
                return;
            }

            var rect = entries[0].contentRect;
            dispatch('mapa:resize', {
                contexto: context,
                ancho: rect.width,
                alto: rect.height
            });
        }

        function destroy() {
            canvas.removeEventListener('click', onClick);
            if (resizeObserver) {
                resizeObserver.disconnect();
            }
        }

        canvas.setAttribute('data-map-context', context);
        canvas.addEventListener('click', onClick);

        var legend = card ? card.querySelector('[data-map-legend]') : null;
        if (legend && options.mostrarLeyenda === false) {
            legend.hidden = true;
        }

        if (window.ResizeObserver && canvas.parentElement) {
            resizeObserver = new ResizeObserver(onResize);
            resizeObserver.observe(canvas.parentElement);
        }

        return {
            render: render,
            clear: clear,
            actualizarEstado: updateState,
            setSeleccionadas: setSelected,
            normalizarMesa: normalizeTable,
            destroy: destroy
        };
    }

    window.MapaVisual = {
        crear: createMapVisual,
        normalizarMesa: normalizeTable,
        normalizarEstado: normalizeState
    };
})();
