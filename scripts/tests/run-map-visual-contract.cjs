const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');

class FakeClassList {
  constructor(owner) { this.owner = owner; this.values = new Set(); }
  add(value) { this.values.add(value); }
  remove(value) { this.values.delete(value); }
  contains(value) { return this.values.has(value); }
  toggle(value, force) {
    const next = force === undefined ? !this.contains(value) : Boolean(force);
    if (next) this.add(value); else this.remove(value);
    return next;
  }
  reset(value) { this.values = new Set(String(value || '').split(/\s+/).filter(Boolean)); }
  toString() { return Array.from(this.values).join(' '); }
}

class FakeElement {
  constructor(tagName) {
    this.tagName = tagName;
    this.attributes = {};
    this.children = [];
    this.parentNode = null;
    this.style = {};
    this.classList = new FakeClassList(this);
    this.listeners = {};
    this.hidden = false;
    this.disabled = false;
    this._text = '';
    this._html = '';
  }
  set className(value) { this.classList.reset(value); }
  get className() { return this.classList.toString(); }
  set innerHTML(value) {
    this._html = String(value || '');
    this.children.forEach((child) => { child.parentNode = null; });
    this.children = [];
    this._text = '';
  }
  get innerHTML() { return this._html; }
  set textContent(value) {
    this._text = String(value == null ? '' : value);
    this.children.forEach((child) => { child.parentNode = null; });
    this.children = [];
    this._html = '';
  }
  get textContent() { return this._text + this.children.map((child) => child.textContent).join(''); }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  getAttribute(name) { return Object.prototype.hasOwnProperty.call(this.attributes, name) ? this.attributes[name] : null; }
  removeAttribute(name) { delete this.attributes[name]; }
  appendChild(child) {
    if (child.isFragment) {
      child.children.slice().forEach((nested) => this.appendChild(nested));
      child.children = [];
      return child;
    }
    if (child.parentNode) child.parentNode.removeChild(child);
    child.parentNode = this;
    this.children.push(child);
    this._html = '';
    return child;
  }
  removeChild(child) {
    const index = this.children.indexOf(child);
    if (index >= 0) this.children.splice(index, 1);
    child.parentNode = null;
    return child;
  }
  addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
  removeEventListener(type, listener) { this.listeners[type] = (this.listeners[type] || []).filter((item) => item !== listener); }
  dispatchEvent(event) {
    if (!event.target) event.target = this;
    (this.listeners[event.type] || []).forEach((listener) => listener(event));
    this.dispatched = (this.dispatched || []).concat(event);
    return true;
  }
  contains(element) { return this === element || this.children.some((child) => child.contains(element)); }
  closest(selector) {
    if (selector === '[data-mapa-mesa]' && this.getAttribute('data-mapa-mesa') !== null) return this;
    if (selector === '[data-map-component]') return this.mapCard || null;
    return this.parentNode ? this.parentNode.closest(selector) : null;
  }
  querySelector(selector) {
    const match = (node) => {
      const attr = selector.match(/^\[([^=\]]+)(?:="([^"]+)")?\]$/);
      if (attr) return node.getAttribute(attr[1]) !== null && (!attr[2] || node.getAttribute(attr[1]) === attr[2]);
      if (selector.charAt(0) === '.') return node.classList.contains(selector.slice(1));
      return false;
    };
    for (const child of this.children) {
      if (match(child)) return child;
      const nested = child.querySelector(selector);
      if (nested) return nested;
    }
    return null;
  }
}

const fakeDocument = {
  createElement(tagName) { return new FakeElement(tagName); },
  createDocumentFragment() { const fragment = new FakeElement('#fragment'); fragment.isFragment = true; return fragment; },
  querySelector() { return null; }
};
class FakeCustomEvent {
  constructor(type, options) { this.type = type; this.detail = options && options.detail; this.bubbles = Boolean(options && options.bubbles); }
}
const context = { window: {}, document: fakeDocument, CustomEvent: FakeCustomEvent };

for (const file of [
  'src/js/operation/map-contract.js',
  'src/js/operation/table-state-adapter.js',
  'src/js/operation/map-visual.js',
]) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  vm.runInNewContext(source, context, { filename: file });
}

