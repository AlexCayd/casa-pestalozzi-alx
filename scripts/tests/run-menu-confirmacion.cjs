const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

// Ejecuta el componente real: el campo requerido debe ser visible y operable.
const dom = new JSDOM('<!doctype html><body><button id="origen">Retirar</button></body>', {
    runScripts: 'outside-only', pretendToBeVisual: true,
});
const { window } = dom;
window.matchMedia = () => ({ matches: true });
window.eval(fs.readFileSync(path.resolve(__dirname, '../../src/js/components/confirmation-modal.js'), 'utf8'));
const controller = window.ConfirmationModal.get();
let confirmed = false;
controller.open({ title: 'Retirar oferta', requireText: 'Hogaza', onPrimary() { confirmed = true; } });
const input = window.document.querySelector('#confirmation-require-input');
const custom = input.closest('[data-confirmation-custom]');
const primary = window.document.querySelector('[data-confirmation-primary]');
assert.equal(custom.hidden, false, 'El campo requerido no queda en una ranura oculta');
assert.equal(custom.hasAttribute('aria-hidden'), false, 'El campo forma parte del árbol accesible');
assert.equal(primary.disabled, true, 'No se confirma sin escribir');
input.value = 'otro'; input.dispatchEvent(new window.Event('input'));
assert.equal(primary.disabled, true, 'Nombre distinto no confirma');
input.value = '  HOGAZA  '; input.dispatchEvent(new window.Event('input'));
assert.equal(primary.disabled, false, 'El nombre esperado habilita la acción');
primary.click();
assert.equal(confirmed, true, 'La acción se puede completar');
dom.window.close();
console.log('Menú: confirmación escrita visible, accesible y operable OK.');
