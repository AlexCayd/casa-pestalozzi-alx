const fs = require('fs');
const path = require('path');
const childProcess = require('child_process');

const root = path.resolve(__dirname, '..', '..');
const files = [
  'src/js/components/confirmation-modal.js',
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

const modal = fs.readFileSync(path.join(root, files[0]), 'utf8');
const pos = fs.readFileSync(path.join(root, files[1]), 'utf8');
const form = fs.readFileSync(path.join(root, files[2]), 'utf8');
const operation = fs.readFileSync(path.join(root, files[3]), 'utf8');
const mapVisual = fs.readFileSync(path.join(root, files[4]), 'utf8');
const tableAdapter = fs.readFileSync(path.join(root, files[5]), 'utf8');
const mapShell = fs.readFileSync(path.join(root, 'src/scss/operation/_map-shell.scss'), 'utf8');

assertContract(!pos.includes('warningsLocalesParaTicket'), 'POS no filtra reservaciones proximas localmente');
assertContract(!pos.includes("reserva.ventana_operativa || '') !== '30_60'"), 'POS no decide por ventana 30_60');
assertContract(pos.includes('confirmar_reservacion_proxima = 0'), 'POS envia confirmacion falsa en la primera peticion');
assertContract(pos.includes("result.codigo === 'REQUIERE_CONFIRMACION'"), 'POS procesa la decision remota canonica del backend');
assertContract(pos.includes('bloqueo.presentacion'), 'POS renderiza la presentacion de cada bloqueo');
assertContract(!pos.includes('bloqueo.motivo'), 'POS no renderiza motivo interno');
assertContract(pos.includes('activeNoticeController'), 'POS conserva referencia al modal de aviso activo');
assertContract(pos.includes('closeModal({ refresh: false })'), 'POS cierra el modal antes de refrescar tras no-show');
assertContract(pos.includes('refreshFailed'), 'POS informa refresco fallido sin reintentar la mutacion');

assertContract(!form.includes('warningCodesForSubmit'), 'formulario no calcula decisiones locales');
assertContract(!form.includes('labels[code] || code'), 'formulario no tiene mapa local de mensajes');
assertContract(!form.includes("label: 'Confirmar', tipo: 'primary'"), 'formulario no inventa accion primaria de decision');
assertContract(form.includes('decisionConfirmationOptions'), 'formulario adapta decisiones estructuradas');
assertContract(form.includes('decisionActions'), 'formulario usa acciones canonicas del backend');
assertContract(/acceptedConfirmationCodes\s*=\s*acceptedConfirmationCodes\s*\.concat/.test(form), 'formulario conserva confirmaciones aceptadas entre decisiones');
assertContract(form.includes("payload.tipo === 'decision_requerida'"), 'formulario prioriza tipo canonico de decision');
assertContract(operation.includes('commitCreationResult'), 'operacion trata el commit de creacion como exito');
assertContract(!operation.includes('confirmaciones_requeridas_presentaciones'), 'operacion no consume mapas paralelos');
assertContract(operation.includes('decisionObjects'), 'operacion consume decisiones estructuradas');
assertContract(!operation.includes("label: 'Confirmar', tipo: 'primary'"), 'operacion no inventa accion primaria de decision');
assertContract(operation.includes('modificadores_visual_mapa'), 'operacion consume modificadores visuales del mapa');
assertContract(operation.includes('projectionContext'), 'operacion conserva contexto atomico de proyeccion');
assertContract(operation.includes('pendingProjectionContext'), 'operacion bloquea render mientras carga una proyeccion');
assertContract(operation.includes('mapProjectionFor'), 'operacion valida el contrato cerrado del mapa');
assertContract(operation.includes('loadDay(nextDate'), 'operacion recarga al seleccionar una reserva de otra hora');
assertContract(operation.includes('responseHour'), 'operacion valida la hora de la respuesta');
assertContract(!operation.includes('normalized.modificadores_mapa'), 'operacion no usa alias de modificadores del mapa');
assertContract(!operation.includes("estadoVisualMapa = 'reservacion-proxima'"), 'operacion no reconstruye proximidad visual');
assertContract(operation.includes('renderOperationAvailability'), 'operacion centraliza disponibilidad del boton crear');
assertContract(operation.includes("String(data.fecha || '') !== fecha"), 'operacion rechaza respuestas de fecha stale');
assertContract(operation.includes('requestSequence !== state.requestSequence'), 'operacion protege respuestas fuera de orden');
assertContract(!operation.includes('ventana_operativa'), 'operacion no recalcula ventanas visuales');
assertContract(operation.includes('estado_visual_mapa'), 'operacion consume proyeccion visual del backend');
assertContract(operation.includes('currentAssignmentIds'), 'operacion conserva snapshot de asignacion actual');
assertContract(operation.includes('candidateSelectionIds'), 'operacion separa seleccion candidata');
assertContract(!operation.includes('state.mesasSeleccionadas'), 'operacion no reutiliza una coleccion ambigua de mesas');
assertContract(operation.includes('mesa_ids_actuales[]'), 'operacion envia el snapshot actual al backend');
assertContract(operation.includes('mesa.ticket_abierto !== true'), 'operacion excluye tickets de la seleccion candidata');
assertContract(operation.includes("['CONFLICTO_TICKETS_ABIERTOS', 'CONFLICTO_TICKET_ABIERTO']"), 'operacion no abre confirmacion para un ticket ajeno');
assertContract(mapVisual.includes('ariaLabel'), 'mapa visual expone etiqueta accesible por mesa');
assertContract(mapVisual.includes('aria-disabled'), 'mapa visual expone estado disabled accesible');
assertContract(mapVisual.includes('previousClasses.forEach'), 'mapa visual remueve clases stale antes de actualizar');
assertContract(tableAdapter.includes('modificadores: modifiers'), 'adaptador conserva modificadores del backend');
assertContract(!tableAdapter.includes('if (hasPendingAbsence) return'), 'adaptador no sustituye estado por ausencia');
assertContract(mapShell.includes('mesa-pin--mod-ausencia_pendiente::after'), 'CSS compone ausencia con pseudo-elemento gris');
assertContract(!mapShell.includes('.mesa-pin--libre.mesa-pin--mod-ausencia_pendiente'), 'CSS no fuerza ausencia a verde mediante borde');
assertContract(tableAdapter.includes('options.estadoVisual'), 'adaptador consume estado visual explicito');
assertContract(!tableAdapter.includes('raw.estado_visual_mapa'), 'adaptador no filtra proyeccion administrativa al POS');
assertContract(modal.includes('canonicalDecisionActions'), 'ConfirmationModal valida acciones canonicas');
assertContract(modal.includes('textValue(options.mensaje).trim()'), 'ConfirmationModal exige mensaje de decision');
assertContract(modal.includes('Decisión de reservación sin acciones canónicas'), 'ConfirmationModal registra decisiones sin acciones');
assertContract(modal.includes('current.decisionActions'), 'ConfirmationModal configura botones desde acciones');

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
assertContract(
  adapter.paraMapaVisual({ id: 4, reservable: 1, estado_base: 'ocupada', modificadores: ['ausencia_pendiente'] }, { estadoBase: 'ocupada' }).estadoVisual === 'ocupada',
  'rojo conserva estado base con ausencia'
);
assertContract(
  adapter.paraMapaVisual({ id: 5, reservable: 1, estado_base: 'bloqueada', modificadores: ['ausencia_pendiente'] }, { estadoBase: 'bloqueada', estadoVisual: 'reservacion-proxima' }).estadoVisual === 'reservacion-proxima',
  'azul conserva estado base con ausencia'
);
assertContract(
  adapter.paraMapaVisual({ id: 6, reservable: 1, estado_base: 'disponible', modificadores: ['reservacion_advertencia', 'ausencia_pendiente'] }, { estadoBase: 'disponible' }).modificadores.join(' ') === 'reservacion_advertencia ausencia_pendiente',
  'verde conserva warning y ausencia como modificadores'
);
assertContract(operation.includes('assignment_snapshot'), 'reasignación conserva snapshot persistido');
assertContract(operation.includes('state.currentAssignmentIds = new Set(assignmentIdsFor(selected))'), 'reasignación reconstruye currentAssignmentIds');
assertContract(operation.includes('state.candidateSelectionIds = preserveAssignment'), 'reasignación reconstruye candidateSelectionIds');
assertContract(operation.includes("'data-disabled': !selectable"), 'reasignación conserva data-disabled contractual');
assertContract(
  adapter.paraMapaVisual(
    { id: 3, reservable: 1, estado_base: 'ocupada', ticket_abierto: true },
    { estadoBase: 'ocupada', seleccionActual: true, seleccionValida: true, seleccionPrioritaria: true, estadoVisual: 'seleccionada' }
  ).estadoVisual === 'seleccionada',
  'seleccion valida puede ser amarilla sin borrar el hecho de bloqueo'
);

console.log('Reservaciones: JS contractual OK');