const adapt = context.window.MesaEstadoAdapter.paraMapaVisual;
const normalize = adapt;
const mapContract = context.window.MapaContrato;
const mapStyles = fs.readFileSync(path.join(root, 'src/scss/operation/_map-shell.scss'), 'utf8');

function styleRule(pattern, label) {
  const match = mapStyles.match(pattern);
  assert.ok(match, `${label}: existe la regla visual`);
  return match[1];
}

function styleProperty(rule, name) {
  const escapedName = name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = rule.match(new RegExp(`(?:^|[;\\n])\\s*${escapedName}\\s*:\\s*([^;]+)`, 'm'));
  return match ? match[1].trim() : null;
}

function assertVisualProperties(rule, expected, label) {
  Object.entries(expected).forEach(([property, value]) => {
    assert.equal(styleProperty(rule, property), value, `${label}: ${property} conserva su token`);
  });
}

const pinBaseRule = styleRule(/\.mesas-map \.mesa-pin\s*\{([^{}]+)\}/, 'pin real y muestra base');
const availableRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--libre\s*\{([^{}]+)\}/, 'estado disponible');
const occupiedRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--ocupada\s*\{([^{}]+)\}/, 'estado ocupado');
const reservationRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--reservacion-proxima\s*\{([^{}]+)\}/, 'reservación próxima');
const unavailableRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--no-utilizable,[\s\S]*?\{([^{}]+)\}/, 'estado no utilizable');
const availableWarningRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--libre\.mesa-pin--mod-reservacion_advertencia\s*\{([^{}]+)\}/, 'disponible con reserva cercana');
const occupiedWarningRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--ocupada\.mesa-pin--mod-reservacion_advertencia\s*\{([^{}]+)\}/, 'ocupada con reserva cercana');
const posAbsenceRule = styleRule(/\.pos-map \.mesas-map \.mesa-pin--reservacion-proxima\.mesa-pin--mod-ausencia_pendiente\s*\{([^{}]+)\}/, 'ausencia pendiente en POS');
const absenceOverlayRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--mod-ausencia_pendiente::after,[\s\S]*?\{([^{}]+)\}/, 'indicador visual de ausencia');
const helpSampleRule = styleRule(/\.map-help-dialog__sample \.mesa-pin\s*\{([^{}]+)\}/, 'geometría de las muestras del modal');

assert.ok(!mapStyles.includes('.mesas-map button.reservation-operation-pin--selected:hover:not(:disabled)'), 'hover no sustituye el borde base de una mesa seleccionada');
assert.match(styleProperty(pinBaseRule, 'border'), /^2px solid /, 'pin real y muestra comparten ancho y estilo de borde');
assertVisualProperties(availableRule, {
  'border-color': 'var(--map-table-available-border)',
  background: 'var(--map-table-available-bg)',
  color: 'var(--map-table-available-text)'
}, 'disponible');
assertVisualProperties(occupiedRule, {
  'border-color': 'var(--map-table-occupied-border)',
  background: 'var(--map-table-occupied-bg)',
  color: 'var(--map-table-occupied-text)'
}, 'ocupada');
assert.match(styleProperty(occupiedRule, 'box-shadow'), /var\(--map-table-occupied-border\)/, 'ocupada conserva su ring rojo base');
assertVisualProperties(reservationRule, {
  'border-color': 'var(--map-table-reservation-border)',
  background: 'var(--map-table-reservation-bg)',
  color: 'var(--map-table-reservation-text)'
}, 'reservación próxima');
assertVisualProperties(unavailableRule, {
  'border-color': 'var(--map-table-unavailable-border)',
  'border-style': 'solid',
  background: 'var(--map-table-unavailable-bg)',
  color: 'var(--map-table-unavailable-text)'
}, 'no utilizable');

