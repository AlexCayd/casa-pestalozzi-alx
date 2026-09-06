const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const workflow = JSON.parse(fs.readFileSync(path.join(__dirname, '../../n8n/reservaciones-comunicaciones.json'), 'utf8'));
const nodes = Object.fromEntries(workflow.nodes.map(node => [node.name, node]));
const execute = (name, data, source) => new Function('$json', '$env', '$', nodes[name].parameters.jsCode)(
    data, { N8N_SECRET: 'fixture-secret' }, () => ({ item: { json: source } })
);
const events = [
    ['reservation.confirmed', 'reservation_confirmation', 5],
    ['reservation.reminder_next_day', 'reservation_reminder', 5],
    ['reservation.schedule_change', 'reservation_schedule_change', 4],
];
for (const [event, template, count] of events) {
    const notifications = [1, 2].map(id => ({ source_id: id, reservation_id: id, attempt: 1,
        contact_type: 'email', contact: 'fixture@example.test', name: `Fixture ${id}`,
        reservation_date: '2037-01-15', reservation_time: '13:00', guests: id + 1,
        management_url: `https://example.test/manage/${id}`, access_expires_at: '2037-01-15T13:15:00-06:00' }));
    const input = { headers: { 'x-n8n-secret': 'fixture-secret' }, body: { schema_version: 1, event, notifications } };
    const validated = execute('Validar secreto y contrato', input)[0].json;
    assert.equal(validated.accepted, true);
    const batch = execute('Normalizar notificaciones', validated);
    assert.equal(batch.length, 2);
    for (const item of batch) {
        assert.equal(nodes['Construir mensaje'].parameters.mode, 'runOnceForEachItem');
        const message = execute('Construir mensaje', item.json).json;
        assert.equal(message.whatsapp_template, template);
        const parameters = message.whatsapp_components.component[0].bodyParameters.parameter;
        assert.equal(parameters.length, count);
        assert.deepEqual(parameters.map(p => p.text), [message.name, message.reservation_date, message.reservation_time,
            ...(count === 5 ? [String(message.guests)] : []), message.management_url]);
        assert.ok(message.email_text.includes('\n'));
        for (const name of ['Email entregado', 'Email fallido', 'Teléfono entregado', 'Teléfono fallido']) {
            assert.equal(nodes[name].parameters.mode, 'runOnceForEachItem');
            const callback = execute(name, {}, message).json;
            assert.deepEqual(callback, { event, source_id: message.source_id, attempt: 1,
                status: name.endsWith('fallido') ? 'failed' : 'delivered' });
        }
    }
    input.headers['x-n8n-secret'] = 'incorrect';
    assert.equal(execute('Validar secreto y contrato', input)[0].json.accepted, false);
    input.headers['x-n8n-secret'] = 'fixture-secret';
    input.body.schema_version = 2;
    assert.equal(execute('Validar secreto y contrato', input)[0].json.accepted, false);
}
assert.equal(nodes['Enviar WhatsApp'].parameters.operation, 'sendTemplate');
assert.equal(workflow.connections['Switch event'].main.length, 3);
console.log('Reservaciones: templates, lotes y callbacks del workflow OK');
