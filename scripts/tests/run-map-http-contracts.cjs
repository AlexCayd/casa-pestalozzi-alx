const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const posSource = fs.readFileSync(path.join(root, 'src/js/modules/punto-de-venta.js'), 'utf8');

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

assert.match(posSource, /timedOut = true[\s\S]*?requestController\.abort\(\)/, 'POS marca y aborta un timeout');
assert.match(posSource, /mapSnapshotStale = true[\s\S]*?setConsultaEstado\(mapFailureCopy, true\)/, 'POS conserva el snapshot como stale y bloquea operaciones dependientes');
assert.match(posSource, /mapFailureCause \+ mapFailureConsequence/, 'POS muestra una causa y una consecuencia una sola vez');
assert.match(posSource, /requestSequence !== dataRequestSequence/, 'POS descarta respuestas de consultas anteriores (la proyección cambia de hora)');

console.log('Mapa: contratos HTTP locales del POS OK');
