/**
 * Controla la vista operativa de reservaciones:
 * carga el dia, sincroniza seleccion y administra el mapa de mesas.
 */
(function () {
    var API_BASE = '/admin/api/reservaciones/operacion';

    function initReservationOperation() {
        var root = document.querySelector('[data-page="reservation-operation"]');

        if (!root) {
            return;
        }
        var csrfToken = root.getAttribute('data-admin-csrf') || '';

        var state = {
            fecha: root.getAttribute('data-initial-fecha') || '',
            surface: root.getAttribute('data-operation-surface') || 'admin',
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
            tituloOperacion: '',
            consecuenciaOperacion: '',
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
            currentAssignmentIds: new Set(),
            candidateSelectionIds: new Set(),
            assignmentSnapshot: null,
            assignmentMode: false,
            assignmentInitialCandidateIds: [],
            assignmentInitialVersion: '',
            assignmentDataUpdated: false,
            assignmentTrigger: null,
            assignmentCancelLabel: 'Cancelar',
            cargando: false,
            guardando: false,
            abortController: null,
            timeoutId: null,
            timedOutSequence: 0,
            requestSequence: 0,
            projectionContext: { fecha: '', hora: '' },
            pendingProjectionContext: null,
            tableWarningMesaId: null,
            pendingAssignmentConflict: null,
            pendingAction: null,
            commentEditingReservationId: null,
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
            panel: root.querySelector('[data-operation-panel]'),
            panelShell: root.querySelector('[data-operation-panel-shell]'),
            structuredDetails: root.querySelector('[data-map-structured-details]'),
            assignmentBar: root.querySelector('[data-operation-assignment-bar]'),
            assignmentTitle: root.querySelector('[data-operation-assignment-title]'),
            assignmentCancel: root.querySelector('[data-operation-assignment-cancel]'),
            assignmentReservation: root.querySelector('[data-operation-assignment-reservation]'),
            assignmentPeople: root.querySelector('[data-operation-assignment-people]'),
            assignmentCapacity: root.querySelector('[data-operation-assignment-capacity]'),
            assignmentDifference: root.querySelector('[data-operation-assignment-difference]'),
            assignmentTables: root.querySelector('[data-operation-assignment-tables]'),
            assignmentRefresh: root.querySelector('[data-operation-assignment-refresh]'),
            capacity: root.querySelector('[data-operation-capacity]'),
            capacityReal: root.querySelector('[data-operation-capacity-real]'),
            capacityOf: root.querySelector('[data-operation-capacity-of]'),
            capacitySecondary: root.querySelector('[data-operation-capacity-secondary]'),
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

        if (els.structuredDetails) {
            els.structuredDetails.addEventListener('toggle', function () {
                if (!els.structuredDetails.open
                    || !root.classList.contains('has-selected-reservation')
                    || !window.matchMedia
                    || !window.matchMedia('(max-width: 900px)').matches) {
                    return;
                }

                // En una superficie estrecha la lista ocupa el mismo plano que
                // el detalle; mantener una sola capa abierta evita ocultar
                // acciones bajo el overlay de detalle.
                document.dispatchEvent(new CustomEvent('operational:close-panel'));
            });
        }

        var createModal = root.querySelector('[data-operation-create-modal]');
        var createForm = createModal ? createModal.querySelector('[data-admin-reservation-form]') : null;
        var createModalError = createModal ? createModal.querySelector('[data-operation-create-error]') : null;
        var createModalLastFocus = null;
        var createModalDirty = false;
        var createModalSubmitting = false;
        var createDecisionState = {
            activeDecision: null,
            requiredConfirmations: [],
            confirmacionesRequeridas: [],
            pendingConfirmation: null,
            lastResponse: null,
            modalOptions: null,
            isAwaitingDecision: false
        };
        var confirmationHost = root.querySelector('[data-operation-confirmation-host]');
        var confirmationController = confirmationHost && window.ConfirmationModal
            ? window.ConfirmationModal.create(confirmationHost)
            : null;
        var actionReason = null;

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
                    title: state.tituloOperacion || '',
                    summary: state.mensajeOperacion || '',
                    message: state.consecuenciaOperacion || '',
                    respectDismissal: true
                });
                return;
            }
            if (state.estadoOperacion === 'cerrado') {
                showGlobalNotice({
                    source: 'context',
                    type: 'warning',
                    title: state.tituloOperacion || '',
                    summary: state.mensajeOperacion || '',
                    message: state.consecuenciaOperacion || '',
                    respectDismissal: true
                });
                return;
            }
            if (state.modo === 'solo_lectura') {
                showGlobalNotice({
                    source: 'readonly',
                    type: 'info',
                    title: state.tituloOperacion || '',
                    summary: state.mensajeOperacion || '',
                    message: state.consecuenciaOperacion || '',
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

        function showTechnicalError(kind, requestedDate, error) {
            error = error || {};
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
            var title = error.titulo || titles[kind] || 'No fue posible actualizar la operacion';
            var message = error.consecuencia || messages[kind] || 'Ocurrio un error inesperado al consultar el servidor.';
            var summary = error.mensaje || 'No se pudieron actualizar los datos del mapa.';

            if (state.hasLoadedData && state.fecha) {
                message += ' Los datos anteriores permanecen visibles. Se mantiene la operación del ' + fechaLegible(state.fecha) + '.';
            }

            showGlobalNotice({
                source: 'technical',
                type: 'error',
                title: title,
                summary: summary,
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
                && (state.surface === 'waiter' || reservacion.origen === 'admin')
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

        function normalizeMesaIds(ids) {
            return Array.from(new Set((Array.isArray(ids) ? ids : []).map(function (mesaId) {
                return parseInt(mesaId, 10);
            }).filter(function (mesaId) {
                return mesaId > 0;
            })));
        }

        function assignmentIdsFor(reservacion) {
            var snapshot = reservacion && reservacion.assignment_snapshot;
            return normalizeMesaIds(snapshot && Array.isArray(snapshot.mesa_ids)
                ? snapshot.mesa_ids
                : (reservacion && reservacion.mesa_ids));
        }

        function activeSelectionIds() {
            return state.assignmentMode
                ? state.candidateSelectionIds
                : state.currentAssignmentIds;
        }

        function mesaPuedeSerCandidata(mesaId) {
            var mesa = tableStateById(mesaId);
            return Boolean(mesa)
                && mesa.reservable === true
                && mesa.disponible_para_asignacion === true
                && mesa.ticket_abierto !== true;
        }

        function candidateIdsFromCurrent() {
            return new Set(Array.from(state.currentAssignmentIds).filter(mesaPuedeSerCandidata));
        }

        function currentAssignmentConflictIds() {
            return Array.from(state.currentAssignmentIds).filter(function (mesaId) {
                var mesa = tableStateById(mesaId);
                return Boolean(mesa)
                    && mesa.asignada_actualmente === true
                    && (mesa.ticket_abierto === true
                        || mesa.disponible_para_asignacion !== true);
            });
        }

        function assignmentConflictMessage() {
            var conflicts = currentAssignmentConflictIds();
            if (!conflicts.length) {
                return '';
            }

            var names = conflicts.map(mesaNombre).join(', ');
            var ticketConflicts = conflicts.filter(function (mesaId) {
                var mesa = tableStateById(mesaId);
                return mesa && mesa.causa_conflicto_asignacion === 'ticket_abierto';
            });
            if (conflicts.length === 1 && ticketConflicts.length === 0) {
                return 'La asignacion actual tiene un conflicto. ' + names +
                    ' no esta disponible para una nueva asignacion. Selecciona otra mesa para completar la reasignacion.';
            }
            if (conflicts.length === 1) {
                return 'La asignación actual tiene un conflicto. ' + names +
                    ' está ocupada por un ticket abierto. Selecciona una nueva mesa para completar la reasignación.';
            }

            return conflicts.length + ' de las mesas asignadas actualmente tienen un conflicto (' + names +
                '). Selecciona nuevas mesas para completar la reasignación.';
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
                return String(reservacion.estado || '') === 'confirmada'
                    && reservacion.aplica_hora_consultada === true;
            });
        }

        function activeReservationsForSelectedHour() {
            return reservationsForSelectedHour().filter(function (reservacion) {
                return reservacion.influye_disponibilidad === true
                    && reservacion.en_proyeccion_mapa !== false
                    && String(reservacion.estado || '') === 'confirmada';
            });
        }

        function reservationsForOperationalList() {
            var reservations = state.reservaciones.filter(function (reservacion) {
                return String(reservacion.estado || '') === 'confirmada'
                    && (reservacion.en_lista_operativa === true || reservacion.en_lista_terminal === true);
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
            }

            var search = String(state.reservationSearch || '').trim().toLowerCase();
            if (search) {
                reservations = reservations.filter(function (reservacion) {
                    var searchFields = [reservacion.nombre];
                    if (state.surface === 'admin') {
                        searchFields.push(reservacion.contacto_visible, reservacion.contacto);
                    }
                    return searchFields
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
            activeSelectionIds().forEach(function (mesaId) {
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
            els.panelShell.hidden = hidden;
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
            var selectedNames = Array.from(state.candidateSelectionIds).map(mesaNombre);

            els.assignmentReservation.textContent = reservacion.nombre;
            els.assignmentPeople.textContent = String(comensales);
            els.assignmentCapacity.textContent = String(capacidad);
            els.assignmentDifference.textContent = (diferencia > 0 ? '+' : '') + diferencia;
            els.assignmentDifference.classList.toggle('is-insufficient', diferencia < 0);
            els.assignmentTables.textContent = selectedNames.length ? selectedNames.join(', ') : 'Sin mesas seleccionadas';
            if (els.assignmentRefresh) {
                var conflictMessage = assignmentConflictMessage();
                els.assignmentRefresh.textContent = conflictMessage ||
                    'Los datos se actualizaron. Tu selección local se conserva; vuelve a validar antes de guardar.';
                els.assignmentRefresh.hidden = !conflictMessage && !state.assignmentDataUpdated;
            }
            if (els.assignmentCancel) {
                els.assignmentCancel.textContent = state.assignmentCancelLabel;
            }

            var saveButton = els.assignmentBar.querySelector('[data-operation-assignment-save]');
            if (saveButton) {
                var disabled = !canAssignTables(reservacion) || state.candidateSelectionIds.size === 0;
                saveButton.setAttribute('data-disabled', disabled ? '1' : '0');
                saveButton.disabled = disabled || state.guardando;
                saveButton.textContent = 'Guardar asignación';
            }
        }

        function enterAssignmentMode(trigger, options) {
            options = options || {};
            var reservacion = selectedReservation();
            if (!reservacion) {
                showGlobalNotice({
                    source: 'assignment',
                    type: 'error',
                    title: '',
                    summary: '',
                    message: ''
                });
                return;
            }
            if (!canAssignTables(reservacion)) {
                showGlobalNotice({
                    source: 'assignment',
                    type: 'restricted',
                    title: '',
                    summary: '',
                    message: ''
                });
                return;
            }
            if (state.guardando || state.assignmentMode) {
                return;
            }

            state.currentAssignmentIds = new Set(assignmentIdsFor(reservacion));
            state.assignmentSnapshot = {
                mesa_ids: Array.from(state.currentAssignmentIds),
                version: String(
                    reservacion.assignment_snapshot && reservacion.assignment_snapshot.version
                        || reservacion.version
                        || ''
                )
            };
            state.candidateSelectionIds = candidateIdsFromCurrent();
            state.assignmentInitialCandidateIds = Array.from(state.candidateSelectionIds);
            state.assignmentInitialVersion = state.assignmentSnapshot.version;
            state.assignmentDataUpdated = false;
            state.assignmentTrigger = trigger || document.activeElement;
            state.assignmentCancelLabel = options.allowAssignLater ? 'Asignar más tarde' : 'Cancelar';
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
                title: '',
                summary: '',
                message: ''
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
            state.candidateSelectionIds = new Set();
            state.assignmentMode = false;
            state.assignmentInitialCandidateIds = [];
            state.assignmentInitialVersion = '';
            state.assignmentSnapshot = null;
            state.assignmentDataUpdated = false;
            state.assignmentTrigger = null;
            state.assignmentCancelLabel = 'Cancelar';
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

            return '/admin/reservaciones/operacion' + (params.toString() ? '?' + params.toString() : '');
        }

        function buildDetailUrl(reservacion) {
            var id = parseInt((reservacion && reservacion.id) || state.reservacionSeleccionadaId || '0', 10);
            var returnUrl = currentOperationUrl(id);

            return '/admin/reservaciones/detalle?id=' + encodeURIComponent(String(id)) +
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

            window.history.replaceState({}, '', '/admin/reservaciones/operacion' + (params.toString() ? '?' + params.toString() : ''));
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
            renderOperationAvailability();
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
            renderOperationContext();
        }

        function renderOperationAvailability() {
            if (!els.create) {
                return;
            }

            var hasValidDate = fechaValida(state.fecha);
            var hasSchedules = sortedHours().length > 0;
            var readonly = state.modo === 'solo_lectura' || state.editable === false;
            var unavailable = !hasValidDate || !hasSchedules || state.cargando || Boolean(state.loadFailure) || readonly;
            els.create.hidden = unavailable;
            els.create.disabled = unavailable || state.guardando;
            els.create.setAttribute('aria-disabled', unavailable || state.guardando ? 'true' : 'false');
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

        function showToast(message, type, consequence) {
            showGlobalNotice({
                source: 'feedback',
                type: type || 'success',
                title: '',
                summary: message,
                message: consequence || '',
                duration: 3200
            });
        }

        function showCreationFeedback(payload) {
            if (!payload) {
                return;
            }

            showGlobalNotice({
                source: 'feedback',
                type: payload.tipo === 'decision_requerida' ? 'warning' : 'success',
                title: payload.titulo || '',
                summary: payload.mensaje || '',
                message: payload.consecuencia || '',
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
            var mapModifiers = mesaEstado && Array.isArray(mesaEstado.modificadores_visual_mapa)
                ? mesaEstado.modificadores_visual_mapa
                : [];
            if (!proxima || mapModifiers.indexOf('reservacion_advertencia') === -1) {
                hideTableWarning();
                return;
            }

            state.tableWarningMesaId = parseInt(mesaId, 10);
            showGlobalNotice({
                source: 'table',
                type: 'warning',
                title: proxima.presentacion ? proxima.presentacion.titulo : '',
                summary: proxima.presentacion ? proxima.presentacion.mensaje : '',
                message: proxima.presentacion ? proxima.presentacion.consecuencia : ''
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
            // El contacto es opcional en el alta: el estado inicial debe
            // coincidir con la advertencia SIN_CONTACTO que valida el backend.
            setCreateFormValue('contacto_tipo', 'ninguno');
            setCreateFormValue('contacto', '');
            setCreateFormValue('comensales', '2');
            setCreateFormValue('nota', '');
            setCreateFormValue('comentario_admin', '');
            setCreateFormValue('request_token', createRequestToken());
            setCreateFormValue('confirmar_sin_contacto', '0');
            setCreateFormValue('confirmar_sobrecapacidad', '0');

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

            document.dispatchEvent(new CustomEvent('operational:close-drawer'));
            document.dispatchEvent(new CustomEvent('operational:close-panel'));
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

        function clearCreateDecisionState(clearFormConfirmations) {
            createDecisionState.activeDecision = null;
            createDecisionState.requiredConfirmations = [];
            createDecisionState.confirmacionesRequeridas = [];
            createDecisionState.pendingConfirmation = null;
            createDecisionState.lastResponse = null;
            createDecisionState.modalOptions = null;
            createDecisionState.isAwaitingDecision = false;
            if (clearFormConfirmations && createForm) {
                createForm.removeAttribute('data-confirmations-accepted');
                setCreateFormValue('confirmaciones', '');
                setCreateFormValue('confirmar_sobrecapacidad', '0');
            }
        }

        function createDecisionItems(payload) {
            var decisions = payload && payload.decision && typeof payload.decision === 'object'
                ? [payload.decision]
                : (payload && (payload.confirmaciones_requeridas || payload.requiredConfirmations));
            if (!Array.isArray(decisions)) {
                return [];
            }
            return decisions.filter(function (decision) {
                return decision && typeof decision === 'object';
            });
        }

        function showCreateDecision(payload) {
            var decisions = createDecisionItems(payload);
            if (!decisions.length) {
                renderCreateModalErrors({}, payload && payload.mensaje
                    ? payload.mensaje
                    : 'El servidor no devolvió una decisión válida.');
                return false;
            }
            var decision = decisions[0];
            createDecisionState.activeDecision = decision.codigo_canonico || decision.codigo || null;
            createDecisionState.requiredConfirmations = [decision.codigo_canonico || decision.codigo].filter(Boolean);
            createDecisionState.confirmacionesRequeridas = [decision];
            createDecisionState.pendingConfirmation = payload;
            createDecisionState.lastResponse = payload;
            createDecisionState.isAwaitingDecision = true;
            createForm.dispatchEvent(new CustomEvent('reservation:confirmation', {
                bubbles: true,
                detail: { type: 'warnings', decision: decision, decisions: [decision] }
            }));
            return true;
        }

        function commitCreationResult(payload) {
            payload = payload || {};
            var committed = payload.commit === true;
            if (!committed) {
                return false;
            }
            clearCreateDecisionState(true);
            if (payload.commit === true && (payload.ok === false || payload.tipo === 'error')) {
                console.error('Reservaciones admin: respuesta contradictoria, commit confirmado', payload);
            }

            var reservationId = parseInt(payload.reservacion_id || payload.reservationId || payload.id || '0', 10) || null;
            var fecha = payload.fecha || createForm.elements.fecha && createForm.elements.fecha.value || state.fecha;
            var hora = horaCorta(payload.hora || createForm.elements.hora && createForm.elements.hora.value || state.horaSeleccionada);
            state.pendingCreationFeedback = {
                reservationId: reservationId,
                fecha: fecha,
                hora: hora,
                nombre: createForm.elements.nombre ? createForm.elements.nombre.value : '',
                comensales: createForm.elements.comensales ? createForm.elements.comensales.value : '0',
                mesaIds: Array.isArray(payload.mesa_ids) ? payload.mesa_ids : (Array.isArray(payload.mesaIds) ? payload.mesaIds : []),
                withoutContact: payload.withoutContact === true,
                requiresManualAssignment: payload.requiresManualAssignment === true,
                dependsOnProjectedRelease: payload.dependsOnProjectedRelease === true,
                codigo: payload.codigo || '',
                tipo: payload.tipo || 'exito',
                titulo: payload.titulo || '',
                descripcion: payload.descripcion || '',
                consecuencia: payload.consecuencia || '',
                commit: true,
                mensaje: payload.mensaje || ''
            };

            closeCreateModal(true);
            loadDay(fecha, {
                preserveReservationId: reservationId,
                preserveHour: hora,
                enterAssignmentMode: payload.requiresManualAssignment === true,
                assignmentLater: payload.requiresManualAssignment === true
            });
            return true;
        }

        function submitCreateModal() {
            if (!createForm || createModalSubmitting) {
                return;
            }

            clearCreateDecisionState(false);
            createModalSubmitting = true;
            clearCreateModalErrors();
            var formData = new FormData(createForm);
            var automaticAssignment = createForm.querySelector('[name="asignar_automaticamente"][value="1"]');
            formData.set('asignar_automaticamente', automaticAssignment && automaticAssignment.checked ? '1' : '0');
            var acceptedConfirmations = createForm.getAttribute('data-confirmations-accepted') || '';
            if (acceptedConfirmations) {
                formData.set('confirmaciones', acceptedConfirmations);
            }
            var preserveConfirmationsForReset = false;
            postJson(createForm.action, new URLSearchParams(formData))
                .then(function (payload) {
                    createDecisionState.lastResponse = payload;
                    if (payload.commit === true) {
                        commitCreationResult(payload);
                        return;
                    }
                    if (payload.tipo === 'decision_requerida') {
                        preserveConfirmationsForReset = showCreateDecision(payload);
                        return;
                    }
                    renderCreateModalErrors({}, payload.mensaje || 'La reservación no se guardó.');
                })
                .catch(function (error) {
                    if (error && error.commit === true) {
                        commitCreationResult(Object.assign({}, error, { ok: true }));
                        return;
                    }
                    var fieldErrors = error && (error.fieldErrors || error.errors) || {};
                    var message = error && error.mensaje || '';
                    var decisions = error && (error.confirmaciones_requeridas || error.requiredConfirmations);
                    if (error && error.tipo === 'decision_requerida'
                        && Array.isArray(decisions) && decisions.length) {
                        preserveConfirmationsForReset = showCreateDecision(error);
                        return;
                    }
                    if (error && error.requiresCapacityConfirmation) {
                        createForm.dispatchEvent(new CustomEvent('reservation:capacity-warning', {
                            bubbles: true,
                            detail: { decisions: decisions || [] }
                        }));
                        return;
                    }
                    renderCreateModalErrors(fieldErrors, message);
                })
                .finally(function () {
                    createModalSubmitting = false;
                    createForm.dispatchEvent(new CustomEvent('reservation:reset-submit', {
                        bubbles: true,
                        detail: { preserveConfirmations: preserveConfirmationsForReset }
                    }));
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
            renderOperationAvailability();
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

            var committed = parseInt(summary.capacidad_fisica_comprometida || '0', 10);
            var demand = parseInt(summary.demanda_no_asignada || '0', 10);
            var projected = parseInt(summary.capacidad_proyectada || '0', 10);
            var real = parseInt(summary.capacidad_real_disponible || summary.capacidad_estimada_horario || '0', 10);
            if (els.capacityReal) els.capacityReal.textContent = String(real);
            if (els.capacityOf) els.capacityOf.textContent = ' de ' + String(summary.capacidad_fisica_total || summary.capacidad_total || '0');
            if (els.capacitySecondary) {
                var secondary = demand > 0
                    ? demand + ' sin mesa'
                    : (projected > 0 ? '+' + projected + ' proyectados' : (committed > 0 ? committed + ' comprometidos' : ''));
                els.capacitySecondary.textContent = secondary ? '· ' + secondary : '';
                els.capacitySecondary.hidden = !secondary;
            }
            if (els.capacityWarning) {
                els.capacityWarning.hidden = !(projected > 0);
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
                mostrarContextoAdmin: state.surface === 'admin',
                contacto: state.surface === 'admin' ? (reservacion.contacto_visible || 'Sin contacto') : '',
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

            // Sin una selección, el mapa recupera toda su superficie útil.
            // El detalle es un overlay temporal, no un empty state persistente.
            setPanelAccessibility(true);
            root.classList.remove('has-selected-reservation');
            els.panel.innerHTML = '';
        }

        function renderRecommendedAction(reservacion, mesaIds, editable) {
            if (reservacion.puede_registrar_ausencia === true) {
                return { key: 'no-show', html: renderActionButton('no-show', 'Registrar ausencia', 'admin-btn admin-btn--primary', false) };
            }
            if (!mesaIds.length) {
                if (!canAssignTables(reservacion)) {
                    return { key: '', html: '' };
                }
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
            setPanelAccessibility(!reservacion);
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
            var comensales = parseInt(reservacion.comensales || '0', 10);
            var mesasActuales = Array.isArray(reservacion.mesas_asignadas) && reservacion.mesas_asignadas.length
                ? reservacion.mesas_asignadas.join(', ')
                : 'Sin mesas asignadas';
            var detailLink = state.surface === 'admin'
                ? '<a class="admin-btn admin-btn--secondary reservation-operation-panel__edit reservation-operation__secondary-actions" href="' + esc(buildDetailUrl(reservacion)) + '">' + (editable ? 'Editar reservación' : 'Ver detalle') + '</a>'
                : '';
            var canCancelReservation = estado === 'confirmada' && !reservacion.ticket_abierto;
            var clientNote = String(reservacion.nota || '').trim();
            var commentHtml = renderCommentBox(reservacion, editable);

            els.panel.innerHTML =
                '<article class="reservation-operation-panel admin-card">' +
                    '<div class="reservation-operation-panel__head reservation-operation__summary">' +
                        '<div>' +
                            '<h3>' + esc(reservacion.nombre) + '</h3>' +
                        '</div>' +
                        '<span class="reservations-table__status reservations-table__status--' + esc(estado) + '">' + esc(estadoLabel(estado)) + '</span>' +
                    '</div>' +
                    '<dl class="reservation-operation-panel__facts">' +
                        '<div><dt>Hora</dt><dd>' + esc(horaCorta(reservacion.hora)) + '</dd></div>' +
                        '<div><dt>Personas</dt><dd>' + esc(plural(comensales, 'persona', 'personas')) + '</dd></div>' +
                        '<div class="reservation-operation-panel__fact--wide"><dt>Mesas</dt><dd>' + esc(mesasActuales) + '</dd></div>' +
                    '</dl>' +
                    (reservacion.conflicto_proximo && reservacion.alerta_operativa
                        ? '<div class="reservation-operation-inline reservation-operation-inline--warning">' +
                            '<strong>Ticket abierto dentro del bloqueo.</strong> ' +
                            esc(reservacion.alerta_operativa.mensaje || 'La liberación proyectada no ocurrió.') +
                            '<div class="reservation-operation-actions">' +
                                 '<a class="admin-btn admin-btn--secondary" href="/punto-de-venta">Ver ticket</a>' +
                            '</div>' +
                        '</div>'
                        : '') +
                    (function () {
                        var recommended = renderRecommendedAction(reservacion, mesaIds, editable);
                        var other = '';
                        if (recommended.key !== 'start-service' && reservacion.puede_iniciar_servicio === true && mesaIds.length) {
                            other += renderActionButton('start-service', 'Iniciar servicio', 'admin-btn admin-btn--secondary', false);
                        }
                        if (recommended.key !== 'no-show' && reservacion.puede_registrar_ausencia === true) {
                            other += renderActionButton('no-show', 'Registrar ausencia', 'admin-btn admin-btn--ghost', false);
                        }
                        if (reservacion.ticket_abierto && reservacion.ticket_id) {
                            other += '<a class="admin-btn admin-btn--secondary" href="/punto-de-venta">Ver ticket</a>';
                        }
                        if (!recommended.html && !other) {
                            return '';
                        }
                        return '<section class="reservation-operation-panel__section reservation-operation-panel__section--actions reservation-operation__quick-actions">' +
                            (recommended.html ? '<div class="reservation-operation-action-group reservation-operation-action-group--recommended"><h4>Siguiente acción</h4>' + recommended.html + '</div>' : '') +
                            (other ? '<div class="reservation-operation-action-group"><h4>Acciones disponibles</h4><div class="reservation-operation-actions">' + other + '</div></div>' : '') +
                        '</section>';
                    })() +
                    '<section class="reservation-operation-panel__section reservation-operation-panel__section--assignment reservation-operation__assignment">' +
                        '<h4>Mesas</h4>' +
                        '<p class="reservation-operation-panel__selected"><strong>' + esc(mesasActuales) + '</strong></p>' +
                        (assignable && mesaIds.length ? '<button class="admin-btn admin-btn--secondary reservation-operation-panel__assignment-start" type="button" data-operation-assignment-start aria-controls="operation-assignment-bar" aria-expanded="' + (state.assignmentMode ? 'true' : 'false') + '">Cambiar mesas</button>' : '') +
                    '</section>' +
                    '<section class="reservation-operation-panel__section reservation-operation__client-note">' +
                        '<h4>Nota del cliente</h4>' +
                        '<p class="reservation-operation-panel__note ' + (clientNote ? '' : 'is-empty') + '">' + esc(clientNote || 'Sin indicaciones') + '</p>' +
                    '</section>' +
                    (commentHtml ?
                        '<section class="reservation-operation-panel__section reservation-operation__comment">' +
                            '<h4>Comentario interno</h4>' +
                            commentHtml +
                        '</section>' : '') +
                    ((detailLink || (assignable && canClearAssignment(reservacion)) || canCancelReservation) ?
                        '<section class="reservation-operation-panel__section reservation-operation-panel__section--more-actions"><h4>Más acciones</h4><div class="reservation-operation-actions">' +
                            detailLink +
                            (assignable && canClearAssignment(reservacion) ? '<button class="admin-btn admin-btn--ghost" type="button" data-operation-clear>Liberar asignación</button>' : '') +
                            (canCancelReservation ? renderActionButton('cancel', 'Cancelar reservación', 'admin-btn admin-btn--danger', false) : '') +
                        '</div></section>' : '') +
                '</article>';
        }

        function renderCommentBox(reservacion, editable) {
            if (!state.config.comentarioAdminDisponible) {
                return '';
            }

            var editing = state.commentEditingReservationId === parseInt(reservacion.id, 10);
            if (!editing) {
                var comment = String(reservacion.comentario_admin || '').trim();
                if (!comment && !editable) {
                    return '';
                }
                return '<div class="reservation-operation-comment-summary">' +
                    (comment ? '<p class="reservation-operation-panel__note">' + esc(comment) + '</p>' : '') +
                    (editable ? '<button type="button" class="admin-btn admin-btn--ghost" data-operation-comment-edit>' + (comment ? 'Editar' : 'Agregar nota') + '</button>' : '') +
                '</div>';
            }
            return '<div class="reservation-operation-comment">' +
                '<textarea rows="4" placeholder="Notas internas para recepción y piso" data-operation-comment ' + (!editable ? 'readonly' : '') + '>' + esc(reservacion.comentario_admin || '') + '</textarea>' +
                '<div class="reservation-operation-comment__actions">' +
                    '<button type="button" class="admin-btn admin-btn--ghost" data-operation-comment-cancel>Cancelar</button>' +
                    '<button type="button" class="admin-btn admin-btn--secondary" data-operation-comment-save data-disabled="' + (!editable ? '1' : '0') + '"' + (!editable || state.guardando ? ' disabled' : '') + '>Guardar</button>' +
                '</div>' +
            '</div>';
        }

        function renderActionButton(action, label, className, disabled) {
            return '<button type="button" class="' + esc(className) + '" data-operation-action="' + esc(action) + '" data-disabled="' + (disabled ? '1' : '0') + '"' + (disabled || state.guardando ? ' disabled' : '') + '>' + esc(label) + '</button>';
        }

        function projectionContextMatchesSelection() {
            if (!state.hasLoadedData || state.cargando || state.pendingProjectionContext !== null) {
                return false;
            }
            return state.projectionContext.fecha === String(state.fecha || '')
                && state.projectionContext.hora === horaCorta(state.horaSeleccionada);
        }

        function mapProjectionFor(mesaEstado) {
            var estado = String(mesaEstado.estado_visual_mapa || '').trim();
            var modificadores = Array.isArray(mesaEstado.modificadores_visual_mapa)
                ? mesaEstado.modificadores_visual_mapa.slice()
                : null;
            var ariaLabel = String(mesaEstado.aria_label_mapa || '').trim();
            if (['libre', 'ocupada', 'reservacion-proxima', 'no-utilizable'].indexOf(estado) === -1
                || modificadores === null
                || !ariaLabel) {
                console.error('[reservaciones] Violacion contractual de proyeccion del mapa.', mesaEstado);
                return {
                    estado: 'no-utilizable',
                    modificadores: [],
                    ariaLabel: 'Mesa no disponible: proyeccion del mapa incompleta.'
                };
            }
            return {
                estado: estado,
                modificadores: modificadores,
                ariaLabel: ariaLabel
            };
        }

        function renderTableMap() {
            var reservacion = selectedReservation();

            if (!els.map || !window.MesaEstadoAdapter) {
                return;
            }

            if (!projectionContextMatchesSelection()) {
                mapVisual.clear(state.cargando
                    ? 'Cargando la proyeccion del intervalo seleccionado.'
                    : 'La proyeccion del mapa no corresponde al intervalo seleccionado.');
                return;
            }

            if (!state.mesasEstado.length) {
                mapVisual.clear(state.cargando
                    ? 'Cargando el mapa de mesas.'
                    : (state.loadFailure ? 'No fue posible cargar el mapa de mesas.' : 'No hay mesas para esta consulta.'));
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
                var assigned = state.currentAssignmentIds.has(mesaId);
                var candidate = state.candidateSelectionIds.has(mesaId);
                var conflict = ocupacion[String(mesaId)] || ocupacion[mesaId] || null;
                var assignedReservation = asignacionesHorario[mesaId] || null;
                var normalized = window.MesaEstadoAdapter.fusionar(mesaEstado, {});
                var mapProjection = mapProjectionFor(mesaEstado);
                var modifiers = mapProjection.modificadores.slice();

                if (assignedReservation) {
                    modifiers.push('asignada');
                }
                if (assigned) {
                    modifiers.push('asignada_actualmente');
                }
                if (candidate) {
                    modifiers.push('seleccion_actual');
                }

                var associatedId = normalized.reservacion_asociada
                    ? parseInt(normalized.reservacion_asociada.id || '0', 10)
                    : 0;
                var blockedBySelf = Boolean(
                    reservacion &&
                    normalized.estado_base === 'bloqueada' &&
                    associatedId === parseInt(reservacion.id, 10)
                ) || normalized.ticket_abierto === true;
                if (blockedBySelf) {
                    // Conserva el bloqueo visual, pero no lo interpreta como
                    // conflicto durante la reasignación de la misma reserva.
                    modifiers.push('bloqueo_propio');
                }

                var ticketConflict = Boolean(
                    conflict &&
                    (conflict.tipo === 'ticket_abierto' || conflict.tipo === 'conflicto_proximo')
                );
                var selectable = state.assignmentMode && Boolean(reservacion) &&
                    editable &&
                    normalized.reservable === true &&
                    normalized.disponible_para_asignacion === true;
                var selectionVisualValid = candidate && selectable;
                var mapAriaLabel = selectionVisualValid
                    ? 'Selección candidata. ' + mapProjection.ariaLabel
                    : mapProjection.ariaLabel;
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

                if (assigned && !candidate && normalized.causa_conflicto_asignacion) {
                    title += ' Asignada actualmente a esta reservación; debe reemplazarse.';
                }

                var mapRaw = Object.assign({}, normalized, { modificadores: [] });
                return window.MesaEstadoAdapter.paraMapaVisual(mapRaw, {
                    seleccionActual: assigned || candidate,
                    seleccionValida: !(assigned || candidate) || selectionVisualValid,
                    seleccionPrioritaria: selectionVisualValid,
                    interactivo: selectable && !state.guardando,
                    titulo: title,
                    ariaLabel: mapAriaLabel,
                    estadoVisual: selectionVisualValid ? 'seleccionada' : mapProjection.estado,
                    modificadores: modifiers,
                    clasesEstado: (candidate ? ['reservation-operation-pin--selected'] : [])
                        .concat(assigned ? ['reservation-operation-pin--assigned'] : []),
                    atributos: {
                        'data-operation-table': mesaId,
                        'data-bloqueada-en-intervalo': mesaEstado.bloqueada_en_intervalo ? '1' : '0',
                        'data-causas-bloqueo': Array.isArray(mesaEstado.causas_bloqueo)
                            ? mesaEstado.causas_bloqueo.join(' ')
                            : '',
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
                state.currentAssignmentIds = new Set();
                state.candidateSelectionIds = new Set();
                state.assignmentSnapshot = null;
                renderAll();
                return;
            }

            var reservacionId = parseInt(reservacion.id, 10);
            var nextDate = String(reservacion.fecha || state.fecha || '');
            var nextHour = horaCorta(reservacion.hora);
            state.reservacionSeleccionadaId = reservacionId;
            state.currentAssignmentIds = new Set();
            state.candidateSelectionIds = new Set();
            state.assignmentSnapshot = null;
            loadDay(nextDate, {
                preserveHour: nextHour,
                requestedHour: nextHour,
                preserveReservationId: reservacionId,
                discardAssignment: true
            });
        }

        function selectTime(hora) {
            var nextHour = horaCorta(hora);
            if (state.modo !== 'solo_lectura' && sortedHours().indexOf(nextHour) === -1) {
                showGlobalNotice({
                    source: 'expired-hour',
                    type: 'restricted',
                    title: 'Horario no disponible',
                    summary: 'El horario ya no forma parte de la operación vigente.',
                    message: state.consecuenciaOperacion || ''
                });
                return;
            }
            exitAssignmentMode({ render: false, restoreFocus: false });
            hideTableWarning();
            dismissScheduleAlert();

            state.reservacionSeleccionadaId = null;
            state.currentAssignmentIds = new Set();
            state.candidateSelectionIds = new Set();
            state.assignmentSnapshot = null;
            loadDay(state.fecha, { preserveHour: nextHour, requestedHour: nextHour });
        }

        function loadDay(fecha, options) {
            options = options || {};
            fecha = String(fecha || '').trim();

            var previousDate = state.fecha;
            var dateChanged = fecha !== previousDate;
            var requestSequence = ++state.requestSequence;
            state.timedOutSequence = 0;

            if (state.timeoutId) {
                window.clearTimeout(state.timeoutId);
                state.timeoutId = null;
            }
            if (state.abortController) {
                state.abortController.abort();
            }

            if (dateChanged) {
                state.fecha = fecha;
                state.horaSeleccionada = null;
                state.projectionContext = { fecha: '', hora: '' };
                state.pendingProjectionContext = null;
                state.reservacionSeleccionadaId = null;
                state.currentAssignmentIds = new Set();
                state.candidateSelectionIds = new Set();
                state.assignmentSnapshot = null;
                state.horarios = [];
                state.reservaciones = [];
                state.mesas = [];
                state.mesasEstado = [];
                state.ocupacionFisica = [];
                state.ocupacionPorReservacion = {};
                state.capacidadHorario = {};
                state.alertasOperativas = [];
                state.estadoOperacion = 'disponible';
                state.mensajeOperacion = '';
                state.tituloOperacion = '';
                state.consecuenciaOperacion = '';
                state.loadFailure = null;
                state.fechaFallida = '';
                state.hasLoadedData = false;
            }

            var preserveReservationId = parseInt(options.preserveReservationId || state.reservacionSeleccionadaId || '0', 10) || null;
            var preserveAssignment = state.assignmentMode
                && options.discardAssignment !== true
                && preserveReservationId !== null
                && preserveReservationId === state.reservacionSeleccionadaId;
            var localSelectionBeforeRefresh = preserveAssignment
                ? Array.from(state.candidateSelectionIds)
                : [];

            if (!preserveAssignment) {
                exitAssignmentMode({ render: false, restoreFocus: false });
            }
            hideTableWarning();
            dismissScheduleAlert();

            if (!fecha) {
                state.fecha = '';
                setLoading(false);
                renderAll();
                return;
            }

            if (!fechaValida(fecha)) {
                state.fechaFallida = fecha;
                setLoading(false);
                showDateWarning(state.fecha || state.fechaMinima);
                renderAll();
                return;
            }

            state.abortController = new AbortController();
            var requestedHour = horaCorta(options.preserveHour || state.horaSeleccionada);
            var queryRequestedHour = horaCorta(options.requestedHour || requestedHour);
            state.pendingProjectionContext = {
                fecha: fecha,
                hora: queryRequestedHour
            };
            if (!options.preserveDateWarning) {
                clearDateWarning();
            }
            setLoading(true);
            renderAll();
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
            if (preserveReservationId) {
                operationQuery.set('reservation_id', String(preserveReservationId));
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
                    if (requestSequence !== state.requestSequence || state.fecha !== fecha) {
                        return;
                    }
                    if (String(data.fecha || '') !== fecha) {
                        throw { kind: 'consistency', requestedDate: fecha, responseDate: data.fecha };
                    }
                    var responseHour = horaCorta(data.hora || data.hora_sugerida || '');
                    if (queryRequestedHour && responseHour !== queryRequestedHour) {
                        throw {
                            kind: 'consistency',
                            requestedDate: fecha,
                            requestedHour: queryRequestedHour,
                            responseHour: responseHour
                        };
                    }
                    if (state.timeoutId) {
                        window.clearTimeout(state.timeoutId);
                        state.timeoutId = null;
                    }
                    state.fecha = data.fecha || fecha;
                    state.projectionContext = {
                        fecha: state.fecha,
                        hora: responseHour
                    };
                    state.pendingProjectionContext = null;
                    state.fechaFallida = '';
                    state.loadFailure = null;
                    state.modo = data.modo || 'operacion';
                    state.editable = data.editable !== false;
                    state.horarios = data.horarios_mapa || data.horarios || [];
                    state.reservaciones = data.reservaciones || [];
                    state.mesas = data.mesas || [];
                    state.mesasEstado = data.mesas_estado || [];
                    state.ocupacionFisica = data.ocupacion_fisica || [];
                    state.ocupacionPorReservacion = data.ocupacion_por_reservacion || {};
                    state.capacidadHorario = data.capacidad_horario || {};
                    state.alertasOperativas = data.alertas_operativas || [];
                    state.estadoOperacion = data.estado_operacion || 'disponible';
                    state.mensajeOperacion = data.mensaje || '';
                    state.tituloOperacion = data.titulo || '';
                    state.consecuenciaOperacion = data.consecuencia || '';
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
                        state.currentAssignmentIds = new Set(assignmentIdsFor(selected));
                        state.assignmentSnapshot = selected.assignment_snapshot || {
                            mesa_ids: Array.from(state.currentAssignmentIds),
                            version: String(selected.version || '')
                        };
                        state.candidateSelectionIds = preserveAssignment
                            ? new Set(localSelectionBeforeRefresh)
                            : new Set();
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
                        state.currentAssignmentIds = new Set();
                        state.candidateSelectionIds = new Set();
                        state.assignmentSnapshot = null;
                    }

                    setDateValue(state.fecha);
                    setLoading(false);
                    renderAll();
                    if (data.hora_solicitada_vencida === true) {
                        showGlobalNotice({
                            source: 'expired-hour',
                            type: 'warning',
                            title: data.titulo || '',
                            summary: data.mensaje || '',
                            message: data.consecuencia || ''
                        });
                    }
                    if (options.enterAssignmentMode || state.pendingInitialAssignment) {
                        state.pendingInitialAssignment = false;
                        enterAssignmentMode(null, { focus: true, allowAssignLater: options.assignmentLater === true });
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
                            renderAll();
                        }
                        return;
                    }
                    if (requestSequence !== state.requestSequence || state.fecha !== fecha) {
                        return;
                    }
                    setLoading(false);
                    state.loadFailure = {
                        title: error && error.titulo || 'No fue posible cargar las reservaciones.',
                        message: error && error.mensaje || 'Intenta actualizar la consulta.'
                    };
                    var kind = error instanceof TypeError ? 'connection' : ((error && error.kind) || 'unexpected');
                    var status = error && error.codigo === 'FECHA_FUERA_DE_HORIZONTE'
                        ? 'Fecha no disponible'
                        : (kind === 'connection' ? 'Sin conexion' : 'Error al actualizar');
                    setUpdateStatus(status, 'error');
                    showTechnicalError(kind, fecha, error);
                    renderAll();
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
            return fallback || '';
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
            data.set('version_esperada', String(
                state.assignmentSnapshot && state.assignmentSnapshot.version
                    || reservacion.version
                    || ''
            ));
            data.set('mesa_ids_actuales_presentes', '1');
            Array.from(state.currentAssignmentIds).forEach(function (mesaId) {
                data.append('mesa_ids_actuales[]', String(mesaId));
            });
            state.candidateSelectionIds.forEach(function (mesaId) {
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
            postJson(API_BASE + '/asignar-mesas', data)
                .then(function (payload) {
                    closeTicketConflictModal();
                    exitAssignmentMode({ restoreFocus: false });
                    if (payload.depende_liberacion_proyectada || (payload.advertencias || []).length) {
                        showGlobalNotice({
                            source: 'projection',
                            type: 'warning',
                            title: payload.titulo || '',
                            summary: payload.mensaje || '',
                            message: payload.consecuencia || '',
                            dismissible: true
                        });
                    } else {
                        showToast(payload.mensaje || '', 'success', payload.consecuencia || '');
                    }
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && error.codigo === 'CAPACIDAD_INSUFICIENTE') {
                        openCapacityConflictModal(error);
                        return;
                    }
                    if (error && ['CONFLICTO_TICKETS_ABIERTOS', 'CONFLICTO_TICKET_ABIERTO'].indexOf(error.codigo) !== -1) {
                        closeTicketConflictModal();
                        showInlineError(error.mensaje || '', error);
                        return;
                    }
                    if (error && ['DEPENDE_LIBERACION_PROYECTADA'].indexOf(error.codigo) !== -1) {
                        openTicketConflictModal(error);
                        return;
                    }
                    if (error && error.codigo === 'CONFLICTO_CONCURRENTE') {
                        closeTicketConflictModal();
                        showInlineError(error.mensaje || '', error);
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
                    if (error && error.codigo === 'VERSION_DESACTUALIZADA') {
                        closeTicketConflictModal();
                        showInlineError(error.mensaje || '', error);
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
                    if (error && error.codigo === 'MESA_OCUPADA') {
                        showInlineError(error.mensaje || '', error);
                        refreshDay({ preserveReservationId: reservacion.id });
                        return;
                    }
                    showInlineError(operationErrorMessage(error, ''), error);
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
            postJson(API_BASE + '/liberar-mesas', data)
                .then(function (payload) {
                    showToast(payload.mensaje || '', 'warning');
                    exitAssignmentMode({ restoreFocus: false });
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && ['VERSION_DESACTUALIZADA', 'CONFLICTO_CONCURRENTE'].indexOf(error.codigo) !== -1) {
                        showInlineError(error.mensaje || '', error);
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
                    showInlineError(operationErrorMessage(error, ''), error);
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function decisionObjects(payload) {
            var raw = Array.isArray(payload && payload.confirmaciones_requeridas)
                ? payload.confirmaciones_requeridas
                : (Array.isArray(payload && payload.requiredConfirmations)
                    ? payload.requiredConfirmations
                    : []);
            var decisions = raw.filter(function (decision) {
                return decision && typeof decision === 'object' && decision.mensaje;
            });
            if (!decisions.length && payload && payload.mensaje && payload.codigo) {
                decisions.push({
                    codigo: payload.codigo,
                    codigo_canonico: payload.codigo_canonico || payload.codigo,
                    tipo: payload.tipo || 'conflicto_recuperable',
                    titulo: payload.titulo || payload.mensaje,
                    mensaje: payload.mensaje,
                    descripcion: payload.descripcion || '',
                    consecuencia: payload.consecuencia || '',
                    acciones: Array.isArray(payload.acciones) ? payload.acciones : []
                });
            }
            return decisions;
        }

        function decisionCodes(decisions) {
            return decisions.map(function (decision) {
                return decision.codigo_canonico || decision.codigo;
            }).filter(Boolean).filter(function (code, index, all) {
                return all.indexOf(code) === index;
            });
        }

        function decisionActions(decision) {
            var actions = decision && Array.isArray(decision.acciones)
                ? decision.acciones.filter(function (action) {
                    return action && action.id && action.label && action.tipo;
                })
                : [];
            if (!actions.length) {
                console.error('Decisión de reservación sin acciones canónicas', decision);
                return [{ id: 'CERRAR', label: 'Cerrar', tipo: 'secondary' }];
            }
            return actions;
        }

        function decisionSummary(decision) {
            return [decision.mensaje, decision.descripcion, decision.consecuencia]
                .filter(Boolean)
                .join(' ');
        }

        function openTicketConflictModal(payload) {
            if (!confirmationController) {
                showInlineError(payload && payload.mensaje || '', payload);
                return;
            }

            var conflictos = Array.isArray(payload.conflictos_ticket)
                ? payload.conflictos_ticket
                : [];
            var decisiones = decisionObjects(payload);
            if (!decisiones.length) {
                showInlineError(payload && payload.mensaje || '', payload);
                return;
            }
            var confirmaciones = decisionCodes(decisiones);
            state.pendingAssignmentConflict = {
                token: payload.conflicto_token || '',
                ticketIds: conflictos.map(function (conflicto) {
                    return parseInt(conflicto.ticket_id, 10);
                }).filter(Boolean),
                confirmaciones: confirmaciones
            };
            var confirmationDetails = decisiones.map(decisionSummary);
            confirmationDetails = confirmationDetails.concat(conflictos.map(function (conflicto) {
                var conflictTables = (conflicto.mesas_conflicto || []).map(mesaNombre).join(', ');
                var allTables = (conflicto.mesa_ids || []).map(mesaNombre).join(', ');
                var origin = conflicto.origen === 'reservacion'
                    ? 'Reservación'
                    : 'Walk-in';
                return 'Ticket abierto' +
                    ' · Mesas seleccionadas: ' + (conflictTables || 'Sin identificar') +
                    ' · Todas sus mesas: ' + (allTables || 'Sin identificar') +
                    ' · Apertura: ' + (conflicto.hora_apertura || 'Sin dato') +
                    ' · Origen: ' + origin;
            }));
            var first = decisiones[0];
            confirmationController.open({
                variant: 'warning',
                decision: true,
                decisionData: first,
                mensaje: first.mensaje,
                actions: decisionActions(first),
                eyebrow: first.tipo === 'decision_requerida' ? 'Decisión administrativa' : 'Conflicto operativo',
                title: first.titulo || first.mensaje,
                description: first.descripcion || '',
                summary: confirmationDetails,
                consequence: first.consecuencia || '',
                onPrimary: function () {
                    var confirmation = state.pendingAssignmentConflict;
                    confirmationController.close(false);
                    if (confirmation) saveTableAssignment(confirmation);
                }
            });
        }

        function openCapacityConflictModal(payload) {
            var reservacion = selectedReservation();
            if (!confirmationController || !reservacion) {
                showInlineError(payload && payload.mensaje || '', payload);
                return;
            }

            var decisiones = decisionObjects(payload);
            if (!decisiones.length) {
                showInlineError(payload && payload.mensaje || '', payload);
                return;
            }
            var confirmaciones = decisionCodes(decisiones);
            var first = decisiones[0];
            state.pendingAssignmentConflict = {
                token: '',
                ticketIds: [],
                confirmaciones: confirmaciones
            };
            confirmationController.open({
                variant: 'warning',
                decision: true,
                decisionData: first,
                mensaje: first.mensaje,
                actions: decisionActions(first),
                eyebrow: first.tipo === 'decision_requerida' ? 'Decisión administrativa' : 'Conflicto operativo',
                title: first.titulo || first.mensaje,
                description: first.descripcion || '',
                summary: decisiones.slice(1).map(decisionSummary),
                consequence: first.consecuencia || '',
                onSecondary: function () {
                    state.pendingAssignmentConflict = null;
                },
                onPrimary: function () {
                    var confirmation = state.pendingAssignmentConflict;
                    closeTicketConflictModal();
                    if (confirmation) saveTableAssignment(confirmation);
                }
            });
        }

        function closeTicketConflictModal() {
            state.pendingAssignmentConflict = null;
            if (confirmationController && !confirmationController.element.hidden) {
                confirmationController.close(true);
            }
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
            postJson(API_BASE + '/comentario', data)
                .then(function (payload) {
                    showToast(payload.mensaje || '', 'success', payload.consecuencia || '');
                    state.commentEditingReservationId = null;
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    showInlineError(operationErrorMessage(error, ''));
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
            postJson(API_BASE + '/reasignar', data)
                .then(function (payload) {
                    showToast(payload.mensaje || '', 'success', payload.consecuencia || '');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    showInlineError(operationErrorMessage(error, ''));
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
                showInlineError('');
                return;
            }

            var estados = {
                verify: 'confirmada',
                'start-service': 'en_curso',
                cancel: 'cancelada',
                'no-show': 'no_show'
            };
            var estado = estados[action] || '';
            var data = new URLSearchParams();
            data.set('reservacion_id', String(reservacion.id));
            data.set('estado', estado);
            data.set('motivo', String(motivo || ''));

            setSaving(true);
            postJson(API_BASE + '/estado', data)
                .then(function (payload) {
                    showToast(payload.mensaje || '', 'success', payload.consecuencia || '');
                    refreshDay({ preserveReservationId: reservacion.id });
                })
                .catch(function (error) {
                    if (error && error.requiere_reasignacion) {
                        showGlobalNotice({
                            source: 'service-start-conflict',
                            type: 'warning',
                            title: 'El inicio de servicio requiere nuevas mesas',
                            summary: error.mensaje || '',
                            message: error.consecuencia || ''
                        });
                        enterAssignmentMode(null, { focus: true });
                        return;
                    }
                    if (error && error.codigo === 'RESERVACION_NO_EDITABLE') {
                        showInlineError(error.mensaje || '', error);
                        refreshDay({ preserveReservationId: reservacion.id, discardAssignment: true });
                        return;
                    }
                    if (error && error.codigo === 'DATOS_INCOMPLETOS') {
                        showInlineError(error.mensaje || '', error);
                        refreshDay({ preserveReservationId: reservacion.id });
                        return;
                    }
                    showInlineError(operationErrorMessage(error, ''), error);
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function openActionModal(action, reservacion) {
            if (!confirmationController) {
                showInlineError('No fue posible abrir la confirmación operativa.');
                return;
            }

            state.pendingAction = action;
            var isCancel = action === 'cancel';
            var isClearAssignment = action === 'clear-assignment';
            var title = isCancel
                ? 'Cancelar reservación'
                : (isClearAssignment ? 'Liberar asignación' : 'Registrar que el cliente no se presentó');
            var description = isCancel
                ? 'La cancelación conservará las relaciones históricas y requiere un motivo.'
                : (isClearAssignment
                    ? 'La reservación conservará el estado confirmado y quedará sin mesas asignadas para completar la asignación manual.'
                    : 'La tolerancia venció y no existe llegada ni ticket abierto.');
            var consequence = isCancel
                ? 'La reservación dejará de comprometer sus mesas y no podrá recuperarse desde el mapa.'
                : (isClearAssignment
                    ? 'La reservación seguirá confirmada y el equipo deberá resolver su asignación manualmente.'
                    : 'La reservación cambiará a no show y sus mesas dejarán de estar comprometidas.');
            var customContent = null;
            actionReason = null;
            if (isCancel) {
                var reasonField = document.createElement('label');
                reasonField.className = 'confirmation-modal__reason';
                var reasonLabel = document.createElement('span');
                reasonLabel.textContent = 'Motivo';
                actionReason = document.createElement('textarea');
                actionReason.rows = 3;
                actionReason.maxLength = 500;
                actionReason.placeholder = 'Explica brevemente el motivo';
                actionReason.setAttribute('aria-label', 'Motivo de la cancelación');
                reasonField.append(reasonLabel, actionReason);
                customContent = reasonField;
            }
            confirmationController.open({
                variant: isCancel ? 'danger' : 'warning',
                eyebrow: isCancel ? 'Acción administrativa' : 'Registro operativo',
                title: title,
                description: description,
                summary: reservacion ? [
                    'Cliente: ' + (reservacion.nombre || 'Sin nombre'),
                    'Fecha y hora: ' + (reservacion.fecha || '') + ' ' + String(reservacion.hora || '').slice(0, 5)
                ] : [],
                consequence: consequence,
                customContent: customContent,
                secondaryLabel: 'Volver',
                primaryLabel: isCancel ? 'Cancelar reservación' : (isClearAssignment ? 'Liberar asignación' : 'Registrar ausencia'),
                initialFocus: isCancel ? actionReason : 'primary',
                onPrimary: function () {
                    return confirmPendingAction();
                }
            });
        }

        function closeActionModal(restoreFocus) {
            state.pendingAction = null;
            if (confirmationController && !confirmationController.element.hidden) {
                confirmationController.close(restoreFocus !== false);
            }
            actionReason = null;
        }

        function confirmPendingAction() {
            var action = state.pendingAction;
            if (!action) {
                return;
            }
            var motivo = actionReason ? actionReason.value.trim() : '';
            if (action === 'cancel' && !motivo) {
                confirmationController.setStatus('Escribe el motivo de la cancelación.', true);
                if (actionReason) actionReason.focus();
                return false;
            }
            if (action === 'clear-assignment') {
                closeActionModal(false);
                clearTableAssignment();
                return true;
            }
            closeActionModal(false);
            executeReservationAction(action, motivo);
            return true;
        }

        function showInlineError(message, error) {
            error = error || {};
            var catalogMessage = error.mensaje || message || '';
            showGlobalNotice({
                source: 'action',
                type: 'error',
                title: error.titulo || 'No se pudo completar la acción',
                summary: catalogMessage || 'La operación no se completó.',
                message: error.consecuencia || 'No se aplicaron cambios; revisa el estado actual antes de continuar.'
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
                setCreateFormValue('confirmar_sobrecapacidad', '0');
            });
            createForm.addEventListener('change', function () {
                createModalDirty = true;
                setCreateFormValue('confirmar_sobrecapacidad', '0');
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

        document.addEventListener('operational:close-panel', function () {
            if (state.assignmentMode) {
                return;
            }
            state.reservacionSeleccionadaId = null;
            state.currentAssignmentIds = new Set();
            state.candidateSelectionIds = new Set();
            state.assignmentSnapshot = null;
            root.classList.add('is-panel-dismissed');
            renderReservationDetail();
            renderTableMap();
            renderAssignmentBar();
            updateUrl();
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

            if (!state.assignmentMode) {
                showGlobalNotice({
                    source: 'assignment',
                    type: 'info',
                    title: 'Modo de asignación inactivo',
                    summary: 'Selecciona una reservación y pulsa “Cambiar mesas”.',
                    message: 'El mapa solo cambia mesas dentro del modo de asignación explícito. La vista actual no se modificó.'
                });
                return;
            }

            var mesaId = parseInt(event.detail.mesaId, 10);
            if (!mesaPuedeSerCandidata(mesaId)) {
                return;
            }
            if (state.candidateSelectionIds.has(mesaId)) {
                state.candidateSelectionIds.delete(mesaId);
                if (state.tableWarningMesaId === mesaId) {
                    hideTableWarning();
                }
            } else {
                state.candidateSelectionIds.add(mesaId);
                showTableWarning(mesaId);
            }

            renderReservationDetail();
            renderTableMap();
            renderAssignmentBar();
        });

        root.addEventListener('click', function (event) {
            var reservationButton = event.target.closest('[data-operation-reservation]');
            var assignmentStart = event.target.closest('[data-operation-assignment-start]');
            var assignmentCancel = event.target.closest('[data-operation-assignment-cancel]');
            var assignmentClear = event.target.closest('[data-operation-clear]');
            var saveButton = event.target.closest('[data-operation-save]');
            var commentButton = event.target.closest('[data-operation-comment-save]');
            var commentEditButton = event.target.closest('[data-operation-comment-edit]');
            var commentCancelButton = event.target.closest('[data-operation-comment-cancel]');
            var actionButton = event.target.closest('[data-operation-action]');
            var mobileTab = event.target.closest('[data-operation-mobile-tab]');

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

            if (commentEditButton) {
                var editReservation = selectedReservation();
                if (editReservation) {
                    state.commentEditingReservationId = parseInt(editReservation.id, 10);
                    renderReservationDetail();
                    window.setTimeout(function () {
                        var textarea = root.querySelector('[data-operation-comment]');
                        if (textarea) textarea.focus();
                    }, 0);
                }
                return;
            }

            if (commentCancelButton) {
                state.commentEditingReservationId = null;
                renderReservationDetail();
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
