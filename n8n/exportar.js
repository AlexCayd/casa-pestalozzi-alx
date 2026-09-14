/**
 * Exporta los flujos de n8n a este directorio, en el formato que acepta
 * "Import from File" del editor.
 *
 *   node n8n/exportar.js
 *
 * Lee la base local de n8n en modo SOLO LECTURA. n8n mantiene los flujos en
 * memoria, así que este script nunca escribe ahí: para cambiar un flujo se usa
 * el editor y luego se vuelve a exportar.
 *
 * Las credenciales NO viajan en el JSON (ver README.md).
 */

const { DatabaseSync } = require('node:sqlite');
const fs = require('fs');
const path = require('path');

const DB = path.join(process.env.USERPROFILE || process.env.HOME, '.n8n', 'database.sqlite');
const DESTINO = __dirname;
const RESERVATION_WORKFLOWS = new Map([
  ['Reservaciones - Confirmación', 'reservaciones-confirmacion.json'],
  ['Reservaciones - Recordatorio', 'reservaciones-recordatorio.json'],
  ['Reservaciones - Cambio de horario', 'reservaciones-cambio-horario.json'],
]);

// 'Áreas de mejora' -> 'areas-de-mejora'
function archivoDe(nombre) {
  return nombre
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '') + '.json';
}

if (!fs.existsSync(DB)) {
  console.error('No encuentro la base de n8n en ' + DB);
  process.exit(1);
}

const db = new DatabaseSync(DB, { readOnly: true });
const flujos = db.prepare(
  'SELECT name, nodes, connections, settings, meta, active, versionId FROM workflow_entity'
).all();
const reservacionesEncontradas = new Set();

for (const flujo of flujos) {
  const esReservaciones = flujo.name.startsWith('Reservaciones - ');
  if (esReservaciones && !RESERVATION_WORKFLOWS.has(flujo.name)) {
    console.log('omitido workflow de reservaciones obsoleto: ' + flujo.name);
    continue;
  }
  const nodes = JSON.parse(flujo.nodes).map((node) => {
    const limpio = { ...node };
    delete limpio.credentials;
    return limpio;
  });
  const exportado = {
    name: flujo.name,
    nodes,
    connections: JSON.parse(flujo.connections),
    settings: JSON.parse(flujo.settings || '{}'),
    active: Boolean(flujo.active),
    versionId: flujo.versionId,
    meta: JSON.parse(flujo.meta || '{}'),
  };

  if (esReservaciones) reservacionesEncontradas.add(flujo.name);
  const nombreArchivo = RESERVATION_WORKFLOWS.get(flujo.name) || archivoDe(flujo.name);
  const archivo = path.join(DESTINO, nombreArchivo);
  fs.writeFileSync(archivo, JSON.stringify(exportado, null, 2) + '\n', 'utf8');
  console.log('exportado: ' + path.basename(archivo) + '  (' + exportado.nodes.length + ' nodos)');
}

const faltantes = [...RESERVATION_WORKFLOWS.keys()].filter((name) => !reservacionesEncontradas.has(name));
if (faltantes.length) {
  console.error('Faltan workflows de reservaciones en n8n: ' + faltantes.join(', '));
  process.exitCode = 1;
}
