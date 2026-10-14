/*
 * Reservaciones dentro del POS: vista compartida, cajón por horario, barra de
 * contexto y ficha. Los módulos pintan con cadenas, así que basta un DOM
 * mínimo que guarde el innerHTML.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');

function nodo() {
  return {
    innerHTML: '',
    textContent: '',
    hidden: false,
    listeners: {},
    attrs: {},
    addEventListener(tipo, fn) { (this.listeners[tipo] ||= []).push(fn); },
    querySelector() { return null; },
    setAttribute(n, v) { this.attrs[n] = String(v); },
    getAttribute(n) { return this.attrs[n] ?? null; },
    contains() { return true; },
    focus() {}
  };
}

const iconos = [];
const contexto = {
  window: { AdminIcons: { get: (n) => { iconos.push(n); return '<svg data-i="' + n + '"></svg>'; } }, setTimeout, matchMedia: () => ({ matches: true }) },
  document: { querySelector: () => null, getElementById: () => null, createElement: () => nodo() },
};
contexto.window.window = contexto.window;
for (const archivo of ['pos-reserva-vista.js', 'pos-cajon.js', 'pos-contexto.js', 'pos-ficha.js']) {
  vm.runInNewContext(fs.readFileSync(path.join(root, 'src/js/operation', archivo), 'utf8'), contexto, { filename: archivo });
}
const V = contexto.window.PosReservaVista;

// ── Vista compartida ─────────────────────────────────────────────────
const plano = { 1: { nombre: 'Mesa 1', numero: 1 }, 2: { nombre: 'Mesa 2', numero: 2 }, 5: { nombre: 'Mesa 5', numero: 5 }, 6: { nombre: 'Mesa 6', numero: 6 }, 3: { nombre: 'Mesa 3', numero: 3 }, 4: { nombre: 'Mesa 4', numero: 4 }, 8: { nombre: 'Mesa 8', numero: 8 }, 9: { nombre: 'Mesa 9', numero: 9 }, 10: { nombre: 'Mesa 10', numero: 10 }, 11: { nombre: 'Mesa 11', numero: 11 } };
const mesaPorId = (id) => plano[id] || null;
assert.equal(V.nombresMesas([3], mesaPorId), 'Mesa 3');
assert.equal(V.nombresMesas([3, 4], mesaPorId), 'Mesas 3 y 4', 'dos vecinas van con «y», sin rango');
assert.equal(V.nombresMesas([8, 9, 10, 11], mesaPorId), 'Mesas 8 a 11', 'tres o más seguidas van en rango');
assert.equal(V.nombresMesas([2, 3, 4, 5, 6, 8, 9, 10, 11], mesaPorId), 'Mesas 2 a 6 y 8 a 11', 'un grupo grande se nombra por tramos');
assert.equal(V.nombresMesas([1, 5, 9], mesaPorId), 'Mesas 1, 5 y 9', 'sueltas, en lista');

const base = { id: 1, estado: 'confirmada', hora: '20:00:00', comensales: 2, mesa_ids: [3] };
assert.equal(V.senalTiempo(Object.assign({}, base, { ventana_pos: 'futura' })), null, 'a más de una hora no hay señal');
assert.match(V.senalTiempo(Object.assign({}, base, { ventana_pos: 'advertencia', minutos_para_inicio: 18 })).html, /en <b>18<\/b> min/);
assert.equal(V.senalTiempo(Object.assign({}, base, { ventana_pos: 'inicio' })).html, 'Llega ahora');
const sinLlegar = V.senalTiempo(Object.assign({}, base, { ventana_pos: 'ausencia_pendiente', ausencia_pendiente: true, minutos_retraso: 27 }));
assert.equal(sinLlegar.variante, 'vencida', 'la tolerancia vencida ya no es «sin llegar»: se registra sola');
assert.equal(sinLlegar.html, 'Se libera', 'la señal dice que la mesa se libera, no que haya que actuar');
assert.match(V.senalTiempo(Object.assign({}, base, { estado: 'en_curso', ticket: { hora_apertura: '2026-10-09 19:05:00' } })).html, /En mesa · <b>19:05<\/b>/);
assert.equal(V.senalTiempo(Object.assign({}, base, { estado: 'cancelada' })).texto, 'Cancelada');
assert.equal(V.senalTiempo(Object.assign({}, base, { estado: 'pendiente_verificacion' })).texto, 'Apartada');
const extra = V.senalesExtra(Object.assign({}, base, { reubicada: true, comensales: 14 }), { maxComensales: 12 });
assert.equal(JSON.stringify(extra.map((s) => s.texto)), JSON.stringify(['Reubicada', 'Grupo grande']), 'reubicada y grupo grande, en ese orden');
assert.equal(V.fechaLarga('2026-10-10'), 'Sábado 10 de octubre', 'la fecha sale en cristiano sin Intl');
assert.equal(V.fechaCorta('2026-10-10'), 'Sáb 10 oct');
assert.equal(V.esc('<b>"Ana"</b>'), '&lt;b&gt;&quot;Ana&quot;&lt;/b&gt;', 'el nombre del cliente se escapa');

// ── Cajón por horario ───────────────────────────────────────────────
const lista = nodo();
const contador = nodo();
const cajon = contexto.window.PosCajon.crear({ lista, contador });
const reservas = [
  Object.assign({}, base, { id: 10, nombre: 'Gómez', hora: '20:00:00', comensales: 6, mesa_ids: [3, 4], ventana_pos: 'advertencia', minutos_para_inicio: 18, nota: 'Silla alta', contacto: '55 1234 5678' }),
  Object.assign({}, base, { id: 11, nombre: 'Ortega', hora: '20:00:00', mesa_ids: [], ventana_pos: 'futura' }),
  Object.assign({}, base, { id: 12, nombre: 'Ramírez', hora: '19:00:00', estado: 'en_curso', ticket: { hora_apertura: '2026-10-09 19:05:00' } }),
  Object.assign({}, base, { id: 13, nombre: 'Sin <script>', hora: '21:00:00', estado: 'cancelada' }),
  Object.assign({}, base, { id: 14, nombre: 'Andrea', hora: '18:30:00', ausencia_pendiente: true, ventana_pos: 'ausencia_pendiente', minutos_retraso: 27 })
];
const estadoCajon = { reservas, esHoy: true, ahoraHM: '19:42', fechaIso: '2026-10-09', contexto: { modo: 'proyeccion', hora: '20:00:00' }, enfocadaId: 10, esAdmin: false, mesaPorId, esBloque: () => false, maxComensales: 12 };
cajon.pintar(estadoCajon);
const html = lista.innerHTML;
assert.equal(contador.textContent, '4', 'el contador cuenta las vivas, no las canceladas');
assert.ok(html.indexOf('>18:30<') < html.indexOf('>19:00<') && html.indexOf('>19:00<') < html.indexOf('>20:00<'), 'los horarios van en orden');
assert.match(html, /data-horario="20:00"[^>]*aria-current="true"/, 'el horario proyectado va marcado');
assert.ok(!/data-horario="19:00"/.test(html) && /En vivo/.test(html), 'un horario que ya llegó no es botón: no se proyecta al pasado');
assert.match(html, /3 reservas · 11 pers\.|2 reservas · 8 pers\./, 'la cabecera resume reservas y personas');
assert.match(html, /Sin mesa asignada/, 'la reserva sin mesa lo dice en la tarjeta');
assert.match(html, /class="pos-reserva is-enfocada"[^>]*data-id="10"/, 'la reserva de la ficha va enfocada');
assert.ok(!html.includes('55 1234 5678'), 'el mesero no ve el contacto');
assert.match(html, /Ya resueltas · 1/, 'las resueltas van plegadas al final');
assert.ok(!html.includes('<script>'), 'los nombres se escapan');
assert.ok(html.indexOf('data-id="14"') < html.indexOf('data-id="10"'), 'sin llegar va antes que por llegar');
// El color sólo donde hay estado: la señal del pin (azul) y la tolerancia
// vencida (azul hondo); «Grupo grande» y compañía son texto, sin pastilla.
assert.match(html, /pos-senal pos-senal--reservada[^"]*"><svg data-i="estado-reservada"><\/svg><span>en <b>18<\/b> min/, 'la señal de tiempo va en el azul del pin');
assert.match(html, /pos-senal pos-senal--vencida/, 'la tolerancia vencida tiene su variante');
cajon.pintar(Object.assign({}, estadoCajon, { esAdmin: true }));
assert.ok(lista.innerHTML.includes('55 1234 5678'), 'el admin sí ve el contacto');
cajon.pintar(Object.assign({}, estadoCajon, { reservas: [], esHoy: false, fechaIso: '2026-10-10' }));
assert.match(lista.innerHTML, /Sin reservaciones para el sábado 10 de octubre/, 'vacío de otro día dice cuál');

// ── Barra de contexto ───────────────────────────────────────────────
const raizContexto = nodo();
const modo = nodo();
const datos = nodo();
raizContexto.querySelector = (sel) => (sel === '[data-contexto-modo]' ? modo : sel === '[data-contexto-datos]' ? datos : null);
const barra = contexto.window.PosContexto.crear({ raiz: raizContexto });
barra.pintar({ modo: 'ahora', esHoy: true, fecha: '2026-10-09', hora: '19:42', ahoraHM: '19:42', horas: ['20:00', '21:00'], horario: { codigo: 'abierto' }, capacidad: { libre: 32, total: 44 }, proxima: { reservacion_id: 10, hora: '20:00', nombre: 'Gómez', comensales: 6 }, enHora: { reservas: 0, personas: 0 }, esAdmin: false });
assert.match(modo.innerHTML, /Ahora<\/span><time data-contexto-reloj>19:42/, 'en vivo dice Ahora y la hora');
assert.match(datos.innerHTML, /<span class="pos-contexto__fraccion"><b>32<\/b>\/44<\/span><span>libres/, 'los libres van contra el total, como una sola cifra');
assert.match(datos.innerHTML, /^<div class="pos-contexto__pastilla">/, 'los datos van en una sola pastilla');
assert.ok(!/data-contexto-proxima|<button/.test(datos.innerHTML), 'la barra ya no muestra la próxima llegada: sólo los libres');
barra.pintar({ modo: 'proyeccion', esHoy: true, fecha: '2026-10-09', hora: '20:00', ahoraHM: '19:42', horas: ['19:30', '20:00', '21:00'], horario: { codigo: 'abierto' }, capacidad: { libre: 14, total: 44 }, enHora: { reservas: 3, personas: 11 } });
assert.match(modo.innerHTML, /Vista de las<\/span><time>20:00/, 'en proyección dice la hora vista');
assert.match(modo.innerHTML, /data-contexto-paso=""[^>]*disabled/, 'hoy no hay paso atrás a una hora pasada (19:30 < 19:42)');
assert.match(modo.innerHTML, /data-contexto-paso="20:30"/, 'el paso adelante es de media hora');
assert.match(modo.innerHTML, /data-contexto-ahora/, 'hay salida a Ahora');
assert.ok(!/pers\./.test(datos.innerHTML), 'en proyección la barra no cuenta reservas ni personas: lo dice el plano');
// Otro día, reservas a las 13:00 y a las 15:00: el paso es de una hora, no de
// horario con reservas a horario con reservas.
barra.pintar({ modo: 'proyeccion', esHoy: false, fecha: '2026-10-13', hora: '13:00', ahoraHM: '09:00', horas: ['13:00', '15:00'], horario: { codigo: 'abierto', hora_apertura: '13:00:00', hora_cierre: '23:00:00' }, capacidad: { libre: 42, total: 44 }, enHora: { reservas: 1, personas: 2 } });
assert.match(modo.innerHTML, /data-contexto-paso="13:30"/, 'de las 13:00 se avanza a las 13:30, aunque no haya reservas a esa hora');
assert.match(modo.innerHTML, /data-contexto-paso=""[^>]*disabled/, 'antes de la apertura no hay paso atrás');
barra.pintar({ modo: 'proyeccion', esHoy: false, fecha: '2026-10-13', hora: '22:00', ahoraHM: '09:00', horas: [], horario: { codigo: 'abierto', hora_apertura: '13:00:00', hora_cierre: '23:00:00' }, capacidad: { libre: 44, total: 44 } });
assert.match(modo.innerHTML, /data-contexto-paso="21:30"/, 'a las 22:00 se puede volver a las 21:30');
assert.match(modo.innerHTML, /data-contexto-paso="22:30"/, 'y avanzar a las 22:30, antes del cierre');
assert.ok(!/data-contexto-paso="23:00"/.test(modo.innerHTML), 'no se proyecta a la hora de cierre');
assert.ok(!/sin-mesa|sin mesa/.test(datos.innerHTML), 'no hay aviso «sin mesa»: toda reservación tiene mesas');
barra.pintar({ modo: 'ahora', esHoy: true, fecha: '2026-10-09', hora: '19:42', ahoraHM: '19:42', horas: [], horario: { codigo: 'sin_configurar' }, esAdmin: true });
assert.match(datos.innerHTML, /Horario no configurado/, 'sin horario, el aviso sustituye a los datos');
assert.match(datos.innerHTML, /href="\/admin\/configuracion\/horarios"/, 'el admin tiene el enlace para configurarlo');

// ── Ficha ───────────────────────────────────────────────────────────
const cuerpo = nodo();
const pie = nodo();
const raizFicha = nodo();
raizFicha.querySelector = (sel) => (sel === '[data-ficha-cuerpo]' ? cuerpo : sel === '[data-ficha-acciones]' ? pie : null);
const ficha = contexto.window.PosFicha.crear({ raiz: raizFicha, acciones: { cancelar() {}, cambiarMesas() {}, comentario() {}, llego() {} } });
const opcionesFicha = { mesaPorId, esBloque: () => false, maxComensales: 12, esAdmin: false, esHoy: true, temporal: { inicio_servicio_anticipado_minutos: 30 }, reservasDia: reservas };
ficha.abrir(Object.assign({}, reservas[0], { comentario_admin: 'Prefieren ventana', puede_iniciar_servicio: false }), opcionesFicha);
assert.match(cuerpo.innerHTML, /id="pos-ficha-nombre"[^>]*>Gómez</, 'el nombre es el título de la ficha');
assert.match(cuerpo.innerHTML, /Mesas 3 y 4/, 'las mesas se nombran');
assert.match(cuerpo.innerHTML, /data-ficha-accion="cambiar-mesas">Cambiar</, 'cambiar mesas va junto a las mesas');
// La hora en que se habilita «Llegó» la dice SÓLO la pista del pie, junto al
// botón: el cuerpo la repetía en una frase de estado y era la misma frase dos
// veces en la misma ficha.
assert.match(pie.innerHTML, /id="pos-ficha-pista">Se habilita a las 19:30</, 'dice cuándo se habilita «Llegó», junto al botón');
assert.ok(!/se habilita/i.test(cuerpo.innerHTML), 'y el cuerpo no lo repite');
assert.match(pie.innerHTML, /mmodal-btn--pending[^>]*disabled/, 'Llegó apagado con --pending y disabled');
assert.match(cuerpo.innerHTML, /Prefieren ventana/, 'el mesero LEE el comentario interno');
assert.ok(!/editar-comentario/.test(cuerpo.innerHTML), 'el mesero no puede editarlo');
assert.ok(!/--danger/.test(cuerpo.innerHTML + pie.innerHTML), 'la ficha no usa el rojo macizo');
ficha.abrir(Object.assign({}, reservas[4], { puede_iniciar_servicio: true, puede_marcar_no_show: true, tolerancia_hasta: '2026-10-09 18:45:00' }), opcionesFicha);
assert.match(pie.innerHTML, /data-ficha-accion="llego"/, 'con la tolerancia vencida aún se puede marcar que llegó');
assert.ok(!/no-llego/.test(pie.innerHTML), 'no hay «No llegó»: la ausencia se registra sola');
assert.match(cuerpo.innerHTML, /se registra sola como ausencia/, 'explica que la ausencia se registra sola');
ficha.abrir(Object.assign({}, reservas[1], { reubicada: true, reubicacion: { id: 7, mesas_antes: [9], mesas_despues: [3], causa_reservacion_id: 10 } }), Object.assign({}, opcionesFicha, { esAdmin: true }));
assert.match(cuerpo.innerHTML, /Reubicada de Mesa 9 a Mesa 3/, 'la reubicación se dice de… a…');
assert.match(cuerpo.innerHTML, /Por el grupo de 6 de las 20:00 en el exterior/, 'y por qué');
assert.match(cuerpo.innerHTML, /data-ficha-accion="editar-comentario">Agregar</, 'el admin puede agregar comentario');
assert.match(pie.innerHTML, /Primero asigna mesas/, 'sin mesas, Llegó explica que faltan');
// Grupo mayor al máximo: ya no se dice que sus mesas «se eligen a mano» (el
// servidor las propone); la celda de mesas lo nombra con la palabra del cajón.
ficha.abrir(Object.assign({}, reservas[0], { comensales: 14, mesa_ids: [8, 9, 10, 11], fecha: '2026-10-14' }), Object.assign({}, opcionesFicha, { esHoy: false }));
assert.ok(!/a mano/.test(cuerpo.innerHTML), 'la ficha no dice que las mesas de un grupo grande se eligen a mano');
assert.match(cuerpo.innerHTML, /class="pos-ficha__matiz">Grupo grande</, 'la celda de mesas dice «Grupo grande»');
assert.match(cuerpo.innerHTML, /class="pos-ficha__dia">Mié 14 oct</, 'otro día: la fecha va junto a la hora');
assert.match(pie.innerHTML, /Se habilita el miércoles 14 de octubre/, 'y la pista dice qué día se habilita «Llegó»');
ficha.abrir(Object.assign({}, reservas[0], { estado: 'en_curso', ticket_id: 456 }), Object.assign({}, opcionesFicha, { proyeccion: true }));
assert.match(pie.innerHTML, /Vuelve a «Ahora» para abrir el ticket/, 'en proyección no se abre el ticket');

// Toda la ficha y el cajón sin hex ni rgba literales (CLAUDE.md).
for (const archivo of ['pos-reserva-vista.js', 'pos-cajon.js', 'pos-contexto.js', 'pos-ficha.js', 'pos-proyeccion.js']) {
  const fuente = fs.readFileSync(path.join(root, 'src/js/operation', archivo), 'utf8');
  assert.ok(!/#[0-9a-fA-F]{3,8}\b|rgba\([0-9]/.test(fuente), archivo + ' no lleva colores literales');
  assert.ok(!/\balert\(|\bconfirm\(|\bprompt\(/.test(fuente), archivo + ' no usa diálogos nativos');
}

console.log('POS: vista de reservaciones, cajón por horario, barra de contexto y ficha OK');
