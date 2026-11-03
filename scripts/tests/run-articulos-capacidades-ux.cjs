const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');

const html = `
<form method="POST" id="m2-capacidades-form">
  <input type="hidden" name="accion" value="configurar">
  <input type="hidden" name="revision" value="3">
</form>
<header><label><input type="checkbox" name="activo" value="1" form="m2-capacidades-form" checked>Configuración activa</label>
  <button type="submit" form="m2-capacidades-form">Guardar capacidades</button></header>
<details open><fieldset>
  <label><input type="checkbox" name="componente" value="1" form="m2-capacidades-form" checked>Composición</label>
  <label><input type="checkbox" name="elaborable" value="1" form="m2-capacidades-form" data-m2-capacidad="elaborable" checked>Elaboración</label>
  <label><input type="checkbox" name="comprable" value="1" form="m2-capacidades-form" checked>Compra</label>
  <label><input type="checkbox" name="almacenable" value="1" form="m2-capacidades-form" data-m2-capacidad="almacenable" checked>Existencias</label>
  <label><input type="checkbox" name="produccion_previa" value="1" form="m2-capacidades-form" data-m2-capacidad="produccion_previa" checked>Producción previa</label>
  <label><input type="checkbox" name="costo_conocido" value="1" form="m2-capacidades-form" checked>Costo</label>
  <p role="alert" data-m2-capacidad-error hidden></p>
</fieldset></details>
<form data-m2-pendiente><div data-m2-builder><div data-m2-rows></div><template data-m2-template></template><p data-m2-status></p></div></form>`;
const dom = new JSDOM(html, {runScripts:'outside-only'});
const {window} = dom;
let confirmationCalls = 0;
window.ConfirmationModal = {get(){ return {open(){ confirmationCalls++; }}; }};
window.eval(fs.readFileSync('src/js/admin/recetas/articulos.js', 'utf8'));

const doc = window.document;
const form = doc.getElementById('m2-capacidades-form');
const values = new window.FormData(form);
for (const name of ['accion','revision','activo','componente','elaborable','comprable','almacenable','produccion_previa','costo_conocido']) {
    assert.equal(values.has(name), true, `FormData conserva el campo asociado externamente: ${name}`);
}
assert.equal(form.querySelectorAll('[name]').length, 2, 'los siete controles asociados se mantienen fuera del formulario sin duplicarlo');

const elaborable = doc.querySelector('[data-m2-capacidad="elaborable"]');
const almacenable = doc.querySelector('[data-m2-capacidad="almacenable"]');
const previa = doc.querySelector('[data-m2-capacidad="produccion_previa"]');
const alert = doc.querySelector('[data-m2-capacidad-error]');
elaborable.checked = false;
almacenable.checked = false;

let submit = new window.Event('submit', {bubbles:true, cancelable:true});
form.dispatchEvent(submit);
assert.equal(submit.defaultPrevented, true, 'bloquea producción previa sin sus dependencias');
assert.equal(confirmationCalls, 0, 'la combinación inválida no abre antes el aviso de borrador');
assert.equal(elaborable.checked, false, 'la validación no activa ni desactiva capacidades por su cuenta');
assert.equal(almacenable.checked, false, 'la validación conserva los valores elegidos');
assert.equal(doc.activeElement, elaborable, 'focaliza la primera capacidad faltante');
assert.equal(alert.hidden, false, 'explica la dependencia real en la misma ficha');

elaborable.checked = true;
submit = new window.Event('submit', {bubbles:true, cancelable:true});
form.dispatchEvent(submit);
assert.equal(submit.defaultPrevented, true, 'bloquea cuando falta únicamente almacenamiento');
assert.equal(doc.activeElement, almacenable, 'focaliza almacenamiento cuando falta esa dependencia');
assert.equal(alert.hidden, false);

almacenable.checked = true;
submit = new window.Event('submit', {bubbles:true, cancelable:true});
form.dispatchEvent(submit);
assert.equal(submit.defaultPrevented, true, 'con borrador pendiente conserva la confirmación explícita');
assert.equal(confirmationCalls, 1, 'una combinación válida abre el aviso de borrador una sola vez');
assert.equal(alert.hidden, true, 'retira el error de dependencia al corregir los valores');
assert.equal(previa.checked, true, 'no altera producción previa durante las comprobaciones');

console.log('Capacidades UX: asociación del formulario, dependencias y conservación de borradores OK.');
