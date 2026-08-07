const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

const root = path.resolve(__dirname, '..', '..');
const files = [
  'src/js/modules/punto-de-venta.js',
  'src/js/admin/reservations/form.js',
  'src/js/admin/reservations/operation.js',
  'src/js/operation/map-visual.js',
  'src/js/operation/table-state-adapter.js'
];

function assertContract(condition, message) {
  if (!condition) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
  }
}

for (const relative of files) {
  const file = path.join(root, relative);
  const result = childProcess.spawnSync(process.execPath, ['--check', file], {
    encoding: 'utf8'
  });
  assertContract(result.status === 0, `${relative} tiene sintaxis valida\n${result.stderr || ''}`);
}

const pos = fs.readFileSync(path.join(root, files[0]), 'utf8');
const form = fs.readFileSync(path.join(root, files[1]), 'utf8');
const operation = fs.readFileSync(path.join(root, files[2]), 'utf8');
const mapVisual = fs.readFileSync(path.join(root, files[3]), 'utf8');
const tableAdapter = fs.readFileSync(path.join(root, files[4]), 'utf8');

assertContract(!pos.includes('warningsLocalesParaTicket'), 'POS no filtra reservaciones proximas localmente');
assertContract(!pos.includes("reserva.ventana_operativa || '') !== '30_60'"), 'POS no decide por ventana 30_60');
assertContract(pos.includes('confirmar_reservacion_proxima = 0'), 'POS envia confirmacion falsa en la primera peticion');
assertContract(pos.includes("result.codigo === 'RESERVACION_PROXIMA'"), 'POS procesa la decision canonica del backend');
assertContract(pos.includes('bloqueo.presentacion'), 'POS renderiza la presentacion de cada bloqueo');
assertContract(!pos.includes('bloqueo.motivo'), 'POS no renderiza motivo interno');
assertContract(pos.includes('activeNoticeController'), 'POS conserva referencia al modal de aviso activo');
assertContract(pos.includes('closeModal({ refresh: false })'), 'POS cierra el modal antes de refrescar tras no-show');
assertContract(pos.includes('refreshFailed'), 'POS informa refresco fallido sin reintentar la mutacion');

assertContract(!form.includes('warningCodesForSubmit'), 'formulario no calcula decisiones locales');
assertContract(!form.includes('labels[code] || code'), 'formulario no tiene mapa local de mensajes');
assertContract(form.includes('decisionConfirmationOptions'), 'formulario adapta decisiones estructuradas');
assertContract(form.includes("payload.tipo === 'decision_requerida'"), 'formulario prioriza tipo canonico de decision');
assertContract(operation.includes('commitCreationResult'), 'operacion trata el commit de creacion como exito');
assertContract(!operation.includes('confirmaciones_requeridas_presentaciones'), 'operacion no consume mapas paralelos');
assertContract(operation.includes('decisionObjects'), 'operacion consume decisiones estructuradas');
assertContract(operation.includes('renderOperationAvailability'), 'operacion centraliza disponibilidad del boton crear');
assertContract(operation.includes("String(data.fecha || '') !== fecha"), 'operacion rechaza respuestas de fecha stale');
assertContract(operation.includes('requestSequence !== state.requestSequence'), 'operacion protege respuestas fuera de orden');
assertContract(!operation.includes('ventana_operativa'), 'operacion no recalcula ventanas visuales');
assertContract(operation.includes('estado_visual_mapa'), 'operacion consume proyeccion visual del backend');
assertContract(mapVisual.includes('ariaLabel'), 'mapa visual expone etiqueta accesible por mesa');
assertContract(tableAdapter.includes('options.estadoVisual'), 'adaptador consume estado visual explicito');
assertContract(!tableAdapter.includes('raw.estado_visual_mapa'), 'adaptador no filtra proyeccion administrativa al POS');

const vm = require('vm');
const adapterContext = { window: {} };
vm.runInNewContext(tableAdapter, adapterContext, { filename: files[4] });
const adapter = adapterContext.window.MesaEstadoAdapter;
const sharedRaw = {
  id: 1,
  reservable: 1,
  estado_base: 'disponible',
  estado_visual_mapa: 'ocupada'
};
assertContract(
  adapter.paraMapaVisual(sharedRaw, { estadoBase: 'disponible' }).estadoVisual === 'libre',
  'POS no hereda estado_visual_mapa administrativo sin opt-in'
);
assertContract(
  adapter.paraMapaVisual(sharedRaw, { estadoBase: 'disponible', estadoVisual: 'ocupada' }).estadoVisual === 'ocupada',
  'mapa administrativo acepta estado visual explicito'
);
assertContract(
  adapter.paraMapaVisual({ id: 2, reservable: 1, estado_base: 'disponible', ticket_abierto: true }, { estadoBase: 'disponible' }).estadoVisual === 'ocupada',
  'ticket abierto conserva precedencia roja'
);

console.log('Reservaciones: JS contractual OK');
