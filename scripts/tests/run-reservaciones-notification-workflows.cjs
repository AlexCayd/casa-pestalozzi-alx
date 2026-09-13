const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
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
  assert.equal(new Set(workflow.nodes.map((item) => item.id)).size, workflow.nodes.length, `${filename}: ids de nodo únicos`);
  assert.equal(new Set(workflow.nodes.map((item) => item.name)).size, workflow.nodes.length, `${filename}: nombres de nodo únicos`);
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

function basePayload(event, channel, attempt = 1, whatsappMode = 'text') {
  return {
    schema_version: 1,
    event,
    source_id: 17,
    reservation_id: 23,
    attempt,
    transport: { whatsapp_mode: whatsappMode },
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

function phpContractMode(environment) {
  const autoload = path.join(root, 'vendor', 'autoload.php').replaceAll('\\', '/').replaceAll("'", "\\'");
  const code = [
    `require '${autoload}';`,
    `$_ENV['APP_ENV'] = '${environment}';`,
    "$payload = \\Services\\Reservations\\Notifications\\ReservationNotificationContract::build('reservation.reminder', 1, 2, 1, 'email', 'fixture@example.test', 'Fixture', '2037-01-15', '18:00', 2, []);",
    "echo json_encode($payload['transport']);",
  ].join(' ');
  return JSON.parse(execFileSync('php', ['-r', code], { encoding: 'utf8' }));
}

function expectedWhatsAppText(input) {
  const name = String(input.recipient?.name || 'Cliente');
  const data = input.data || {};
  if (input.event === 'reservation.confirmation') {
    const code = String(data.confirmation_code || '');
    const expiry = data.expires_at ? `\n\nVence el ${data.expires_at}.` : '';
    return data.purpose === 'contact_access'
      ? `Hola ${name},\n\nTu código de acceso a Mis reservaciones es: ${code}.\n\nÚsalo para consultar y gestionar tus reservaciones.${expiry}`
      : `Hola ${name},\n\nTu código de confirmación de Casa Pestalozzi es: ${code}.\n\nÚsalo para confirmar tu reservación.${expiry}`;
  }
  if (input.event === 'reservation.reminder') {
    const reservation = input.reservation || {};
    const guests = Number(reservation.guests || 0);
    const people = guests === 1 ? 'persona' : 'personas';
    const management = data.management_url ? `\n\nPuedes revisar, modificar o cancelar tu reservación aquí:\n${data.management_url}` : '';
    const expiry = data.access_expires_at ? `\n\nEste acceso vence el ${data.access_expires_at}.` : '';
    return `Hola ${name},\n\nTe recordamos tu reservación en Casa Pestalozzi para el ${reservation.date} a las ${reservation.time}, para ${guests} ${people}.${management}${expiry}`;
  }
  const reservation = input.reservation || {};
  const management = data.management_url ? `\n\nElige otro horario o cancela tu reservación aquí:\n${data.management_url}` : '';
  const expiry = data.access_expires_at ? `\n\nEste acceso vence el ${data.access_expires_at}.` : '';
  return `Hola ${name},\n\nUn cambio en nuestro horario afecta tu reservación del ${reservation.date} a las ${reservation.time}.${management}${expiry}`;
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
  assert.equal(workflow.connections['Normalizar WhatsApp'].main[0][0].node, 'Resolver modo WhatsApp', `${label}: WhatsApp llega al selector de modo`);
  assert.equal(reachableNodes(workflow, 'Normalizar Email').has('Enviar WhatsApp Text'), false, `${label}: rama Email no alcanza WhatsApp Text`);
  assert.equal(reachableNodes(workflow, 'Normalizar Email').has('Enviar WhatsApp Template'), false, `${label}: rama Email no alcanza WhatsApp Template`);
  assert.equal(reachableNodes(workflow, 'Normalizar WhatsApp').has('Enviar Email'), false, `${label}: rama WhatsApp no alcanza SMTP`);

  const modeSwitch = node(workflow, 'Resolver modo WhatsApp');
  const modeRules = modeSwitch.parameters.rules.values;
  assert.equal(modeRules.length, 2, `${label}: selector WhatsApp tiene dos modos`);
  assert.equal(modeRules[0].outputKey, 'text', `${label}: salida text canónica`);
  assert.equal(modeRules[1].outputKey, 'template', `${label}: salida template canónica`);
  assert.equal(modeRules[0].conditions.conditions[0].leftValue, '={{ $json.mode }}', `${label}: selector usa mode`);
  assert.equal(modeRules[1].conditions.conditions[0].leftValue, '={{ $json.mode }}', `${label}: selector usa mode`);
  assert.equal(modeRules[0].conditions.conditions[0].rightValue, 'text', `${label}: regla text`);
  assert.equal(modeRules[1].conditions.conditions[0].rightValue, 'template', `${label}: regla template`);
  assert.deepEqual(jsonReferences(modeSwitch.parameters), ['mode'], `${label}: selector sólo depende de mode`);
  assert.ok(String(modeSwitch.notes || '').includes('transport.whatsapp_mode'), `${label}: selector documenta el origen del modo`);
  const modeConnections = workflow.connections['Resolver modo WhatsApp'].main;
  assert.equal(modeConnections[0][0].node, 'Enviar WhatsApp Text', `${label}: mode=text llega a Send`);
  assert.equal(modeConnections[1][0].node, 'Enviar WhatsApp Template', `${label}: mode=template llega a Send Template`);

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
      mode: input.transport.whatsapp_mode,
      to: input.contact.value,
      text: expectedWhatsAppText(input),
      template: input.whatsapp_template,
      components: input.whatsapp_components,
      purpose: input.data.purpose,
    };
  assert.deepEqual(normalized.json, expected, `${label}: contrato ${channel} normalizado`);
  assertOnlyKeys(normalized.json, Object.keys(expected), `${label}: contrato ${channel} cerrado`);
  assert.equal(normalizerCode.includes('...n'), false, `${label}: normalizador no propaga el payload común`);
  assert.equal(normalizerCode.includes('...$json'), false, `${label}: normalizador no propaga $json`);
  assert.equal(normalizerCode.includes('$env'), false, `${label}: normalizador no depende de variables de entorno n8n`);
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
  const whatsappTextParameters = node(workflow, 'Enviar WhatsApp Text').parameters;
  assert.equal(whatsappTextParameters.resource, 'message', `${label}: recurso WhatsApp Text`);
  assert.equal(whatsappTextParameters.operation, 'send', `${label}: TEST usa Message Send`);
  assert.equal(whatsappTextParameters.recipientPhoneNumber, '={{ $json.to }}', `${label}: Text recipient aislado`);
  assert.equal(whatsappTextParameters.textBody, '={{ $json.text }}', `${label}: Text body aislado`);
  assert.deepEqual(jsonReferences(whatsappTextParameters), ['text', 'to'], `${label}: Send Text sólo consume to/text`);
  assert.equal(JSON.stringify(whatsappTextParameters).includes('template'), false, `${label}: Send Text no consume template`);
  assert.equal(JSON.stringify(whatsappTextParameters).includes('components'), false, `${label}: Send Text no consume components`);
  assert.equal(JSON.stringify(whatsappTextParameters).includes('email_text'), false, `${label}: Send Text no consume Email`);

  const whatsappTemplateParameters = node(workflow, 'Enviar WhatsApp Template').parameters;
  assert.equal(whatsappTemplateParameters.resource, 'message', `${label}: recurso WhatsApp Template`);
  assert.equal(whatsappTemplateParameters.operation, 'sendTemplate', `${label}: producción conserva Send Template`);
  assert.equal(whatsappTemplateParameters.recipientPhoneNumber, '={{ $json.to }}', `${label}: Template recipient aislado`);
  assert.equal(whatsappTemplateParameters.components, '={{ $json.components }}', `${label}: Template components aislados`);
  assert.deepEqual(jsonReferences(whatsappTemplateParameters), ['components', 'template', 'to'], `${label}: Send Template sólo consume to/template/components`);
  assert.equal(JSON.stringify(whatsappTemplateParameters).includes('$json.text'), false, `${label}: Send Template no consume text`);
  assert.equal(JSON.stringify(whatsappTemplateParameters).includes('email_text'), false, `${label}: Send Template no consume Email`);

  if (channel === 'whatsapp') {
    const expectedTarget = input.transport.whatsapp_mode === 'text'
      ? 'Enviar WhatsApp Text'
      : 'Enviar WhatsApp Template';
    const modeIndex = input.transport.whatsapp_mode === 'text' ? 0 : 1;
    assert.equal(modeConnections[modeIndex][0].node, expectedTarget, `${label}: modo seleccionado llega al transporte correcto`);
  }
}

const workflows = new Map(definitions.map(([filename]) => [filename, load(filename)]));
assert.deepEqual(phpContractMode('development'), { whatsapp_mode: 'text' }, 'PHP development prepara modo text sin transportar');
assert.deepEqual(phpContractMode('test'), { whatsapp_mode: 'text' }, 'PHP TEST selecciona WhatsApp Text');
assert.deepEqual(phpContractMode('production'), { whatsapp_mode: 'template' }, 'PHP production selecciona WhatsApp Template');
for (const workflow of workflows.values()) {
  node(workflow, 'Switch channel');
  node(workflow, 'Normalizar Email');
  node(workflow, 'Normalizar WhatsApp');
  node(workflow, 'Resolver modo WhatsApp');
  assert.equal(node(workflow, 'Enviar Email').type, 'n8n-nodes-base.emailSend');
  assert.equal(node(workflow, 'Enviar WhatsApp Text').type, 'n8n-nodes-base.whatsApp');
  assert.equal(node(workflow, 'Enviar WhatsApp Template').type, 'n8n-nodes-base.whatsApp');
}

const confirmation = workflows.get('reservaciones-confirmacion.json');
assert.equal(confirmation.nodes.filter((item) => item.type === 'n8n-nodes-base.webhook').length, 1);
const confirmationValidator = node(confirmation, 'Validar secreto y contrato').parameters.jsCode;
for (const [channel, mode] of [['email', 'text'], ['whatsapp', 'text'], ['whatsapp', 'template']]) {
  const payload = basePayload('reservation.confirmation', channel, 1, mode);
  const result = runCode(confirmationValidator, {
    headers: { 'x-n8n-secret': 'fixture-secret' },
    body: payload,
  }, { N8N_SECRET: 'fixture-secret' });
  assert.equal(result.json.accepted, true, `confirmación ${channel} ${mode}`);
  const prepared = runCode(node(confirmation, 'Preparar confirmación').parameters.jsCode, result.json);
  assert.equal(typeof prepared.json.email_subject, 'string');
  assert.equal(typeof prepared.json.whatsapp_template, 'string');
  assertChannelRouting(confirmation, prepared.json, channel, `confirmación ${channel} ${mode}`);
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
for (const mode of ['text', 'template']) {
  const contactAccess = basePayload('reservation.confirmation', 'whatsapp', 1, mode);
  contactAccess.reservation_id = null;
  contactAccess.reservation = null;
  contactAccess.data = { purpose: 'contact_access', confirmation_code: '123456', expires_at: '2037-01-14T18:05:00-06:00' };
  const result = runCode(confirmationValidator, {
    headers: { 'x-n8n-secret': 'fixture-secret' },
    body: contactAccess,
  }, { N8N_SECRET: 'fixture-secret' });
  assert.equal(result.json.accepted, true, `confirmación contact_access ${mode}`);
  const prepared = runCode(node(confirmation, 'Preparar confirmación').parameters.jsCode, result.json);
  assertChannelRouting(confirmation, prepared.json, 'whatsapp', `contact_access whatsapp ${mode}`);
  const normalized = runCode(node(confirmation, 'Normalizar WhatsApp').parameters.jsCode, prepared.json);
  assert.ok(normalized.json.text.includes('Mis reservaciones'), `contact_access ${mode}: texto funcional propio`);
}
const confirmationInvalidMode = basePayload('reservation.confirmation', 'whatsapp', 1, 'invalid');
assert.equal(runCode(confirmationValidator, {
  headers: { 'x-n8n-secret': 'fixture-secret' },
  body: confirmationInvalidMode,
}, { N8N_SECRET: 'fixture-secret' }).json.valid, false, 'confirmación rechaza modo WhatsApp inválido');

const reminder = workflows.get('reservaciones-recordatorio.json');
assert.equal(reminder.nodes.filter((item) => item.type === 'n8n-nodes-base.scheduleTrigger').length, 1);
const reminderValidator = node(reminder, 'Validar candidatos').parameters.jsCode;
for (const [channel, mode] of [['email', 'text'], ['whatsapp', 'text'], ['whatsapp', 'template']]) {
  const payload = basePayload('reservation.reminder', channel, 1, mode);
  const candidates = runCode(reminderValidator, {
    ok: true,
    due: true,
    event: 'reservation.reminder',
    notifications: [payload],
  });
  assert.equal(candidates.length, 1, `recordatorio ${channel} ${mode}`);
  const prepared = runCode(node(reminder, 'Preparar recordatorio').parameters.jsCode, candidates[0].json);
  assert.equal(typeof prepared.json.email_subject, 'string');
  assert.equal(typeof prepared.json.whatsapp_template, 'string');
  assertChannelRouting(reminder, prepared.json, channel, `recordatorio ${channel} ${mode}`);
}
const invalidReminder = basePayload('reservation.reminder', 'email');
invalidReminder.contact.value = 'invalid';
assert.equal(runCode(reminderValidator, {
  ok: true,
  due: true,
  event: 'reservation.reminder',
  notifications: [invalidReminder],
}).length, 0, 'recordatorio defensivo');
const invalidReminderMode = basePayload('reservation.reminder', 'whatsapp', 1, 'invalid');
assert.equal(runCode(reminderValidator, {
  ok: true,
  due: true,
  event: 'reservation.reminder',
  notifications: [invalidReminderMode],
}).length, 0, 'recordatorio rechaza modo WhatsApp inválido');

const schedule = workflows.get('reservaciones-cambio-horario.json');
assert.equal(schedule.nodes.filter((item) => item.type === 'n8n-nodes-base.webhook').length, 1);
const scheduleValidator = node(schedule, 'Validar secreto y contrato').parameters.jsCode;
for (const [channel, mode] of [['email', 'text'], ['whatsapp', 'text'], ['whatsapp', 'template']]) {
  for (const attempt of [1, 2]) {
    const payload = basePayload('reservation.schedule_change', channel, attempt, mode);
    const result = runCode(scheduleValidator, {
      headers: { 'x-n8n-secret': 'fixture-secret' },
      body: payload,
    }, { N8N_SECRET: 'fixture-secret' });
    assert.equal(result.json.accepted, true, `cambio horario ${channel} ${mode} attempt ${attempt}`);
    const prepared = runCode(node(schedule, 'Preparar cambio de horario').parameters.jsCode, result.json);
    assert.equal(typeof prepared.json.email_subject, 'string');
    assert.equal(typeof prepared.json.whatsapp_template, 'string');
    assertChannelRouting(schedule, prepared.json, channel, `cambio horario ${channel} ${mode} attempt ${attempt}`);
  }
}
const attemptThree = basePayload('reservation.schedule_change', 'email', 3);
assert.equal(runCode(scheduleValidator, {
  headers: { 'x-n8n-secret': 'fixture-secret' },
  body: attemptThree,
}, { N8N_SECRET: 'fixture-secret' }).json.valid, false, 'attempt 3 bloqueado');
const invalidScheduleMode = basePayload('reservation.schedule_change', 'whatsapp', 1, 'invalid');
assert.equal(runCode(scheduleValidator, {
  headers: { 'x-n8n-secret': 'fixture-secret' },
  body: invalidScheduleMode,
}, { N8N_SECRET: 'fixture-secret' }).json.valid, false, 'cambio horario rechaza modo WhatsApp inválido');

for (const workflow of [reminder, schedule]) {
  for (const transport of ['Enviar WhatsApp Text', 'Enviar WhatsApp Template']) {
    const connections = workflow.connections[transport].main;
    assert.equal(connections[0][0].node, 'WhatsApp delivered', `${workflow.name}: ${transport} conserva callback delivered`);
    assert.equal(connections[1][0].node, 'WhatsApp failed', `${workflow.name}: ${transport} conserva callback failed`);
  }
  for (const channel of ['Email', 'WhatsApp']) {
    for (const status of ['delivered', 'failed']) {
      const code = node(workflow, `${channel} ${status}`).parameters.jsCode;
      assert.ok(code.includes(`channel: '${channel.toLowerCase()}'`));
      assert.ok(code.includes(`status: '${status}'`));
    }
  }
}

process.stdout.write('Workflows de reservaciones: Email, WhatsApp Text/Template, callbacks y validación defensiva OK\n');
