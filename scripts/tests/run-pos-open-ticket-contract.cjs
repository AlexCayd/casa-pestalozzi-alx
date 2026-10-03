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

function sourceBetween(startMarker, endMarker) {
  const start = source.indexOf(startMarker);
  const end = source.indexOf(endMarker, start);
  assertContract(start !== -1 && end > start, `se encuentra ${startMarker}`);
  return source.slice(start, end).trim();
}

const specialMapContext = {
  window: { MapaContrato: { validarEstado: (value) => ({ valido: ['libre', 'ocupada', 'reservacion-proxima', 'no-utilizable'].includes(value) }), validarModificadores: Array.isArray } },
  mesasEstado: new Map(),
  pedidosLlevar() { return []; },
  rotuloPedidosLlevar(total) { return total ? `${total} pedidos` : ''; },
  tituloLlevarMapa() { return 'Llevar. Disponible para un nuevo pedido.'; },
  insetPos(value) { return value; },
  reservaParaModal() { return null; },
  ticketActual() { return null; },
  reservacionProximaMesa() { return null; },
  ticketSelectionMode: false,
  selectedMesaIds: []
};
function evaluatePosFunction(startMarker, endMarker, targetContext) {
  return vm.runInNewContext('(' + sourceBetween(startMarker, endMarker) + ')', targetContext);
}
specialMapContext.mesaEstadoPorId = (id) => specialMapContext.mesasEstado.get(Number(id)) || null;
specialMapContext.capacidadesPosMesa = evaluatePosFunction('function capacidadesPosMesa(mesa)', '\n  function isLlevar', specialMapContext);
specialMapContext.isLlevar = evaluatePosFunction('function isLlevar(mesa)', '\n  /*', specialMapContext);
specialMapContext.mesaReservable = evaluatePosFunction('function mesaReservable(mesa)', '\n  function mesaTicketable', specialMapContext);
specialMapContext.mesaTicketable = evaluatePosFunction('function mesaTicketable(mesa)', '\n  // La "Caja"', specialMapContext);
specialMapContext.esCaja = evaluatePosFunction('function esCaja(mesa)', '\n  function estadoVisualPosMesa', specialMapContext);
specialMapContext.estadoVisualPosMesa = evaluatePosFunction('function estadoVisualPosMesa', '\n  function reservacionProximaMesa', specialMapContext);
specialMapContext.mesasEstado.set(90, { id: 90, capacidades_pos: { abrir_caja: true, operable: true, independiente_consulta: true } });
specialMapContext.mesasEstado.set(91, { id: 91, capacidades_pos: { crear_pedido_llevar: true, operable: true, independiente_consulta: true } });
specialMapContext.mesasEstado.set(92, {
  id: 92,
  estado_visual_pos: 'libre',
  aria_label_pos: 'Barra operativa. Disponible para abrir un ticket.',
  modificadores_visual_pos: [],
  ticket_bloquea_consulta: false,
  capacidades_pos: { ticketable: true, operable: true, reservable: false, mostrar_estado_ticket: true, etiqueta_operacion: 'Barra' }
});
specialMapContext.insetPos = (value) => value;
const cajaContract = vm.runInNewContext('(' + sourceBetween('function contratoMesaMapa(mesa, estado)', '\n  // El contrato de Llevar') + ')', specialMapContext);
const cajaVisual = cajaContract({ id: 90, tipo: 'mesa', nombre: 'Elemento con flujo de caja' }, 'zona');
assertContract(cajaVisual.estado_visual_pos === 'libre', 'Caja conserva su estado visual operativo propio sin datos de ocupación');

const llevarContract = vm.runInNewContext('(' + sourceBetween('function contratoLlevarMapa(mesa)', '\n  function contratoVisualMesaValido') + ')', specialMapContext);
const llevarVisual = llevarContract({ id: 91, tipo: 'mesa', nombre: 'Elemento con flujo de llevar' });
assertContract(llevarVisual.estado_visual_pos === 'libre' && llevarVisual.independienteDeConsulta, 'Llevar conserva su contrato operativo independiente de la consulta de mesas');

const specialOptions = vm.runInNewContext('(' + sourceBetween('function opcionesVisualesMesa(mesa, estado)', '\n  function mesaPuedeSeleccionarse') + ')', specialMapContext);
const cajaOptions = specialOptions({ id: 90, tipo: 'mesa', nombre: 'Elemento con flujo de caja', pos_x: 20, pos_y: 30 }, 'zona');
assertContract(cajaOptions.interactivo && cajaOptions.independienteDeConsulta && !cajaOptions.seleccionValida, 'Caja conserva su acción propia y no entra en selección multimesa');
const llevarOptions = specialOptions({ id: 91, tipo: 'mesa', nombre: 'Elemento con flujo de llevar', pos_x: 40, pos_y: 30 }, 'zona');
assertContract(llevarOptions.interactivo && llevarOptions.independienteDeConsulta, 'Llevar conserva la apertura de su tablero');

const barra = { id: 92, tipo: 'mesa', nombre: 'Mesa 92', reservable: false, pos_x: 50, pos_y: 50 };
const barraContract = cajaContract(barra, 'zona');
const barraOptions = specialOptions(barra, 'zona');
const mapRuntime = { window: {} };
vm.runInNewContext(fs.readFileSync(path.join(root, 'src/js/operation/map-contract.js'), 'utf8'), mapRuntime);
vm.runInNewContext(fs.readFileSync(path.join(root, 'src/js/operation/map-visual.js'), 'utf8'), mapRuntime);
vm.runInNewContext(fs.readFileSync(path.join(root, 'src/js/operation/table-state-adapter.js'), 'utf8'), mapRuntime);
const barraProjection = mapRuntime.window.MesaEstadoAdapter.paraMapaVisual(barraContract, barraOptions);
assertContract(barraContract.estado_visual_pos === 'libre', 'Barra activa no reservable recibe el contrato visual operativo de POS');
assertContract(barraProjection.interactivo && barraProjection.estadoNoVerificado === false, 'Barra sigue operable según su permiso POS aunque no sea reservable');

console.log('POS: apertura y contratos de elementos operativos OK');
