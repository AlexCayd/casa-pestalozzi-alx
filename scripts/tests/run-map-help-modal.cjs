const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const view = fs.readFileSync(path.join(root, 'views/operation/partials/map.php'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'src/js/operation/map-help.js'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'src/scss/operation/_map-shell.scss'), 'utf8');

function check(condition, message) {
  assert.ok(condition, message);
}

check((view.match(/data-map-help-open/g) || []).length === 1, 'el parcial tiene un solo botón reutilizable');
check((view.match(/<dialog\b/g) || []).length === 1, 'el parcial monta un único diálogo por mapa');
check(view.includes('aria-controls="'), 'el botón apunta a su diálogo');
check(view.includes('aria-haspopup="dialog"'), 'el botón anuncia el tipo de ventana');
check(view.includes('aria-labelledby="'), 'el diálogo asocia su título');
check(view.includes('autofocus'), 'el diálogo define foco inicial');
check(view.includes('title="Ayuda sobre los estados del mapa"'), 'tooltip y nombre accesible usan el copy acordado');
check(view.includes('Cómo interpretar el mapa de mesas'), 'el modal usa el título acordado');
check(view.includes('mesa-pin--mod-reservacion_advertencia'), 'la muestra de advertencia reutiliza clases reales');
check(view.includes('mesa-pin--mod-accion_pendiente'), 'la muestra de ausencia incluye su indicador');
check(view.includes('Los colores son informativos. La disponibilidad definitiva y las acciones permitidas se verifican al realizar la operación.'), 'el modal incluye la nota operativa');
check(view.includes('map-help-button--overlay') && view.includes('map-help-button--header'), 'el parcial soporta las dos ubicaciones');
check(styles.includes('.map-help-dialog:not([open])') && styles.includes('display: none'), 'el diálogo cerrado conserva la ocultación nativa');

const documentListeners = {};
let incompatibleModalOpen = false;

const dialogListeners = {};
const focusTarget = { focusCount: 0, focus() { this.focusCount += 1; } };
const map = { querySelector(selector) { return selector === '[data-map-help-dialog]' ? dialog : null; } };
const dialog = {
  open: false,
  showCount: 0,
  closeCount: 0,
  closeButton: null,
  addEventListener(type, listener) {
    (dialogListeners[type] ||= []).push(listener);
  },
  querySelector(selector) {
    return selector === '[autofocus], [data-map-help-close]' ? focusTarget : null;
  },
  showModal() {
    this.open = true;
    this.showCount += 1;
  },
  close() {
    if (!this.open) return;
    this.open = false;
    this.closeCount += 1;
    (dialogListeners.close || []).forEach((listener) => listener());
  },
  nativeEscape() {
    // Simula la secuencia del elemento <dialog>: Escape emite cancel y el
    // comportamiento nativo cierra el diálogo, que después emite close.
    (dialogListeners.cancel || []).forEach((listener) => listener({ cancelable: true }));
    this.close();
  }
};

const trigger = {
  focusCount: 0,
  isConnected: true,
  focus() { this.focusCount += 1; },
  closest(selector) {
    if (selector === '[data-map-help-open]') return this;
    if (selector === '[data-map-component]') return map;
    return null;
  }
};
const closeButton = {
  closest(selector) {
    if (selector === '[data-map-help-close]') return this;
    if (selector === '[data-map-help-dialog]') return dialog;
    return null;
  }
};

const document = {
  addEventListener(type, listener) {
    documentListeners[type] = listener;
  },
  querySelector(selector) {
    if (selector.startsWith('dialog[open]')) return incompatibleModalOpen ? {} : null;
    return null;
  }
};

vm.runInNewContext(controller, { window: {}, document, WeakMap, WeakSet, Boolean });
check(typeof documentListeners.click === 'function', 'el controlador registra delegación de clic');
check(!documentListeners.keydown, 'no duplica el controlador global de Escape');

documentListeners.click({ target: trigger });
check(dialog.open && dialog.showCount === 1, 'el botón abre el diálogo nativo');
check(focusTarget.focusCount === 1, 'al abrir, el foco entra en el control inicial');
check(trigger.focusCount === 0, 'el foco queda en el diálogo mientras está abierto');
documentListeners.click({ target: {} });
check(dialog.open && dialog.showCount === 1, 'un objetivo sin closest no interrumpe el controlador');

documentListeners.click({ target: closeButton });
check(!dialog.open && dialog.closeCount === 1, 'el botón de cierre cierra el diálogo');
check(trigger.focusCount === 1, 'al cerrar, el foco vuelve al botón de ayuda');

documentListeners.click({ target: trigger });
dialog.nativeEscape();
check(!dialog.open && trigger.focusCount === 2, 'Escape cierra el diálogo nativo y devuelve el foco');

incompatibleModalOpen = true;
documentListeners.click({ target: trigger });
check(!dialog.open && dialog.showCount === 2, 'la ayuda no abre mientras otro modal está activo');

console.log('Mapa: botón y modal de ayuda OK');
