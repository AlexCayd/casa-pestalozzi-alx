/**
 * Controla el formulario publico de reservaciones:
 * calendario, horarios disponibles, comensales y envio JSON.
 */

/* ---- Calendario ---- */
function initCalendar() {
  var wrap = $("#datePicker");
  var display = $("#dateDisplay");
  var hidden = $("#fechaHidden");
  var cal = $("#cpCalendar");

  if (!wrap || !display || !hidden || !cal) return;

  if (window.createReservationDatePicker) {
    return window.createReservationDatePicker({
      root: wrap,
      input: hidden,
      display: display,
      calendar: cal,
      minDate: wrap.getAttribute("data-min-date"),
      maxDate: wrap.getAttribute("data-max-date"),
      enabledWeekdays: wrap.getAttribute("data-enabled-weekdays")
    });
  }

  var label = cal.querySelector(".cpc-label");
  var grid = cal.querySelector(".cpc-grid");
  var prevBtn = cal.querySelector(".cpc-prev");
  var nextBtn = cal.querySelector(".cpc-next");

  var configuredToday = wrap.getAttribute("data-today-date")
    || wrap.getAttribute("data-min-date");
  var today = configuredToday
    ? new Date(String(configuredToday) + "T00:00:00")
    : new Date();
  today.setHours(0, 0, 0, 0);
  var curYear = today.getFullYear();
  var curMonth = today.getMonth();
  var selected = null;

  var MONTHS = ["Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

  function pad(n) { return n < 10 ? "0" + n : "" + n; }

  function emitDateChange() {
    hidden.dispatchEvent(new CustomEvent("reservation:datechange", {
      detail: { fecha: hidden.value }
    }));
  }

  function render() {
    label.textContent = MONTHS[curMonth] + " " + curYear;
    grid.innerHTML = "";

    var first = new Date(curYear, curMonth, 1).getDay();
    var days = new Date(curYear, curMonth + 1, 0).getDate();

    for (var i = 0; i < first; i++) {
      var empty = document.createElement("span");
      empty.className = "cpc-day empty";
      grid.appendChild(empty);
    }

    for (var d = 1; d <= days; d++) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "cpc-day";
      btn.textContent = d;

      var date = new Date(curYear, curMonth, d);
      date.setHours(0, 0, 0, 0);

      if (date < today) {
        btn.classList.add("disabled");
        btn.disabled = true;
      }
      if (date.getTime() === today.getTime()) btn.classList.add("today");
      if (selected && date.getTime() === selected.getTime()) btn.classList.add("selected");

      (function(dt) {
        btn.addEventListener("click", function() {
          selected = dt;
          hidden.value = dt.getFullYear() + "-" + pad(dt.getMonth() + 1) + "-" + pad(dt.getDate());
          display.value = pad(dt.getDate()) + " / " + pad(dt.getMonth() + 1) + " / " + dt.getFullYear();
          closeCalendar();
          emitDateChange();
        });
      })(date);

      grid.appendChild(btn);
    }
  }

  function openCalendar() {
    cal.classList.add("open");
    cal.setAttribute("aria-hidden", "false");
    render();
  }

  function closeCalendar() {
    cal.classList.remove("open");
    cal.setAttribute("aria-hidden", "true");
  }

  display.addEventListener("click", function(e) {
    e.stopPropagation();
    cal.classList.contains("open") ? closeCalendar() : openCalendar();
  });

  prevBtn.addEventListener("click", function(e) {
    e.stopPropagation();
    curMonth--;
    if (curMonth < 0) { curMonth = 11; curYear--; }
    render();
  });

  nextBtn.addEventListener("click", function(e) {
    e.stopPropagation();
    curMonth++;
    if (curMonth > 11) { curMonth = 0; curYear++; }
    render();
  });

  document.addEventListener("click", function(e) {
    if (!wrap.contains(e.target)) closeCalendar();
  });
}

