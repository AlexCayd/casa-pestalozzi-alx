const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const context = { window: {} };

for (const file of [
  'src/js/operation/map-visual.js',
  'src/js/operation/table-state-adapter.js'
]) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  vm.runInNewContext(source, context, { filename: file });
}

const normalize = context.window.MapaVisual.normalizarMesa;
const adapt = context.window.MesaEstadoAdapter.paraMapaVisual;
const mapStyles = fs.readFileSync(path.join(root, 'src/scss/operation/_map-shell.scss'), 'utf8');

for (const raw of [
  { id: 1, reservable: true },
  { id: 2, reservable: true, estadoVisual: 'estado-desconocido' }
]) {
  const mesa = normalize(raw);
  assert.equal(mesa.estadoVisual, 'no-utilizable', 'un contrato ausente o desconocido usa el estado neutral');
  assert.equal(mesa.interactivo, false, 'un estado no validado no permite interacción visual');
  assert.equal(mesa.seleccionada, false, 'un estado no validado no permite selección visual');
}

const occupiedSelection = adapt({
  id: 3,
  reservable: true,
  estado_visual_pos: 'ocupada',
  seleccion_actual: true,
  disponible_para_asignacion: false
});
assert.equal(occupiedSelection.estadoVisual, 'ocupada', 'la selección conserva el hecho base ocupado');
assert.equal(occupiedSelection.seleccionada, true, 'la selección se representa como capa secundaria');

const unusableSelection = adapt({
  id: 4,
  reservable: false,
  estado_base: 'no_reservable',
  estado_visual_pos: 'no-utilizable',
  seleccion_actual: true
});
assert.equal(unusableSelection.estadoVisual, 'no-utilizable', 'no utilizable conserva la precedencia');
assert.equal(unusableSelection.seleccionada, false, 'no se selecciona una mesa no utilizable');

const ticketWarningSelection = adapt({
  id: 6,
  reservable: true,
  estado_visual_pos: 'ocupada',
  seleccion_actual: true,
  modificadores: ['ticket_abierto', 'reservacion_advertencia']
});
assert.equal(ticketWarningSelection.estadoVisual, 'ocupada', 'ticket y advertencia conservan ocupación');
assert.equal(ticketWarningSelection.seleccionada, true, 'ticket puede conservar selección como anillo secundario');
assert.ok(ticketWarningSelection.modificadores.includes('reservacion_advertencia'));

const upcomingSelection = adapt({ id: 7, reservable: true, estado_visual_pos: 'reservacion-proxima', seleccion_actual: true });
assert.equal(upcomingSelection.estadoVisual, 'reservacion-proxima');
assert.equal(upcomingSelection.seleccionada, true, 'seleccionar una mesa próxima no la vuelve disponible');

const warningSelection = adapt({
  id: 8,
  reservable: true,
  estado_visual_pos: 'libre',
  seleccion_actual: true,
  modificadores: ['reservacion_advertencia']
});
assert.equal(warningSelection.estadoVisual, 'libre');
assert.equal(warningSelection.seleccionada, true);
const warningSelectionRule = mapStyles.match(/\.mesas-map \.mesa-pin--libre\.mesa-pin--seleccionada\.mesa-pin--mod-reservacion_advertencia\s*\{([^}]+)\}/);
assert.ok(warningSelectionRule, 'la selección con advertencia tiene una regla de composición explícita');
assert.match(warningSelectionRule[1], /border-color: var\(--map-reservation-warning-border\)/);
assert.match(warningSelectionRule[1], /border-style: dashed/);
assert.match(warningSelectionRule[1], /background: var\(--map-table-available-bg\)/);
assert.match(warningSelectionRule[1], /0 0 0 3px[\s\S]*var\(--map-table-selected-bg\)/);

const absenceSelection = adapt({
  id: 9,
  reservable: true,
  estado_visual_pos: 'reservacion-proxima',
  seleccion_actual: true,
  modificadores: ['ausencia_pendiente', 'accion_pendiente']
});
assert.equal(absenceSelection.estadoVisual, 'reservacion-proxima');
assert.equal(absenceSelection.seleccionada, true, 'selección conserva la señal de ausencia pendiente');
assert.ok(absenceSelection.modificadores.includes('ausencia_pendiente'));

const assignmentConflict = adapt({
  id: 10,
  reservable: true,
  estado_visual_pos: 'ocupada',
  disponible_para_asignacion: false,
  modificadores: ['asignada_actualmente', 'restriccion_intervalo']
});
assert.equal(assignmentConflict.estadoVisual, 'ocupada', 'la asignación actual no oculta un conflicto');
assert.ok(assignmentConflict.modificadores.includes('asignada_actualmente'));

const multipleSelection = [
  adapt({ id: 11, reservable: true, estado_visual_pos: 'libre', seleccion_actual: true }),
  adapt({ id: 12, reservable: true, estado_visual_pos: 'ocupada', seleccion_actual: true })
];
assert.deepEqual(multipleSelection.map((mesa) => mesa.id), [11, 12]);
assert.deepEqual(multipleSelection.map((mesa) => mesa.seleccionada), [true, true]);

const invalidLegacySelection = normalize({
  id: 5,
  reservable: true,
  estadoVisual: 'seleccionada',
  estadoVisualAnterior: 'no-utilizable'
});
assert.equal(invalidLegacySelection.estadoVisual, 'no-utilizable');
assert.equal(invalidLegacySelection.seleccionada, false, 'el estado heredado no supera la precedencia no utilizable');

console.log('Mapa: contrato visual seguro y selección superpuesta OK');
