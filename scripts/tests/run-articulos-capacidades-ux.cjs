const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');

// Contrato de interfaz del módulo de artículos (arquitectura §18): alta en un paso
// desde el diálogo de la lista y ficha única, donde guardar los datos generales o el
// uso recarga la página y descartaría una receta sin publicar.
const html = `
<a href="/admin/panaderia/almacenes/catalogo/nuevo" data-m6-abrir-alta data-admin-modal-open="articulo-alta">Nuevo artículo</a>
<form method="POST" id="alta" data-m6-alta>
  <input name="nombre" value="Harina de trigo">
  <label><input type="radio" name="tipo" value="insumo" data-m6-tipo checked>Se compra</label>
  <label><input type="radio" name="tipo" value="preparacion" data-m6-tipo>Se prepara en casa</label>
  <button data-m6-alta-enviar data-etiqueta-insumo="Crear artículo" data-etiqueta-preparacion="Crear y abrir su receta">Crear artículo</button>
</form>
<form method="POST" id="uso" data-m6-descarta-borrador="Guardar el uso">
  <input type="hidden" name="accion" value="clasificar"><input type="hidden" name="revision" value="3">
  <label><input type="radio" name="tipo" value="insumo">Se compra</label>
  <label><input type="radio" name="tipo" value="preparacion" checked>Se prepara en casa</label>
  <label><input type="checkbox" name="activo" value="1" checked>Activo en Panadería</label>
  <button>Guardar uso</button>
</form>
<form method="POST" id="identidad" data-m6-descarta-borrador="Guardar los datos generales"><input name="nombre" value="Masa madre"><button>Guardar datos generales</button></form>
<form method="POST" id="receta"><input name="rendimiento" value="100.000">
  <div data-m2-builder><div data-m2-rows></div><template data-m2-template></template><p data-m2-status></p></div></form>`;
const dom = new JSDOM(html, {runScripts:'outside-only'});
const {window} = dom;
const doc = window.document;
const confirmaciones = [];
window.ConfirmationModal = {get(){ return {open(opciones){ confirmaciones.push(opciones); }}; }};
window.eval(fs.readFileSync('src/js/admin/recetas/articulos.js', 'utf8'));
const enviar = form => { const e = new window.Event('submit', {bubbles:true, cancelable:true}); form.dispatchEvent(e); return e; };

// Alta: con JavaScript el enlace no navega (el diálogo lo abre el gestor de modales).
const enlace = doc.querySelector('[data-m6-abrir-alta]');
const clic = new window.MouseEvent('click', {bubbles:true, cancelable:true});
enlace.dispatchEvent(clic);
assert.equal(clic.defaultPrevented, true, 'el enlace de alta no abandona la lista');

// El botón anuncia lo que va a pasar según cómo se obtiene el artículo.
const alta = doc.getElementById('alta');
const boton = alta.querySelector('[data-m6-alta-enviar]');
const [insumo, preparacion] = alta.querySelectorAll('[data-m6-tipo]');
assert.equal(boton.textContent, 'Crear artículo');
preparacion.checked = true;
preparacion.dispatchEvent(new window.Event('change', {bubbles:true}));
assert.equal(boton.textContent, 'Crear y abrir su receta', 'una preparación avisa que abrirá su receta');
insumo.checked = true;
insumo.dispatchEvent(new window.Event('change', {bubbles:true}));
assert.equal(boton.textContent, 'Crear artículo', 'volver a insumo restituye la etiqueta');
assert.deepEqual([...new window.FormData(alta).keys()], ['nombre', 'tipo'], 'el alta viaja en un solo formulario');

// Un doble envío no crea dos artículos.
assert.equal(enviar(alta).defaultPrevented, false, 'el primer envío continúa');
assert.equal(alta.getAttribute('aria-busy'), 'true');
assert.equal(enviar(alta).defaultPrevented, true, 'el segundo envío se detiene');

// Ficha: sin cambios en la receta, guardar el uso no pide confirmación.
const uso = doc.getElementById('uso');
const identidad = doc.getElementById('identidad');
const receta = doc.getElementById('receta');
assert.equal(enviar(uso).defaultPrevented, false, 'sin borrador el uso se guarda directo');
assert.equal(confirmaciones.length, 0);

