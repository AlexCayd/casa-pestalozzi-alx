const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..', '..');
const definitions = [
  ['reservaciones-confirmacion.json', 'Reservaciones - Confirmación'],
  ['reservaciones-recordatorio.json', 'Reservaciones - Recordatorio'],
  ['reservaciones-cambio-horario.json', 'Reservaciones - Cambio de horario'],
];

function load(filename) {
  const raw = fs.readFileSync(path.join(root, 'n8n', filename), 'utf8');
  const workflow = JSON.parse(raw);
  assert.equal(workflow.name, definitions.find(([name]) => name === filename)[1]);
  assert.equal(Object.prototype.hasOwnProperty.call(workflow, 'pinData'), false, `${filename}: pinData`);
  assert.equal(raw.includes('"credentials"'), false, `${filename}: credentials`);
  assert.equal(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i.test(raw), false, `${filename}: PII email`);
  assert.equal(/\+52\d{10}/.test(raw), false, `${filename}: PII WhatsApp`);
  for (const item of workflow.nodes) {
    assert.match(item.id, /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i, `${filename}: id de nodo importable`);
    if (item.webhookId) {
      assert.match(item.webhookId, /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i, `${filename}: webhookId importable`);
    }
  }
  assert.match(workflow.versionId, /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i, `${filename}: versionId importable`);
  return workflow;
}

function node(workflow, name) {
  const found = workflow.nodes.find((item) => item.name === name);
  assert.ok(found, `${workflow.name}: falta ${name}`);
  return found;
}

function runCode(code, json, env = {}) {
  return Function('$json', '$env', code)(json, env);
}

function basePayload(event, channel, attempt = 1) {
  return {
    schema_version: 1,
    event,
    source_id: 17,
    reservation_id: 23,
    attempt,
    contact: {
      type: channel,
      value: channel === 'email' ? 'fixture@example.test' : '+525500000001',
    },
    recipient: { name: 'Fixture' },
    reservation: { date: '2037-01-15', time: '18:00', guests: 2 },
    data: {
      purpose: event.replaceAll('.', '_'),
      confirmation_code: '123456',
      expires_at: '2037-01-14T18:05:00-06:00',
      management_url: 'https://example.test/reservacion/gestionar?access=fixture',
      access_expires_at: '2037-01-15T18:30:00-06:00',
    },
  };
}

function reachableNodes(workflow, start) {
  const pending = [start];
  const reached = new Set();
  while (pending.length > 0) {
    const current = pending.pop();
    if (reached.has(current)) continue;
    reached.add(current);
    for (const output of workflow.connections[current]?.main || []) {
      for (const connection of output || []) pending.push(connection.node);
    }
  }
  return reached;
}

function assertOnlyKeys(value, keys, label) {
  assert.deepEqual(Object.keys(value).sort(), [...keys].sort(), label);
}

function jsonReferences(parameters) {
  return [...JSON.stringify(parameters).matchAll(/\$json\.([A-Za-z_][A-Za-z0-9_]*)/g)]
    .map((match) => match[1])
    .filter((value, index, values) => values.indexOf(value) === index)
    .sort();
}