assertVisualProperties(availableWarningRule, {
  'border-color': 'var(--map-reservation-warning-border)',
  'border-style': 'dashed',
  'border-width': '2px',
  background: 'var(--map-table-available-bg)',
  color: 'var(--map-table-available-text)'
}, 'libre con advertencia');
assert.equal(styleProperty(availableWarningRule, 'box-shadow'), null, 'libre con advertencia conserva la sombra base');
assert.match(styleProperty(availableRule, 'box-shadow'), /inset 0 1px 0/, 'libre conserva su sombra base');
assertVisualProperties(occupiedWarningRule, {
  'border-color': 'var(--map-reservation-warning-border)',
  'border-style': 'dashed',
  'border-width': '2px',
  background: 'var(--map-table-occupied-bg)',
  color: 'var(--map-table-occupied-text)'
}, 'ocupada con advertencia');
assert.equal(styleProperty(occupiedWarningRule, 'box-shadow'), null, 'ocupada con advertencia conserva su ring rojo base');

assertVisualProperties(posAbsenceRule, {
  'border-color': 'var(--map-table-reservation-overdue-border)',
  background: 'var(--map-table-reservation-overdue-bg)',
  color: 'var(--map-table-reservation-overdue-text)'
}, 'ausencia pendiente en POS');
assertVisualProperties(absenceOverlayRule, { border: '2px solid var(--map-table-reservation-overdue-border)' }, 'ausencia pendiente en Reservaciones');
assert.equal(styleProperty(helpSampleRule, 'background'), null, 'el modal no redefine fondos semánticos');
assert.equal(styleProperty(helpSampleRule, 'border-color'), null, 'el modal no redefine bordes semánticos');
assert.equal(styleProperty(helpSampleRule, 'border-style'), null, 'el modal no redefine el estilo del borde');
assert.equal(styleProperty(helpSampleRule, 'box-shadow'), null, 'el modal conserva las sombras y rings reales');
assert.equal(styleProperty(helpSampleRule, 'border-radius'), null, 'el modal conserva la forma del pin real');
assert.ok(!mapStyles.includes('.map-help-dialog__sample .mesa-pin--reservacion-proxima.mesa-pin--mod-ausencia_pendiente'), 'Reservaciones no tiene una regla modal que fuerce azul oscuro');
const helpTokens = Array.from(mapStyles.matchAll(/--map-help-[\w-]+/g), (match) => match[0]);
assert.ok(helpTokens.every((token) => /^--map-help-offset-(top|right)$/.test(token)), 'la ayuda no define tokens propios de color para los pines');

for (const raw of [
  { id: 1, reservable: true },
  { id: 2, reservable: true, estadoVisual: 'estado-desconocido' }
]) {
  const mesa = normalize(raw);
  assert.equal(mesa.estadoVisual, 'no-utilizable', 'un contrato ausente o desconocido usa el estado neutral');
  assert.equal(mesa.estadoNoVerificado, true, 'el fallback queda marcado como error de presentación');
  assert.equal(mesa.interactivo, false, 'un estado no validado no permite interacción visual');
  assert.equal(mesa.seleccionada, false, 'un estado no validado no permite selección visual');
}

const knownUnavailable = adapt({
  id: 20,
  reservable: false,
  estado_visual_pos: 'no-utilizable',
  modificadores_visual_pos: []
});
assert.equal(knownUnavailable.estadoNoVerificado, false, 'no-utilizable legítimo no es un error de contrato');
assert.equal(knownUnavailable.interactivo, false, 'no-utilizable legítimo conserva la restricción conocida');

const hostileInvalid = adapt({
  id: 21,
  reservable: true,
  estado_visual_pos: 'inexistente',
  modificadores_visual_pos: [],
  interactivo: true,
  seleccion_actual: true
}, { interactivo: true, seleccionValida: true, seleccionActual: true });
assert.equal(hostileInvalid.estadoNoVerificado, true, 'el estado desconocido conserva el indicador con opciones de consumidor');
assert.equal(hostileInvalid.interactivo, false, 'el consumidor no vuelve a habilitar el contrato inválido');
assert.equal(hostileInvalid.seleccionada, false, 'el consumidor no vuelve a seleccionar el contrato inválido');

const invalidModifiers = adapt({
  id: 22,
  reservable: true,
  estado_visual_pos: 'libre',
  modificadores_visual_pos: null
});
assert.equal(invalidModifiers.estadoNoVerificado, true, 'un contrato de modificadores incompleto se marca como no verificado');

