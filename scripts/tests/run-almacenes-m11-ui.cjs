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
assert.match(change('0.225','merma'),/no puede ser negativo/,'Sin negativos: se avisa que el servidor rechazará la merma');
assert.match(change('-1','ajuste'),/cantidad válida/,'El conteo ya no admite negativos');
dom.window.close();
console.log('Almacenes M1.1: preview decimal exacto, límites y confirmación OK.');

// Piezas (§16): piezas × contenido + suelto, exacto; sin piezas, lo suelto es el total.
const piezasDom = new JSDOM('<main data-almacenes-workspace><form data-stock-operation="entrada" data-stock-saldo="450.000" data-stock-unidad="ml" data-stock-contenido="750.000" data-confirm-description="Barra"><input name="piezas"><input name="cantidad"><p data-stock-preview></p></form>'
  + '<form data-faltante-form data-faltante-restante="30.000" data-faltante-unidad="g" data-confirm-description="Por conciliar 30.000 g"><input data-faltante-cantidad data-origen-nombre="Refrigeración" data-origen-propio="1"><input data-faltante-cantidad data-origen-nombre="Central" data-origen-propio="0"><input data-faltante-cantidad disabled value="5" data-origen-nombre="Vacío" data-origen-propio="1"><p data-faltante-suma></p></form></main>', { runScripts:'outside-only',pretendToBeVisual:true });
piezasDom.window.eval(fs.readFileSync(path.resolve(__dirname,'../../src/js/admin/inventario/inventario.js'),'utf8'));
piezasDom.window.document.dispatchEvent(new piezasDom.window.Event('DOMContentLoaded'));
const pd = piezasDom.window.document, pf = pd.querySelector('[data-stock-operation]');
const [pzs, suelto] = pf.querySelectorAll('input'), pout = pf.querySelector('p');
function piezas(p, c, type='entrada') { pf.dataset.stockOperation=type; pzs.value=p; suelto.value=c; pzs.dispatchEvent(new piezasDom.window.Event('input')); return pout.textContent; }
assert.match(piezas('8','450'),/6900\.000 ml/,'8 piezas + 450 ml sobre 450 ml de saldo');
assert.match(pf.dataset.confirmDescription,/8 piezas \+ 450\.000 ml \(6450\.000 ml\)/,'La confirmación dice piezas, suelto y total');
assert.match(piezas('2',''),/1950\.000 ml/,'Sin suelto cuenta sólo las piezas');
assert.match(piezas('','100'),/550\.000 ml/,'Sin piezas lo suelto es el total');
assert.match(piezas('0','0','ajuste'),/0\.000 ml/,'Un conteo admite cero');
assert.match(piezas('1','-5'),/cantidad válida/,'Suelto negativo no es válido');
const ff = pd.querySelector('[data-faltante-form]'), [refri, central] = ff.querySelectorAll('[data-faltante-cantidad]'), suma = ff.querySelector('[data-faltante-suma]');
function reparto(a, b) { refri.value=a; central.value=b; ff.dispatchEvent(new piezasDom.window.Event('input')); return suma.textContent; }
assert.match(suma.textContent,/Por repartir: 30\.000 g/,'Estado inicial sin contar el origen deshabilitado');
assert.match(reparto('20','5'),/Faltan 5\.000 g/,'Suma parcial');
assert.equal(suma.dataset.state,'pendiente');
assert.match(reparto('20.1','10'),/Sobran 0\.100 g/,'Suma excedida, exacta a milésimas');
assert.match(reparto('20','10'),/suma exactamente 30\.000 g/,'Suma exacta');
assert.equal(suma.dataset.state,'exacto');
assert.match(ff.dataset.confirmDescription,/Refrigeración 20\.000 g · Central 10\.000 g \(en confirmación\)/,'La confirmación lista el reparto y lo que espera a su dueño');
assert.match(reparto('1.0001',''),/Revisa las cantidades/,'Precisión excesiva');
piezasDom.window.close();
console.log('Almacenes M3: captura en piezas y suma exacta del reparto de faltantes OK.');
