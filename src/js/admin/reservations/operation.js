/**
 * Controla la vista operativa de reservaciones:
 * carga el dia, sincroniza seleccion y administra el mapa de mesas.
 */
(function () {
    var API_BASE = '/admin/api/reservations/operation';

    function initReservationOperation() {
        var root = document.querySelector('[data-page="reservation-operation"]');

        if (!root) {
            return;
        }

        var state = {
            fecha: root.getAttribute('data-initial-fecha') || '',
            fechaMinima: root.getAttribute('data-min-fecha') || '',
            fechaFallida: '',
            modo: root.getAttribute('data-operation-mode') || 'operacion',
            editable: root.getAttribute('data-operation-editable') !== '0',
            horarios: [],
            reservaciones: [],
            mesas: [],
            ocupacionPorReservacion: {},
            estadoOperacion: 'disponible',
            mensajeOperacion: '',
            hasLoadedData: false,
            loadFailure: null,
            pendingCreationFeedback: null,
            reservacionSeleccionadaId: parseInt(root.getAttribute('data-initial-reservation-id') || '0', 10) || null,
            horaSeleccionada: horaCorta(root.getAttribute('data-initial-hora') || ''),
            mesasSeleccionadas: new Set(),
            assignmentMode: false,
            assignmentInitialMesaIds: [],
            assignmentTrigger: null,
            cargando: false,
            guardando: false,
            abortController: null,
            timeoutId: null,
            timedOutSequence: 0,
            requestSequence: 0,
            config: {
                estadoLabels: {},
                estadosEditables: [],
                transiciones: {},
                comentarioAdminDisponible: root.getAttribute('data-comment-enabled') === '1'
            }
        };

        var els = {
            filters: root.querySelector('[data-operation-filters]'),
            dateRoot: root.querySelector('[data-operation-date-group] [data-reservation-date-picker]'),
            date: root.querySelector('[data-operation-date-group] [data-date-input]'),
            dateDisplay: root.querySelector('[data-operation-date-group] [data-date-display]'),
            dateGroup: root.querySelector('[data-operation-date-group]'),
            dateWarning: root.querySelector('[data-operation-date-warning]'),
            dateWarningMessage: root.querySelector('[data-operation-date-warning-message]'),
            hourRoot: root.querySelector('[data-operation-time-group] [data-reservation-time-picker]'),
            hour: root.querySelector('[data-operation-hour]'),
            load: root.querySelector('[data-operation-load]'),
            create: root.querySelector('[data-operation-create]'),
            title: root.querySelector('[data-operation-title]'),
            description: root.querySelector('[data-operation-description]'),
            readonlyNotice: root.querySelector('[data-operation-readonly-notice]'),
            results: root.querySelector('[data-operation-results]'),
            loadError: root.querySelector('[data-operation-load-error]'),
            loadErrorTitle: root.querySelector('[data-operation-load-error-title]'),
            loadErrorMessage: root.querySelector('[data-operation-load-error-message]'),
            context: root.querySelector('[data-operation-context]'),
            contextTitle: root.querySelector('[data-operation-context-title]'),
            contextMessage: root.querySelector('[data-operation-context-message]'),
            count: root.querySelector('[data-operation-count]'),
            dateLabel: root.querySelector('[data-operation-date-label]'),
            hourLabel: root.querySelector('[data-operation-hour-label]'),
            updateStatus: document.querySelector('[data-operation-update-status]'),
            mobileLayout: root.querySelector('[data-operation-mobile-view]'),
            reservations: root.querySelector('[data-operation-reservations]'),
            map: root.querySelector('[data-operation-map]'),
            selectionCopy: root.querySelector('[data-operation-selection-copy]'),
            panel: root.querySelector('[data-operation-panel]'),
            panelShell: root.querySelector('[data-operation-panel-shell]'),
            assignmentBar: root.querySelector('[data-operation-assignment-bar]'),
            assignmentTitle: root.querySelector('[data-operation-assignment-title]'),
            assignmentReservation: root.querySelector('[data-operation-assignment-reservation]'),
            assignmentPeople: root.querySelector('[data-operation-assignment-people]'),
            assignmentCapacity: root.querySelector('[data-operation-assignment-capacity]'),
            assignmentDifference: root.querySelector('[data-operation-assignment-difference]'),
            assignmentTables: root.querySelector('[data-operation-assignment-tables]'),
            toast: root.querySelector('[data-operation-toast]')
        };

        var mapVisual = window.MapaVisual && window.MapaVisual.crear({
            canvas: els.map,
            contexto: 'operacion-reservaciones',
            interactivo: true,
            seleccionMultiple: true,
            mostrarLeyenda: true
        });

        if (!mapVisual) {
            return;
        }

        var createModal = root.querySelector('[data-operation-create-modal]');
        var createForm = createModal ? createModal.querySelector('[data-admin-reservation-form]') : null;
        var createModalError = createModal ? createModal.querySelector('[data-operation-create-error]') : null;
        var createModalLastFocus = null;
        var createModalDirty = false;
        var createModalSubmitting = false;

        var toastTimer = null;
        var datePicker = null;
        var timePicker = null;

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

        function fechaValida(fecha) {
            var match = String(fecha || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
            if (!match) {
                return false;
            }

            var date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
            return date.getFullYear() === Number(match[1]) &&
                date.getMonth() === Number(match[2]) - 1 &&
                date.getDate() === Number(match[3]);
        }

        function fechaLegible(fecha) {
            var parts = String(fecha || '').split('-');
            if (parts.length !== 3) {
                return fecha || 'la fecha seleccionada';
            }

            return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2])).toLocaleDateString('es-MX', {
                day: 'numeric',
                month: 'long',
                year: 'numeric'
            });
        }

        function setDateValue(fecha) {
            if (datePicker) {
                datePicker.setValue(fecha, true);
            } else if (els.date) {
                els.date.value = fecha;
            }
        }

        function clearDateWarning() {
            if (els.dateWarning) {
                els.dateWarning.hidden = true;
            }
            if (els.dateDisplay) {
                els.dateDisplay.setAttribute('aria-invalid', 'false');
            }
            if (els.date) {
                els.date.setAttribute('aria-invalid', 'false');
            }
            if (els.dateGroup) {
                els.dateGroup.classList.remove('is-warning');
            }
        }

        function showDateWarning(fechaRestaurada) {
            var fecha = fechaRestaurada || state.fecha || state.fechaMinima;
            setDateValue(fecha);
            if (els.dateWarningMessage) {
                els.dateWarningMessage.textContent =
                    'La fecha seleccionada no tiene un formato valido.' +
                    (fecha ? ' Se mantienen los datos del ' + fechaLegible(fecha) + '.' : '');
            }
            if (els.dateWarning) {
                els.dateWarning.hidden = false;
            }
            if (els.dateDisplay) {
                els.dateDisplay.setAttribute('aria-invalid', 'true');
            }
            if (els.date) {
                els.date.setAttribute('aria-invalid', 'true');
            }
            if (els.dateGroup) {
                els.dateGroup.classList.add('is-warning');
            }
        }

        function renderOperationContext() {
            if (!els.context) {
                return;
            }

            var title = '';
            if (state.estadoOperacion === 'cerrado') {
                title = 'Restaurante cerrado';
            } else if (state.estadoOperacion === 'sin_horarios') {
                title = 'Sin horarios disponibles';
            }

            els.context.hidden = title === '';
            if (els.contextTitle) {
                els.contextTitle.textContent = title;
            }
            if (els.contextMessage) {
                els.contextMessage.textContent = state.mensajeOperacion || '';
            }
        }

        function showTechnicalError(kind, requestedDate) {
            state.fechaFallida = requestedDate || state.fecha;
            setDateValue(state.fecha);
            updateUrl();

            var titles = {
                connection: 'Problema de conexion',
                timeout: 'La consulta tardo demasiado',
                invalid_json: 'Respuesta no interpretable',
                consistency: 'Respuesta inconsistente',
                server: 'Error interno del servidor'
            };
            var messages = {
                connection: 'No se pudo actualizar la informacion. Revisa tu conexion y reintenta.',
                timeout: 'La solicitud excedio el tiempo de espera. Puedes reintentar.',
                invalid_json: 'No fue posible interpretar la respuesta del servidor.',
                consistency: 'El servidor respondio con datos de otra fecha. La respuesta fue descartada.',
                server: 'El servidor no pudo completar la consulta. Puedes reintentar.'
            };
            var title = titles[kind] || 'No fue posible actualizar la operacion';
            var message = messages[kind] || 'Ocurrio un error inesperado. Reintenta la consulta.';

            if (state.hasLoadedData && state.fecha) {
                message += ' Los datos anteriores permanecen visibles. Se mantiene la operación del ' + fechaLegible(state.fecha) + '.';
            }

            if (els.loadErrorTitle) {
                els.loadErrorTitle.textContent = title;
            }
            if (els.loadErrorMessage) {
                els.loadErrorMessage.textContent = message;
            }
            if (els.loadError) {
                els.loadError.hidden = false;
            } else {
                showPanelError(message);
            }
        }

        function plural(total, singular, pluralText) {
            return total + ' ' + (total === 1 ? singular : pluralText);
        }

        function estadoLabel(estado) {
            return state.config.estadoLabels[estado] || estado;
        }

        function isEditable(reservacion) {
            return state.editable && reservacion &&
                reservacion.editable !== false &&
                state.config.estadosEditables.indexOf(String(reservacion.estado)) !== -1;
        }

        function canTransition(reservacion, targetState) {
            if (!isEditable(reservacion)) {
                return false;
            }

            var allowed = state.config.transiciones[String(reservacion.estado)] || [];
            return Array.isArray(allowed) && allowed.indexOf(targetState) !== -1;
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

        function setPanelAccessibility(hidden) {
            if (!els.panelShell) {
                return;
            }
            els.panelShell.setAttribute('aria-hidden', hidden ? 'true' : 'false');
            els.panelShell.toggleAttribute('inert', hidden);
        }

        function renderAssignmentBar() {
            var reservacion = selectedReservation();
            if (!els.assignmentBar) {
                return;
            }

            var visible = state.assignmentMode && Boolean(reservacion);
            els.assignmentBar.hidden = !visible;
            els.assignmentBar.setAttribute('aria-hidden', visible ? 'false' : 'true');
            if (!visible) {
                return;
            }

            var capacidad = selectedCapacity();
            var comensales = parseInt(reservacion.comensales || '0', 10);
            var diferencia = capacidad - comensales;
            var selectedNames = Array.from(state.mesasSeleccionadas).map(mesaNombre);

            els.assignmentReservation.textContent = reservacion.nombre + ' · Reservación #' + reservacion.id;
            els.assignmentPeople.textContent = String(comensales);
            els.assignmentCapacity.textContent = String(capacidad);
            els.assignmentDifference.textContent = (diferencia > 0 ? '+' : '') + diferencia;
            els.assignmentDifference.classList.toggle('is-insufficient', diferencia < 0);
            els.assignmentTables.textContent = selectedNames.length ? selectedNames.join(', ') : 'Sin mesas seleccionadas';

            var saveButton = els.assignmentBar.querySelector('[data-operation-assignment-save]');
            if (saveButton) {
                var disabled = !isEditable(reservacion) || state.mesasSeleccionadas.size === 0;
                saveButton.setAttribute('data-disabled', disabled ? '1' : '0');
                saveButton.disabled = disabled || state.guardando;
                saveButton.textContent = state.mesasSeleccionadas.size > 0 && capacidad < comensales
                    ? 'Guardar de todos modos'
                    : 'Guardar';
            }
        }

        function enterAssignmentMode(trigger, options) {
            options = options || {};
            var reservacion = selectedReservation();
            if (!reservacion || !isEditable(reservacion) || state.guardando || state.assignmentMode) {
                return;
            }

            state.assignmentInitialMesaIds = Array.from(state.mesasSeleccionadas);
            state.assignmentTrigger = trigger || document.activeElement;
            state.assignmentMode = true;
            root.classList.add('assignment-mode');
            document.body.classList.add('is-assignment-mode');
            document.documentElement.classList.add('is-assignment-mode');
            root.setAttribute('data-assignment-mode', 'true');
            document.dispatchEvent(new CustomEvent('operational:close-drawer'));
            setPanelAccessibility(true);
            renderAssignmentBar();
            renderTableMap();

            if (options.focus !== false && els.assignmentTitle) {
                window.requestAnimationFrame(function () {
                    els.assignmentTitle.focus();
                });
            }
        }

        function exitAssignmentMode(options) {
            options = options || {};
            if (!state.assignmentMode) {
                return;
            }

            var trigger = state.assignmentTrigger;
            if (options.restoreSelection) {
                state.mesasSeleccionadas = new Set(state.assignmentInitialMesaIds);
            }
            state.assignmentMode = false;
            state.assignmentInitialMesaIds = [];
            state.assignmentTrigger = null;
            root.classList.remove('assignment-mode');
            document.body.classList.remove('is-assignment-mode');
            document.documentElement.classList.remove('is-assignment-mode');
            root.removeAttribute('data-assignment-mode');
            setPanelAccessibility(false);

            if (options.render !== false) {
                renderReservationDetail();
                renderTableMap();
                renderAssignmentBar();
            }
            var focusTarget = trigger && trigger.isConnected
                ? trigger
                : root.querySelector('[data-operation-assignment-start]');
            if (options.restoreFocus !== false && focusTarget) {
                window.requestAnimationFrame(function () {
                    focusTarget.focus();
                });
            }
        }

        function cancelAssignmentMode() {
            exitAssignmentMode({ restoreSelection: true });
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

            if (state.horaSeleccionada) {
                params.set('hora', state.horaSeleccionada);
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
            if (state.horaSeleccionada) {
                params.set('hora', state.horaSeleccionada);
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
                els.load.setAttribute('aria-busy', isLoading ? 'true' : 'false');
                els.load.setAttribute('title', isLoading ? 'Actualizando mapa' : 'Actualizar mapa');
                var loadLabel = els.load.querySelector('[data-operation-load-label]');
                if (loadLabel) {
                    loadLabel.textContent = isLoading ? 'Actualizando mapa…' : 'Actualizar mapa';
                }
            }
            if (timePicker) {
                timePicker.setDisabled(isLoading || sortedHours().length === 0);
            } else if (els.hour) {
                els.hour.disabled = isLoading || sortedHours().length === 0;
            }
            if (els.results) {
                els.results.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            }
            root.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            root.classList.toggle('is-loading', isLoading);
            if (isLoading) {
                setUpdateStatus('Actualizando', 'loading');
            }
        }

        function setUpdateStatus(message, tone) {
            if (!els.updateStatus) {
                return;
            }
            els.updateStatus.textContent = message;
            els.updateStatus.setAttribute('data-status', tone || 'ready');
        }

        function updateHeaderContext() {
            document.dispatchEvent(new CustomEvent('operational:contextchange', {
                detail: {
                    fecha: state.fecha || '',
                    hora: state.horaSeleccionada || ''
                }
            }));
        }

        function renderMode() {
            var readonly = state.modo === 'solo_lectura' || state.editable === false;
            state.editable = !readonly;
            root.setAttribute('data-operation-mode', readonly ? 'solo_lectura' : 'operacion');
            root.setAttribute('data-operation-editable', readonly ? '0' : '1');
            if (els.title) {
                els.title.textContent = readonly ? 'Operacion historica · Solo lectura' : 'Operacion de reservaciones';
            }
            if (els.description) {
                els.description.textContent = readonly
                    ? 'Consulta reservaciones, mesas, estados y comentarios sin permitir modificaciones.'
                    : 'Gestiona el servicio diario, los estados y la asignacion de mesas.';
            }
            if (els.readonlyNotice) {
                els.readonlyNotice.hidden = !readonly;
            }
            if (els.create) {
                els.create.hidden = readonly;
            }
        }

        function setMobileView(view) {
            if (!els.mobileLayout || ['list', 'detail', 'tables'].indexOf(view) === -1) {
                return;
            }
            els.mobileLayout.setAttribute('data-operation-mobile-view', view);
            root.querySelectorAll('[data-operation-mobile-tab]').forEach(function (button) {
                var active = button.getAttribute('data-operation-mobile-tab') === view;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-selected', active ? 'true' : 'false');
            });
        }

        function setSaving(isSaving) {
            state.guardando = isSaving;
            root.classList.toggle('is-saving', isSaving);
            Array.prototype.forEach.call(root.querySelectorAll('[data-operation-save], [data-operation-action], [data-operation-comment-save]'), function (button) {
                button.disabled = isSaving || button.getAttribute('data-disabled') === '1';
            });
            renderAssignmentBar();
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

        function showCreationFeedback(payload) {
            if (!els.toast || !payload) {
                return;
            }

            var mesaNames = (payload.mesaIds || []).map(function (mesaId) {
                var mesa = tableById(mesaId);
                return mesa ? mesa.nombre : 'Mesa ' + mesaId;
            });
            var assigned = mesaNames.length > 0;
            var title = assigned ? 'Reservación creada' : 'Reservación creada sin mesa asignada';
            var detail = fechaLegible(payload.fecha) + ' · ' + (payload.hora || '--:--');
            var customer = (payload.nombre || 'Sin nombre') + ' · ' + plural(payload.comensales || 0, 'persona', 'personas');
            var assignment = assigned
                ? mesaNames.join(', ') + (mesaNames.length === 1 ? ' asignada automáticamente' : ' asignadas automáticamente')
                : 'La reservación se guardó correctamente, pero necesita asignación manual.';

            window.clearTimeout(toastTimer);
            els.toast.className = 'reservation-operation-toast reservation-operation-toast--' + (assigned ? 'success' : 'warning');
            els.toast.innerHTML = '<strong>' + esc(title) + '</strong><span>' + esc(detail) + '</span><span>' + esc(customer) + '</span><span>' + esc(assignment) + '</span>';
            els.toast.hidden = false;
            toastTimer = window.setTimeout(function () {
                els.toast.hidden = true;
            }, 5200);
        }

        function createRequestToken() {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID().replace(/-/g, '');
            }

            var alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_';
            var token = '';
            for (var index = 0; index < 32; index++) {
                token += alphabet.charAt(Math.floor(Math.random() * alphabet.length));
            }
            return token;
        }

        function clearCreateModalErrors() {
            if (!createForm) {
                return;
            }

            createForm.querySelectorAll('.reservation-detail-field-msg').forEach(function (message) {
                message.textContent = '';
                message.classList.remove('show');
            });
            createForm.querySelectorAll('[aria-invalid="true"]').forEach(function (field) {
                field.setAttribute('aria-invalid', 'false');
            });
            if (createModalError) {
                createModalError.textContent = '';
                createModalError.hidden = true;
            }
        }

        function renderCreateModalErrors(errors, message) {
            if (!createForm) {
                return;
            }

            clearCreateModalErrors();
            var firstInvalid = null;
            Object.keys(errors || {}).forEach(function (fieldName) {
                var control = createForm.elements[fieldName];
                if (!control) {
                    return;
                }

                var element = control.length && control.item ? control.item(0) : control;
                var label = element.closest('label');
                var fieldMessage = label ? label.querySelector('.reservation-detail-field-msg') : null;
                var messages = Array.isArray(errors[fieldName]) ? errors[fieldName] : [errors[fieldName]];
                var text = messages.filter(Boolean).join(' ');

                if (fieldMessage) {
                    fieldMessage.textContent = text;
                    fieldMessage.classList.toggle('show', Boolean(text));
                }

                var visibleControl = label
                    ? (label.querySelector('[data-date-display], [data-time-display], input:not([type="hidden"]), select, textarea') || element)
                    : element;
                if (visibleControl && visibleControl.setAttribute) {
                    visibleControl.setAttribute('aria-invalid', text ? 'true' : 'false');
                    if (text && !firstInvalid) {
                        firstInvalid = visibleControl;
                    }
                }
            });

            if (createModalError && message) {
                createModalError.textContent = message;
                createModalError.hidden = false;
            }
            if (firstInvalid) {
                firstInvalid.focus();
            }
        }

        function setCreateFormValue(name, value) {
            if (!createForm || !createForm.elements[name]) {
                return;
            }
            createForm.elements[name].value = value == null ? '' : String(value);
        }

        function resetCreateModalForm() {
            if (!createForm) {
                return;
            }

            clearCreateModalErrors();
            createForm.removeAttribute('data-operational-warning-accepted');
            setCreateFormValue('nombre', '');
            setCreateFormValue('contacto_tipo', 'email');
            setCreateFormValue('contacto', '');
            setCreateFormValue('comensales', '2');
            setCreateFormValue('nota', '');
            setCreateFormValue('comentario_admin', '');
            setCreateFormValue('request_token', createRequestToken());

            var automatic = createForm.querySelector('[name="asignar_automaticamente"][value="1"]');
            if (automatic) {
                automatic.checked = true;
            }

            var targetDate = (els.create && els.create.getAttribute('data-create-date')) || state.fecha;
            if (createForm.__reservationDatePicker) {
                createForm.__reservationDatePicker.setValue(targetDate, true);
            } else {
                setCreateFormValue('fecha', targetDate);
            }

            if (createForm.__reservationTimePicker) {
                createForm.__reservationTimePicker.loadForDate(targetDate, '');
            } else {
                setCreateFormValue('hora', '');
            }

            createModalDirty = false;
            createForm.dispatchEvent(new CustomEvent('reservation:reset-submit', { bubbles: true }));
        }

        function closeCreateModal(force) {
            if (!createModal) {
                return true;
            }

            if (!force && createModalDirty && !window.confirm('Hay cambios sin guardar. ¿Cerrar el formulario?')) {
                return false;
            }

            if (typeof createModal.close === 'function' && createModal.open) {
                createModal.close();
            } else {
                createModal.removeAttribute('open');
            }
            root.classList.remove('is-create-modal-open');
            createModalDirty = false;
            if (createModalLastFocus && typeof createModalLastFocus.focus === 'function') {
                createModalLastFocus.focus();
            }
            createModalLastFocus = null;
            return true;
        }

        function openCreateModal() {
            if (!createModal || !createForm || createModalSubmitting) {
                return;
            }

            createModalLastFocus = document.activeElement;
            root.classList.add('is-create-modal-open');
            if (typeof createModal.showModal === 'function') {
                if (!createModal.open) {
                    createModal.showModal();
                }
            } else {
                createModal.setAttribute('open', '');
            }
            resetCreateModalForm();

            window.setTimeout(function () {
                var firstField = createForm.querySelector('[name="nombre"]');
                if (firstField) {
                    firstField.focus();
                }
            }, 0);
        }

        function submitCreateModal() {
            if (!createForm || createModalSubmitting) {
                return;
            }

            createModalSubmitting = true;
            clearCreateModalErrors();

            postJson(createForm.action, new URLSearchParams(new FormData(createForm)))
                .then(function (payload) {
                    var reservationId = parseInt(payload.reservationId || payload.id || '0', 10) || null;
                    var fecha = payload.fecha || createForm.elements.fecha && createForm.elements.fecha.value || state.fecha;
                    var hora = horaCorta(payload.hora || createForm.elements.hora && createForm.elements.hora.value || state.horaSeleccionada);
                    var message = payload.message || payload.msg || 'Reservación creada.';

                    state.pendingCreationFeedback = {
                        reservationId: reservationId,
                        fecha: fecha,
                        hora: hora,
                        nombre: createForm.elements.nombre ? createForm.elements.nombre.value : '',
                        comensales: createForm.elements.comensales ? createForm.elements.comensales.value : '0',
                        mesaIds: Array.isArray(payload.mesaIds) ? payload.mesaIds : [],
                        message: message
                    };

                    closeCreateModal(true);
                    loadDay(fecha, {
                        preserveReservationId: reservationId,
                        preserveHour: hora
                    });
                })
                .catch(function (error) {
                    var fieldErrors = error && (error.fieldErrors || error.errors) || {};
                    var message = error && (error.message || error.msg) || 'No fue posible crear la reservación.';
                    renderCreateModalErrors(fieldErrors, message);
                })
                .finally(function () {
                    createModalSubmitting = false;
                    createForm.dispatchEvent(new CustomEvent('reservation:reset-submit', { bubbles: true }));
                });
        }

        function renderLoadingShell() {
            if (els.reservations) {
                els.reservations.innerHTML =
                    '<div class="reservation-operation-skeleton">' +
                        '<span></span><span></span><span></span>' +
                    '</div>';
            }
            if (els.map) {
                mapVisual.clear('Cargando mapa');
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
            renderMode();
            renderScheduleSelector();
            renderOperationContext();
            renderReservationList();
            renderReservationDetail();
            renderTableMap();
            renderAssignmentBar();
            if (els.create) {
                els.create.setAttribute('data-create-date', state.fecha || '');
            }
            updateHeaderContext();
            updateUrl();
            if (state.pendingCreationFeedback) {
                var creationFeedback = state.pendingCreationFeedback;
                state.pendingCreationFeedback = null;
                showCreationFeedback(creationFeedback);
            }
        }

        function renderScheduleSelector() {
            var hours = sortedHours();

            if (!els.hour) {
                return;
            }

            if (timePicker) {
                timePicker.setOptions(
                    hours,
                    state.horaSeleccionada,
                    state.mensajeOperacion || 'No hay horarios disponibles para la fecha seleccionada.',
                    true
                );
                timePicker.setDisabled(state.cargando || hours.length === 0);
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
            var editable = isEditable(reservacion);
            var estado = String(reservacion.estado || 'confirmada');

            return window.OperationalReservationCard.render(reservacion, {
                hora: hora,
                estado: estado,
                estadoLabel: estadoLabel(estado),
                seleccionada: selected,
                mostrarSinMesas: true,
                clases: [
                    selected ? 'is-selected' : '',
                    editable ? '' : 'is-readonly',
                    highlighted ? 'is-highlighted' : '',
                    dimmed ? 'is-dimmed' : ''
                ]
            });
        }

        function renderPanelState(kind) {
            if (!els.panel) {
                return;
            }

            var states = {
                selection: ['Selecciona una reservación', 'Elige una reservación del menú lateral para consultar sus datos y mesas.', 'reservation-operation-panel--selection'],
                empty: ['No hay reservaciones para este horario.', 'Puedes consultar otro horario o crear una nueva reservación.', 'reservation-operation-panel--empty'],
                error: ['No fue posible cargar las reservaciones.', 'Intenta actualizar la consulta.', 'reservation-operation-panel--error']
            };
            var current = states[kind] || states.selection;
            root.classList.remove('has-selected-reservation');
            els.panel.innerHTML =
                '<article class="reservation-operation-panel admin-card ' + current[2] + '" role="status" aria-live="polite">' +
                    '<h3>' + current[0] + '</h3>' +
                    '<p class="reservation-operation-panel__muted">' + current[1] + '</p>' +
                '</article>';
        }

        function nombreAbreviado(nombre) {
            var partes = String(nombre || '').trim().split(/\s+/).filter(Boolean);
            return partes.length > 2 ? partes.slice(0, 2).join(' ') : partes.join(' ');
        }

        function renderReservationContext(reservacion) {
            if (!els.selectionCopy) {
                return;
            }
            if (!reservacion) {
                els.selectionCopy.textContent = 'Ninguna reservación seleccionada';
                return;
            }
            var mesas = Array.isArray(reservacion.mesas_asignadas) && reservacion.mesas_asignadas.length
                ? reservacion.mesas_asignadas.join(', ')
                : 'Sin mesas asignadas';
            els.selectionCopy.textContent = '#' + reservacion.id + ' · ' + nombreAbreviado(reservacion.nombre) + ' · ' + mesas;
        }

        function renderRecommendedAction(reservacion, mesaIds, editable) {
            if (!editable) {
                return { key: '', html: '' };
            }
            if (!mesaIds.length) {
                return { key: 'reassign', html: renderActionButton('reassign', 'Cambiar mesas', 'admin-btn admin-btn--primary', false) };
            }
            if (canTransition(reservacion, 'confirmada')) {
                return { key: 'confirm', html: renderActionButton('confirm', 'Confirmar llegada', 'admin-btn admin-btn--primary', false) };
            }
            if (canTransition(reservacion, 'completada')) {
                return { key: 'complete', html: renderActionButton('complete', 'Completar reservación', 'admin-btn admin-btn--primary', false) };
            }
            if (canTransition(reservacion, 'no_show')) {
                return { key: 'no-show', html: renderActionButton('no-show', 'Marcar no show', 'admin-btn admin-btn--primary', false) };
            }
            return { key: '', html: '' };
        }

        function renderReservationDetail() {
            var reservacion = selectedReservation();

            if (!els.panel) {
                return;
            }

            root.classList.toggle('has-selected-reservation', Boolean(reservacion));
            renderReservationContext(reservacion);

            if (!reservacion) {
                renderPanelState(state.loadFailure && !state.hasLoadedData ? 'error' : (state.reservaciones.length ? 'selection' : 'empty'));
                return;
            }

            var editable = isEditable(reservacion);
            var estado = String(reservacion.estado || 'confirmada');
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
                    '<div class="reservation-operation-panel__head reservation-operation__summary">' +
                        '<div>' +
                            '<span class="reservation-operation-panel__label">Reservación #' + esc(reservacion.id) + '</span>' +
                            '<h3>' + esc(reservacion.nombre) + '</h3>' +
                        '</div>' +
                        '<span class="reservations-table__status reservations-table__status--' + esc(estado) + '">' + esc(estadoLabel(estado)) + '</span>' +
                    '</div>' +
                    '<dl class="reservation-operation-panel__facts">' +
                        '<div><dt>Hora</dt><dd>' + esc(horaCorta(reservacion.hora)) + '</dd></div>' +
                        '<div><dt>Personas</dt><dd>' + esc(plural(comensales, 'persona', 'personas')) + '</dd></div>' +
                        '<div class="reservation-operation-panel__fact--wide"><dt>Mesas asignadas</dt><dd>' + esc(mesasActuales) + '</dd></div>' +
                        '<div><dt>Capacidad</dt><dd>' + esc(String(capacidad)) + '</dd></div>' +
                        '<div><dt>Diferencia</dt><dd class="' + (diferencia < 0 ? 'is-insufficient' : '') + '">' + (diferencia > 0 ? '+' : '') + esc(String(diferencia)) + '</dd></div>' +
                        '<div class="reservation-operation-panel__fact--wide reservation-operation-panel__fact--contact"><dt>Contacto</dt><dd>' + esc(reservacion.contacto) + '</dd></div>' +
                    '</dl>' +
                    '<section class="reservation-operation-panel__section reservation-operation-panel__section--actions reservation-operation__quick-actions">' +
                        (function () {
                            var recommended = renderRecommendedAction(reservacion, mesaIds, editable);
                            var other = '';
                            if (recommended.key !== 'reassign') other += renderActionButton('reassign', 'Cambiar mesas', 'admin-btn admin-btn--secondary', !editable);
                            if (recommended.key !== 'complete') other += renderActionButton('complete', 'Completar', 'admin-btn admin-btn--secondary', !canTransition(reservacion, 'completada'));
                            if (recommended.key !== 'no-show') other += renderActionButton('no-show', 'No show', 'admin-btn admin-btn--ghost', !canTransition(reservacion, 'no_show'));
                            return '<div class="reservation-operation-action-group reservation-operation-action-group--recommended"><h4>Siguiente acción</h4>' + (recommended.html || '<p class="reservation-operation-panel__muted">No hay una acción recomendada para este estado.</p>') + '</div>' +
                                '<div class="reservation-operation-action-group"><h4>Otras acciones</h4><div class="reservation-operation-actions">' + (other || '<p class="reservation-operation-panel__muted">No hay otras acciones disponibles.</p>') + '</div></div>' +
                                '<div class="reservation-operation-action-group reservation-operation-action-group--danger"><h4>Cancelar reservación</h4>' + renderActionButton('cancel', 'Cancelar reservación', 'admin-btn admin-btn--danger', !canTransition(reservacion, 'cancelada')) + '</div>';
                        })() +
                    '</section>' +
                    '<section class="reservation-operation-panel__section reservation-operation-panel__section--assignment reservation-operation__assignment">' +
                        '<h4>Mesas asignadas</h4>' +
                        (!editable ? '<p class="reservation-operation-inline reservation-operation-inline--muted">Este estado es de solo lectura.</p>' : '') +
                        (editable ? '<button class="admin-btn admin-btn--secondary reservation-operation-panel__assignment-start" type="button" data-operation-assignment-start aria-controls="operation-assignment-bar" aria-expanded="' + (state.assignmentMode ? 'true' : 'false') + '">Cambiar mesas</button>' : '') +
                        '<div class="reservation-operation-summary">' +
                            '<div><span>Reservación</span><strong>' + comensales + ' personas</strong></div>' +
                            '<div><span>Capacidad total</span><strong class="' + (insufficient ? 'is-insufficient' : '') + '">' + capacidad + '</strong></div>' +
                            '<div><span>Diferencia</span><strong class="' + (diferencia < 0 ? 'is-insufficient' : '') + '">' + (diferencia > 0 ? '+' : '') + diferencia + '</strong></div>' +
                        '</div>' +
                        '<div class="reservation-operation-panel__selected ' + (!selectedNames.length ? 'is-empty' : '') + '"><span>Mesas asignadas</span><strong>' + esc(selectedNames.length ? selectedNames.join(', ') : 'Sin mesas asignadas') + '</strong></div>' +
                        (insufficient ? '<p class="reservation-operation-inline reservation-operation-inline--warning">La capacidad seleccionada es menor que los comensales. Puedes guardar explícitamente esta asignación.</p>' : '') +
                        '<p class="reservation-operation-inline reservation-operation-inline--error" data-operation-inline-error hidden></p>' +
                        '<button class="admin-btn admin-btn--primary reservation-operation-panel__submit" type="button" data-operation-save data-disabled="' + (!editable || state.mesasSeleccionadas.size === 0 ? '1' : '0') + '"' + (!editable || state.mesasSeleccionadas.size === 0 || state.guardando ? ' disabled' : '') + '>' +
                            (insufficient ? 'Guardar de todos modos' : 'Guardar asignación') +
                        '</button>' +
                    '</section>' +
                    '<section class="reservation-operation-panel__section reservation-operation__client-note">' +
                        '<h4>Nota del cliente</h4>' +
                        (reservacion.nota ? '<p class="reservation-operation-panel__note">' + esc(reservacion.nota) + '</p>' : '<p class="reservation-operation-panel__muted">Sin nota del cliente.</p>') +
                    '</section>' +
                    '<section class="reservation-operation-panel__section reservation-operation__comment">' +
                        '<h4>Comentario interno</h4>' +
                        renderCommentBox(reservacion, editable) +
                    '</section>' +
                    '<a class="admin-btn admin-btn--secondary reservation-operation-panel__edit reservation-operation__secondary-actions" href="' + esc(buildDetailUrl(reservacion)) + '">' + (editable ? 'Editar reservación' : 'Ver detalle') + '</a>' +
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

            var editable = reservacion ? isEditable(reservacion) : false;
            var ocupacion = reservacion
                ? (state.ocupacionPorReservacion[String(reservacion.id)] || state.ocupacionPorReservacion[reservacion.id] || {})
                : {};
            var mesasVisuales = state.mesas.map(function (mesa) {
                var mesaId = parseInt(mesa.id, 10);
                var active = parseInt(mesa.activo || '0', 10) === 1;
                var reservable = parseInt(mesa.reservable || '0', 10) === 1;
                var assigned = state.mesasSeleccionadas.has(mesaId);
                var occupied = ocupacion[String(mesaId)] || ocupacion[mesaId] || null;
                var estado = !active || !reservable ? 'no-reservable' : (assigned ? 'asignada' : (occupied ? 'ocupada' : 'libre'));
                var assignedToSelection = Boolean(reservacion && assigned);
                var selectable = Boolean(reservacion) && editable && active && reservable && !occupied;
                var title = !active || !reservable
                    ? mesa.nombre + ' no reservable'
                    : (occupied ? 'Ocupada por ' + occupied.nombre + ' a las ' + horaCorta(occupied.hora) : mesa.nombre + ' disponible');

                return {
                    id: mesaId,
                    nombre: mesa.nombre,
                    tipo: mesa.tipo || 'mesa',
                    estadoVisual: estado,
                    x: mesa.pos_x,
                    y: mesa.pos_y,
                    ancho: mesa.ancho,
                    alto: mesa.alto,
                    reservable: active && reservable,
                    capacidad: mesa.capacidad,
                    seleccionada: assigned,
                    interactivo: selectable && !state.guardando,
                    titulo: title,
                    clasesEstado: assignedToSelection
                        ? ['mesa-pin--bloqueada', 'reservation-operation-pin--assigned', 'reservation-operation-pin--selected']
                        : (estado === 'no-reservable' ? ['mesa-pin--zona'] : []),
                    atributos: {
                        'data-operation-table': mesaId,
                        'data-disabled': !selectable || state.guardando ? '1' : '0'
                    }
                };
            });

            mapVisual.render({
                mesas: mesasVisuales,
                elementos: []
            });

        }

        function selectReservation(id) {
            var reservacion = findReservationById(id);

            exitAssignmentMode({ render: false, restoreFocus: false });

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
            exitAssignmentMode({ render: false, restoreFocus: false });
            state.horaSeleccionada = horaCorta(hora);

            state.reservacionSeleccionadaId = null;
            state.mesasSeleccionadas = new Set();
            renderAll();
        }

        function loadDay(fecha, options) {
            options = options || {};
            fecha = String(fecha || '').trim();

            exitAssignmentMode({ render: false, restoreFocus: false });

            if (!fecha) {
                return;
            }

            if (!fechaValida(fecha)) {
                showDateWarning(state.fecha || state.fechaMinima);
                return;
            }

            if (state.timeoutId) {
                window.clearTimeout(state.timeoutId);
                state.timeoutId = null;
            }
            if (state.abortController) {
                state.abortController.abort();
            }

            state.abortController = new AbortController();
            var requestSequence = ++state.requestSequence;
            state.timedOutSequence = 0;
            var requestedHour = horaCorta(options.preserveHour || state.horaSeleccionada);
            if (!options.preserveDateWarning) {
                clearDateWarning();
            }
            setLoading(true);
            if (!state.reservaciones.length && !state.mesas.length) {
                renderLoadingShell();
            }
            if (els.loadError) {
                els.loadError.hidden = true;
            }

            state.timeoutId = window.setTimeout(function () {
                state.timedOutSequence = requestSequence;
                if (state.abortController) {
                    state.abortController.abort();
                }
            }, 12000);

            fetch(API_BASE + '?fecha=' + encodeURIComponent(fecha), {
                headers: { 'Accept': 'application/json' },
                signal: state.abortController.signal,
                credentials: 'same-origin'
            })
                .then(function (response) {
                    return response.text().then(function (body) {
                        var data;
                        try {
                            data = JSON.parse(body);
                        } catch (parseError) {
                            throw { kind: 'invalid_json', httpStatus: response.status };
                        }
                        if (!response.ok || !data.ok) {
                            data.httpStatus = response.status;
                            data.kind = response.status >= 500 ? 'server' : 'business';
                            throw data;
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    if (requestSequence !== state.requestSequence) {
                        return;
                    }
                    if (data.fecha !== fecha) {
                        throw { kind: 'consistency', requestedDate: fecha, responseDate: data.fecha };
                    }
                    if (state.timeoutId) {
                        window.clearTimeout(state.timeoutId);
                        state.timeoutId = null;
                    }
                    state.fecha = data.fecha || fecha;
                    state.fechaFallida = '';
                    state.loadFailure = null;
                    state.modo = data.modo || 'operacion';
                    state.editable = data.editable !== false;
                    state.horarios = data.horarios || [];
                    state.reservaciones = data.reservaciones || [];
                    state.mesas = data.mesas || [];
                    state.ocupacionPorReservacion = data.ocupacion_por_reservacion || {};
                    state.estadoOperacion = data.estado_operacion || 'disponible';
                    state.mensajeOperacion = data.mensaje || '';
                    state.hasLoadedData = true;
                    state.config.estadoLabels = (data.config && data.config.estado_labels) || {};
                    state.config.estadosEditables = (data.config && data.config.estados_editables) || [];
                    state.config.transiciones = (data.config && data.config.transiciones) || {};
                    state.config.comentarioAdminDisponible = Boolean(data.config && data.config.comentario_admin_disponible);

                    var selected = options.preserveReservationId
                        ? findReservationById(options.preserveReservationId)
                        : null;
                    if (selected) {
                        state.reservacionSeleccionadaId = parseInt(selected.id, 10);
                        state.horaSeleccionada = horaCorta(selected.hora);
                        state.mesasSeleccionadas = new Set((selected.mesa_ids || []).map(function (mesaId) {
                            return parseInt(mesaId, 10);
                        }));
                    } else {
                        var availableHours = sortedHours();
                        state.reservacionSeleccionadaId = null;
                        state.horaSeleccionada = requestedHour && availableHours.indexOf(requestedHour) !== -1
                            ? requestedHour
                            : (data.hora_sugerida || availableHours[0] || null);
                        state.mesasSeleccionadas = new Set();
                    }

                    setDateValue(state.fecha);
                    setLoading(false);
                    renderAll();
                    setUpdateStatus('Actualizado ' + new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' }), 'ready');
                })
                .catch(function (error) {
                    if (state.timeoutId) {
                        window.clearTimeout(state.timeoutId);
                        state.timeoutId = null;
                    }
                    if (error && error.name === 'AbortError') {
                        if (state.timedOutSequence === requestSequence && requestSequence === state.requestSequence) {
                            setLoading(false);
                            state.loadFailure = { title: 'No fue posible cargar las reservaciones.', message: 'Intenta actualizar la consulta.' };
                            setUpdateStatus('Tiempo agotado', 'error');
                            showTechnicalError('timeout', fecha);
                            if (!state.hasLoadedData && els.panel) {
                                renderPanelState('error');
                            }
                        }
                        return;
                    }
                    if (requestSequence !== state.requestSequence) {
                        return;
                    }
                    setLoading(false);
                    state.loadFailure = { title: 'No fue posible cargar las reservaciones.', message: 'Intenta actualizar la consulta.' };
                    var kind = error instanceof TypeError ? 'connection' : ((error && error.kind) || 'unexpected');
                    setUpdateStatus(kind === 'connection' ? 'Sin conexion' : 'Error al actualizar', 'error');
                    showTechnicalError(kind, fecha);
                    if (!state.hasLoadedData && els.panel) {
                        renderPanelState('error');
                    }
                });
        }

        function refreshDay(options) {
            options = options || {};
            loadDay(state.fecha, {
                preserveReservationId: options.preserveReservationId || state.reservacionSeleccionadaId,
                preserveHour: state.horaSeleccionada
            });
        }

        function postJson(url, data) {
            var controller = new AbortController();
            var timeoutId = window.setTimeout(function () {
                controller.abort();
            }, 12000);

            return fetch(url, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                credentials: 'same-origin',
                signal: controller.signal,
                body: data.toString()
            }).then(function (response) {
                return response.text().then(function (body) {
                    var payload;
                    try {
                        payload = JSON.parse(body);
                    } catch (parseError) {
                        throw { kind: 'invalid_json', httpStatus: response.status };
                    }
                    if (!response.ok || !payload.ok) {
                        payload.httpStatus = response.status;
                        payload.kind = response.status >= 500 ? 'server' : 'business';
                        throw payload;
                    }
                    return payload;
                });
            }).catch(function (error) {
                if (error && error.name === 'AbortError') {
                    throw { kind: 'timeout' };
                }
                throw error;
            }).finally(function () {
                window.clearTimeout(timeoutId);
            });
        }

        function operationErrorMessage(error, fallback) {
            if (error && error.mensaje) {
                return error.mensaje;
            }
            if (error instanceof TypeError) {
                return 'No se pudo conectar con el servidor. Los datos visibles no cambiaron.';
            }
            if (error && error.kind === 'invalid_json') {
                return 'No fue posible interpretar la respuesta del servidor. Los datos visibles no cambiaron.';
            }
            if (error && error.kind === 'timeout') {
                return 'La solicitud excedio el tiempo de espera. Los datos visibles no cambiaron.';
            }
            if (error && error.kind === 'server') {
                return 'El servidor no pudo completar la accion. Los datos visibles no cambiaron.';
            }
            return fallback;
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
                    exitAssignmentMode({ restoreFocus: false });
                    showToast('Asignacion guardada.', 'success');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && error.codigo === 'MESA_OCUPADA') {
                        showInlineError('La mesa acaba de ser asignada a otra reservacion. Los datos fueron actualizados.');
                        refreshDay({ preserveReservationId: reservacion.id });
                        return;
                    }
                    showInlineError(operationErrorMessage(error, 'No fue posible guardar los cambios. Intentalo nuevamente.'));
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
                .catch(function (error) {
                    showInlineError(operationErrorMessage(error, 'No fue posible guardar los cambios. Intentalo nuevamente.'));
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
                    showInlineError(operationErrorMessage(error, 'No fue posible guardar los cambios. Intentalo nuevamente.'));
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
            if (!estado || !canTransition(reservacion, estado)) {
                showInlineError('La transicion solicitada ya no esta disponible. Actualiza los datos e intenta nuevamente.');
                return;
            }
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
                    showInlineError(operationErrorMessage(error, 'No fue posible guardar los cambios. Intentalo nuevamente.'));
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

        function requestSelectedDate() {
            loadDay(els.date ? els.date.value : state.fecha, {
                preserveHour: els.hour ? els.hour.value : state.horaSeleccionada
            });
        }

        if (els.dateRoot && window.createReservationDatePicker) {
            datePicker = window.createReservationDatePicker({
                root: els.dateRoot,
                minDate: state.fechaMinima,
                today: state.fechaMinima,
                initialValue: state.fecha,
                onChange: requestSelectedDate
            });
        }

        if (els.hourRoot && window.createReservationTimePicker) {
            timePicker = window.createReservationTimePicker({
                root: els.hourRoot,
                initialTime: state.horaSeleccionada,
                autoLoad: false
            });
        }

        if (els.filters) {
            els.filters.addEventListener('submit', function (event) {
                event.preventDefault();
                requestSelectedDate();
            });
        }

        if (els.date) {
            els.date.addEventListener('change', requestSelectedDate);
        }

        if (els.hour) {
            els.hour.addEventListener('reservation:timechange', function () {
                selectTime(els.hour.value);
            });
            if (!timePicker) {
                els.hour.addEventListener('change', function () {
                    selectTime(els.hour.value);
                });
            }
        }

        if (createModal && createForm) {
            if (els.create) {
                els.create.addEventListener('click', function (event) {
                    event.preventDefault();
                    openCreateModal();
                });
            }

            createForm.addEventListener('reservation:jsonsubmit', submitCreateModal);
            createForm.addEventListener('input', function () {
                createModalDirty = true;
            });
            createForm.addEventListener('change', function () {
                createModalDirty = true;
            });

            var closeCreateButtons = createModal.querySelectorAll('[data-operation-create-close], [data-operation-create-cancel]');
            closeCreateButtons.forEach(function (closeCreateButton) {
                closeCreateButton.addEventListener('click', function () {
                    closeCreateModal(false);
                });
            });

            createModal.addEventListener('keydown', function (event) {
                if (event.key !== 'Tab') {
                    return;
                }

                var focusable = Array.prototype.slice.call(createModal.querySelectorAll(
                    'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'
                )).filter(function (element) {
                    return !element.hidden && element.offsetParent !== null;
                });

                if (!focusable.length) {
                    event.preventDefault();
                    createModal.focus();
                    return;
                }

                var first = focusable[0];
                var last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault();
                    last.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault();
                    first.focus();
                }
            });

            createModal.addEventListener('cancel', function (event) {
                event.preventDefault();
                closeCreateModal(false);
            });
        }

        root.addEventListener('click', function (event) {
            var target = event.target;
            var createTrigger = target && typeof target.closest === 'function'
                ? target.closest('[data-operation-create]')
                : null;

            if (createTrigger && root.contains(createTrigger)) {
                event.preventDefault();
                openCreateModal();
            }
        });

        if (root.getAttribute('data-initial-date-warning') === '1') {
            showDateWarning(state.fecha);
        }

        root.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && createModal && createModal.open) {
                event.preventDefault();
                closeCreateModal(false);
                return;
            }

            if (event.key === 'Escape' && state.assignmentMode) {
                event.preventDefault();
                cancelAssignmentMode();
                return;
            }

            var mobileTab = event.target.closest('[data-operation-mobile-tab]');
            if (!mobileTab || (event.key !== 'Enter' && event.key !== ' ')) {
                return;
            }

            event.preventDefault();
            setMobileView(mobileTab.getAttribute('data-operation-mobile-tab'));
        });

        els.map.addEventListener('mapa:mesa-click', function (event) {
            if (!event.detail || event.detail.contexto !== 'operacion-reservaciones') {
                return;
            }

            if (!state.assignmentMode && window.matchMedia('(max-width: 1199px)').matches) {
                enterAssignmentMode(null, { focus: false });
            }

            var mesaId = parseInt(event.detail.mesaId, 10);
            if (state.mesasSeleccionadas.has(mesaId)) {
                state.mesasSeleccionadas.delete(mesaId);
            } else {
                state.mesasSeleccionadas.add(mesaId);
            }

            renderReservationDetail();
            renderTableMap();
            renderAssignmentBar();
        });

        root.addEventListener('click', function (event) {
            var reservationButton = event.target.closest('[data-operation-reservation]');
            var assignmentStart = event.target.closest('[data-operation-assignment-start]');
            var assignmentCancel = event.target.closest('[data-operation-assignment-cancel]');
            var saveButton = event.target.closest('[data-operation-save]');
            var commentButton = event.target.closest('[data-operation-comment-save]');
            var actionButton = event.target.closest('[data-operation-action]');
            var retryButton = event.target.closest('[data-operation-retry]');
            var mobileTab = event.target.closest('[data-operation-mobile-tab]');

            if (mobileTab) {
                setMobileView(mobileTab.getAttribute('data-operation-mobile-tab'));
                return;
            }

            if (retryButton) {
                loadDay(state.fechaFallida || state.fecha, {
                    preserveReservationId: state.reservacionSeleccionadaId,
                    preserveHour: state.horaSeleccionada
                });
                return;
            }

            if (reservationButton) {
                selectReservation(reservationButton.getAttribute('data-operation-reservation'));
                return;
            }

            if (assignmentStart) {
                enterAssignmentMode(assignmentStart);
                return;
            }

            if (assignmentCancel) {
                cancelAssignmentMode();
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

        loadDay(state.fecha, {
            preserveReservationId: state.reservacionSeleccionadaId,
            preserveHour: state.horaSeleccionada,
            preserveDateWarning: root.getAttribute('data-initial-date-warning') === '1'
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReservationOperation);
    } else {
        initReservationOperation();
    }
})();
