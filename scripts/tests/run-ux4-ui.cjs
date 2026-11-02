const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');
const source = fs.readFileSync('src/js/admin/recetas/articulos.js', 'utf8');
const dom = new JSDOM(`<details id="versiones"><summary>Versiones publicadas</summary></details>
<form id="capacidades"><input name="accion" value="configurar"><button>Guardar capacidades</button></form>
<form id="receta"><input name="rendimiento" value="100.000">
<p data-m2-draft-status>Base de edición: versión 1 publicada.</p>
<div data-m2-builder><div data-m2-rows><fieldset data-m2-row>
<select data-m2-articulo name="componentes[0][articulo_id]"><option value="1" data-unidad="g">Harina</option></select>
<select data-m2-unidad><option>g</option></select><select data-m2-modalidad><option value="almacenado">Existencias</option></select>
<select name="componentes[0][version_receta_id]" data-m2-version><option value="">No ejecuta elaboración</option></select>
</fieldset></div><template data-m2-template></template><p data-m2-status></p></div></form>`, {runScripts:'outside-only',url:'https://example.test/#versiones'});
const w=dom.window,d=w.document;
let confirmation=null;
w.ConfirmationModal={get:()=>({open:options=>{confirmation=options;}})};
w.eval(source);
assert.equal(d.getElementById('versiones').open,true,'Retorno abre el historial plegado');
const capacities=d.querySelector('#capacidades');
let event=new w.Event('submit',{cancelable:true});capacities.dispatchEvent(event);
assert.equal(event.defaultPrevented,false,'La sincronización inicial del selector no crea un borrador falso');
assert.equal(confirmation,null);
const input=d.querySelector('[name="rendimiento"]'),status=d.querySelector('[data-m2-draft-status]');
input.value='110.000';input.dispatchEvent(new w.Event('input',{bubbles:true}));
assert.match(status.textContent,/Cambios pendientes/);
event=new w.Event('submit',{cancelable:true});capacities.dispatchEvent(event);
assert.equal(event.defaultPrevented,true,'Guardar capacidades requiere decidir sobre borrador');
assert.equal(confirmation.secondaryLabel,'Seguir editando');
assert.equal(input.value,'110.000','Cancelar conserva el valor');
input.value='100.000';input.dispatchEvent(new w.Event('input',{bubbles:true}));
assert.match(status.textContent,/Base de edición/,'Restituir valores elimina la marca de cambios');
d.querySelector('#receta').setAttribute('data-m2-pendiente','');
input.dispatchEvent(new w.Event('change',{bubbles:true}));
assert.match(status.textContent,/Cambios pendientes/,'Una previsión del servidor continúa pendiente');
dom.window.close();
console.log('UX4 UI: ancla progresiva, estado del borrador, sincronización inicial y protección UX1 OK.');
