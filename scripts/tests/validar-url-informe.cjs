'use strict';
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('src/js/admin/ia/ia.js', 'utf8');
const start = source.indexOf('    function enviarInforme(borrador) {');
const end = source.indexOf('    function avisoInforme(', start);
if (start < 0 || end < 0) throw new Error('No se encontró enviarInforme');
const code = source.slice(start, end);
const url = process.argv[2];

async function run(candidate) {
    let opened = null;
    let warning = '';
    const tab = { closed: false, location: { replace(value) { opened = value; } }, close() { this.closed = true; } };
    const sandbox = {
        informePendiente: false, AMBITO: 'restaurante', API: '/admin/api/consultas', TIEMPO_LIMITE: 1000,
        URLSearchParams, actualizarCompositor() {}, anunciar() {}, avisoInforme(text) { warning = text; },
        window: {
            open() { return tab; }, AbortController, setTimeout, clearTimeout,
            fetch() { return Promise.resolve({ ok: true, redirected: false, json: () => Promise.resolve({ ok: true, informe: { url: candidate } }) }); }
        }
    };
    vm.runInNewContext(code + "\nenviarInforme({consulta:{intencion:'informe_finanzas',periodo:'hoy'}});", sandbox);
    await new Promise(resolve => setImmediate(resolve));
    return { opened, warning, closed: tab.closed };
}
(async () => {
    const good = await run(url);
    if (good.opened !== url || good.closed || good.warning) throw new Error('El asistente no abrió la URL canónica');
    for (const bad of [url + '&unidad_id=2', url.replace('/admin/', '//evil/admin/'), url.replace('/informe?', '/informe.pdf?'), url.replace('desde=', 'otro=')]) {
        const result = await run(bad);
        if (result.opened || !result.closed || !result.warning) throw new Error('URL manipulada aceptada: ' + bad);
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