// Con cambios sin publicar, cada formulario que recarga la ficha pide decidir.
const rendimiento = receta.querySelector('[name="rendimiento"]');
rendimiento.value = '110.000';
assert.equal(enviar(uso).defaultPrevented, true, 'guardar el uso no descarta la receta en silencio');
assert.equal(confirmaciones.length, 1);
assert.equal(confirmaciones[0].variant, 'warning');
assert.equal(confirmaciones[0].secondaryLabel, 'Seguir editando');
assert.match(confirmaciones[0].description, /^Guardar el uso /, 'el aviso nombra la acción que se intentó');
assert.equal(rendimiento.value, '110.000', 'el aviso conserva lo capturado');
assert.equal(enviar(identidad).defaultPrevented, true, 'los datos generales piden la misma decisión');
assert.match(confirmaciones[1].description, /^Guardar los datos generales /);

// Confirmar el descarte envía una sola vez, ya sin volver a preguntar.
let reenviados = 0;
uso.requestSubmit = () => { reenviados++; assert.equal(enviar(uso).defaultPrevented, false, 'el envío confirmado continúa'); };
confirmaciones[0].onPrimary();
assert.equal(reenviados, 1);
assert.equal(confirmaciones.length, 2, 'la confirmación no se repite');

// Una previsión devuelta por el servidor también es un borrador, aunque nada cambie.
rendimiento.value = '100.000';
assert.equal(enviar(identidad).defaultPrevented, false, 'restituir los valores retira el aviso');
receta.setAttribute('data-m2-pendiente', '');
assert.equal(enviar(identidad).defaultPrevented, true, 'la previsión pendiente conserva el aviso');
assert.equal(confirmaciones.length, 3);

dom.window.close();

// «Se compra por pieza»: el campo sólo se captura con la casilla y nunca en pza.
const piezaHtml = `<form id="datos"><select name="unidad"><option>g</option><option>kg</option><option>ml</option><option>l</option><option>pza</option></select>
<div data-m6-pieza><input type="hidden" name="por_pieza_control" value="1">
<label><input type="checkbox" name="por_pieza" value="1" data-m6-pieza-activar></label>
<input type="hidden" name="contenido_pieza" value="">
<input name="contenido_pieza" type="number" data-m6-pieza-campo></div></form>`;
const domPieza = new JSDOM(piezaHtml, {runScripts:'outside-only'});
domPieza.window.eval(fs.readFileSync('src/js/admin/recetas/articulos.js', 'utf8'));
const dp = domPieza.window.document, fp = dp.getElementById('datos');
const unidadSel = fp.querySelector('[name="unidad"]'), casilla = fp.querySelector('[data-m6-pieza-activar]'), cont = fp.querySelector('[data-m6-pieza-campo]');
const enviado = () => new domPieza.window.FormData(fp).getAll('contenido_pieza');
const cambiar = el => el.dispatchEvent(new domPieza.window.Event('change', {bubbles:true}));
assert.equal(cont.disabled, true, 'sin la casilla el contenido por pieza está bloqueado (lo bloquea el JS al cargar)');
assert.deepEqual(enviado(), [''], 'bloqueado, sólo viaja el contenido vacío');
unidadSel.value = 'l'; cambiar(unidadSel);
casilla.checked = true; cambiar(casilla);
assert.equal(cont.disabled, false, 'la casilla habilita el campo');
assert.equal(cont.required, true, 'marcada, el contenido es obligatorio');
cont.value = '1';
assert.deepEqual(enviado(), ['', '1'], 'habilitado, el valor capturado es el último y gana en el servidor');
casilla.checked = false; cambiar(casilla);
assert.equal(cont.value, '', 'desmarcarla vacía el campo');
assert.deepEqual(enviado(), [''], 'desmarcada, viaja vacío');
casilla.checked = true; cambiar(casilla);
assert.equal(cont.value, '1', 'volver a marcar antes de guardar devuelve el valor');
cont.value = '25';
unidadSel.value = 'pza'; cambiar(unidadSel);
assert.equal(casilla.checked, false, 'en pza la casilla se desmarca');
assert.equal(casilla.disabled, true, 'en pza la casilla se bloquea');
assert.equal(cont.disabled, true, 'en pza el contenido se bloquea');
assert.deepEqual(enviado(), [''], 'en pza no viaja contenido por pieza');
unidadSel.value = 'kg'; cambiar(unidadSel);
assert.equal(casilla.disabled, false, 'al salir de pza la casilla vuelve a estar disponible');
assert.equal(cont.disabled, true, 'pero el campo sigue bloqueado hasta marcarla');
domPieza.window.close();
console.log('Artículos UX: alta en un paso, doble envío, conservación de borradores y contenido por pieza OK.');
