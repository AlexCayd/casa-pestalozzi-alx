const assert = require('node:assert/strict');
const { JSDOM } = require('jsdom');
const fs = require('node:fs');
const path = require('node:path');
const dom = new JSDOM('<main data-almacenes-workspace><form data-stock-operation="entrada" data-stock-saldo="0.100" data-stock-unidad="g" data-confirm-description="Cocina"><input name="cantidad"><p data-stock-preview></p></form></main>', { runScripts:'outside-only',pretendToBeVisual:true });
dom.window.eval(fs.readFileSync(path.resolve(__dirname,'../../src/js/admin/inventario/inventario.js'),'utf8'));
dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
const form=dom.window.document.querySelector('form'), input=form.querySelector('input'), output=form.querySelector('p');
function change(value,type='entrada') { form.dataset.stockOperation=type; input.value=value; input.dispatchEvent(new dom.window.Event('input')); return output.textContent; }
assert.match(change('0.200'),/0\.300 g/,'Suma exacta sin errores binarios');
assert.match(change('0.225','merma'),/-0\.125 g/,'Negativo se informa sin ocultarlo');
assert.match(change('999999999.999','ajuste'),/999999999\.999 g/,'Conserva límite decimal');
assert.match(change('1.0001'),/cantidad válida/,'No redondea precisión excesiva');
assert.match(change('-1','entrada'),/cantidad válida/,'Entrada positiva');
assert.match(change('2.345'),/2\.445 g/);
assert.match(form.dataset.confirmDescription,/Cantidad: 2\.345 g/,'Confirmación presenta la cantidad y el resultado');
dom.window.close();
console.log('Almacenes M1.1: preview decimal exacto, límites y confirmación OK.');
