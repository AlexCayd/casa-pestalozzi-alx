"use strict";

const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const state = require("../../src/js/components/reservation-form-state.js");

let checks = 0;
function check(name, callback) {
  callback();
  checks += 1;
  process.stdout.write("✓ " + name + "\n");
}

check("selector presenta correo", function () {
  assert.deepEqual(state.contactPresentation("email"), {
    type: "email",
    autocomplete: "email",
    inputmode: "email",
    placeholder: "cliente@ejemplo.com",
    label: "Correo electrónico",
    help: "Escribe un correo electrónico válido."
  });
});

check("selector presenta teléfono", function () {
  const phone = state.contactPresentation("telefono");
  assert.equal(phone.type, "tel");
  assert.equal(phone.inputmode, "tel");
  assert.match(phone.help, /lada/i);
});

check("incremento posterior a 12 activa Más de 12 sin producir 13", function () {
  assert.deepEqual(
    state.guestTransition({ guests: 12, largeParty: false }, "increment", null, 12),
    { guests: 12, largeParty: true }
  );
});

check("Más de 12 no sigue incrementando", function () {
  assert.deepEqual(
    state.guestTransition({ guests: 12, largeParty: true }, "increment", null, 12),
    { guests: 12, largeParty: true }
  );
});

check("reducir desde Más de 12 restaura exactamente 12", function () {
  assert.deepEqual(
    state.guestTransition({ guests: 12, largeParty: true }, "decrement", null, 12),
    { guests: 12, largeParty: false }
  );
});

check("estado de disponibilidad confirma hora seleccionada", function () {
  assert.deepEqual(
    state.availabilityState({
      ok: true,
      abierto: true,
      horarios: [{ hora: "18:00:00", disponible: true }]
    }, "18:00", false),
    {
      status: "ready",
      slots: ["18:00"],
      selectedAvailable: true,
      message: "Disponibilidad confirmada."
    }
  );
});

check("capacidad insuficiente no se representa como ausencia de horarios", function () {
  const result = state.availabilityState({
    ok: true,
    abierto: true,
    horarios: [{ hora: "18:00", disponible: false }],
    mensaje: "No hay capacidad suficiente para 10 personas en esta fecha."
  }, "18:00", false);
  assert.equal(result.status, "unavailable");
  assert.match(result.message, /capacidad suficiente/i);
});

check("envío se bloquea sin fecha", function () {
  assert.equal(state.canSubmitVisit({
    date: "",
    time: "18:00",
    guests: 4,
    maxGuests: 12,
    availabilityStatus: "ready",
    availableSlots: ["18:00"]
  }), false);
});

check("envío se bloquea sin hora disponible", function () {
  assert.equal(state.canSubmitVisit({
    date: "2026-12-04",
    time: "18:30",
    guests: 4,
    maxGuests: 12,
    availabilityStatus: "ready",
    availableSlots: ["18:00"]
  }), false);
});

check("envío se bloquea en Más de 12", function () {
  assert.equal(state.canSubmitVisit({
    date: "2026-12-04",
    time: "18:00",
    guests: 12,
    maxGuests: 12,
    largeParty: true,
    availabilityStatus: "ready",
    availableSlots: ["18:00"]
  }), false);
});

check("flujo normal permite enviar fecha y hora disponibles", function () {
  assert.equal(state.canSubmitVisit({
    date: "2026-12-04",
    time: "18:00",
    guests: 12,
    maxGuests: 12,
    largeParty: false,
    availabilityStatus: "ready",
    availabilityPending: false,
    availableSlots: ["18:00"]
  }), true);
});

check("formulario administrativo consume el detalle de capacidad por horario", function () {
  const source = fs.readFileSync(
    path.join(__dirname, "../../src/js/admin/reservations/form.js"),
    "utf8"
  );
  assert.match(source, /reservation:scheduleloaded/);
  assert.match(source, /capacidad_realmente_libre/);
  assert.match(source, /depende_liberacion_proyectada/);
});

check("mapa conserva estados visuales de proyección y conflicto", function () {
  const source = fs.readFileSync(
    path.join(__dirname, "../../src/js/admin/reservations/operation.js"),
    "utf8"
  );
  assert.match(source, /capacidadHorario/);
  assert.match(source, /conflicto_proximo/);
  assert.match(source, /Asignación con liberación proyectada/);
});

process.stdout.write("OK: " + checks + " comprobaciones JavaScript de reservaciones.\n");
