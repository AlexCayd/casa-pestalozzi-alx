const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

const root = path.resolve(__dirname, '..', '..');
const files = [
  'src/js/modules/punto-de-venta.js',
  'src/js/admin/reservations/form.js',
  'src/js/admin/reservations/operation.js'
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

assertContract(!pos.includes('warningsLocalesParaTicket'), 'POS no filtra reservaciones proximas localmente');
assertContract(!pos.includes("reserva.ventana_operativa || '') !== '30_60'"), 'POS no decide por ventana 30_60');
assertContract(pos.includes('confirmar_reservacion_proxima = 0'), 'POS envia confirmacion falsa en la primera peticion');
assertContract(pos.includes("result.codigo === 'RESERVACION_PROXIMA'"), 'POS procesa la decision canonica del backend');
assertContract(pos.includes('bloqueo.presentacion'), 'POS renderiza la presentacion de cada bloqueo');
assertContract(!pos.includes('bloqueo.motivo'), 'POS no renderiza motivo interno');

assertContract(!form.includes('warningCodesForSubmit'), 'formulario no calcula decisiones locales');
assertContract(!form.includes('labels[code] || code'), 'formulario no tiene mapa local de mensajes');
assertContract(form.includes('decisionConfirmationOptions'), 'formulario adapta decisiones estructuradas');
assertContract(!operation.includes('confirmaciones_requeridas_presentaciones'), 'operacion no consume mapas paralelos');
assertContract(operation.includes('decisionObjects'), 'operacion consume decisiones estructuradas');

console.log('Reservaciones: JS contractual OK');
