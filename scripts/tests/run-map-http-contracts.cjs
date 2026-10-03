const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const posSource = fs.readFileSync(path.join(root, 'src/js/modules/punto-de-venta.js'), 'utf8');
const operationSource = fs.readFileSync(path.join(root, 'src/js/admin/reservations/operation.js'), 'utf8');

function extractFunction(source, startMarker, endMarker, file) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assert.ok(start >= 0 && end > start, `${file}: localiza ${startMarker}`);
  return vm.runInNewContext('(' + source.slice(start, end).trim() + ')', {}, { filename: file });
}

const validarPos = extractFunction(
  posSource,
  'function validatePosMapResponse(data, expectedDate)',
  '\n  function fetchData',
  'punto-de-venta.js'
);
const validarOperacion = extractFunction(
  operationSource,
  'function validarRespuestaMapaOperacion(payload, fecha, hora)',
  '\n    function initReservationOperation',
  'operation.js'
);

const posSnapshot = {
  ok: true,
  fecha: '2026-09-29',
  mesas: [{ id: 1 }],
  mesas_estado: [{
    id: 1,
    estado_visual_pos: 'visual-desconocido',
    capacidades_pos: {
      reservable: true,
      operable: true,
      ticketable: true,
      independiente_consulta: false,
      abrir_caja: false,
      crear_pedido_llevar: false,
      mostrar_estado_ticket: false,
      decorativo: false,
      etiqueta_operacion: ''
    }
  }],
  reservaciones: [],
  tickets: [],
  meseros: [],
  alertas_impresion: []
};
assert.equal(validarPos(posSnapshot, '2026-09-29').valida, true, 'acepta el snapshot POS estructuralmente válido');
assert.equal(validarPos(posSnapshot, '2026-09-30').motivo, 'contexto', 'descarta una fecha POS stale');
assert.equal(validarPos(Object.assign({}, posSnapshot, { tickets: {} }), '2026-09-29').motivo, 'incompleta', 'rechaza una colección POS inválida');
assert.equal(validarPos(Object.assign({}, posSnapshot, { mesas_estado: [{ id: 0 }] }), '2026-09-29').motivo, 'incompleta', 'rechaza una mesa sin id válido');
assert.equal(validarPos(Object.assign({}, posSnapshot, { mesas_estado: [{ id: 1 }] }), '2026-09-29').motivo, 'incompleta', 'rechaza capacidades POS ausentes');

const operationSnapshot = {
  ok: true,
  fecha: '2026-09-29',
  hora: '13:30:00',
  mesas: [{ id: 1 }],
  mesas_estado: [{ id: 1, estado_visual_mapa: 'visual-desconocido' }],
  reservaciones: [],
  horarios_mapa: ['13:30'],
  ocupacion_fisica: [],
  alertas_operativas: [],
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

assert.match(posSource, /timedOut = true[\s\S]*?requestController\.abort\(\)/, 'POS marca y aborta un timeout');
assert.match(posSource, /mapSnapshotStale = true[\s\S]*?setConsultaEstado\(mapFailureCopy, true\)/, 'POS conserva el snapshot como stale y bloquea operaciones dependientes');
assert.match(posSource, /mapFailureCause \+ mapFailureConsequence/, 'POS muestra una causa y una consecuencia una sola vez');
assert.match(operationSource, /request\.timedOut = true[\s\S]*?controller\.abort\(\)/, 'Reservaciones marca y aborta un timeout');
assert.match(operationSource, /requestSequence !== state\.requestSequence/, 'Reservaciones descarta respuestas de consultas anteriores');

console.log('Mapa: contratos HTTP locales de POS y Reservaciones OK');
