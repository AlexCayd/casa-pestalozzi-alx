// Trampa de foco del gestor de modales compartido (src/js/admin/admin.js):
// ancestros ocultos, inertes o <details> cerrados no cuentan como bordes del ciclo.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {JSDOM} = require('jsdom');

const dom = new JSDOM('<button id="origen" data-admin-modal-open="m">Abrir</button>'
    + '<div id="m" data-admin-modal hidden><section data-admin-modal-dialog>'
    + '<button id="primero" data-admin-modal-close>Cerrar</button>'
    + '<details id="plegable"><summary id="resumen">Solicitar transferencia</summary><input id="dentro-plegado"></details>'
    + '<input id="visible">'
    + '<div hidden><input id="oculto-por-ancestro"></div>'
    + '<div aria-hidden="true"><button id="aria-oculto">x</button></div>'
    + '<div inert><button id="inerte">x</button></div>'
    + '</section></div>', {runScripts: 'outside-only', pretendToBeVisual: true});
const w = dom.window, d = w.document;
w.requestAnimationFrame = fn => fn();
w.matchMedia = () => ({matches: false, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {}});
w.eval(fs.readFileSync('src/js/admin/admin.js', 'utf8'));
d.dispatchEvent(new w.Event('DOMContentLoaded'));
d.getElementById('origen').click();
const tab = (shift) => d.dispatchEvent(new w.KeyboardEvent('keydown', {key: 'Tab', shiftKey: !!shift, bubbles: true, cancelable: true}));

// El último elemento real es #visible: los ocultos por ancestro, aria-hidden o inert no cuentan.
d.getElementById('visible').focus();
tab(false);
assert.equal(d.activeElement.id, 'primero', 'Tab desde el último visible vuelve al primero (no se escapa a un oculto)');
tab(true);
assert.equal(d.activeElement.id, 'visible', 'Mayús+Tab desde el primero va al último visible');
// Un <summary> es enfocable y forma parte del ciclo; su contenido plegado no.
d.getElementById('plegable').open = true;
d.getElementById('primero').focus();
tab(true);
assert.equal(d.activeElement.id, 'visible', 'Con el details abierto el último sigue siendo el visible');
d.getElementById('plegable').open = false;
d.getElementById('resumen').focus();
assert.equal(d.activeElement.id, 'resumen', 'El summary recibe foco');
dom.window.close();

// Details anidados (transferencia cerrada con su historial plegado dentro): el summary del
// interior no se ve y no puede ser el borde del ciclo; un enlace dentro de un summary visible sí.
{
    const anidado = new JSDOM('<button id="origen" data-admin-modal-open="m">Abrir</button>'
        + '<div id="m" data-admin-modal hidden><section data-admin-modal-dialog>'
        + '<button id="primero" data-admin-modal-close>Cerrar</button>'
        + '<details id="doc"><summary id="doc-resumen">Transferencia #30 <a id="en-resumen" href="#t30">Enlace</a></summary>'
        + '<details id="historial"><summary id="historial-resumen">Historial</summary><a href="#x">x</a></details></details>'
        + '</section></div>', {runScripts: 'outside-only', pretendToBeVisual: true});
    const aw = anidado.window, ad = aw.document;
    aw.requestAnimationFrame = fn => fn();
    aw.matchMedia = w.matchMedia;
    aw.eval(fs.readFileSync('src/js/admin/admin.js', 'utf8'));
    ad.dispatchEvent(new aw.Event('DOMContentLoaded'));
    ad.getElementById('origen').click();
    const tecla = (shift) => ad.dispatchEvent(new aw.KeyboardEvent('keydown', {key: 'Tab', shiftKey: !!shift, bubbles: true, cancelable: true}));
    ad.getElementById('primero').focus();
    tecla(true);
    assert.equal(ad.activeElement.id, 'en-resumen', 'Con el documento cerrado, el último visible es el enlace de su summary, no el historial plegado');
    tecla(false);
    assert.equal(ad.activeElement.id, 'primero', 'Tab desde el último visible vuelve al primero');
    ad.getElementById('doc').open = true;
    ad.getElementById('primero').focus();
    tecla(true);
    assert.equal(ad.activeElement.id, 'historial-resumen', 'Abierto el documento, el summary del historial pasa a ser el último');
    anidado.window.close();
}
console.log('Modales: trampa de foco ignora ancestros ocultos, inertes y details cerrados OK.');