const occupiedSelection = adapt({
  id: 3,
  reservable: true,
  estado_visual_pos: 'ocupada',
  seleccion_actual: true,
  disponible_para_asignacion: false
}, { seleccionValida: true });
assert.equal(occupiedSelection.estadoVisual, 'ocupada', 'la selección conserva el hecho base ocupado');
assert.equal(occupiedSelection.seleccionada, true, 'la selección se representa como capa secundaria');

const unusableSelection = adapt({
  id: 4,
  reservable: false,
  estado_base: 'no_reservable',
  estado_visual_pos: 'no-utilizable',
  seleccion_actual: true
});
assert.equal(unusableSelection.estadoVisual, 'no-utilizable', 'no utilizable conserva la precedencia');
assert.equal(unusableSelection.seleccionada, false, 'no se selecciona una mesa no utilizable');

const ticketWarningSelection = adapt({
  id: 6,
  reservable: true,
  estado_visual_pos: 'ocupada',
  seleccion_actual: true,
  modificadores: ['ticket_abierto', 'reservacion_advertencia']
}, { seleccionValida: true });
assert.equal(ticketWarningSelection.estadoVisual, 'ocupada', 'ticket y advertencia conservan ocupación');
assert.equal(ticketWarningSelection.seleccionada, true, 'ticket puede conservar selección como anillo secundario');
assert.ok(ticketWarningSelection.modificadores.includes('reservacion_advertencia'));

const upcomingSelection = adapt({ id: 7, reservable: true, estado_visual_pos: 'reservacion-proxima', seleccion_actual: true }, { seleccionValida: true });
assert.equal(upcomingSelection.estadoVisual, 'reservacion-proxima');
assert.equal(upcomingSelection.seleccionada, true, 'seleccionar una mesa próxima no la vuelve disponible');
const upcomingWarningSelectionRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--seleccionada\.mesa-pin--mod-reservacion_advertencia\s*\{([^{}]+)\}/, 'próxima con advertencia y selección');
assert.equal(styleProperty(upcomingWarningSelectionRule, 'background'), null, 'la advertencia no reemplaza el fondo azul de una reservación próxima');
assert.equal(styleProperty(upcomingWarningSelectionRule, 'color'), null, 'la advertencia no reemplaza el texto semántico de una reservación próxima');
assert.equal(styleProperty(upcomingWarningSelectionRule, 'box-shadow'), null, 'la regla de advertencia no borra el ring amarillo de selección');
assert.match(styleProperty(upcomingWarningSelectionRule, 'border-color'), /--map-reservation-warning-border/, 'la advertencia conserva su borde de alerta');

const warningSelection = adapt({
  id: 8,
  reservable: true,
  estado_visual_pos: 'libre',
  seleccion_actual: true,
  modificadores: ['reservacion_advertencia']
}, { seleccionValida: true });
assert.equal(warningSelection.estadoVisual, 'libre');
assert.equal(warningSelection.seleccionada, true);
const warningSelectionRule = mapStyles.match(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--libre\.mesa-pin--seleccionada\.mesa-pin--mod-reservacion_advertencia\s*\{([^}]+)\}/);
assert.ok(warningSelectionRule, 'la selección con advertencia tiene una regla de composición explícita');
assert.match(warningSelectionRule[1], /border-color: var\(--map-reservation-warning-border\)/);
assert.match(warningSelectionRule[1], /border-style: dashed/);
assert.match(warningSelectionRule[1], /background: var\(--map-table-available-bg\)/);
assert.match(warningSelectionRule[1], /0 0 0 3px[\s\S]*var\(--map-table-selected-bg\)/);

const selectedRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--seleccionada\s*\{([^{}]+)\}/, 'ring de selección compartido');
const freeSelectionRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--libre\.mesa-pin--seleccionada\s*\{([^{}]+)\}/, 'libre seleccionada');
const occupiedSelectionRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--ocupada\.mesa-pin--seleccionada\s*\{([^{}]+)\}/, 'ocupada seleccionada');
const reservationSelectionRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--reservacion-proxima\.mesa-pin--seleccionada\s*\{([^{}]+)\}/, 'reservación próxima seleccionada');
const upcomingWarningSelectedRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--reservacion-proxima\.mesa-pin--seleccionada\.mesa-pin--mod-reservacion_advertencia\s*\{([^{}]+)\}/, 'reservación próxima con advertencia y selección');
const occupiedWarningSelectionRule = styleRule(/:is\(\.mesas-map, \.map-help-dialog__sample\) \.mesa-pin--ocupada\.mesa-pin--seleccionada\.mesa-pin--mod-reservacion_advertencia\s*\{([^{}]+)\}/, 'ocupada con advertencia y selección');
assert.equal(styleProperty(selectedRule, 'background'), null, 'la selección compartida no redefine el fondo base');
assert.equal(styleProperty(selectedRule, 'border-color'), null, 'la selección compartida no redefine el borde base');
assert.match(styleProperty(selectedRule, 'box-shadow'), /var\(--map-table-selected-bg\)/, 'la selección compartida conserva el ring cuando la combinación no tiene regla específica');
assert.match(styleProperty(occupiedSelectionRule, 'box-shadow'), /0 0 0 3px[\s\S]*var\(--map-table-selected-bg\)/, 'la muestra del modal usa el ring amarillo de la mesa real');

assertVisualProperties(freeSelectionRule, {
  'border-color': 'var(--map-table-available-border)',
  background: 'var(--map-table-available-bg)',
  color: 'var(--map-table-available-text)'
}, 'libre seleccionada');
assert.match(styleProperty(freeSelectionRule, 'box-shadow'), /0 0 0 3px[\s\S]*var\(--map-table-selected-bg\)/, 'libre seleccionada añade ring amarillo');
assertVisualProperties(occupiedSelectionRule, {
  'border-color': 'var(--map-table-occupied-border)',
  background: 'var(--map-table-occupied-bg)',
  color: 'var(--map-table-occupied-text)'
}, 'ocupada seleccionada');
assert.match(styleProperty(occupiedSelectionRule, 'box-shadow'), /0 0 0 3px[\s\S]*var\(--map-table-selected-bg\)/, 'ocupada seleccionada añade ring amarillo');
assert.match(styleProperty(occupiedSelectionRule, 'box-shadow'), /0 0 0 5px[\s\S]*var\(--map-table-occupied-border\)/, 'ocupada seleccionada conserva ring rojo exterior');
assertVisualProperties(reservationSelectionRule, {
  'border-color': 'var(--map-table-reservation-border)',
  background: 'var(--map-table-reservation-bg)',
  color: 'var(--map-table-reservation-text)'
}, 'reservación próxima seleccionada');
assert.match(styleProperty(reservationSelectionRule, 'box-shadow'), /0 0 0 3px[\s\S]*var\(--map-table-selected-bg\)/, 'reservación próxima seleccionada añade ring amarillo');
assertVisualProperties(upcomingWarningSelectedRule, {
  'border-color': 'var(--map-reservation-warning-border)',
  'border-style': 'dashed',
  'border-width': '2px'
}, 'próxima con advertencia y selección conserva la alerta');
assertVisualProperties(occupiedWarningSelectionRule, {
  'border-color': 'var(--map-reservation-warning-border)',
  'border-style': 'dashed',
  background: 'var(--map-table-occupied-bg)',
  color: 'var(--map-table-occupied-text)'
}, 'ocupada con advertencia y selección');
assert.match(styleProperty(occupiedWarningSelectionRule, 'box-shadow'), /0 0 0 3px[\s\S]*var\(--map-table-selected-bg\)/, 'ocupada con advertencia añade ring amarillo');
assert.match(styleProperty(occupiedWarningSelectionRule, 'box-shadow'), /0 0 0 5px[\s\S]*var\(--map-reservation-warning-border\)/, 'ocupada con advertencia conserva la alerta azul exterior');

