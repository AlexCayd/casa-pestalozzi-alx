const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const exists = (file) => fs.existsSync(path.join(root, file));

const routes = read('public/index.php');
const menuForm = read('views/admin/menu/form.php');
const menuIndex = read('views/admin/menu/index.php');
const recipesForm = read('views/admin/recetas/form.php');
const contextRoutes = read('controllers/AdminRecetasController.php');

for (const retired of [
  'controllers/AdminProductosCasaController.php',
  'services/Menu/ProductosCasa.php',
  'services/Menu/RecetasCasa.php',
  'services/Menu/CatalogoComercial.php',
  'views/admin/menu/global.php',
  'views/admin/menu/asociar.php',
  'views/admin/recetas/presentaciones-global.php',
  'src/js/admin/menu/casa.js',
  'public/build/js/admin/casa.js',
]) assert.equal(exists(retired), false, `El archivo fue retirado: ${retired}`);

for (const retiredRoute of [
  '/admin/global/productos',
  '/admin/menu/casa-receta',
  '/admin/panaderia/menu/casa-receta',
  '/admin/global/recetas',
  '/admin/global/productos/presentaciones',
]) assert.equal(routes.includes(retiredRoute), false, `Ruta obsoleta ausente: ${retiredRoute}`);

for (const localRoute of [
  '/admin/menu',
  '/admin/panaderia/menu',
  '/admin/recetas/editar',
  '/admin/panaderia/recetas/editar',
]) assert.equal(routes.includes(localRoute), true, `Ruta local conservada: ${localRoute}`);

for (const field of ['name="nombre"', 'name="descripcion"', 'name="precio"', 'name="activo"', 'name="categoria_id"', 'name="area_id"']) {
  assert.equal(menuForm.includes(field), true, `El formulario local conserva ${field}`);
}
for (const retiredField of ['confirmar_identidad', 'restaurar_casa', 'productos/global']) {
  assert.equal(menuForm.includes(retiredField), false, `El formulario no depende de ${retiredField}`);
  assert.equal(menuIndex.includes(retiredField), false, `El listado no depende de ${retiredField}`);
}

assert.equal(recipesForm.includes('recetasBase'), true, 'El editor usa rutas locales por negocio');
assert.equal(contextRoutes.includes('function path('), true, 'El controlador construye rutas por contexto');
assert.equal(routes.includes("'/admin/panaderia/recetas/editar'"), true, 'Panadería mantiene su flujo local de recetas');

console.log('Menú A4 UI: rutas locales, formularios simples y retiro de Productos de la casa OK.');
