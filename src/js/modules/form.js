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
      enabledWeekdays: wrap.getAttribute("data-enabled-weekdays")
    });
  }

  var label = cal.querySelector(".cpc-label");
  var grid = cal.querySelector(".cpc-grid");
  var prevBtn = cal.querySelector(".cpc-prev");
  var nextBtn = cal.querySelector(".cpc-next");

  var today = new Date();
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
        return { personas: typeof getGuestCount === "function" ? getGuestCount() : 2 };
      },
      initialDate: dateInput.value,
      initialTime: hidden.value
    });

    if (picker) {
      dateInput.addEventListener("reservation:datechange", function(e) {
        picker.loadForDate((e.detail && e.detail.fecha) || dateInput.value, "");
      });

      return picker;
    }
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

    setLoading();
    abortController = typeof AbortController !== "undefined" ? new AbortController() : null;

    fetch(endpoint + "?fecha=" + encodeURIComponent(fecha), {
      headers: { "Accept": "application/json" },
      signal: abortController ? abortController.signal : undefined
    })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (currentRequest !== requestId) return;
        form.dispatchEvent(new CustomEvent("reservation:scheduleloaded", {
          detail: res || {}
        }));

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

        renderHours(Array.isArray(res.horarios) ? res.horarios : []);
      })
      .catch(function(error) {
        if (error && error.name === "AbortError") return;
        if (currentRequest !== requestId) return;
        setDisabled("Elige una hora");
        setStatus("No fue posible consultar los horarios. Inténtalo nuevamente.", true);
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

  var maxGuests = parseInt(form.getAttribute("data-max-guests"), 10) || 12;
  var guests = 2;
  var guestCount = 6;
  var identity = form.querySelector("[data-new-reservation-identity]");
  var contactField = form.querySelector("[data-new-reservation-contact]");
  var contactInput = form.elements.contacto;
  var summary = form.querySelector("[data-reservation-selection-summary]");
  var dateInput = document.getElementById("fechaHidden");
  var timeInput = document.getElementById("horaHidden");
  var submitButton = form.querySelector('button[type="submit"]');
  var message = document.getElementById("formMsg");
  var otpStep = document.querySelector("[data-new-reservation-otp]");
  var otpInput = otpStep && otpStep.querySelector("[data-new-reservation-otp-input]");
  var otpMessage = otpStep && otpStep.querySelector("[data-new-reservation-otp-message]");
  var otpPreview = otpStep && otpStep.querySelector("[data-new-reservation-preview]");
  var countdown = otpStep && otpStep.querySelector("[data-new-reservation-countdown]");
  var verifyButton = otpStep && otpStep.querySelector("[data-new-reservation-verify]");
  var resendButton = otpStep && otpStep.querySelector("[data-new-reservation-resend]");
  var confirm = document.getElementById("reservaConfirm");
  var confirmText = document.getElementById("confirmText");
  var sessionVerified = null;
  var submitting = false;
  var activeIdentity = null;
  var holdExpiresAt = 0;
  var countdownTimer = null;
  var availabilityTimer = null;
  var timePicker;

  function randomToken() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") {
      return window.crypto.randomUUID().replace(/-/g, "") + Date.now().toString(36);
    }
    return Date.now().toString(36) + Math.random().toString(36).slice(2)
      + Math.random().toString(36).slice(2);
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
    var phone = selectedContactType() === "telefono";
    contactInput.type = phone ? "tel" : "email";
    contactInput.autocomplete = phone ? "tel" : "email";
    contactInput.placeholder = phone ? "+52 55 1234 5678" : "tu@correo.com";
  }

  form.querySelectorAll('input[name="tipo_contacto"]').forEach(function(input) {
    input.addEventListener("change", syncContactInput);
  });
  syncContactInput();

  function setMessage(text, error) {
    message.textContent = text || "";
    message.classList.toggle("show", Boolean(text));
    message.classList.toggle("is-error", Boolean(error));
  }

  function setFieldError(field, text) {
    var target = form.querySelector('[data-field-error="' + field + '"]');
    if (!target) return;
    target.textContent = text || "";
    target.classList.toggle("show", Boolean(text));
  }

  function clearErrors() {
    form.querySelectorAll("[data-field-error]").forEach(function(target) {
      target.textContent = "";
      target.classList.remove("show");
    });
    setMessage("");
  }

  function updateIdentity() {
    var selectionReady = Boolean(dateInput.value && timeInput.value);
    identity.hidden = !selectionReady;
    contactField.hidden = sessionVerified === true;
    if (selectionReady) {
      summary.textContent = guests + (guests === 1 ? " persona" : " personas")
        + " · " + dateInput.value + " · " + timeInput.value;
      summary.hidden = false;
    } else {
      summary.hidden = true;
    }
    submitButton.disabled = submitting || !selectionReady;
  }

  function reloadAvailability() {
    clearTimeout(availabilityTimer);
    availabilityTimer = setTimeout(function() {
      if (dateInput.value && timePicker && typeof timePicker.loadForDate === "function") {
        timePicker.loadForDate(dateInput.value, "");
      }
    }, 300);
  }

  function setGuests(value) {
    guests = Math.max(1, Math.min(maxGuests, parseInt(value, 10) || 2));
    document.getElementById("guestsVal").textContent = Math.max(6, guests);
    document.getElementById("guestsNum").value = Math.max(6, guests);
    document.getElementById("guestsExtra").classList.toggle("show", guests >= 6);
    var largeParty = document.getElementById("largeParty");
    if (largeParty) largeParty.hidden = guests < maxGuests;
    updateIdentity();
    reloadAvailability();
  }

  document.querySelectorAll("#guestPills .pill").forEach(function(pill) {
    pill.addEventListener("click", function() {
      document.querySelectorAll("#guestPills .pill").forEach(function(item) {
        item.classList.remove("sel");
      });
      pill.classList.add("sel");
      var value = pill.getAttribute("data-g");
      setGuests(value === "6+" ? guestCount : value);
    });
  });
  document.getElementById("guestsPlus").addEventListener("click", function() {
    guestCount = Math.min(maxGuests, guestCount + 1);
    setGuests(guestCount);
  });
  document.getElementById("guestsMinus").addEventListener("click", function() {
    guestCount = Math.max(6, guestCount - 1);
    setGuests(guestCount);
  });

  initCalendar();
  initScheduleChanges();
  initSpecialScheduleNotice(form);
  timePicker = initHourPicker(form, function() { return guests; });
  dateInput.addEventListener("reservation:datechange", updateIdentity);
  timeInput.addEventListener("reservation:timechange", updateIdentity);
  setGuests(2);

  jsonRequest("/api/reservaciones/mis-reservaciones", {
    method: "GET",
    headers: { "Accept": "application/json" }
  }).then(function(data) {
    sessionVerified = Boolean(data.ok);
    window.CP_RESERVATION_SESSION = sessionVerified;
    updateIdentity();
  }).catch(function() {
    sessionVerified = false;
    updateIdentity();
  });

  function validate() {
    var errors = {};
    if (!form.elements.nombre.value.trim()) errors.nombre = "Escribe tu nombre.";
    if (!dateInput.value) errors.fecha = "Elige una fecha.";
    if (!timeInput.value) errors.hora = "Elige un horario disponible.";
    if (guests < 1 || guests > maxGuests) errors.comensales = "Elige entre 1 y 12 personas.";
    if (sessionVerified !== true && !contactInput.value.trim()) {
      errors.contacto = "Escribe el contacto que verificaremos.";
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
      request_token: form.elements.request_token.value
    };
  }

  function showConfirmation(data) {
    clearInterval(countdownTimer);
    form.hidden = true;
    otpStep.hidden = true;
    confirm.classList.add("show");
    var reservation = data.reservation || {};
    confirmText.textContent = "Mesa para " + (reservation.comensales || guests)
      + " el " + (reservation.fecha || dateInput.value)
      + " a las " + (reservation.hora || timeInput.value) + ".";
    window.CP_RESERVATION_SESSION = true;
    window.dispatchEvent(new CustomEvent("reservation:sessionchange", { detail: { verified: true } }));
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
    }
    tick();
    countdownTimer = setInterval(tick, 1000);
  }

  function showOtp(data, requestPayload) {
    activeIdentity = {
      tipo: requestPayload.tipo_contacto,
      contacto: requestPayload.contacto,
      request_token: requestPayload.request_token
    };
    form.hidden = true;
    otpStep.hidden = false;
    otpMessage.textContent = data.mensaje || "";
    otpInput.value = "";
    renderPreview(data.preview_code || "");
    startCountdown(data.hold_expires_at || data.otp_expires_at);
    otpInput.focus();
    if (window.ScrollTrigger) window.ScrollTrigger.refresh();
  }

  function submitReservation(requestPayload) {
    var endpoint = sessionVerified === true
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
        sessionVerified = false;
        window.CP_RESERVATION_SESSION = false;
        updateIdentity();
        setMessage("Tu sesión venció. Captura el contacto para crear una retención.", true);
        return;
      }
      setMessage(data.mensaje || "No fue posible crear la reservación.", true);
    }).catch(function() {
      setMessage("No fue posible crear la reservación.", true);
    }).finally(function() {
      submitting = false;
      updateIdentity();
    });
  }

  form.addEventListener("submit", function(event) {
    event.preventDefault();
    clearErrors();
    if (!validate()) {
      setMessage("Revisa los datos de tu reservación.", true);
      return;
    }
    submitting = true;
    updateIdentity();
    submitReservation(payload());
  });

  verifyButton.addEventListener("click", function() {
    if (!activeIdentity) return;
    var code = otpInput.value.replace(/\D/g, "").slice(0, 6);
    otpInput.value = code;
    otpMessage.textContent = "";
    jsonRequest("/api/reservaciones/contacto/verificar", {
      method: "POST",
      body: JSON.stringify({
        tipo: activeIdentity.tipo,
        contacto: activeIdentity.contacto,
        codigo: code,
        request_token: activeIdentity.request_token
      })
    }).then(function(data) {
      if (!data.ok) {
        otpMessage.textContent = data.mensaje || "No fue posible verificar el código.";
        return;
      }
      sessionVerified = true;
      showConfirmation(data);
    }).catch(function() {
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
      }
    }).catch(function() {
      otpMessage.textContent = "No fue posible reenviar el código.";
    });
  });
}
