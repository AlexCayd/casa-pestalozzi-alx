const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM } = require('jsdom');

const root = path.resolve(__dirname, '../..').replace(/\\/g, '/');
const php = `require '${root}/vendor/autoload.php'; $rango=['start'=>'2024-01-01','end'=>'2024-01-07','preset'=>0,'label'=>'Personalizado']; $rangeAllowFuture=true; $rangeShowPresets=false; $rangeMaxDays=366; include '${root}/views/admin/partials/_range-picker.php';`;
const html = execFileSync('php', ['-r', php], { encoding: 'utf8' });
const dom = new JSDOM(html, { url: 'http://localhost/admin/ia', runScripts: 'outside-only' });
const { window } = dom;
window.eval(fs.readFileSync(path.join(root, 'src/js/admin/core/range-picker.js'), 'utf8'));
const picker = window.document.querySelector('[data-analytics-range-picker]');
const api = window.AdminRangePicker.iniciar(picker);
assert.equal(window.AdminRangePicker.iniciar(picker), api, 'Inicialización idempotente');
const query = (selector) => picker.querySelector(selector);
const apply = query('[data-range-apply]');
let applied = [];
picker.addEventListener('admin-range:aplicar', (event) => {
    event.preventDefault();
    applied.push({ ...event.detail });
});

api.establecer('2024-01-01', '2024-12-31');
api.abrir();
assert.equal(apply.disabled, false, '366 días inclusivos permitidos');
apply.click();
assert.deepEqual(applied, [{ desde: '2024-01-01', hasta: '2024-12-31' }], 'Evento entrega fechas sin navegación');
assert.equal(query('[data-range-pop]').hidden, true, 'Aplicar cierra');
assert.equal(window.document.activeElement, query('[data-range-trigger]'), 'Foco vuelve al disparador');
assert.match(query('[data-range-dates]').textContent, /31 dic 2024/, 'Disparador refleja aplicación');

api.establecer('2024-01-01', '2025-01-01');
api.abrir();
assert.equal(apply.disabled, true, '367 días bloqueados');
assert.match(query('[data-range-summary]').textContent, /366 días inclusivos/, 'Límite explicado');
apply.click();
assert.equal(applied.length, 1, 'No aplica rango excesivo');

api.establecer('2024-01-01', '2024-01-07');
api.abrir();
for (const selector of ['[data-range-apply]', '[data-range-cancel]', '[data-range-prev]', '[data-range-next]']) {
    for (const key of ['Enter', ' ']) {
        const event = new window.KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
        query(selector).dispatchEvent(event);
        assert.equal(event.defaultPrevented, false, 'Teclado nativo de ' + selector + '/' + key);
    }
}
const day = query('[data-iso="2024-01-01"]');
day.focus();
const select = new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
day.dispatchEvent(select);
assert.equal(select.defaultPrevented, true, 'Enter selecciona solo en rejilla');
assert.equal(apply.disabled, true, 'Primer extremo no aplica un rango incompleto');
query('[data-range-cancel]').click();
assert.equal(applied.length, 1, 'Cancelar no aplica');

api.establecer('2024-02-30', '2024-03-01');
api.abrir();
assert.equal(apply.disabled, true, 'Fecha imposible no se normaliza a otra fecha');
api.cerrar(false);
dom.window.close();
console.log('PASS range-picker: componente PHP real, init idempotente, evento cancelable, 366/367 días, teclado nativo, cancelar y fechas estrictas.');
