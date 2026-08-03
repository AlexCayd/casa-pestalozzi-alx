/**
 * Admin create/edit reservation form.
 */
(function () {
    var formState = window.ReservationFormState || {};

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
            var modal = form.closest('[data-operation-create-modal]');
            var externalActions = modal ? modal.querySelector('[data-operation-create-footer]') : null;
            var editButton = card.querySelector('[data-form-edit]');
            var cancelButton = form.querySelector('[data-form-cancel]') || (externalActions && externalActions.querySelector('[data-form-cancel]'));
            var saveButton = form.querySelector('[data-form-save]') || (externalActions && externalActions.querySelector('[data-form-save]'));
            var editBanner = card.querySelector('[data-edit-mode-banner]');
            var dateRoot = form.querySelector('[data-reservation-date-picker]');
            var timeRoot = form.querySelector('[data-reservation-time-picker]');
            var dateInput = form.querySelector('[data-date-input]');
            var timeInput = form.querySelector('[data-time-input]');
            var timeStatus = form.querySelector('[data-time-status]');
            var capacitySummary = form.querySelector('[data-reservation-capacity-summary]');
            var capacityTotal = form.querySelector('[data-capacity-total]');
            var capacityReal = form.querySelector('[data-capacity-real]');
            var capacityProjected = form.querySelector('[data-capacity-projected]');
            var capacityEstimated = form.querySelector('[data-capacity-estimated]');
            var capacityWarning = form.querySelector('[data-capacity-warning]');
            var contactSelect = form.querySelector('select[data-contact-type]:not([data-contact-type-locked])');
            var contactTypeEmpty = form.querySelector('[data-contact-type-empty]');
            var contactInput = form.querySelector('input[name="contacto"]');
            var contactLabel = form.querySelector('[data-contact-field-label]');
            var contactHelp = form.querySelector('[data-contact-help]');
            var feedback = form.querySelector('[data-form-feedback]');
            var reservationId = parseInt(formValue(form, 'id') || '0', 10) || 0;
            var editableControls = Array.prototype.slice.call(form.querySelectorAll('[data-reservation-control]'));
            var initialDate = form.getAttribute('data-initial-date') || (dateInput ? dateInput.value : '');
            var initialTime = normalizeHour(form.getAttribute('data-initial-time') || (timeInput ? timeInput.value : ''));
            var originalValues = {
                nombre: formValue(form, 'nombre'),
                contacto_tipo: formValue(form, 'contacto_tipo'),
                contacto: formValue(form, 'contacto'),
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
            var confirmation = card.querySelector('[data-reservation-confirmation]');
            var confirmationDialog = confirmation ? confirmation.querySelector('[data-confirmation-dialog]') : null;
            var confirmationEyebrow = confirmation ? confirmation.querySelector('[data-confirmation-eyebrow]') : null;
            var confirmationTitle = confirmation ? confirmation.querySelector('[data-confirmation-title]') : null;
            var confirmationDescription = confirmation ? confirmation.querySelector('[data-confirmation-description]') : null;
            var confirmationBack = confirmation ? confirmation.querySelector('[data-confirmation-back]') : null;
            var confirmationConfirm = confirmation ? confirmation.querySelector('[data-confirmation-confirm]') : null;
            var confirmationLastFocused = null;
            var confirmationPreviousOverflow = '';
            var activeConfirmation = null;
            var saveLabel = saveButton ? saveButton.textContent : 'Guardar cambios';
            var jsonTransport = form.getAttribute('data-form-transport') === 'json';
            var contactValues = { email: '', telefono: '' };
            var activeContactType = formValue(form, 'contacto_tipo') || 'email';
            var availabilityTimer = null;
            var availabilityDetails = {};
            contactValues[activeContactType] = formValue(form, 'contacto');

            function renderCapacitySummary() {
                if (!capacitySummary) return;
                var hour = normalizeHour(timeInput ? timeInput.value : '');
                var detail = hour ? availabilityDetails[hour] : null;
                capacitySummary.hidden = !detail;
                if (!detail) {
                    if (capacityWarning) capacityWarning.hidden = true;
                    return;
                }
                if (capacityTotal) capacityTotal.textContent = String(detail.capacidad_total || 0);
                if (capacityReal) capacityReal.textContent = String(detail.capacidad_realmente_libre || 0);
                if (capacityProjected) capacityProjected.textContent = String(detail.capacidad_proyectada || 0);
                if (capacityEstimated) capacityEstimated.textContent = String(detail.capacidad_estimada_horario || 0);
                if (capacityWarning) {
                    capacityWarning.textContent = detail.advertencia ||
                        'Esta disponibilidad depende de la liberación proyectada de una mesa con ticket abierto.';
                    capacityWarning.hidden = !detail.depende_liberacion_proyectada;
                }
            }

            function fieldMessage(name) {
                return form.querySelector('[data-field-error="' + name + '"]');
            }

            function setFieldError(name, message) {
                var target = fieldMessage(name);
                var control = form.elements[name];
                message = Array.isArray(message) ? (message[0] || '') : (message || '');
                if (target) {
                    target.textContent = String(message);
                    target.classList.toggle('show', Boolean(message));
                }
                if (control) {
                    control.setAttribute('aria-invalid', message ? 'true' : 'false');
                }
            }

            function showFeedback(message, type) {
                if (!feedback) return;
                feedback.textContent = message || '';
                feedback.hidden = !message;
                feedback.classList.remove('is-success', 'is-error', 'is-warning');
                if (message) feedback.classList.add('is-' + (type || 'error'));
            }

            function clearFieldErrors() {
                form.querySelectorAll('[data-field-error]').forEach(function (target) {
                    target.textContent = '';
                    target.classList.remove('show');
                });
                form.querySelectorAll('[aria-invalid="true"]').forEach(function (control) {
                    control.setAttribute('aria-invalid', 'false');
                });
                showFeedback('', 'error');
            }

            function syncContactField(preservePrevious) {
                if (!contactInput) return;
                var selectedType = contactSelect
                    ? contactSelect.value
                    : formValue(form, 'contacto_tipo');
                var nextType = selectedType === 'telefono' ? 'telefono' : 'email';
                if (preservePrevious !== false && activeContactType !== nextType) {
                    contactValues[activeContactType] = contactInput.value;
                    contactInput.value = contactValues[nextType] || '';
                }
                activeContactType = nextType;
                var presentation = typeof formState.contactPresentation === 'function'
                    ? formState.contactPresentation(nextType)
                    : {
                        type: nextType === 'telefono' ? 'tel' : 'email',
                        autocomplete: nextType === 'telefono' ? 'tel' : 'email',
                        inputmode: nextType === 'telefono' ? 'tel' : 'email',
                        placeholder: nextType === 'telefono' ? '+52 55 1234 5678' : 'cliente@ejemplo.com',
                        label: nextType === 'telefono' ? 'Teléfono' : 'Correo electrónico',
                        help: ''
                    };
                contactInput.type = presentation.type;
                contactInput.autocomplete = presentation.autocomplete;
                contactInput.inputMode = presentation.inputmode;
                contactInput.placeholder = presentation.placeholder;
                if (contactLabel) {
                    contactLabel.firstChild.textContent = presentation.label + ' ';
                }
                if (contactHelp) contactHelp.textContent = presentation.help;
                setFieldError('contacto', '');
                setFieldError('contacto_tipo', '');
            }

            function submitAfterConfirmation() {
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    HTMLFormElement.prototype.submit.call(form);
                }
            }

            function confirmationOptions(type, detail) {
                detail = detail || {};
                if (type === 'capacity') {
                    var requested = parseInt(detail.requested || '0', 10) || 0;
                    var available = parseInt(detail.available || '0', 10) || 0;
                    return {
                        type: type,
                        eyebrow: 'Requiere asignación manual',
                        title: 'Capacidad insuficiente',
                        description: 'La reservación solicita ' + requested +
                            ' personas y sólo hay capacidad disponible para ' + available +
                            '. Puedes crearla sin mesas y completar la asignación manualmente.',
                        backLabel: 'Cancelar',
                        confirmLabel: 'Crear sin mesas',
                        focusTarget: form.elements.comensales,
                        onConfirm: function () {
                            setFormValue(form, 'permitir_capacidad_insuficiente', '1');
                            var automatic = form.querySelector('[name="asignar_automaticamente"][value="1"]');
                            if (automatic) automatic.checked = false;
                            submitAfterConfirmation();
                        }
                    };
                }
                if (type === 'contact') {
                    return {
                        type: type,
                        eyebrow: 'Contacto opcional',
                        title: 'Crear reservación sin contacto',
                        description: 'Esta reservación no tendrá un correo o teléfono asociado. No será posible contactar al cliente desde el sistema.',
                        backLabel: 'Volver y agregar contacto',
                        confirmLabel: 'Crear sin contacto',
                        focusTarget: contactInput,
                        onConfirm: function () {
                            form.setAttribute('data-contact-warning-accepted', '1');
                            setFormValue(form, 'confirmar_sin_contacto', '1');
                            submitAfterConfirmation();
                        }
                    };
                }
                if (type === 'operational-edit') {
                    return {
                        type: type,
                        eyebrow: 'Cambio operativo',
                        title: 'Revalidar mesas asignadas',
                        description: 'Cambiar fecha, hora o comensales puede liberar las mesas actuales si dejan de ser válidas. El servidor volverá a comprobar capacidad y ocupación antes de guardar.',
                        backLabel: 'Seguir editando',
                        confirmLabel: 'Revalidar y guardar',
                        focusTarget: saveButton,
                        onConfirm: function () {
                            form.setAttribute('data-operational-warning-accepted', '1');
                            submitAfterConfirmation();
                        }
                    };
                }

                if (type === 'contact-edit') {
                    var contactType = detail.contactType === 'telefono' ? 'teléfono' : 'correo electrónico';
                    var isFirstContact = detail.firstContact === true;
                    var isRemovingContact = detail.removingContact === true;
                    var hasContactValue = detail.hasValue === true;
                    return {
                        type: type,
                        eyebrow: 'Cambio de contacto',
                        title: isFirstContact ? 'Agregar contacto' : (hasContactValue ? 'Guardar contacto' : 'Guardar tipo de contacto'),
                        description: isFirstContact
                            ? 'Se guardará el ' + contactType + ' del cliente con el formato normalizado por el sistema.'
                            : (isRemovingContact
                                ? 'Se quitará el valor de contacto y se conservará ' + contactType + ' como tipo seleccionado.'
                                : (hasContactValue
                                    ? 'Se actualizarán el tipo y el valor de contacto como ' + contactType + '.'
                                    : 'Se guardará ' + contactType + ' como tipo de contacto preferido. El dato podrá agregarse después.')),
                        backLabel: 'Seguir editando',
                        confirmLabel: isFirstContact ? 'Agregar contacto' : (hasContactValue ? 'Guardar contacto' : 'Guardar tipo'),
                        focusTarget: contactInput,
                        onConfirm: function () {
                            form.setAttribute('data-contact-confirmation-accepted', '1');
                            submitAfterConfirmation();
                        }
                    };
                }

                return {
                    type: type || 'custom',
                    eyebrow: detail.eyebrow || 'Confirmación',
                    title: detail.title || 'Confirma esta acción',
                    description: detail.description || '',
                    backLabel: detail.backLabel || 'Cancelar',
                    confirmLabel: detail.confirmLabel || 'Continuar',
                    focusTarget: detail.focusTarget || null,
                    onBack: typeof detail.onBack === 'function' ? detail.onBack : null,
                    onConfirm: typeof detail.onConfirm === 'function' ? detail.onConfirm : null
                };
            }

            function closeConfirmation(restoreFocus) {
                if (!confirmation) return;

                confirmation.classList.remove('is-open');
                confirmation.hidden = true;
                document.body.style.overflow = confirmationPreviousOverflow;
                var current = activeConfirmation;
                activeConfirmation = null;

                if (restoreFocus !== false) {
                    var target = current && current.focusTarget
                        ? current.focusTarget
                        : confirmationLastFocused;
                    if (target && document.contains(target) && typeof target.focus === 'function') {
                        target.focus();
                    }
                }
                confirmationLastFocused = null;
            }

            function openConfirmation(type, detail) {
                if (!confirmation) return false;

                activeConfirmation = confirmationOptions(type, detail);
                confirmationLastFocused = document.activeElement;
                confirmationPreviousOverflow = document.body.style.overflow;
                if (confirmationEyebrow) confirmationEyebrow.textContent = activeConfirmation.eyebrow;
                if (confirmationTitle) confirmationTitle.textContent = activeConfirmation.title;
                if (confirmationDescription) confirmationDescription.textContent = activeConfirmation.description;
                if (confirmationBack) confirmationBack.textContent = activeConfirmation.backLabel;
                if (confirmationConfirm) confirmationConfirm.textContent = activeConfirmation.confirmLabel;
                confirmation.hidden = false;
                document.body.style.overflow = 'hidden';

                window.requestAnimationFrame(function () {
                    confirmation.classList.add('is-open');
                    var preferred = confirmationBack || confirmationDialog;
                    if (preferred) preferred.focus();
                });
                return true;
            }

            if (confirmation) {
                confirmation.querySelectorAll('[data-confirmation-close]').forEach(function (closer) {
                    closer.addEventListener('click', function () {
                        closeConfirmation(true);
                    });
                });
                if (confirmationBack) {
                    confirmationBack.addEventListener('click', function () {
                        var current = activeConfirmation;
                        closeConfirmation(true);
                        if (current && current.onBack) current.onBack();
                    });
                }
                if (confirmationConfirm) {
                    confirmationConfirm.addEventListener('click', function () {
                        var current = activeConfirmation;
                        closeConfirmation(false);
                        if (current && current.onConfirm) current.onConfirm();
                    });
                }
                document.addEventListener('keydown', function (event) {
                    if (!confirmation.hidden && event.key === 'Escape') {
                        event.preventDefault();
                        closeConfirmation(true);
                    }
                });
                form.addEventListener('reservation:capacity-warning', function (event) {
                    openConfirmation('capacity', event.detail || {});
                });
                form.addEventListener('reservation:confirmation', function (event) {
                    var detail = event.detail || {};
                    openConfirmation(detail.type || 'custom', detail);
                });

                if (confirmation.getAttribute('data-confirmation-autostart') === 'capacity') {
                    window.setTimeout(function () {
                        openConfirmation('capacity', {
                            requested: confirmation.getAttribute('data-confirmation-requested'),
                            available: confirmation.getAttribute('data-confirmation-available')
                        });
                    }, 0);
                }
            }

            var requiredFocusQueued = false;

            function markRequiredControlInvalid(control) {
                if (!control || !control.required) return;

                var field = control.closest('.reservation-detail-form__field');
                var message = field ? field.querySelector('.reservation-detail-field-msg') : null;
                if (message) {
                    message.textContent = 'Completa este campo.';
                    message.classList.add('show');
                }
                control.setAttribute('aria-invalid', 'true');

                if (!requiredFocusQueued) {
                    requiredFocusQueued = true;
                    window.setTimeout(function () {
                        if (typeof control.focus === 'function') control.focus();
                        requiredFocusQueued = false;
                    }, 0);
                }
            }

            form.addEventListener('invalid', function (event) {
                if (!event.target || event.target === contactInput) return;
                markRequiredControlInvalid(event.target);
                event.preventDefault();
            }, true);

            if (dateRoot && window.createReservationDatePicker) {
                datePicker = window.createReservationDatePicker({
                    root: dateRoot,
                    minDate: dateRoot.getAttribute('data-min-date'),
                    initialValue: originalValues.fecha,
                    enabledWeekdays: dateRoot.getAttribute('data-enabled-weekdays')
                });
                form.__reservationDatePicker = datePicker;
            }

            if (timeRoot && window.createReservationTimePicker) {
                timePicker = window.createReservationTimePicker({
                    root: timeRoot,
                    status: timeStatus,
                    endpoint: timeRoot.getAttribute('data-schedules-endpoint'),
                    initialDate: originalValues.fecha,
                    initialTime: originalValues.hora,
                    invalidateUnavailable: true,
                    autoLoad: mode === 'crear' || isEditing,
                    getQueryParams: function () {
                        var params = {
                            personas: parseInt(formValue(form, 'comensales') || '0', 10) || 1,
                            reservacion_id: reservationId || ''
                        };
                        var selectedHour = timeInput ? normalizeHour(timeInput.value) : '';
                        if (selectedHour) params.hora = selectedHour;
                        return params;
                    }
                });
                form.__reservationTimePicker = timePicker;
            }

            function hasValidDate() {
                return !dateInput || /^\d{4}-\d{2}-\d{2}$/.test(dateInput.value || '');
            }

            function hasValidTime() {
                return !timeInput || normalizeHour(timeInput.value) !== '';
            }

            function clearTemporaryErrors() {
                clearFieldErrors();
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

                if (contactSelect && contactSelect.hasAttribute('data-contact-type-edit-only')) {
                    contactSelect.hidden = !isEditing;
                    if (contactTypeEmpty) contactTypeEmpty.hidden = isEditing;
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

                fecha = String(fecha || (dateInput ? dateInput.value : '')).trim();
                if (dateInput && fecha && dateInput.value !== fecha) {
                    dateInput.value = fecha;
                }
                availabilityDetails = {};
                renderCapacitySummary();

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
                setFormValue(form, 'contacto_tipo', originalValues.contacto_tipo);
                setFormValue(form, 'contacto', originalValues.contacto);
                setFormValue(form, 'comensales', originalValues.comensales);
                setFormValue(form, 'comentario_admin', originalValues.comentario_admin);
                activeContactType = originalValues.contacto_tipo || 'email';
                contactValues = { email: '', telefono: '' };
                contactValues[activeContactType] = originalValues.contacto;
                syncContactField(false);

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
                    if (timePicker && typeof timePicker.clear === 'function') timePicker.clear(true);
                    if (timeInput) timeInput.value = '';
                    loadSchedules(fecha, '');
                });
            }

            if (timeInput) {
                timeInput.addEventListener('reservation:timechange', function () {
                    renderCapacitySummary();
                    updateSaveState();
                    if (!isLoadingSchedules && isEditing && dateInput && timeInput.value) {
                        loadSchedules(dateInput.value, timeInput.value);
                    }
                });
            }
            if (timeRoot) {
                timeRoot.addEventListener('reservation:scheduleloaded', function (event) {
                    var payload = event.detail || {};
                    var expectedDate = dateInput ? String(dateInput.value || '') : '';
                    if (payload.ok === true && String(payload.fecha || '') !== expectedDate) {
                        availabilityDetails = {};
                        renderCapacitySummary();
                        return;
                    }
                    availabilityDetails = payload.detalle_horarios || {};
                    if (!Object.keys(availabilityDetails).length) {
                        (payload.horarios || []).forEach(function (slot) {
                            if (slot && typeof slot === 'object' && slot.hora) {
                                availabilityDetails[normalizeHour(slot.hora)] = slot;
                            }
                        });
                    }
                    renderCapacitySummary();
                });
            }

            if (contactSelect) {
                contactSelect.addEventListener('change', function () {
                    syncContactField(true);
                    if (mode === 'editar') {
                        form.removeAttribute('data-contact-confirmation-accepted');
                    }
                    updateSaveState();
                });
            }
            syncContactField(false);

            var guestsInput = form.elements.comensales;
            if (guestsInput) {
                guestsInput.addEventListener('input', function () {
                    window.clearTimeout(availabilityTimer);
                    setFieldError('comensales', '');
                    if (!isEditing || !dateInput || !dateInput.value) {
                        updateSaveState();
                        return;
                    }
                    isLoadingSchedules = true;
                    updateSaveState();
                    availabilityTimer = window.setTimeout(function () {
                        loadSchedules(dateInput.value, timeInput ? timeInput.value : '');
                    }, 250);
                });
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

            form.addEventListener('input', function (event) {
                var control = event.target;
                if (mode === 'crear' && control === contactInput && String(contactInput.value || '').trim()) {
                    form.removeAttribute('data-contact-warning-accepted');
                    setFormValue(form, 'confirmar_sin_contacto', '0');
                }
                if (mode === 'editar' && control === contactInput) {
                    form.removeAttribute('data-contact-confirmation-accepted');
                }
                if (mode === 'crear' && control && ['fecha', 'hora', 'comensales', 'asignar_automaticamente'].indexOf(control.name) !== -1) {
                    setFormValue(form, 'permitir_capacidad_insuficiente', '0');
                }
                if (control && control.required) {
                    control.removeAttribute('aria-invalid');
                    var field = control.closest('.reservation-detail-form__field');
                    var message = field ? field.querySelector('.reservation-detail-field-msg') : null;
                    if (message && message.textContent === 'Completa este campo.') {
                        message.textContent = '';
                        message.classList.remove('show');
                    }
                }
                updateSaveState();
            });

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

                clearFieldErrors();
                var nombre = String(formValue(form, 'nombre') || '').trim();
                var comensales = parseInt(formValue(form, 'comensales') || '0', 10);
                var contacto = String(formValue(form, 'contacto') || '').trim();
                var contactoTipo = formValue(form, 'contacto_tipo');
                var invalidClientData = false;
                if (mode === 'editar') {
                    if (contacto && contactoTipo !== 'email' && contactoTipo !== 'telefono') {
                        setFieldError('contacto_tipo', 'Selecciona el tipo de contacto.');
                        setFieldError('contacto', 'Selecciona correo o telefono para validar el dato.');
                        invalidClientData = true;
                    } else if (contacto && contactoTipo === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contacto)) {
                        setFieldError('contacto', 'Escribe un correo electronico valido.');
                        invalidClientData = true;
                    } else if (contacto && contactoTipo === 'telefono' && !/^\+52(?:[\s().-]*\d){10}$/.test(contacto)) {
                        setFieldError('contacto', 'Usa +52 seguido de diez digitos.');
                        invalidClientData = true;
                    }
                }
                if (!nombre) {
                    setFieldError('nombre', 'Escribe un nombre para la reservación.');
                    invalidClientData = true;
                }
                if (!Number.isInteger(comensales) || comensales < 1) {
                    setFieldError('comensales', 'Indica un número válido de comensales.');
                    invalidClientData = true;
                }
                if (mode !== 'editar') {
                    if (contacto && contactoTipo === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contacto)) {
                    setFieldError('contacto', 'Escribe un correo electrónico válido.');
                    invalidClientData = true;
                }
                if (contacto && contactoTipo === 'telefono' && !/^(?:\+?52)?[\s\-().]*\d(?:[\s\-().]*\d){9}$/.test(contacto)) {
                    setFieldError('contacto', 'Escribe un teléfono mexicano válido de diez dígitos.');
                    invalidClientData = true;
                }

                }

                if (!hasValidDate() || !hasValidTime() || invalidClientData) {
                    event.preventDefault();
                    if (!hasValidDate()) {
                        setFieldError('fecha', 'Elige una fecha.');
                    }
                    if (timeStatus && !hasValidTime()) {
                        setFieldError('hora', 'Elige un horario disponible.');
                    }
                    showFeedback('Revisa los campos marcados antes de guardar.', 'error');
                    updateSaveState();
                    return;
                }

                if (
                    mode === 'crear'
                    && form.getAttribute('data-contact-warning-accepted') !== '1'
                    && String(formValue(form, 'confirmar_sin_contacto') || '') !== '1'
                    && !String(formValue(form, 'contacto') || '').trim()
                ) {
                    event.preventDefault();
                    openConfirmation('contact');
                    return;
                }

                if (mode === 'crear' && String(formValue(form, 'contacto') || '').trim()) {
                    setFormValue(form, 'confirmar_sin_contacto', '0');
                }

                var changedContact = mode === 'editar' && (
                    contacto !== String(originalValues.contacto || '').trim()
                    || contactoTipo !== originalValues.contacto_tipo
                );
                if (changedContact && !form.getAttribute('data-contact-confirmation-accepted')) {
                    event.preventDefault();
                    openConfirmation('contact-edit', {
                        contactType: contactoTipo,
                        firstContact: String(originalValues.contacto || '').trim() === '' && contacto !== '',
                        removingContact: String(originalValues.contacto || '').trim() !== '' && contacto === '',
                        hasValue: contacto !== ''
                    });
                    return;
                }

                var changedOperational = mode === 'editar' && (
                    (dateInput && dateInput.value !== originalValues.fecha) ||
                    (timeInput && normalizeHour(timeInput.value) !== originalValues.hora) ||
                    String(formValue(form, 'comensales')) !== String(originalValues.comensales)
                );

                if (changedOperational && hasTables && !form.getAttribute('data-operational-warning-accepted')) {
                    event.preventDefault();
                    openConfirmation('operational-edit');
                    return;
                }

                isSubmitting = true;
                if (saveButton) {
                    saveButton.disabled = true;
                    saveButton.textContent = 'Guardando...';
                }

                if (jsonTransport) {
                    event.preventDefault();
                    form.dispatchEvent(new CustomEvent('reservation:jsonsubmit', {
                        bubbles: true,
                        detail: { form: form }
                    }));
                }

            });

            form.addEventListener('reservation:clear-errors', clearTemporaryErrors);
            form.addEventListener('reservation:reset-submit', function () {
                isSubmitting = false;
                form.removeAttribute('data-contact-warning-accepted');
                form.removeAttribute('data-contact-confirmation-accepted');
                if (saveButton) {
                    saveButton.textContent = saveLabel;
                }
                updateSaveState();
            });

            if (jsonTransport && mode === 'editar') {
                form.addEventListener('reservation:jsonsubmit', function () {
                    var body = new FormData(form);
                    body.set('response_format', 'json');
                    fetch(form.action, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    }).then(function (response) {
                        return response.json().catch(function () {
                            return {
                                ok: false,
                                codigo: 'RESPUESTA_INVALIDA',
                                mensaje: 'El servidor devolvió una respuesta no válida.'
                            };
                        }).then(function (payload) {
                            payload.httpStatus = response.status;
                            return payload;
                        });
                    }).then(function (payload) {
                        if (!payload.ok) {
                            Object.keys(payload.fieldErrors || {}).forEach(function (field) {
                                setFieldError(field, payload.fieldErrors[field]);
                            });
                            showFeedback(
                                payload.mensaje || 'No fue posible guardar los cambios.',
                                'error'
                            );
                            return;
                        }
                        showFeedback(
                            payload.advertencia || payload.mensaje || 'Cambios guardados correctamente.',
                            payload.requiere_asignacion || payload.depende_liberacion_proyectada
                                ? 'warning'
                                : 'success'
                        );
                        if (payload.redirect) {
                            window.setTimeout(function () {
                                window.location.assign(payload.redirect);
                            }, 250);
                        }
                    }).catch(function () {
                        showFeedback(
                            'No fue posible conectar con el servidor. Los cambios no se guardaron.',
                            'error'
                        );
                    }).finally(function () {
                        form.dispatchEvent(new CustomEvent('reservation:reset-submit'));
                    });
                });
            }

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

            // El servidor vuelve a filtrar los horarios cada minuto. Esto
            // evita que una pantalla abierta conserve seleccionable un turno
            // que acaba de vencer según la zona horaria de la aplicación.
            var scheduleRefreshId = window.setInterval(function () {
                if (document.hidden || isSubmitting || isLoadingSchedules || !isEditing) return;
                if (modal && !modal.open) return;
                var currentDate = dateInput ? dateInput.value : '';
                if (!currentDate) return;
                loadSchedules(currentDate, timeInput ? timeInput.value : '');
            }, 60000);

            window.addEventListener('pagehide', function () {
                window.clearInterval(scheduleRefreshId);
            }, { once: true });
        });
    }

    function initReservationActionModal() {
        var root = document.querySelector('[data-reservation-detail-root]');
        var modal = root ? root.querySelector('[data-reservation-action-modal]') : null;
        if (!root || !modal) return;

        var form = modal.querySelector('[data-reservation-action-form]');
        var stateInput = modal.querySelector('[data-reservation-action-state]');
        var title = modal.querySelector('[data-reservation-action-title]');
        var description = modal.querySelector('[data-reservation-action-description]');
        var reasonField = modal.querySelector('[data-reservation-action-reason-field]');
        var reason = modal.querySelector('[data-reservation-action-reason]');
        var error = modal.querySelector('[data-reservation-action-error]');
        var lastFocus = null;

        function close() {
            modal.classList.remove('is-open');
            modal.hidden = true;
            document.body.style.overflow = '';
            if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
            lastFocus = null;
        }

        root.querySelectorAll('[data-reservation-action-open]').forEach(function (trigger) {
            trigger.addEventListener('click', function () {
                var targetState = trigger.getAttribute('data-action-state') || '';
                var requiresReason = trigger.getAttribute('data-action-reason') === '1';
                lastFocus = trigger;
                stateInput.value = targetState;
                reasonField.hidden = !requiresReason;
                reason.value = '';
                error.textContent = '';
                title.textContent = trigger.getAttribute('data-action-label') || 'Confirmar acción';
                description.textContent = targetState === 'no_show'
                    ? 'Confirma que la tolerancia venció, no existe llegada y no hay ticket abierto.'
                    : (targetState === 'cancelada'
                        ? 'La cancelación conservará las relaciones históricas y requiere un motivo.'
                        : 'El servidor volverá a validar el estado, las mesas y los tickets antes de guardar.');
                modal.hidden = false;
                document.body.style.overflow = 'hidden';
                window.requestAnimationFrame(function () {
                    modal.classList.add('is-open');
                    (requiresReason ? reason : modal.querySelector('[data-admin-modal-dialog]')).focus();
                });
            });
        });

        modal.querySelectorAll('[data-reservation-action-close]').forEach(function (button) {
            button.addEventListener('click', close);
        });

        form.addEventListener('submit', function (event) {
            if (!reasonField.hidden && !reason.value.trim()) {
                event.preventDefault();
                error.textContent = 'Escribe el motivo antes de continuar.';
                reason.focus();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) {
                event.preventDefault();
                close();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAdminReservationForms();
            initReservationActionModal();
        });
    } else {
        initAdminReservationForms();
        initReservationActionModal();
    }
})();
