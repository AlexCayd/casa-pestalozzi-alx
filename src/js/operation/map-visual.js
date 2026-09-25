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

    var UNVERIFIED_CLASS = 'mesa-pin--estado-no-verificado';
    var VISUAL_STATE_ALIASES = {
        disponible: 'libre',
        libre: 'libre',
        ocupada: 'ocupada',
        'reservacion-proxima': 'reservacion-proxima',
        proxima: 'reservacion-proxima',
        bloqueada: 'reservacion-proxima',
        seleccionada: 'seleccionada',
        'no-utilizable': 'no-utilizable',
        no_utilizable: 'no-utilizable',
        no_reservable: 'no-utilizable',
        'no-reservable': 'no-utilizable',
        zona: 'no-utilizable',
        'con-ticket': 'ocupada'
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

    function canonicalState(value) {
        var state = String(value == null ? '' : value).trim().toLowerCase();
        return Object.prototype.hasOwnProperty.call(VISUAL_STATE_ALIASES, state)
            ? VISUAL_STATE_ALIASES[state]
            : null;
    }

    function inspectVisualState(value, previousState) {
        var state = String(value == null ? '' : value).trim().toLowerCase();
        if (state === 'seleccionada') {
            var previous = canonicalState(previousState);
            if (previous && previous !== 'seleccionada') {
                return {
                    valido: true,
                    estadoVisual: previous,
                    seleccionHeredada: true
                };
            }
            return {
                valido: false,
                estadoVisual: 'no-utilizable',
                seleccionHeredada: false
            };
        }

        var normalized = canonicalState(state);
        return {
            valido: normalized !== null,
            estadoVisual: normalized || 'no-utilizable',
            seleccionHeredada: false
        };
    }

    function normalizeState(value) {
        // Un estado ausente no demuestra disponibilidad. El contrato comparte
        // el mismo fallback neutral que usan los adaptadores.
        return inspectVisualState(value).estadoVisual;
    }

    function validModifierContract(value) {
        return Array.isArray(value) && value.every(function (modifier) {
            return typeof modifier === 'string'
                && /^[a-z0-9_-]+$/i.test(modifier.trim());
        });
    }

    function unverifiedFrom(raw) {
        var contract = inspectVisualState(
            raw.estadoVisual != null ? raw.estadoVisual
                : (raw.estado_visual_pos != null ? raw.estado_visual_pos
                    : (raw.estado_visual_mapa != null ? raw.estado_visual_mapa
                        : (raw.estado_visual != null ? raw.estado_visual : raw.estado))),
            raw.estadoVisualAnterior != null ? raw.estadoVisualAnterior : raw.estado_visual_previo
        );
        var modifiersValid = true;
        ['modificadores_visual_pos', 'modificadores_visual_mapa'].forEach(function (field) {
            if (Object.prototype.hasOwnProperty.call(raw, field)
                && !validModifierContract(raw[field])) {
                modifiersValid = false;
            }
        });
        if (Object.prototype.hasOwnProperty.call(raw, 'modificadores')
            && !validModifierContract(raw.modificadores)) {
            modifiersValid = false;
        }
        return toBoolean(raw.estadoNoVerificado || raw.estado_no_verificado)
            || !contract.valido
            || !modifiersValid;
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
        var visualValue = raw.estadoVisual || raw.estado_visual_pos || raw.estado_visual_mapa || raw.estado_visual || raw.estado;
        var previousState = raw.estadoVisualAnterior || raw.estado_visual_previo;
        var visualContract = inspectVisualState(visualValue, previousState);
        var estadoNoVerificado = unverifiedFrom(raw);
        var state = estadoNoVerificado ? 'no-utilizable' : visualContract.estadoVisual;
        var legacySelected = visualContract.seleccionHeredada;
        var seleccionValidaSolicitada = raw.seleccionValida == null
            ? true
            : toBoolean(raw.seleccionValida);
        var seleccionValida = seleccionValidaSolicitada && !estadoNoVerificado;
        var selected = (toBoolean(raw.seleccionada) || legacySelected)
            && seleccionValida
            && state !== 'no-utilizable';
        var reservable = toBoolean(raw.reservable);
        var interactivoSolicitado = raw.interactivoSolicitado == null
            ? (raw.interactivo == null
                ? reservable && state !== 'no-utilizable'
                : toBoolean(raw.interactivo))
            : toBoolean(raw.interactivoSolicitado);
        var modifiers = normalizeClasses(raw.modificadores);
        var stateClasses = normalizeClasses(raw.clasesEstado || raw.clases_estado);
        if (estadoNoVerificado) {
            modifiers = modifiers.filter(function (modifier) {
                return modifier !== 'seleccion_actual' && modifier !== 'seleccionada';
            });
            stateClasses = stateClasses.filter(function (className) {
                return !/(^|[-_])(selected|seleccionada|highlight)([-_]|$)/i.test(className);
            });
        }

        return {
            id: id,
            nombre: String(raw.nombre || ('Mesa ' + id)),
            tipo: String(raw.tipo || 'mesa').toLowerCase().replace(/[^a-z0-9_-]/g, '') || 'mesa',
            estadoVisual: state,
            estadoNoVerificado: estadoNoVerificado,
            x: clampPercent(raw.x != null ? raw.x : raw.pos_x, 50),
            y: clampPercent(raw.y != null ? raw.y : raw.pos_y, 50),
            ancho: numberOrNull(raw.ancho != null ? raw.ancho : raw.width),
            alto: numberOrNull(raw.alto != null ? raw.alto : raw.height),
            reservable: reservable,
            activo: raw.activo == null ? null : toBoolean(raw.activo),
            capacidad: Math.max(0, parseInt(raw.capacidad || '0', 10) || 0),
            reservacionProxima: raw.reservacion_proxima || null,
            motivoBloqueo: String(raw.motivo_bloqueo || raw.motivoBloqueo || ''),
            seleccionada: selected,
            interactivoSolicitado: interactivoSolicitado,
            interactivo: !estadoNoVerificado && state !== 'no-utilizable' && interactivoSolicitado,
            independienteDeConsulta: toBoolean(raw.independienteDeConsulta),
            titulo: String(raw.titulo || raw.title || raw.nombre || ('Mesa ' + id)),
            ariaLabel: String(raw.ariaLabel || raw.aria_label || ''),
            // Rótulo bajo el nombre de un área operativa. Vacío deja el
            // genérico; Llevar lo usa para contar sus pedidos abiertos.
            subtitulo: String(raw.subtitulo || ''),
            numero: raw.numero == null ? '' : String(raw.numero),
            estadoBase: String(raw.estadoBase || raw.estado_base || ''),
            modificadores: modifiers,
            seleccionValida: seleccionValida,
            seleccionValidaSolicitada: seleccionValidaSolicitada,
            clasesEstado: stateClasses,
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
        var queryStatus = card ? card.querySelector('[data-map-query-status]') : null;
        var validationStatus = card ? card.querySelector('[data-map-validation-status]') : null;
        var queryBlocksOperations = false;

        function canInteract(table) {
            return interactive
                && table.interactivo === true
                && table.estadoNoVerificado !== true
                && (!queryBlocksOperations || table.independienteDeConsulta === true);
        }

        function syncValidationStatus() {
            if (!validationStatus) return;
            var invalidTables = tables.filter(function (table) {
                return table.estadoNoVerificado;
            });
            if (!invalidTables.length) {
                validationStatus.textContent = '';
                validationStatus.hidden = true;
                return;
            }
            var names = invalidTables.map(function (table) {
                return table.nombre || table.titulo || 'Elemento del mapa';
            });
            validationStatus.textContent = 'Estado no verificado en: ' + names.join(', ')
                + '. No se puede operar en esos elementos hasta recibir información válida.';
            validationStatus.hidden = false;
        }

        function accessibleTableLabel(table) {
            var unverifiedCopy = table.estadoNoVerificado
                ? ' Estado no verificado. La información del mapa está incompleta; no se puede operar en este elemento hasta recibir información válida.'
                : '';
            if (table.ariaLabel) {
                var suppliedLabel = table.ariaLabel;
                var suppliedLower = suppliedLabel.toLowerCase();
                if (table.modificadores.indexOf('ausencia_pendiente') !== -1
                    && suppliedLower.indexOf('ausencia') === -1
                    && suppliedLower.indexOf('tolerancia vencida') === -1) {
                    suppliedLabel += ' Acción pendiente: registrar ausencia.';
                }
                if (table.modificadores.indexOf('reservacion_advertencia') !== -1
                    && suppliedLower.indexOf('reservaci') === -1) {
                    suppliedLabel += ' Reservación cercana.';
                }
                return suppliedLabel
                    + unverifiedCopy
                    + (table.seleccionada ? ', seleccionada' : '');
            }
            var parts = [table.titulo];
            if (table.capacidad > 0) {
                parts.push('capacidad ' + table.capacidad);
            }
            parts.push(STATE_LABELS[table.estadoVisual] || table.estadoVisual);
            if (table.modificadores.indexOf('reservacion_advertencia') !== -1) {
                parts.push('con reservación cercana');
            }
            if (table.modificadores.indexOf('ausencia_pendiente') !== -1) {
                parts.push('acción pendiente: registrar ausencia');
            }
            if (table.seleccionada) {
                parts.push('seleccionada');
            }
            if (table.estadoNoVerificado) {
                parts.push('Estado no verificado. La información del mapa está incompleta; no se puede operar en este elemento hasta recibir información válida');
            }
            return parts.join(', ');
        }

        function visibleTableState(table) {
            if (table.estadoNoVerificado) {
                return 'Estado no verificado';
            }
            if (table.estadoVisual === 'no-utilizable') {
                if (table.tipo === 'zona') {
                    return 'Elemento representativo';
                }
                if (table.activo === false) {
                    return 'Mesa fuera de servicio';
                }
                if (context === 'operacion-reservaciones') {
                    return 'No asignable en Reservaciones';
                }
                if (table.independienteDeConsulta && table.interactivo) {
                    return 'Elemento operativo';
                }
                return 'No disponible para esta operación';
            }
            if (table.modificadores.indexOf('ausencia_pendiente') !== -1) {
                return 'Ausencia pendiente';
            }
            if (table.modificadores.indexOf('reservacion_advertencia') !== -1
                || table.estadoVisual === 'reservacion-proxima') {
                return 'Reserva próxima';
            }
            return (STATE_LABELS[table.estadoVisual] || table.estadoVisual)
                .replace(/^./, function (letter) { return letter.toUpperCase(); });
        }

        function visibleTableContext(table) {
            if (table.estadoNoVerificado) {
                return '';
            }
            if (table.estadoVisual === 'no-utilizable' && table.motivoBloqueo) {
                return table.motivoBloqueo;
            }
            var reservation = table.reservacionProxima || null;
            var hour = reservation && (reservation.hora || reservation.hora_reservacion)
                ? String(reservation.hora || reservation.hora_reservacion).slice(0, 5)
                : '';
            var capacity = table.capacidad > 0 ? table.capacidad + ' lugares' : '';
            return [hour, capacity].filter(Boolean).join(' · ');
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
            // Los modificadores visuales, incluido ausencia_pendiente, nunca
            // determinan la usabilidad; sólo el permiso normalizado lo hace.
            var isInteractive = canInteract(table);
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
            pin.classList.remove(UNVERIFIED_CLASS);

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

            if (table.estadoNoVerificado) {
                pin.classList.add(UNVERIFIED_CLASS);
            }

            if (table.seleccionada && !table.estadoNoVerificado) {
                pin.classList.add('mesa-pin--seleccionada');
                pin.classList.add('mesa-pin--highlight');
            }

            pin.setAttribute('data-estado-visual', table.estadoVisual);
            pin.setAttribute('data-estado-base', table.estadoBase || table.estadoVisual);
            pin.setAttribute('data-modificadores', table.modificadores.join(' '));
            if (table.estadoNoVerificado) {
                pin.setAttribute('data-estado-no-verificado', '1');
            } else {
                pin.removeAttribute('data-estado-no-verificado');
            }
            pin.setAttribute('data-disabled', isInteractive ? '0' : '1');
            pin.setAttribute('aria-disabled', isInteractive ? 'false' : 'true');
            pin.disabled = !isInteractive;
            pin.setAttribute('aria-pressed', table.seleccionada ? 'true' : 'false');
            pin.setAttribute('aria-label', accessibleTableLabel(table));

            var warningIcon = pin.querySelector('.mesa-pin__verification-warning');
            if (table.estadoNoVerificado && !warningIcon) {
                warningIcon = document.createElement('span');
                warningIcon.className = 'mesa-pin__verification-warning';
                warningIcon.setAttribute('aria-hidden', 'true');
                warningIcon.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 3.5 2.7 20h18.6L12 3.5Z"></path><path d="M12 9v4.5M12 17h.01"></path></svg>';
                pin.appendChild(warningIcon);
            } else if (!table.estadoNoVerificado && warningIcon) {
                warningIcon.parentNode.removeChild(warningIcon);
            }
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
            var context = button.querySelector('.operational-map__structured-context');
            button.disabled = !canInteract(table);
            button.setAttribute('aria-disabled', button.disabled ? 'true' : 'false');
            button.setAttribute('aria-pressed', table.seleccionada ? 'true' : 'false');
            button.setAttribute('aria-label', accessibleTableLabel(table));
            // El CSS pinta el punto de estado desde aquí, con los mismos tokens
            // --map-table-* que usan los pines del mapa.
            button.setAttribute('data-estado-visual', table.estadoVisual);
            if (table.estadoNoVerificado) {
                button.setAttribute('data-estado-no-verificado', '1');
            } else {
                button.removeAttribute('data-estado-no-verificado');
            }
            if (state) {
                state.textContent = visibleTableState(table);
            }
            if (context) {
                context.textContent = visibleTableContext(table);
                context.hidden = !context.textContent;
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
                button.disabled = !canInteract(table);
                button.setAttribute('aria-disabled', button.disabled ? 'true' : 'false');
                button.setAttribute('aria-label', accessibleTableLabel(table));
                button.setAttribute('data-estado-visual', table.estadoVisual);
                if (table.estadoNoVerificado) {
                    button.setAttribute('data-estado-no-verificado', '1');
                }

                var name = document.createElement('span');
                name.className = 'operational-map__structured-name';
                name.textContent = table.titulo;
                button.appendChild(name);

                var state = document.createElement('span');
                state.className = 'operational-map__structured-state';
                state.textContent = visibleTableState(table);
                button.appendChild(state);

                var context = document.createElement('span');
                context.className = 'operational-map__structured-context';
                context.textContent = visibleTableContext(table);
                context.hidden = !context.textContent;
                button.appendChild(context);

                button.addEventListener('click', function () {
                    dispatchTableClick(table);
                });
                item.appendChild(button);
                structuredList.appendChild(item);
            });
        }

        function createPin(table) {
            var pin = document.createElement('button');
            var isInteractive = canInteract(table);

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
                typeLabel.textContent = table.subtitulo || 'Área operativa';
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
            syncValidationStatus();
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
            syncValidationStatus();

            if (message) {
                var empty = document.createElement('div');
                empty.className = 'mapa-empty-state';

                var icon = document.createElement('span');
                icon.className = 'mapa-empty-icon';
                icon.setAttribute('aria-hidden', 'true');
                icon.innerHTML = '<svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="9"></circle><path d="M12 11v5m0-8h.01"></path></svg>';

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

            if (changes.interactivo != null) {
                table.interactivoSolicitado = toBoolean(changes.interactivo);
            }
            if (changes.seleccionValida != null) {
                table.seleccionValidaSolicitada = toBoolean(changes.seleccionValida);
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

            if (changes.subtitulo != null) {
                table.subtitulo = String(changes.subtitulo);
                var typeLabelActual = pin.querySelector('.mesa-pin__type-label');
                if (typeLabelActual) {
                    typeLabelActual.textContent = table.subtitulo || 'Área operativa';
                }
            }

            if (changes.estadoVisual != null || changes.estadoNoVerificado != null) {
                var stateValue = changes.estadoVisual != null ? changes.estadoVisual : table.estadoVisual;
                var statePrevious = changes.estadoVisualAnterior != null
                    ? changes.estadoVisualAnterior
                    : table.estadoVisual;
                var visualContract = inspectVisualState(stateValue, statePrevious);
                var explicitlyClearingError = changes.estadoNoVerificado != null
                    && !toBoolean(changes.estadoNoVerificado)
                    && changes.estadoVisual != null;
                table.estadoNoVerificado = toBoolean(changes.estadoNoVerificado)
                    || !visualContract.valido
                    || (table.estadoNoVerificado && !explicitlyClearingError);
                table.estadoVisual = table.estadoNoVerificado
                    ? 'no-utilizable'
                    : visualContract.estadoVisual;
            }
            table.interactivo = !table.estadoNoVerificado
                && table.estadoVisual !== 'no-utilizable'
                && table.interactivoSolicitado;
            table.seleccionValida = !table.estadoNoVerificado
                && table.estadoVisual !== 'no-utilizable'
                && table.seleccionValidaSolicitada;
            if (table.estadoNoVerificado) {
                table.modificadores = table.modificadores.filter(function (modifier) {
                    return modifier !== 'seleccion_actual' && modifier !== 'seleccionada';
                });
                table.clasesEstado = table.clasesEstado.filter(function (className) {
                    return !/(^|[-_])(selected|seleccionada|highlight)([-_]|$)/i.test(className);
                });
            }
            if (changes.seleccionada != null) {
                table.seleccionada = toBoolean(changes.seleccionada) && table.seleccionValida;
            } else if (table.estadoNoVerificado) {
                table.seleccionada = false;
            }

            if (changes.atributos) {
                var attributes = normalizeAttributes(changes.atributos);
                Object.keys(attributes).forEach(function (name) {
                    pin.setAttribute(name, attributes[name]);
                });
            }

            applyState(pin, table);
            syncStructuredTable(table);
            syncValidationStatus();
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

            syncValidationStatus();
            emitSelectionIfChanged();
        }

        function setConsultaEstado(message, blockOperations) {
            message = String(message || '').trim();
            queryBlocksOperations = Boolean(message && blockOperations === true);
            if (queryStatus) {
                queryStatus.textContent = message;
                queryStatus.hidden = !message;
            }
            if (card) {
                if (queryBlocksOperations) {
                    card.setAttribute('data-map-stale', '1');
                } else {
                    card.removeAttribute('data-map-stale');
                }
            }
            tables.forEach(function (table) {
                var pin = canvas.querySelector('[data-mapa-mesa="' + table.id + '"]');
                if (pin) {
                    applyState(pin, table);
                }
                syncStructuredTable(table);
            });
        }

        function dispatchTableClick(table) {
            if (!canInteract(table)) {
                return;
            }
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
            setConsultaEstado: setConsultaEstado,
            normalizarMesa: normalizeTable,
            destroy: destroy
        };
    }

    function validResponseCollection(payload, name) {
        if (!Array.isArray(payload[name])) return false;
        if (name !== 'mesas' && name !== 'mesas_estado') return true;
        return payload[name].every(function (item) {
            if (!item || typeof item !== 'object' || Array.isArray(item)) return false;
            var id = Number(item.id);
            return Number.isInteger(id) && id > 0;
        });
    }

    function validResponseObject(payload, name) {
        return Object.prototype.hasOwnProperty.call(payload, name)
            && payload[name] !== null
            && typeof payload[name] === 'object';
    }

    function validateMapResponse(payload, expected) {
        expected = expected || {};
        if (!payload || typeof payload !== 'object' || Array.isArray(payload) || payload.ok !== true) {
            return { valida: false, motivo: 'incompleta' };
        }
        if (expected.fecha != null && String(payload.fecha || '') !== String(expected.fecha)) {
            return { valida: false, motivo: 'contexto' };
        }
        var expectedHour = String(expected.hora || '').trim().slice(0, 5);
        if (expectedHour) {
            var responseHour = String(payload.hora || payload.hora_sugerida || '').trim().slice(0, 5);
            if (responseHour !== expectedHour) {
                return { valida: false, motivo: 'contexto' };
            }
        }
        var collections = Array.isArray(expected.colecciones)
            ? expected.colecciones
            : ['mesas', 'mesas_estado'];
        for (var i = 0; i < collections.length; i += 1) {
            if (!validResponseCollection(payload, collections[i])) {
                return { valida: false, motivo: 'incompleta' };
            }
        }
        var objects = Array.isArray(expected.objetos) ? expected.objetos : [];
        for (var j = 0; j < objects.length; j += 1) {
            if (!validResponseObject(payload, objects[j])) {
                return { valida: false, motivo: 'incompleta' };
            }
        }
        return { valida: true, motivo: '' };
    }

    window.MapaVisual = {
        crear: createMapVisual,
        normalizarMesa: normalizeTable,
        normalizarEstado: normalizeState,
        validarEstadoVisual: inspectVisualState,
        validarModificadoresVisuales: validModifierContract,
        validarRespuestaMapa: validateMapResponse
    };
})();