function assertChannelRouting(workflow, input, channel, label) {
  const switchNode = node(workflow, 'Switch channel');
  const rules = switchNode.parameters.rules.values;
  assert.equal(rules.length, 2, `${label}: Switch debe tener dos salidas`);
  assert.equal(rules[0].outputKey, 'email', `${label}: salida Email canónica`);
  assert.equal(rules[1].outputKey, 'whatsapp', `${label}: salida WhatsApp canónica`);
  assert.equal(rules[0].conditions.conditions[0].leftValue, '={{ $json.contact.type }}', `${label}: Switch Email usa contact.type`);
  assert.equal(rules[1].conditions.conditions[0].leftValue, '={{ $json.contact.type }}', `${label}: Switch WhatsApp usa contact.type`);
  assert.equal(rules[0].conditions.conditions[0].rightValue, 'email', `${label}: regla Email`);
  assert.equal(rules[1].conditions.conditions[0].rightValue, 'whatsapp', `${label}: regla WhatsApp`);
  assert.deepEqual(
    jsonReferences(switchNode.parameters),
    ['contact'],
    `${label}: Switch sólo depende de contact.type`,
  );

  const connections = workflow.connections['Switch channel'].main;
  assert.equal(connections.length, 2, `${label}: Switch conserva exactamente dos ramas`);
  assert.equal(connections[0][0].node, 'Normalizar Email', `${label}: rama Email`);
  assert.equal(connections[1][0].node, 'Normalizar WhatsApp', `${label}: rama WhatsApp`);
  assert.equal(workflow.connections['Normalizar Email'].main[0][0].node, 'Enviar Email', `${label}: Email llega a SMTP`);
  assert.equal(workflow.connections['Normalizar WhatsApp'].main[0][0].node, 'Enviar WhatsApp', `${label}: WhatsApp llega a WhatsApp`);
  assert.equal(reachableNodes(workflow, 'Normalizar Email').has('Enviar WhatsApp'), false, `${label}: rama Email no alcanza WhatsApp`);
  assert.equal(reachableNodes(workflow, 'Normalizar WhatsApp').has('Enviar Email'), false, `${label}: rama WhatsApp no alcanza SMTP`);

  const metadata = {
    schema_version: input.schema_version,
    event: input.event,
    source_id: input.source_id,
    reservation_id: input.reservation_id,
    attempt: input.attempt,
  };
  const normalizerName = channel === 'email' ? 'Normalizar Email' : 'Normalizar WhatsApp';
  const rejectedNormalizerName = channel === 'email' ? 'Normalizar WhatsApp' : 'Normalizar Email';
  const normalizerCode = node(workflow, normalizerName).parameters.jsCode;
  const normalized = runCode(normalizerCode, input);
  const expected = channel === 'email'
    ? {
      ...metadata,
      channel: 'email',
      to: input.contact.value,
      subject: input.email_subject,
      message: input.email_text,
      purpose: input.data.purpose,
    }
    : {
      ...metadata,
      channel: 'whatsapp',
      to: input.contact.value,
      template: input.whatsapp_template,
      components: input.whatsapp_components,
      purpose: input.data.purpose,
    };
  assert.deepEqual(normalized.json, expected, `${label}: contrato ${channel} normalizado`);
  assertOnlyKeys(normalized.json, Object.keys(expected), `${label}: contrato ${channel} cerrado`);
  assert.equal(normalizerCode.includes('...n'), false, `${label}: normalizador no propaga el payload común`);
  assert.equal(normalizerCode.includes('...$json'), false, `${label}: normalizador no propaga $json`);
  if (channel === 'email') {
    assert.equal(normalizerCode.includes('whatsapp_template'), false, `${label}: Email no depende del template WhatsApp`);
    assert.equal(normalizerCode.includes('whatsapp_components'), false, `${label}: Email no depende de componentes WhatsApp`);
  } else {
    assert.equal(normalizerCode.includes('email_subject'), false, `${label}: WhatsApp no depende del asunto Email`);
    assert.equal(normalizerCode.includes('email_text'), false, `${label}: WhatsApp no depende del texto Email`);
  }
  assert.deepEqual(
    runCode(node(workflow, rejectedNormalizerName).parameters.jsCode, input),
    [],
    `${label}: el canal opuesto rechaza el payload`,
  );

  const emailParameters = node(workflow, 'Enviar Email').parameters;
  assert.equal(emailParameters.toEmail, '={{ $json.to }}', `${label}: SMTP recipient aislado`);
  assert.equal(emailParameters.subject, '={{ $json.subject }}', `${label}: SMTP subject aislado`);
  assert.equal(emailParameters.text, '={{ $json.message }}', `${label}: SMTP body aislado`);
  assert.deepEqual(jsonReferences(emailParameters), ['message', 'subject', 'to'], `${label}: SMTP sólo consume contrato Email`);
  const whatsappParameters = node(workflow, 'Enviar WhatsApp').parameters;
  assert.equal(whatsappParameters.resource, 'message', `${label}: recurso WhatsApp existente`);
  assert.equal(whatsappParameters.operation, 'sendTemplate', `${label}: conserva envío por template`);
  assert.equal(whatsappParameters.recipientPhoneNumber, '={{ $json.to }}', `${label}: WhatsApp recipient aislado`);
  assert.equal(whatsappParameters.components, '={{ $json.components }}', `${label}: WhatsApp components aislados`);
  assert.deepEqual(jsonReferences(whatsappParameters), ['components', 'template', 'to'], `${label}: WhatsApp sólo consume su contrato`);
  assert.ok(!JSON.stringify(whatsappParameters).includes('email_subject'), `${label}: WhatsApp no usa asunto Email`);
  assert.ok(!JSON.stringify(whatsappParameters).includes('email_text'), `${label}: WhatsApp no usa texto Email`);
}

const workflows = new Map(definitions.map(([filename]) => [filename, load(filename)]));
for (const workflow of workflows.values()) {
  node(workflow, 'Switch channel');
  node(workflow, 'Normalizar Email');
  node(workflow, 'Normalizar WhatsApp');
  assert.equal(node(workflow, 'Enviar Email').type, 'n8n-nodes-base.emailSend');
  assert.equal(node(workflow, 'Enviar WhatsApp').type, 'n8n-nodes-base.whatsApp');
}

