/**
 * Exporta los flujos de n8n a este directorio, en el formato que acepta
 * "Import from File" del editor.
 *
 *   node n8n/exportar.js              # sólo compara y muestra cambios
 *   node n8n/exportar.js --write      # escribe después de revisar el diff
 *
 * Lee la base local de n8n en modo SOLO LECTURA. n8n mantiene los flujos en
 * memoria, así que este script nunca escribe ahí: para cambiar un flujo se usa
 * el editor y luego se vuelve a exportar.
 *
 * Las credenciales y metadatos de ejecución NO viajan en el JSON (ver
 * README.md). Las ejecuciones tampoco se consultan: sólo se lee
 * workflow_entity.
 */

const { DatabaseSync } = require('node:sqlite');
const fs = require('fs');
const path = require('path');

const DB = path.join(process.env.USERPROFILE || process.env.HOME, '.n8n', 'database.sqlite');
const DESTINO = __dirname;
const ESCRIBIR = process.argv.includes('--write');
const CAMPOS_PRIVADOS = new Set(['credentials', ['pin', 'Data'].join('')]);

function sinSecretos(value) {
  if (Array.isArray(value)) return value.map(sinSecretos);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(
    Object.entries(value)
      .filter(([key]) => !CAMPOS_PRIVADOS.has(key))
      .map(([key, child]) => [key, sinSecretos(child)]),
  );
}

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
const flujos = db.prepare('SELECT name, nodes, connections, settings, meta FROM workflow_entity').all();

for (const flujo of flujos) {
  const exportado = sinSecretos({
    name: flujo.name,
    nodes: JSON.parse(flujo.nodes),
    connections: JSON.parse(flujo.connections),
    settings: JSON.parse(flujo.settings || '{}'),
    meta: JSON.parse(flujo.meta || '{}'),
  });

  const archivo = path.join(DESTINO, archivoDe(flujo.name));
  const contenido = JSON.stringify(exportado, null, 2) + '\n';
  const anterior = fs.existsSync(archivo) ? fs.readFileSync(archivo, 'utf8') : null;
  if (anterior === contenido) {
    console.log('sin cambios: ' + path.basename(archivo) + '  (' + exportado.nodes.length + ' nodos)');
    continue;
  }
  if (!ESCRIBIR) {
    console.log('cambio detectado, no se escribió: ' + path.basename(archivo)
      + '  (revisa el diff y usa --write para confirmar)');
    continue;
  }
  fs.writeFileSync(archivo, contenido, 'utf8');
  console.log('exportado: ' + path.basename(archivo) + '  (' + exportado.nodes.length + ' nodos)');
}
