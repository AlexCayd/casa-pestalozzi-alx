/**
 * Shared reservation time picker.
 */
(function () {
  function normalizeHour(value) {
    var match = String(value || "").match(/^([01]\d|2[0-3]):([0-5]\d)/);
    return match ? match[1] + ":" + match[2] : "";
  }

  function buildStaticHours(step) {
    var hours = [];
    step = parseInt(step || "0", 10);
    if (!step || step < 1 || step > 60) return hours;

    for (var minute = 0; minute < 24 * 60; minute += step) {
      var hour = Math.floor(minute / 60);
      var minutePart = minute % 60;
      hours.push((hour < 10 ? "0" : "") + hour + ":" + (minutePart < 10 ? "0" : "") + minutePart);
    }
    return hours;
  }

  window.createReservationTimePicker = function createReservationTimePicker(options) {
    options = options || {};

    var root = options.root;
    if (!root) return null;

    var display = options.display || root.querySelector("[data-time-display]") || root.querySelector(".hour-display");
    var input = options.input || root.querySelector("[data-time-input]") || root.querySelector('input[type="hidden"]');
    var dropdown = options.dropdown || root.querySelector("[data-time-dropdown]") || root.querySelector(".hour-dropdown");
    var optionsList = options.optionsList || root.querySelector("[data-time-options]") || dropdown;
    var status = options.status || null;
    var endpoint = options.endpoint || root.getAttribute("data-schedules-endpoint") || "";
    var staticStep = options.staticStep || root.getAttribute("data-static-step") || 0;
    var invalidateUnavailable = options.invalidateUnavailable === true;
    var initiallyDisabled = Boolean(display && display.disabled) || Boolean(input && input.disabled);
    var controlDisabled = initiallyDisabled;
    var enabled = false;
    var availableHours = [];
    var lastAlternatives = [];
    var requestId = 0;
    var abortController = null;
    var requestTimeoutId = null;
    var requestTimeoutMs = parseInt(options.requestTimeoutMs, 10) || 10000;
    // Modo inline: rejilla de horarios siempre visible. Opt-in por marcado para
    // no tocar a ningún consumidor del desplegable.
    var inline = options.inline === true || root.getAttribute("data-inline") === "1";

    if (!display || !input || !dropdown || !optionsList) {
      return null;
    }

    function syncValueState() {
      root.classList.toggle("has-value", Boolean(input.value));
    }

    // Lenis cancela el `wheel` de TODO lo que lleve data-lenis-prevent en su
    // camino, tenga scroll propio o no. El atributo estaba fijo en el marcado y
    // en escritorio la rejilla inline no desborda —el modo inline le quita el
    // max-height y la deja en `overflow-y: visible`—, así que la rueda sobre las
    // horas no movía ni el contenedor ni la página: la reserva se sentía
    // trabada. Ahora la marca describe la realidad y se revisa cada vez que
    // cambia el alto de la rejilla.
    function marcarScrollPropio() {
      if (optionsList.scrollHeight > optionsList.clientHeight + 1) {
        optionsList.setAttribute("data-lenis-prevent", "");
      } else {
        optionsList.removeAttribute("data-lenis-prevent");
      }
    }

    // Reparto de la rejilla en columnas iguales.
    //
    // Con el chip creciendo libre, cada línea metía cuantos cupieran y el
    // resultado dependía del número de horas: la tarde salía 7+5 y llenaba el
    // ancho, pero la mañana caía en 7+2 y la noche —cuatro horas— dejaba media
    // línea vacía. Aquí se busca cuántas columnas reparten mejor las horas
    // VISIBLES y el chip pasa a medir una columna exacta: las filas completas
    // llenan el ancho y una última fila corta queda centrada bajo la anterior.
    function repartirColumnas() {
      if (!inline) return;
      // Sin la variable el chip vuelve a su base, que es su ancho mínimo: es lo
      // que hay que medir para saber cuántos caben por línea.
      optionsList.style.removeProperty("--horas-ancho");

      var visibles = 0;
      var minimo = 0;
      Array.prototype.forEach.call(optionsList.querySelectorAll(".hour-option"), function (chip) {
        // El filtrado por franja es `display: none`: sin caja, no cuenta.
        if (!chip.offsetWidth) return;
        visibles++;
        if (!minimo) minimo = chip.offsetWidth;
      });

      var ancho = optionsList.clientWidth;
      if (!visibles || !minimo || !ancho) return;

      var hueco = parseFloat(getComputedStyle(optionsList).columnGap) || 0;
      var porFila = Math.max(1, Math.floor((ancho + hueco) / (minimo + hueco)));
      // Filas lo más parejas posible: nueve horas con siete de tope son 5+4, no
      // 7+2.
      var columnas = Math.ceil(visibles / Math.ceil(visibles / porFila));

      optionsList.style.setProperty(
        "--horas-ancho",
        "calc((100% - " + (columnas - 1) * hueco + "px) / " + columnas + ")"
      );
    }

    // El reparto va primero: cambia el número de filas y con ello el alto, que
    // es justo lo que mira marcarScrollPropio().
    function ajustarRejilla() {
      repartirColumnas();
      marcarScrollPropio();
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

    function emitScheduleLoaded(data) {
      var event;
      try {
        event = new CustomEvent("reservation:scheduleloaded", {
          bubbles: true,
          detail: data || {}
        });
      } catch (error) {
        event = document.createEvent("CustomEvent");
        event.initCustomEvent("reservation:scheduleloaded", true, true, data || {});
      }
      root.dispatchEvent(event);
    }

    function setStatus(text, show) {
      if (!status) return;
      status.textContent = text || "";
      status.classList.toggle("show", Boolean(show && text));
    }

    // ── Franjas del día (sólo inline) ──────────────────────────
    //
    // La rejilla de la landing llega a las veinticinco horas seguidas, de las
    // 08:30 a las 21:30: una cinta de números por la que hay que buscar. Se
    // agrupan en mañana / tarde / noche y se filtran con un control segmentado,
    // que es como se piensa una reserva —"a comer" o "a cenar"— antes de pensar
    // la hora exacta.
    //
    // Sólo en inline. El desplegable del panel y el editor de "Gestionar
    // reservación" siguen con su lista corriente: allí la lista es corta y vive
    // dentro de una tarjeta, y un control de más le quitaría el sitio.
    // Los iconos son SVG en línea y de trazo (`currentColor`), no emoji ni una
    // fuente de iconos: así heredan la tinta del botón y cambian solos al
    // activarse, que es lo que un glifo de color no sabe hacer.
    //
    //   mañana → sol saliendo (semicírculo sobre el horizonte)
    //   tarde  → sol pleno
    //   noche  → luna
    var ICONOS = {
      manana: '<circle cx="12" cy="14" r="3.4"/><path d="M12 6.5V4.8M5.9 8.1 4.7 6.9M18.1 8.1l1.2-1.2M3.5 14h1.7M18.8 14h1.7M3 19h18"/>',
      tarde: '<circle cx="12" cy="12" r="4"/><path d="M12 3.4V5M12 19v1.6M4.6 4.6 5.8 5.8M18.2 18.2l1.2 1.2M3.4 12H5M19 12h1.6M4.6 19.4l1.2-1.2M18.2 5.8l1.2-1.2"/>',
      noche: '<path d="M20 14.3A8.2 8.2 0 0 1 9.7 4a8.4 8.4 0 1 0 10.3 10.3Z"/>'
    };

    var FRANJAS = [
      { id: "manana", label: "Mañana", hasta: 13 },
      { id: "tarde", label: "Tarde", hasta: 19 },
      { id: "noche", label: "Noche", hasta: 24 }
    ];
    var franjasNav = null;

    function franjaDe(hour) {
      var h = parseInt(String(hour).slice(0, 2), 10);
      for (var i = 0; i < FRANJAS.length; i++) {
        if (h < FRANJAS[i].hasta) return FRANJAS[i].id;
      }
      return FRANJAS[FRANJAS.length - 1].id;
    }

    function limpiarFranjas() {
      if (franjasNav && franjasNav.parentNode) franjasNav.parentNode.removeChild(franjasNav);
      franjasNav = null;
      optionsList.removeAttribute("data-franja");
    }

    // El filtrado va por atributo sobre la rejilla y el CSS oculta lo que no
    // toca. No se reconstruye el DOM: cada botón conserva su oyente y su estado
    // `.sel`, así que cambiar de franja no puede perder la hora ya elegida.
    function activarFranja(id) {
      if (!franjasNav) return;
      optionsList.setAttribute("data-franja", id);
      Array.prototype.forEach.call(
        franjasNav.querySelectorAll("[data-franja-id]"),
        function (boton) {
          var activa = boton.getAttribute("data-franja-id") === id;
          boton.classList.toggle("is-activa", activa);
          boton.setAttribute("aria-pressed", activa ? "true" : "false");
        }
      );
      // El filtrado oculta chips: cambian tanto el reparto de columnas como el
      // alto de la rejilla.
      ajustarRejilla();
    }

    // Se reconstruye en cada render porque las franjas con horas cambian de una
    // fecha a otra: un martes que abre a las 16:00 no tiene mañana.
    function pintarFranjas(horas, preferida) {
      if (!inline || !horas.length) return;

      var presentes = FRANJAS.filter(function (franja) {
        return horas.some(function (hora) { return franjaDe(hora) === franja.id; });
      });
      // Con una sola franja el control no informa de nada y le roba sitio a la
      // rejilla.
      if (presentes.length < 2) return;

      franjasNav = document.createElement("div");
      franjasNav.className = "hour-franjas";
      presentes.forEach(function (franja) {
        var boton = document.createElement("button");
        boton.type = "button";
        boton.className = "hour-franjas__btn";
        // El icono es decorativo: la etiqueta ya dice de qué franja se trata, y
        // repetirlo al lector de pantalla sólo añade ruido.
        boton.innerHTML =
          '<svg class="hour-franjas__icono" viewBox="0 0 24 24" fill="none" stroke="currentColor"' +
          ' stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"' +
          ' focusable="false">' + (ICONOS[franja.id] || "") + '</svg>' +
          '<span>' + franja.label + '</span>';
        boton.setAttribute("data-franja-id", franja.id);
        boton.setAttribute("aria-pressed", "false");
        boton.addEventListener("click", function (event) {
          event.stopPropagation();
          activarFranja(franja.id);
        });
        franjasNav.appendChild(boton);
      });
      dropdown.insertBefore(franjasNav, optionsList);

      // Arranca en la franja de la hora ya elegida; si no hay ninguna elegida,
      // en la primera que tenga horas.
      var inicial = preferida ? franjaDe(preferida) : "";
      var valida = inicial && presentes.some(function (f) { return f.id === inicial; });
      activarFranja(valida ? inicial : presentes[0].id);
    }

    // En modo popover el estado sin horarios se comunica por el placeholder del
    // display; inline ese display no existe, así que el texto va a la rejilla.
    //
    // Es el embudo de todos los caminos "no hay rejilla que enseñar"
    // —setUnavailable, setLoading y las salidas vacías de renderHours—, así que
    // es aquí donde se retira el control de franjas.
    function renderInlinePlaceholder(text) {
      if (!inline) return;
      limpiarFranjas();
      var vacio = document.createElement("p");
      vacio.className = "hour-tabs__empty";
      vacio.textContent = text || "Elige una fecha para ver horarios.";
      optionsList.appendChild(vacio);
      marcarScrollPropio();
    }

    function clearRequestTimeout() {
      if (requestTimeoutId === null) return;
      clearTimeout(requestTimeoutId);
      requestTimeoutId = null;
    }

    // Sin coordinador, el clic-fuera global no cierra la rejilla inline.
    var popoverCoordinator = inline ? null : (window.ReservationPopoverCoordinator || null);

    /*
     * Modo portal: el desplegable se monta en <body> con position:fixed.
     *
     * Dentro de una tarjeta del panel no le basta con el z-index — motion.js
     * marca cada .admin-card como contexto de apilamiento y el overflow la
     * recorta—, así que el panel de horas terminaba tapando el formulario
     * entero o cortado a media altura. Portado, se coloca contra el disparador y
     * puede voltearse hacia arriba cuando no cabe abajo. Opt-in: el landing
     * sigue con el desplegable anclado de siempre.
     */
    var portal = !inline && (options.portal === true || root.getAttribute("data-portal") === "1");
    var portalMontado = false;

    function posicionarPortal() {
      if (!portal) return;
      var ancla = root.getBoundingClientRect();
      var alto = dropdown.offsetHeight;
      var margen = 8;
      var espacioAbajo = window.innerHeight - ancla.bottom;

      dropdown.style.width = "";
      dropdown.style.minWidth = Math.max(ancla.width, 240) + "px";
      dropdown.style.left = Math.max(
        margen,
        Math.min(ancla.left, window.innerWidth - dropdown.offsetWidth - margen)
      ) + "px";

      // Voltea hacia arriba solo si arriba hay más sitio: en una ventana corta
      // ninguna de las dos posiciones cabe y abajo al menos no tapa el campo.
      var arriba = espacioAbajo < alto + margen && ancla.top > espacioAbajo;
      dropdown.style.top = (arriba ? Math.max(margen, ancla.top - alto - margen) : ancla.bottom + margen) + "px";
    }

    function seguirAncla() {
      if (dropdown.classList.contains("open")) posicionarPortal();
    }

    function abrirPortal() {
      if (!portal) return;
      if (!portalMontado) {
        document.body.appendChild(dropdown);
        dropdown.classList.add("is-portal");
        portalMontado = true;
      }
      posicionarPortal();
      // capture: el desplegable también debe seguir al campo cuando quien
      // desplaza es un contenedor interno, no la ventana.
      window.addEventListener("scroll", seguirAncla, true);
      window.addEventListener("resize", seguirAncla);
    }

    /*
     * Al cerrar se deshace el porte por completo: el nodo vuelve con su campo y
     * se limpian clase y coordenadas. Dejándolo en <body> con position:fixed y
     * el top/left de la última apertura, el panel quedaba flotando sobre toda la
     * pantalla —por encima del modal y del select— en cuanto se había abierto
     * una vez.
     */
    function cerrarPortal() {
      if (!portal) return;
      window.removeEventListener("scroll", seguirAncla, true);
      window.removeEventListener("resize", seguirAncla);

      if (portalMontado) {
        dropdown.classList.remove("is-portal");
        dropdown.style.top = "";
        dropdown.style.left = "";
        dropdown.style.width = "";
        dropdown.style.minWidth = "";
        root.appendChild(dropdown);
        portalMontado = false;
      }
    }

    function ocultar(restoreFocus) {
      dropdown.classList.remove("open");
      root.classList.remove("is-open");
      dropdown.setAttribute("aria-hidden", "true");
      display.setAttribute("aria-expanded", "false");
      cerrarPortal();
      if (restoreFocus) display.focus();
    }

    var popover = {
      root: root,
      // Portado, el desplegable ya no es descendiente de root: sin esto el
      // coordinador leería un clic dentro del panel como un clic fuera.
      contains: function (nodo) {
        return root.contains(nodo) || dropdown.contains(nodo);
      },
      close: function (restoreFocus) {
        ocultar(restoreFocus);
      }
    };

    function closeDropdown(restoreFocus) {
      if (inline) return;
      if (popoverCoordinator) {
        popoverCoordinator.close(popover, restoreFocus === true);
        return;
      }
      ocultar(restoreFocus === true);
    }

    function openDropdown() {
      if (inline) return;
      if (popoverCoordinator) popoverCoordinator.open(popover);
      dropdown.classList.add("open");
      root.classList.add("is-open");
      dropdown.setAttribute("aria-hidden", "false");
      display.setAttribute("aria-expanded", "true");
      abrirPortal();
    }

    function focusHour(preferSelected) {
      var option = preferSelected
        ? optionsList.querySelector(".hour-option.sel")
        : null;
      option = option || optionsList.querySelector(".hour-option");
      if (option) option.focus();
    }

    function clearValue(silent) {
      input.value = "";
      display.value = "";
      optionsList.querySelectorAll(".hour-option").forEach(function (button) {
        button.classList.remove("sel");
        button.setAttribute("aria-selected", "false");
      });
      syncValueState();
      if (!silent) emitChange();
    }

    function setUnavailable(text, keepStatus, silent) {
      enabled = false;
      availableHours = [];
      clearValue(true);
      display.placeholder = text || "Elige una hora";
      display.disabled = true;
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      optionsList.innerHTML = "";
      renderInlinePlaceholder(text || "Elige una fecha para ver horarios.");
      closeDropdown();
      if (!keepStatus) setStatus("", false);
      if (!silent) emitChange();
    }

    function setLoading(preferredHour) {
      enabled = false;
      availableHours = [];
      preferredHour = normalizeHour(preferredHour);
      if (preferredHour) {
        input.value = preferredHour;
        display.value = preferredHour;
        syncValueState();
      } else {
        clearValue(true);
      }
      display.placeholder = "Consultando horarios…";
      display.disabled = true;
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      optionsList.innerHTML = "";
      renderInlinePlaceholder("Consultando horarios…");
      closeDropdown();
      setStatus("Consultando horarios…", true);
      emitChange();
    }

    function keepCurrentAfterLookupError(hour, message, silent) {
      hour = normalizeHour(hour);
      if (!hour) {
        setUnavailable("Elige una hora", true, silent);
        setStatus(message, true);
        return;
      }

      enabled = false;
      availableHours = [];
      input.value = hour;
      display.value = hour;
      syncValueState();
      display.placeholder = "Elige una hora";
      display.disabled = true;
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      optionsList.innerHTML = "";
      renderInlinePlaceholder(message || "No pudimos consultar los horarios.");
      closeDropdown();
      setStatus(message, true);
      if (!silent) emitChange();
    }

    function buildUnavailableMessage(preferredHour) {
      var queryParams = typeof options.getQueryParams === "function"
        ? (options.getQueryParams() || {})
        : {};
      var people = parseInt(queryParams.personas, 10) || 0;
      return typeof options.unavailableMessage === "function"
        ? options.unavailableMessage(preferredHour, queryParams)
        : "La hora de las " + preferredHour + " ya no está disponible"
          + (people ? " para " + people + (people === 1 ? " persona" : " personas") : "")
          + (lastAlternatives.length
            ? ". Prueba: " + lastAlternatives.join(", ") + "."
            : ".");
    }

    function invalidatePreferredHour(preferredHour, silent, keepAlternatives) {
      if (keepAlternatives && availableHours.length) {
        enabled = true;
        clearValue(true);
        display.placeholder = "Elige otra hora";
        display.disabled = controlDisabled;
        display.setAttribute("aria-disabled", controlDisabled ? "true" : "false");
        root.classList.toggle("is-disabled", controlDisabled);
        closeDropdown(true);
      } else {
        setUnavailable("Sin horarios disponibles", true, true);
      }
      setStatus(buildUnavailableMessage(preferredHour), true);
      if (!silent) emitChange();
    }

    function selectHour(hour, silent) {
      hour = normalizeHour(hour);
      input.value = hour;
      display.value = hour;
      syncValueState();
      optionsList.querySelectorAll(".hour-option").forEach(function (button) {
        var selected = button.getAttribute("data-hour") === hour;
        button.classList.toggle("sel", selected);
        button.setAttribute("aria-selected", selected ? "true" : "false");
      });
      // Una hora preseleccionada de otra franja —al volver del OTP, o tras
      // cambiar de fecha conservando la hora— quedaría marcada dentro de un
      // grupo oculto: la rejilla no enseñaría ninguna elegida.
      if (inline && hour) {
        var franja = franjaDe(hour);
        if (optionsList.getAttribute("data-franja") !== franja) activarFranja(franja);
      }
      setStatus("", false);
      closeDropdown();
      if (!silent) emitChange();
    }

    function renderHours(hours, preferredHour, emptyMessage, silent) {
      var normalized = [];
      var seen = {};
      preferredHour = normalizeHour(preferredHour);
      optionsList.innerHTML = "";
      // El control de franjas es hermano de la rejilla, no hijo: vaciar el
      // innerHTML de ésta no se lo lleva.
      limpiarFranjas();

      (Array.isArray(hours) ? hours : []).forEach(function (hour) {
        if (hour && typeof hour === "object") {
          if (hour.disponible === false) return;
          hour = hour.hora;
        }
        hour = normalizeHour(hour);
        if (!hour || seen[hour]) return;
        seen[hour] = true;
        normalized.push(hour);
      });
      availableHours = normalized.slice();

      if (!normalized.length) {
        if (preferredHour) {
          if (invalidateUnavailable) {
            invalidatePreferredHour(preferredHour, silent, false);
          } else {
            keepCurrentAfterLookupError(preferredHour, emptyMessage || "No hay horarios disponibles para la fecha seleccionada.", silent);
          }
          return;
        }
        setUnavailable("Sin horarios disponibles", true, silent);
        setStatus(emptyMessage || "No hay horarios disponibles para la fecha seleccionada.", true);
        return;
      }

      enabled = true;
      display.disabled = controlDisabled;
      root.classList.toggle("is-disabled", controlDisabled);
      display.setAttribute("aria-disabled", controlDisabled ? "true" : "false");
      display.placeholder = "Elige una hora";
      clearValue(true);
      setStatus("", false);

      normalized.forEach(function (hour) {
        var button = document.createElement("button");
        button.type = "button";
        button.className = "hour-option";
        button.textContent = hour;
        button.setAttribute("data-hour", hour);
        button.setAttribute("data-franja", franjaDe(hour));
        button.setAttribute("role", "option");
        button.setAttribute("aria-selected", "false");
        if (inline) button.disabled = controlDisabled;
        button.addEventListener("click", function (event) {
          event.stopPropagation();
          selectHour(hour, false);
        });
        optionsList.appendChild(button);
      });

      // Después de los botones y ANTES de resolver la hora preferida: si esa
      // hora cae en otra franja, selectHour() la trae a la vista.
      pintarFranjas(normalized, preferredHour);
      // pintarFranjas() puede no llegar a activar ninguna franja —con una sola
      // no se pinta el control—, así que el ajuste va también aquí.
      ajustarRejilla();

      if (preferredHour && seen[preferredHour]) {
        selectHour(preferredHour, true);
      } else if (preferredHour) {
        if (invalidateUnavailable) {
          invalidatePreferredHour(preferredHour, silent, true);
        } else {
          input.value = preferredHour;
          display.value = preferredHour;
          syncValueState();
          setStatus("El horario actual ya no está disponible; elige otro solo si deseas cambiarlo.", true);
          if (!silent) emitChange();
        }
      } else if (!silent) {
        emitChange();
      }
    }

    function loadForDate(fecha, preferredHour) {
      requestId++;
      var currentRequest = requestId;
      preferredHour = normalizeHour(preferredHour);

      if (abortController) {
        abortController.abort();
        abortController = null;
      }
      clearRequestTimeout();

      if (!fecha) {
        setUnavailable("Selecciona fecha y comensales");
        return Promise.resolve([]);
      }
      if (!endpoint) {
        keepCurrentAfterLookupError(preferredHour, "No fue posible consultar los horarios.");
        return Promise.resolve([]);
      }

      setLoading(preferredHour);
      abortController = typeof AbortController !== "undefined" ? new AbortController() : null;
      var timedOut = false;
      var timeoutNotified = false;

      var params = new URLSearchParams({ fecha: fecha });
      if (typeof options.getQueryParams === "function") {
        var extras = options.getQueryParams() || {};
        Object.keys(extras).forEach(function (key) {
          if (extras[key] !== undefined && extras[key] !== null && extras[key] !== "") {
            params.set(key, extras[key]);
          }
        });
      }

      requestTimeoutId = setTimeout(function () {
        if (currentRequest !== requestId) return;
        timedOut = true;
        requestTimeoutId = null;
        requestId++;
        if (abortController) {
          abortController.abort();
          abortController = null;
        }
        timeoutNotified = true;
        keepCurrentAfterLookupError(
          preferredHour,
          "No fue posible consultar los horarios. Intenta nuevamente."
        );
        emitScheduleLoaded({
          ok: false,
          mensaje: "No fue posible consultar los horarios. Intenta nuevamente."
        });
      }, requestTimeoutMs);

      return fetch(endpoint + "?" + params.toString(), {
        headers: { "Accept": "application/json" },
        credentials: "same-origin",
        signal: abortController ? abortController.signal : undefined
      })
        .then(function (response) {
          return response.text().then(function (raw) {
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
        .then(function (data) {
          if (currentRequest === requestId) clearRequestTimeout();
          if (currentRequest !== requestId) return [];
          if (data && data.fecha !== undefined && String(data.fecha) !== String(fecha)) {
            keepCurrentAfterLookupError(
              preferredHour,
              "La respuesta no corresponde a la fecha seleccionada."
            );
            emitScheduleLoaded({
              ok: false,
              codigo: "FECHA_RESPUESTA_MISMATCH",
              fecha: data.fecha,
              requested_fecha: fecha,
              mensaje: "La respuesta no corresponde a la fecha seleccionada."
            });
            return [];
          }
          emitScheduleLoaded(data);
          lastAlternatives = Array.isArray(data.alternativas)
            ? data.alternativas.map(normalizeHour).filter(Boolean).slice(0, 5)
            : [];

          if (!data.ok) {
            var errorMessage = data.mensaje || "No fue posible consultar los horarios.";
            if (preferredHour) {
              keepCurrentAfterLookupError(preferredHour, errorMessage);
            } else {
              setUnavailable("Elige una hora", true);
              setStatus(errorMessage, true);
            }
            return [];
          }

          if (data.abierto === false) {
            var closedMessage = data.mensaje || "El restaurante no recibe reservaciones en esta fecha.";
            if (preferredHour) {
              invalidatePreferredHour(preferredHour, false, false);
              setStatus(closedMessage, true);
            } else {
              setUnavailable("Restaurante cerrado", true);
              setStatus(closedMessage, true);
            }
            return [];
          }

          renderHours(
            Array.isArray(data.horarios) ? data.horarios : [],
            preferredHour,
            data.mensaje || "No hay horarios disponibles para la fecha seleccionada.",
            false
          );
          return data.horarios || [];
        })
        .catch(function (error) {
          if (currentRequest === requestId) clearRequestTimeout();
          if (error && error.name === "AbortError" && !timedOut) return [];
          if (currentRequest !== requestId) return [];
          if (!timeoutNotified) {
            keepCurrentAfterLookupError(
              preferredHour,
              "No fue posible consultar los horarios. Intenta nuevamente."
            );
            emitScheduleLoaded({
              ok: false,
              mensaje: "No fue posible consultar los horarios. Intenta nuevamente."
            });
          }
          return [];
        });
    }

    function setControlDisabled(disabled) {
      disabled = Boolean(disabled);
      controlDisabled = disabled;
      display.disabled = disabled || !enabled;
      input.disabled = disabled;
      display.setAttribute("aria-disabled", disabled || !enabled ? "true" : "false");
      root.classList.toggle("is-disabled", disabled || !enabled);
      // Inline los chips están siempre a la vista: sin esto seguirían clicables
      // en el modo de más de 12 personas, donde el flujo en línea no aplica.
      if (inline) {
        optionsList.querySelectorAll(".hour-option").forEach(function (button) {
          button.disabled = disabled;
        });
      }
      if (disabled) closeDropdown();
    }

    display.addEventListener("click", function (event) {
      event.stopPropagation();
      if (!enabled || display.disabled) return;
      if (dropdown.classList.contains("open")) {
        closeDropdown();
      } else {
        openDropdown();
        focusHour(true);
      }
    });

    display.addEventListener("keydown", function (event) {
      if ((event.key === "Enter" || event.key === " ") && enabled && !display.disabled) {
        event.preventDefault();
        openDropdown();
        focusHour(true);
      }
      if (event.key === "Escape") {
        closeDropdown(true);
      }
    });

    optionsList.addEventListener("keydown", function (event) {
      if (["ArrowDown", "ArrowUp", "ArrowLeft", "ArrowRight", "Home", "End", "Escape"].indexOf(event.key) === -1) return;
      var buttons = Array.from(optionsList.querySelectorAll(".hour-option"));
      var index = buttons.indexOf(document.activeElement);
      if (event.key === "Escape") {
        // Inline no hay desplegable que cerrar: dejar pasar la tecla.
        if (inline) return;
        event.preventDefault();
        closeDropdown(true);
        return;
      }
      if (index === -1 || !buttons.length) return;
      event.preventDefault();
      // Inline los horarios son una rejilla, no una columna: las flechas
      // horizontales recorren la misma lista.
      if (event.key === "ArrowDown" || event.key === "ArrowRight") index = Math.min(buttons.length - 1, index + 1);
      if (event.key === "ArrowUp" || event.key === "ArrowLeft") index = Math.max(0, index - 1);
      if (event.key === "Home") index = 0;
      if (event.key === "End") index = buttons.length - 1;
      buttons[index].focus();
    });

    if (popoverCoordinator) popoverCoordinator.register(popover);

    var initialTime = normalizeHour(options.initialTime || input.value);
    var suppliedHours = Array.isArray(options.hours) ? options.hours.slice() : buildStaticHours(staticStep);

    if (initialTime && suppliedHours.length && suppliedHours.indexOf(initialTime) === -1) {
      suppliedHours.push(initialTime);
      suppliedHours.sort();
    }

    if (suppliedHours.length) {
      renderHours(suppliedHours, initialTime, "No hay horarios disponibles.", true);
    } else if (options.autoLoad === false) {
      enabled = false;
      input.value = initialTime;
      display.value = initialTime;
      display.placeholder = initialTime ? "Elige una hora" : (display.placeholder || "Elige una fecha primero");
      display.setAttribute("aria-disabled", "true");
      root.classList.add("is-disabled");
      optionsList.innerHTML = "";
      renderInlinePlaceholder("Elige una fecha para ver horarios.");
      closeDropdown();
    } else {
      setUnavailable("Selecciona fecha y comensales", false, true);
    }

    if (options.autoLoad !== false && options.initialDate && endpoint) {
      loadForDate(options.initialDate, options.initialTime || input.value);
    } else if (initialTime) {
      input.value = initialTime;
      display.value = initialTime;
      syncValueState();
    }

    setControlDisabled(initiallyDisabled);
    syncValueState();

    // Al cambiar el ancho cambian las dos cosas: cuántos chips caben por línea y
    // si la rejilla desborda. El umbral de 640px, donde vuelve el max-height,
    // cruza en los dos sentidos.
    window.addEventListener("resize", ajustarRejilla);

    return {
      loadForDate: loadForDate,
      setOptions: function (hours, preferredHour, emptyMessage, silent) {
        renderHours(hours, preferredHour, emptyMessage, Boolean(silent));
        setControlDisabled(false);
      },
      setValue: function (hour, silent) {
        hour = normalizeHour(hour);
        if (!hour) {
          clearValue(Boolean(silent));
          return;
        }
        if (availableHours.indexOf(hour) === -1) {
          var nextHours = availableHours.concat([hour]).sort();
          renderHours(nextHours, hour, "", true);
          if (!silent) emitChange();
          return;
        }
        selectHour(hour, Boolean(silent));
      },
      setDisabled: setControlDisabled,
      clear: function (silent) {
        clearValue(Boolean(silent));
      },
      getValue: function () {
        return input.value;
      }
    };
  };
})();
