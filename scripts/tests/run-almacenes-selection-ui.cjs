const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const file = fs.readFileSync(path.join(__dirname, '../../src/js/admin/inventario/inventario.js'), 'utf8');
const source = file.slice(file.indexOf('// Almacenes comparte'));
const boxes = [true, true, true].map(checked => ({checked, focused:false, focus(){this.focused=true;}, matches(){return true;}}));
const handlers = {};
const status = {textContent:''};
const selected = () => boxes.filter(box => box.checked);
const selection = {
    addEventListener(name, handler){handlers[name]=handler;},
    querySelectorAll(selector){return selector.endsWith(':checked') ? selected() : boxes;},
    querySelector(selector){return selector === '[data-warehouse-all]' ? null : selected()[0] || null;},
    requestSubmit(){handlers.submit({preventDefault(){}});}
};
const workspace = {
    dataset: {initialView:'existencias'},
    querySelector(selector){return selector === '[data-warehouse-selection]' ? selection : selector === '[data-selection-status]' ? status : null;},
    querySelectorAll(){return [];}
};
// El modal contextual registra la limpieza de ?asociar= al cancelarse (ver run-almacenes-m11-ui.cjs).
const escuchas=[];const modal={id:'almacen-asociar',addEventListener(tipo){escuchas.push(tipo);}};const dispatched=[];
const document={readyState:'complete',querySelector(selector){return selector==='[data-almacenes-workspace]'?workspace:selector==='[data-admin-modal][data-contextual-open="1"]'?modal:null;},dispatchEvent(event){dispatched.push(event);}};
class CustomEvent { constructor(type,options){this.type=type;this.detail=options&&options.detail;} }
vm.runInNewContext(source, {window:{setTimeout(){return 1;},clearTimeout(){},addEventListener(){}},document,CustomEvent});
assert.deepEqual(dispatched.map(event=>[event.type,event.detail.id]),[['admin:open-modal','almacen-asociar']],'La llegada contextual abre el modal compartido una sola vez');
assert.deepEqual(escuchas,['admin:modal-closed'],'Cancelar el modal contextual limpia la URL');
boxes[0].checked=false;handlers.change({target:boxes[0]});
assert.equal(selected().length,2,'Se permite combinar dos almacenes');
assert.match(status.textContent,/2 seleccionados/,'El cambio pendiente se anuncia sin fingir resultados actualizados');
boxes[1].checked=false;handlers.change({target:boxes[1]});
assert.equal(selected().length,1,'Se permite consulta individual');
boxes[2].checked=false;handlers.change({target:boxes[2]});
assert.equal(boxes[2].checked,true,'La última selección no se elimina');
assert.match(status.textContent,/al menos un almacén/,'La restricción se anuncia de forma accesible');
boxes.forEach(box => {box.checked=false;});let prevented=false;
handlers.submit({preventDefault(){prevented=true;}});
assert.equal(prevented,true,'Un submit sin selección se rechaza');
assert.equal(boxes[0].focused,true,'El error devuelve foco a la selección');
console.log('Almacenes UI: selección múltiple, último almacén y envío vacío OK');

// Segmento y etiqueta comparten detalle; el foco en cualquiera debe conservarlo.
const timers = new Map(); let nextTimer = 0;
const tip = {id:'stock', style:{}, offsetWidth:200, offsetHeight:100, open:false, hovered:false, events:{},
    addEventListener(name, fn){(this.events[name] ||= []).push(fn);},
    showPopover(){this.open=true;}, hidePopover(){this.open=false;},
    matches(selector){return selector === ':hover' ? this.hovered : this.open;}
};
const triggers = [0,1].map(() => ({events:{}, getAttribute(){return 'stock';}, getBoundingClientRect(){return {left:20,bottom:50};}, addEventListener(name,fn){this.events[name]=fn;}}));
const tooltipWorkspace={dataset:{initialView:'existencias'},querySelector(){return null;},querySelectorAll(selector){return selector==='[data-stock-tip]'?triggers:[];}};
const tooltipDocument = {readyState:'complete',activeElement:null,getElementById(){return tip;},querySelector(selector){return selector==='[data-almacenes-workspace]'?tooltipWorkspace:null;}};
vm.runInNewContext(source,{document:tooltipDocument,window:{innerWidth:800,innerHeight:600,setTimeout(fn){timers.set(++nextTimer,fn);return nextTimer;},clearTimeout(id){timers.delete(id);}}});
const flush = () => {const pending = [...timers.values()];timers.clear();pending.forEach(fn=>fn());};
triggers[0].events.pointerenter();assert.equal(tip.open,true,'Hover abre detalle');
triggers[0].events.pointerleave();tip.hovered=true;tip.events.pointerenter.forEach(fn=>fn());flush();
assert.equal(tip.open,true,'Se puede atravesar desde segmento hacia el detalle');
tip.hovered=false;tooltipDocument.activeElement=triggers[1];tip.events.pointerleave.forEach(fn=>fn());flush();
assert.equal(tip.open,true,'El foco de la etiqueta mantiene el mismo tooltip aunque el segmento no tenga foco');
tooltipDocument.activeElement=null;tip.events.pointerleave.forEach(fn=>fn());flush();
assert.equal(tip.open,false,'Salir de detalle y perder foco lo cierra');
console.log('Almacenes UI: tooltip atravesable y foco compartido OK');
