const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const view = fs.readFileSync(path.join(root, 'views/operation/partials/map.php'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'src/js/operation/map-help.js'), 'utf8');
const styles = fs.readFileSync(path.join(root, 'src/scss/operation/_map-shell.scss'), 'utf8');
const reservations = fs.readFileSync(path.join(root, 'views/operation/reservations/index.php'), 'utf8');
const pos = fs.readFileSync(path.join(root, 'views/punto-de-venta/partials/pos-workspace.php'), 'utf8');

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
check((view.match(/<li class="map-help-dialog__state">/g) || []).length === 7, 'el modal conserva las siete muestras reales');
check(view.includes('mesa-pin--mod-reservacion_advertencia'), 'la muestra de advertencia reutiliza clases reales');
check(view.includes('mesa-pin--mod-accion_pendiente'), 'la muestra de ausencia incluye su indicador');
check(view.includes('Los colores orientan. Las acciones disponibles se verifican al realizar la operación.'), 'el modal incluye la nota operativa breve');
check(view.includes('Reserva cercana') && view.includes('Ausencia pendiente') && view.includes('No utilizable'), 'el modal usa las etiquetas breves acordadas');
check(view.includes('mapHelpContext === \'reservations\'') && view.includes('Ticket abierto o intervalo bloqueado.') && view.includes('Ticket abierto.'), 'verde y rojo cambian según el contexto invocador');
check(view.includes('Consulta el estado actual de las mesas.') && view.includes('Consulta la disponibilidad para la fecha y hora seleccionadas.'), 'el modal define un subtítulo para cada contexto');
check(!view.includes('map-help-dialog__contexts') && !view.includes('Muestra principalmente la operación actual'), 'el modal elimina los bloques de explicación extensa');
check(reservations.includes("'helpContext' => 'reservations'") && reservations.includes("'legendPosition' => 'none'"), 'Reservaciones contextualiza la ayuda y oculta su leyenda');
check(reservations.includes("'helpPosition' => 'overlay'") && pos.includes("'helpPosition' => 'overlay'"), 'POS y Reservaciones usan el mismo patrón de botón sobre el mapa');
check(pos.includes("'helpContext' => 'pos'") && pos.includes("'legendPosition' => 'none'"), 'POS mantiene ayuda contextual y no renderiza leyenda');
check(styles.includes('width: min(860px, calc(100vw - 48px))') && styles.includes('max-height: calc(100dvh - 48px)'), 'el modal usa el ancho y altura máxima acordados');
check(styles.includes('font-size: var(--operational-text-base)'), 'el texto descriptivo conserva cuerpo legible');
check(controller.includes('function positionHelpButton') && controller.includes('ResizeObserver'), 'el botón se recoloca si los pines o el tamaño del mapa cambian');
check(styles.includes('width: 44px;') && styles.includes('height: 44px;'), 'los botones de ayuda conservan el área táctil mínima');
check(styles.includes('grid-template-columns: minmax(0, 1fr);'), 'la ayuda pasa a una columna en móvil');
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
