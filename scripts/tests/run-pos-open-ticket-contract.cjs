const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const sourcePath = path.join(root, 'src/js/modules/punto-de-venta.js');
const source = fs.readFileSync(sourcePath, 'utf8').replace(/\r\n/g, '\n');

function assertContract(condition, message) {
  if (!condition) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
  }
}

const classifierStart = source.indexOf('function clasificarResultadoAperturaTicket(resultado)');
const classifierEnd = source.indexOf('\n}\n\nfunction initMapa', classifierStart);
assertContract(classifierStart !== -1 && classifierEnd !== -1, 'existe el clasificador de apertura');

const classifierSource = source.slice(classifierStart, classifierEnd + 2);
const classify = vm.runInNewContext(`(${classifierSource})`, {}, {
  filename: sourcePath,
});

assertContract(
  classify({
    ok: true,
    codigo: 'REQUIERE_CONFIRMACION',
    tipo: 'decision_requerida',
    commit: false,
    advertencia: {},
  }) === 'decision',
  'Request #1 se interpreta como decision'
);
assertContract(
  classify({ ok: false, tipo: 'error', commit: false, codigo: 'MESA_OCUPADA' }) === 'error',
  'respuesta de error se interpreta como error'
);
assertContract(
  classify({ ok: true, tipo: 'exito', commit: true, codigo: 'TICKET_CREADO', ticket_id: 123 }) === 'exito',
  'respuesta confirmada se interpreta como exito'
);
assertContract(
  classify({ ok: true, tipo: 'exito', commit: false, codigo: 'TICKET_CREADO', ticket_id: 123 }) === 'inconsistente',
  'tipo exito con commit falso se rechaza como inconsistente'
);
assertContract(
  classify({ ok: true, tipo: 'informacion', commit: false, codigo: 'OK', ticket_id: 123 }) === 'inconsistente',
  'OK con ticket y commit falso se rechaza como inconsistente'
);

const requestStart = source.indexOf('function requestOpenTicket(payload, options)');
const requestEnd = source.indexOf('\n  function requestReservationOperation', requestStart);
const requestSource = source.slice(requestStart, requestEnd);
const decisionBranch = requestSource.indexOf("if (resultadoTipo === 'decision')");
const errorBranch = requestSource.indexOf("if (resultadoTipo === 'error')");
const successBranch = requestSource.indexOf("if (resultadoTipo === 'exito')");
const ticketGuard = requestSource.indexOf("if (!result.ticket_id && !result.id)");

assertContract(requestStart !== -1 && requestEnd !== -1, 'requestOpenTicket tiene limites reconocibles');
assertContract(decisionBranch !== -1 && errorBranch > decisionBranch, 'decision precede error');
assertContract(successBranch > errorBranch, 'error precede exito');
assertContract(ticketGuard > successBranch, 'ticket_id se valida despues de clasificar exito');
assertContract(
  requestSource.includes('mostrarAdvertenciaReservacionProxima(payload, warnings, result)'),
  'decision muestra la presentacion canonica antes de validar ticket_id'
);
const noticeStart = source.indexOf('function showOpenTicketNotice(options)');
const noticeEnd = source.indexOf('\n  function mostrarAdvertenciaReservacionProxima', noticeStart);
const warningStart = source.indexOf('function mostrarAdvertenciaReservacionProxima');
const warningEnd = source.indexOf('\n  function requestOpenTicket', warningStart);
assertContract(noticeStart !== -1 && noticeEnd !== -1, 'showOpenTicketNotice tiene limites reconocibles');
assertContract(warningStart !== -1 && warningEnd !== -1, 'mostrarAdvertenciaReservacionProxima tiene limites reconocibles');
const noticeSource = source.slice(noticeStart, noticeEnd);
const warningSource = source.slice(warningStart, warningEnd);
assertContract(
  noticeSource.includes('return result.then(function (value)') || noticeSource.includes('return options.onConfirm();'),
  'el modal propaga la Promise de onConfirm'
);
assertContract(
  warningSource.includes('return requestOpenTicket(confirmedPayload, { warningConfirmed: true });'),
  'el warning retorna la Promise de apertura confirmada'
);
assertContract(
  requestSource.includes('return result;'),
  'la apertura confirmada conserva el resultado para cerrar el modal canónico'
);
assertContract(
  !requestSource.includes('if (result.ok) {'),
  'requestOpenTicket no usa ok como primera precedencia'
);
assertContract(
  requestSource.includes('Respuesta contractual inconsistente al abrir el ticket.'),
  'respuesta inconsistente conserva proteccion contractual'
);

console.log('POS: contrato de decision y commit de apertura OK');
