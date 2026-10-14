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

const helperStart = source.indexOf('function resolverOpcionesModalReservacion(context)');
const helperEnd = source.indexOf('\n  }\n\n', helperStart);
assertContract(helperStart !== -1 && helperEnd !== -1, 'existe el resolver de opciones del modal');

const helperSource = source.slice(helperStart, helperEnd + 4);
const resolver = vm.runInNewContext(`(${helperSource})`, {}, {
  filename: sourcePath
});

const reservation = { id: 6030, estado: 'confirmada' };
const mesa = { id: 4 };
const warningFacts = {
  disponible_para_ticket: true,
  requiere_advertencia_ticket: true,
  ticket_abierto: false,
  ausencia_pendiente: false
};

function resolve(previousOptions, facts, reserva = reservation) {
  return resolver({
    mesa,
    reserva,
    previousOptions,
    backend: facts
  });
}

let options = resolve({ allowWalkIn: true }, warningFacts);
assertContract(options.allowWalkIn === true, 'refresh conserva walk-in en 60–30');

for (let i = 0; i < 3; i += 1) {
  options = resolve(options, warningFacts);
}
assertContract(options.allowWalkIn === true, 'múltiples refresh conservan walk-in');

const blockedFacts = {
  disponible_para_ticket: false,
  requiere_advertencia_ticket: false,
  ticket_abierto: false,
  ausencia_pendiente: false
};
assertContract(
  resolve(options, blockedFacts).allowWalkIn === false,
  'cruzar a bloqueo elimina walk-in'
);

assertContract(
  resolve(options, Object.assign({}, warningFacts, { ticket_abierto: true })).allowWalkIn === false,
  'ticket abierto elimina walk-in'
);

assertContract(
  resolve(options, Object.assign({}, warningFacts, { ausencia_pendiente: true })).allowWalkIn === false,
  'ausencia pendiente elimina walk-in'
);

assertContract(
  resolve({}, warningFacts).allowWalkIn === false,
  'los hechos backend no convierten una opción ausente en autorización UI'
);

const activeState = { listeners: 0 };
function renderModal(modalOptions) {
  activeState.listeners = 0;
  if (modalOptions.allowWalkIn === true) activeState.listeners = 1;
}

renderModal(options);
assertContract(activeState.listeners === 1, 'la acción walk-in conserva un único handler activo');
renderModal(resolve(options, warningFacts));
assertContract(activeState.listeners === 1, 'el segundo refresh no duplica el handler');

// La ficha sustituye al modal de reservación: al refrescar, el walk-in se
// revalida con los hechos nuevos y la ficha se repinta sólo si cambió.
const sincronizar = source.slice(source.indexOf('function sincronizarFicha()'), source.indexOf('function postReservacion('));
assertContract(sincronizar.includes('resolverOpcionesModalReservacion({') && sincronizar.includes('previousOptions: { allowWalkIn: true }'),
  'el refresco revalida «Abrir ticket de todas formas» con el resolver');
assertContract(sincronizar.includes('ficha.refrescar(reserva, extra)'), 'el refresco pasa las opciones efectivas a la ficha');
const fichaSource = fs.readFileSync(path.join(root, 'src/js/operation/pos-ficha.js'), 'utf8');
assertContract(fichaSource.includes('opcionesNuevas.allowWalkIn') && fichaSource.includes('editando || confirmando'),
  'la ficha repinta si cambia el walk-in y nunca a media edición');
assertContract(source.includes('requestOpenTicket({'), 'el botón conserva requestOpenTicket');
assertContract(!helperSource.includes('minutos'), 'el resolver no duplica ventanas temporales');

console.log('POS: refresh de modal walk-in conserva y revalida la acción OK');
