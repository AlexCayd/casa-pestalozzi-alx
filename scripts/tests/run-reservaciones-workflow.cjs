const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const workflowPath = path.join(__dirname, '../../n8n/reservaciones-comunicaciones.json');
const workflowRaw = fs.readFileSync(workflowPath, 'utf8');
const workflow = JSON.parse(workflowRaw);
const nodes = Object.fromEntries(workflow.nodes.map((node) => [node.name, node]));
const events = [
    'reservation.confirmed',
    'reservation.schedule_change',
    'reservation.reminder_next_day',
];

function node(name) {
    assert.ok(nodes[name], `workflow contiene ${name}`);
    return nodes[name];
}

function execute(name, data, source = {}) {
    const code = node(name).parameters.jsCode;
    return new Function('$json', '$env', '$', code)(
        data,
        { N8N_SECRET: 'fixture-secret' },
        () => ({ item: { json: source } }),
    );
}

function notification(id, contactType = 'email') {
    return {
        source_id: id,
        reservation_id: id,
        attempt: 1,
        contact_type: contactType,
        contact: contactType === 'email' ? 'fixture@example.test' : '+5215555555555',
        name: `Fixture ${id}`,
        reservation_date: '2037-01-15',
        reservation_time: '13:00',
        guests: id + 1,
        management_url: `https://example.test/manage/${id}`,
        access_expires_at: '2037-01-15T13:15:00-06:00',
    };
}

function connectionsFrom(name, output = 0) {
    return (workflow.connections[name] && workflow.connections[name].main || [])[output] || [];
}

assert.equal(workflow.name, 'Reservaciones - comunicaciones');
assert.equal(workflowRaw.includes('"credentials"'), false, 'workflow no versiona credenciales');
assert.equal(workflowRaw.includes('"pinData"'), false, 'workflow no versiona pinData');
assert.equal(/twilio|RESERVATION_PHONE_FROM|n8n-nodes-base\.twilio/i.test(workflowRaw), false,
    'workflow de reservaciones no conserva Twilio');
assert.match(workflowRaw, /n8n-nodes-base\.whatsApp/);

const validator = node('Validar secreto y contrato');
const normalizer = node('Normalizar notificaciones');
assert.ok(validator.parameters.jsCode.includes("'reservation.confirmed'"));
assert.ok(normalizer.parameters.jsCode.includes("'reservation.confirmed'"));
assert.equal(node('Cada cinco minutos').parameters.rule.interval[0].minutesInterval, 5,
    'Schedule Trigger conserva cinco minutos');
assert.match(node('Preparar recordatorios').parameters.url, /recordatorios\/preparar/);

for (const event of events) {
    const notifications = [notification(1), notification(2)];
    const input = {
        headers: { 'x-n8n-secret': 'fixture-secret' },
        body: { schema_version: 1, event, notifications },
    };
    const validated = execute('Validar secreto y contrato', input)[0].json;
    assert.equal(validated.valid, true, `${event}: contrato válido`);
    assert.equal(validated.authorized, true, `${event}: secreto válido`);
    assert.equal(validated.accepted, true, `${event}: request aceptado`);

    const batch = execute('Normalizar notificaciones', validated);
    assert.equal(batch.length, 2, `${event}: normalización conserva el lote`);
    assert.deepEqual(batch.map((item) => item.json.event), [event, event]);

    for (const item of batch) {
        assert.equal(node('Construir mensaje').parameters.mode, 'runOnceForEachItem');
        const message = execute('Construir mensaje', item.json).json;
        const expectedTemplate = event === 'reservation.confirmed'
            ? 'reservation_confirmation'
            : event === 'reservation.reminder_next_day'
                ? 'reservation_reminder'
                : 'reservation_schedule_change';
        const expectedCount = event === 'reservation.schedule_change' ? 4 : 5;
        assert.equal(message.whatsapp_template, expectedTemplate);
        const parameters = message.whatsapp_components.component[0].bodyParameters.parameter;
        assert.equal(parameters.length, expectedCount);
        assert.deepEqual(parameters.map((parameter) => parameter.text), [
            message.name,
            message.reservation_date,
            message.reservation_time,
            ...(expectedCount === 5 ? [String(message.guests)] : []),
            message.management_url,
        ]);
        assert.ok(message.email_text.includes('\n'));

        for (const callbackNode of ['Email entregado', 'Email fallido', 'Teléfono entregado', 'Teléfono fallido']) {
            assert.equal(node(callbackNode).parameters.mode, 'runOnceForEachItem');
            const callback = execute(callbackNode, {}, message).json;
            assert.deepEqual(callback, {
                event,
                source_id: message.source_id,
                attempt: 1,
                status: callbackNode.endsWith('fallido') ? 'failed' : 'delivered',
            });
        }
    }
}