/* ---- Selector de hora ---- */
function initHourPicker(form, getGuestCount) {
  var wrap = $("#hourPicker");
  var display = $("#hourDisplay");
  var hidden = $("#horaHidden");
  var dropdown = $("#hourDropdown");
  var dateInput = $("#fechaHidden");
  var status = $("#hourStatus");
  var endpoint = form ? form.getAttribute("data-schedules-endpoint") : "";
  var requestId = 0;
  var abortController = null;
  var requestTimeoutId = null;
  var requestTimeoutMs = 10000;
  var enabled = false;

  if (!wrap || !display || !hidden || !dropdown || !dateInput || !endpoint) {
    return { clear: function() {} };
  }

  if (window.createReservationTimePicker) {
    var picker = window.createReservationTimePicker({
      root: wrap,
      input: hidden,
      display: display,
      dropdown: dropdown,
      status: status,
      endpoint: endpoint,
      getQueryParams: function() {
        var selectedHour = hidden.value || "";
        var params = {
          personas: typeof getGuestCount === "function" ? getGuestCount() : 2
        };
        if (selectedHour) params.hora = selectedHour;
        return params;
      },
      invalidateUnavailable: true,
      initialDate: dateInput.value,
      initialTime: hidden.value
    });

    if (picker) return picker;
  }

  function setStatus(text, show) {
    if (!status) return;
    status.textContent = text || "";
    status.classList.toggle("show", Boolean(show && text));
  }

  function closeHourDropdown() {
    dropdown.classList.remove("open");
    dropdown.setAttribute("aria-hidden", "true");
  }

  function setDisabled(text) {
    enabled = false;
    hidden.value = "";
    display.value = "";
    display.disabled = true;
    hidden.disabled = true;
    display.placeholder = text || "Elige una hora";
    display.setAttribute("aria-disabled", "true");
    wrap.classList.add("is-disabled");
    dropdown.innerHTML = "";
    closeHourDropdown();
  }

  function setLoading() {
    setDisabled("Consultando horarios...");
    setStatus("Consultando horarios...", true);
  }

  function normalizeFallbackHours(hours) {
    return (Array.isArray(hours) ? hours : []).reduce(function(result, item) {
      if (item && typeof item === "object") {
        if (item.disponible === false) return result;
        item = item.hora;
      }
      var match = String(item || "").match(/^([01]\d|2[0-3]):([0-5]\d)/);
      if (match) result.push(match[1] + ":" + match[2]);
      return result;
    }, []);
  }

  function emitScheduleLoaded(data) {
    form.dispatchEvent(new CustomEvent("reservation:scheduleloaded", {
      detail: data || {}
    }));
  }

  function renderHours(hours) {
    dropdown.innerHTML = "";
    hidden.value = "";
    display.value = "";

    if (!hours.length) {
      setDisabled("Sin horarios disponibles");
      setStatus("No hay horarios disponibles para esta fecha.", true);
      return;
    }

    enabled = true;
    display.disabled = false;
    hidden.disabled = false;
    wrap.classList.remove("is-disabled");
    display.removeAttribute("aria-disabled");
    display.placeholder = "Elige una hora";
    setStatus("", false);

    hours.forEach(function(hora) {
      var btn = document.createElement("button");
      btn.type = "button";
      btn.className = "hour-option";
      btn.textContent = hora;
      btn.addEventListener("click", function(e) {
        e.stopPropagation();
        hidden.value = hora;
        display.value = hora;
        dropdown.querySelectorAll(".hour-option").forEach(function(b) { b.classList.remove("sel"); });
        btn.classList.add("sel");
        setStatus("", false);
        closeHourDropdown();
      });
      dropdown.appendChild(btn);
    });
  }

  function loadForDate(fecha) {
    requestId++;
    var currentRequest = requestId;

    if (abortController) {
      abortController.abort();
      abortController = null;
    }

    if (requestTimeoutId !== null) {
      clearTimeout(requestTimeoutId);
      requestTimeoutId = null;
    }

    if (!fecha) {
      setDisabled("Selecciona fecha y comensales");
      setStatus("", false);
      return Promise.resolve([]);
    }

    setLoading();
    abortController = typeof AbortController !== "undefined" ? new AbortController() : null;

    var timedOut = false;
    var timeoutNotified = false;
    requestTimeoutId = setTimeout(function() {
      if (currentRequest !== requestId) return;
      timedOut = true;
      requestTimeoutId = null;
      requestId++;
      if (abortController) {
        abortController.abort();
        abortController = null;
      }
      timeoutNotified = true;
      setDisabled("Elige una hora");
      setStatus("No fue posible consultar los horarios. Intenta nuevamente.", true);
      emitScheduleLoaded({
        ok: false,
        mensaje: "No fue posible consultar los horarios. Intenta nuevamente."
      });
    }, requestTimeoutMs);

    var fallbackParams = new URLSearchParams({
      fecha: fecha,
      personas: typeof getGuestCount === "function" ? getGuestCount() : 2
    });
    if (hidden.value) fallbackParams.set("hora", hidden.value);

    return fetch(endpoint + "?" + fallbackParams.toString(), {
      headers: { "Accept": "application/json" },
      credentials: "same-origin",
      signal: abortController ? abortController.signal : undefined
    })
      .then(function(response) {
        return response.text().then(function(raw) {
          var data;
          try {
            data = JSON.parse(raw);
          } catch (error) {
            throw new Error("INVALID_AVAILABILITY_JSON");
          }
          if (!response.ok) {
            return {
              ok: false,
              mensaje: data && data.mensaje
                ? data.mensaje
                : "No fue posible consultar los horarios."
            };
          }
          return data;
        });
      })
      .then(function(res) {
        if (currentRequest === requestId && requestTimeoutId !== null) {
          clearTimeout(requestTimeoutId);
          requestTimeoutId = null;
        }
        if (currentRequest !== requestId) return;
        if (res && res.fecha !== undefined && String(res.fecha) !== String(fecha)) {
          setDisabled("Elige una hora");
          setStatus("La respuesta no corresponde a la fecha seleccionada.", true);
          emitScheduleLoaded({
            ok: false,
            codigo: "FECHA_RESPUESTA_MISMATCH",
            fecha: res.fecha,
            requested_fecha: fecha,
            mensaje: "La respuesta no corresponde a la fecha seleccionada."
          });
          return [];
        }
        emitScheduleLoaded(res);

        if (!res.ok) {
          setDisabled("Elige una hora");
          setStatus(res.mensaje || "No fue posible consultar los horarios.", true);
          return;
        }

        if (res.abierto === false) {
          setDisabled("Restaurante cerrado");
          setStatus(res.mensaje || "El restaurante no recibe reservaciones en esta fecha.", true);
          return;
        }

        renderHours(normalizeFallbackHours(res.horarios));
      })
      .catch(function(error) {
        if (currentRequest === requestId && requestTimeoutId !== null) {
          clearTimeout(requestTimeoutId);
          requestTimeoutId = null;
        }
        if (error && error.name === "AbortError" && !timedOut) return [];
        if (currentRequest !== requestId) return;
        if (timeoutNotified) return [];
        emitScheduleLoaded({
          ok: false,
          mensaje: "No fue posible consultar los horarios. Intenta nuevamente."
        });
        return [];
      });
  }

  dateInput.addEventListener("reservation:datechange", function(e) {
    loadForDate((e.detail && e.detail.fecha) || dateInput.value);
  });

  display.addEventListener("click", function(e) {
    e.stopPropagation();
    if (!enabled) return;

    var isOpen = dropdown.classList.contains("open");
    var cal = $("#cpCalendar");
    if (cal) {
      cal.classList.remove("open");
      cal.setAttribute("aria-hidden", "true");
    }
    isOpen ? closeHourDropdown() : (dropdown.classList.add("open"), dropdown.setAttribute("aria-hidden", "false"));
  });

  document.addEventListener("click", function(e) {
    if (!wrap.contains(e.target)) closeHourDropdown();
  });

  setDisabled("Elige una fecha primero");

  return {
    loadForDate: loadForDate,
    setDisabled: function(disabled) {
      if (disabled) {
        setDisabled("Selecciona una fecha y hora");
        return;
      }
      display.disabled = !enabled;
      hidden.disabled = false;
      display.setAttribute("aria-disabled", enabled ? "false" : "true");
    },
    clear: function() {
      setDisabled("Elige una fecha primero");
      setStatus("", false);
    }
  };
}

function initSpecialScheduleNotice(form) {
  var card = document.querySelector("[data-special-schedule]");
  if (!form || !card) return;

  var label = card.querySelector("[data-special-schedule-label]");
  var date = card.querySelector("[data-special-schedule-date]");
  var hours = card.querySelector("[data-special-schedule-hours]");
  var reason = card.querySelector("[data-special-schedule-reason]");
  var regular = card.querySelector("[data-special-schedule-regular]");
  var note = card.querySelector("[data-special-schedule-note]");

  function formatDate(value) {
    var parsed = new Date(String(value || "") + "T12:00:00");
    if (Number.isNaN(parsed.getTime())) return value || "";
    return parsed.toLocaleDateString("es-MX", {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric"
    });
  }

  form.addEventListener("reservation:scheduleloaded", function(event) {
    var detail = event.detail && event.detail.detalle_horario;
    if (!detail || detail.es_excepcion !== true) {
      card.hidden = true;
      return;
    }

    label.textContent = detail.etiqueta || (detail.abierto ? "Horario especial" : "Cierre especial");
    date.textContent = formatDate(detail.fecha);
    hours.textContent = detail.abierto
      ? String(detail.hora_apertura || "") + "–" + String(detail.hora_cierre || "")
      : "Cerrado todo el día";
    reason.textContent = detail.motivo || "";
    reason.hidden = !detail.motivo;
    note.textContent = detail.abierto
      ? "Este horario reemplaza al horario habitual para la fecha seleccionada."
      : "No se reciben reservaciones durante esta fecha.";

    var habitual = detail.habitual || {};
    regular.textContent = habitual.abierto
      ? String(habitual.hora_apertura || "") + "–" + String(habitual.hora_cierre || "")
      : "Cerrado";
    card.hidden = false;
  });
}

function initScheduleChanges() {
  var section = document.querySelector("[data-schedule-changes]");
  if (!section) return;

  var toggle = section.querySelector("[data-schedule-toggle]");
  var extras = section.querySelectorAll("[data-schedule-extra]");
  if (!toggle || !extras.length) return;

  toggle.addEventListener("click", function() {
    var expanded = toggle.getAttribute("aria-expanded") === "true";
    extras.forEach(function(card) {
      card.hidden = expanded;
    });
    toggle.setAttribute("aria-expanded", expanded ? "false" : "true");
    toggle.textContent = expanded ? "Ver más" : "Ver menos";
  });
}

/**
 * Flujo público: exploración -> retención/creación -> OTP cuando corresponde.
 * La disponibilidad mostrada es orientativa; el servidor la repite bajo lock.
 */
