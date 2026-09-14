const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

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

// Execute the exported source, with no filesystem, network, process or environment access.
function runCode(code, json, bindings = {}) {
  const sandbox = { $json: json, ...bindings };
  Object.defineProperty(sandbox, '$env', { get() { throw new Error('Environment access blocked'); } });
  const result = vm.runInNewContext('(function () {\n' + code + '\n})()', sandbox, { timeout: 1000 });
  return result === undefined ? undefined : JSON.parse(JSON.stringify(result));
}

function expression(value, json, bindings = {}) {
  if (typeof value !== 'string' || !value.startsWith('=')) return value;
  assert.ok(value.startsWith('={{') && value.endsWith('}}'), 'supported n8n expression');
  return runCode('return (' + value.slice(3, -2).trim() + ');', json, bindings);
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
    null,
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

const transports = ['Enviar Email', 'Enviar WhatsApp Text', 'Enviar WhatsApp Template'];
const routes = [['email', 'text'], ['whatsapp', 'text'], ['whatsapp', 'template']];
const configNames = [
  'RESERVATION_APP_BASE_URL', 'RESERVATION_EMAIL_FROM',
  'RESERVATION_WHATSAPP_PHONE_NUMBER_ID', 'RESERVATION_WHATSAPP_TEMPLATE_LANGUAGE',
];
const exercisedCode = new Set();
let scenarios = 0;

function incoming(workflow, target) {
  const edges = [];
  for (const [source, outputs] of Object.entries(workflow.connections)) {
    outputs.main.forEach((branch, output) => {
      for (const connection of branch) {
        if (connection.node === target) edges.push([source, output]);
      }
    });
  }
  return edges.sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b)));
}

