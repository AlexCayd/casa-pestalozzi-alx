const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '..', '..');
const sourceRoot = path.join(root, 'src');

function jsFiles(directory) {
  const files = [];
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...jsFiles(absolute));
    else if (entry.isFile() && absolute.endsWith('.js')) files.push(absolute);
  }
  return files;
}

const files = jsFiles(sourceRoot);
for (const file of files) {
  execFileSync(process.execPath, ['--check', file], { stdio: 'inherit' });
}

console.log(`PASS: ${files.length} archivos JavaScript pasan verificación sintáctica.`);