const absenceSelection = adapt({
  id: 9,
  reservable: true,
  estado_visual_pos: 'reservacion-proxima',
  seleccion_actual: true,
  modificadores: ['ausencia_pendiente', 'accion_pendiente']
}, { seleccionValida: true });
assert.equal(absenceSelection.estadoVisual, 'reservacion-proxima');
assert.equal(absenceSelection.seleccionada, true, 'selección conserva la señal de ausencia pendiente');
assert.ok(absenceSelection.modificadores.includes('ausencia_pendiente'));

const assignmentConflict = adapt({
  id: 10,
  reservable: true,
  estado_visual_pos: 'ocupada',
  disponible_para_asignacion: false,
  modificadores: ['asignada_actualmente', 'restriccion_intervalo']
});
assert.equal(assignmentConflict.estadoVisual, 'ocupada', 'la asignación actual no oculta un conflicto');
assert.ok(assignmentConflict.modificadores.includes('asignada_actualmente'));

const multipleSelection = [
  adapt({ id: 11, reservable: true, estado_visual_pos: 'libre', seleccion_actual: true }, { seleccionValida: true }),
  adapt({ id: 12, reservable: true, estado_visual_pos: 'ocupada', seleccion_actual: true }, { seleccionValida: true })
];
assert.deepEqual(multipleSelection.map((mesa) => mesa.id), [11, 12]);
assert.deepEqual(multipleSelection.map((mesa) => mesa.seleccionada), [true, true]);

const invalidLegacySelection = normalize({
  id: 5,
  reservable: true,
  estadoVisual: 'seleccionada',
  estadoVisualAnterior: 'estado-ilegible'
});
assert.equal(invalidLegacySelection.estadoVisual, 'no-utilizable');
assert.equal(invalidLegacySelection.estadoNoVerificado, true, 'la selección heredada sin un estado previo válido es un error');
assert.equal(invalidLegacySelection.seleccionada, false, 'el estado heredado sin estado base válido no puede seleccionarse');

const inheritedUnavailable = normalize({
  id: 24,
  reservable: false,
  estadoVisual: 'seleccionada',
  estadoVisualAnterior: 'no-utilizable'
});
assert.equal(inheritedUnavailable.estadoNoVerificado, false, 'un estado previo no-utilizable sí es un estado base válido');
assert.equal(inheritedUnavailable.estadoVisual, 'no-utilizable');

const validLegacySelection = normalize({
  id: 23,
  reservable: true,
  estadoVisual: 'seleccionada',
  estadoVisualAnterior: 'libre',
  interactivo: true
}, { seleccionValida: true });
assert.equal(validLegacySelection.estadoVisual, 'libre', 'la selección heredada válida conserva el estado base');
assert.equal(validLegacySelection.estadoNoVerificado, false, 'la selección heredada con estado previo no es error');
assert.equal(validLegacySelection.seleccionada, true);

assert.equal(mapContract.validarEstado('libre').valido, true, 'libre forma parte del contrato visual');
assert.equal(mapContract.validarEstado('ocupada').valido, true, 'ocupada forma parte del contrato visual');
assert.equal(mapContract.validarEstado('reservacion-proxima').valido, true, 'reservación próxima forma parte del contrato visual');
assert.equal(mapContract.validarEstado('no-utilizable').valido, true, 'no utilizable forma parte del contrato visual');
assert.equal(mapContract.validarEstado('seleccionada').valido, false, 'seleccionada no es un estado base');
assert.equal(mapContract.identificarSeleccionHeredada('seleccionada', 'ocupada'), true, 'seleccionada se reconoce sólo con estado previo válido');
assert.equal(mapContract.identificarSeleccionHeredada('seleccionada', 'estado-ajeno'), false, 'un estado previo inválido no habilita selección heredada');
assert.equal(mapContract.validarModificadores(['ticket_abierto', 'ausencia_pendiente']), true);
assert.equal(mapContract.validarModificadores(null), false, 'un contrato de modificadores incompleto se rechaza');
assert.deepEqual(Object.keys(mapContract.fallbackSeguro()).sort(), [
  'estadoNoVerificado', 'estadoVisual', 'interactivo', 'modificadores', 'seleccionValida', 'seleccionada'
].sort(), 'el fallback neutral no concede selección ni interacción');
assert.equal(context.window.MapaVisual.validarRespuestaMapa, undefined, 'el renderer no valida respuestas HTTP');

