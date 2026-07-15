/**
 * Controla la vista operativa de reservaciones:
 * carga el dia, sincroniza seleccion y administra el mapa de mesas.
 */
(function () {
    var EDITABLE_STATES = ['pendiente', 'confirmada'];
    var API_BASE = '/admin/api/reservations/operation';

    function initReservationOperation() {
        var root = document.querySelector('[data-page="reservation-operation"]');

        if (!root) {
            return;
        }

        var state = {
            fecha: root.getAttribute('data-initial-fecha') || '',
            horarios: [],
            reservaciones: [],
            mesas: [],
            ocupacionPorReservacion: {},
            reservacionSeleccionadaId: parseInt(root.getAttribute('data-initial-reservation-id') || '0', 10) || null,
            horaSeleccionada: null,
            mesasSeleccionadas: new Set(),
            cargando: false,
            guardando: false,
            abortController: null,
            config: {
                estadoLabels: {},
                estadosEditables: EDITABLE_STATES,
                comentarioAdminDisponible: root.getAttribute('data-comment-enabled') === '1'
            }
        };

        var els = {
            filters: root.querySelector('[data-operation-filters]'),
            date: root.querySelector('[data-operation-date]'),
            hour: root.querySelector('[data-operation-hour]'),
            load: root.querySelector('[data-operation-load]'),
            count: root.querySelector('[data-operation-count]'),
            dateLabel: root.querySelector('[data-operation-date-label]'),
            hourLabel: root.querySelector('[data-operation-hour-label]'),
            reservations: root.querySelector('[data-operation-reservations]'),
            map: root.querySelector('[data-operation-map]'),
            mapStatus: root.querySelector('[data-operation-map-status]'),
            panel: root.querySelector('[data-operation-panel]'),
            toast: root.querySelector('[data-operation-toast]')
        };

        var toastTimer = null;

        function esc(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function horaCorta(hora) {
            return String(hora || '').substring(0, 5);
        }

        function plural(total, singular, pluralText) {
            return total + ' ' + (total === 1 ? singular : pluralText);
        }

        function estadoLabel(estado) {
            return state.config.estadoLabels[estado] || estado;
        }

        function isEditable(reservacion) {
            return reservacion &&
                reservacion.editable !== false &&
                state.config.estadosEditables.indexOf(String(reservacion.estado)) !== -1;
        }

        function selectedReservation() {
            var id = parseInt(state.reservacionSeleccionadaId || '0', 10);
            return state.reservaciones.find(function (reservacion) {
                return parseInt(reservacion.id, 10) === id;
            }) || null;
        }

        function findReservationById(id) {
            id = parseInt(id || '0', 10);
            return state.reservaciones.find(function (reservacion) {
                return parseInt(reservacion.id, 10) === id;
            }) || null;
        }

        function tableById(id) {
            id = parseInt(id, 10);
            return state.mesas.find(function (mesa) {
                return parseInt(mesa.id, 10) === id;
            }) || null;
        }

        function mesaNombre(id) {
            var mesa = tableById(id);
            return mesa ? mesa.nombre : ('Mesa ' + id);
        }

        function selectedCapacity() {
            var total = 0;
            state.mesasSeleccionadas.forEach(function (mesaId) {
                var mesa = tableById(mesaId);
                total += mesa ? parseInt(mesa.capacidad || '0', 10) : 0;
            });
            return total;
        }

        function sortedHours() {
            var hours = {};

            state.horarios.forEach(function (hora) {
                if (hora) {
                    hours[horaCorta(hora)] = true;
                }
            });

            state.reservaciones.forEach(function (reservacion) {
                if (reservacion.hora) {
                    hours[horaCorta(reservacion.hora)] = true;
                }
            });

            return Object.keys(hours).sort();
        }

        function currentOperationUrl(reservacionId) {
            var params = new URLSearchParams();

            if (state.fecha) {
                params.set('fecha', state.fecha);
            }

            if (reservacionId) {
                params.set('reservacion_id', String(reservacionId));
            }

            return '/admin/reservations/operation' + (params.toString() ? '?' + params.toString() : '');
        }

        function buildDetailUrl(reservacion) {
            var id = parseInt((reservacion && reservacion.id) || state.reservacionSeleccionadaId || '0', 10);
            var returnUrl = currentOperationUrl(id);

            return '/admin/reservations/show?id=' + encodeURIComponent(String(id)) +
                '&return_url=' + encodeURIComponent(returnUrl);
        }

        function updateUrl() {
            if (!window.history || !window.history.replaceState) {
                return;
            }

            var params = new URLSearchParams();
            if (state.fecha) {
                params.set('fecha', state.fecha);
            }
            if (state.reservacionSeleccionadaId) {
                params.set('reservacion_id', String(state.reservacionSeleccionadaId));
            }

            var returnUrl = root.getAttribute('data-return-url') || '';
            if (returnUrl) {
                params.set('return_url', returnUrl);
            }

            window.history.replaceState({}, '', '/admin/reservations/operation' + (params.toString() ? '?' + params.toString() : ''));
        }

        function setLoading(isLoading) {
            state.cargando = isLoading;
            if (els.load) {
                els.load.disabled = isLoading;
                els.load.textContent = isLoading ? 'Consultando' : 'Consultar';
            }
            if (els.date) {
                els.date.disabled = isLoading;
            }
            if (els.hour) {
                els.hour.disabled = isLoading || state.horarios.length === 0;
            }
            root.classList.toggle('is-loading', isLoading);
        }

        function setSaving(isSaving) {
            state.guardando = isSaving;
            root.classList.toggle('is-saving', isSaving);
            Array.prototype.forEach.call(root.querySelectorAll('[data-operation-save], [data-operation-action], [data-operation-comment-save]'), function (button) {
                button.disabled = isSaving || button.getAttribute('data-disabled') === '1';
            });
        }

        function showToast(message, type) {
            if (!els.toast) {
                return;
            }

            window.clearTimeout(toastTimer);
            els.toast.textContent = message;
            els.toast.className = 'reservation-operation-toast reservation-operation-toast--' + (type || 'success');
            els.toast.hidden = false;
            toastTimer = window.setTimeout(function () {
                els.toast.hidden = true;
            }, 3200);
        }

        function renderLoadingShell() {
            if (els.reservations) {
                els.reservations.innerHTML =
                    '<div class="reservation-operation-skeleton">' +
                        '<span></span><span></span><span></span>' +
                    '</div>';
            }
            if (els.map) {
                els.map.innerHTML = '<div class="mapa-empty-state"><span class="mapa-empty-icon">o</span><span>Cargando mapa</span></div>';
            }
            if (els.panel) {
                els.panel.innerHTML =
                    '<article class="reservation-operation-panel admin-card">' +
                        '<span class="reservation-operation-panel__label">Reservacion seleccionada</span>' +
                        '<h3>Cargando</h3>' +
                        '<p class="reservation-operation-panel__muted">Consultando reservaciones del dia.</p>' +
                    '</article>';
            }
        }

        function renderAll() {
            renderScheduleSelector();
            renderReservationList();
            renderReservationDetail();
            renderTableMap();
            updateUrl();
        }

        function renderScheduleSelector() {
            var hours = sortedHours();

            if (!els.hour) {
                return;
            }

            if (!hours.length) {
                els.hour.innerHTML = '<option value="">Sin horarios</option>';
                els.hour.disabled = true;
                return;
            }

            els.hour.disabled = state.cargando;
            els.hour.innerHTML = hours.map(function (hora) {
                return '<option value="' + esc(hora) + '"' + (hora === state.horaSeleccionada ? ' selected' : '') + '>' + esc(hora) + '</option>';
            }).join('');
        }

        function renderReservationList() {
            var total = state.reservaciones.length;

            if (els.count) {
                els.count.textContent = String(total);
            }
            if (els.dateLabel) {
                els.dateLabel.textContent = state.fecha || 'Sin fecha';
            }
            if (els.hourLabel) {
                els.hourLabel.textContent = state.horaSeleccionada || 'Sin horario';
            }

            if (!els.reservations) {
                return;
            }

            if (!total) {
                els.reservations.innerHTML =
                    '<div class="mapa-empty-state">' +
                        '<span class="mapa-empty-icon">o</span>' +
                        '<span>No hay reservaciones para este dia.</span>' +
                    '</div>';
                return;
            }

            els.reservations.innerHTML = state.reservaciones.map(renderReservationCard).join('');
        }

        function renderReservationCard(reservacion) {
            var id = parseInt(reservacion.id, 10);
            var selected = id === parseInt(state.reservacionSeleccionadaId || '0', 10);
            var hora = horaCorta(reservacion.hora);
            var highlighted = state.horaSeleccionada && hora === state.horaSeleccionada;
            var dimmed = state.horaSeleccionada && hora !== state.horaSeleccionada;
            var estado = String(reservacion.estado || 'pendiente');
            var editable = isEditable(reservacion);
            var mesaIds = Array.isArray(reservacion.mesa_ids) ? reservacion.mesa_ids : [];
            var mesas = Array.isArray(reservacion.mesas_asignadas) ? reservacion.mesas_asignadas : [];
            var mesasHtml = mesas.length
                ? mesas.slice(0, 3).map(function (nombre) { return '<span>' + esc(nombre) + '</span>'; }).join('') + (mesas.length > 3 ? '<span>+' + (mesas.length - 3) + '</span>' : '')
                : '<span class="reservation-operation-card__muted">Sin mesas asignadas</span>';

            return '<article class="reservation-operation-card' +
                (selected ? ' is-selected' : '') +
                (editable ? '' : ' is-readonly') +
                (highlighted ? ' is-highlighted' : '') +
                (dimmed ? ' is-dimmed' : '') +
                '">' +
                '<button type="button" class="reservation-operation-card__button" data-operation-reservation="' + id + '">' +
                    '<div class="reservation-operation-card__head">' +
                        '<time class="reservation-operation-card__time">' + esc(hora) + '</time>' +
                        '<span class="reservations-table__status reservations-table__status--' + esc(estado) + '">' + esc(estadoLabel(estado)) + '</span>' +
                    '</div>' +
                    '<div class="reservation-operation-card__customer">' +
                        '<strong>' + esc(reservacion.nombre) + '</strong>' +
                        '<span>' + esc(reservacion.email) + '</span>' +
                    '</div>' +
                    '<div class="reservation-operation-card__meta">' +
                        '<span>' + esc(plural(parseInt(reservacion.comensales || '0', 10), 'persona', 'personas')) + '</span>' +
                        (mesaIds.length ? '' : '<span class="admin-badge admin-badge--warning">Sin mesas asignadas</span>') +
                    '</div>' +
                    '<div class="reservation-operation-card__tables">' + mesasHtml + '</div>' +
                    (reservacion.comentario_admin ? '<p class="reservation-operation-card__internal">' + esc(reservacion.comentario_admin).substring(0, 100) + '</p>' : '') +
                '</button>' +
            '</article>';
        }

        function renderReservationDetail() {
            var reservacion = selectedReservation();

            if (!els.panel) {
                return;
            }

            if (!reservacion) {
                els.panel.innerHTML =
                    '<article class="reservation-operation-panel admin-card">' +
                        '<span class="reservation-operation-panel__label">Reservacion seleccionada</span>' +
                        '<h3>Sin seleccion</h3>' +
                        '<p class="reservation-operation-panel__muted">' + (state.reservaciones.length ? 'Selecciona una reservacion del dia.' : 'No hay reservaciones para este dia.') + '</p>' +
                    '</article>';
                return;
            }

            var editable = isEditable(reservacion);
            var estado = String(reservacion.estado || 'pendiente');
            var mesaIds = Array.isArray(reservacion.mesa_ids) ? reservacion.mesa_ids : [];
            var capacidad = selectedCapacity();
            var comensales = parseInt(reservacion.comensales || '0', 10);
            var diferencia = capacidad - comensales;
            var insufficient = state.mesasSeleccionadas.size > 0 && capacidad < comensales;
            var selectedNames = Array.from(state.mesasSeleccionadas).map(mesaNombre);
            var mesasActuales = Array.isArray(reservacion.mesas_asignadas) && reservacion.mesas_asignadas.length
                ? reservacion.mesas_asignadas.join(', ')
                : 'Sin mesas asignadas';

            els.panel.innerHTML =
                '<article class="reservation-operation-panel admin-card">' +
                    '<div class="reservation-operation-panel__head">' +
                        '<div>' +
                            '<span class="reservation-operation-panel__label">Reservacion seleccionada</span>' +
                            '<h3>#' + esc(reservacion.id) + ' - ' + esc(reservacion.nombre) + '</h3>' +
                        '</div>' +
                        '<span class="reservations-table__status reservations-table__status--' + esc(estado) + '">' + esc(estadoLabel(estado)) + '</span>' +
                    '</div>' +
                    '<dl class="reservation-operation-panel__facts">' +
                        '<div><dt>Correo</dt><dd>' + esc(reservacion.email) + '</dd></div>' +
                        '<div><dt>Comensales</dt><dd>' + esc(plural(comensales, 'persona', 'personas')) + '</dd></div>' +
                        '<div><dt>Hora</dt><dd>' + esc(horaCorta(reservacion.hora)) + '</dd></div>' +
                        '<div><dt>Mesas actuales</dt><dd>' + esc(mesasActuales) + '</dd></div>' +
                    '</dl>' +
                    '<a class="admin-btn admin-btn--secondary reservation-operation-panel__edit" href="' + esc(buildDetailUrl(reservacion)) + '">' + (editable ? 'Editar reservacion' : 'Ver detalle') + '</a>' +
                    '<section class="reservation-operation-panel__section">' +
                        '<h4>Asignacion manual</h4>' +
                        (!editable ? '<p class="reservation-operation-inline reservation-operation-inline--muted">Este estado es de solo lectura.</p>' : '') +
                        '<div class="reservation-operation-summary">' +
                            '<div><span>Personas</span><strong>' + comensales + '</strong></div>' +
                            '<div><span>Capacidad</span><strong class="' + (insufficient ? 'is-insufficient' : '') + '">' + capacidad + '</strong></div>' +
                            '<div><span>Diferencia</span><strong class="' + (diferencia < 0 ? 'is-insufficient' : '') + '">' + (diferencia > 0 ? '+' : '') + diferencia + '</strong></div>' +
                        '</div>' +
                        '<p class="reservation-operation-panel__selected ' + (!selectedNames.length ? 'is-empty' : '') + '">' + esc(selectedNames.length ? selectedNames.join(', ') : 'Sin mesas asignadas') + '</p>' +
                        (insufficient ? '<p class="reservation-operation-inline reservation-operation-inline--warning">La capacidad seleccionada es menor que los comensales. Puedes guardar explicitamente esta asignacion.</p>' : '') +
                        '<p class="reservation-operation-inline reservation-operation-inline--error" data-operation-inline-error hidden></p>' +
                        '<button class="admin-btn admin-btn--primary reservation-operation-panel__submit" type="button" data-operation-save data-disabled="' + (!editable || state.mesasSeleccionadas.size === 0 ? '1' : '0') + '"' + (!editable || state.mesasSeleccionadas.size === 0 || state.guardando ? ' disabled' : '') + '>' +
                            (insufficient ? 'Guardar de todos modos' : 'Guardar asignacion') +
                        '</button>' +
                    '</section>' +
                    '<section class="reservation-operation-panel__section">' +
                        '<h4>Nota del cliente</h4>' +
                        (reservacion.nota ? '<p class="reservation-operation-panel__note">' + esc(reservacion.nota) + '</p>' : '<p class="reservation-operation-panel__muted">Sin nota del cliente.</p>') +
                    '</section>' +
                '</article>' +
                '<article class="reservation-operation-panel admin-card">' +
                    '<div class="reservation-operation-panel__head">' +
                        '<div><span class="reservation-operation-panel__label">Operacion interna</span><h3>Comentario y estado</h3></div>' +
                    '</div>' +
                    '<section class="reservation-operation-panel__section">' +
                        '<h4>Comentario interno</h4>' +
                        renderCommentBox(reservacion, editable) +
                    '</section>' +
                    '<section class="reservation-operation-panel__section">' +
                        '<h4>Acciones rapidas</h4>' +
                        '<div class="reservation-operation-actions">' +
                            renderActionButton('confirm', 'Confirmar', 'admin-btn admin-btn--primary', estado !== 'pendiente' || mesaIds.length === 0) +
                            renderActionButton('reassign', 'Reasignar', 'admin-btn admin-btn--secondary', !editable) +
                            renderActionButton('complete', 'Completar', 'admin-btn admin-btn--secondary', !editable) +
                            renderActionButton('no-show', 'No show', 'admin-btn admin-btn--ghost', !editable) +
                            renderActionButton('cancel', 'Cancelar', 'admin-btn admin-btn--danger', !editable) +
                        '</div>' +
                    '</section>' +
                '</article>';
        }

        function renderCommentBox(reservacion, editable) {
            if (!state.config.comentarioAdminDisponible) {
                return '<div class="reservation-operation-migration"><p>Los comentarios internos no estan disponibles en esta instalacion.</p></div>';
            }

            return '<div class="reservation-operation-comment">' +
                '<textarea rows="4" placeholder="Notas internas para recepcion y piso" data-operation-comment ' + (!editable ? 'readonly' : '') + '>' + esc(reservacion.comentario_admin || '') + '</textarea>' +
                '<button type="button" class="admin-btn admin-btn--secondary" data-operation-comment-save data-disabled="' + (!editable ? '1' : '0') + '"' + (!editable || state.guardando ? ' disabled' : '') + '>Guardar comentario</button>' +
            '</div>';
        }

        function renderActionButton(action, label, className, disabled) {
            return '<button type="button" class="' + esc(className) + '" data-operation-action="' + esc(action) + '" data-disabled="' + (disabled ? '1' : '0') + '"' + (disabled || state.guardando ? ' disabled' : '') + '>' + esc(label) + '</button>';
        }

        function renderTableMap() {
            var reservacion = selectedReservation();

            if (!els.map) {
                return;
            }

            if (!reservacion) {
                els.map.innerHTML = '<div class="mapa-empty-state"><span class="mapa-empty-icon">o</span><span>Selecciona una reservacion.</span></div>';
                if (els.mapStatus) {
                    els.mapStatus.textContent = 'Selecciona una reservacion para ver disponibilidad.';
                }
                return;
            }

            var editable = isEditable(reservacion);
            var ocupacion = state.ocupacionPorReservacion[String(reservacion.id)] || state.ocupacionPorReservacion[reservacion.id] || {};
            var html = state.mesas.map(function (mesa) {
                var mesaId = parseInt(mesa.id, 10);
                var active = parseInt(mesa.activo || '0', 10) === 1;
                var reservable = parseInt(mesa.reservable || '0', 10) === 1;
                var assigned = state.mesasSeleccionadas.has(mesaId);
                var occupied = ocupacion[String(mesaId)] || ocupacion[mesaId] || null;
                var estado = !active || !reservable ? 'zona' : (assigned ? 'bloqueada' : (occupied ? 'ocupada' : 'libre'));
                var selectable = editable && active && reservable && !occupied;
                var title = !active || !reservable
                    ? mesa.nombre + ' no reservable'
                    : (occupied ? 'Ocupada por ' + occupied.nombre + ' a las ' + horaCorta(occupied.hora) : mesa.nombre + ' disponible');
                var left = Math.max(0, Math.min(100, parseFloat(mesa.pos_x || '50')));
                var top = Math.max(0, Math.min(100, parseFloat(mesa.pos_y || '50')));

                return '<button class="mesa-pin mesa-pin--tipo-' + esc(mesa.tipo || 'mesa') + ' mesa-pin--' + estado + (assigned ? ' reservation-operation-pin--assigned mesa-pin--highlight reservation-operation-pin--selected' : '') + '"' +
                    ' type="button"' +
                    ' style="left: ' + left + '%; top: ' + top + '%;"' +
                    ' title="' + esc(title) + '"' +
                    ' data-operation-table="' + mesaId + '"' +
                    ' data-disabled="' + (!selectable ? '1' : '0') + '"' +
                    (!selectable || state.guardando ? ' disabled' : '') +
                    '><span class="mesa-pin__label">' + esc(mesa.nombre) + '</span></button>';
            }).join('');

            els.map.innerHTML = html;

            if (els.mapStatus) {
                els.mapStatus.textContent = editable
                    ? 'Disponibilidad calculada para las ' + horaCorta(reservacion.hora) + '.'
                    : 'Reservacion finalizada o cerrada. El mapa esta en solo lectura.';
            }
        }

        function chooseDefaultSelection(preserveReservationId) {
            var preserved = preserveReservationId ? findReservationById(preserveReservationId) : null;
            var now = new Date();
            var currentHour = String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0');
            var active = state.reservaciones.filter(isEditable);
            var nextActive = active.find(function (reservacion) {
                return horaCorta(reservacion.hora) >= currentHour;
            });

            if (preserved) {
                return preserved;
            }
            if (nextActive) {
                return nextActive;
            }
            if (active.length) {
                return active[0];
            }
            return state.reservaciones[0] || null;
        }

        function selectReservation(id) {
            var reservacion = findReservationById(id);

            if (!reservacion) {
                state.reservacionSeleccionadaId = null;
                state.mesasSeleccionadas = new Set();
                renderAll();
                return;
            }

            state.reservacionSeleccionadaId = parseInt(reservacion.id, 10);
            state.horaSeleccionada = horaCorta(reservacion.hora);
            state.mesasSeleccionadas = new Set((reservacion.mesa_ids || []).map(function (mesaId) {
                return parseInt(mesaId, 10);
            }));
            renderAll();
        }

        function selectTime(hora) {
            state.horaSeleccionada = horaCorta(hora);

            var firstAtTime = state.reservaciones.find(function (reservacion) {
                return horaCorta(reservacion.hora) === state.horaSeleccionada && isEditable(reservacion);
            }) || state.reservaciones.find(function (reservacion) {
                return horaCorta(reservacion.hora) === state.horaSeleccionada;
            });

            if (firstAtTime) {
                selectReservation(firstAtTime.id);
                return;
            }

            renderAll();
        }

        function loadDay(fecha, options) {
            options = options || {};

            if (!fecha) {
                return;
            }

            if (state.abortController) {
                state.abortController.abort();
            }

            state.abortController = new AbortController();
            state.fecha = fecha;
            setLoading(true);
            renderLoadingShell();

            fetch(API_BASE + '?fecha=' + encodeURIComponent(fecha), {
                headers: { 'Accept': 'application/json' },
                signal: state.abortController.signal,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data.ok) {
                            throw data;
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    state.fecha = data.fecha || fecha;
                    state.horarios = data.horarios || [];
                    state.reservaciones = data.reservaciones || [];
                    state.mesas = data.mesas || [];
                    state.ocupacionPorReservacion = data.ocupacion_por_reservacion || {};
                    state.config.estadoLabels = (data.config && data.config.estado_labels) || {};
                    state.config.estadosEditables = (data.config && data.config.estados_editables) || EDITABLE_STATES;
                    state.config.comentarioAdminDisponible = Boolean(data.config && data.config.comentario_admin_disponible);

                    var selected = chooseDefaultSelection(options.preserveReservationId || state.reservacionSeleccionadaId);
                    if (selected) {
                        state.reservacionSeleccionadaId = parseInt(selected.id, 10);
                        state.horaSeleccionada = horaCorta(selected.hora);
                        state.mesasSeleccionadas = new Set((selected.mesa_ids || []).map(function (mesaId) {
                            return parseInt(mesaId, 10);
                        }));
                    } else {
                        state.reservacionSeleccionadaId = null;
                        state.horaSeleccionada = data.hora_sugerida || state.horarios[0] || null;
                        state.mesasSeleccionadas = new Set();
                    }

                    if (els.date) {
                        els.date.value = state.fecha;
                    }
                    setLoading(false);
                    renderAll();
                })
                .catch(function (error) {
                    if (error && error.name === 'AbortError') {
                        return;
                    }
                    setLoading(false);
                    state.reservaciones = [];
                    state.mesas = [];
                    renderAll();
                    showPanelError('No fue posible cargar los datos. Intentalo nuevamente.');
                });
        }

        function refreshDay(options) {
            options = options || {};
            loadDay(state.fecha, {
                preserveReservationId: options.preserveReservationId || state.reservacionSeleccionadaId
            });
        }

        function postJson(url, data) {
            return fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                credentials: 'same-origin',
                body: data.toString()
            }).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok || !payload.ok) {
                        payload.httpStatus = response.status;
                        throw payload;
                    }
                    return payload;
                });
            });
        }

        function saveTableAssignment() {
            var reservacion = selectedReservation();

            if (!reservacion || !isEditable(reservacion) || state.guardando) {
                return;
            }

            hideInlineError();
            var data = new URLSearchParams();
            var capacidad = selectedCapacity();
            var comensales = parseInt(reservacion.comensales || '0', 10);

            data.set('reservacion_id', String(reservacion.id));
            state.mesasSeleccionadas.forEach(function (mesaId) {
                data.append('mesa_ids[]', String(mesaId));
            });
            if (capacidad < comensales) {
                data.set('permitir_capacidad_insuficiente', '1');
            }

            setSaving(true);
            postJson(API_BASE + '/assign-tables', data)
                .then(function () {
                    showToast('Asignacion guardada.', 'success');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && error.codigo === 'MESA_OCUPADA') {
                        showInlineError('La mesa acaba de ser asignada a otra reservacion. Los datos fueron actualizados.');
                        refreshDay({ preserveReservationId: reservacion.id });
                        return;
                    }
                    showInlineError((error && error.mensaje) || 'No fue posible guardar los cambios. Intentalo nuevamente.');
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function saveComment() {
            var reservacion = selectedReservation();
            var textarea = root.querySelector('[data-operation-comment]');

            if (!reservacion || !textarea || state.guardando) {
                return;
            }

            var data = new URLSearchParams();
            data.set('reservacion_id', String(reservacion.id));
            data.set('comentario_admin', textarea.value || '');

            setSaving(true);
            postJson(API_BASE + '/update-comment', data)
                .then(function () {
                    showToast('Comentario guardado.', 'success');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function () {
                    showInlineError('No fue posible guardar los cambios. Intentalo nuevamente.');
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function reassignAutomatically() {
            var reservacion = selectedReservation();

            if (!reservacion || state.guardando) {
                return;
            }

            var data = new URLSearchParams();
            data.set('reservacion_id', String(reservacion.id));

            setSaving(true);
            postJson(API_BASE + '/reassign', data)
                .then(function () {
                    showToast('Asignacion guardada.', 'success');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    showInlineError((error && error.mensaje) || 'No fue posible guardar los cambios. Intentalo nuevamente.');
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function changeReservationStatus(action) {
            var reservacion = selectedReservation();

            if (!reservacion || state.guardando) {
                return;
            }

            if (action === 'cancel' && !window.confirm('Cancelar esta reservacion?')) {
                return;
            }

            var estados = {
                confirm: 'confirmada',
                complete: 'completada',
                cancel: 'cancelada',
                'no-show': 'no_show'
            };
            var labels = {
                confirm: 'Reservacion confirmada.',
                complete: 'Reservacion completada.',
                cancel: 'Reservacion cancelada.',
                'no-show': 'Reservacion marcada como no show.'
            };
            var estado = estados[action] || '';
            var data = new URLSearchParams();
            data.set('reservacion_id', String(reservacion.id));
            data.set('estado', estado);

            setSaving(true);
            postJson(API_BASE + '/status', data)
                .then(function () {
                    showToast(labels[action] || 'Cambios guardados.', 'success');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    showInlineError((error && error.mensaje) || 'No fue posible guardar los cambios. Intentalo nuevamente.');
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function showInlineError(message) {
            var target = root.querySelector('[data-operation-inline-error]');
            if (target) {
                target.textContent = message;
                target.hidden = false;
                return;
            }
            showPanelError(message);
        }

        function hideInlineError() {
            var target = root.querySelector('[data-operation-inline-error]');
            if (target) {
                target.textContent = '';
                target.hidden = true;
            }
        }

        function showPanelError(message) {
            if (!els.panel) {
                return;
            }
            els.panel.innerHTML =
                '<article class="reservation-operation-panel admin-card">' +
                    '<span class="reservation-operation-panel__label">Error operativo</span>' +
                    '<h3>No se pudo continuar</h3>' +
                    '<p class="reservation-operation-inline reservation-operation-inline--error">' + esc(message) + '</p>' +
                '</article>';
        }

        if (els.filters) {
            els.filters.addEventListener('submit', function (event) {
                event.preventDefault();
                loadDay(els.date ? els.date.value : state.fecha, {});
            });
        }

        if (els.date) {
            els.date.addEventListener('change', function () {
                loadDay(els.date.value, {});
            });
        }

        if (els.hour) {
            els.hour.addEventListener('change', function () {
                selectTime(els.hour.value);
            });
        }

        root.addEventListener('click', function (event) {
            var reservationButton = event.target.closest('[data-operation-reservation]');
            var tableButton = event.target.closest('[data-operation-table]');
            var saveButton = event.target.closest('[data-operation-save]');
            var commentButton = event.target.closest('[data-operation-comment-save]');
            var actionButton = event.target.closest('[data-operation-action]');

            if (reservationButton) {
                selectReservation(reservationButton.getAttribute('data-operation-reservation'));
                return;
            }

            if (tableButton && tableButton.getAttribute('data-disabled') !== '1') {
                var mesaId = parseInt(tableButton.getAttribute('data-operation-table'), 10);
                if (state.mesasSeleccionadas.has(mesaId)) {
                    state.mesasSeleccionadas.delete(mesaId);
                } else {
                    state.mesasSeleccionadas.add(mesaId);
                }
                renderReservationDetail();
                renderTableMap();
                return;
            }

            if (saveButton) {
                saveTableAssignment();
                return;
            }

            if (commentButton) {
                saveComment();
                return;
            }

            if (actionButton) {
                var action = actionButton.getAttribute('data-operation-action');
                if (action === 'reassign') {
                    reassignAutomatically();
                } else {
                    changeReservationStatus(action);
                }
            }
        });

        loadDay(state.fecha, { preserveReservationId: state.reservacionSeleccionadaId });
    }

    document.addEventListener('DOMContentLoaded', initReservationOperation);
})();
