const assert = require('node:assert/strict');
const { JSDOM, VirtualConsole } = require('jsdom');
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

// Menú «Más» de la fila (popover nativo). JSDOM no trae el API popover: se simula lo
// mínimo (abrir, cerrar y el evento toggle) para comprobar lo que añade inventario.js.
const menuDom = new JSDOM('<main data-almacenes-workspace><button class="almacen-more" popovertarget="m1" aria-controls="m1" aria-expanded="false">Más</button>'
  + '<div id="m1" popover data-row-menu><button type="button" data-admin-modal-open="articulo-1-configurar">Configurar asociación</button><a href="/m">Movimientos</a></div>'
  + '<div id="articulo-1-configurar" data-admin-modal></div><button id="fuera">Fuera</button></main>', { runScripts:'outside-only', pretendToBeVisual:true });
const mw = menuDom.window, md = mw.document, menu = md.getElementById('m1'), mas = md.querySelector('.almacen-more');
let abierto = false;
function alternar(estado) { abierto = estado; const e = new mw.Event('toggle'); e.newState = estado ? 'open' : 'closed'; menu.dispatchEvent(e); }
menu.showPopover = () => alternar(true);
menu.hidePopover = () => alternar(false);
const matchesOriginal = mw.Element.prototype.matches;
menu.matches = function (s) { return s === ':popover-open' ? abierto : matchesOriginal.call(this, s); };
mw.eval(fs.readFileSync(path.resolve(__dirname,'../../src/js/admin/inventario/inventario.js'),'utf8'));
md.dispatchEvent(new mw.Event('DOMContentLoaded'));
const tecla = (el, key) => el.dispatchEvent(new mw.KeyboardEvent('keydown', {key, bubbles:true, cancelable:true}));
tecla(mas, 'ArrowDown');
assert.equal(mas.getAttribute('aria-expanded'), 'true', 'aria-expanded sigue al popover abierto');
assert.equal(md.activeElement.textContent, 'Configurar asociación', 'Flecha abajo abre y enfoca el primer elemento');
tecla(md.activeElement, 'ArrowDown'); assert.equal(md.activeElement.textContent, 'Movimientos', 'Flechas recorren el menú');
tecla(md.activeElement, 'Home'); assert.equal(md.activeElement.textContent, 'Configurar asociación', 'Inicio vuelve al primero');
tecla(md.activeElement, 'Escape');
assert.equal(abierto, false); assert.equal(md.activeElement, mas, 'Escape cierra y devuelve el foco a «Más»');
assert.equal(mas.getAttribute('aria-expanded'), 'false');
mas.focus(); alternar(true);
md.querySelector('[data-admin-modal-open="articulo-1-configurar"]').click();
assert.equal(abierto, false, 'Abrir el modal desde el menú lo cierra: no queda encima del diálogo');
const cuadros = []; mw.requestAnimationFrame = fn => { cuadros.push(fn); return cuadros.length; };
md.getElementById('fuera').focus();
md.getElementById('articulo-1-configurar').dispatchEvent(new mw.CustomEvent('admin:modal-closed'));
cuadros.forEach(fn => fn());
assert.equal(md.activeElement, mas, 'Al cerrar el modal el foco vuelve a «Más», no al elemento oculto');
menuDom.window.close();
console.log('Almacenes: menú «Más» con aria-expanded, flechas, Escape y foco de vuelta OK.');

// Cancelar el diálogo abierto por ?asociar= limpia la URL (sin dejarla personalizada).
function cancelarAsociacion(href) {
  // JSDOM no navega: location.reload() se reporta como «navigation» en la consola virtual.
  const recargas = [];
  const consola = new VirtualConsole();
  consola.on('jsdomError', e => { if (String(e.message).includes('navigation')) recargas.push(e.message); else throw e; });
  const d = new JSDOM('<main data-almacenes-workspace><div id="almacen-asociar" data-admin-modal data-contextual-open="1"></div></main>', { url: href, runScripts:'outside-only', pretendToBeVisual:true, virtualConsole: consola });
  d.window.eval(fs.readFileSync(path.resolve(__dirname,'../../src/js/admin/inventario/inventario.js'),'utf8'));
  d.window.document.dispatchEvent(new d.window.Event('DOMContentLoaded'));
  d.window.document.getElementById('almacen-asociar').dispatchEvent(new d.window.CustomEvent('admin:modal-closed'));
  const r = { href: d.window.location.href, recargas: recargas.length };
  d.window.close();
  return r;
}
let c = cancelarAsociacion('http://localhost/admin/global/almacenes?articulo_id=1026&vista=existencias&asociar=1026');
assert.equal(c.href, 'http://localhost/admin/global/almacenes', 'Cancelar desde la fila vuelve a la vista por defecto');
assert.equal(c.recargas, 1, 'Recarga para quitar el filtro del artículo');
c = cancelarAsociacion('http://localhost/admin/almacenes?seleccion=1&almacen_ids%5B%5D=1005&q=lim&articulo_id=7&asociar=7');
assert.equal(c.href, 'http://localhost/admin/almacenes?seleccion=1&almacen_ids%5B%5D=1005&q=lim', 'Conserva la selección y la búsqueda');
assert.equal(c.recargas, 1);
c = cancelarAsociacion('http://localhost/admin/almacenes?articulo_id=7&asociar=7&volver=%2Fadmin%2Frecetas%2Farticulos%2Feditar%3Fid%3D7');
assert.equal(c.href, 'http://localhost/admin/almacenes?articulo_id=7&volver=%2Fadmin%2Frecetas%2Farticulos%2Feditar%3Fid%3D7', 'Desde otro módulo sólo quita asociar y conserva el regreso');
assert.equal(c.recargas, 0, 'Sin recarga cuando se conserva el artículo');
console.log('Almacenes: cancelar «Asociar artículo» abierto por URL la limpia o vuelve a la vista por defecto OK.');


