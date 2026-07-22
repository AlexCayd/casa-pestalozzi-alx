/**
 * Admin create/edit reservation form.
 */
(function () {
    function normalizeHour(value) {
        var match = String(value || '').match(/^([01]\d|2[0-3]):([0-5]\d)/);
        return match ? match[1] + ':' + match[2] : '';
    }

    function formValue(form, name) {
        return form.elements[name] ? form.elements[name].value : '';
    }

    function setFormValue(form, name, value) {
        if (form.elements[name]) {
            form.elements[name].value = value == null ? '' : String(value);
        }
    }

    function initAdminReservationForms() {
        document.querySelectorAll('[data-admin-reservation-form]').forEach(function (form) {
            var mode = form.getAttribute('data-form-mode') || 'editar';
            var editable = form.getAttribute('data-form-editable') === '1';
            var card = form.closest('[data-reservation-form-card]') || form;
            var detailRoot = form.closest('[data-reservation-detail-root]');
            var editButton = card.querySelector('[data-form-edit]');
            var cancelButton = form.querySelector('[data-form-cancel]');
            var saveButton = form.querySelector('[data-form-save]');
            var editBanner = card.querySelector('[data-edit-mode-banner]');
            var dateRoot = form.querySelector('[data-reservation-date-picker]');
            var timeRoot = form.querySelector('[data-reservation-time-picker]');
            var dateInput = form.querySelector('[data-date-input]');
            var timeInput = form.querySelector('[data-time-input]');
            var timeStatus = form.querySelector('[data-time-status]');
            var editableControls = Array.prototype.slice.call(form.querySelectorAll('[data-reservation-control]'));
            var initialDate = form.getAttribute('data-initial-date') || (dateInput ? dateInput.value : '');
            var initialTime = normalizeHour(form.getAttribute('data-initial-time') || (timeInput ? timeInput.value : ''));
            var originalValues = {
                nombre: formValue(form, 'nombre'),
                email: formValue(form, 'email'),
                fecha: initialDate,
                hora: initialTime,
                comensales: formValue(form, 'comensales'),
                comentario_admin: formValue(form, 'comentario_admin')
            };
            var hasTables = form.getAttribute('data-has-tables') === '1';
            var isEditing = mode === 'crear' || form.classList.contains('is-editing');
            var isSubmitting = false;
            var isLoadingSchedules = false;
            var datePicker = null;
            var timePicker = null;
            var saveLabel = saveButton ? saveButton.textContent : 'Guardar cambios';

            if (dateRoot && window.createReservationDatePicker) {
                datePicker = window.createReservationDatePicker({
                    root: dateRoot,
                    minDate: dateRoot.getAttribute('data-min-date'),
                    initialValue: originalValues.fecha,
                    enabledWeekdays: dateRoot.getAttribute('data-enabled-weekdays')
                });
            }

            if (timeRoot && window.createReservationTimePicker) {
                timePicker = window.createReservationTimePicker({
                    root: timeRoot,
                    status: timeStatus,
                    endpoint: timeRoot.getAttribute('data-schedules-endpoint'),
                    initialDate: originalValues.fecha,
                    initialTime: originalValues.hora,
                    autoLoad: mode === 'crear' || isEditing
                });
            }

            function hasValidDate() {
                return !dateInput || /^\d{4}-\d{2}-\d{2}$/.test(dateInput.value || '');
            }

            function hasValidTime() {
                return !timeInput || normalizeHour(timeInput.value) !== '';
            }

            function clearTemporaryErrors() {
                form.querySelectorAll('.reservation-detail-field-msg').forEach(function (message) {
                    message.textContent = '';
                    message.classList.remove('show');
                });
            }

            function setOperationalActionsDisabled(disabled) {
                if (!detailRoot) return;

                detailRoot.querySelectorAll('[data-reservation-operational-control]').forEach(function (control) {
                    var isLink = control.tagName === 'A';
                    control.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                    control.classList.toggle('is-disabled-by-editing', disabled);

                    if (isLink) {
                        if (disabled) {
                            control.setAttribute('tabindex', '-1');
                        } else {
                            control.removeAttribute('tabindex');
                        }
                    } else {
                        control.disabled = disabled;
                    }
                });
            }

            function updateSaveState() {
                if (!saveButton) return;

                var shouldShow = isEditing && editable;
                saveButton.hidden = !shouldShow;
                saveButton.disabled = !shouldShow || isSubmitting || isLoadingSchedules || !hasValidDate() || !hasValidTime();
            }

            function setEditingMode(enabled) {
                isEditing = mode === 'crear' ? true : Boolean(enabled && editable);

                form.classList.toggle('is-editing', isEditing);
                card.classList.toggle('is-editing', isEditing);
                if (detailRoot) {
                    detailRoot.classList.toggle('is-editing', isEditing);
                }

                editableControls.forEach(function (control) {
                    control.disabled = !isEditing;
                    control.setAttribute('aria-disabled', isEditing ? 'false' : 'true');
                });

                if (datePicker) datePicker.setDisabled(!isEditing);
                if (timePicker) timePicker.setDisabled(!isEditing);
                if (editButton) {
                    editButton.hidden = isEditing || !editable;
                    editButton.setAttribute('aria-expanded', isEditing ? 'true' : 'false');
                }
                if (cancelButton) {
                    cancelButton.hidden = mode === 'crear' || !isEditing;
                }
                if (editBanner) {
                    editBanner.hidden = mode !== 'editar' || !isEditing;
                }

                setOperationalActionsDisabled(isEditing && mode === 'editar');
                updateSaveState();
            }

            function loadSchedules(fecha, preferredHour) {
                if (!timePicker) {
                    updateSaveState();
                    return Promise.resolve([]);
                }

                isLoadingSchedules = true;
                updateSaveState();

                return timePicker.loadForDate(fecha, preferredHour).then(function (hours) {
                    isLoadingSchedules = false;
                    updateSaveState();
                    return hours;
                }, function (error) {
                    isLoadingSchedules = false;
                    updateSaveState();
                    throw error;
                });
            }

            function restoreOriginalValues() {
                setFormValue(form, 'nombre', originalValues.nombre);
                setFormValue(form, 'email', originalValues.email);
                setFormValue(form, 'comensales', originalValues.comensales);
                setFormValue(form, 'comentario_admin', originalValues.comentario_admin);

                if (datePicker) {
                    datePicker.setValue(originalValues.fecha, true);
                } else if (dateInput) {
                    dateInput.value = originalValues.fecha;
                }

                if (timeInput) {
                    timeInput.value = originalValues.hora;
                }

                clearTemporaryErrors();
                loadSchedules(originalValues.fecha, originalValues.hora).then(function () {
                    setEditingMode(false);
                });
            }

            if (dateInput && timePicker) {
                dateInput.addEventListener('reservation:datechange', function (event) {
                    if (!isEditing) return;
                    var fecha = (event.detail && event.detail.fecha) || dateInput.value;
                    loadSchedules(fecha, '');
                });
            }

            if (timeInput) {
                timeInput.addEventListener('reservation:timechange', updateSaveState);
            }

            if (editButton) {
                editButton.addEventListener('click', function () {
                    setEditingMode(true);
                    loadSchedules(dateInput ? dateInput.value : originalValues.fecha, timeInput ? timeInput.value : originalValues.hora);

                    var first = form.querySelector('[name="nombre"]');
                    if (first) {
                        first.focus();
                    }
                });
            }

            if (cancelButton) {
                cancelButton.addEventListener('click', function () {
                    restoreOriginalValues();
                });
            }

            form.addEventListener('input', updateSaveState);

            form.addEventListener('submit', function (event) {
                if (isSubmitting) {
                    event.preventDefault();
                    return;
                }

                if (mode === 'editar' && !isEditing) {
                    event.preventDefault();
                    setEditingMode(true);
                    return;
                }

                if (!hasValidDate() || !hasValidTime()) {
                    event.preventDefault();
                    if (timeStatus && !hasValidTime()) {
                        timeStatus.textContent = 'Elige un horario disponible.';
                        timeStatus.classList.add('show');
                    }
                    updateSaveState();
                    return;
                }

                var changedOperational = mode === 'editar' && (
                    (dateInput && dateInput.value !== originalValues.fecha) ||
                    (timeInput && normalizeHour(timeInput.value) !== originalValues.hora) ||
                    String(formValue(form, 'comensales')) !== String(originalValues.comensales)
                );

                if (changedOperational && hasTables && !form.getAttribute('data-operational-warning-accepted')) {
                    if (!window.confirm('Cambiar fecha, hora o comensales puede liberar las mesas asignadas si ya no son validas.')) {
                        event.preventDefault();
                        return;
                    }
                    form.setAttribute('data-operational-warning-accepted', '1');
                }

                isSubmitting = true;
                if (saveButton) {
                    saveButton.disabled = true;
                    saveButton.textContent = 'Guardando...';
                }
            });

            if (detailRoot) {
                detailRoot.addEventListener('click', function (event) {
                    var operationalLink = event.target.closest('a[data-reservation-operational-control]');
                    if (isEditing && operationalLink) {
                        event.preventDefault();
                    }
                });

                detailRoot.addEventListener('submit', function (event) {
                    if (isEditing && event.target.matches('[data-reservation-operational-action]')) {
                        event.preventDefault();
                    }
                }, true);
            }

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && mode === 'editar' && isEditing && !isSubmitting) {
                    restoreOriginalValues();
                }
            });

            setEditingMode(isEditing);
        });
    }

    document.addEventListener('DOMContentLoaded', initAdminReservationForms);
})();
