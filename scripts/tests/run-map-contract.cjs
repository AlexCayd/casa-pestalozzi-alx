const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const context = { window: {} };
const source = fs.readFileSync(path.join(root, 'src/js/operation/map-contract.js'), 'utf8');
vm.runInNewContext(source, context, { filename: 'map-contract.js' });

const contract = context.window.MapaContrato;
const validStates = ['libre', 'ocupada', 'reservacion-proxima', 'no-utilizable'];

for (const state of validStates) {
  assert.equal(contract.validarEstado(state).valido, true, `${state} es un estado permitido`);
  assert.equal(contract.normalizarEstado(state), state, `${state} se normaliza sin cambios`);
}

assert.equal(contract.validarEstado('').valido, false, 'el estado ausente es inválido');
assert.equal(contract.normalizarEstado('desconocido'), 'no-utilizable', 'un estado desconocido cae al estado seguro');
assert.equal(contract.validarEstado('seleccionada').valido, false, 'seleccionada no es un estado base');
assert.equal(contract.validarEstado('seleccionada', 'ocupada').valido, true, 'seleccionada heredada exige un estado previo válido');
assert.equal(contract.identificarSeleccionHeredada('seleccionada', 'libre'), true, 'reconoce la selección heredada válida');
assert.equal(contract.identificarSeleccionHeredada('seleccionada', 'estado-ajeno'), false, 'rechaza la selección heredada sin base válida');
assert.equal(contract.validarModificadores(['ticket_abierto', 'ausencia_pendiente']), true, 'acepta modificadores con formato válido');
assert.equal(contract.validarModificadores(['modificador inválido']), false, 'rechaza modificadores con caracteres inválidos');
assert.equal(contract.validarModificadores(null), false, 'rechaza modificadores ausentes');
assert.deepEqual(
  Object.keys(contract.fallbackSeguro()).sort(),
  ['estadoNoVerificado', 'estadoVisual', 'interactivo', 'modificadores', 'seleccionValida', 'seleccionada'].sort(),
  'el fallback bloquea selección e interacción sin introducir un estado base nuevo'
);

console.log('Mapa: contrato visual puro OK');
