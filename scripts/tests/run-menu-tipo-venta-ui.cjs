// Fase 1 (arquitectura §16): contrato de interfaz del tipo de venta.
// Ejecuta los componentes reales (selección en lote y editor de variantes) con
// JSDOM y comprueba el marcado de las vistas que envían al backend.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

// ── Contratos de marcado ────────────────────────────────────────────
const form = read('views/admin/menu/form.php');
assert.match(form, /type="radio" name="tipo_venta"[\s\S]{0,200}?required>/, 'La ficha envía tipo_venta con radios obligatorios');
assert.match(form, /\$tipoVigente === \$valor \? 'checked'/, 'Sólo se marca el tipo vigente (el alta llega sin tipo)');
assert.match(form, /se confirmará al guardar/, 'La sugerencia explica que guardar la confirma');

const index = read('views/admin/menu/index.php');
assert.match(index, /\$menuBase \. '\/tipo-venta' \. \$sufijoLista/, 'La confirmación en lote va a {menuBase}/tipo-venta conservando el filtro');
assert.match(index, /name="ofertas\[\]"/, 'Cada fila envía su oferta en ofertas[]');
assert.match(index, /<input type="hidden" name="menu_csrf" value="<\?php echo \$esc\(\$menuCsrf\); \?>">\n\s*<\?php endif; \?>\n\s*<div class="admin-menu__lote-barra">/, 'El lote lleva menu_csrf');
assert.match(index, /<input type="hidden" name="tipo" data-reactive-control/, 'La búsqueda reactiva conserva el filtro de tipo');
assert.match(index, /if \(\$tvEditar\) : \?>\s*<form method="POST"/, 'Sin edición no se pinta el formulario de lote');
assert.equal((index.match(/data-reactive-page/g) || []).length >= 1, true);

const componentes = read('views/admin/recetas/_articulo-componentes.php');
const reventa = componentes.slice(componentes.indexOf('if(!empty($composicionReventa))'), componentes.indexOf('<?php else: ?>'));
assert.match(reventa, /name="componentes\[0\]\[modalidad\]" value="almacenado"/, 'Reventa fija la modalidad almacenado');
assert.match(reventa, /name="componentes\[0\]\[etapa\]" value="servicio"/, 'Reventa fija la etapa servicio');
for (const ausente of ['data-m2-agregar', 'data-m2-quitar', 'version_receta_id', 'componentes[1]']) {
    assert.equal(reventa.includes(ausente), false, `El editor de reventa no ofrece ${ausente}`);
}
assert.match(read('views/admin/recetas/presentaciones.php'), /\$composicionReventa=\(\$oferta\['tipo_venta'\]\?\?''\)==='reventa'&&\(\$oferta\['tipo_venta_estado'\]\?\?''\)==='confirmado'/, 'Sólo la reventa confirmada simplifica Presentaciones');

const legada = read('views/admin/recetas/form.php');
assert.match(legada, /<\?php if \(!\$recetaReventa\) : \?>\s*<template data-recipe-template>/, 'La receta de reventa no ofrece agregar filas');
assert.match(legada, /\$subrecetas = \[\];/, 'La receta de reventa no ofrece preparaciones anteriores');

// El campo vive en un parcial que comparten la ficha y el alta («Se compra por pieza»).
assert.match(read('views/admin/recetas/articulo-identidad.php'), /include __DIR__\.'\/_articulo-pieza\.php'/, 'La identidad incluye el contenido por pieza');
assert.match(read('views/admin/recetas/_articulo-pieza.php'), /name="contenido_pieza" type="number" min="0\.001"[^>]*step="0\.001"/, 'La identidad captura el contenido por pieza a milésimas');
// M6 (arquitectura §18): las capacidades se derivan; la ficha pregunta cómo se obtiene.
assert.match(read('views/admin/recetas/articulo.php'), /¿Cómo se obtiene\?[\s\S]*Se compra<[\s\S]*Se prepara en casa/, 'La ficha clasifica el artículo por cómo se obtiene');
assert.match(read('views/admin/menu/variantes.php'), /'solo_precio' => \$soloPrecio/, 'Variantes recibe la bandera de reventa confirmada');