function assertStructure(workflow) {
  const raw = JSON.stringify(workflow);
  assert.equal(/\$env\b|process\.env|Deno\.env/.test(raw), false, workflow.name + ': entorno bloqueado');
  assert.equal(raw.includes('delivered'), false, 'sólo aceptación del proveedor');
  assert.equal(workflow.active, false, 'importación inactiva hasta asignar credenciales');
  assert.equal(workflow.settings.saveDataSuccessExecution, 'none');
  assert.equal(workflow.settings.saveDataErrorExecution, 'none');
  assert.equal(workflow.settings.saveManualExecutions, false);
  assert.equal(workflow.settings.saveExecutionProgress, false);
  assert.equal(workflow.nodes.some((n) => /executeWorkflow|httpRequestTool|mysql/i.test(n.type)), false, 'workflow independiente');
  const trigger = workflow.nodes[0];
  assert.equal(reachableNodes(workflow, trigger.name).size, workflow.nodes.length, 'sin nodos huérfanos');
  for (const [source, outputs] of Object.entries(workflow.connections)) {
    node(workflow, source);
    for (const branch of outputs.main) {
      for (const edge of branch) {
        node(workflow, edge.node);
        assert.equal(edge.type, 'main');
        assert.equal(edge.index, 0);
      }
    }
  }

  const configuration = node(workflow, 'Configuración');
  assert.equal(configuration.type, 'n8n-nodes-base.set');
  assert.equal(configuration.typeVersion, 3.4);
  assert.equal(configuration.parameters.mode, 'manual');
  assert.equal(configuration.parameters.includeOtherFields, true, 'Set conserva el body del webhook');
  const assignments = configuration.parameters.assignments.assignments;
  assert.deepEqual(assignments.map((a) => a.name), configNames);
  for (const field of assignments) {
    assert.equal(field.type, 'string');
    assert.equal(typeof field.value, 'string');
    assert.equal(field.value.startsWith('='), false, 'configuración fija, no leída del caller');
  }
  assert.equal(workflow.connections[trigger.name].main[0][0].node, 'Configuración');
  assert.equal(workflow.nodes.filter((n) => n.type === 'n8n-nodes-base.emailSend').length, 1);
  assert.equal(workflow.nodes.filter((n) => n.type === 'n8n-nodes-base.whatsApp').length, 2);
  for (const name of transports) {
    const send = node(workflow, name);
    assert.equal(send.onError, 'continueErrorOutput', name + ': error separado');
    assert.notEqual(send.continueOnFail, true);
    assert.notEqual(send.retryOnFail, true, 'sin reenvíos automáticos del proveedor');
    assert.equal(workflow.connections[name].main.length, 2, 'éxito y error conectados');
  }
  assert.equal(node(workflow, 'Enviar Email').parameters.fromEmail, "={{ $('Configuración').first().json.RESERVATION_EMAIL_FROM }}");
  for (const name of transports.slice(1)) {
    assert.equal(node(workflow, name).parameters.phoneNumberId, "={{ $('Configuración').first().json.RESERVATION_WHATSAPP_PHONE_NUMBER_ID }}");
  }
  assert.equal(node(workflow, 'Enviar WhatsApp Template').parameters.template,
    "={{ $json.template + '|' + $('Configuración').first().json.RESERVATION_WHATSAPP_TEMPLATE_LANGUAGE }}");

  // Native auth configuration is checked here; credential rejection requires an n8n integration check.
  for (const webhook of workflow.nodes.filter((n) => n.type === 'n8n-nodes-base.webhook')) {
    assert.equal(webhook.parameters.authentication, 'headerAuth', 'autenticación nativa antes del Code');
    assert.equal(webhook.parameters.httpMethod, 'POST');
    assert.equal(webhook.parameters.responseMode, 'responseNode');
    assert.equal(webhook.parameters.options?.rawBody, undefined);
    assert.equal(/headers|secret|authorized|\$env/i.test(node(workflow, 'Validar contrato').parameters.jsCode), false, 'Code sólo valida contrato');
  }
  for (const http of workflow.nodes.filter((n) => n.type === 'n8n-nodes-base.httpRequest')) {
    assert.equal(http.parameters.authentication, 'genericCredentialType');
    assert.equal(http.parameters.genericAuthType, 'httpHeaderAuth');
    assert.equal(http.parameters.method, 'POST');
    assert.ok(http.parameters.options.timeout > 0);
    assert.equal(http.parameters.headerParameters.parameters.some((h) => /secret|authorization|api.key/i.test(h.name)), false);
    assert.match(http.parameters.url, /\$\('Configuración'\)\.first\(\)\.json\.RESERVATION_APP_BASE_URL/);
  }
  if (workflow.name === 'Reservaciones - Confirmación') {
    assert.equal(workflow.nodes.some((n) => n.type === 'n8n-nodes-base.httpRequest'), false, 'confirmación sin callback');
    assert.equal(workflow.nodes.some((n) => n.name === 'Responder 202'), false);
    for (const [target, output] of [['Responder 200', 0], ['Responder 502', 1]]) {
      assert.deepEqual(incoming(workflow, target), transports.map((name) => [name, output]).sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b))));
      assert.equal(workflow.connections[target], undefined, 'respuesta síncrona terminal');
    }
  } else {
    for (const name of transports) {
      const channel = name === 'Enviar Email' ? 'Email' : 'WhatsApp';
      assert.deepEqual(workflow.connections[name].main.map((branch) => branch.map((e) => e.node)), [
        [channel + ' accepted'], [channel + ' failed'],
      ]);
    }
    for (const channel of ['Email', 'WhatsApp']) {
      for (const status of ['accepted', 'failed']) {
        assert.equal(workflow.connections[channel + ' ' + status].main[0][0].node, 'Registrar resultado');
      }
    }
    assert.equal(node(workflow, 'Registrar resultado').parameters.jsonBody, '={{ $json }}');
    assert.equal(workflow.connections['Registrar resultado'], undefined, 'fallo de callback nunca reenvía');
  }
  if (trigger.type === 'n8n-nodes-base.webhook') {
    assert.deepEqual(incoming(workflow, 'Responder 422'), [['Contrato válido', 1]]);
    assert.equal(workflow.connections['Responder 422'], undefined, '422 no envía');
  } else {
    assert.deepEqual(trigger.parameters.rule.interval, [{ field: 'minutes', minutesInterval: 5 }]);
    assert.ok(node(workflow, 'Preparar recordatorios').parameters.url.includes('/recordatorios/preparar'));
    assert.equal(node(workflow, 'Reclamar recordatorio').retryOnFail, false);
  }
}

