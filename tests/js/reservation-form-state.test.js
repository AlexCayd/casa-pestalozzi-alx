const assert = require("assert");
const state = require("../../src/js/components/reservation-form-state.js");

function transition(current, action) {
  return state.availabilityTransition(current, action);
}

const initial = {
  status: "idle",
  pending: false,
  requestId: 0,
  slots: [],
  date: "2026-11-02",
  guests: 2,
  name: "Ana Pérez",
  contact: "ana@example.com"
};

const loading = transition(initial, {
  type: "start",
  requestId: 1,
  date: "2026-11-02",
  guests: 2,
  hour: ""
});
assert.strictEqual(loading.status, "loading");
assert.strictEqual(loading.pending, true);
assert.deepStrictEqual(loading.slots, []);
assert.strictEqual(loading.date, "2026-11-02");
assert.strictEqual(loading.time, "");
assert.strictEqual(loading.name, initial.name);
assert.strictEqual(loading.contact, initial.contact);

const success = transition(loading, {
  type: "response",
  requestId: 1,
  selectedTime: "14:00",
  payload: {
    ok: true,
    fecha: "2026-11-02",
    personas: 2,
    abierto: true,
    horarios: [
      { hora: "14:00", disponible: true },
      { hora: "15:00", disponible: false },
      { hora: "16:00", disponible: true }
    ]
  }
});
assert.strictEqual(success.status, "ready");
assert.strictEqual(success.pending, false);
assert.deepStrictEqual(success.slots, ["14:00", "16:00"]);
assert.strictEqual(success.time, "14:00");

const changedDate = transition(success, {
  type: "start",
  requestId: 2,
  date: "2026-11-03",
  guests: 2,
  hour: ""
});
assert.strictEqual(changedDate.date, "2026-11-03");
assert.strictEqual(changedDate.time, "");
assert.deepStrictEqual(changedDate.slots, []);

const mismatchedResponse = transition(changedDate, {
  type: "response",
  requestId: 2,
  payload: {
    ok: true,
    fecha: "2026-11-02",
    personas: 2,
    abierto: true,
    horarios: [{ hora: "14:00", disponible: true }]
  }
});
assert.strictEqual(mismatchedResponse.status, "error");
assert.strictEqual(mismatchedResponse.pending, false);
assert.deepStrictEqual(mismatchedResponse.slots, []);
assert.strictEqual(mismatchedResponse.name, success.name);
assert.strictEqual(mismatchedResponse.contact, success.contact);

assert.notStrictEqual(
  state.availabilityCacheKey("2026-11-02", 2, "14:00"),
  state.availabilityCacheKey("2026-11-03", 2, "14:00")
);
assert.notStrictEqual(
  state.availabilityCacheKey("2026-11-02", 2, "14:00"),
  state.availabilityCacheKey("2026-11-02", 4, "14:00")
);
assert.strictEqual(
  state.availabilityResponseMatches(
    { date: "2026-11-03", guests: 2 },
    { fecha: "2026-11-03", personas: 2 }
  ),
  true
);
assert.strictEqual(
  state.availabilityResponseMatches(
    { date: "2026-11-03", guests: 2 },
    { fecha: "2026-11-02", personas: 2 }
  ),
  false
);
assert.strictEqual(
  state.availabilityState({
    ok: true,
    fecha: "2026-11-04",
    horarios: [{ hora: "09:00", disponible: true }]
  }, "", false).status,
  "ready"
);

const empty = transition(loading, {
  type: "response",
  requestId: 1,
  payload: {
    ok: true,
    fecha: "2026-11-02",
    personas: 2,
    abierto: true,
    horarios: []
  }
});
assert.strictEqual(empty.status, "unavailable");
assert.strictEqual(empty.pending, false);
assert.deepStrictEqual(empty.slots, []);

const httpError = transition(loading, {
  type: "error",
  requestId: 1,
  message: "HTTP 500"
});
assert.strictEqual(httpError.status, "error");
assert.strictEqual(httpError.pending, false);
assert.strictEqual(httpError.message, "HTTP 500");

const jsonError = transition(loading, {
  type: "error",
  requestId: 1,
  message: "La respuesta del servidor no es válida."
});
assert.strictEqual(jsonError.status, "error");
assert.strictEqual(jsonError.pending, false);

const aborted = transition(loading, { type: "abort", requestId: 1 });
assert.strictEqual(aborted.status, "idle");
assert.strictEqual(aborted.pending, false);
assert.strictEqual(aborted.message, "");

const staleResponse = transition(loading, {
  type: "response",
  requestId: 0,
  payload: { ok: true, abierto: true, horarios: [{ hora: "18:00" }] }
});
assert.deepStrictEqual(staleResponse, loading);

const staleAbort = transition(loading, {
  type: "abort",
  requestId: 1,
  stale: true
});
assert.deepStrictEqual(staleAbort, loading);

assert.deepStrictEqual(
  state.guestTransition({ guests: 6, largeParty: false }, "increment", null, 12),
  { guests: 7, largeParty: false }
);
assert.deepStrictEqual(
  state.guestTransition({ guests: 12, largeParty: false }, "increment", null, 12),
  { guests: 12, largeParty: true }
);
assert.deepStrictEqual(
  state.guestTransition({ guests: 12, largeParty: true }, "decrement", null, 12),
  { guests: 12, largeParty: false }
);
assert.deepStrictEqual(
  state.guestTransition({ guests: 2, largeParty: false }, "select", 5, 12),
  { guests: 5, largeParty: false }
);

const cleared = transition(success, { type: "clear", requestId: 1 });
assert.strictEqual(cleared.status, "idle");
assert.strictEqual(cleared.pending, false);
assert.deepStrictEqual(cleared.slots, []);
assert.strictEqual(cleared.time, "");
assert.strictEqual(cleared.name, initial.name);
assert.strictEqual(cleared.contact, initial.contact);

console.log("reservation-form-state: PASS (fecha/cambio/limpieza/query/mismatch/cache/stale/capacidad/guests/cleanup)");