const card = new FakeElement('section');
const canvas = new FakeElement('div');
const queryStatus = new FakeElement('div');
const validationStatus = new FakeElement('div');
const structuredList = null;
card.mapCard = card;
card.querySelector = function (selector) {
  if (selector === '[data-map-structured-list]') return structuredList;
  if (selector === '[data-map-query-status]') return queryStatus;
  if (selector === '[data-map-validation-status]') return validationStatus;
  return null;
};
canvas.mapCard = card;
canvas.closest = function (selector) { return selector === '[data-map-component]' ? card : null; };
const renderer = context.window.MapaVisual.crear({ canvas, contexto: 'operacion-reservaciones', seleccionMultiple: true });
renderer.render({ mesas: [
  adapt({ id: 101, nombre: 'Mesa válida', estadoVisual: 'libre', reservable: true, interactivo: true, seleccionValida: true }),
  adapt({ id: 102, nombre: 'Mesa desconocida', estadoVisual: 'estado-ajeno', reservable: true, interactivo: true, seleccionada: true }),
  adapt({ id: 103, nombre: 'Caja', estadoVisual: 'libre', reservable: false, interactivo: true, independienteDeConsulta: true })
] });
const validPin = canvas.querySelector('[data-mapa-mesa="101"]');
const invalidPin = canvas.querySelector('[data-mapa-mesa="102"]');
const independentPin = canvas.querySelector('[data-mapa-mesa="103"]');
assert.equal(validPin.disabled, false, 'una mesa válida sigue operable junto a una inválida');
assert.equal(invalidPin.disabled, true, 'el estado inválido se deshabilita en el renderer compartido');
assert.ok(invalidPin.classList.contains('mesa-pin--estado-no-verificado'), 'el pin inválido recibe su clase diferenciada');
assert.ok(invalidPin.querySelector('.mesa-pin__verification-warning'), 'el pin inválido muestra el icono de advertencia');
assert.match(invalidPin.getAttribute('aria-label'), /Estado no verificado[\s\S]*no se puede operar/);
assert.match(validationStatus.textContent, /Mesa desconocida/);
assert.equal(validationStatus.hidden, false, 'el error de una mesa también aparece en un aviso del mapa');
canvas.dispatchEvent({ type: 'click', target: invalidPin });
assert.equal((canvas.dispatched || []).filter((event) => event.type === 'mapa:mesa-click').length, 0, 'un pin inválido no despacha operaciones');
canvas.dispatchEvent({ type: 'click', target: validPin });
assert.equal((canvas.dispatched || []).filter((event) => event.type === 'mapa:mesa-click').length, 1, 'la mesa válida mantiene sus operaciones');

renderer.actualizarEstado(102, {
  estadoVisual: 'libre',
  estadoNoVerificado: false,
  interactivo: true,
  seleccionValida: true,
  seleccionada: false,
  modificadores: []
});
assert.equal(invalidPin.disabled, false, 'el pin recupera interacción al recibir información válida');
assert.equal(invalidPin.classList.contains('mesa-pin--estado-no-verificado'), false, 'la recuperación limpia la clase de error');
assert.equal(invalidPin.getAttribute('data-estado-no-verificado'), null, 'la recuperación limpia el atributo de error');
assert.equal(invalidPin.querySelector('.mesa-pin__verification-warning'), null, 'la recuperación retira el icono de error');
assert.equal(validationStatus.hidden, true, 'el aviso se limpia cuando todas las proyecciones son válidas');

renderer.setConsultaEstado('Consulta desactualizada; actualiza el mapa.', true);
assert.equal(validPin.disabled, true, 'un fallo general bloquea operaciones dependientes del mapa');
assert.equal(independentPin.disabled, false, 'un elemento operativo independiente conserva su acción propia');
assert.equal(card.getAttribute('data-map-stale'), '1', 'el mapa identifica que la consulta está desactualizada');
renderer.setConsultaEstado('', false);
assert.equal(validPin.disabled, false, 'una consulta recuperada vuelve a permitir operaciones válidas');

console.log('Mapa: contratos, estado no verificado, recuperación y contexto temporal OK');
