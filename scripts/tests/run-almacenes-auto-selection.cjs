// Selección de «Almacenes en consulta» (panel desplegable): los cambios quedan
// pendientes hasta «Aplicar», nunca se envían solos; los atajos y el buscador no
// vacían la consulta; al enviar, la tabla se cambia por filas esqueleto.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dom = new JSDOM(`<main data-almacenes-workspace><form data-warehouse-selection method="get" action="/admin/almacenes">
<input name="seleccion" value="1"><input name="q" value="Harina"><input name="solo_alertas" value="1">
<div data-alcance-panel>
<div data-alcance-buscar hidden><input data-alcance-q type="search"></div>
<button type="button" data-warehouse-all hidden>Seleccionar todos</button>
<fieldset data-alcance-grupo><legend>Restaurante <span data-alcance-cuenta>2/2</span></legend>
<p data-alcance-atajos hidden><button type="button" data-alcance-todos>Todos</button><button type="button" data-alcance-ninguno>Ninguno</button></p>
<label class="almacen-choice" data-alcance-nombre="Cocina"><input type="checkbox" name="almacen_ids[]" value="1002" checked>Cocina</label>
<label class="almacen-choice" data-alcance-nombre="Barra y Maridaje"><input type="checkbox" name="almacen_ids[]" value="1005" checked>Barra</label>
<p data-alcance-vacio hidden>Sin coincidencias</p></fieldset>
<fieldset data-alcance-grupo><legend>Panadería <span data-alcance-cuenta>0/1</span></legend>
<p data-alcance-atajos hidden><button type="button" data-alcance-todos>Todos</button><button type="button" data-alcance-ninguno>Ninguno</button></p>
<label class="almacen-choice" data-alcance-nombre="Refrigeración"><input type="checkbox" name="almacen_ids[]" value="1006">Frío</label>
<p data-alcance-vacio hidden>Sin coincidencias</p></fieldset>
<p data-selection-status role="status"></p><button data-alcance-aplicar>Aplicar selección</button>
</div></form>
<section data-stock-list><form class="almacen-filters"></form><div class="almacen-legend"></div>
<div class="almacen-table"><table><tbody><tr><td>A</td></tr><tr><td>B</td></tr><tr><td>C</td></tr><tr><td>D</td></tr></tbody></table></div>
<nav class="admin-pagination"></nav></section></main>`, {runScripts:'outside-only',url:'http://localhost'});
const {window}=dom, document=window.document, pending=new Map(); let timer=0, submissions=0, payload;
window.setTimeout=fn=>{pending.set(++timer,fn);return timer;}; window.clearTimeout=id=>pending.delete(id);
const form=document.querySelector('form');
form.requestSubmit=()=>{ const event=new window.Event('submit',{bubbles:true,cancelable:true}); if(form.dispatchEvent(event)){submissions++;payload=new window.FormData(form);} };
window.eval(fs.readFileSync(path.resolve(__dirname,'../../src/js/admin/inventario/inventario.js'),'utf8'));
document.dispatchEvent(new window.Event('DOMContentLoaded'));
const boxes=[...document.querySelectorAll('[type="checkbox"]')];
const status=document.querySelector('[data-selection-status]'), aplicar=document.querySelector('[data-alcance-aplicar]');
const grupos=[...document.querySelectorAll('[data-alcance-grupo]')];
const change=index=>{boxes[index].checked=!boxes[index].checked;boxes[index].dispatchEvent(new window.Event('change',{bubbles:true}));};
const flush=()=>{const tasks=[...pending.values()];pending.clear();tasks.forEach(fn=>fn());};
const list=document.querySelector('[data-stock-list]');

// Con JavaScript aparecen buscador, «Seleccionar todos» y los atajos por propietario.
assert.equal(document.querySelector('[data-alcance-buscar]').hidden,false);
assert.equal(document.querySelector('[data-warehouse-all]').hidden,false);
grupos.forEach(g=>assert.equal(g.querySelector('[data-alcance-atajos]').hidden,false));

// Marcar no envía: queda pendiente, con recuento por grupo y en «Aplicar».
change(0);flush();
assert.equal(submissions,0,'Un cambio no se envía solo');
assert.match(status.textContent,/1 seleccionados/);
assert.equal(grupos[0].querySelector('[data-alcance-cuenta]').textContent,'1/2');
assert.equal(aplicar.textContent,'Aplicar (1)');

// «Ninguno» no puede dejar la consulta vacía.
grupos[0].querySelector('[data-alcance-ninguno]').click();
assert.equal(boxes.filter(b=>b.checked).length,1,'«Ninguno» se rechaza si vaciaría la consulta');
assert.match(status.textContent,/al menos un almacén/);
// La última casilla tampoco se desmarca a mano.
change(1);assert.equal(boxes[1].checked,true,'No se vacía la selección');

// «Todos» del grupo y «Seleccionar todos» marcan sin enviar.
grupos[1].querySelector('[data-alcance-todos]').click();
assert.equal(boxes[2].checked,true);assert.equal(grupos[1].querySelector('[data-alcance-cuenta]').textContent,'1/1');
document.querySelector('[data-warehouse-all]').click();
assert.equal(boxes.every(b=>b.checked),true);assert.equal(submissions,0);

// El buscador oculta filas sin tocar lo marcado; un grupo sin coincidencias lo dice.
const q=document.querySelector('[data-alcance-q]');
q.value='refrigeracion';q.dispatchEvent(new window.Event('input',{bubbles:true}));
assert.equal(boxes[0].closest('label').hidden,true);assert.equal(boxes[2].closest('label').hidden,false,'Sin acentos ni mayúsculas');
assert.equal(grupos[0].querySelector('[data-alcance-vacio]').hidden,false);
assert.equal(boxes[0].checked,true,'Ocultar no desmarca');
// «Ninguno» sólo actúa sobre lo visible.
grupos[0].querySelector('[data-alcance-ninguno]').click();assert.equal(boxes[0].checked,true);
const enter=new window.KeyboardEvent('keydown',{key:'Enter',bubbles:true,cancelable:true});q.dispatchEvent(enter);
assert.equal(enter.defaultPrevented,true,'Enter en el buscador no envía');
q.value='';q.dispatchEvent(new window.Event('input',{bubbles:true}));

// Aplicar envía el mismo GET y cambia la tabla por el esqueleto.
change(1);form.requestSubmit();
assert.equal(submissions,1);assert.deepEqual(payload.getAll('almacen_ids[]'),['1002','1006']);
assert.equal(payload.get('q'),'Harina');assert.equal(payload.get('solo_alertas'),'1');assert.equal(payload.has('pagina'),false);
assert.equal(payload.has('almacen_q'),false,'El buscador no viaja en el GET');
const skeleton=list.querySelector('[data-stock-skeleton]');
assert.ok(skeleton,'Aparece el esqueleto');
assert.equal(skeleton.querySelectorAll('.almacen-skeleton__fila').length,4,'Tantas filas como la tabla (3 a 6)');
assert.equal(skeleton.querySelector('.almacen-skeleton__filas').getAttribute('aria-hidden'),'true');
['.almacen-legend','.almacen-table','.admin-pagination'].forEach(s=>assert.equal(list.querySelector(s).hidden,true,'Se oculta '+s));
assert.equal(list.querySelector('.almacen-filters').hidden,false,'El buscador de artículos sigue visible');
flush();assert.match(skeleton.querySelector('[role="status"]').textContent,/Actualizando existencias/);
form.requestSubmit();assert.equal(submissions,1,'No se repite el envío durante navegación');

// Volver con el historial retira el esqueleto y restablece lo que pintó el servidor.
window.dispatchEvent(new window.Event('pageshow'));
assert.equal(list.querySelector('[data-stock-skeleton]'),null);
assert.equal(list.querySelector('.almacen-table').hidden,false);
assert.deepEqual(boxes.map(b=>b.checked),[true,true,false],'Vuelve la selección aplicada');
assert.equal(aplicar.textContent,'Aplicar selección');

// Envío vacío se rechaza y devuelve el foco.
boxes.forEach(box=>{box.checked=false;});form.requestSubmit();assert.equal(submissions,1,'Envío vacío se rechaza');
assert.equal(document.activeElement,boxes[0]);
assert.equal(list.querySelector('[data-stock-skeleton]'),null,'Un envío rechazado no muestra carga');
console.log('Almacenes: selección pendiente hasta aplicar, atajos, buscador, esqueleto y selección no vacía OK.');
