/*
 * Avisos de la carta: el espejo en JS del marcado y la barra de formato.
 *
 * avisos.js repite en el navegador las reglas de
 * Services\Configuration\AvisosCarta para la vista previa en vivo. Aquí se
 * comprueba con los MISMOS casos que run-avisos-carta.php
 * (fixtures/avisos-carta-marcado.json), y las dos acciones de la barra
 * (Negritas, Subtítulo) sobre un área de texto simulada. Sin jsdom: basta un
 * stub de window/document con vm.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const raiz = path.resolve(__dirname, '../..');
const codigo = fs.readFileSync(path.join(raiz, 'src/js/admin/configuration/avisos.js'), 'utf8');
const document = {
    readyState: 'complete',
    querySelector: () => null,
    addEventListener: () => {},
    // Sin execCommand: avisos.js cae a su respaldo y escribe el valor a mano.
    execCommand: () => false,
};
const window = { document };
vm.runInNewContext(codigo, { window, document, Event: class { constructor(t) { this.type = t; } } });
const M = window.AvisosCartaMarcado;
assert.ok(M, 'avisos.js publica sus reglas de marcado.');

let n = 0;
const casos = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/avisos-carta-marcado.json'), 'utf8'));
for (const caso of casos) {
    assert.equal(M.html(caso.entrada, caso.clases || {}), caso.html, 'Marcado: ' + caso.nombre);
    n++;
}

function area(valor, desde, hasta) {
    return {
        value: valor,
        selectionStart: desde,
        selectionEnd: hasta === undefined ? desde : hasta,
        focus() {},
        setSelectionRange(a, b) { this.selectionStart = a; this.selectionEnd = b; },
        dispatchEvent() {},
    };
}
const seleccion = (ta) => ta.value.slice(ta.selectionStart, ta.selectionEnd);

// Negritas: envuelve la selección, la deja seleccionada y alterna.
let ta = area('Pregunta hoy', 9, 12);
M.alternarNegrita(ta);
assert.equal(ta.value, 'Pregunta **hoy**'); assert.equal(seleccion(ta), 'hoy'); n++;
M.alternarNegrita(ta);
assert.equal(ta.value, 'Pregunta hoy', 'Negritas sobre negritas las quita.'); assert.equal(seleccion(ta), 'hoy'); n++;

// Sin selección: la palabra bajo el cursor; en un hueco, un marcador.
ta = area('Pregunta hoy', 3);
M.alternarNegrita(ta);
assert.equal(ta.value, '**Pregunta** hoy'); n++;
ta = area('Hola ', 5);
M.alternarNegrita(ta);
assert.equal(ta.value, 'Hola **texto**'); assert.equal(seleccion(ta), 'texto', 'En un hueco deja «texto» seleccionado para escribir encima.'); n++;

// Los espacios de la orilla se quedan fuera y cada renglón lleva sus marcas.
ta = area(' uno \ndos', 0, 9);
M.alternarNegrita(ta);
assert.equal(ta.value, ' **uno** \n**dos**'); n++;

// Subtítulo: añade y quita el prefijo del renglón del cursor.
ta = area('Formas de pago\nEfectivo', 3);
M.alternarSubtitulo(ta);
assert.equal(ta.value, '## Formas de pago\nEfectivo');
assert.equal(ta.selectionStart, 6, 'El cursor se queda en la misma letra.'); n++;
M.alternarSubtitulo(ta);
assert.equal(ta.value, 'Formas de pago\nEfectivo', 'Subtítulo sobre subtítulo lo quita.'); n++;

// En un renglón vacío deja el prefijo listo para escribir.
ta = area('Uno\n', 4);
M.alternarSubtitulo(ta);
assert.equal(ta.value, 'Uno\n## '); assert.equal(ta.selectionStart, 7); n++;

// Varios renglones: todos subtítulo; los vacíos se respetan.
ta = area('a\n\nb', 0, 4);
M.alternarSubtitulo(ta);
assert.equal(ta.value, '## a\n\n## b'); n++;

console.log(`Avisos de la carta (JS): ${n} comprobaciones OK`);
