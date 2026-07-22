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
function initHourPicker(form) {
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

/* ---- Formulario ---- */
function initForm() {
  var form = $("#reservaForm");
  if (!form) return;

  var pills = $$("#guestPills .pill");
  var extra = $("#guestsExtra");
  var maxGuests = parseInt(form.getAttribute("data-max-guests"), 10) || 12;
  var guests = 2;
  var stepVal = $("#guestsVal");
  var stepHid = $("#guestsNum");
  var stepMinus = $("#guestsMinus");
  var stepPlus = $("#guestsPlus");
  var largeParty = $("#largeParty");
  var guestCount = 6;

  function setLargePartyVisible(visible) {
    if (!largeParty) return;
    largeParty.hidden = !visible;
    largeParty.setAttribute("aria-hidden", visible ? "false" : "true");
  }

  function syncLargePartyNotice() {
    setLargePartyVisible(guests === maxGuests);
  }

  function updateStepper() {
    guestCount = Math.max(6, Math.min(maxGuests, guestCount));
    stepVal.textContent = guestCount;
    stepHid.value = guestCount;
    stepMinus.disabled = guestCount <= 6;
    stepPlus.disabled = guestCount >= maxGuests;
    guests = guestCount;
    syncLargePartyNotice();
  }

  pills.forEach(function(p) {
    p.addEventListener("click", function() {
      pills.forEach(function(x) { x.classList.remove("sel"); });
      p.classList.add("sel");
      var g = p.getAttribute("data-g");
      clearFieldError("comensales");
      setLargePartyVisible(false);

      if (g === "6+") {
        extra.classList.add("show");
        guests = guestCount || 6;
        updateStepper();
      } else {
        extra.classList.remove("show");
        guests = parseInt(g, 10) || 2;
        syncLargePartyNotice();
      }
    });
  });

  stepPlus.addEventListener("click", function() {
    if (guestCount < maxGuests) {
      guestCount += 1;
    }
    clearFieldError("comensales");
    updateStepper();
  });

  stepMinus.addEventListener("click", function() {
    guestCount = Math.max(6, guestCount - 1);
    clearFieldError("comensales");
    updateStepper();
  });

  initCalendar();
  initHourPicker(form);
  updateStepper();

  var msg = $("#formMsg");
  var submitButton = form.querySelector('button[type="submit"]');
  var timeInput = $("#horaHidden");
  var isSubmitting = false;
  var confirm = $("#reservaConfirm");
  var confirmMark = confirm ? confirm.querySelector(".mark") : null;
  var confirmTitle = confirm ? confirm.querySelector("h3") : null;
  var confirmText = $("#confirmText");

  function updateSubmitState() {
    if (!submitButton) return;
    submitButton.disabled = isSubmitting || !timeInput || !timeInput.value;
  }

  if (timeInput) {
    timeInput.addEventListener("reservation:timechange", updateSubmitState);
  }
  updateSubmitState();

  function fieldErrorEl(field) {
    return form.querySelector('[data-field-error="' + field + '"]');
  }

  function setFieldError(field, text) {
    var el = fieldErrorEl(field);
    if (!el) return;
    el.textContent = text || "";
    el.classList.toggle("show", Boolean(text));
  }

  function clearFieldError(field) {
    setFieldError(field, "");
  }

  function clearFieldErrors() {
    form.querySelectorAll("[data-field-error]").forEach(function(el) {
      el.textContent = "";
      el.classList.remove("show");
    });
  }

  function applyErrors(errors) {
    Object.keys(errors || {}).forEach(function(field) {
      var messages = errors[field];
      setFieldError(field, Array.isArray(messages) ? messages[0] : String(messages || ""));
    });
  }

  function showInlineMessage(text) {
    msg.textContent = text;
    msg.classList.add("show");
    if (!reduce && window.gsap) gsap.fromTo(form, { x: -6 }, { x: 0, duration: 0.4, ease: "elastic.out(1,0.3)" });
  }

  function hideInlineMessage() {
    msg.classList.remove("show");
    msg.textContent = "";
  }

  function showConfirmation(options) {
    if (!confirm || !confirmText || !confirmTitle || !confirmMark) return;

    confirm.classList.remove("reserva__confirm--warning");
    if (options.warning) confirm.classList.add("reserva__confirm--warning");

    confirmMark.textContent = options.warning ? "!" : "✓";
    confirmTitle.textContent = options.title;
    confirmText.textContent = options.text;

    form.style.display = "none";
    confirm.classList.add("show");
    if (window.ScrollTrigger) ScrollTrigger.refresh();
  }

  function validateClient(nombre, email, fecha, hora) {
    var errors = {};

    if (!nombre) errors.nombre = ["Escribe tu nombre para la reservacion."];
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) errors.email = ["Escribe un correo electronico valido."];
    if (!fecha) errors.fecha = ["Elige una fecha para tu reservacion."];
    if (!hora) errors.hora = ["Elige un horario disponible."];
    if (guests < 1 || guests > maxGuests) errors.comensales = ["Las reservaciones en linea son de 1 a 12 personas."];
    if (form.nota && form.nota.value.trim().length > 500) errors.nota = ["La nota es demasiado larga. Usa maximo 500 caracteres."];

    return errors;
  }

  form.addEventListener("submit", function(e) {
    e.preventDefault();
    clearFieldErrors();
    hideInlineMessage();

    var nombre = form.nombre.value.trim();
    var email = form.email.value.trim();
    var fecha = $("#fechaHidden").value;
    var hora = $("#horaHidden").value;
    var clientErrors = validateClient(nombre, email, fecha, hora);

    if (Object.keys(clientErrors).length) {
      applyErrors(clientErrors);
      showInlineMessage("Revisa los datos de tu reservacion.");
      if (clientErrors.comensales) setLargePartyVisible(guests >= maxGuests);
      return;
    }

    var data = new FormData();
    data.append("nombre", nombre);
    data.append("email", email);
    data.append("fecha", fecha);
    data.append("hora", hora);
    data.append("comensales", guests);
    data.append("nota", form.nota ? form.nota.value.trim() : "");
    data.append("request_token", form.request_token ? form.request_token.value : "");

    isSubmitting = true;
    updateSubmitState();

    fetch("/reservar", { method: "POST", body: data })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (!res.ok) {
          isSubmitting = false;
          updateSubmitState();
          applyErrors(res.errors || {});
          showInlineMessage(res.msg || "No pudimos registrar tu reservacion. Intenta nuevamente.");
          if (res.errors && res.errors.comensales) setLargePartyVisible(guests >= maxGuests);
          return;
        }

        var fd = new Date(fecha + "T00:00:00");
        var fmt = fd.toLocaleDateString("es-MX", { weekday: "long", day: "numeric", month: "long" });

        if (res.requiere_confirmacion === true || res.warning) {
          showConfirmation({
            warning: true,
            title: "Solicitud recibida",
            text: "Recibimos tu solicitud, pero todavia requiere confirmacion del restaurante."
          });
          return;
        }

        showConfirmation({
          warning: false,
          title: "¡Mesa reservada!",
          text: "Gracias, " + nombre + ". Mesa para " + guests + " el " + fmt + " a las " + hora + ". Te esperamos."
        });
      })
      .catch(function() {
        isSubmitting = false;
        updateSubmitState();
        showInlineMessage("No fue posible registrar tu reservacion. Intentalo nuevamente.");
      });
  });
}
