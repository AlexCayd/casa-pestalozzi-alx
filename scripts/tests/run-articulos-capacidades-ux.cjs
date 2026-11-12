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
console.log('Artículos UX: alta en un paso, doble envío y conservación de borradores OK.');