function initForm() {
  var form = document.getElementById("reservaForm");
  if (!form) return;

  var formStateHelpers = window.ReservationFormState || {};
  var csrfNode = document.querySelector("[data-reservation-csrf]");
  var csrfToken = csrfNode ? csrfNode.getAttribute("data-reservation-csrf") || "" : "";
  var maxGuests = parseInt(form.getAttribute("data-max-guests"), 10) || 12;
  var reservationState = {
    currentStep: 1,
    maxCompletedStep: 0,
    date: "",
    time: "",
    guests: 2,
    name: "",
    contactType: "email",
    email: "",
    phone: "",
    details: "",
    largeParty: false,
    availabilityStatus: "idle",
    availableSlots: [],
    availabilityPending: false,
    invalidSteps: {}
  };
  var guests = reservationState.guests;
  var guestCount = 7;
  var largePartyMode = false;
  var guestPills = document.getElementById("guestPills");
  var guestsExtra = document.getElementById("guestsExtra");
  var stepPanels = Array.from(form.querySelectorAll("[data-reservation-step]"));
  var stepButtons = Array.from(document.querySelectorAll("[data-reservation-step-target]"));
  var progressLines = Array.from(document.querySelectorAll("[data-reservation-progress-line]"));
  var contactFields = Array.from(form.querySelectorAll("[data-new-reservation-contact], [data-new-reservation-contact-input]"));
  var contactInput = form.elements.contacto;
  var contactLabel = form.querySelector("[data-contact-label]");
  var contactHelp = form.querySelector("[data-new-contact-help]");
  var verifiedContactHelp = form.querySelector("[data-new-contact-verified-help]");
  var changeContactButton = form.querySelector("[data-new-contact-change]");
  var submitHelp = form.querySelector("[data-new-reservation-submit-help]");
  var submitLabel = form.querySelector("[data-new-reservation-submit-label]");
  var summary = form.querySelector("[data-reservation-selection-summary]");
  var selectionStatus = summary.querySelector("[data-selection-status]");
  var selectionPrimary = summary.querySelector("[data-selection-primary]");
  var selectionSecondary = summary.querySelector("[data-selection-secondary]");
  var selectionDetails = summary.querySelector("[data-selection-details]");
  var largePartyInfo = summary.querySelector("[data-large-party-info]");
  var review = form.querySelector("[data-reservation-review]");
  var reviewDate = review.querySelector("[data-review-date]");
  var reviewGuests = review.querySelector("[data-review-guests]");
  var reviewName = review.querySelector("[data-review-name]");
  var reviewContact = review.querySelector("[data-review-contact]");
  var reviewMethod = review.querySelector("[data-review-method]");
  var reviewDetails = review.querySelector("[data-review-details]");
  var reviewDetailsGroup = review.querySelector("[data-review-details-group]");
  var dateInput = document.getElementById("fechaHidden");
  var timeInput = document.getElementById("horaHidden");
  var timeRoot = form.querySelector("[data-reservation-time-picker]");
  var submitButton = form.querySelector('button[type="submit"]');
  var message = document.getElementById("formMsg");
  var otpStep = document.querySelector("[data-new-reservation-otp]");
  var otpInput = otpStep && otpStep.querySelector("[data-new-reservation-otp-input]");
  var otpError = otpStep && otpStep.querySelector("[data-new-reservation-otp-error]");
  var otpMessage = otpStep && otpStep.querySelector("[data-new-reservation-otp-message]");
  var otpPreview = otpStep && otpStep.querySelector("[data-new-reservation-preview]");
  var countdown = otpStep && otpStep.querySelector("[data-new-reservation-countdown]");
  var verifyButton = otpStep && otpStep.querySelector("[data-new-reservation-verify]");
  var resendButton = otpStep && otpStep.querySelector("[data-new-reservation-resend]");
  var otpContact = otpStep && otpStep.querySelector("[data-new-reservation-contact]");
  var confirm = document.getElementById("reservaConfirm");
  var confirmText = document.getElementById("confirmText");
  var confirmDate = confirm && confirm.querySelector("[data-confirm-date]");
  var confirmTime = confirm && confirm.querySelector("[data-confirm-time]");
  var confirmGuests = confirm && confirm.querySelector("[data-confirm-guests]");
  var confirmName = confirm && confirm.querySelector("[data-confirm-name]");
  var confirmContact = confirm && confirm.querySelector("[data-confirm-contact]");
  var mobileProgressCurrent = document.querySelector("[data-progress-current]");
  var mobileProgressName = document.querySelector("[data-progress-name]");
  var mobileProgressBar = document.querySelector("[data-progress-bar]");
  var progressNames = ["Tu visita", "Tus datos", "Detalles", "Revisar"];
  var sessionVerified = null;
  var verifiedContact = null;
  var submitting = false;
  var activeIdentity = null;
  var holdExpiresAt = 0;
  var countdownTimer = null;
  var availabilityTimer = null;
  var availabilityRequestId = 0;
  var availabilityKey = "";
  var lastSubmittedFingerprint = "";
  var holdExpiryHandled = false;
  var timePicker;
  var datePicker;

  function normalizeContact(type, value) {
    value = String(value || "").trim();
    return type === "telefono"
      ? value.replace(/[\s\-().]+/g, "")
      : value.toLowerCase();
  }

  function verifiedContactMatchesForm() {
    return Boolean(
      verifiedContact
      && reservationState.contactType === verifiedContact.tipo
      && normalizeContact(reservationState.contactType, currentContact())
        === verifiedContact.contacto
    );
  }

  function setVerifiedContact(type, contact) {
    type = String(type || "");
    contact = String(contact || "");
    if (!type || !contact) {
      verifiedContact = null;
      return;
    }
    verifiedContact = {
      tipo: type,
      contacto: normalizeContact(type, contact)
    };
    var typeInput = form.querySelector('input[name="tipo_contacto"][value="' + type + '"]');
    if (typeInput) typeInput.checked = true;
    reservationState.contactType = type;
    if (type === "telefono") {
      reservationState.phone = contact;
    } else {
      reservationState.email = contact;
    }
    if (contactInput) contactInput.value = contact;
    syncContactInput();
  }

  function invalidateVerifiedContact() {
    verifiedContact = null;
    sessionVerified = false;
    window.CP_RESERVATION_SESSION = false;
    window.CP_RESERVATION_CONTACT = null;
  }

  function updateVerifiedContactCopy() {
    var verified = sessionVerified === true && Boolean(verifiedContact);
    contactFields.forEach(function(field) {
      field.hidden = verified;
    });
    if (verifiedContactHelp) verifiedContactHelp.hidden = !verified;
    if (submitHelp) submitHelp.textContent = verified
      ? "Utilizaremos el contacto que ya verificaste para esta reservación."
      : "Te enviaremos un código temporal para confirmar tu reservación.";
    if (submitLabel) submitLabel.textContent = verified
      ? "Crear reservación"
      : "Confirmar reservación";
  }

  function randomToken() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID().replace(/-/g, "") + Date.now().toString(36);
    }
    if (window.crypto && typeof window.crypto.getRandomValues === "function") {
      var bytes = new Uint8Array(32);
      window.crypto.getRandomValues(bytes);
      return Array.prototype.map.call(bytes, function(byte) {
        return byte.toString(16).padStart(2, "0");
      }).join("");
    }
    return Date.now().toString(36) + Math.random().toString(36).slice(2)
      + Math.random().toString(36).slice(2);
  }

  function rotateRequestToken() {
    form.elements.request_token.value = randomToken();
    lastSubmittedFingerprint = "";
  }

  if (!form.elements.request_token.value) form.elements.request_token.value = randomToken();

  function jsonRequest(url, options) {
    return fetch(url, Object.assign({
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "Accept": "application/json" }
    }, options || {})).then(function(response) {
      return response.json().catch(function() {
        return { ok: false, codigo: "ERROR_INTERNO", mensaje: "La respuesta del servidor no es válida." };
      }).then(function(data) {
        data.httpStatus = response.status;
        return data;
      });
    });
  }

  function selectedContactType() {
    var selected = form.querySelector('input[name="tipo_contacto"]:checked');
    return selected ? selected.value : "email";
  }

  function syncContactInput() {
    if (!contactInput) return;
    var nextType = selectedContactType();
    if (reservationState.contactType !== nextType) {
      if (reservationState.contactType === "telefono") {
        reservationState.phone = contactInput.value;
      } else {
        reservationState.email = contactInput.value;
      }
      contactInput.value = nextType === "telefono"
        ? reservationState.phone
        : reservationState.email;
    }
    var phone = nextType === "telefono";
    reservationState.contactType = nextType;
    contactInput.type = phone ? "tel" : "email";
    contactInput.autocomplete = phone ? "tel" : "email";
    contactInput.placeholder = phone ? "+52 55 1234 5678" : "cliente@ejemplo.com";
    if (contactLabel) contactLabel.textContent = phone ? "Teléfono" : "Correo electrónico";
    if (contactHelp) {
      contactHelp.textContent = phone
        ? "Incluye el código de país."
        : "Te enviaremos un código temporal para confirmar tu reservación.";
    }
    updateInterface();
  }

  form.querySelectorAll('input[name="tipo_contacto"]').forEach(function(input) {
    input.addEventListener("change", function() {
      clearFieldError("contacto");
      syncContactInput();
    });
  });
  syncContactInput();

  function setMessage(text, error) {
    message.textContent = text || "";
    message.classList.toggle("show", Boolean(text));
    message.classList.toggle("is-error", Boolean(error));
  }

  function fieldControl(field) {
    return field === "fecha"
      ? document.getElementById("dateDisplay")
      : field === "hora"
        ? document.getElementById("hourDisplay")
        : field === "comensales"
          ? guestPills
          : form.elements[field];
  }

  function fieldContainer(field, control) {
    var component = field === "fecha"
      ? document.getElementById("datePicker")
      : field === "hora"
        ? document.getElementById("hourPicker")
        : control;
    return component && component.closest
      ? component.closest(".reservation-field, .field")
      : null;
  }

  function stepPanel(step) {
    return form.querySelector('[data-reservation-step="' + step + '"]');
  }

  function syncStepInvalid(step) {
    var panel = stepPanel(step);
    reservationState.invalidSteps[step] = Boolean(
      panel && panel.querySelector(".reservation-field.is-invalid, .field.is-invalid")
    );
  }

  function setFieldError(field, text) {
    var target = form.querySelector('[data-field-error="' + field + '"]');
    if (!target) return;
    var hasError = Boolean(text);
    target.replaceChildren();
    if (hasError) {
      var icon = document.createElement("span");
      icon.className = "reservation-field__error-icon";
      icon.setAttribute("aria-hidden", "true");
      icon.textContent = "!";
      target.append(icon, document.createTextNode(String(text)));
    }
    target.classList.toggle("show", hasError);
    var control = fieldControl(field);
    var container = fieldContainer(field, control);
    if (control) {
      control.setAttribute("aria-invalid", hasError ? "true" : "false");
      if (hasError && target.id) {
        var describedBy = (control.getAttribute("aria-describedby") || "")
          .split(/\s+/)
          .filter(Boolean);
        if (describedBy.indexOf(target.id) === -1) describedBy.push(target.id);
        control.setAttribute("aria-describedby", describedBy.join(" "));
      }
    }
    if (container) container.classList.toggle("is-invalid", hasError);
    var panel = container && container.closest("[data-reservation-step]");
    if (panel) syncStepInvalid(parseInt(panel.getAttribute("data-reservation-step"), 10));
    updateProgress();
  }

  function clearFieldError(field) {
    setFieldError(field, "");
  }

  function clearErrors(step) {
    var scope = step ? stepPanel(step) : form;
    if (!scope) return;
    scope.querySelectorAll("[data-field-error]").forEach(function(target) {
      target.replaceChildren();
      target.classList.remove("show");
    });
    scope.querySelectorAll("[aria-invalid]").forEach(function(control) {
      control.setAttribute("aria-invalid", "false");
    });
    scope.querySelectorAll(".reservation-field.is-invalid, .field.is-invalid").forEach(function(field) {
      field.classList.remove("is-invalid");
    });
    if (step) reservationState.invalidSteps[step] = false;
    else reservationState.invalidSteps = {};
    setMessage("");
    updateProgress();
  }

  function setOtpError(text) {
    if (!otpError || !otpInput) return;
    var hasError = Boolean(text);
    otpError.replaceChildren();
    if (hasError) {
      var icon = document.createElement("span");
      icon.className = "reservation-field__error-icon";
      icon.setAttribute("aria-hidden", "true");
      icon.textContent = "!";
      otpError.append(icon, document.createTextNode(String(text)));
    }
    otpError.classList.toggle("show", hasError);
    otpInput.setAttribute("aria-invalid", hasError ? "true" : "false");
    otpInput.closest(".field").classList.toggle("is-invalid", hasError);
  }

  function clearOtpError() {
    setOtpError("");
  }

  function formatReservationDate(value) {
    var parsed = new Date(String(value || "") + "T12:00:00");
    if (Number.isNaN(parsed.getTime())) return value || "";
    return parsed.toLocaleDateString("es-MX", {
      weekday: "long",
      day: "numeric",
      month: "long"
    });
  }

  function syncStateFromControls() {
    reservationState.date = dateInput.value;
    reservationState.time = timeInput.value;
    reservationState.guests = guests;
    reservationState.largeParty = largePartyMode;
    reservationState.name = form.elements.nombre.value;
    reservationState.contactType = selectedContactType();
    if (reservationState.contactType === "telefono") {
      reservationState.phone = contactInput ? contactInput.value : "";
    } else {
      reservationState.email = contactInput ? contactInput.value : "";
    }
    reservationState.details = form.elements.nota.value;
  }

  function currentContact() {
    return reservationState.contactType === "telefono"
      ? reservationState.phone
      : reservationState.email;
  }

  function contactIsValid() {
    if (sessionVerified === true) return verifiedContactMatchesForm();
    var value = String(currentContact()).trim();
    if (!value) return false;
    return reservationState.contactType === "email"
      ? /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
      : /^\+52\s?(?:\d[\s-]?){10}$/.test(value);
  }

  function visitIsValid() {
    if (typeof formStateHelpers.canSubmitVisit === "function") {
      return formStateHelpers.canSubmitVisit({
        date: reservationState.date,
        time: reservationState.time,
        guests: reservationState.guests,
        maxGuests: maxGuests,
        largeParty: reservationState.largeParty,
        availabilityStatus: reservationState.availabilityStatus,
        availabilityPending: reservationState.availabilityPending,
        availableSlots: reservationState.availableSlots
      });
    }
    return Boolean(
      !reservationState.largeParty
      && reservationState.date
      && reservationState.time
      && reservationState.guests >= 1
      && reservationState.guests <= maxGuests
      && reservationState.availabilityStatus === "ready"
      && reservationState.availableSlots.indexOf(reservationState.time) !== -1
      && !reservationState.availabilityPending
    );
  }

  function dataIsValid() {
    return Boolean(reservationState.name.trim() && contactIsValid());
  }

  function updateSummaries() {
    var readableDate = formatReservationDate(reservationState.date);
    var people = reservationState.guests + (reservationState.guests === 1 ? " persona" : " personas");
    var selectionReady = Boolean(reservationState.date && reservationState.time);

    if (reservationState.largeParty) {
      if (selectionStatus) selectionStatus.textContent = "Más de 12 personas";
      if (selectionDetails) selectionDetails.hidden = true;
      if (largePartyInfo) largePartyInfo.hidden = false;
      summary.hidden = false;
    } else if (reservationState.date) {
      if (selectionDetails) selectionDetails.hidden = false;
      if (largePartyInfo) largePartyInfo.hidden = true;
      selectionPrimary.textContent = readableDate
        + (reservationState.time ? " · " + reservationState.time : "");
      selectionSecondary.textContent = people;
      if (selectionStatus) {
        selectionStatus.textContent = reservationState.availabilityPending
          ? "Consultando disponibilidad…"
          : reservationState.availabilityStatus === "ready" && reservationState.time
            ? "Disponibilidad confirmada"
            : "Elige un horario disponible";
      }
      summary.hidden = false;
    } else {
      if (selectionDetails) selectionDetails.hidden = false;
      if (largePartyInfo) largePartyInfo.hidden = true;
      summary.hidden = true;
    }

    reviewDate.textContent = selectionReady
      ? readableDate + " · " + reservationState.time
      : (reservationState.date ? readableDate + " · falta elegir la hora" : "Selecciona fecha y hora");
    reviewGuests.textContent = people;
    reviewName.textContent = reservationState.name.trim() || "—";
    reviewMethod.textContent = reservationState.contactType === "telefono"
      ? "Confirmación por teléfono"
      : "Confirmación por correo";
    reviewContact.textContent = currentContact().trim() || "—";
    reviewDetails.textContent = reservationState.details.trim();
    reviewDetailsGroup.hidden = !reservationState.details.trim();
  }

  function updateProgress() {
    var currentStep = reservationState.currentStep;
    stepButtons.forEach(function(button) {
      var step = parseInt(button.getAttribute("data-reservation-step-target"), 10);
      var current = step === reservationState.currentStep;
      var completed = step <= reservationState.maxCompletedStep && !current;
      var accessible = current || step <= reservationState.maxCompletedStep;
      button.classList.toggle("is-current", current);
      button.classList.toggle("is-complete", completed);
      button.classList.toggle("is-invalid", Boolean(reservationState.invalidSteps[step]));
      if (current) {
        button.setAttribute("aria-current", "step");
      } else {
        button.removeAttribute("aria-current");
      }
      button.disabled = !accessible;
      button.setAttribute("aria-disabled", accessible ? "false" : "true");
    });
    progressLines.forEach(function(line) {
      var segment = parseInt(line.getAttribute("data-reservation-progress-line"), 10);
      line.classList.toggle("is-complete", segment <= reservationState.maxCompletedStep);
    });
    if (mobileProgressCurrent) mobileProgressCurrent.textContent = currentStep;
    if (mobileProgressName) mobileProgressName.textContent = progressNames[currentStep - 1] || progressNames[0];
    if (mobileProgressBar) mobileProgressBar.style.width = (currentStep * 25) + "%";
  }

  function updateInterface() {
    syncStateFromControls();
    updateVerifiedContactCopy();
    updateSummaries();
    updateProgress();

    stepPanels.forEach(function(panel) {
      var step = parseInt(panel.getAttribute("data-reservation-step"), 10);
      var next = panel.querySelector("[data-reservation-next]");
      if (!next) return;
      if (step === 1) next.disabled = reservationState.largeParty
        || reservationState.availabilityPending
        || !visitIsValid();
      if (step === 2) next.disabled = false;
    });

    submitButton.disabled = submitting
      || reservationState.largeParty
      || !visitIsValid()
      || !dataIsValid()
      || reservationState.availabilityPending;
  }

  function setCurrentStep(step, moveFocus) {
    step = Math.max(1, Math.min(4, parseInt(step, 10) || 1));
    reservationState.currentStep = step;
    stepPanels.forEach(function(panel) {
      var active = parseInt(panel.getAttribute("data-reservation-step"), 10) === step;
      panel.hidden = !active;
      panel.classList.toggle("is-active", active);
      panel.setAttribute("aria-hidden", active ? "false" : "true");
    });
    updateInterface();
    if (moveFocus !== false) {
      window.requestAnimationFrame(function() {
        var activePanel = form.querySelector('[data-reservation-step="' + step + '"]');
        var heading = activePanel && activePanel.querySelector("h3");
        if (heading) heading.focus({ preventScroll: true });
      });
    }
    if (window.ScrollTrigger) window.ScrollTrigger.refresh();
  }

  function shakeStep(step) {
    var panel = stepPanel(step);
    if (!panel) return;
    panel.classList.remove("reservation-step--error-shake");
    void panel.offsetWidth;
    panel.classList.add("reservation-step--error-shake");
    window.setTimeout(function() {
      panel.classList.remove("reservation-step--error-shake");
    }, 240);
  }

  function focusFirstInvalid(control) {
    if (!control || typeof control.focus !== "function") return;
    window.requestAnimationFrame(function() {
      control.focus({ preventScroll: false });
    });
  }

  function validateCurrentStep(step) {
    clearErrors(step);
    syncStateFromControls();
    var valid = true;
    var firstInvalid = null;

    function invalid(field, messageText) {
      setFieldError(field, messageText);
      if (!firstInvalid) firstInvalid = fieldControl(field);
      valid = false;
    }

    if (step === 1) {
      if (!reservationState.date) invalid("fecha", "Elige una fecha.");
      if (reservationState.guests < 1 || reservationState.guests > maxGuests) {
        invalid("comensales", "Elige la cantidad de comensales.");
      }
      if (
        !reservationState.time
        || reservationState.availabilityPending
        || reservationState.availabilityStatus !== "ready"
        || reservationState.availableSlots.indexOf(reservationState.time) === -1
      ) {
        invalid("hora", reservationState.availabilityPending
          ? "Espera a que carguemos los horarios disponibles."
          : "Elige un horario disponible.");
      }
    }

    if (step === 2) {
      if (!reservationState.name.trim()) invalid("nombre", "Escribe tu nombre.");
      if (sessionVerified !== true && !contactIsValid()) {
        invalid(
          "contacto",
          reservationState.contactType === "telefono"
            ? "Escribe un teléfono válido."
            : "Escribe un correo electrónico válido."
        );
      }
    }

    reservationState.invalidSteps[step] = !valid;
    updateInterface();
    if (!valid) {
      focusFirstInvalid(firstInvalid);
      shakeStep(step);
    }
    return valid;
  }

  function normalizeAvailableSlots(hours) {
    if (typeof formStateHelpers.normalizeSlots === "function") {
      return formStateHelpers.normalizeSlots(hours);
    }
    return (Array.isArray(hours) ? hours : []).reduce(function(slots, item) {
      if (item && typeof item === "object") {
        if (item.disponible === false) return slots;
        item = item.hora;
      }
      var match = String(item || "").match(/^([01]\d|2[0-3]):([0-5]\d)/);
      var hour = match ? match[1] + ":" + match[2] : "";
      if (hour && slots.indexOf(hour) === -1) slots.push(hour);
      return slots;
    }, []);
  }

  function applyAvailabilityTransition(action) {
    action = action || {};
    if (typeof formStateHelpers.availabilityTransition !== "function") {
      if (action.type === "start") {
        reservationState.availabilityStatus = "loading";
        reservationState.availabilityPending = true;
        reservationState.availableSlots = [];
      } else if (action.type === "error") {
        reservationState.availabilityStatus = "error";
        reservationState.availabilityPending = false;
        reservationState.availableSlots = [];
      } else if (action.type === "response") {
        var fallbackPayload = action.payload || {};
        reservationState.availableSlots = fallbackPayload.ok === true
          ? normalizeAvailableSlots(fallbackPayload.horarios)
          : [];
        reservationState.availabilityStatus = fallbackPayload.ok !== true
          ? "error"
          : (fallbackPayload.abierto === false
            ? "closed"
            : (reservationState.availableSlots.length ? "ready" : "unavailable"));
        reservationState.availabilityPending = false;
      }
      return;
    }

    var next = formStateHelpers.availabilityTransition({
      status: reservationState.availabilityStatus,
      pending: reservationState.availabilityPending,
      requestId: availabilityRequestId,
      date: reservationState.date,
      guests: reservationState.guests,
      slots: reservationState.availableSlots,
      name: reservationState.name,
      contact: currentContact()
    }, Object.assign({ requestId: availabilityRequestId }, action));

    reservationState.availabilityStatus = next.status;
    reservationState.availabilityPending = next.pending;
    reservationState.availableSlots = Array.isArray(next.slots) ? next.slots : [];
  }

  function reloadAvailability(immediate) {
    clearTimeout(availabilityTimer);
    availabilityRequestId++;
    var currentAvailabilityRequest = availabilityRequestId;
    syncStateFromControls();
    if (reservationState.largeParty || !reservationState.date) {
      reservationState.availabilityStatus = "idle";
      reservationState.availabilityPending = false;
      reservationState.availableSlots = [];
      availabilityKey = "";
      if (timePicker) timePicker.loadForDate("", "");
      updateInterface();
      return;
    }

    var requestedKey = reservationState.date + "|" + reservationState.guests + "|" + (reservationState.time || "");
    applyAvailabilityTransition({
      type: "start",
      date: reservationState.date,
      guests: reservationState.guests,
      hour: reservationState.time
    });
    updateInterface();

    availabilityTimer = setTimeout(function() {
      if (currentAvailabilityRequest !== availabilityRequestId) return;
      if (!timePicker || typeof timePicker.loadForDate !== "function") {
        applyAvailabilityTransition({
          type: "error",
          message: "No fue posible cargar el selector de horarios. Intenta nuevamente."
        });
        updateInterface();
        return;
      }
      availabilityKey = requestedKey;
      var loadResult;
      try {
        loadResult = timePicker.loadForDate(
          reservationState.date,
          reservationState.time || timeInput.value
        );
      } catch (error) {
        loadResult = Promise.reject(error);
      }

      Promise.resolve(loadResult)
        .then(function(hours) {
          var currentKey = reservationState.date + "|" + reservationState.guests + "|" + (reservationState.time || "");
          if (
            currentAvailabilityRequest !== availabilityRequestId
            || requestedKey !== currentKey
            || availabilityKey !== requestedKey
          ) return;
          if (reservationState.availabilityStatus === "loading") {
            applyAvailabilityTransition({
              type: "response",
              payload: { ok: true, abierto: true, horarios: hours }
            });
          }
          updateInterface();
        }, function() {
          if (currentAvailabilityRequest !== availabilityRequestId) return;
          applyAvailabilityTransition({
            type: "error",
            message: "No fue posible consultar los horarios. Intenta nuevamente."
          });
          updateInterface();
        })
        .finally(function() {
          if (currentAvailabilityRequest !== availabilityRequestId) return;
          reservationState.availabilityPending = false;
          updateInterface();
        });
    }, immediate ? 0 : 300);
  }

  function transitionGuests(action, value) {
    if (typeof formStateHelpers.guestTransition === "function") {
      return formStateHelpers.guestTransition({
        guests: guests,
        largeParty: largePartyMode
      }, action, value, maxGuests);
    }
    if (action === "increment" && guests >= maxGuests) {
      return { guests: maxGuests, largeParty: true };
    }
    if (action === "decrement" && largePartyMode) {
      return { guests: maxGuests, largeParty: false };
    }
    var delta = action === "increment" ? 1 : (action === "decrement" ? -1 : 0);
    var next = action === "select" ? value : guests + delta;
    return {
      guests: Math.max(1, Math.min(maxGuests, parseInt(next, 10) || 2)),
      largeParty: false
    };
  }

  function applyGuestState(nextState) {
    var nextGuests = Math.max(1, Math.min(maxGuests, parseInt(nextState.guests, 10) || 2));
    var nextLargeParty = nextState.largeParty === true;
    var changed = guests !== nextGuests || largePartyMode !== nextLargeParty;
    var modeChanged = largePartyMode !== nextLargeParty;
    guests = nextGuests;
    largePartyMode = nextLargeParty;
    reservationState.guests = guests;
    reservationState.largeParty = largePartyMode;
    if (guests > 6) guestCount = guests;

    document.querySelectorAll("#guestPills .pill").forEach(function(item) {
      var itemValue = item.getAttribute("data-g");
      var selected = guests > 6 || largePartyMode
        ? itemValue === "7"
        : parseInt(itemValue, 10) === guests;
      item.classList.toggle("sel", selected);
      item.setAttribute("aria-pressed", selected ? "true" : "false");
    });

    document.getElementById("guestsVal").textContent = largePartyMode
      ? "Más de 12"
      : Math.max(7, guestCount);
    document.getElementById("guestsNum").value = Math.max(7, guestCount);
    guestsExtra.hidden = guests <= 6 && !largePartyMode;
    guestsExtra.classList.toggle("show", guests > 6 || largePartyMode);
    document.getElementById("guestsMinus").disabled = !largePartyMode && guestCount <= 7;
    document.getElementById("guestsPlus").disabled = largePartyMode;

    if (modeChanged && largePartyMode) {
      clearTimeout(availabilityTimer);
      availabilityKey = "";
      reservationState.availabilityStatus = "idle";
      reservationState.availabilityPending = false;
      reservationState.availableSlots = [];
      if (datePicker && typeof datePicker.setValue === "function") {
        datePicker.setValue("", true);
        datePicker.setDisabled(true);
      } else {
        dateInput.value = "";
        var dateDisplay = document.getElementById("dateDisplay");
        if (dateDisplay) {
          dateDisplay.value = "";
          dateDisplay.disabled = true;
        }
        dateInput.disabled = true;
      }
      if (timePicker) {
        timePicker.loadForDate("", "");
        timePicker.setDisabled(true);
      } else {
        timeInput.value = "";
        timeInput.disabled = true;
      }
      reservationState.currentStep = 1;
      reservationState.maxCompletedStep = 0;
      clearErrors();
    } else if (modeChanged) {
      if (datePicker && typeof datePicker.setDisabled === "function") {
        datePicker.setDisabled(false);
      } else {
        var restoredDateDisplay = document.getElementById("dateDisplay");
        if (restoredDateDisplay) restoredDateDisplay.disabled = false;
        dateInput.disabled = false;
      }
      if (timePicker) {
        timePicker.setDisabled(false);
        timePicker.loadForDate("", "");
      } else {
        timeInput.disabled = false;
      }
      reservationState.availabilityStatus = "idle";
      reservationState.availableSlots = [];
      reservationState.availabilityPending = false;
    }

    clearFieldError("comensales");
    updateInterface();
    if (changed && !largePartyMode && reservationState.date) reloadAvailability(false);
  }

  function setGuests(value) {
    applyGuestState(transitionGuests("select", value));
  }

  guestPills.querySelectorAll(".pill[data-g]").forEach(function(pill) {
    pill.addEventListener("click", function() {
      setGuests(pill.getAttribute("data-g"));
    });
  });
  document.getElementById("guestsPlus").addEventListener("click", function() {
    applyGuestState(transitionGuests("increment"));
  });
  document.getElementById("guestsMinus").addEventListener("click", function() {
    applyGuestState(transitionGuests("decrement"));
  });

  stepButtons.forEach(function(button) {
    button.addEventListener("click", function() {
      if (button.disabled) return;
      setCurrentStep(button.getAttribute("data-reservation-step-target"));
    });
  });

  form.querySelectorAll("[data-reservation-next]").forEach(function(button) {
    button.addEventListener("click", function() {
      var currentStep = reservationState.currentStep;
      if (!validateCurrentStep(currentStep)) return;
      var nextStep = Math.min(4, currentStep + 1);
      reservationState.maxCompletedStep = Math.max(
        reservationState.maxCompletedStep,
        currentStep
      );
      if (nextStep === 4) reservationState.maxCompletedStep = 4;
      setCurrentStep(nextStep);
    });
  });

  form.querySelectorAll("[data-reservation-back]").forEach(function(button) {
    button.addEventListener("click", function() {
      setCurrentStep(reservationState.currentStep - 1);
    });
  });

  form.querySelectorAll("[data-reservation-edit]").forEach(function(button) {
    button.addEventListener("click", function() {
      setCurrentStep(button.getAttribute("data-reservation-edit"));
    });
  });

  datePicker = initCalendar();
  initScheduleChanges();
  initSpecialScheduleNotice(form);
  timePicker = initHourPicker(form, function() { return guests; });

  if (timeRoot) {
    timeRoot.addEventListener("reservation:scheduleloaded", function(event) {
      var data = event.detail || {};
      var selectedDate = String(reservationState.date || dateInput.value || "");
      var responseDate = String(data.fecha || "");
      var responsePeople = data.personas === undefined || data.personas === null
        ? null
        : parseInt(data.personas, 10);
      if (
        data.ok === true
        && (!responseDate || responseDate !== selectedDate
          || (responsePeople !== null && responsePeople !== guests))
      ) {
        if (reservationState.availabilityPending) {
          applyAvailabilityTransition({
            type: "error",
            message: "La respuesta no corresponde a la fecha o comensales seleccionados."
          });
        }
        updateInterface();
        return;
      }
      applyAvailabilityTransition({
        type: "response",
        payload: data,
        selectedTime: timeInput.value
      });
      updateInterface();
    });
  }

  dateInput.addEventListener("reservation:datechange", function() {
    clearFieldError("fecha");
    availabilityKey = "";
    reservationState.time = "";
    timeInput.value = "";
    if (timePicker && typeof timePicker.clear === "function") timePicker.clear(true);
    reloadAvailability(true);
  });
  timeInput.addEventListener("reservation:timechange", function() {
    clearFieldError("hora");
    if (!reservationState.availabilityPending && reservationState.date && timeInput.value) {
      reloadAvailability(true);
      return;
    }
    updateInterface();
  });
  form.elements.nombre.addEventListener("input", function() {
    clearFieldError("nombre");
    updateInterface();
  });
  contactInput.addEventListener("input", function() {
    syncStateFromControls();
    if (sessionVerified === true && !verifiedContactMatchesForm()) {
      invalidateVerifiedContact();
    }
    clearFieldError("contacto");
    updateInterface();
  });
  form.elements.nota.addEventListener("input", function() {
    reservationState.details = form.elements.nota.value;
    updateInterface();
  });
  if (otpInput) {
    otpInput.addEventListener("input", function() {
      clearOtpError();
    });
  }
  setGuests(2);
  setCurrentStep(1, false);

  if (changeContactButton) {
    changeContactButton.addEventListener("click", function() {
      invalidateVerifiedContact();
      contactInput.value = "";
      contactFields.forEach(function(field) { field.hidden = false; });
      if (verifiedContactHelp) verifiedContactHelp.hidden = true;
      updateInterface();
      contactInput.focus();
    });
  }

  window.addEventListener("reservation:sessionchange", function(event) {
    var detail = event.detail || {};
    if (detail.verified) {
      sessionVerified = true;
      setVerifiedContact(detail.tipo, detail.contacto);
    } else {
      invalidateVerifiedContact();
    }
    updateInterface();
  });

  jsonRequest("/api/reservaciones/mis-reservaciones", {
    method: "GET",
    headers: { "Accept": "application/json" }
  }).then(function(data) {
    sessionVerified = Boolean(data.ok);
    if (sessionVerified) {
      setVerifiedContact(data.verified_contact_type, data.verified_contact);
    } else {
      verifiedContact = null;
    }
    window.CP_RESERVATION_SESSION = sessionVerified;
    updateInterface();
  }).catch(function() {
    sessionVerified = false;
    verifiedContact = null;
    updateInterface();
  });

  function validate() {
    var errors = {};
    if (!form.elements.nombre.value.trim()) errors.nombre = "Escribe tu nombre.";
    if (!dateInput.value) errors.fecha = "Elige una fecha.";
    if (!timeInput.value) errors.hora = "Elige un horario disponible.";
    if (guests < 1 || guests > maxGuests) errors.comensales = "Elige entre 1 y " + maxGuests + " personas.";
    if (sessionVerified !== true || !verifiedContactMatchesForm()) {
      var contactValue = contactInput.value.trim();
      if (!contactValue) {
        errors.contacto = "Escribe el contacto que verificaremos.";
      } else if (selectedContactType() === "email"
        && !contactIsValid()) {
        errors.contacto = "El correo no es válido.";
      } else if (selectedContactType() === "telefono"
        && !contactIsValid()) {
        errors.contacto = "El teléfono no es válido.";
      }
    }
    Object.keys(errors).forEach(function(field) { setFieldError(field, errors[field]); });
    return Object.keys(errors).length === 0;
  }

  function payload() {
    return {
      nombre: form.elements.nombre.value.trim(),
      tipo_contacto: selectedContactType(),
      contacto: contactInput ? contactInput.value.trim() : "",
      fecha: dateInput.value,
      hora: timeInput.value,
      personas: guests,
      notas: form.elements.nota.value.trim(),
      request_token: form.elements.request_token.value,
      csrf_token: csrfToken
    };
  }

  function payloadFingerprint(requestPayload) {
    return JSON.stringify({
      nombre: requestPayload.nombre,
      tipo_contacto: requestPayload.tipo_contacto,
      contacto: normalizeContact(requestPayload.tipo_contacto, requestPayload.contacto),
      fecha: requestPayload.fecha,
      hora: requestPayload.hora,
      personas: requestPayload.personas,
      notas: requestPayload.notas
    });
  }

  function showConfirmation(data) {
    clearInterval(countdownTimer);
    form.hidden = true;
    otpStep.hidden = true;
    confirm.classList.add("show");
    var reservation = data.reservation || {};
    var confirmedDate = reservation.fecha || dateInput.value;
    var confirmedTime = reservation.hora || timeInput.value;
    var confirmedGuests = reservation.comensales || guests;
    var confirmedName = reservation.nombre || form.elements.nombre.value.trim() || "Invitado";
    var confirmedContact = currentContact().trim() || "Contacto verificado";
    confirmText.textContent = "Te esperamos en Casa Pestalozzi.";
    if (confirmDate) confirmDate.textContent = formatReservationDate(confirmedDate);
    if (confirmTime) confirmTime.textContent = confirmedTime;
    if (confirmGuests) confirmGuests.textContent = confirmedGuests + (confirmedGuests === 1 ? " persona" : " personas");
    if (confirmName) confirmName.textContent = confirmedName;
    if (confirmContact) confirmContact.textContent = confirmedContact;
    sessionVerified = true;
    setVerifiedContact(
      activeIdentity ? activeIdentity.tipo : selectedContactType(),
      activeIdentity ? activeIdentity.contacto : currentContact()
    );
    window.CP_RESERVATION_SESSION = true;
    window.dispatchEvent(new CustomEvent("reservation:sessionchange", {
      detail: {
        verified: true,
        tipo: verifiedContact ? verifiedContact.tipo : "",
        contacto: verifiedContact ? verifiedContact.contacto : ""
      }
    }));
    if (window.ScrollTrigger) window.ScrollTrigger.refresh();
  }

  function renderPreview(code) {
    otpPreview.replaceChildren();
    otpPreview.hidden = !code;
    if (!code) return;
    var title = document.createElement("strong");
    title.textContent = "Modo de desarrollo";
    var text = document.createElement("span");
    text.textContent = "Código de prueba: " + code;
    var use = document.createElement("button");
    use.type = "button";
    use.className = "reservation-access__link";
    use.textContent = "Usar código";
    use.addEventListener("click", function() {
      otpInput.value = code;
      otpInput.focus();
    });
    otpPreview.append(title, text, use);
  }

  function startCountdown(value) {
    holdExpiresAt = Date.parse(value || "");
    clearInterval(countdownTimer);
    function tick() {
      var remaining = Math.max(0, holdExpiresAt - Date.now());
      var seconds = Math.ceil(remaining / 1000);
      var minutes = Math.floor(seconds / 60);
      var rest = String(seconds % 60).padStart(2, "0");
      countdown.textContent = remaining > 0
        ? "Tu retención vence en " + minutes + ":" + rest + "."
        : "La retención venció. Vuelve a elegir un horario.";
      verifyButton.disabled = remaining <= 0;
      if (remaining <= 0) handleHoldExpired();
    }
    tick();
    if (holdExpiresAt > Date.now()) countdownTimer = setInterval(tick, 1000);
  }

  function handleHoldExpired() {
    if (holdExpiryHandled) return;
    holdExpiryHandled = true;
    clearInterval(countdownTimer);
    countdownTimer = null;
    activeIdentity = null;
    rotateRequestToken();
    form.hidden = false;
    otpStep.hidden = true;
    if (confirm) confirm.classList.remove("show");
    otpInput.value = "";
    clearOtpError();
    setCurrentStep(1, false);
    reservationState.maxCompletedStep = 0;
    reservationState.time = "";
    reservationState.availabilityStatus = "idle";
    reservationState.availabilityPending = false;
    if (timePicker && typeof timePicker.clear === "function") timePicker.clear(true);
    if (timePicker && typeof timePicker.setDisabled === "function") timePicker.setDisabled(false);
    timeInput.value = "";
    setMessage("El tiempo para confirmar terminó. Elige un horario nuevamente.", true);
    reloadAvailability(true);
    updateInterface();
    if (window.ScrollTrigger) window.ScrollTrigger.refresh();
  }

  function showOtp(data, requestPayload) {
    activeIdentity = {
      tipo: requestPayload.tipo_contacto,
      contacto: requestPayload.contacto,
      request_token: requestPayload.request_token,
      csrf_token: csrfToken
    };
    holdExpiryHandled = false;
    form.hidden = true;
    otpStep.hidden = false;
    if (otpContact) otpContact.textContent = requestPayload.contacto;
    otpMessage.textContent = data.mensaje || "";
    otpInput.value = "";
    clearOtpError();
    renderPreview(data.preview_code || "");
    startCountdown(data.hold_expires_at || data.otp_expires_at);
    otpInput.focus();
    if (window.ScrollTrigger) window.ScrollTrigger.refresh();
  }

  function submitReservation(requestPayload) {
    var endpoint = sessionVerified === true && verifiedContactMatchesForm()
      ? "/api/reservaciones/crear"
      : "/api/reservaciones/retencion";
    return jsonRequest(endpoint, {
      method: "POST",
      body: JSON.stringify(requestPayload)
    }).then(function(data) {
      if (data.ok && data.codigo === "RETENCION_CREADA") {
        showOtp(data, requestPayload);
        return;
      }
      if (data.ok) {
        showConfirmation(data);
        return;
      }
      if (data.httpStatus === 401 && sessionVerified === true) {
        invalidateVerifiedContact();
        setCurrentStep(2);
        setMessage("Tu autorización temporal venció. Confirma nuevamente tu contacto.", true);
        return;
      }
      if (data.codigo === "RETENCION_EXPIRADA") {
        handleHoldExpired();
        return;
      }
      if (data.codigo === "REQUEST_TOKEN_CONFLICTO") {
        rotateRequestToken();
        setMessage("La solicitud cambió. Revisa los datos y vuelve a intentarlo.", true);
        return;
      }
      setMessage(data.mensaje || "No fue posible crear la reservación.", true);
    }).catch(function() {
      setMessage("No fue posible crear la reservación.", true);
    }).finally(function() {
      submitting = false;
      updateInterface();
    });
  }

  form.addEventListener("submit", function(event) {
    event.preventDefault();
    if (reservationState.currentStep !== 4) {
      var activeNext = form.querySelector(
        '[data-reservation-step="' + reservationState.currentStep + '"] [data-reservation-next]'
      );
      if (activeNext && !activeNext.disabled) activeNext.click();
      return;
    }
    clearErrors();
    if (!validate() || !visitIsValid() || reservationState.availabilityPending) {
      setMessage("Revisa los datos de tu reservación.", true);
      return;
    }
    submitting = true;
    updateInterface();
    var requestPayload = payload();
    var fingerprint = payloadFingerprint(requestPayload);
    if (lastSubmittedFingerprint && lastSubmittedFingerprint !== fingerprint) {
      rotateRequestToken();
      requestPayload.request_token = form.elements.request_token.value;
    }
    lastSubmittedFingerprint = fingerprint;
    submitReservation(requestPayload);
  });

  verifyButton.addEventListener("click", function() {
    if (!activeIdentity) return;
    var code = otpInput.value.replace(/\D/g, "").slice(0, 6);
    otpInput.value = code;
    otpMessage.textContent = "";
    if (!/^\d{6}$/.test(code)) {
      setOtpError("Escribe el código de seis dígitos.");
      otpMessage.textContent = "Revisa el código antes de continuar.";
      otpInput.focus();
      otpStep.classList.remove("reservation-step--error-shake");
      void otpStep.offsetWidth;
      otpStep.classList.add("reservation-step--error-shake");
      window.setTimeout(function() {
        otpStep.classList.remove("reservation-step--error-shake");
      }, 240);
      return;
    }
    clearOtpError();
    jsonRequest("/api/reservaciones/contacto/verificar", {
      method: "POST",
      body: JSON.stringify({
        tipo: activeIdentity.tipo,
        contacto: activeIdentity.contacto,
        codigo: code,
        request_token: activeIdentity.request_token,
        csrf_token: activeIdentity.csrf_token
      })
    }).then(function(data) {
      if (!data.ok) {
        if (data.codigo === "RETENCION_EXPIRADA") {
          handleHoldExpired();
          return;
        }
        setOtpError(data.mensaje || "El código no es válido.");
        otpMessage.textContent = data.mensaje || "No fue posible verificar el código.";
        otpInput.focus();
        return;
      }
      sessionVerified = true;
      showConfirmation(data);
    }).catch(function() {
      setOtpError("No fue posible verificar el código.");
      otpMessage.textContent = "No fue posible verificar el código.";
    });
  });

  resendButton.addEventListener("click", function() {
    if (!activeIdentity) return;
    otpMessage.textContent = "";
    jsonRequest("/api/reservaciones/contacto/codigo", {
      method: "POST",
      body: JSON.stringify(activeIdentity)
    }).then(function(data) {
      otpMessage.textContent = data.mensaje || "";
      if (data.ok) {
        renderPreview(data.preview_code || "");
      } else if (data.codigo === "RETENCION_EXPIRADA") {
        handleHoldExpired();
      }
    }).catch(function() {
      otpMessage.textContent = "No fue posible reenviar el código.";
    });
  });

  var availabilityRefreshInterval = window.setInterval(function() {
    if (document.hidden || submitting || reservationState.availabilityPending) return;
    if (!reservationState.date) return;
    reloadAvailability(true);
  }, 60000);

  window.addEventListener("pagehide", function() {
    window.clearInterval(availabilityRefreshInterval);
  }, { once: true });
}
