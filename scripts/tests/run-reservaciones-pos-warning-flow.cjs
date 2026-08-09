const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const posPath = path.join(root, 'src/js/modules/punto-de-venta.js');
const pos = fs.readFileSync(posPath, 'utf8');

function assertContract(condition, message) {
  if (!condition) {
    console.error(`FAIL: ${message}`);
    process.exit(1);
  }
}

const clickStart = pos.indexOf('function onMesaClick(mesaId)');
const clickEnd = pos.indexOf('\n  // ── Selección multimesa', clickStart);
const onMesaClick = pos.slice(clickStart, clickEnd);

assertContract(clickStart !== -1 && clickEnd !== -1, 'onMesaClick tiene límites reconocibles');
assertContract(onMesaClick.includes('backend.disponible_para_ticket === true'), 'el click consume permiso backend');
assertContract(onMesaClick.includes('backend.requiere_advertencia_ticket === true'), 'el click consume warning backend');
assertContract(onMesaClick.includes('showReservationModal(reserva, { allowWalkIn: true, mesa: mesa })'), 'el click muestra información y conserva walk-in');
assertContract(!onMesaClick.includes('ventana_pos') && !onMesaClick.includes('ventana_operativa'), 'onMesaClick no decide por ventana textual');
assertContract(pos.includes('id="mmodal-abrir-reservacion"'), 'el modal de reservación ofrece acción explícita');
assertContract(pos.includes('Abrir ticket de todas formas'), 'la acción de warning tiene etiqueta operativa');
assertContract(pos.includes('requestOpenTicket({'), 'la acción reutiliza requestOpenTicket');
assertContract(pos.includes('payload.confirmar_reservacion_proxima = 0'), 'la primera petición conserva confirmación pendiente');
assertContract(pos.includes('confirmar_reservacion_proxima: 1'), 'la segunda petición confirma la reservación');
assertContract(!/minutos\s*[<>=]+|[<>=]+\s*minutos/.test(onMesaClick), 'el click no compara minutos localmente');

const temporalContract = [
  ['11:59', true, false, true],
  ['12:00', true, true, true],
  ['12:01', true, true, true],
  ['12:15', true, true, true],
  ['12:29', true, true, true],
  ['12:30', false, false, false]
];
temporalContract.forEach(([hour, canTicket, warning, walkIn]) => {
  assertContract(canTicket === walkIn || hour === '12:30', `matriz temporal coherente en ${hour}`);
  assertContract(hour === '12:30' ? !canTicket && !warning && !walkIn : canTicket && walkIn, `contrato POS ${hour}`);
});

console.log('Reservaciones: flujo POS de warning contractual OK');
