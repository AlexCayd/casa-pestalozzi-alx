const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const operationSource = fs.readFileSync(path.join(root, 'src/js/admin/reservations/operation.js'), 'utf8');

function extractFunction(source, startMarker, endMarker, file) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `${file}: localiza ${startMarker}`);
  return vm.runInNewContext('(' + source.slice(start, end).trim() + ')', {}, { filename: file });
}

const validarOperacion = extractFunction(
  operationSource,
  'function validarRespuestaMapaOperacion(payload, fecha, hora)',
  '\n    function initReservationOperation',
  'operation.js'
);

const operationSnapshot = {
  ok: true,
  fecha: '2026-09-29',
  hora: '13:30:00',
  mesas: [{ id: 1 }],
  mesas_estado: [{ id: 1, estado_visual_mapa: 'visual-desconocido' }],
  reservaciones: [],
  horarios_mapa: ['13:30'],
  ocupacion_por_reservacion: {},
  capacidad_horario: {}
};
assert.equal(validarOperacion(operationSnapshot, '2026-09-29', '13:30').valida, true, 'acepta el snapshot de Reservaciones estructuralmente válido');
assert.equal(validarOperacion(Object.assign({}, operationSnapshot, { ocupacion_por_reservacion: [] }), '2026-09-29', '13:30').valida, true, 'acepta el mapa asociativo PHP vacío serializado como arreglo');
assert.equal(validarOperacion(operationSnapshot, '2026-09-30', '13:30').motivo, 'contexto', 'descarta una fecha stale de Reservaciones');
assert.equal(validarOperacion(operationSnapshot, '2026-09-29', '14:00').motivo, 'contexto', 'descarta una hora stale de Reservaciones');
assert.equal(validarOperacion(Object.assign({}, operationSnapshot, { hora: '25:90' }), '2026-09-29', '').motivo, 'contexto', 'rechaza una hora con formato inválido');
assert.equal(validarOperacion(Object.assign({}, operationSnapshot, { hora: '', hora_sugerida: '', horarios_mapa: [] }), '2026-09-29', '').valida, true, 'permite el snapshot sin horario configurado');
assert.equal(validarOperacion(Object.assign({}, operationSnapshot, { hora: '', hora_sugerida: '' }), '2026-09-29', '13:30').motivo, 'contexto', 'no acepta una hora ausente cuando se solicitó una hora');
assert.equal(validarOperacion(Object.assign({}, operationSnapshot, { capacidad_horario: [] }), '2026-09-29', '13:30').motivo, 'incompleta', 'rechaza un objeto de contexto entregado como arreglo');
assert.equal(validarOperacion(Object.assign({}, operationSnapshot, { horarios_mapa: null }), '2026-09-29', '13:30').motivo, 'incompleta', 'rechaza colecciones requeridas incompletas');

assert.match(operationSource, /request\.timedOut = true[\s\S]*?controller\.abort\(\)/, 'Reservaciones marca y aborta un timeout');
assert.match(operationSource, /requestSequence !== state\.requestSequence/, 'Reservaciones descarta respuestas de consultas anteriores');

console.log('Mapa: contratos HTTP locales de Reservaciones OK');