const confirmation = workflows.get('reservaciones-confirmacion.json');
assert.equal(confirmation.nodes.filter((item) => item.type === 'n8n-nodes-base.webhook').length, 1);
const confirmationValidator = node(confirmation, 'Validar secreto y contrato').parameters.jsCode;
for (const channel of ['email', 'whatsapp']) {
  const payload = basePayload('reservation.confirmation', channel);
  const result = runCode(confirmationValidator, {
    headers: { 'x-n8n-secret': 'fixture-secret' },
    body: payload,
  }, { N8N_SECRET: 'fixture-secret' });
  assert.equal(result.json.accepted, true, `confirmación ${channel}`);
  const prepared = runCode(node(confirmation, 'Preparar confirmación').parameters.jsCode, result.json);
  assert.equal(typeof prepared.json.email_subject, 'string');
  assert.equal(typeof prepared.json.whatsapp_template, 'string');
  assertChannelRouting(confirmation, prepared.json, channel, `confirmación ${channel}`);
}
const confirmationForbidden = runCode(confirmationValidator, {
  headers: { 'x-n8n-secret': 'wrong' },
  body: basePayload('reservation.confirmation', 'email'),
}, { N8N_SECRET: 'fixture-secret' });
assert.equal(confirmationForbidden.json.authorized, false, 'confirmación 403');
const confirmationInvalid = basePayload('reservation.confirmation', 'email');
confirmationInvalid.contact.type = 'telefono';
assert.equal(runCode(confirmationValidator, {
  headers: { 'x-n8n-secret': 'fixture-secret' },
  body: confirmationInvalid,
}, { N8N_SECRET: 'fixture-secret' }).json.valid, false, 'confirmación 422');
const contactAccess = basePayload('reservation.confirmation', 'whatsapp');
contactAccess.reservation_id = null;
contactAccess.reservation = null;
contactAccess.data = { purpose: 'contact_access', confirmation_code: '123456', expires_at: '2037-01-14T18:05:00-06:00' };
assert.equal(runCode(confirmationValidator, {
  headers: { 'x-n8n-secret': 'fixture-secret' },
  body: contactAccess,
}, { N8N_SECRET: 'fixture-secret' }).json.accepted, true, 'confirmación contact_access');

const reminder = workflows.get('reservaciones-recordatorio.json');
assert.equal(reminder.nodes.filter((item) => item.type === 'n8n-nodes-base.scheduleTrigger').length, 1);
const reminderValidator = node(reminder, 'Validar candidatos').parameters.jsCode;
for (const channel of ['email', 'whatsapp']) {
  const payload = basePayload('reservation.reminder', channel);
  const candidates = runCode(reminderValidator, {
    ok: true,
    due: true,
    event: 'reservation.reminder',
    notifications: [payload],
  });
  assert.equal(candidates.length, 1, `recordatorio ${channel}`);
  const prepared = runCode(node(reminder, 'Preparar recordatorio').parameters.jsCode, candidates[0].json);
  assert.equal(typeof prepared.json.email_subject, 'string');
  assert.equal(typeof prepared.json.whatsapp_template, 'string');
  assertChannelRouting(reminder, prepared.json, channel, `recordatorio ${channel}`);
}
const invalidReminder = basePayload('reservation.reminder', 'email');
invalidReminder.contact.value = 'invalid';
assert.equal(runCode(reminderValidator, {
  ok: true,
  due: true,
  event: 'reservation.reminder',
  notifications: [invalidReminder],
}).length, 0, 'recordatorio defensivo');

const schedule = workflows.get('reservaciones-cambio-horario.json');
assert.equal(schedule.nodes.filter((item) => item.type === 'n8n-nodes-base.webhook').length, 1);
const scheduleValidator = node(schedule, 'Validar secreto y contrato').parameters.jsCode;
for (const channel of ['email', 'whatsapp']) {
  for (const attempt of [1, 2]) {
    const payload = basePayload('reservation.schedule_change', channel, attempt);
    const result = runCode(scheduleValidator, {
      headers: { 'x-n8n-secret': 'fixture-secret' },
      body: payload,
    }, { N8N_SECRET: 'fixture-secret' });
    assert.equal(result.json.accepted, true, `cambio horario ${channel} attempt ${attempt}`);
    const prepared = runCode(node(schedule, 'Preparar cambio de horario').parameters.jsCode, result.json);
    assert.equal(typeof prepared.json.email_subject, 'string');
    assert.equal(typeof prepared.json.whatsapp_template, 'string');
    assertChannelRouting(schedule, prepared.json, channel, `cambio horario ${channel} attempt ${attempt}`);
  }
}
const attemptThree = basePayload('reservation.schedule_change', 'email', 3);
assert.equal(runCode(scheduleValidator, {
  headers: { 'x-n8n-secret': 'fixture-secret' },
  body: attemptThree,
}, { N8N_SECRET: 'fixture-secret' }).json.valid, false, 'attempt 3 bloqueado');

for (const workflow of [reminder, schedule]) {
  for (const channel of ['Email', 'WhatsApp']) {
    for (const status of ['delivered', 'failed']) {
      const code = node(workflow, `${channel} ${status}`).parameters.jsCode;
      assert.ok(code.includes(`channel: '${channel.toLowerCase()}'`));
      assert.ok(code.includes(`status: '${status}'`));
    }
  }
}

process.stdout.write('Workflows de reservaciones: seis canales, callbacks y validación defensiva OK\n');