for (const workflow of workflows.values()) assertStructure(workflow);
const confirmation = workflows.get('reservaciones-confirmacion.json');
const reminder = workflows.get('reservaciones-recordatorio.json');
const schedule = workflows.get('reservaciones-cambio-horario.json');
for (const workflow of [reminder, schedule]) {
  for (const name of ['Configuración', ...transports]) {
    assert.deepEqual(node(workflow, name).parameters, node(confirmation, name).parameters, 'paridad de ' + name);
  }
  assert.equal(node(workflow, 'Normalizar Email').parameters.jsCode, node(confirmation, 'Normalizar Email').parameters.jsCode);
}

// Traverse the actual JSON graph, executing Code and expressions. Only native I/O is stubbed.
// Real SMTP/Meta calls, auth rejection, server timeouts and import compatibility require n8n.
function simulate(workflow, input, { failure = null, prepareResponse = null, callbackFailure = false, claimed = true } = {}) {
  const queue = [{ name: workflow.nodes[0].name, json: input, history: {}, trace: [] }];
  const firstItems = new Map();
  const result = { responses: [], sends: [], callbacks: [], visited: [], timeline: [] };
  let steps = 0;
  while (queue.length) {
    assert.ok(++steps < 200, 'grafo sin ciclos');
    const task = queue.shift();
    const current = node(workflow, task.name);
    const p = current.parameters;
    const trace = [...task.trace, task.name];
    result.visited.push(task.name);
    const bindings = {
      $: (name) => {
        assert.ok(task.history[name], task.name + ': referencia enlazada ' + name);
        return { item: { json: task.history[name] }, first: () => ({ json: firstItems.get(name) }) };
      },
      $input: { first: () => ({ json: task.json }) },
    };
    const evaluate = (value) => expression(value, task.json, bindings);
    let output = 0;
    let items = [{ json: task.json }];
    switch (current.type) {
      case 'n8n-nodes-base.webhook':
      case 'n8n-nodes-base.scheduleTrigger':
        break;
      case 'n8n-nodes-base.set': {
        const json = p.includeOtherFields ? { ...task.json } : {};
        for (const assignment of p.assignments.assignments) json[assignment.name] = evaluate(assignment.value);
        items = [{ json }];
        break;
      }
      case 'n8n-nodes-base.code': {
        const returned = runCode(p.jsCode, task.json, bindings);
        exercisedCode.add(workflow.name + ':' + task.name);
        if (p.mode === 'runOnceForEachItem') {
          assert.ok(returned === null || (returned && !Array.isArray(returned) && returned.json), task.name + ': retorno válido each-item');
          items = returned === null ? [] : [returned];
        } else {
          assert.ok(Array.isArray(returned), task.name + ': retorno all-items');
          items = returned;
          for (const item of items) assert.deepEqual(item.pairedItem, { item: 0 }, 'candidatos conservan vínculo al input');
        }
        break;
      }
      case 'n8n-nodes-base.if': {
        const condition = p.conditions.conditions[0];
        assert.equal(condition.operator.operation, 'true');
        output = evaluate(condition.leftValue) === true ? 0 : 1;
        break;
      }
      case 'n8n-nodes-base.switch': {
        const matches = p.rules.values.flatMap((rule, index) => {
          const condition = rule.conditions.conditions[0];
          assert.equal(condition.operator.operation, 'equals');
          return evaluate(condition.leftValue) === condition.rightValue ? [index] : [];
        });
        assert.equal(matches.length, 1, task.name + ': exactamente una rama válida');
        output = matches[0];
        break;
      }
      case 'n8n-nodes-base.respondToWebhook':
        result.responses.push({ code: evaluate(p.options.responseCode), body: evaluate(p.responseBody), trace });
        result.timeline.push('response');
        break;
      case 'n8n-nodes-base.emailSend':
      case 'n8n-nodes-base.whatsApp': {
        const parameters = Object.fromEntries(Object.entries(p).map(([key, value]) => [key, evaluate(value)]));
        result.sends.push({ name: task.name, json: task.json, parameters, trace });
        result.timeline.push('send');
        output = failure ? 1 : 0;
        // Deliberately unrelated provider metadata must not replace source/attempt/channel.
        items = [{ json: failure
          ? { error: { message: 'private provider details', code: failure }, source_id: 9999 }
          : { messageId: 'smtp-fixture', messages: [{ id: 'wa-fixture' }], source_id: 9999, channel: 'wrong' } }];
        break;
      }
      case 'n8n-nodes-base.httpRequest':
        if (task.name === 'Preparar recordatorios') {
          assert.ok(prepareResponse);
          items = [{ json: prepareResponse }];
        } else if (task.name === 'Reclamar recordatorio') {
          items = [{ json: { ok: true, claimed } }];
        } else {
          assert.equal(task.name, 'Registrar resultado');
          result.callbacks.push({ body: evaluate(p.jsonBody), url: evaluate(p.url), trace });
          result.timeline.push('callback');
          if (callbackFailure) items = [];
        }
        break;
      default:
        assert.fail('Unsupported node: ' + current.type);
    }
    if (items.length && !firstItems.has(task.name)) firstItems.set(task.name, items[0].json);
    for (const item of items) {
      const history = { ...task.history, [task.name]: item.json };
      for (const edge of workflow.connections[task.name]?.main[output] || []) {
        queue.push({ name: edge.node, json: item.json, history, trace });
      }
    }
  }
  scenarios++;
  return result;
}

