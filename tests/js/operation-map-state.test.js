const assert = require("assert");
const fs = require("fs");
const vm = require("vm");

const source = fs.readFileSync(
  require.resolve("../../src/js/operation/table-state-adapter.js"),
  "utf8"
);
const context = { window: {} };
vm.runInNewContext(source, context, { filename: "table-state-adapter.js" });

const adapter = context.window.MesaEstadoAdapter;
assert.deepStrictEqual(Array.from(adapter.precedenciaVisual), [
  "no-utilizable",
  "ocupada",
  "seleccionada",
  "reservacion-proxima",
  "libre"
]);

function normalize(raw, options) {
  return adapter.paraMapaVisual(Object.assign({
    id: 1,
    nombre: "Mesa 1",
    reservable: true,
    activo: true,
    estado_base: "disponible",
    modificadores: []
  }, raw || {}), options || {});
}

function classNames(table) {
  return new Set([
    "mesa-pin--" + table.estadoVisual,
    ...table.clasesEstado,
    ...table.modificadores.map((modifier) => "mesa-pin--mod-" + modifier)
  ]);
}

let table = normalize();
assert.strictEqual(table.estadoVisual, "libre");
assert.ok(classNames(table).has("mesa-pin--libre"));

table = normalize({
  reservacion_proxima: { id: 10 },
  modificadores: ["reservacion_proxima", "reservacion_advertencia"]
});
assert.strictEqual(table.estadoVisual, "reservacion-proxima");
assert.ok(classNames(table).has("mesa-pin--mod-reservacion_advertencia"));

table = normalize({
  reservacion_proxima: { id: 11 },
  accion_pendiente: "REGISTRAR_AUSENCIA",
  modificadores: ["reservacion_proxima", "reservacion_vencida", "accion_pendiente"]
});
assert.strictEqual(table.estadoVisual, "libre");
assert.ok(classNames(table).has("mesa-pin--libre"));
assert.ok(classNames(table).has("mesa-pin--mod-accion_pendiente"));

table = normalize({
  reservacion_proxima: { id: 11 },
  accion_pendiente: "REGISTRAR_AUSENCIA",
  modificadores: ["reservacion_proxima", "accion_pendiente"]
}, { seleccionActual: true });
assert.strictEqual(table.estadoVisual, "seleccionada");
assert.ok(classNames(table).has("mesa-pin--mod-accion_pendiente"));

table = normalize({
  ticket_abierto: { id: 20 },
  modificadores: ["ticket_abierto"]
});
assert.strictEqual(table.estadoVisual, "ocupada");
assert.ok(classNames(table).has("mesa-pin--mod-ticket_abierto"));

table = normalize({
  ticket_abierto: { id: 20 },
  reservacion_proxima: { id: 10 },
  modificadores: ["ticket_abierto", "reservacion_proxima", "reservacion_advertencia"]
}, { seleccionActual: true });
assert.strictEqual(table.estadoVisual, "ocupada");
assert.strictEqual(table.seleccionada, true);
assert.ok(classNames(table).has("mesa-pin--ocupada"));
assert.ok(classNames(table).has("mesa-pin--mod-ticket_abierto"));
assert.ok(classNames(table).has("mesa-pin--mod-reservacion_advertencia"));
assert.ok(classNames(table).has("mesa-pin--mod-seleccion_actual"));

table = normalize({}, { seleccionActual: true });
assert.strictEqual(table.estadoVisual, "seleccionada");
assert.ok(classNames(table).has("mesa-pin--seleccionada"));

table = normalize({ reservable: false }, { seleccionActual: true });
assert.strictEqual(table.estadoVisual, "no-utilizable");
assert.strictEqual(table.seleccionada, false);

table = normalize({ activo: false, reservable: true }, { seleccionActual: true });
assert.strictEqual(table.estadoVisual, "no-utilizable");

console.log("operation-map-state: PASS (precedencia/rojo-azul/seleccion/no-reservable/clases)");