const reproduced = execute('Validar secreto y contrato', {
    headers: { 'x-n8n-secret': 'fixture-secret' },
    body: {
        schema_version: 1,
        event: 'reservation.confirmed',
        notifications: [notification(999, 'telefono')],
    },
})[0].json;
const reproducedBatch = execute('Normalizar notificaciones', reproduced);
assert.equal(reproducedBatch.length, 1, 'smoke de reservation.confirmed produce un item');
assert.equal(reproducedBatch[0].json.source_id, 999);
assert.equal(reproducedBatch[0].json.reservation_id, 999);
assert.equal(reproducedBatch[0].json.contact_type, 'telefono');
assert.equal(reproducedBatch[0].json.event, 'reservation.confirmed');

const invalidSecret = {
    headers: { 'x-n8n-secret': 'wrong-secret' },
    body: { schema_version: 1, event: 'reservation.confirmed', notifications: [notification(1)] },
};
const rejected = execute('Validar secreto y contrato', invalidSecret)[0].json;
assert.equal(rejected.valid, true, 'un smoke con secreto incorrecto conserva contrato válido');
assert.equal(rejected.authorized, false, 'secreto incorrecto no se autoriza');
assert.equal(execute('Normalizar notificaciones', rejected).length, 0,
    'secreto incorrecto no llega al transporte');

const malformed = execute('Validar secreto y contrato', {
    headers: { 'x-n8n-secret': 'fixture-secret' },
    body: { schema_version: 1, event: 'reservation.confirmed', notifications: [{ source_id: 1 }] },
})[0].json;
assert.equal(malformed.valid, false, 'el contrato rechaza notificaciones incompletas');
assert.equal(execute('Normalizar notificaciones', malformed).length, 0,
    'notificación incompleta no se normaliza');

const scheduled = {
    ok: true,
    due: true,
    event: 'reservation.reminder_next_day',
    notifications: [notification(1, 'telefono')],
};
assert.equal(execute('Normalizar notificaciones', scheduled).length, 1,
    'scheduler válido entra por la misma normalización');

const switchRules = node('Switch event').parameters.rules.values.map(
    (rule) => rule.conditions.conditions[0].rightValue,
);
assert.deepEqual([...switchRules].sort(), [...events].sort(), 'Switch event tiene exactamente tres rutas');
assert.equal(connectionsFrom('Switch event').length, 1);
assert.equal(connectionsFrom('Switch event', 1).length, 1);
assert.equal(connectionsFrom('Switch event', 2).length, 1);
for (const output of [0, 1, 2]) {
    assert.equal(connectionsFrom('Switch event', output)[0].node, 'Construir mensaje');
}

assert.equal(node('Enviar WhatsApp').type, 'n8n-nodes-base.whatsApp');
assert.equal(node('Enviar WhatsApp').parameters.resource, 'message');
assert.equal(node('Enviar WhatsApp').parameters.operation, 'sendTemplate');
assert.equal(node('Enviar WhatsApp').parameters.phoneNumberId, '={{ $env.RESERVATION_WHATSAPP_PHONE_NUMBER_ID }}');
assert.match(node('Enviar WhatsApp').parameters.template, /RESERVATION_WHATSAPP_TEMPLATE_LANGUAGE/);
assert.equal(node('Enviar WhatsApp').parameters.components, '={{ $json.whatsapp_components }}');

const requiredEdges = [
    ['Webhook reservaciones', 'Validar secreto y contrato'],
    ['Validar secreto y contrato', 'Responder temprano'],
    ['Responder temprano', 'Normalizar notificaciones'],
    ['Normalizar notificaciones', 'Switch event'],
    ['Construir mensaje', 'Elegir canal'],
    ['Elegir canal', 'Enviar email'],
    ['Elegir canal', 'Enviar WhatsApp'],
    ['Email entregado', 'Registrar resultado'],
    ['Email fallido', 'Registrar resultado'],
    ['Teléfono entregado', 'Registrar resultado'],
    ['Teléfono fallido', 'Registrar resultado'],
    ['Enviar email', 'Email entregado'],
    ['Enviar WhatsApp', 'Teléfono entregado'],
];
for (const [from, to] of requiredEdges) {
    assert.ok(Object.values(workflow.connections[from] || {}).some((outputs) =>
        outputs.flat().some((connection) => connection.node === to)), `${from} conecta con ${to}`);
}

console.log('Reservaciones: contrato, tres eventos, canales, conexiones y callbacks del workflow OK');
