const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');
const dom = new JSDOM(`<main data-almacenes-workspace><form data-warehouse-selection method="get" action="/admin/almacenes">
<input name="seleccion" value="1"><input name="q" value="Harina"><input name="solo_alertas" value="1">
<label><input type="checkbox" name="almacen_ids[]" value="1002" checked>Cocina</label>
<label><input type="checkbox" name="almacen_ids[]" value="1005" checked>Barra</label>
<label><input type="checkbox" name="almacen_ids[]" value="1006" checked>Frío</label>
<button type="button" data-warehouse-all hidden>Todos</button><button>Aplicar selección</button>
</form><p data-selection-status role="status"></p></main>`, {runScripts:'outside-only',url:'http://localhost'});
const {window}=dom, document=window.document, pending=new Map(); let timer=0, submissions=0, payload;
window.setTimeout=fn=>{pending.set(++timer,fn);return timer;}; window.clearTimeout=id=>pending.delete(id);
const form=document.querySelector('form');
form.requestSubmit=()=>{ const event=new window.Event('submit',{bubbles:true,cancelable:true}); if(form.dispatchEvent(event)){submissions++;payload=new window.FormData(form);} };
window.eval(fs.readFileSync(path.resolve(__dirname,'../../src/js/admin/inventario/inventario.js'),'utf8'));
document.dispatchEvent(new window.Event('DOMContentLoaded'));
const boxes=[...document.querySelectorAll('[type="checkbox"]')];
const change=index=>{boxes[index].checked=!boxes[index].checked;boxes[index].dispatchEvent(new window.Event('change',{bubbles:true}));};
const flush=()=>{const tasks=[...pending.values()];pending.clear();tasks.forEach(fn=>fn());};
change(0);change(1);assert.equal(submissions,0);assert.equal(pending.size,1,'Cambios consecutivos se agrupan');flush();
assert.equal(submissions,1);assert.deepEqual(payload.getAll('almacen_ids[]'),['1006']);
assert.equal(payload.get('q'),'Harina');assert.equal(payload.get('solo_alertas'),'1');assert.equal(payload.has('pagina'),false);
form.requestSubmit();assert.equal(submissions,1,'No se repite el envío durante navegación');
window.dispatchEvent(new window.Event('pageshow'));change(2);assert.equal(boxes[2].checked,true,'No se vacía la selección');
document.querySelector('[data-warehouse-all]').click();flush();assert.equal(submissions,2);assert.equal(payload.getAll('almacen_ids[]').length,3);
window.dispatchEvent(new window.Event('pageshow'));change(0);form.requestSubmit();flush();assert.equal(submissions,3,'Aplicación manual cancela el temporizador');
boxes.forEach(box=>{box.checked=false;});form.requestSubmit();assert.equal(submissions,3,'Envío vacío se rechaza');
assert.equal(document.activeElement,boxes[0]);
assert.equal(document.querySelector('[data-warehouse-all]').hidden,false);
window.dispatchEvent(new window.Event('pageshow'));change(0);window.dispatchEvent(new window.Event('pagehide'));flush();
assert.equal(submissions,3,'Abandonar la página cancela una consulta pendiente');
console.log('Almacenes: autoaplicación agrupada, URL, fallback manual, todos y selección no vacía OK.');
