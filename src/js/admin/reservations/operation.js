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
        var csrfToken = root.getAttribute('data-admin-csrf') || '';

        var state = {
            fecha: root.getAttribute('data-initial-fecha') || '',
            fechaMinima: root.getAttribute('data-min-fecha') || '',
            fechaFallida: '',
            modo: root.getAttribute('data-operation-mode') || 'operacion',
            editable: root.getAttribute('data-operation-editable') !== '0',
            horarios: [],
            reservaciones: [],
            mesas: [],
            mesasEstado: [],
            ocupacionFisica: [],
            ocupacionPorReservacion: {},
            capacidadHorario: {},
            alertasOperativas: [],
            estadoOperacion: 'disponible',
            mensajeOperacion: '',
            scheduleAlertDismissed: false,
            hasLoadedData: false,
            loadFailure: null,
            pendingCreationFeedback: null,
            pendingInitialAssignment: root.getAttribute('data-initial-operation-intent') === 'assign',
            reservacionSeleccionadaId: parseInt(root.getAttribute('data-initial-reservation-id') || '0', 10) || null,
            horaSeleccionada: horaCorta(root.getAttribute('data-initial-hora') || ''),
            assignmentFilter: 'all',
            reservationSearch: '',
            horaSolicitadaInicial: horaCorta(root.getAttribute('data-initial-requested-hour') || ''),
            mesasSeleccionadas: new Set(),
            assignmentMode: false,
            assignmentInitialMesaIds: [],
            assignmentInitialVersion: '',
            assignmentDataUpdated: false,
            assignmentTrigger: null,
            cargando: false,
            guardando: false,
            abortController: null,
            timeoutId: null,
            timedOutSequence: 0,
            requestSequence: 0,
            tableWarningMesaId: null,
            pendingAssignmentConflict: null,
            pendingAction: null,
            config: {
                estadoLabels: {},
                estadosEditables: [],
                transiciones: {},
                comentarioAdminDisponible: root.getAttribute('data-comment-enabled') === '1',
                temporal: {}
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
            assignmentFilter: root.querySelector('[data-operation-assignment-filter]'),
            reservationSearch: root.querySelector('[data-operation-reservation-search]'),
            load: root.querySelector('[data-operation-load]'),
            create: root.querySelector('[data-operation-create]'),
            title: root.querySelector('[data-operation-title]'),
            description: root.querySelector('[data-operation-description]'),
            results: root.querySelector('[data-operation-results]'),
            globalNotice: document.querySelector('#global-operation-notice-root [data-operation-global-notice]'),
            globalNoticeIcon: document.querySelector('#global-operation-notice-root [data-operation-global-notice-icon]'),
            globalNoticeTitle: document.querySelector('#global-operation-notice-root [data-operation-global-notice-title]'),
            globalNoticeSummary: document.querySelector('#global-operation-notice-root [data-operation-global-notice-summary]'),
            globalNoticeMessage: document.querySelector('#global-operation-notice-root [data-operation-global-notice-message]'),
            globalNoticeDetail: document.querySelector('#global-operation-notice-root [data-operation-global-notice-detail]'),
            globalNoticeExpand: document.querySelector('#global-operation-notice-root [data-operation-global-notice-expand]'),
            globalNoticeClose: document.querySelector('#global-operation-notice-root [data-operation-global-notice-close]'),
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
            assignmentRefresh: root.querySelector('[data-operation-assignment-refresh]'),
            assignmentClear: root.querySelector('[data-operation-clear]'),
            capacity: root.querySelector('[data-operation-capacity]'),
            capacityTotal: root.querySelector('[data-operation-capacity-total]'),
            capacityReal: root.querySelector('[data-operation-capacity-real]'),
            capacityProjected: root.querySelector('[data-operation-capacity-projected]'),
            capacityEstimated: root.querySelector('[data-operation-capacity-estimated]'),
            capacityWarning: root.querySelector('[data-operation-capacity-warning]'),
            tableWarning: null
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
        var ticketConflictModal = root.querySelector('[data-operation-ticket-conflict-modal]');
        var ticketConflictList = root.querySelector('[data-operation-ticket-conflict-list]');
        var ticketConflictError = root.querySelector('[data-operation-ticket-conflict-error]');
        var actionModal = root.querySelector('[data-operation-action-modal]');
        var actionTitle = root.querySelector('[data-operation-action-title]');
        var actionDescription = root.querySelector('[data-operation-action-description]');
        var actionReasonField = root.querySelector('[data-operation-action-reason-field]');
        var actionReason = root.querySelector('[data-operation-action-reason]');
        var actionError = root.querySelector('[data-operation-action-error]');
        var ticketConflictLastFocus = null;
        var actionModalLastFocus = null;

        var noticeTimer = null;
        var activeNoticeSource = '';
        var dismissedNoticeSources = {};
        var temporalIntervalId = null;
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

        function hideGlobalNotice(source) {
            if (!els.globalNotice || (source && activeNoticeSource !== source)) {
                return;
            }

            window.clearTimeout(noticeTimer);
            noticeTimer = null;
            activeNoticeSource = '';
            els.globalNotice.hidden = true;
            els.globalNotice.classList.remove('is-expanded');
            if (els.globalNoticeDetail) els.globalNoticeDetail.setAttribute('aria-hidden', 'true');
            if (els.globalNoticeExpand) {
                els.globalNoticeExpand.setAttribute('aria-expanded', 'false');
                els.globalNoticeExpand.textContent = 'Expandir';
            }
        }

        function showGlobalNotice(options) {
            if (!els.globalNotice) {
                return;
            }

            options = options || {};
            if (options.respectDismissal === true && dismissedNoticeSources[options.source || 'context']) {
                return;
            }
            var type = ['info', 'warning', 'success', 'error', 'restricted'].indexOf(options.type) !== -1
                ? options.type
                : 'info';
            var icons = {
                info: 'i',
                warning: '!',
                success: '✓',
                error: '!',
                restricted: '×'
            };

            window.clearTimeout(noticeTimer);
            noticeTimer = null;
            activeNoticeSource = options.source || 'context';
            els.globalNotice.className = 'operational-global-notice operational-global-notice--' + type;
            els.globalNotice.setAttribute('role', type === 'error' ? 'alert' : 'status');
            if (els.globalNoticeIcon) els.globalNoticeIcon.textContent = icons[type];
            if (els.globalNoticeTitle) els.globalNoticeTitle.textContent = options.title || 'Aviso';
            if (els.globalNoticeSummary) {
                els.globalNoticeSummary.textContent = options.summary || 'Consulta este aviso operativo.';
            }
            if (els.globalNoticeMessage) {
                els.globalNoticeMessage.textContent = options.message ||
                    'Revisa el contexto mostrado y continúa con una opción disponible.';
            }
            if (els.globalNoticeClose) {
                els.globalNoticeClose.hidden = options.dismissible === false;
            }
            els.globalNotice.classList.remove('is-expanded');
            if (els.globalNoticeDetail) els.globalNoticeDetail.setAttribute('aria-hidden', 'true');
            if (els.globalNoticeExpand) {
                els.globalNoticeExpand.setAttribute('aria-expanded', 'false');
                els.globalNoticeExpand.textContent = 'Expandir';
            }
            els.globalNotice.hidden = false;

            if (parseInt(options.duration || '0', 10) > 0) {
                noticeTimer = window.setTimeout(function () {
                    hideGlobalNotice(activeNoticeSource);
                    renderOperationContext();
                }, parseInt(options.duration, 10));
            }
        }

        function renderOperationContext() {
            if (state.estadoOperacion === 'sin_horarios' && !state.scheduleAlertDismissed) {
                showGlobalNotice({
                    source: 'schedule',
                    type: 'warning',
                    title: 'Sin horarios disponibles',
                    summary: 'No quedan horarios operativos disponibles para esta fecha.',
                    message: (state.mensajeOperacion || 'La jornada no tiene más bloques disponibles.') +
                        ' Selecciona otra fecha para continuar.',
                    respectDismissal: true
                });
                return;
            }
            if (state.estadoOperacion === 'cerrado') {
                showGlobalNotice({
                    source: 'context',
                    type: 'warning',
                    title: 'Restaurante cerrado',
                    summary: 'No hay operación programada para la fecha seleccionada.',
                    message: (state.mensajeOperacion || 'El restaurante no recibe reservaciones en esta fecha.') +
                        ' Selecciona una fecha abierta para continuar.',
                    respectDismissal: true
                });
                return;
            }
            if (state.modo === 'solo_lectura') {
                showGlobalNotice({
                    source: 'readonly',
                    type: 'info',
                    title: 'Modo histórico',
                    summary: 'Esta vista es solo de lectura.',
                    message: 'Estás consultando una fecha anterior. La asignación, los comentarios y los cambios de estado están deshabilitados; vuelve a una fecha vigente para operar.',
                    respectDismissal: true
                });
                return;
            }
            if (['context', 'schedule', 'readonly'].indexOf(activeNoticeSource) !== -1) {
                hideGlobalNotice(activeNoticeSource);
            }
        }

        function dismissScheduleAlert() {
            state.scheduleAlertDismissed = true;
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
                connection: 'No se pudo actualizar la información. Revisa tu conexión antes de volver a usar Actualizar mapa.',
                timeout: 'La solicitud excedió el tiempo de espera. Espera un momento antes de volver a usar Actualizar mapa.',
                invalid_json: 'No fue posible interpretar la respuesta del servidor.',
                consistency: 'El servidor respondio con datos de otra fecha. La respuesta fue descartada.',
                server: 'El servidor no pudo completar la consulta. Vuelve a usar Actualizar mapa cuando el servicio esté disponible.'
            };
            var title = titles[kind] || 'No fue posible actualizar la operacion';
            var message = messages[kind] || 'Ocurrio un error inesperado al consultar el servidor.';

            if (state.hasLoadedData && state.fecha) {
                message += ' Los datos anteriores permanecen visibles. Se mantiene la operación del ' + fechaLegible(state.fecha) + '.';
            }

            showGlobalNotice({
                source: 'technical',
                type: 'error',
                title: title,
                summary: 'No se pudieron actualizar los datos del mapa.',
                message: message + ' Puedes cerrar este aviso y volver a usar Actualizar mapa cuando la conexión esté disponible.'
            });
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

        function canAssignTables(reservacion) {
            return state.editable && reservacion && String(reservacion.estado || '') === 'confirmada' &&
                isEditable(reservacion) && reservacion.ticket_abierto !== true;
        }

        function canClearAssignment(reservacion) {
            return canAssignTables(reservacion)
                && reservacion.origen === 'admin'
                && Array.isArray(reservacion.mesa_ids)
                && reservacion.mesa_ids.length > 0;
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

        function tableStateById(id) {
            id = parseInt(id, 10);
            return state.mesasEstado.find(function (mesa) {
                return parseInt(mesa.id, 10) === id;
            }) || null;
        }

        /**
         * La lista y el detalle siguen el horario activo; el arreglo diario se
         * conserva completo para poder cambiar de horario sin otra solicitud.
         */
        function reservationsForSelectedHour() {
            var selectedHour = horaCorta(state.horaSeleccionada);
            if (!selectedHour) {
                return [];
            }

            return state.reservaciones.filter(function (reservacion) {
                return horaCorta(reservacion.hora) === selectedHour;
            });
        }

        function activeReservationsForSelectedHour() {
            return reservationsForSelectedHour().filter(function (reservacion) {
                return reservacion.influye_disponibilidad === true;
            });
        }

        function reservationsForOperationalList() {
            var reservations = state.reservaciones.filter(function (reservacion) {
                return reservacion.en_lista_operativa === true || reservacion.en_lista_terminal === true;
            });

            if (state.assignmentFilter === 'pending') {
                reservations = reservations.filter(function (reservacion) {
                    return String(reservacion.estado || '') === 'confirmada' &&
                        (!Array.isArray(reservacion.mesa_ids) || reservacion.mesa_ids.length === 0);
                });
            } else if (state.assignmentFilter === 'assigned') {
                reservations = reservations.filter(function (reservacion) {
                    return String(reservacion.estado || '') === 'confirmada' &&
                        Array.isArray(reservacion.mesa_ids) && reservacion.mesa_ids.length > 0;
                });
            } else if (state.assignmentFilter === 'in_course') {
                reservations = reservations.filter(function (reservacion) {
                    return String(reservacion.estado || '') === 'en_curso' || reservacion.ticket_abierto === true;
                });
            }

            var search = String(state.reservationSearch || '').trim().toLowerCase();
            if (search) {
                reservations = reservations.filter(function (reservacion) {
                    return [reservacion.nombre, reservacion.contacto_visible, reservacion.contacto]
                        .join(' ')
                        .toLowerCase()
                        .indexOf(search) !== -1;
                });
            }

            return reservations;
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
            if (els.assignmentRefresh) {
                els.assignmentRefresh.hidden = !state.assignmentDataUpdated;
            }

            var saveButton = els.assignmentBar.querySelector('[data-operation-assignment-save]');
            if (saveButton) {
                var disabled = !canAssignTables(reservacion) || state.mesasSeleccionadas.size === 0;
                saveButton.setAttribute('data-disabled', disabled ? '1' : '0');
                saveButton.disabled = disabled || state.guardando;
                saveButton.textContent = state.mesasSeleccionadas.size > 0 && capacidad < comensales
                    ? 'Guardar de todos modos'
                    : 'Guardar';
            }
            if (els.assignmentClear) {
                var clearable = canClearAssignment(reservacion);
                els.assignmentClear.hidden = !clearable;
                els.assignmentClear.disabled = !clearable || state.guardando;
                els.assignmentClear.setAttribute('data-disabled', clearable ? '0' : '1');
            }
        }

        function enterAssignmentMode(trigger, options) {
            options = options || {};
            var reservacion = selectedReservation();
            if (!reservacion) {
                showGlobalNotice({
                    source: 'assignment',
                    type: 'error',
                    title: 'Reservación no encontrada',
                    summary: 'No fue posible iniciar la reasignación.',
                    message: 'La reservación solicitada no existe o no pertenece a la fecha y horario consultados. Continúa usando el mapa o selecciona otra reservación.'
                });
                return;
            }
            if (!canAssignTables(reservacion)) {
                showGlobalNotice({
                    source: 'assignment',
                    type: 'restricted',
                    title: 'Reservación no editable',
                    summary: 'La reservación seleccionada ya no permite cambiar mesas.',
                    message: 'La reservación pertenece a un estado final o su horario ya pasó. Continúa usando el mapa normalmente o selecciona otra reservación editable.'
                });
                return;
            }
            if (state.guardando || state.assignmentMode) {
                return;
            }

            state.assignmentInitialMesaIds = Array.from(state.mesasSeleccionadas);
            state.assignmentInitialVersion = String(reservacion.version || '');
            state.assignmentDataUpdated = false;
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
            updateUrl();
            showGlobalNotice({
                source: 'assignment',
                type: 'info',
                title: 'Modo de asignación activo',
                summary: 'Selecciona las mesas para esta reservación.',
                message: 'Las mesas ocupadas o bloqueadas no pueden seleccionarse. Revisa la capacidad y confirma la asignación en la barra inferior.'
            });

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
            state.assignmentInitialVersion = '';
            state.assignmentDataUpdated = false;
            state.assignmentTrigger = null;
            hideTableWarning();
            if (activeNoticeSource === 'assignment') {
                hideGlobalNotice('assignment');
                renderOperationContext();
            }
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
            updateUrl();
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

            // El historial puede mostrar horas de reservaciones conservadas.
            // En operación actual/futura sólo se usan los slots que el
            // servidor declaró vigentes; una reservación pasada no los amplía.
            if (state.modo === 'solo_lectura') {
                state.reservaciones.forEach(function (reservacion) {
                    if (reservacion.hora) {
                        hours[horaCorta(reservacion.hora)] = true;
                    }
                });
            }

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
                params.set('reservation_id', String(reservacionId));
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
                params.set('reservation_id', String(state.reservacionSeleccionadaId));
            }
            if (state.assignmentMode) {
                params.set('mode', 'assign');
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
            var historical = state.modo === 'solo_lectura';
            var readonly = historical || state.editable === false;
            state.editable = !readonly;
            root.setAttribute('data-operation-mode', historical ? 'solo_lectura' : 'operacion');
            root.setAttribute('data-operation-editable', readonly ? '0' : '1');
            if (els.title) {
                els.title.textContent = historical
                    ? 'Operacion historica · Solo lectura'
                    : (readonly ? 'Operación sin horarios disponibles' : 'Operacion de reservaciones');
            }
            if (els.description) {
                els.description.textContent = historical
                    ? 'Consulta reservaciones, mesas, estados y comentarios sin permitir modificaciones.'
                    : (readonly
                        ? 'Selecciona una fecha futura para continuar con acciones operativas.'
                        : 'Gestiona el servicio diario, los estados y la asignacion de mesas.');
            }
            if (els.create) {
                els.create.hidden = readonly;
            }
            renderOperationContext();
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
            Array.prototype.forEach.call(root.querySelectorAll('[data-operation-save], [data-operation-action], [data-operation-comment-save], [data-operation-clear]'), function (button) {
                button.disabled = isSaving || button.getAttribute('data-disabled') === '1';
            });
            renderAssignmentBar();
        }

        function showToast(message, type) {
            showGlobalNotice({
                source: 'feedback',
                type: type || 'success',
                title: type === 'error' ? 'No fue posible completar la acción' : 'Cambios guardados',
                summary: message,
                message: type === 'error'
                    ? 'Los datos visibles no cambiaron. Revisa el estado de la reservación antes de continuar.'
                    : 'El servidor confirmó la actualización y el mapa conserva la información más reciente.',
                duration: 3200
            });
        }

        function showCreationFeedback(payload) {
            if (!payload) {
                return;
            }

            var mesaNames = (payload.mesaIds || []).map(function (mesaId) {
                var mesa = tableById(mesaId);
                return mesa ? mesa.nombre : 'Mesa ' + mesaId;
            });
            var assigned = mesaNames.length > 0;
            var withoutContact = payload.withoutContact === true;
            var projectedRelease = payload.dependsOnProjectedRelease === true;
            var title = !assigned && withoutContact
                ? 'Reservación creada sin contacto y sin mesas'
                : (!assigned
                    ? 'Reservación creada; requiere asignación manual'
                    : (withoutContact ? 'Reservación creada sin contacto' : 'Reservación creada'));
            var detail = fechaLegible(payload.fecha) + ' · ' + (payload.hora || '--:--');
            var customer = (payload.nombre || 'Sin nombre') + ' · ' + plural(payload.comensales || 0, 'persona', 'personas');
            var assignment = assigned
                ? mesaNames.join(', ') + (mesaNames.length === 1 ? ' asignada automáticamente' : ' asignadas automáticamente')
                : 'La reservación se guardó correctamente, pero necesita asignación manual.';

            showGlobalNotice({
                source: 'feedback',
                type: assigned && !projectedRelease ? 'success' : 'warning',
                title: title,
                summary: assigned
                    ? (projectedRelease
                        ? 'Las mesas quedaron asignadas con una liberación proyectada.'
                        : 'La reservación se creó y sus mesas quedaron asignadas.')
                    : 'La reservación se creó y requiere asignación manual.',
                message: detail + '. ' + customer + '. ' + assignment +
                    (projectedRelease ? ' Confirma el cierre del ticket antes del bloqueo operativo.' : ''),
                duration: 5200
            });
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

        function hideTableWarning() {
            state.tableWarningMesaId = null;
            if (activeNoticeSource === 'table') {
                hideGlobalNotice('table');
                renderOperationContext();
            }
        }

        /**
         * Presenta la advertencia no bloqueante del contrato normalizado. La
         * mutación posterior seguirá validándose de nuevo en el servidor.
         */
        function showTableWarning(mesaId) {
            var mesaEstado = tableStateById(mesaId);
            var proxima = mesaEstado && mesaEstado.reservacion_proxima;
            var minutos = parseInt((mesaEstado && mesaEstado.minutos_restantes) || '0', 10);

            if (!proxima || proxima.ventana_operativa !== '30_60') {
                hideTableWarning();
                return;
            }

            state.tableWarningMesaId = parseInt(mesaId, 10);
            var relatedTables = Array.isArray(proxima.mesas) && proxima.mesas.length
                ? proxima.mesas.join(', ')
                : (proxima.mesa_ids || []).map(mesaNombre).join(', ');
            showGlobalNotice({
                source: 'table',
                type: 'warning',
                title: 'Reservación próxima',
                summary: 'Esta mesa tiene una reservación en ' + minutos + ' minutos.',
                message: 'Esta mesa tiene una reservación a las ' + horaCorta(proxima.hora) +
                    '. Folio ' + (proxima.folio || ('#' + proxima.id)) +
                    (proxima.nombre ? ' · ' + proxima.nombre : '') +
                    ' · ' + plural(parseInt(proxima.comensales || '0', 10), 'comensal', 'comensales') +
                    (relatedTables ? ' · ' + relatedTables : '')
            });
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
            createForm.removeAttribute('data-contact-warning-accepted');
            setCreateFormValue('nombre', '');
            setCreateFormValue('contacto_tipo', 'email');
            setCreateFormValue('contacto', '');
            setCreateFormValue('comensales', '2');
            setCreateFormValue('nota', '');
            setCreateFormValue('comentario_admin', '');
            setCreateFormValue('request_token', createRequestToken());
            setCreateFormValue('confirmar_sin_contacto', '0');
            setCreateFormValue('permitir_capacidad_insuficiente', '0');

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

            if (!force && createModalDirty) {
                createForm.dispatchEvent(new CustomEvent('reservation:confirmation', {
                    bubbles: true,
                    detail: {
                    type: 'discard',
                    eyebrow: 'Cambios pendientes',
                    title: 'Cambios sin guardar',
                    description: 'Los datos capturados se perderán si cierras el formulario.',
                    backLabel: 'Seguir editando',
                    confirmLabel: 'Cerrar sin guardar',
                    onConfirm: function () {
                        closeCreateModal(true);
                    }
                    }
                }));
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
                        withoutContact: payload.withoutContact === true,
                        requiresManualAssignment: payload.requiresManualAssignment === true,
                        dependsOnProjectedRelease: payload.dependsOnProjectedRelease === true,
                        message: message
                    };

                    closeCreateModal(true);
                    loadDay(fecha, {
                        preserveReservationId: reservationId,
                        preserveHour: hora,
                        enterAssignmentMode: payload.requiresManualAssignment === true
                    });
                })
                .catch(function (error) {
                    var fieldErrors = error && (error.fieldErrors || error.errors) || {};
                    var message = error && (error.message || error.msg) || 'No fue posible crear la reservación.';
                    if (error && error.requiresCapacityConfirmation) {
                        createForm.dispatchEvent(new CustomEvent('reservation:capacity-warning', {
                            bubbles: true,
                            detail: {
                                requested: parseInt(error.requestedCapacity || '0', 10),
                                available: parseInt(error.availableCapacity || '0', 10)
                            }
                        }));
                        return;
                    }
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
            renderCapacityContext();
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

        function renderCapacityContext() {
            if (!els.capacity) return;
            var summary = state.capacidadHorario || {};
            var hasSummary = Object.keys(summary).length > 0;
            els.capacity.hidden = !hasSummary;
            if (!hasSummary) return;

            if (els.capacityTotal) els.capacityTotal.textContent = String(summary.capacidad_total || 0);
            if (els.capacityReal) els.capacityReal.textContent = String(summary.capacidad_realmente_libre || 0);
            if (els.capacityProjected) els.capacityProjected.textContent = String(summary.capacidad_proyectada || 0);
            if (els.capacityEstimated) els.capacityEstimated.textContent = String(summary.capacidad_estimada_horario || 0);
            if (els.capacityWarning) {
                els.capacityWarning.hidden = !(parseInt(summary.capacidad_proyectada || '0', 10) > 0);
            }
        }

        function stopTemporalRefresh() {
            if (temporalIntervalId) {
                window.clearInterval(temporalIntervalId);
                temporalIntervalId = null;
            }
        }

        /**
         * Un único intervalo vuelve a consultar la fuente autoritativa cada
         * minuto configurado. Así también incorpora cierres/aperturas del POS.
         */
        function startTemporalRefresh() {
            stopTemporalRefresh();
            var seconds = parseInt(state.config.temporal.refresco_estados_segundos || '0', 10);
            if (seconds < 1) {
                return;
            }

            temporalIntervalId = window.setInterval(function () {
                if (document.hidden || state.cargando || state.guardando) {
                    return;
                }
                refreshDay({ preserveReservationId: state.reservacionSeleccionadaId });
            }, seconds * 1000);
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
            var reservations = reservationsForOperationalList();
            var total = reservations.length;

            if (els.count) {
                els.count.textContent = String(total);
            }
            if (els.dateLabel) {
                els.dateLabel.textContent = state.fecha || 'Sin fecha';
            }
            if (els.hourLabel) {
                els.hourLabel.textContent = reservations.length
                    ? 'Pendientes hasta cierre'
                    : (state.horaSeleccionada || 'Sin horario');
            }

            if (!els.reservations) {
                return;
            }

            if (!total) {
                els.reservations.innerHTML =
                    '<div class="mapa-empty-state">' +
                        '<span class="mapa-empty-icon">o</span>' +
                        '<span>No hay reservaciones pendientes de operación.</span>' +
                    '</div>';
                return;
            }

            els.reservations.innerHTML = reservations.map(renderReservationCard).join('');
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
                mostrarContextoAdmin: true,
                contacto: reservacion.contacto_visible || 'Sin contacto',
                origen: reservacion.origen_visible || '',
                nota: reservacion.nota_breve || '',
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
            var context = els.selectionCopy && els.selectionCopy.closest('.operational-selection-context');
            if (!els.selectionCopy) {
                return;
            }
            if (!reservacion) {
                if (context) {
                    context.classList.remove('has-selection');
                }
                els.selectionCopy.textContent = 'Ninguna reservación seleccionada';
                return;
            }
            var mesas = Array.isArray(reservacion.mesas_asignadas) && reservacion.mesas_asignadas.length
                ? reservacion.mesas_asignadas.join(', ')
                : 'Sin mesas asignadas';
            els.selectionCopy.textContent = '#' + reservacion.id + ' \u00b7 ' + nombreAbreviado(reservacion.nombre) +
                ' \u00b7 ' + (horaCorta(reservacion.hora) || 'Sin horario') +
                ' \u00b7 ' + plural(parseInt(reservacion.comensales || '0', 10), 'persona', 'personas') +
                ' \u00b7 ' + mesas;
            if (context) {
                context.classList.add('has-selection');
            }
            return;
            els.selectionCopy.textContent = '#' + reservacion.id + ' · ' + nombreAbreviado(reservacion.nombre) + ' · ' + mesas;
        }

        function renderRecommendedAction(reservacion, mesaIds, editable) {
            if (!mesaIds.length) {
                return { key: 'reassign', html: renderActionButton('reassign', 'Asignar mesas', 'admin-btn admin-btn--primary', !canAssignTables(reservacion)) };
            }
            if (String(reservacion.estado) === 'pendiente_verificacion' && editable) {
                return { key: 'verify', html: renderActionButton('verify', 'Confirmar verificación', 'admin-btn admin-btn--primary', false) };
            }
            if (reservacion.puede_iniciar_servicio === true) {
                return { key: 'start-service', html: renderActionButton('start-service', 'Iniciar servicio', 'admin-btn admin-btn--primary', false) };
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
                renderPanelState(
                    state.loadFailure && !state.hasLoadedData
                        ? 'error'
                        : (reservationsForSelectedHour().length ? 'selection' : 'empty')
                );
                return;
            }

            var editable = isEditable(reservacion);
            var assignable = canAssignTables(reservacion);
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
                    (reservacion.conflicto_proximo && reservacion.alerta_operativa
                        ? '<div class="reservation-operation-inline reservation-operation-inline--warning">' +
                            '<strong>Ticket abierto dentro del bloqueo.</strong> ' +
                            esc(reservacion.alerta_operativa.mensaje || 'La liberación proyectada no ocurrió.') +
                            '<div class="reservation-operation-actions">' +
                                '<a class="admin-btn admin-btn--secondary" href="/punto-de-venta">Consultar ticket #' + esc(reservacion.alerta_operativa.ticket_id || '') + '</a>' +
                                (assignable ? '<button class="admin-btn admin-btn--secondary" type="button" data-operation-assignment-start>Reasignar mesas</button>' : '') +
                            '</div>' +
                        '</div>'
                        : '') +
                    '<section class="reservation-operation-panel__section reservation-operation-panel__section--actions reservation-operation__quick-actions">' +
                        (function () {
                            var recommended = renderRecommendedAction(reservacion, mesaIds, editable);
                            var other = '';
                            if (recommended.key !== 'reassign') other += renderActionButton('reassign', 'Cambiar mesas', 'admin-btn admin-btn--secondary', !assignable);
                            if (recommended.key !== 'start-service' && reservacion.puede_iniciar_servicio === true) {
                                other += renderActionButton('start-service', 'Iniciar servicio', 'admin-btn admin-btn--secondary', !mesaIds.length);
                            }
                            if (reservacion.puede_registrar_ausencia === true) {
                                other += renderActionButton('no-show', 'Registrar que el cliente no se presentó', 'admin-btn admin-btn--ghost', false);
                            }
                            if (reservacion.ticket_abierto && reservacion.ticket_id) {
                                other += '<a class="admin-btn admin-btn--secondary" href="/punto-de-venta">Consultar ticket #' + esc(reservacion.ticket_id) + '</a>';
                            }
                            return '<div class="reservation-operation-action-group reservation-operation-action-group--recommended"><h4>Siguiente acción</h4>' + (recommended.html || '<p class="reservation-operation-panel__muted">No hay una acción recomendada para este estado.</p>') + '</div>' +
                                '<div class="reservation-operation-action-group"><h4>Otras acciones</h4><div class="reservation-operation-actions">' + (other || '<p class="reservation-operation-panel__muted">No hay otras acciones disponibles.</p>') + '</div></div>' +
                                '<div class="reservation-operation-action-group reservation-operation-action-group--danger"><h4>Cancelar reservación</h4>' + renderActionButton('cancel', 'Cancelar reservación', 'admin-btn admin-btn--danger', !(estado === 'confirmada' && !reservacion.ticket_abierto)) + '</div>';
                        })() +
                    '</section>' +
                    '<section class="reservation-operation-panel__section reservation-operation-panel__section--assignment reservation-operation__assignment">' +
                        '<h4>Mesas asignadas</h4>' +
                        (!assignable ? '<p class="reservation-operation-inline reservation-operation-inline--muted">Este estado no permite cambiar mesas.</p>' : '') +
                        (assignable ? '<button class="admin-btn admin-btn--secondary reservation-operation-panel__assignment-start" type="button" data-operation-assignment-start aria-controls="operation-assignment-bar" aria-expanded="' + (state.assignmentMode ? 'true' : 'false') + '">Cambiar mesas</button>' : '') +
                        (canClearAssignment(reservacion) ? '<button class="admin-btn admin-btn--danger" type="button" data-operation-clear>Dejar pendiente de asignacion</button>' : '') +
                        '<div class="reservation-operation-summary">' +
                            '<div><span>Reservación</span><strong>' + comensales + ' personas</strong></div>' +
                            '<div><span>Capacidad total</span><strong class="' + (insufficient ? 'is-insufficient' : '') + '">' + capacidad + '</strong></div>' +
                            '<div><span>Diferencia</span><strong class="' + (diferencia < 0 ? 'is-insufficient' : '') + '">' + (diferencia > 0 ? '+' : '') + diferencia + '</strong></div>' +
                        '</div>' +
                        '<div class="reservation-operation-panel__selected ' + (!selectedNames.length ? 'is-empty' : '') + '"><span>Mesas asignadas</span><strong>' + esc(selectedNames.length ? selectedNames.join(', ') : 'Sin mesas asignadas') + '</strong></div>' +
                        (insufficient ? '<p class="reservation-operation-inline reservation-operation-inline--warning">La capacidad seleccionada es menor que los comensales. Puedes guardar explícitamente esta asignación.</p>' : '') +
                        '<button class="admin-btn admin-btn--primary reservation-operation-panel__submit" type="button" data-operation-save data-disabled="' + (!assignable || state.mesasSeleccionadas.size === 0 ? '1' : '0') + '"' + (!assignable || state.mesasSeleccionadas.size === 0 || state.guardando ? ' disabled' : '') + '>' +
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

            if (!els.map || !window.MesaEstadoAdapter) {
                return;
            }

            var editable = reservacion ? canAssignTables(reservacion) : false;
            var ocupacion = reservacion
                ? (state.ocupacionPorReservacion[String(reservacion.id)] || state.ocupacionPorReservacion[reservacion.id] || {})
                : {};
            var asignacionesHorario = {};
            activeReservationsForSelectedHour().forEach(function (reservationAtHour) {
                (reservationAtHour.mesa_ids || []).forEach(function (mesaId) {
                    mesaId = parseInt(mesaId, 10);
                    if (mesaId > 0) {
                        asignacionesHorario[mesaId] = reservationAtHour;
                    }
                });
            });

            var mesasVisuales = state.mesasEstado.map(function (mesaEstado) {
                var mesaId = parseInt(mesaEstado.id, 10);
                var assigned = state.mesasSeleccionadas.has(mesaId);
                var conflict = ocupacion[String(mesaId)] || ocupacion[mesaId] || null;
                var assignedReservation = asignacionesHorario[mesaId] || null;
                var normalized = window.MesaEstadoAdapter.fusionar(mesaEstado, {});
                var modifiers = [];

                if (assignedReservation) {
                    modifiers.push('asignada');
                }
                if (assigned) {
                    modifiers.push('seleccion_actual');
                }

                var associatedId = normalized.reservacion_asociada
                    ? parseInt(normalized.reservacion_asociada.id || '0', 10)
                    : 0;
                var blockedBySelf = Boolean(
                    reservacion &&
                    normalized.estado_base === 'bloqueada' &&
                    associatedId === parseInt(reservacion.id, 10)
                );
                if (blockedBySelf) {
                    // Conserva el bloqueo visual, pero no lo interpreta como
                    // conflicto durante la reasignación de la misma reserva.
                    modifiers.push('bloqueo_propio');
                }

                if (conflict && normalized.estado_base !== 'no_reservable') {
                    if (conflict.tipo === 'ticket_abierto' || conflict.tipo === 'conflicto_proximo') {
                        normalized.estado_base = 'ocupada';
                        modifiers.push('ticket_abierto');
                        if (conflict.tipo === 'conflicto_proximo') {
                            modifiers.push('conflicto_proximo');
                        }
                        if (conflict.walk_in) {
                            modifiers.push('walk_in');
                        }
                    } else if (normalized.estado_base !== 'ocupada') {
                        normalized.estado_base = 'bloqueada';
                    }
                }

                var ticketConflict = Boolean(
                    conflict &&
                    (conflict.tipo === 'ticket_abierto' || conflict.tipo === 'conflicto_proximo')
                );
                var selectable = Boolean(reservacion) &&
                    editable &&
                    normalized.reservable === true &&
                    (!conflict || ticketConflict) &&
                    (normalized.estado_base !== 'ocupada' || ticketConflict) &&
                    (normalized.estado_base !== 'bloqueada' || blockedBySelf);
                var title = normalized.titulo;
                if (assignedReservation) {
                    title += ' Asignada a reservaci\u00f3n #' + assignedReservation.id +
                        ' a las ' + horaCorta(assignedReservation.hora) + '.';
                }
                if (conflict) {
                    title = ticketConflict
                        ? normalized.nombre + (
                            conflict.tipo === 'conflicto_proximo'
                                ? '. Conflicto próximo: el ticket continúa abierto dentro del bloqueo.'
                                : '. Ocupada por servicio activo.'
                        )
                        : normalized.nombre + '. Bloqueada por otra reservación a las ' + horaCorta(conflict.hora) + '.';
                }

                return window.MesaEstadoAdapter.paraMapaVisual(normalized, {
                    seleccionActual: assigned,
                    seleccionValida: true,
                    interactivo: selectable && !state.guardando,
                    titulo: title,
                    modificadores: modifiers,
                    clasesEstado: assigned && !ticketConflict ? ['reservation-operation-pin--selected'] : [],
                    atributos: {
                        'data-operation-table': mesaId,
                        'data-disabled': !selectable || state.guardando ? '1' : '0'
                    }
                });
            });

            mapVisual.render({
                mesas: mesasVisuales,
                elementos: []
            });
        }

        function selectReservation(id) {
            var reservacion = findReservationById(id);

            exitAssignmentMode({ render: false, restoreFocus: false });
            hideTableWarning();

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
            var nextHour = horaCorta(hora);
            if (state.modo !== 'solo_lectura' && sortedHours().indexOf(nextHour) === -1) {
                showGlobalNotice({
                    source: 'expired-hour',
                    type: 'restricted',
                    title: 'Horario no disponible',
                    summary: 'El horario ya no forma parte de la operación vigente.',
                    message: 'El servidor excluyó ese bloque porque ya pasó o no pertenece al horario configurado. Selecciona uno de los horarios disponibles.'
                });
                return;
            }
            exitAssignmentMode({ render: false, restoreFocus: false });
            hideTableWarning();
            dismissScheduleAlert();
            state.horaSeleccionada = nextHour;

            state.reservacionSeleccionadaId = null;
            state.mesasSeleccionadas = new Set();
            renderAll();
        }

        function loadDay(fecha, options) {
            options = options || {};
            fecha = String(fecha || '').trim();

            var preserveReservationId = parseInt(options.preserveReservationId || state.reservacionSeleccionadaId || '0', 10) || null;
            var preserveAssignment = state.assignmentMode
                && options.discardAssignment !== true
                && preserveReservationId !== null
                && preserveReservationId === state.reservacionSeleccionadaId;
            var localSelectionBeforeRefresh = preserveAssignment
                ? Array.from(state.mesasSeleccionadas)
                : [];

            if (!preserveAssignment) {
                exitAssignmentMode({ render: false, restoreFocus: false });
            }
            hideTableWarning();
            dismissScheduleAlert();

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
            var queryRequestedHour = horaCorta(options.requestedHour || requestedHour);
            if (!options.preserveDateWarning) {
                clearDateWarning();
            }
            setLoading(true);
            if (!state.reservaciones.length && !state.mesas.length) {
                renderLoadingShell();
            }
            if (activeNoticeSource === 'technical') {
                hideGlobalNotice('technical');
            }

            state.timeoutId = window.setTimeout(function () {
                state.timedOutSequence = requestSequence;
                if (state.abortController) {
                    state.abortController.abort();
                }
            }, 12000);

            var operationQuery = new URLSearchParams({ fecha: fecha });
            if (queryRequestedHour) {
                operationQuery.set('hora', queryRequestedHour);
            }

            fetch(API_BASE + '?' + operationQuery.toString(), {
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
                    state.mesasEstado = data.mesas_estado || [];
                    state.ocupacionFisica = data.ocupacion_fisica || [];
                    state.ocupacionPorReservacion = data.ocupacion_por_reservacion || {};
                    state.capacidadHorario = data.capacidad_horario || {};
                    state.alertasOperativas = data.alertas_operativas || [];
                    state.estadoOperacion = data.estado_operacion || 'disponible';
                    state.mensajeOperacion = data.mensaje || '';
                    state.scheduleAlertDismissed = false;
                    delete dismissedNoticeSources.schedule;
                    delete dismissedNoticeSources.context;
                    state.hasLoadedData = true;
                    state.config.estadoLabels = (data.config && data.config.estado_labels) || {};
                    state.config.estadosEditables = (data.config && data.config.estados_editables) || [];
                    state.config.transiciones = (data.config && data.config.transiciones) || {};
                    state.config.comentarioAdminDisponible = Boolean(data.config && data.config.comentario_admin_disponible);
                    state.config.temporal = (data.config && data.config.temporal) || {};

                    var selected = preserveReservationId
                        ? findReservationById(preserveReservationId)
                        : null;
                    if (
                        selected
                        && state.modo !== 'solo_lectura'
                        && state.horarios.map(horaCorta).indexOf(horaCorta(selected.hora)) === -1
                    ) {
                        selected = null;
                    }
                    if (selected) {
                        state.reservacionSeleccionadaId = parseInt(selected.id, 10);
                        state.horaSeleccionada = horaCorta(selected.hora);
                        state.mesasSeleccionadas = preserveAssignment
                            ? new Set(localSelectionBeforeRefresh)
                            : new Set((selected.mesa_ids || []).map(function (mesaId) {
                                return parseInt(mesaId, 10);
                            }));
                        if (preserveAssignment) {
                            state.assignmentDataUpdated = true;
                        }
                    } else {
                        if (preserveAssignment) {
                            exitAssignmentMode({ render: false, restoreFocus: false, restoreSelection: false });
                        }
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
                    if (data.hora_solicitada_vencida === true) {
                        var noFutureHours = data.sin_horarios_futuros === true;
                        showGlobalNotice({
                            source: 'expired-hour',
                            type: 'warning',
                            title: noFutureHours ? 'No hay horarios disponibles' : 'Horario no disponible',
                            summary: noFutureHours
                                ? 'El horario solicitado ya pasó y hoy no quedan más bloques.'
                                : 'El horario solicitado ya pasó para el día actual.',
                            message: noFutureHours
                                ? 'No puede abrirse como operación editable. Selecciona una fecha futura para continuar.'
                                : 'No puede abrirse en modo operativo. Se cargó el siguiente horario disponible; usa la vista histórica de solo lectura cuando necesites consultar fechas anteriores.'
                        });
                    }
                    if (options.enterAssignmentMode || state.pendingInitialAssignment) {
                        state.pendingInitialAssignment = false;
                        enterAssignmentMode(null, { focus: true });
                    }
                    startTemporalRefresh();
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
                preserveHour: state.horaSeleccionada,
                discardAssignment: options.discardAssignment === true
            });
        }

        function postJson(url, data) {
            if (csrfToken && data && typeof data.set === 'function') {
                data.set('admin_csrf', csrfToken);
            }
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
            if (error && error.codigo === 'CSRF_INVALIDO') {
                return 'La sesión de seguridad expiró. Recarga la página e intenta nuevamente.';
            }
            if (error && error.codigo === 'SESION_EXPIRADA') {
                return 'Tu sesión expiró. Inicia sesión nuevamente para continuar.';
            }
            if (error && error.codigo === 'MESA_OCUPADA') {
                return 'Las mesas seleccionadas ya no están disponibles. Actualiza el mapa y elige otras.';
            }
            if (error && error.codigo === 'TICKET_ABIERTO') {
                return 'La reservación ya tiene un ticket abierto. Actualiza la consulta para continuar.';
            }
            if (error && error.codigo === 'CONFLICTO_CONCURRENTE') {
                return 'Otra operación cambió esta reservación. Actualiza la consulta e intenta nuevamente.';
            }
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

        function saveTableAssignment(conflictConfirmation) {
            var reservacion = selectedReservation();

            if (!reservacion || !canAssignTables(reservacion) || state.guardando) {
                return;
            }

            hideInlineError();
            var data = new URLSearchParams();

            data.set('reservation_id', String(reservacion.id));
            data.set('fecha', String(reservacion.fecha || state.fecha || ''));
            data.set('hora', horaCorta(reservacion.hora || state.horaSeleccionada || ''));
            data.set('version_esperada', String(reservacion.version || ''));
            data.set('mesa_ids_actuales_presentes', '1');
            (reservacion.mesa_ids || []).forEach(function (mesaId) {
                data.append('mesa_ids_actuales[]', String(mesaId));
            });
            state.mesasSeleccionadas.forEach(function (mesaId) {
                data.append('mesa_ids[]', String(mesaId));
            });
            if (conflictConfirmation) {
                (conflictConfirmation.ticketIds || []).forEach(function (ticketId) {
                    data.append('ticket_ids_aceptados[]', String(ticketId));
                });
                data.set('conflicto_token', String(conflictConfirmation.token || ''));
                (conflictConfirmation.confirmaciones || []).forEach(function (codigo) {
                    data.append('confirmaciones[]', String(codigo));
                });
            }

            setSaving(true);
            postJson(API_BASE + '/assign-tables', data)
                .then(function (payload) {
                    closeTicketConflictModal();
                    exitAssignmentMode({ restoreFocus: false });
                    if (payload.depende_liberacion_proyectada || (payload.advertencias || []).length) {
                        showGlobalNotice({
                            source: 'projection',
                            type: 'warning',
                            title: 'Asignación con liberación proyectada',
                            summary: payload.advertencia || 'Una mesa sigue ocupada físicamente.',
                            message: 'Confirma que el ticket cierre antes del bloqueo o reasigna la reservación.',
                            dismissible: true
                        });
                    } else {
                        showToast('Asignacion guardada.', 'success');
                    }
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && ['CONFLICTO_TICKETS_ABIERTOS', 'CONFLICTO_TICKET_ABIERTO', 'CAPACIDAD_INSUFICIENTE', 'DEPENDE_LIBERACION_PROYECTADA'].indexOf(error.codigo) !== -1) {
                        openTicketConflictModal(error);
                        return;
                    }
                    if (error && error.codigo === 'CONFLICTO_CONCURRENTE') {
                        closeTicketConflictModal();
                        showInlineError('La ocupación cambió mientras confirmabas. Se actualizará el mapa para que revises nuevamente.');
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
                    if (error && error.codigo === 'VERSION_DESACTUALIZADA') {
                        closeTicketConflictModal();
                        showInlineError('La reservación cambió desde que abriste el mapa. Se actualizarán fecha, hora, versión y mesas antes de continuar.');
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
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

        function clearTableAssignment() {
            var reservacion = selectedReservation();
            if (!reservacion || !canClearAssignment(reservacion) || state.guardando) {
                return;
            }

            var data = new URLSearchParams();
            data.set('reservation_id', String(reservacion.id));
            data.set('fecha', String(reservacion.fecha || state.fecha || ''));
            data.set('hora', horaCorta(reservacion.hora || state.horaSeleccionada || ''));
            data.set('version_esperada', String(reservacion.version || ''));
            data.set('mesa_ids_actuales_presentes', '1');
            (reservacion.mesa_ids || []).forEach(function (mesaId) {
                data.append('mesa_ids_actuales[]', String(mesaId));
            });
            data.append('confirmaciones[]', 'LIBERAR_ASIGNACION_ACTUAL');

            setSaving(true);
            postJson(API_BASE + '/clear-tables', data)
                .then(function () {
                    showToast('Asignacion liberada; la reservacion requiere mesas.', 'warning');
                    exitAssignmentMode({ restoreFocus: false });
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && ['VERSION_DESACTUALIZADA', 'CONFLICTO_CONCURRENTE'].indexOf(error.codigo) !== -1) {
                        showInlineError('La reservacion cambio mientras confirmabas. Se actualizaran los datos antes de continuar.');
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
                    showInlineError(operationErrorMessage(error, 'No fue posible liberar la asignacion. Intentalo nuevamente.'));
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function openTicketConflictModal(payload) {
            if (!ticketConflictModal || !ticketConflictList) {
                showInlineError('La selección incluye tickets abiertos y requiere confirmación explícita.');
                return;
            }

            var conflictos = Array.isArray(payload.conflictos_ticket)
                ? payload.conflictos_ticket
                : [];
            var confirmaciones = Array.isArray(payload.confirmaciones_requeridas)
                ? payload.confirmaciones_requeridas.slice()
                : [];
            if (conflictos.length && confirmaciones.indexOf('CONFLICTO_TICKET_ABIERTO') === -1) {
                confirmaciones.push('CONFLICTO_TICKET_ABIERTO');
            }
            state.pendingAssignmentConflict = {
                token: payload.conflicto_token || '',
                ticketIds: conflictos.map(function (conflicto) {
                    return parseInt(conflicto.ticket_id, 10);
                }).filter(Boolean),
                confirmaciones: confirmaciones
            };
            var warningLabels = {
                CAPACIDAD_INSUFICIENTE: 'La capacidad seleccionada es insuficiente.',
                DEPENDE_LIBERACION_PROYECTADA: 'La seleccion depende de la liberacion proyectada de un servicio activo.',
                CONFLICTO_TICKET_ABIERTO: 'La seleccion coincide con un ticket abierto; el ticket no se modificara.',
                SIN_CONTACTO: 'La reservacion no tiene contacto registrado.'
            };
            var warningMarkup = confirmaciones.map(function (codigo) {
                return '<p class="reservation-operation-inline reservation-operation-inline--warning"><strong>Confirmacion requerida:</strong> ' +
                    esc(warningLabels[codigo] || codigo) + '</p>';
            }).join('');
            ticketConflictList.innerHTML = warningMarkup + conflictos.map(function (conflicto) {
                var conflictTables = (conflicto.mesas_conflicto || []).map(mesaNombre).join(', ');
                var allTables = (conflicto.mesa_ids || []).map(mesaNombre).join(', ');
                var origin = conflicto.origen === 'reservacion'
                    ? 'Reservación #' + esc(conflicto.reservacion_id || '')
                    : 'Walk-in';
                return '<article class="admin-card reservation-operation-conflict">' +
                    '<h3>Ticket #' + esc(conflicto.ticket_id) + '</h3>' +
                    '<p><strong>Mesas seleccionadas:</strong> ' + esc(conflictTables || 'Sin identificar') + '</p>' +
                    '<p><strong>Todas sus mesas:</strong> ' + esc(allTables || 'Sin identificar') + '</p>' +
                    '<p><strong>Apertura:</strong> ' + esc(conflicto.hora_apertura || 'Sin dato') + '</p>' +
                    '<p><strong>Origen:</strong> ' + origin + '</p>' +
                '</article>';
            }).join('');
            if (ticketConflictError) {
                ticketConflictError.hidden = true;
                ticketConflictError.textContent = '';
            }
            ticketConflictLastFocus = document.activeElement;
            ticketConflictModal.showModal();
            window.requestAnimationFrame(function () {
                var first = ticketConflictModal.querySelector('[data-operation-ticket-conflict-confirm], [data-operation-ticket-conflict-close], button:not([disabled])');
                if (first) first.focus();
            });
        }

        function closeTicketConflictModal() {
            state.pendingAssignmentConflict = null;
            if (ticketConflictModal && ticketConflictModal.open) {
                ticketConflictModal.close();
            }
            if (ticketConflictLastFocus && document.contains(ticketConflictLastFocus)) {
                ticketConflictLastFocus.focus();
            }
            ticketConflictLastFocus = null;
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

            if (action === 'cancel' || action === 'no-show') {
                openActionModal(action, reservacion);
                return;
            }

            executeReservationAction(action, '');
        }

        function actionAllowed(reservacion, action) {
            var estado = String(reservacion.estado || '');
            if (action === 'verify') {
                return false;
            }
            if (action === 'start-service') {
                return reservacion.puede_iniciar_servicio === true;
            }
            if (action === 'no-show') {
                return reservacion.puede_registrar_ausencia === true;
            }
            if (action === 'cancel') {
                return (estado === 'confirmada' || estado === 'pendiente_verificacion')
                    && !reservacion.ticket_abierto;
            }
            return false;
        }

        function executeReservationAction(action, motivo) {
            var reservacion = selectedReservation();
            if (!reservacion || !actionAllowed(reservacion, action)) {
                showInlineError('La acción solicitada ya no está disponible. Actualiza los datos e intenta nuevamente.');
                return;
            }

            var estados = {
                verify: 'confirmada',
                'start-service': 'en_curso',
                cancel: 'cancelada',
                'no-show': 'no_show'
            };
            var labels = {
                verify: 'Verificación confirmada.',
                'start-service': 'Servicio iniciado.',
                cancel: 'Reservación cancelada.',
                'no-show': 'Reservación marcada como no show.'
            };
            var estado = estados[action] || '';
            var data = new URLSearchParams();
            data.set('reservacion_id', String(reservacion.id));
            data.set('estado', estado);
            data.set('motivo', String(motivo || ''));

            setSaving(true);
            postJson(API_BASE + '/status', data)
                .then(function (payload) {
                    showToast(labels[action] || 'Cambios guardados.', 'success');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && error.requiere_reasignacion) {
                        showGlobalNotice({
                            source: 'service-start-conflict',
                            type: 'warning',
                            title: 'El inicio de servicio requiere nuevas mesas',
                            summary: error.mensaje || 'Las mesas originales ya no están disponibles.',
                            message: 'Selecciona una asignación válida en el mapa y vuelve a confirmar la llegada. No se recuperó ninguna mesa automáticamente.'
                        });
                        enterAssignmentMode(null, { focus: true });
                        return;
                    }
                    if (error && error.codigo === 'RESERVACION_NO_EDITABLE') {
                        showInlineError('La reservación ya no permite modificar sus mesas.');
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
                    if (error && error.codigo === 'DATOS_INCOMPLETOS') {
                        showInlineError('Falta contexto de la asignación. Actualiza el mapa antes de volver a guardar.');
                        refreshDay({ preserveReservationId: reservacion.id });
                        return;
                    }
                    showInlineError(operationErrorMessage(error, 'No fue posible guardar los cambios. Intentalo nuevamente.'));
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function openActionModal(action, reservacion) {
            if (!actionModal) {
                showInlineError('No fue posible abrir la confirmación operativa.');
                return;
            }

            state.pendingAction = action;
            var isCancel = action === 'cancel';
            var isClearAssignment = action === 'clear-assignment';
            if (isClearAssignment) {
                actionTitle.textContent = 'Dejar reservacion sin mesas';
                actionDescription.textContent = 'La reservacion administrativa conservara su estado confirmado, pero quedara pendiente de asignacion. Esta accion requiere una confirmacion explicita.';
            }
            actionTitle.textContent = isCancel ? 'Cancelar reservación' : 'Marcar como no show';
            actionDescription.textContent = isCancel
                ? 'La reservación conservará sus relaciones históricas. No puede cancelarse si el servicio ya comenzó.'
                : 'Confirma que la tolerancia venció y que el cliente no llegó. Esta acción no puede revertirse desde el mapa.';
            if (isClearAssignment) {
                actionTitle.textContent = 'Dejar reservacion sin mesas';
                actionDescription.textContent = 'La reservacion administrativa conservara su estado confirmado, pero quedara pendiente de asignacion. Esta accion requiere una confirmacion explicita.';
            }
            actionReasonField.hidden = !isCancel;
            if (!isCancel) {
                actionTitle.textContent = 'Registrar que el cliente no se presentó';
            }
            actionReason.value = '';
            actionError.hidden = true;
            actionError.textContent = '';
            actionModalLastFocus = document.activeElement;
            actionModal.showModal();
            window.requestAnimationFrame(function () {
                var first = isCancel && actionReason
                    ? actionReason
                    : actionModal.querySelector('[data-operation-action-confirm], button:not([disabled])');
                if (first) first.focus();
            });
        }

        function closeActionModal() {
            state.pendingAction = null;
            if (actionModal && actionModal.open) {
                actionModal.close();
            }
            if (actionModalLastFocus && document.contains(actionModalLastFocus)) {
                actionModalLastFocus.focus();
            }
            actionModalLastFocus = null;
        }

        function confirmPendingAction() {
            var action = state.pendingAction;
            if (!action) {
                return;
            }
            var motivo = actionReason ? actionReason.value.trim() : '';
            if (action === 'cancel' && !motivo) {
                actionError.textContent = 'Escribe el motivo de la cancelación.';
                actionError.hidden = false;
                actionReason.focus();
                return;
            }
            if (action === 'clear-assignment') {
                closeActionModal();
                clearTableAssignment();
                return;
            }
            closeActionModal();
            executeReservationAction(action, motivo);
        }

        function showInlineError(message) {
            showGlobalNotice({
                source: 'action',
                type: 'error',
                title: 'Acción no permitida',
                summary: 'No fue posible completar la acción solicitada.',
                message: message + ' Los datos visibles no cambiaron; revisa el estado actual antes de continuar.'
            });
        }

        function hideInlineError() {
            if (activeNoticeSource === 'action') {
                hideGlobalNotice('action');
                renderOperationContext();
            }
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
                invalidateUnavailable: true,
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

        if (els.assignmentFilter) {
            els.assignmentFilter.addEventListener('change', function () {
                state.assignmentFilter = els.assignmentFilter.value || 'all';
                renderReservationList();
            });
        }

        if (els.reservationSearch) {
            els.reservationSearch.addEventListener('input', function () {
                state.reservationSearch = els.reservationSearch.value || '';
                renderReservationList();
            });
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
                setCreateFormValue('permitir_capacidad_insuficiente', '0');
            });
            createForm.addEventListener('change', function () {
                createModalDirty = true;
                setCreateFormValue('permitir_capacidad_insuficiente', '0');
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

        if (els.globalNoticeClose) {
            els.globalNoticeClose.addEventListener('click', function () {
                if (activeNoticeSource) {
                    dismissedNoticeSources[activeNoticeSource] = true;
                    if (activeNoticeSource === 'schedule') {
                        dismissScheduleAlert();
                    }
                }
                hideGlobalNotice();
            });
        }
        if (els.globalNoticeExpand) {
            els.globalNoticeExpand.addEventListener('click', function () {
                var expanded = !els.globalNotice.classList.contains('is-expanded');
                els.globalNotice.classList.toggle('is-expanded', expanded);
                els.globalNoticeExpand.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                els.globalNoticeExpand.textContent = expanded ? 'Ocultar detalle' : 'Expandir';
                if (els.globalNoticeDetail) {
                    els.globalNoticeDetail.setAttribute('aria-hidden', expanded ? 'false' : 'true');
                }
                if (expanded) {
                    window.clearTimeout(noticeTimer);
                    noticeTimer = null;
                }
            });
        }

        root.addEventListener('keydown', function (event) {
            var activeDialog = ticketConflictModal && ticketConflictModal.open
                ? ticketConflictModal
                : (actionModal && actionModal.open ? actionModal : null);
            if (activeDialog && event.key === 'Tab') {
                var focusable = Array.prototype.slice.call(activeDialog.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'));
                if (focusable.length) {
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                        return;
                    }
                    if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                        return;
                    }
                }
            }

            if (event.key === 'Escape' && ticketConflictModal && ticketConflictModal.open) {
                event.preventDefault();
                closeTicketConflictModal();
                return;
            }

            if (event.key === 'Escape' && actionModal && actionModal.open) {
                event.preventDefault();
                closeActionModal();
                return;
            }

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
                if (state.tableWarningMesaId === mesaId) {
                    hideTableWarning();
                }
            } else {
                state.mesasSeleccionadas.add(mesaId);
                showTableWarning(mesaId);
            }

            renderReservationDetail();
            renderTableMap();
            renderAssignmentBar();
        });

        root.addEventListener('click', function (event) {
            var ticketConflictClose = event.target.closest('[data-operation-ticket-conflict-close]');
            var ticketConflictConfirm = event.target.closest('[data-operation-ticket-conflict-confirm]');
            var actionClose = event.target.closest('[data-operation-action-close]');
            var actionConfirm = event.target.closest('[data-operation-action-confirm]');
            var reservationButton = event.target.closest('[data-operation-reservation]');
            var assignmentStart = event.target.closest('[data-operation-assignment-start]');
            var assignmentCancel = event.target.closest('[data-operation-assignment-cancel]');
            var assignmentClear = event.target.closest('[data-operation-clear]');
            var saveButton = event.target.closest('[data-operation-save]');
            var commentButton = event.target.closest('[data-operation-comment-save]');
            var actionButton = event.target.closest('[data-operation-action]');
            var mobileTab = event.target.closest('[data-operation-mobile-tab]');

            if (ticketConflictClose) {
                closeTicketConflictModal();
                return;
            }

            if (ticketConflictConfirm) {
                if (state.pendingAssignmentConflict) {
                    var confirmation = state.pendingAssignmentConflict;
                    saveTableAssignment(confirmation);
                }
                return;
            }

            if (actionClose) {
                closeActionModal();
                return;
            }

            if (actionConfirm) {
                confirmPendingAction();
                return;
            }

            if (mobileTab) {
                setMobileView(mobileTab.getAttribute('data-operation-mobile-tab'));
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

            if (assignmentClear) {
                var clearReservation = selectedReservation();
                if (clearReservation && canClearAssignment(clearReservation)) {
                    openActionModal('clear-assignment', clearReservation);
                }
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
                    enterAssignmentMode(actionButton);
                } else {
                    changeReservationStatus(action);
                }
            }
        });

        window.addEventListener('pagehide', function () {
            stopTemporalRefresh();
            if (state.timeoutId) {
                window.clearTimeout(state.timeoutId);
                state.timeoutId = null;
            }
            if (state.abortController) {
                state.abortController.abort();
            }
        });

        window.addEventListener('pageshow', function () {
            if (state.hasLoadedData) {
                startTemporalRefresh();
                refreshDay({ preserveReservationId: state.reservacionSeleccionadaId });
            }
        });

        document.addEventListener('visibilitychange', function () {
            if (!document.hidden && state.hasLoadedData && !state.cargando && !state.guardando) {
                refreshDay({ preserveReservationId: state.reservacionSeleccionadaId });
            }
        });

        loadDay(state.fecha, {
            preserveReservationId: state.reservacionSeleccionadaId,
            preserveHour: state.horaSeleccionada,
            requestedHour: state.horaSolicitadaInicial,
            preserveDateWarning: root.getAttribute('data-initial-date-warning') === '1'
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReservationOperation);
    } else {
        initReservationOperation();
    }
})();