function inputFor(workflow, payload) {
  if (workflow === reminder) {
    return [{}, { prepareResponse: { ok: true, due: true, event: 'reservation.reminder', notifications: [payload] } }];
  }
  return [{ body: payload, RESERVATION_EMAIL_FROM: 'caller-controlled', RESERVATION_APP_BASE_URL: 'https://caller.invalid' }, {}];
}

function checkResult(workflow, payload, failure = null, callbackFailure = false) {
  const [input, options] = inputFor(workflow, payload);
  const result = simulate(workflow, input, { ...options, failure, callbackFailure });
  assert.equal(result.sends.length, 1, 'un solo intento de transporte');
  const send = result.sends[0];
  const target = payload.contact.type === 'email' ? transports[0] : payload.transport.whatsapp_mode === 'text' ? transports[1] : transports[2];
  assert.equal(send.name, target);
  const prepareName = workflow === confirmation ? 'Preparar confirmación' : workflow === reminder ? 'Preparar recordatorio' : 'Preparar cambio de horario';
  const preparedInput = workflow === reminder ? payload : { valid: true, notification: payload };
  const prepared = runCode(node(workflow, prepareName).parameters.jsCode, preparedInput).json;
  assertChannelRouting(workflow, prepared, payload.contact.type, workflow.name + ' ' + target);
  const configured = Object.fromEntries(node(workflow, 'Configuración').parameters.assignments.assignments.map((a) => [a.name, a.value]));
  if (payload.contact.type === 'email') {
    assert.equal(send.parameters.fromEmail, configured.RESERVATION_EMAIL_FROM);
  } else {
    assert.equal(send.parameters.phoneNumberId, configured.RESERVATION_WHATSAPP_PHONE_NUMBER_ID);
    if (payload.transport.whatsapp_mode === 'template') {
      assert.equal(send.parameters.template, prepared.whatsapp_template + '|' + configured.RESERVATION_WHATSAPP_TEMPLATE_LANGUAGE);
      assert.deepEqual(send.parameters.components, prepared.whatsapp_components);
    }
  }
  if (workflow === confirmation) {
    assert.equal(result.responses.length, 1);
    assert.deepEqual(result.timeline, ['send', 'response'], 'confirmación espera al proveedor');
    assert.deepEqual(result.responses[0].body, failure
      ? { ok: false, accepted: false, error: 'TRANSPORT_FAILED' }
      : { ok: true, accepted: true, channel: payload.contact.type });
    assert.equal(result.responses[0].code, failure ? 502 : 200);
    assert.ok(result.responses[0].trace.includes(target));
    assert.equal(result.callbacks.length, 0, 'confirmación nunca hace callback');
  } else {
    assert.equal(result.callbacks.length, 1);
    assert.deepEqual(result.callbacks[0].body, {
      event: payload.event, source_id: payload.source_id, attempt: payload.attempt,
      channel: payload.contact.type, status: failure ? 'failed' : 'accepted',
      ...(workflow === reminder && failure ? { retryable: false } : {}),
    });
    assert.equal(result.callbacks[0].url, configured.RESERVATION_APP_BASE_URL + '/api/integraciones/n8n/reservaciones/notificacion-resultado');
    if (workflow === schedule) {
      assert.equal(result.responses.length, 1);
      assert.equal(result.responses[0].code, 202);
      assert.deepEqual(result.responses[0].body, { ok: true, accepted: true });
      assert.deepEqual(result.timeline, ['response', 'send', 'callback'], '202 sólo acepta el trabajo en n8n');
    } else {
      assert.equal(result.responses.length, 0);
      assert.deepEqual(result.timeline, ['send', 'callback']);
    }
  }
  return result;
}

