/**
 * Shared reservation time picker.
 */
(function () {
  function normalizeHour(value) {
    var match = String(value || "").match(/^([01]\d|2[0-3]):([0-5]\d)/);
    return match ? match[1] + ":" + match[2] : "";
  }

  window.createReservationTimePicker = function createReservationTimePicker(options) {
    options = options || {};

    var root = options.root;
    if (!root) return null;

    var display = options.display || root.querySelector("[data-time-display]") || root.querySelector(".hour-display");
    var input = options.input || root.querySelector("[data-time-input]") || root.querySelector('input[type="hidden"]');
    var dropdown = options.dropdown || root.querySelector("[data-time-dropdown]") || root.querySelector(".hour-dropdown");
    var status = options.status || null;
    var endpoint = options.endpoint || root.getAttribute("data-schedules-endpoint") || "";
    var enabled = false;
    var requestId = 0;

    if (!display || !input || !dropdown || !endpoint) {
      return null;
    }

    function emitChange() {
      var event;
      try {
        event = new CustomEvent("reservation:timechange", { detail: { hora: input.value } });
      } catch (error) {
        event = document.createEvent("CustomEvent");
        event.initCustomEvent("reservation:timechange", true, true, { hora: input.value });
      }
      input.dispatchEvent(event);
      if (typeof options.onChange === "function") {
        options.onChange(input.value);
      }
    }

    function setStatus(text, show) {
      if (!status) return;
      status.textContent = text || "";
      status.classList.toggle("show", Boolean(show && text));
    }

    function closeDropdown() {
      dropdown.classList.remove("open");
      dropdown.setAttribute("aria-hidden", "true");
      display.setAttribute("aria-expanded", "false");
    }

    function clearValue(silent) {
      input.value = "";
      display.value = "";
      dropdown.querySelectorAll(".hour-option").forEach(function (button) {
        button.classList.remove("sel");
        button.setAttribute("aria-pressed", "false");
        button.setAttribute("aria-selected", "false");
      });
      if (!silent) emitChange();
    }

    function setDisabled(text, keepStatus) {
      enabled = false;
      clearValue(true);
      display.placeholder = text || "Elige una hora";
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      dropdown.innerHTML = "";
      closeDropdown();
      if (!keepStatus) setStatus("", false);
      emitChange();
    }

    function setLoading(preferredHour) {
      enabled = false;
      preferredHour = normalizeHour(preferredHour);
      if (preferredHour) {
        input.value = preferredHour;
        display.value = preferredHour;
      } else {
        clearValue(true);
      }
      display.placeholder = "Consultando horarios...";
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      dropdown.innerHTML = "";
      closeDropdown();
      setStatus("Consultando horarios disponibles...", true);
      emitChange();
    }

    function keepCurrentAfterLookupError(hour, message) {
      hour = normalizeHour(hour);
      if (!hour) {
        setDisabled("Elige una hora", true);
        setStatus(message, true);
        return;
      }

      enabled = false;
      input.value = hour;
      display.value = hour;
      display.placeholder = "Elige una hora";
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      dropdown.innerHTML = "";
      closeDropdown();
      setStatus(message, true);
      emitChange();
    }

    function selectHour(hour, silent) {
      hour = normalizeHour(hour);
      input.value = hour;
      display.value = hour;
      dropdown.querySelectorAll(".hour-option").forEach(function (button) {
        var selected = button.getAttribute("data-hour") === hour;
        button.classList.toggle("sel", selected);
        button.setAttribute("aria-pressed", selected ? "true" : "false");
        button.setAttribute("aria-selected", selected ? "true" : "false");
      });
      setStatus("", false);
      closeDropdown();
      if (!silent) emitChange();
    }

    function renderHours(hours, preferredHour) {
      var normalized = [];
      var seen = {};
      preferredHour = normalizeHour(preferredHour);
      dropdown.innerHTML = "";

      hours.forEach(function (hour) {
        hour = normalizeHour(hour);
        if (!hour || seen[hour]) return;
        seen[hour] = true;
        normalized.push(hour);
      });

      if (!normalized.length) {
        setDisabled("Sin horarios disponibles", true);
        setStatus("No hay horarios disponibles para la fecha seleccionada.", true);
        return;
      }

      enabled = true;
      root.classList.remove("is-disabled");
      display.removeAttribute("aria-disabled");
      display.placeholder = "Elige una hora";
      clearValue(true);
      setStatus("", false);

      normalized.forEach(function (hour) {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "hour-option";
        button.textContent = hour;
        button.setAttribute("data-hour", hour);
        button.setAttribute("role", "option");
        button.setAttribute("aria-pressed", "false");
        button.setAttribute("aria-selected", "false");
        button.addEventListener("click", function (event) {
          event.stopPropagation();
          selectHour(hour, false);
        });
        dropdown.appendChild(button);
      });

      if (preferredHour && seen[preferredHour]) {
        selectHour(preferredHour, true);
      } else {
        emitChange();
      }
    }

    function loadForDate(fecha, preferredHour) {
      requestId++;
      var currentRequest = requestId;
      preferredHour = normalizeHour(preferredHour);

      if (!fecha) {
        setDisabled("Elige una fecha primero");
        return Promise.resolve([]);
      }

      setLoading(preferredHour);

      return fetch(endpoint + "?fecha=" + encodeURIComponent(fecha), {
        headers: { "Accept": "application/json" },
        credentials: "same-origin"
      })
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (currentRequest !== requestId) return [];

          if (!data.ok) {
            setDisabled("Elige una hora", true);
            setStatus((data.errors && data.errors.fecha && data.errors.fecha[0]) || data.msg || "No hay horarios disponibles para la fecha seleccionada.", true);
            return [];
          }

          if (data.dia_activo === false) {
            setDisabled("Dia sin reservaciones", true);
            setStatus("El restaurante no recibe reservaciones en esa fecha.", true);
            return [];
          }

          renderHours(Array.isArray(data.horarios) ? data.horarios : [], preferredHour);
          return data.horarios || [];
        })
        .catch(function () {
          if (currentRequest !== requestId) return [];
          keepCurrentAfterLookupError(preferredHour, "No fue posible consultar los horarios. Intenta nuevamente.");
          return [];
        });
    }

    display.addEventListener("click", function (event) {
      event.stopPropagation();
      if (!enabled || display.disabled) return;
      dropdown.classList.toggle("open");
      dropdown.setAttribute("aria-hidden", dropdown.classList.contains("open") ? "false" : "true");
      display.setAttribute("aria-expanded", dropdown.classList.contains("open") ? "true" : "false");
    });

    display.addEventListener("keydown", function (event) {
      if ((event.key === "Enter" || event.key === " ") && enabled && !display.disabled) {
        event.preventDefault();
        dropdown.classList.add("open");
        dropdown.setAttribute("aria-hidden", "false");
        display.setAttribute("aria-expanded", "true");
      }
      if (event.key === "Escape") {
        closeDropdown();
      }
    });

    document.addEventListener("click", function (event) {
      if (!root.contains(event.target)) {
        closeDropdown();
      }
    });

    var initialTime = normalizeHour(options.initialTime || input.value);

    if (options.autoLoad === false) {
      enabled = false;
      input.value = initialTime;
      display.value = initialTime;
      display.placeholder = initialTime ? "Elige una hora" : "Elige una fecha primero";
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      dropdown.innerHTML = "";
      closeDropdown();
    } else {
      setDisabled("Elige una fecha primero");
    }

    if (options.autoLoad !== false && options.initialDate) {
      loadForDate(options.initialDate, options.initialTime || input.value);
    } else if (initialTime) {
      input.value = initialTime;
      display.value = initialTime;
    }

    return {
      loadForDate: loadForDate,
      setDisabled: function (disabled) {
        display.disabled = Boolean(disabled);
        input.disabled = Boolean(disabled);
        display.setAttribute("aria-disabled", disabled ? "true" : "false");
        root.classList.toggle("is-disabled", Boolean(disabled));
        if (disabled) closeDropdown();
      },
      clear: function () {
        setDisabled("Elige una fecha primero");
      },
      getValue: function () {
        return input.value;
      }
    };
  };
})();
