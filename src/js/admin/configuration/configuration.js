/**
 * Interacciones frontend del módulo Configuración.
 * Todas las validaciones deben repetirse en el backend al agregar persistencia.
 */
(function () {
    function setFieldError(field, message) {
        if (!field) {
            return;
        }

        const container = field.closest('.admin-field, .admin-datetime-field');
        const feedback = container ? container.querySelector('[data-field-error]') : null;
        const visibleFields = field.type === 'hidden' && container
            ? Array.from(container.querySelectorAll('[data-date-display], [data-time-display]'))
            : [field];
        if (container) {
            container.classList.toggle('has-error', Boolean(message));
        }
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        visibleFields.forEach(function (visibleField) {
            visibleField.setAttribute('aria-invalid', message ? 'true' : 'false');
        });
        if (feedback) {
            feedback.textContent = message || '';
        }
    }

    function focusField(field) {
        if (!field) {
            return;
        }

        const container = field.closest('.admin-field, .admin-datetime-field');
        const focusTarget = field.type === 'hidden' && container
            ? container.querySelector('[data-date-display], [data-time-display]')
            : field;
        if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
        }
    }

    function initStaticTimePicker(field) {
        if (!field || !window.createReservationTimePicker) {
            return null;
        }
        if (field._reservationTimePicker) {
            return field._reservationTimePicker;
        }

        const root = field.closest('[data-reservation-time-picker]');
        if (!root) {
            return null;
        }

        field._reservationTimePicker = window.createReservationTimePicker({
            root: root,
            initialTime: field.value,
            staticStep: root.getAttribute('data-static-step'),
            autoLoad: false,
            // En el panel el desplegable vive dentro de una .admin-card, que lo
            // recorta y lo atrapa en su contexto de apilamiento: portado a
            // <body> se coloca contra el campo sin tapar el resto del formulario.
            portal: true
        });
        return field._reservationTimePicker;
    }

    function setStatus(element, message, type) {
        if (!element) {
            return;
        }

        element.textContent = message;
        element.classList.remove('is-error', 'is-pending');
        if (type) {
            element.classList.add('is-' + type);
        }
    }

    async function jsonResponse(response) {
        const contentType = response.headers.get('Content-Type') || '';
        if (!contentType.toLowerCase().includes('application/json')) {
            throw new Error('El servidor no devolvió una respuesta JSON válida.');
        }
        return response.json();
    }

    /**
     * Confirma guardar un horario que deja reservaciones fuera.
     *
     * Devuelve una promesa porque el guardado es async y reintenta con
     * confirmar_conflictos=true sólo después de la confirmación visible.
     */
    async function confirmarConflictosHorario(total) {
        const detalle = total + (total === 1 ? ' reservación' : ' reservaciones');

        if (!window.ConfirmationModal) {
            return false;
        }

        const resultado = await window.ConfirmationModal.get().open({
            variant: 'warning',
            eyebrow: 'Horario de operación',
            title: 'Confirmar cambio de horario',
            description: 'Este cambio afecta ' + detalle + '.',
            consequence: 'Ninguna será cancelada automáticamente. El seguimiento quedará disponible para preparar avisos y atender los casos afectados.',
            secondaryLabel: 'Revisar el horario',
            primaryLabel: 'Aplicar cambio',
        });

        return resultado && resultado.action === 'primary';
    }

    function initSchedule() {
        const form = document.querySelector('[data-schedule-form]');
        if (!form) {
            return;
        }

        const status = form.querySelector('[data-schedule-status]');
        const submitButton = form.querySelector('[data-schedule-validate]');
        const resetButton = form.querySelector('[data-schedule-reset]');
        const csrfField = form.querySelector('[name="admin_csrf"]');
        const apiUrl = form.getAttribute('data-schedule-api') || form.action;
        const readApiUrl = form.getAttribute('data-schedule-read-api') || apiUrl;
        const unsavedModal = document.getElementById('schedule-unsaved-modal');
        const leaveLink = unsavedModal ? unsavedModal.querySelector('[data-unsaved-leave]') : null;
        let initialDirty = form.dataset.initialDirty === '1';
        let initialState = '';
        let hasUnsavedChanges = false;
        let saving = false;
        let confirmedLeave = false;
        let pendingUrl = '';

        function normalizeTime(value) {
            const match = String(value || '').trim().match(/^([01]\d|2[0-3]):([0-5]\d)/);
            return match ? match[1] + ':' + match[2] : '';
        }

        function getScheduleState() {
            return JSON.stringify(Array.from(form.querySelectorAll('[data-schedule-row]')).map(function (row) {
                const day = row.querySelector('input[type="hidden"][name$="[dia_semana]"]');
                const toggle = row.querySelector('[data-schedule-toggle]');
                const open = row.querySelector('[data-schedule-open]');
                const close = row.querySelector('[data-schedule-close]');
                const isOpen = Boolean(toggle && toggle.checked);

                return {
                    dia_semana: Number(day ? day.value : -1),
                    abierto: isOpen ? 1 : 0,
                    hora_apertura: isOpen ? normalizeTime(open ? open.value : '') : '',
                    hora_cierre: isOpen ? normalizeTime(close ? close.value : '') : ''
                };
            }).sort(function (a, b) {
                return a.dia_semana - b.dia_semana;
            }));
        }

        function getSchedulePayload() {
            return JSON.parse(getScheduleState());
        }

        function applyPersistedSchedule(horarios) {
            const byDay = new Map((Array.isArray(horarios) ? horarios : []).map(function (horario) {
                return [Number(horario.dia_semana), horario];
            }));

            Array.from(form.querySelectorAll('[data-schedule-row]')).forEach(function (row) {
                const day = row.querySelector('input[type="hidden"][name$="[dia_semana]"]');
                const toggle = row.querySelector('[data-schedule-toggle]');
                const open = row.querySelector('[data-schedule-open]');
                const close = row.querySelector('[data-schedule-close]');
                const persisted = byDay.get(Number(day ? day.value : -1));
                if (!persisted || !toggle || !open || !close) {
                    throw new Error('El servidor devolvió un horario semanal incompleto.');
                }

                toggle.checked = Boolean(persisted.abierto);
                const openValue = toggle.checked ? normalizeTime(persisted.hora_apertura) : '';
                const closeValue = toggle.checked ? normalizeTime(persisted.hora_cierre) : '';
                open.value = openValue;
                close.value = closeValue;
                updateRow(row);
                // Tras recargar del servidor, la rejilla tiene que volver a
                // corresponder con las horas que quedaron puestas.
                syncHourGrid(row);
            });
        }

        // Sin badge de estado: que "Guardar horarios" pase de apagado a
        // encendido ya dice que hay algo pendiente, y el rotulo repetia eso
        // ocupando la cabecera del panel.
        function renderDirtyState() {
            submitButton.disabled = !hasUnsavedChanges || saving;
            if (resetButton) {
                resetButton.disabled = !hasUnsavedChanges || saving;
            }
        }

        function updateDirtyState() {
            hasUnsavedChanges = initialDirty || getScheduleState() !== initialState;
            if (hasUnsavedChanges) {
                setStatus(status, '', null);
            }
            renderDirtyState();
        }

        function updateRow(row) {
            const toggle = row.querySelector('[data-schedule-toggle]');
            const label = row.querySelector('[data-switch-label]');
            const fields = [row.querySelector('[data-schedule-open]'), row.querySelector('[data-schedule-close]')];

            row.classList.toggle('is-closed', !toggle.checked);
            label.textContent = toggle.checked ? 'Abierto' : 'Cerrado';
            // Los campos son hidden: no se deshabilitan (un hidden deshabilitado
            // no viaja en el envio y el backend recibiria el dia sin horas),
            // solo se limpia su error cuando el dia esta cerrado.
            fields.forEach(function (field) {
                if (!toggle.checked) {
                    setFieldError(field, '');
                }
            });
        }

        function validateRow(row) {
            const toggle = row.querySelector('[data-schedule-toggle]');
            const open = row.querySelector('[data-schedule-open]');
            const close = row.querySelector('[data-schedule-close]');
            setFieldError(open, '');
            setFieldError(close, '');

            if (!toggle.checked) {
                return true;
            }

            // Un solo mensaje: apertura y cierre se eligen en la misma rejilla,
            // así que dos errores simultáneos describirían un único descuido.
            if (!open.value) {
                setFieldError(open, 'Marca la hora de apertura en la rejilla.');
                return false;
            }
            if (!close.value) {
                setFieldError(open, 'Marca también la hora de cierre.');
                return false;
            }
            if (open.value >= close.value) {
                setFieldError(open, 'El cierre debe ser posterior a la apertura.');
                return false;
            }
            return true;
        }

        /*
         * Rejilla de horas 00-23, con resolución de media hora.
         *
         * Sustituye a las pestañas de rangos preferidos, que solo ofrecían las
         * combinaciones ya guardadas. Primer toque = apertura, segundo =
         * cierre; tocar de nuevo con el rango completo lo reinicia. Un cierre
         * anterior o igual a la apertura no es un error que mostrar sino la
         * señal de que se está empezando otro rango, así que se toma como la
         * nueva apertura.
         *
         * Cada toque pregunta si es la hora en punto o y media: el restaurante
         * abre a las 08:30 y con la rejilla a resolución de hora ese horario
         * existía en la base pero no se podía volver a elegir desde el panel.
         *
         * Los inputs ocultos siguen siendo la fuente de verdad: la rejilla los
         * escribe y el resto del formulario (dirty state, validación, guardado
         * por API) no se entera de que cambió la forma de elegir.
         */

        /** Minutos desde medianoche, o null si el valor no es una hora. */
        function minutosDeValor(valor) {
            const normal = normalizeTime(valor);
            if (!normal) {
                return null;
            }
            return Number(normal.slice(0, 2)) * 60 + Number(normal.slice(3, 5));
        }

        /** Bloque horario (0-23) al que pertenecen unos minutos. */
        function bloqueDeMinutos(minutos) {
            return minutos === null ? null : Math.floor(minutos / 60);
        }

        function textoHora(minutos) {
            const hh = String(Math.floor(minutos / 60)).padStart(2, '0');
            const mm = String(minutos % 60).padStart(2, '0');
            return hh + ':' + mm;
        }

        function syncHourGrid(row) {
            const grid = row.querySelector('[data-schedule-hours]');
            if (!grid) {
                return;
            }

            const toggle = row.querySelector('[data-schedule-toggle]');
            const abierto = Boolean(toggle && toggle.checked);
            const open = row.querySelector('[data-schedule-open]');
            const close = row.querySelector('[data-schedule-close]');
            const desdeMin = abierto ? minutosDeValor(open ? open.value : '') : null;
            const hastaMin = abierto ? minutosDeValor(close ? close.value : '') : null;
            const desde = bloqueDeMinutos(desdeMin);
            const hasta = bloqueDeMinutos(hastaMin);

            grid.querySelectorAll('[data-schedule-hour]').forEach(function (btn) {
                const hora = Number(btn.dataset.scheduleHour);
                const esApertura = hora === desde;
                const esCierre = hora === hasta;
                const esExtremo = esApertura || esCierre;
                const dentro = desde !== null && hasta !== null && hora > desde && hora < hasta;

                btn.classList.toggle('is-edge', esExtremo);
                btn.classList.toggle('is-in-range', dentro);
                // La media hora se marca en la propia celda: sin ella, 08:00 y
                // 08:30 se veían idénticos en la rejilla.
                const media = (esApertura && desdeMin % 60 !== 0) || (esCierre && hastaMin % 60 !== 0);
                btn.classList.toggle('is-half', media);
                const marca = btn.querySelector('[data-schedule-hour-min]');
                if (marca) {
                    marca.textContent = media ? ':30' : '';
                }

                btn.setAttribute('aria-pressed', esExtremo || dentro ? 'true' : 'false');
                btn.disabled = !abierto;
            });

            const resumen = row.querySelector('[data-schedule-summary]');
            if (!resumen) {
                return;
            }

            if (!abierto) {
                resumen.textContent = 'Cerrado todo el día.';
            } else if (open.value && close.value) {
                resumen.textContent = 'Abre ' + normalizeTime(open.value) + ' y cierra ' + normalizeTime(close.value) + '.';
            } else if (open.value) {
                resumen.textContent = 'Apertura ' + normalizeTime(open.value) + '. Elige la hora de cierre.';
            } else {
                resumen.textContent = 'Elige la hora de apertura.';
            }
        }

        function aplicarMinutos(row, minutos) {
            const open = row.querySelector('[data-schedule-open]');
            const close = row.querySelector('[data-schedule-close]');
            const valor = textoHora(minutos);
            const desde = minutosDeValor(open.value);
            const rangoCompleto = Boolean(open.value && close.value);

            if (rangoCompleto || desde === null || minutos <= desde) {
                open.value = valor;
                close.value = '';
            } else {
                close.value = valor;
            }

            syncHourGrid(row);
            validateRow(row);
            updateDirtyState();
        }

        /*
         * Elegir el minuto sin diálogo nativo (ver CLAUDE.md): un pequeño
         * popover anclado al botón con las dos únicas opciones que el negocio
         * usa. Se cierra al elegir, con Escape o al tocar fuera.
         */
        let popMinutos = null;

        function cerrarPopMinutos() {
            if (!popMinutos) {
                return;
            }
            const anterior = popMinutos;
            popMinutos = null;
            if (anterior.boton) {
                anterior.boton.setAttribute('aria-expanded', 'false');
            }
            if (anterior.nodo && anterior.nodo.parentNode) {
                anterior.nodo.parentNode.removeChild(anterior.nodo);
            }
        }

        function pedirMinuto(row, boton, hora) {
            // Segundo toque sobre el mismo botón: se entiende como cancelar.
            if (popMinutos && popMinutos.boton === boton) {
                cerrarPopMinutos();
                return;
            }
            cerrarPopMinutos();

            const enPunto = hora * 60;
            const yMedia = hora * 60 + 30;
            const nodo = document.createElement('div');
            nodo.className = 'admin-schedule__minute-pop';
            nodo.setAttribute('role', 'group');
            nodo.setAttribute('aria-label', 'Minuto de la hora ' + textoHora(enPunto));
            nodo.innerHTML =
                '<button type="button" class="admin-schedule__minute" data-minutos="' + enPunto + '">' +
                    '<span class="admin-schedule__minute-hora">' + textoHora(enPunto) + '</span>' +
                    '<span class="admin-schedule__minute-nombre">En punto</span>' +
                '</button>' +
                '<button type="button" class="admin-schedule__minute" data-minutos="' + yMedia + '">' +
                    '<span class="admin-schedule__minute-hora">' + textoHora(yMedia) + '</span>' +
                    '<span class="admin-schedule__minute-nombre">Y media</span>' +
                '</button>';

            nodo.addEventListener('click', function (evento) {
                const opcion = evento.target.closest('[data-minutos]');
                if (!opcion) {
                    return;
                }
                const minutos = Number(opcion.dataset.minutos);
                cerrarPopMinutos();
                aplicarMinutos(row, minutos);
            });

            /*
             * Se cuelga de la rejilla en position:absolute y no como un hijo
             * más: insertarlo en el flujo le robaría una celda al grid y las 24
             * horas se recorrerían de sitio cada vez que se abre.
             */
            const rejilla = boton.parentNode;
            rejilla.appendChild(nodo);
            const maximo = Math.max(0, rejilla.clientWidth - nodo.offsetWidth);
            nodo.style.left = Math.min(boton.offsetLeft, maximo) + 'px';
            nodo.style.top = (boton.offsetTop + boton.offsetHeight + 6) + 'px';

            boton.setAttribute('aria-expanded', 'true');
            popMinutos = { nodo: nodo, boton: boton };

            const primera = nodo.querySelector('[data-minutos]');
            if (primera) {
                primera.focus();
            }
        }

        document.addEventListener('keydown', function (evento) {
            if (evento.key === 'Escape' && popMinutos) {
                const boton = popMinutos.boton;
                cerrarPopMinutos();
                if (boton) {
                    boton.focus();
                }
            }
        });

        document.addEventListener('click', function (evento) {
            if (!popMinutos) {
                return;
            }
            if (popMinutos.nodo.contains(evento.target) || popMinutos.boton.contains(evento.target)) {
                return;
            }
            cerrarPopMinutos();
        });

        form.querySelectorAll('[data-schedule-row]').forEach(function (row) {
            const toggle = row.querySelector('[data-schedule-toggle]');
            toggle.addEventListener('change', function () {
                cerrarPopMinutos();
                updateRow(row);
                syncHourGrid(row);
                updateDirtyState();
            });
            row.querySelectorAll('[data-schedule-hour]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    pedirMinuto(row, btn, Number(btn.dataset.scheduleHour));
                });
            });
            updateRow(row);
            syncHourGrid(row);
        });

        initialState = getScheduleState();
        hasUnsavedChanges = initialDirty;
        renderDirtyState();

        window.addEventListener('beforeunload', function (event) {
            if (!hasUnsavedChanges || saving || confirmedLeave) {
                return;
            }

            event.preventDefault();
            event.returnValue = '';
        });

        document.addEventListener('click', function (event) {
            const link = event.target.closest('a[href]');
            if (
                !hasUnsavedChanges
                || saving
                || confirmedLeave
                || !link
                || link === leaveLink
                || event.defaultPrevented
                || event.button !== 0
                || event.ctrlKey
                || event.metaKey
                || event.shiftKey
                || event.altKey
                || (link.target && link.target.toLowerCase() !== '_self')
                || link.hasAttribute('download')
            ) {
                return;
            }

            const rawHref = link.getAttribute('href') || '';
            if (/^(mailto:|tel:|javascript:)/i.test(rawHref)) {
                return;
            }

            let url;
            try {
                url = new URL(link.href, window.location.href);
            } catch (error) {
                return;
            }

            if (url.origin !== window.location.origin) {
                return;
            }

            const sameDocument = url.pathname === window.location.pathname
                && url.search === window.location.search;
            if (sameDocument) {
                return;
            }

            event.preventDefault();
            pendingUrl = url.href;
            if (leaveLink) {
                leaveLink.href = pendingUrl;
            }
            document.dispatchEvent(new CustomEvent('admin:open-modal', {
                detail: { id: 'schedule-unsaved-modal', trigger: link }
            }));
        });

        if (leaveLink) {
            leaveLink.addEventListener('click', function (event) {
                if (!pendingUrl) {
                    event.preventDefault();
                    return;
                }
                confirmedLeave = true;
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', function () {
                if (!hasUnsavedChanges || saving) {
                    return;
                }
                // Volver por GET recupera la versión persistida, incluso si
                // esta vista provino de un POST rechazado con datos inválidos.
                confirmedLeave = true;
                window.location.assign(window.location.pathname);
            });
        }

        form.addEventListener('submit', async function (event) {
            event.preventDefault();
            if (saving || !hasUnsavedChanges) {
                return;
            }

            const rows = Array.from(form.querySelectorAll('[data-schedule-row]'));
            let valid = true;
            rows.forEach(function (row) {
                if (!validateRow(row)) {
                    valid = false;
                }
            });
            if (!valid) {
                setStatus(status, 'Revisa los horarios marcados antes de continuar.', 'error');
                const invalidField = form.querySelector('[aria-invalid="true"]');
                if (invalidField) {
                    focusField(invalidField);
                }
                return;
            }

            saving = true;
            renderDirtyState();
            setStatus(status, 'Guardando horarios…', 'pending');

            try {
                let confirmarConflictos = false;
                let data = null;
                let response = null;

                while (true) {
                    response = await fetch(apiUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            horarios: getSchedulePayload(),
                            confirmar_conflictos: confirmarConflictos,
                            admin_csrf: csrfField ? csrfField.value : ''
                        })
                    });
                    data = await jsonResponse(response);

                    if (
                        response.status === 409
                        && data.codigo === 'RESERVACIONES_AFECTADAS'
                        && data.requiere_confirmacion === true
                        && !confirmarConflictos
                    ) {
                        const total = Number(data.reservaciones_afectadas || 0);
                        const confirmed = await confirmarConflictosHorario(total);
                        if (!confirmed) {
                            setStatus(status, 'No se guardaron los cambios de horario.', 'error');
                            return;
                        }
                        confirmarConflictos = true;
                        continue;
                    }
                    break;
                }

                if (!response.ok || !data || data.ok !== true) {
                    throw new Error(
                        (data && (data.mensaje || (data.errors && data.errors[0])))
                        || 'No fue posible actualizar los horarios.'
                    );
                }

                if (data.impacto_id) {
                    window.location.assign(
                        '/admin/configuracion/horarios?resultado=horarios_actualizados&impacto_id='
                        + encodeURIComponent(String(data.impacto_id))
                    );
                    return;
                }

                const readResponse = await fetch(readApiUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const readData = await jsonResponse(readResponse);
                if (!readResponse.ok || readData.ok !== true || !Array.isArray(readData.horarios)) {
                    throw new Error(readData.mensaje || 'No fue posible volver a consultar los horarios.');
                }

                applyPersistedSchedule(readData.horarios);
                initialDirty = false;
                initialState = getScheduleState();
                hasUnsavedChanges = false;
                setStatus(status, data.mensaje || 'Los horarios fueron actualizados.', 'success');
            } catch (error) {
                setStatus(
                    status,
                    error && error.message
                        ? error.message
                        : 'No fue posible actualizar los horarios.',
                    'error'
                );
            } finally {
                saving = false;
                renderDirtyState();
            }
        });
    }

    function initExceptionForm() {
        const form = document.querySelector('[data-exception-form]');
        if (!form) {
            return;
        }

        const type = form.querySelector('[data-exception-type]');
        const times = form.querySelector('[data-exception-times]');
        const open = form.querySelector('[data-exception-open]');
        const close = form.querySelector('[data-exception-close]');
        const status = form.querySelector('[data-exception-status]');
        const modal = document.getElementById('schedule-exception-modal');
        const title = modal.querySelector('#exception-modal-title');
        const id = form.querySelector('[data-exception-id]');
        const date = form.querySelector('[data-exception-date]');
        const dateRoot = date ? date.closest('[data-reservation-date-picker]') : null;
        const reason = form.querySelector('[name="motivo"]');
        const active = form.querySelector('input[type="checkbox"][name="activo"]');
        const minimumDate = dateRoot ? dateRoot.getAttribute('data-min-date') || '' : '';
        const datePicker = dateRoot && window.createReservationDatePicker
            ? window.createReservationDatePicker({
                root: dateRoot,
                minDate: minimumDate,
                today: dateRoot.getAttribute('data-today-date'),
                initialValue: date.value
            })
            : null;
        const openPicker = initStaticTimePicker(open);
        const closePicker = initStaticTimePicker(close);

        function validateDate() {
            setFieldError(date, '');
            if (!date.value) {
                setFieldError(date, 'Selecciona una fecha.');
                return false;
            }
            if (minimumDate && date.value < minimumDate) {
                setFieldError(date, 'No puedes registrar una excepción para una fecha anterior al día actual.');
                return false;
            }

            return true;
        }

        function updateType() {
            const isSpecial = type.value === 'horario_especial';
            times.hidden = !isSpecial;
            open.disabled = !isSpecial;
            close.disabled = !isSpecial;
            open.required = isSpecial;
            close.required = isSpecial;
            if (openPicker) {
                openPicker.setDisabled(!isSpecial);
            }
            if (closePicker) {
                closePicker.setDisabled(!isSpecial);
            }
            if (!isSpecial) {
                setFieldError(open, '');
                setFieldError(close, '');
            }
            setStatus(status, '', null);
        }

        type.addEventListener('change', updateType);
        date.addEventListener('reservation:datechange', validateDate);
        updateType();

        function resetForCreate() {
            form.reset();
            id.value = '';
            if (datePicker) {
                datePicker.setValue('', true);
            } else {
                date.value = '';
            }
            type.value = 'cerrado';
            type.dispatchEvent(new Event('change', { bubbles: true }));
            reason.value = '';
            if (openPicker) {
                openPicker.setValue('', true);
            } else {
                open.value = '';
            }
            if (closePicker) {
                closePicker.setValue('', true);
            } else {
                close.value = '';
            }
            active.checked = true;
            title.textContent = 'Registrar excepción';
            setFieldError(date, '');
            setFieldError(open, '');
            setFieldError(close, '');
            updateType();
        }

        function fillForEdit(data) {
            id.value = data.id || '';
            if (datePicker) {
                datePicker.setValue(data.fecha || '', true);
            } else {
                date.value = data.fecha || '';
            }
            type.value = data.tipo || 'cerrado';
            type.dispatchEvent(new Event('change', { bubbles: true }));
            reason.value = data.motivo || '';
            if (openPicker) {
                openPicker.setValue(data.hora_apertura || '', true);
            } else {
                open.value = data.hora_apertura || '';
            }
            if (closePicker) {
                closePicker.setValue(data.hora_cierre || '', true);
            } else {
                close.value = data.hora_cierre || '';
            }
            active.checked = Boolean(data.activo);
            title.textContent = 'Editar excepción';
            setFieldError(date, '');
            setFieldError(open, '');
            setFieldError(close, '');
            updateType();
        }

        document.querySelectorAll('[data-exception-create]').forEach(function (button) {
            button.addEventListener('click', resetForCreate);
        });

        document.querySelectorAll('[data-exception-edit]').forEach(function (button) {
            button.addEventListener('click', function () {
                let data = {};
                try {
                    data = JSON.parse(button.dataset.exceptionEdit || '{}');
                } catch (error) {
                    data = {};
                }
                fillForEdit(data);
                document.dispatchEvent(new CustomEvent('admin:open-modal', {
                    detail: { id: 'schedule-exception-modal', trigger: button }
                }));
            });
        });

        async function sendException(confirmarConflictos) {
            const payload = Object.fromEntries(new FormData(form).entries());
            payload.confirmar_conflictos = confirmarConflictos;
            const response = await fetch('/api/configuracion/horarios/excepciones', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            return { response: response, data: await jsonResponse(response) };
        }

        function confirmException(total, onPrimary) {
            if (!window.ConfirmationModal) {
                return false;
            }
            window.ConfirmationModal.get().open({
                variant: 'warning',
                eyebrow: 'Horario de operación',
                title: 'Confirmar cambio de horario',
                description: 'Este cambio afecta ' + total + (total === 1 ? ' reservación.' : ' reservaciones.'),
                consequence: 'Ninguna será cancelada automáticamente. El seguimiento quedará disponible para preparar avisos y atender los casos afectados.',
                secondaryLabel: 'Revisar el horario',
                primaryLabel: 'Aplicar cambio',
                onPrimary: onPrimary
            });
            return true;
        }

        form.addEventListener('submit', async function (event) {
            setFieldError(open, '');
            setFieldError(close, '');
            let valid = validateDate() && form.checkValidity();

            if (type.value === 'horario_especial') {
                if (!open.value) {
                    setFieldError(open, 'Indica la hora de apertura.');
                    valid = false;
                }
                if (!close.value) {
                    setFieldError(close, 'Indica la hora de cierre.');
                    valid = false;
                } else if (open.value && open.value >= close.value) {
                    setFieldError(close, 'El cierre debe ser posterior a la apertura.');
                    valid = false;
                }
            }

            if (!valid) {
                event.preventDefault();
                setStatus(status, 'Revisa los campos obligatorios de la excepción.', 'error');
                form.reportValidity();
                const invalidField = form.querySelector('[aria-invalid="true"]');
                if (invalidField) {
                    focusField(invalidField);
                }
                return;
            }
            event.preventDefault();
            if (form.dataset.submitting === '1') {
                return;
            }

            form.dataset.submitting = '1';
            setStatus(status, 'Guardando excepción…', 'pending');
            try {
                const first = await sendException(false);
                if (
                    first.response.status === 409
                    && first.data.codigo === 'RESERVACIONES_AFECTADAS'
                    && first.data.requiere_confirmacion === true
                ) {
                    form.dataset.submitting = '0';
                    const opened = confirmException(
                        Number(first.data.reservaciones_afectadas || 0),
                        function () {
                            form.dataset.submitting = '1';
                            setStatus(status, 'Aplicando cambio…', 'pending');
                            return sendException(true).then(function (result) {
                                if (!result.response.ok || !result.data || result.data.ok !== true) {
                                    throw new Error(result.data.mensaje || 'No fue posible guardar la excepción.');
                                }
                                window.location.assign('/admin/configuracion/horarios?resultado='
                                    + encodeURIComponent(result.data.editada ? 'excepcion_actualizada' : 'excepcion_creada')
                                    + (result.data.impacto_id ? '&impacto_id=' + encodeURIComponent(String(result.data.impacto_id)) : ''));
                            }).catch(function (error) {
                                form.dataset.submitting = '0';
                                setStatus(status, error.message || 'No fue posible guardar la excepción.', 'error');
                            });
                        }
                    );
                    if (!opened) {
                        setStatus(status, 'No fue posible abrir la confirmación.', 'error');
                    }
                    return;
                }
                if (!first.response.ok || !first.data || first.data.ok !== true) {
                    throw new Error(first.data.mensaje || 'No fue posible guardar la excepción.');
                }
                window.location.assign('/admin/configuracion/horarios?resultado='
                    + encodeURIComponent(first.data.editada ? 'excepcion_actualizada' : 'excepcion_creada')
                    + (first.data.impacto_id ? '&impacto_id=' + encodeURIComponent(String(first.data.impacto_id)) : ''));
            } catch (error) {
                form.dataset.submitting = '0';
                setStatus(status, error.message || 'No fue posible guardar la excepción.', 'error');
            }
        });

        if (document.querySelector('[data-open-exception-modal]')) {
            document.dispatchEvent(new CustomEvent('admin:open-modal', {
                detail: { id: 'schedule-exception-modal' }
            }));
        }
    }

    function initExceptionDelete() {
        const actionStatus = document.querySelector('[data-exception-action-status]');
        function sendDelete(id, confirmarConflictos) {
            return fetch('/api/configuracion/horarios/excepciones', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    id: id,
                    confirmar_conflictos: confirmarConflictos
                })
            }).then(function (response) {
                return jsonResponse(response).then(function (data) {
                    return { response: response, data: data };
                });
            });
        }

        function openDeleteConfirmation(data, onPrimary) {
            if (!window.ConfirmationModal) return false;
            window.ConfirmationModal.get().open({
                variant: 'danger',
                eyebrow: 'Horario de operación',
                title: 'Eliminar excepción',
                description: 'Se eliminará ' + (data.tipo_nombre || 'la excepción') + ' del ' + (data.fecha || 'día seleccionado') + '.',
                consequence: 'Ninguna reservación será cancelada automáticamente. Si el cambio afecta reservaciones, el seguimiento quedará disponible para atenderlas.',
                secondaryLabel: 'Cancelar',
                primaryLabel: 'Eliminar excepción',
                onPrimary: onPrimary
            });
            return true;
        }

        function executeDelete(data, confirmarConflictos, button) {
            if (button) button.disabled = true;
            return sendDelete(Number(data.id || 0), confirmarConflictos).then(function (result) {
                if (
                    result.response.status === 409
                    && result.data.codigo === 'RESERVACIONES_AFECTADAS'
                    && result.data.requiere_confirmacion === true
                    && !confirmarConflictos
                ) {
                    if (button) button.disabled = false;
                    return openDeleteConfirmation(data, function () {
                        return executeDelete(data, true, button);
                    });
                }
                if (!result.response.ok || !result.data || result.data.ok !== true) {
                    throw new Error(result.data.mensaje || 'No fue posible eliminar la excepción.');
                }
                window.location.assign('/admin/configuracion/horarios?resultado=excepcion_eliminada'
                    + (result.data.impacto_id ? '&impacto_id=' + encodeURIComponent(String(result.data.impacto_id)) : ''));
                return true;
            }).catch(function (error) {
                if (button) button.disabled = false;
                setStatus(actionStatus, error.message || 'No fue posible eliminar la excepción.', 'error');
                return false;
            });
        }

        document.querySelectorAll('[data-exception-delete]').forEach(function (button) {
            button.addEventListener('click', function () {
                let data = {};
                try {
                    data = JSON.parse(button.dataset.exceptionDelete || '{}');
                } catch (error) {
                    data = {};
                }
                openDeleteConfirmation(data, function () {
                    return executeDelete(data, false, button);
                });
            });
        });
    }

    function initExceptionStateToggles() {
        const actionStatus = document.querySelector('[data-exception-action-status]');
        document.querySelectorAll('[data-exception-state-form]').forEach(function (form) {
            const toggle = form.querySelector('[data-exception-state-toggle]');
            const label = form.querySelector('[data-switch-label]');
            const state = form.querySelector('[data-exception-state-value]');
            if (!toggle || !label || !state) {
                return;
            }

            function sendState(confirmarConflictos) {
                return fetch('/api/configuracion/horarios/excepciones/estado', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        id: Number(form.querySelector('[name="id"]').value || 0),
                        activo: toggle.checked,
                        confirmar_conflictos: confirmarConflictos
                    })
                }).then(function (response) {
                    return jsonResponse(response).then(function (data) {
                        return { response: response, data: data };
                    });
                });
            }

            function applyState(confirmarConflictos, restoreState) {
                return sendState(confirmarConflictos).then(function (result) {
                    if (
                        result.response.status === 409
                        && result.data.codigo === 'RESERVACIONES_AFECTADAS'
                        && result.data.requiere_confirmacion === true
                        && !confirmarConflictos
                    ) {
                        toggle.disabled = false;
                        if (!window.ConfirmationModal) return false;
                        return window.ConfirmationModal.get().open({
                            variant: 'warning',
                            eyebrow: 'Horario de operación',
                            title: 'Confirmar cambio de horario',
                            description: 'Este cambio afecta ' + Number(result.data.reservaciones_afectadas || 0) + ' reservaciones.',
                            consequence: 'Ninguna será cancelada automáticamente. El seguimiento quedará disponible para preparar avisos y atender los casos afectados.',
                            secondaryLabel: 'Revisar el horario',
                            primaryLabel: 'Aplicar cambio',
                            onPrimary: function () { return applyState(true, restoreState); },
                            onSecondary: restoreState
                        });
                    }
                    if (!result.response.ok || !result.data || result.data.ok !== true) {
                        throw new Error(result.data.mensaje || 'No fue posible actualizar la excepción.');
                    }
                    window.location.assign('/admin/configuracion/horarios?resultado=estado_actualizado'
                        + (result.data.impacto_id ? '&impacto_id=' + encodeURIComponent(String(result.data.impacto_id)) : ''));
                    return true;
                });
            }

            toggle.addEventListener('change', function () {
                const restoreState = function () {
                    toggle.checked = !toggle.checked;
                    label.textContent = toggle.checked ? 'Activa' : 'Inactiva';
                    state.value = toggle.checked ? '1' : '0';
                    toggle.disabled = false;
                };
                label.textContent = toggle.checked ? 'Activa' : 'Inactiva';
                state.value = toggle.checked ? '1' : '0';
                toggle.disabled = true;
                applyState(false, restoreState).catch(function (error) {
                    restoreState();
                    setStatus(actionStatus, error.message || 'No fue posible actualizar la excepción.', 'error');
                });
            });
        });
    }

    function initAnnouncement() {
        const form = document.querySelector('[data-announcement-form]');
        if (!form) {
            return;
        }

        const active = form.querySelector('[data-announcement-active]');
        const message = form.querySelector('[data-announcement-message]');
        // El tipo pasó de <select> a un grupo de radios: hay N controles y el
        // que manda es el marcado, no el primero del DOM.
        const typeInputs = Array.from(form.querySelectorAll('[data-announcement-type]'));
        function tipoActual() {
            const marcado = typeInputs.find(function (input) { return input.checked; });
            return marcado ? marcado.value : '';
        }

        /*
         * Un mensaje escrito por una persona no se pisa nunca. El placeholder
         * por tipo existía, pero con un anuncio ya guardado el campo llegaba
         * lleno y no se veía; y con el campo vacío obligaba a redactar desde
         * cero. Al cambiar de tipo se escribe su frase de ejemplo mientras el
         * usuario no haya tocado el campo.
         */
        let mensajeEditadoAMano = Boolean(message && message.value.trim());
        if (message) {
            message.addEventListener('input', function () {
                mensajeEditadoAMano = true;
            });
        }
        const start = form.querySelector('[data-announcement-start]');
        const end = form.querySelector('[data-announcement-end]');
        const linkText = form.querySelector('[data-announcement-link-text]');
        const linkUrl = form.querySelector('[data-announcement-link-url]');
        const counter = form.querySelector('[data-announcement-counter]');
        const status = form.querySelector('[data-announcement-status]');
        const preview = document.querySelector('[data-announcement-preview]');
        const previewState = document.querySelector('[data-preview-state]');
        const previewTypeLabel = preview.querySelector('[data-preview-type-label]');
        const previewMessage = preview.querySelector('[data-preview-message]');
        const previewLink = preview.querySelector('[data-preview-link]');
        const previewLinkLabel = preview.querySelector('[data-preview-link-label]');
        const previewIcon = preview.querySelector('[data-preview-icon]');
        const purpose = document.querySelector('[data-announcement-purpose]');
        const presentation = document.querySelector('[data-announcement-presentation]');
        const example = document.querySelector('[data-announcement-example]');
        const exampleBanner = document.querySelector('[data-announcement-example-banner]');
        const exampleTypeLabel = document.querySelector('[data-announcement-example-type]');
        const exampleIcon = document.querySelector('[data-announcement-example-icon]');
        const useExample = document.querySelector('[data-announcement-use-example]');

        // Fondo de la vista previa. El landing es oscuro, pero el panel puede
        // estar en tema claro: sin alternar, el acento se juzga sobre un fondo
        // que el comensal nunca ve.
        const previewStage = document.querySelector('[data-preview-stage]');
        document.querySelectorAll('[data-preview-theme]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (previewStage) {
                    previewStage.dataset.tema = btn.dataset.previewTheme;
                }
                document.querySelectorAll('[data-preview-theme]').forEach(function (otro) {
                    const activo = otro === btn;
                    otro.classList.toggle('is-active', activo);
                    otro.setAttribute('aria-pressed', activo ? 'true' : 'false');
                });
            });
        });

        let announcementTypes = {};

        try {
            announcementTypes = JSON.parse(form.dataset.announcementTypes || '{}');
        } catch (error) {
            announcementTypes = {};
        }

        const defaultType = Object.keys(announcementTypes)[0] || 'evento';

        function initDateTimeParts(kind, hiddenField) {
            const container = form.querySelector('[data-announcement-datetime="' + kind + '"]');
            if (!container || !hiddenField) {
                return null;
            }

            const dateRoot = container.querySelector('[data-reservation-date-picker]');
            const timeRoot = container.querySelector('[data-reservation-time-picker]');
            const dateInput = dateRoot ? dateRoot.querySelector('[data-date-input]') : null;
            const timeInput = timeRoot ? timeRoot.querySelector('[data-time-input]') : null;
            const clearButton = container.querySelector('[data-announcement-datetime-clear]');
            const minimumDate = dateRoot ? dateRoot.getAttribute('data-min-date') || '' : '';
            const datePicker = dateRoot && window.createReservationDatePicker
                ? window.createReservationDatePicker({
                    root: dateRoot,
                    minDate: minimumDate,
                    today: dateRoot.getAttribute('data-today-date'),
                    allowPast: false,
                    initialValue: dateInput ? dateInput.value : ''
                })
                : null;
            const timePicker = initStaticTimePicker(timeInput);

            function sync() {
                const dateValue = dateInput ? dateInput.value : '';
                const timeValue = timeInput ? timeInput.value : '';
                hiddenField.value = dateValue || timeValue
                    ? dateValue + (timeValue ? 'T' + timeValue : '')
                    : '';
                if (clearButton) {
                    clearButton.hidden = !dateValue && !timeValue;
                }
            }

            function handleChange() {
                sync();
                setFieldError(hiddenField, '');
                updatePreview();
                setStatus(status, '', null);
            }

            if (dateInput) {
                dateInput.addEventListener('reservation:datechange', handleChange);
            }
            if (timeInput) {
                timeInput.addEventListener('reservation:timechange', handleChange);
            }
            if (clearButton) {
                clearButton.addEventListener('click', function () {
                    if (datePicker) {
                        datePicker.setValue('', true);
                    } else if (dateInput) {
                        dateInput.value = '';
                    }
                    if (timePicker) {
                        timePicker.setValue('', true);
                    } else if (timeInput) {
                        timeInput.value = '';
                    }
                    handleChange();
                });
            }

            sync();
            return {
                sync: sync,
                isPartial: function () {
                    return Boolean(dateInput && dateInput.value) !== Boolean(timeInput && timeInput.value);
                },
                isBeforeMinimum: function () {
                    return Boolean(dateInput && dateInput.value && minimumDate && dateInput.value < minimumDate);
                }
            };
        }

        const startParts = initDateTimeParts('start', start);
        const endParts = initDateTimeParts('end', end);

        function isAllowedUrl(value) {
            if (/^\/(?!\/)[^\s\\]*$/.test(value)) {
                return true;
            }

            try {
                const parsed = new URL(value);
                return parsed.protocol === 'http:' || parsed.protocol === 'https:';
            } catch (error) {
                return false;
            }
        }

        function containsHtml(value) {
            return /<[^>]*>/.test(value) || value.includes('\0');
        }

        function validateLinkPair(clearErrors) {
            const hasText = linkText.value.trim() !== '';
            const hasUrl = linkUrl.value.trim() !== '';

            linkText.required = hasUrl;
            linkUrl.required = hasText;
            if (clearErrors !== false) {
                setFieldError(linkText, '');
                setFieldError(linkUrl, '');
            }

            if (hasText && !hasUrl) {
                setFieldError(linkUrl, 'Ingresa también la URL del enlace.');
                return false;
            }
            if (hasUrl && !hasText) {
                setFieldError(linkText, 'Ingresa también el texto del enlace.');
                return false;
            }

            return true;
        }

        function updatePreview() {
            const tipo = tipoActual();
            const selectedType = Object.prototype.hasOwnProperty.call(announcementTypes, tipo)
                ? tipo
                : defaultType;
            const selectedConfig = announcementTypes[selectedType] || {};
            const trimmedLinkText = linkText.value.trim();
            const trimmedLinkUrl = linkUrl.value.trim();
            counter.textContent = message.value.length + ' / 255';
            message.required = active.checked;
            preview.dataset.type = selectedType;
            Object.keys(announcementTypes).forEach(function (typeName) {
                preview.classList.remove('hero-announcement--' + typeName);
                if (exampleBanner) {
                    exampleBanner.classList.remove('hero-announcement--' + typeName);
                }
            });
            preview.classList.add('hero-announcement--' + selectedType);
            preview.style.setProperty('--announcement-accent', selectedConfig.acento || '#9fc2c5');
            previewTypeLabel.textContent = selectedConfig.etiqueta || 'Evento';
            if (previewIcon) {
                previewIcon.innerHTML = selectedConfig.icono || '';
            }
            if (purpose) {
                purpose.textContent = selectedConfig.descripcion || '';
            }
            if (presentation) {
                presentation.textContent = selectedConfig.presentacion === 'discreto'
                    ? 'Se muestra como aviso discreto en una esquina y desaparece solo a los 8 segundos.'
                    : 'Se muestra como diálogo centrado y espera a que el visitante lo cierre.';
            }
            if (example) {
                example.textContent = selectedConfig.ejemplo || '';
            }
            if (exampleBanner) {
                exampleBanner.dataset.type = selectedType;
                exampleBanner.classList.add('hero-announcement--' + selectedType);
                exampleBanner.style.setProperty('--announcement-accent', selectedConfig.acento || '#9fc2c5');
            }
            if (exampleTypeLabel) {
                exampleTypeLabel.textContent = selectedConfig.etiqueta || 'Evento';
            }
            if (exampleIcon) {
                exampleIcon.innerHTML = selectedConfig.icono || '';
            }
            message.placeholder = selectedConfig.placeholder || 'Cuéntale al comensal qué pasa y cuándo.';
            // El ejemplo entra sólo si el campo sigue virgen: así el tipo elegido
            // se nota de inmediato sin borrar lo que alguien haya redactado.
            if (!mensajeEditadoAMano && selectedConfig.ejemplo) {
                message.value = selectedConfig.ejemplo;
            }
            linkText.placeholder = selectedConfig.texto_enlace || '';
            previewState.textContent = active.checked ? 'Activo' : 'Inactivo · no será visible';
            previewState.classList.toggle('is-live', active.checked);
            previewMessage.textContent = message.value.trim() || 'Escribe un mensaje para ver la vista previa.';

            const showLink = trimmedLinkText !== ''
                && trimmedLinkUrl !== ''
                && isAllowedUrl(trimmedLinkUrl);
            previewLink.hidden = !showLink;
            preview.classList.toggle('hero-announcement--has-link', showLink);
            preview.classList.toggle('hero-announcement--without-link', !showLink);
            previewLinkLabel.textContent = trimmedLinkText || 'Ver más';
            previewLink.href = showLink ? trimmedLinkUrl : '#';

            if (showLink && /^https?:\/\//i.test(trimmedLinkUrl)) {
                previewLink.target = '_blank';
                previewLink.rel = 'noopener noreferrer';
            } else {
                previewLink.removeAttribute('target');
                previewLink.removeAttribute('rel');
            }
        }

        if (useExample) {
            useExample.addEventListener('click', function () {
                const selectedConfig = announcementTypes[tipoActual()] || announcementTypes[defaultType] || {};
                if (!selectedConfig.ejemplo) {
                    return;
                }
                message.value = selectedConfig.ejemplo;
                message.dispatchEvent(new Event('input', { bubbles: true }));
                message.focus();
            });
        }

        function validate() {
            [message, start, end, linkText, linkUrl].forEach(function (field) {
                setFieldError(field, '');
            });
            let valid = true;

            if (active.checked && message.value.trim() === '') {
                setFieldError(message, 'El mensaje es obligatorio cuando el anuncio está activo.');
                valid = false;
            } else if (message.value.length > 255) {
                setFieldError(message, 'El mensaje no puede superar 255 caracteres.');
                valid = false;
            } else if (containsHtml(message.value)) {
                setFieldError(message, 'El mensaje debe contener únicamente texto, sin etiquetas HTML.');
                valid = false;
            }

            const startIsPartial = Boolean(startParts && startParts.isPartial());
            const endIsPartial = Boolean(endParts && endParts.isPartial());
            const startIsBeforeMinimum = Boolean(startParts && startParts.isBeforeMinimum());
            const endIsBeforeMinimum = Boolean(endParts && endParts.isBeforeMinimum());
            if (startIsBeforeMinimum) {
                setFieldError(start, 'La fecha de inicio no puede ser anterior al día actual.');
                valid = false;
            } else if (startIsPartial) {
                setFieldError(start, 'Selecciona fecha y hora para programar el inicio.');
                valid = false;
            }
            if (endIsBeforeMinimum) {
                setFieldError(end, 'La fecha de finalización no puede ser anterior al día actual.');
                valid = false;
            } else if (endIsPartial) {
                setFieldError(end, 'Selecciona fecha y hora para programar la finalización.');
                valid = false;
            } else if (!startIsPartial && start.value && end.value && new Date(end.value) <= new Date(start.value)) {
                setFieldError(end, 'La finalización debe ser posterior al inicio.');
                valid = false;
            } else if (active.checked && end.value && new Date(end.value) < new Date()) {
                setFieldError(end, 'La finalización no puede estar en el pasado al activar el anuncio.');
                valid = false;
            }

            if (!validateLinkPair()) {
                valid = false;
            }
            if (linkText.value.length > 80) {
                setFieldError(linkText, 'El texto del enlace no puede superar 80 caracteres.');
                valid = false;
            } else if (containsHtml(linkText.value)) {
                setFieldError(linkText, 'El texto del enlace debe contener únicamente texto.');
                valid = false;
            }
            if (linkUrl.value.length > 500) {
                setFieldError(linkUrl, 'La URL no puede superar 500 caracteres.');
                valid = false;
            }
            if (linkUrl.value.trim() && !isAllowedUrl(linkUrl.value.trim())) {
                setFieldError(linkUrl, 'Usa una ruta interna o una URL http/https válida.');
                valid = false;
            }
            return valid;
        }

        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            field.addEventListener('input', function () {
                updatePreview();
                validateLinkPair();
                setStatus(status, '', null);
            });
            field.addEventListener('change', function () {
                updatePreview();
                validateLinkPair();
            });
        });

        form.addEventListener('submit', function (event) {
            if (startParts) {
                startParts.sync();
            }
            if (endParts) {
                endParts.sync();
            }
            if (!validate()) {
                event.preventDefault();
                setStatus(status, 'Revisa los campos marcados antes de continuar.', 'error');
                const invalidField = form.querySelector('[aria-invalid="true"]');
                if (invalidField) {
                    focusField(invalidField);
                }
                return;
            }

            setStatus(status, 'Guardando anuncio…', 'pending');
        });
        updatePreview();
        validateLinkPair(false);
    }

    function initReports() {
        const filters = document.querySelector('[data-report-filters]');
        if (!filters) {
            return;
        }

        const search = filters.querySelector('[data-report-search]');
        const statusFilter = filters.querySelector('[data-report-status]');
        const rows = Array.from(document.querySelectorAll('[data-report-row]'));
        const empty = document.querySelector('[data-report-empty]');
        const tableWrap = document.querySelector('.admin-config-table-wrap');
        const count = document.querySelector('[data-report-count]');

        function normalize(value) {
            return (value || '').toLocaleLowerCase('es').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        function applyFilters() {
            const query = normalize(search.value.trim());
            const selectedStatus = statusFilter.value;
            let visible = 0;

            rows.forEach(function (row) {
                const matchesSearch = !query || normalize(row.dataset.search).includes(query);
                const matchesStatus = !selectedStatus || row.dataset.status === selectedStatus;
                row.hidden = !(matchesSearch && matchesStatus);
                if (!row.hidden) {
                    visible += 1;
                }
            });

            count.textContent = String(visible);
            empty.hidden = visible > 0;
            tableWrap.hidden = visible === 0;
        }

        search.addEventListener('input', applyFilters);
        statusFilter.addEventListener('change', applyFilters);
        filters.querySelector('[data-report-reset]').addEventListener('click', function () {
            search.value = '';
            statusFilter.value = '';
            applyFilters();
            search.focus();
        });
        filters.addEventListener('submit', function (event) {
            event.preventDefault();
            applyFilters();
        });

        document.querySelectorAll('[data-report-detail]').forEach(function (button) {
            button.addEventListener('click', function () {
                let report = {};
                try {
                    report = JSON.parse(button.dataset.reportDetail || '{}');
                } catch (error) {
                    report = {};
                }

                const modal = document.getElementById('report-detail-modal');
                const fields = {
                    '[data-detail-folio]': report.folio || 'Reporte',
                    '[data-detail-title]': report.titulo || 'Detalle del reporte',
                    '[data-detail-description]': report.descripcion || '—',
                    '[data-detail-module]': report.modulo || '—',
                    '[data-detail-browser]': report.navegador || '—',
                    '[data-detail-route]': report.ruta_origen || '—',
                    '[data-detail-date]': report.fecha || '—',
                    '[data-detail-status]': report.estado || '—'
                };
                Object.entries(fields).forEach(function ([selector, value]) {
                    const element = modal.querySelector(selector);
                    if (element) {
                        element.textContent = value;
                    }
                });

                // Abrir la pantalla donde se reportó el fallo. Solo rutas
                // internas: el servidor ya descarta cualquier otra cosa al
                // guardar, y aquí se vuelve a comprobar antes de ofrecerla.
                const routeLink = modal.querySelector('[data-detail-route-link]');
                if (routeLink) {
                    const ruta = String(report.ruta_origen || '');
                    const interna = /^\/[^\s]*$/.test(ruta);
                    routeLink.hidden = !interna;
                    if (interna) {
                        routeLink.href = ruta;
                    }
                }

                const idInput = modal.querySelector('[data-detail-id]');
                if (idInput) {
                    idInput.value = report.id || '';
                }

                document.dispatchEvent(new CustomEvent('admin:open-modal', { detail: { id: 'report-detail-modal', trigger: button } }));
            });
        });

        applyFilters();
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (!document.querySelector('[data-configuration-page]')) {
            return;
        }

        initSchedule();
        initExceptionForm();
        initExceptionDelete();
        initExceptionStateToggles();
        initAnnouncement();
        initReports();
    });
})();