for (const [workflow, event] of [
  [confirmation, 'reservation.confirmation'], [reminder, 'reservation.reminder'], [schedule, 'reservation.schedule_change'],
]) {
  for (const [channel, mode] of routes) {
    for (const attempt of workflow === schedule ? [1, 2] : workflow === reminder ? [1, 2, 3] : [1]) {
      const payload = basePayload(event, channel, attempt, mode);
      checkResult(workflow, payload);
      for (const failure of ['PROVIDER_REJECTED', 'TIMEOUT', 'HTTP_400', 'HTTP_500', 'INVALID_JSON']) {
        checkResult(workflow, payload, failure);
      }
      if (workflow !== confirmation) checkResult(workflow, payload, null, true);
    }
  }
}
for (const [channel, mode] of routes) {
  const payload = basePayload('reservation.confirmation', channel, 1, mode);
  payload.reservation = null;
  payload.reservation_id = null;
  payload.data.purpose = 'contact_access';
  checkResult(confirmation, payload);
  checkResult(confirmation, payload, 'PROVIDER_REJECTED');
}

function assertRejected(workflow, payload) {
  const [input, options] = inputFor(workflow, payload);
  const result = simulate(workflow, input, options);
  assert.equal(result.sends.length, 0, 'contrato inválido no envía');
  assert.equal(result.callbacks.length, 0, 'contrato inválido no emite callback');
  if (workflow === reminder) assert.equal(result.responses.length, 0);
  else {
    assert.equal(result.responses.length, 1);
    assert.equal(result.responses[0].code, 422);
    assert.deepEqual(result.responses[0].body, { ok: false, accepted: false, error: 'INVALID_CONTRACT' });
  }
}
const invalidMutations = [
  (p) => { p.schema_version = 2; },
  (p) => { p.event = 'unknown.event'; },
  (p) => { p.source_id = 0; },
  (p) => { p.reservation_id = -1; },
  (p) => { p.attempt = 4; },
  (p) => { p.contact.type = 'telefono'; },
  (p) => { p.contact.value = 'invalid'; },
  (p) => { p.transport.whatsapp_mode = 'invalid'; },
  (p) => { delete p.transport; },
  (p) => { p.recipient.name = ' '; },
  (p) => { p.reservation.date = 'invalid'; },
  (p) => { p.reservation.time = 'invalid'; },
  (p) => { p.reservation.guests = 0; },
  (p) => { p.reservation = null; },
  (p) => { p.data = null; },
];
for (const [workflow, event] of [
  [confirmation, 'reservation.confirmation'], [reminder, 'reservation.reminder'], [schedule, 'reservation.schedule_change'],
]) {
  for (const malformed of [null, [], {}, 'invalid json', true, 42]) assertRejected(workflow, malformed);
  for (const channel of ['email', 'whatsapp']) {
    for (const mutate of invalidMutations) {
      const payload = basePayload(event, channel);
      mutate(payload);
      assertRejected(workflow, payload);
    }
  }
  const payload = basePayload(event, 'email');
  if (workflow === confirmation) {
    payload.data.confirmation_code = 'bad';
    assertRejected(workflow, payload);
    payload.data.confirmation_code = '123456';
    payload.data.expires_at = 'invalid';
  } else {
    payload.data.management_url = 'javascript:alert(1)';
    assertRejected(workflow, payload);
    payload.data.management_url = 'https://example.test/manage';
    payload.data.access_expires_at = 'invalid';
  }
  assertRejected(workflow, payload);
}

