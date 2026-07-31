/**
 * Reglas de estado puras compartidas por los formularios de reservaciones.
 * El módulo funciona en navegador y en Node para pruebas sin DOM.
 */
(function (root, factory) {
  var api = factory();
  if (typeof module === "object" && module.exports) {
    module.exports = api;
  }
  if (root) {
    root.ReservationFormState = api;
  }
})(typeof window !== "undefined" ? window : globalThis, function () {
  function normalizeHour(value) {
    var match = String(value || "").match(/^([01]\d|2[0-3]):([0-5]\d)/);
    return match ? match[1] + ":" + match[2] : "";
  }

  function normalizeSlots(hours) {
    return (Array.isArray(hours) ? hours : []).reduce(function (slots, item) {
      if (item && typeof item === "object") {
        if (item.disponible === false) return slots;
        item = item.hora;
      }
      var hour = normalizeHour(item);
      if (hour && slots.indexOf(hour) === -1) slots.push(hour);
      return slots;
    }, []);
  }

  function contactPresentation(type) {
    var phone = String(type || "") === "telefono";
    return {
      type: phone ? "tel" : "email",
      autocomplete: phone ? "tel" : "email",
      inputmode: phone ? "tel" : "email",
      placeholder: phone ? "+52 55 1234 5678" : "cliente@ejemplo.com",
      label: phone ? "Teléfono" : "Correo electrónico",
      help: phone
        ? "Incluye lada y diez dígitos; el sistema normalizará el prefijo de México."
        : "Escribe un correo electrónico válido."
    };
  }

  function guestTransition(state, action, value, maximum) {
    maximum = Math.max(1, parseInt(maximum, 10) || 12);
    state = state || {};
    var guests = Math.max(1, Math.min(maximum, parseInt(state.guests, 10) || 1));
    var largeParty = state.largeParty === true;

    if (action === "increment") {
      if (largeParty) return { guests: maximum, largeParty: true };
      if (guests >= maximum) return { guests: maximum, largeParty: true };
      return { guests: guests + 1, largeParty: false };
    }
    if (action === "decrement") {
      if (largeParty) return { guests: maximum, largeParty: false };
      return { guests: Math.max(1, guests - 1), largeParty: false };
    }
    if (action === "select") {
      return {
        guests: Math.max(1, Math.min(maximum, parseInt(value, 10) || 1)),
        largeParty: false
      };
    }

    return { guests: guests, largeParty: largeParty };
  }

  function availabilityState(payload, selectedTime, pending) {
    var slots = normalizeSlots(payload && payload.horarios);
    var selected = normalizeHour(selectedTime);
    if (pending) {
      return {
        status: "loading",
        slots: [],
        selectedAvailable: false,
        message: "Consultando disponibilidad…"
      };
    }
    if (!payload || payload.ok !== true) {
      return {
        status: "error",
        slots: [],
        selectedAvailable: false,
        message: payload && payload.mensaje
          ? String(payload.mensaje)
          : "No fue posible consultar la disponibilidad."
      };
    }
    if (payload.abierto === false) {
      return {
        status: "closed",
        slots: [],
        selectedAvailable: false,
        message: payload.mensaje || "El restaurante no recibe reservaciones en esta fecha."
      };
    }
    if (!slots.length) {
      return {
        status: "unavailable",
        slots: [],
        selectedAvailable: false,
        message: payload.mensaje || "No hay capacidad suficiente para esta selección."
      };
    }
    return {
      status: "ready",
      slots: slots,
      selectedAvailable: Boolean(selected && slots.indexOf(selected) !== -1),
      message: selected && slots.indexOf(selected) !== -1
        ? "Disponibilidad confirmada."
        : "Elige un horario disponible."
    };
  }

  function canSubmitVisit(state) {
    state = state || {};
    var slots = Array.isArray(state.availableSlots) ? state.availableSlots : [];
    var time = normalizeHour(state.time);
    return Boolean(
      state.largeParty !== true
      && /^\d{4}-\d{2}-\d{2}$/.test(String(state.date || ""))
      && time
      && parseInt(state.guests, 10) >= 1
      && parseInt(state.guests, 10) <= (parseInt(state.maxGuests, 10) || 12)
      && state.availabilityStatus === "ready"
      && state.availabilityPending !== true
      && slots.indexOf(time) !== -1
    );
  }

  return {
    normalizeHour: normalizeHour,
    normalizeSlots: normalizeSlots,
    contactPresentation: contactPresentation,
    guestTransition: guestTransition,
    availabilityState: availabilityState,
    canSubmitVisit: canSubmitVisit
  };
});
