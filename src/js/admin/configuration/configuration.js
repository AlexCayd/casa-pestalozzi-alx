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
            autoLoad: false
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

    function initSchedule() {
        const form = document.querySelector('[data-schedule-form]');
        if (!form) {
            return;
        }

        const unsaved = document.querySelector('[data-unsaved-status]');
        const status = form.querySelector('[data-schedule-status]');
        const submitButton = form.querySelector('[data-schedule-validate]');
        const unsavedModal = document.getElementById('schedule-unsaved-modal');
        const leaveLink = unsavedModal ? unsavedModal.querySelector('[data-unsaved-leave]') : null;
        const initialDirty = form.dataset.initialDirty === '1';
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

        function renderDirtyState() {
            unsaved.textContent = hasUnsavedChanges ? 'Cambios sin guardar' : 'Horarios actualizados';
            unsaved.classList.toggle('is-dirty', hasUnsavedChanges);
            submitButton.disabled = !hasUnsavedChanges || saving;
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
            fields.forEach(function (field) {
                field.disabled = !toggle.checked;
                field.required = toggle.checked;
                if (field._reservationTimePicker) {
                    field._reservationTimePicker.setDisabled(!toggle.checked);
                }
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

            let valid = true;
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
            return valid;
        }

        form.querySelectorAll('[data-schedule-row]').forEach(function (row) {
            const toggle = row.querySelector('[data-schedule-toggle]');
            const timeFields = Array.from(row.querySelectorAll('[data-schedule-open], [data-schedule-close]'));
            timeFields.forEach(initStaticTimePicker);
            toggle.addEventListener('change', function () {
                updateRow(row);
                updateDirtyState();
            });
            timeFields.forEach(function (input) {
                input.addEventListener('reservation:timechange', function () {
                    validateRow(row);
                    updateDirtyState();
                });
            });
            updateRow(row);
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

        form.addEventListener('submit', function (event) {
            if (saving || !hasUnsavedChanges) {
                event.preventDefault();
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
                event.preventDefault();
                setStatus(status, 'Revisa los horarios marcados antes de continuar.', 'error');
                const invalidField = form.querySelector('[aria-invalid="true"]');
                if (invalidField) {
                    focusField(invalidField);
                }
                return;
            }
            saving = true;
            submitButton.disabled = true;
            setStatus(status, 'Guardando horarios…', 'pending');
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

        form.addEventListener('submit', function (event) {
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
            setStatus(status, 'Guardando excepción…', 'pending');
        });

        if (document.querySelector('[data-open-exception-modal]')) {
            document.dispatchEvent(new CustomEvent('admin:open-modal', {
                detail: { id: 'schedule-exception-modal' }
            }));
        }
    }

    function initExceptionDelete() {
        const modal = document.getElementById('schedule-exception-delete-modal');
        if (!modal) {
            return;
        }

        const form = modal.querySelector('[data-exception-delete-form]');
        const id = form.querySelector('[data-exception-delete-id]');
        const type = modal.querySelector('[data-exception-delete-type]');
        const date = modal.querySelector('[data-exception-delete-date]');
        const reason = modal.querySelector('[data-exception-delete-reason]');
        const reasonWrap = modal.querySelector('[data-exception-delete-reason-wrap]');
        const submit = form.querySelector('[data-exception-delete-submit]');
        const submitLabel = submit.textContent;
        let submitting = false;

        document.querySelectorAll('[data-exception-delete]').forEach(function (button) {
            button.addEventListener('click', function () {
                let data = {};
                try {
                    data = JSON.parse(button.dataset.exceptionDelete || '{}');
                } catch (error) {
                    data = {};
                }

                id.value = Number.isInteger(Number(data.id)) && Number(data.id) > 0 ? String(data.id) : '';
                type.textContent = data.tipo_nombre || 'la excepción';
                date.textContent = data.fecha || 'la fecha seleccionada';
                reason.textContent = data.motivo || '';
                reasonWrap.hidden = !data.motivo;
                submitting = false;
                submit.disabled = false;
                submit.textContent = submitLabel;

                document.dispatchEvent(new CustomEvent('admin:open-modal', {
                    detail: { id: 'schedule-exception-delete-modal', trigger: button }
                }));
            });
        });

        form.addEventListener('submit', function (event) {
            if (!id.value || submitting) {
                event.preventDefault();
                return;
            }

            submitting = true;
            submit.disabled = true;
            submit.textContent = 'Eliminando…';
        });
    }

    function initExceptionStateToggles() {
        document.querySelectorAll('[data-exception-state-form]').forEach(function (form) {
            const toggle = form.querySelector('[data-exception-state-toggle]');
            const label = form.querySelector('[data-switch-label]');
            const state = form.querySelector('[data-exception-state-value]');
            if (!toggle || !label || !state) {
                return;
            }

            toggle.addEventListener('change', function () {
                label.textContent = toggle.checked ? 'Activa' : 'Inactiva';
                state.value = toggle.checked ? '1' : '0';
                toggle.disabled = true;
                form.submit();
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
        const type = form.querySelector('[data-announcement-type]');
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
        const example = document.querySelector('[data-announcement-example]');
        const exampleBanner = document.querySelector('[data-announcement-example-banner]');
        const exampleTypeLabel = document.querySelector('[data-announcement-example-type]');
        const exampleIcon = document.querySelector('[data-announcement-example-icon]');
        const useExample = document.querySelector('[data-announcement-use-example]');
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
            const selectedType = Object.prototype.hasOwnProperty.call(announcementTypes, type.value)
                ? type.value
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
            message.placeholder = selectedConfig.placeholder || 'Escribe el mensaje del anuncio.';
            linkText.placeholder = selectedConfig.texto_enlace || '';
            previewState.textContent = active.checked ? 'Activo' : 'Inactivo · no será visible';
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
                const selectedConfig = announcementTypes[type.value] || announcementTypes[defaultType] || {};
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