// Distinct IDs catch accidental .first() callbacks after filtering a reminder batch.
const candidates = [
  basePayload('reservation.reminder', 'email'),
  basePayload('reservation.reminder', 'email'),
  basePayload('reservation.reminder', 'whatsapp', 1, 'text'),
  basePayload('reservation.reminder', 'whatsapp', 1, 'template'),
];
candidates.forEach((p, index) => { p.source_id = 100 + index; });
candidates[0].contact.value = 'invalid';
for (const failure of [null, 'TIMEOUT']) {
  const result = simulate(reminder, {}, { failure, prepareResponse: {
    ok: true, due: true, event: 'reservation.reminder', notifications: candidates,
  } });
  assert.equal(result.sends.length, 3);
  assert.deepEqual(result.callbacks.map((c) => c.body).sort((a, b) => a.source_id - b.source_id),
    candidates.slice(1).map((p) => ({
      event: p.event, source_id: p.source_id, attempt: p.attempt, channel: p.contact.type,
      status: failure ? 'failed' : 'accepted',
      ...(failure ? { retryable: false } : {}),
    })));
}
for (const response of [
  { ok: false }, { ok: true, due: false }, { ok: true, due: true, event: 'wrong' },
  { ok: true, due: true, event: 'reservation.reminder', notifications: [] },
  { ok: true, due: true, event: 'reservation.reminder', notifications: 'invalid' },
]) {
  const result = simulate(reminder, {}, { prepareResponse: response });
  assert.equal(result.sends.length, 0);
  assert.equal(result.callbacks.length, 0);
}
const noClaim = simulate(reminder, {}, { claimed: false, prepareResponse: {
  ok: true, due: true, event: 'reservation.reminder', notifications: candidates.slice(1),
} });
assert.equal(noClaim.sends.length, 0, 'claim duplicado nunca envía');
assert.equal(noClaim.callbacks.length, 0, 'claim duplicado no altera estado');

for (const workflow of workflows.values()) {
  for (const code of workflow.nodes.filter((n) => n.type === 'n8n-nodes-base.code')) {
    assert.ok(exercisedCode.has(workflow.name + ':' + code.name), code.name + ': Code realmente ejecutado');
  }
}
process.stdout.write('Workflows de reservaciones: ' + scenarios + ' escenarios en memoria OK; confirmación 200/502/422, callbacks accepted/failed, grafo, paridad y auth nativa.\n');