// Un diálogo por operación: con varios almacenes, el selector muestra sólo el panel del
// elegido y el foco inicial va al selector; con uno, al primer campo del formulario.
{
  const d = new JSDOM('<main data-almacenes-workspace>'
    + '<div id="articulo-1-merma" data-admin-modal><button data-admin-modal-close aria-label="Cerrar ventana">x</button>'
    + '<select id="articulo-1-merma-almacen" data-warehouse-picker><option value="">Elige</option><option value="1">Central</option><option value="4">Bodega</option></select><p data-warehouse-empty>Elige uno</p>'
    + '<div data-warehouse-panel="1" hidden><form><input type="hidden" name="accion" value="merma"><input id="c1" name="cantidad"></form></div>'
    + '<div data-warehouse-panel="4" hidden><form><input type="hidden" name="accion" value="merma"><input id="c4" name="cantidad"></form></div></div>'
    + '<div id="articulo-2-ajuste" data-admin-modal><button data-admin-modal-close>x</button><div data-warehouse-panel="1"><form><input type="hidden" name="version" value="3"><input id="p2" name="piezas"><input name="cantidad"></form></div></div>'
    + '</main>', { runScripts:'outside-only', pretendToBeVisual:true });
  const w = d.window, doc = w.document;
  w.eval(fs.readFileSync(path.resolve(__dirname,'../../src/js/admin/inventario/inventario.js'),'utf8'));
  doc.dispatchEvent(new w.Event('DOMContentLoaded'));
  const varios = doc.getElementById('articulo-1-merma'), picker = varios.querySelector('[data-warehouse-picker]');
  const paneles = () => Array.from(varios.querySelectorAll('[data-warehouse-panel]')).map(p => p.hidden);
  varios.dispatchEvent(new w.CustomEvent('admin:modal-opened', {detail:{trigger:null}}));
  assert.equal(doc.activeElement, picker, 'Sin almacén elegido, el foco va al selector');
  assert.deepEqual(paneles(), [true, true], 'Ningún formulario visible hasta elegir almacén');
  assert.equal(varios.querySelector('[data-warehouse-empty]').hidden, false, 'La ayuda explica que falta elegir almacén');
  picker.value = '4'; picker.dispatchEvent(new w.Event('change'));
  assert.deepEqual(paneles(), [true, false], 'Elegir un almacén muestra sólo su formulario');
  assert.equal(varios.querySelector('[data-warehouse-empty]').hidden, true, 'La ayuda se retira al elegir');
  varios.dispatchEvent(new w.CustomEvent('admin:modal-opened', {detail:{trigger:null}}));
  assert.equal(doc.activeElement.id, 'c4', 'Reabierto con almacén elegido, el foco va a su primer campo');
  const tab = (shift) => doc.activeElement.dispatchEvent(new w.KeyboardEvent('keydown', {key:'Tab', shiftKey:shift, bubbles:true, cancelable:true}));
  tab(false);
  assert.equal(doc.activeElement.getAttribute('aria-label'), 'Cerrar ventana', 'Tab desde el último campo visible vuelve al principio: los paneles ocultos no cuentan');
  tab(true);
  assert.equal(doc.activeElement.id, 'c4', 'Mayús+Tab desde el primero va al último visible, no a uno oculto');
  const uno = doc.getElementById('articulo-2-ajuste');
  uno.dispatchEvent(new w.CustomEvent('admin:modal-opened', {detail:{trigger:null}}));
  assert.equal(doc.activeElement.id, 'p2', 'Con un solo almacén el foco va al primer campo visible, no a un oculto');
  d.window.close();
  console.log('Almacenes: diálogo por operación, selector de almacén y foco inicial OK.');
}