// ── Selección en lote (componente real) ─────────────────────────────
{
    const html = `<form data-seleccion-lote data-seleccion-singular="producto" data-seleccion-plural="productos">
        <label hidden data-seleccion-todos-envoltura><input type="checkbox" data-seleccion-todos> Todos</label>
        <p data-seleccion-estado role="status">Marca los productos.</p>
        <input type="checkbox" name="ofertas[]" value="1" data-seleccion-item>
        <input type="checkbox" name="ofertas[]" value="2" data-seleccion-item>
        <input type="checkbox" name="ofertas[]" value="3" data-seleccion-item>
        <button type="submit">Confirmar</button></form>`;
    const dom = new JSDOM(html, { runScripts: 'outside-only' });
    const { window } = dom; const doc = window.document;
    window.eval(read('src/js/admin/core/seleccion-lote.js'));
    doc.dispatchEvent(new window.Event('DOMContentLoaded'));
    const f = doc.querySelector('form');
    const todos = doc.querySelector('[data-seleccion-todos]');
    const items = [...doc.querySelectorAll('[data-seleccion-item]')];
    const estado = doc.querySelector('[data-seleccion-estado]');
    assert.equal(doc.querySelector('[data-seleccion-todos-envoltura]').hidden, false, 'Con JS aparece «seleccionar todos»');
    assert.equal(estado.textContent, 'Marca los productos.', 'Al cargar no se reemplaza la instrucción del servidor');

    todos.checked = true; todos.dispatchEvent(new window.Event('change', { bubbles: true }));
    assert.deepEqual(items.map((c) => c.checked), [true, true, true], '«Todos» marca la página');
    assert.equal(estado.textContent, '3 productos seleccionados.');

    items[1].checked = false; items[1].dispatchEvent(new window.Event('change', { bubbles: true }));
    assert.equal(todos.checked, false); assert.equal(todos.indeterminate, true, 'Selección parcial = estado mixto');
    assert.equal(estado.textContent, '2 productos seleccionados.');

    items.forEach((c) => { c.checked = false; });
    items[0].dispatchEvent(new window.Event('change', { bubbles: true }));
    let envio = new window.Event('submit', { bubbles: true, cancelable: true });
    f.dispatchEvent(envio);
    assert.equal(envio.defaultPrevented, true, 'No se envía un lote vacío');
    assert.equal(doc.activeElement, items[0], 'El foco va a la primera casilla');
    assert.match(estado.textContent, /al menos un producto/);

    items[2].checked = true;
    envio = new window.Event('submit', { bubbles: true, cancelable: true });
    f.dispatchEvent(envio);
    assert.equal(envio.defaultPrevented, false, 'Con selección el POST nativo sigue');
    assert.equal(f.getAttribute('aria-busy'), 'true');
    envio = new window.Event('submit', { bubbles: true, cancelable: true });
    f.dispatchEvent(envio);
    assert.equal(envio.defaultPrevented, true, 'Un segundo envío se bloquea');
    dom.window.close();
}

// ── Variantes en reventa confirmada (componente real) ───────────────
function editorVariantes(soloPrecio, ajustes) {
    const datos = {
        producto_id: 7, nombre: 'Botella', precio: 650, solo_precio: soloPrecio,
        grupos: [{ nombre: 'Temperatura', opciones: [
            { id: 1, nombre: 'Ambiente', precio_delta: 0, predeterminada: true, activo: true, ajustes: [] },
            { id: 2, nombre: 'En hielera', precio_delta: 40, predeterminada: false, activo: true, ajustes },
        ] }],
        ingredientes: [{ id: 5, nombre: 'Hielo', unidad: 'g', costo: '0.01' }], subrecetas: [],
        base: [{ tipo: 'ingrediente', ref_id: 2, nombre: 'Vino', unidad: 'ml', cantidad: '750' }],
        plantillas: {}, limites: { grupos: 6, opciones: 12 }, puede_precio: true, puede_composicion: true,
    };
    const html = `<section data-variantes-editor data-variantes-url="/x" data-admin-csrf="t">
        <script type="application/json" data-variantes-datos>${JSON.stringify(datos)}</script>
        <div data-variantes-errores hidden></div><div data-variantes-grupos></div><div data-variantes-plantillas></div>
        <div data-variantes-pos></div><div data-variantes-receta></div><p data-variantes-estado></p>
        <button data-variantes-descartar></button><button data-variantes-guardar></button></section>`;
    const dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true });
    dom.window.eval(read('src/js/admin/menu/variantes.js'));
    const doc = dom.window.document;
    // El editor repinta al abrir un panel: se vuelve a buscar cada botón.
    const total = doc.querySelectorAll('[data-toggle-receta]').length;
    for (let i = 0; i < total; i++) doc.querySelectorAll('[data-toggle-receta]')[i].click();
    assert.equal(doc.querySelectorAll('.admin-vopcion__panel').length, total, 'Los paneles de todas las opciones quedan abiertos');
    return dom;
}
{
    const dom = editorVariantes(true, []);
    const doc = dom.window.document;
    assert.equal(doc.querySelectorAll('[data-agregar-ajuste]').length, 0, 'Reventa: no se ofrece agregar, quitar ni cambiar artículos');
    const vacios = [...doc.querySelectorAll('.admin-vopcion__vacio')];
    assert.equal(vacios.length, 2); vacios.forEach((v) => assert.match(v.textContent, /sólo cambia el nombre y el precio/));
    [...doc.querySelectorAll('.admin-vopcion__resumen')].forEach((r) => assert.match(r.textContent, /Sólo cambia el precio/));
    assert.equal(doc.querySelectorAll('[data-campo="precio_delta"]:not([disabled])').length, 2, 'El precio de cada opción sigue editable');
    dom.window.close();
}
{
    const dom = editorVariantes(true, [{ accion: 'agregar', tipo: 'ingrediente', ref_id: 5, cantidad: '200' }]);
    const doc = dom.window.document;
    assert.equal(doc.querySelectorAll('[data-agregar-ajuste]').length, 0, 'Con ajustes heredados tampoco se ofrecen nuevos');
    assert.equal(doc.querySelectorAll('[data-borrar-ajuste]').length, 1, 'El ajuste heredado se puede quitar para guardar');
    assert.match(doc.querySelector('.admin-vopcion__nota--aviso').textContent, /Quita estos ajustes/);
    dom.window.close();
}
{
    const dom = editorVariantes(false, []);
    assert.equal(dom.window.document.querySelectorAll('[data-agregar-ajuste]').length >= 3, true, 'Preparado o sugerido: editor sin cambios');
    dom.window.close();
}

console.log('Menú tipo de venta UI: ficha, lote, editores de reventa, variantes de precio y contenido por pieza OK.');
