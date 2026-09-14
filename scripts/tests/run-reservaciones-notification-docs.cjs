const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const files = ['docs/arquitectura.md', 'docs/config.md', 'docs/migracion_services.md',
  'docs/reservaciones/notificaciones.md', 'docs/reservaciones/reservaciones.md',
  'docs/reservaciones/afectaciones_reservaciones_por_cambios_horario.md',
  'n8n/README.md', 'n8n/deploy/README.md'];
let count = 0;
for (const file of files) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  for (const match of source.matchAll(/\[[^\]]*\]\(([^)]+)\)/g)) {
    const link = match[1];
    if (/^(?:https?:|mailto:|#)/.test(link)) continue;
    const target = path.resolve(root, path.dirname(file), decodeURIComponent(link.split('#')[0]));
    assert.ok(fs.existsSync(target), `${file}: enlace local roto ${link}`);
    count++;
  }
}
console.log(`Documentación: ${count} enlaces locales vigentes.`);
