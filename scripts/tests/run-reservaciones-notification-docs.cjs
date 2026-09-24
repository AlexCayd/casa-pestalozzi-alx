const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

const root = path.resolve(__dirname, '../..');

function source(file) {
  const bytes = fs.readFileSync(path.join(root, file));
  return file === 'README.md'
    ? bytes.toString('utf16le').replace(/^\uFEFF/, '')
    : bytes.toString('utf8');
}

function names(directory) {
  return fs.readdirSync(path.join(root, directory)).sort();
}

function walkMarkdown(directory) {
  return fs.readdirSync(path.join(root, directory), { withFileTypes: true }).flatMap((entry) => {
    const relative = path.join(directory, entry.name).replaceAll('\\', '/');
    if (entry.isDirectory()) return walkMarkdown(relative);
    return entry.isFile() && entry.name.endsWith('.md') ? [relative] : [];
  });
}

function slug(text) {
  return text.toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^\p{L}\p{N}\s-]/gu, '')
    .trim()
    .replace(/\s+/g, '-');
}

const expectedDocs = [
  'arquitectura.md',
  'config.md',
  'correcciones_pendientes_arquitectura.md',
  'operacion.md',
  'privacidad',
  'reservaciones',
  'usuarios'
].sort();
assert.deepEqual(names('docs'), expectedDocs, 'docs contiene sólo fuentes vigentes y pendientes verificados');
assert.deepEqual(names('docs/reservaciones'), ['notificaciones.md', 'reservaciones.md']);
assert.deepEqual(names('docs/privacidad'), ['privacidad.md']);
assert.deepEqual(names('docs/usuarios'), ['credenciales.md', 'usuarios.md']);

const architecture = source('docs/arquitectura.md');
for (const domain of [
  'Analytics', 'Configuration', 'Contact', 'Integrations', 'Inventory', 'Menu',
  'Notifications', 'Pos', 'Reservations', 'Scheduling', 'Security', 'Shared',
  'Tables', 'Users'
]) {
  assert.ok(architecture.includes(`\`${domain}\``), `arquitectura identifica el dominio ${domain}`);
}
assert.match(architecture, /Controller → Service → Model/);
assert.match(architecture, /nivel por dominio/);
assert.match(architecture, /Shared` es una excepción/);

const config = source('docs/config.md');
for (const token of ['APP_ENV', 'APP_TIMEZONE', 'DB_HOST', 'N8N_BASE_URL', 'N8N_RESERVATIONS_WEBHOOK_SECRET', 'N8N_RESERVATIONS_CALLBACK_SECRET']) {
  assert.ok(config.includes(token), `configuración documenta ${token}`);
}
assert.match(config, /development.*test.*production/s);
assert.match(config, /Configuración administrativa/);

const operation = source('docs/operacion.md');
for (const token of ['POST /api/enviar-comanda', 'print_ok', 'impresion_activa', 'no recibe una clave de idempotencia', 'npm run test:pos-print-alerts']) {
  assert.ok(operation.includes(token), `operación POS documenta ${token}`);
}
assert.match(operation, /últimas 16 horas/);

const reservations = source('docs/reservaciones/reservaciones.md');
for (const section of ['## Mapas y estados visuales', '### Prioridad visual', '### Límites temporales', '### Modal de ayuda', '## Cambios de horario y afectaciones']) {
  assert.ok(reservations.includes(section), `reservaciones tiene la fuente normativa ${section}`);
}
for (const rule of ['Disponible', 'Advertencia', 'Próxima', 'Inicio', 'Tolerancia', 'Ausencia pendiente', 'Ticket abierto', 'No utilizable', 'Seleccionada', 'fuera_horario_operacion', 'en_proyeccion_mapa']) {
  assert.ok(reservations.includes(rule), `reservaciones cubre ${rule}`);
}
assert.match(reservations, /no garantiza disponibilidad/);

const notifications = source('docs/reservaciones/notificaciones.md');
for (const token of ['claimed: true', 'accepted|failed', 'N8N_RESERVATIONS_WEBHOOK_SECRET', 'N8N_RESERVATIONS_CALLBACK_SECRET', 'npm run test:notifications']) {
  assert.ok(notifications.includes(token), `notificaciones documenta ${token}`);
}
assert.match(notifications, /accepted.*no\s+entrega|accepted.*sin confirmar entrega/s);

const pending = source('docs/correcciones_pendientes_arquitectura.md');
const pendingIds = Array.from(pending.matchAll(/^## ARQ-(\d+)/gm), (match) => match[1]);
assert.deepEqual(pendingIds, ['001', '003', '004', '005'], 'sólo conserva hallazgos vigentes verificados');

const markdownFiles = [
  ...walkMarkdown('docs'),
  ...walkMarkdown('n8n'),
  'README.md',
  'CLAUDE.md'
];
let linkCount = 0;
for (const file of markdownFiles) {
  const text = source(file);
  const headings = new Set(Array.from(text.matchAll(/^#{1,6}\s+(.+?)\s*#*$/gm), (match) => slug(match[1])));
  for (const match of text.matchAll(/\[[^\]]*\]\(([^)]+)\)/g)) {
    const href = match[1].trim();
    if (/^(?:https?:|mailto:|data:|#)/i.test(href)) continue;
    const decoded = decodeURIComponent(href);
    const [relativePath, fragment] = decoded.split('#', 2);
    const target = path.resolve(root, path.dirname(file), relativePath);
    assert.ok(fs.existsSync(target), `${file}: enlace local roto ${href}`);
    if (fragment && fs.statSync(target).isFile() && target.toLowerCase().endsWith('.md')) {
      const targetText = path.relative(root, target).replaceAll('\\', '/');
      const targetHeadings = new Set(Array.from(source(targetText).matchAll(/^#{1,6}\s+(.+?)\s*#*$/gm), (heading) => slug(heading[1])));
      assert.ok(targetHeadings.has(fragment.toLowerCase()), `${file}: encabezado local roto ${href}`);
    }
    linkCount += 1;
  }
}

console.log(`Documentación: contratos vigentes y ${linkCount} enlaces locales verificados.`);
