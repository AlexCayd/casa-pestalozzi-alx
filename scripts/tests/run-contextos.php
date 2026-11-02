<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Security\Contextos;
use Services\Security\Permisos;
use Controllers\AdminContextosController;

$total = 0;
$assert = static function (bool $valor, string $mensaje) use (&$total): void {
    if (!$valor) { throw new RuntimeException($mensaje); }
    $total++;
};
$assert(Contextos::permitidos('super_admin', null) === ['global', 'restaurante', 'panaderia'], 'Super admin: tres contextos');
$assert(Contextos::permitidos('admin', 'restaurante') === ['restaurante'], 'Administrador de Restaurante');
$assert(Contextos::permitidos('admin', 'panaderia') === ['panaderia'], 'Administrador de Panadería');
foreach ([null, '', 'invalida', 'global'] as $unidad) {
    $assert(Contextos::permitidos('admin', $unidad) === [], 'Admin sin asignación válida: denegado');
}
$assert(Contextos::permitidos('waiter', null) === ['restaurante'], 'Personal legado conserva Restaurante');
$assert(Contextos::permitidos('cook', 'invalida') === [], 'Asignación inválida no hereda Restaurante');
$raiz = dirname(__DIR__, 2);
$sidebar = file_get_contents($raiz . '/views/admin/partials/_sidebar.php');
$layout = file_get_contents($raiz . '/views/admin/layout.php');
$selector = file_get_contents($raiz . '/views/admin/partials/_contexto.php');
$assert(str_contains($sidebar, "include __DIR__ . '/_contexto.php'") && !str_contains($layout, "partials/_contexto.php"), 'Selector único en sidebar');
$assert(str_contains($selector, 'data-context-trigger') && str_contains($selector, 'popover') && str_contains($selector, '$contextosDisponibles'), 'Selector accesible en capa superior con contextos autorizados');
$assert(str_contains($selector,'<noscript>') && str_contains($selector,'data-context-select'), 'Conserva navegación con formulario sin JavaScript');

$index = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
preg_match_all("/\\\$router->(?:get|post|delete)\\('([^']+)'/", $index, $rutas);
foreach (array_unique($rutas[1]) as $ruta) {
    if (Permisos::clasificar($ruta)['tipo'] === 'publica') { continue; }
    $esperado = in_array($ruta, ['/admin', '/admin/contexto'], true) || Contextos::deRuta($ruta) === 'panaderia';
    $assert(Contextos::permite($ruta, 'admin', 'panaderia') === $esperado, 'Frontera Panadería: ' . $ruta);
    if (Contextos::deRuta($ruta) !== 'restaurante') {
        $assert(!Contextos::permite($ruta, 'admin', 'restaurante'), 'Restaurante no abre otro contexto: ' . $ruta);
    }
}
foreach (['menu', 'recetas', 'inventario'] as $modulo) {
    $ruta = '/admin/panaderia/' . $modulo;
    $assert(Permisos::decidir($ruta, 'GET', null)['resultado'] === 'login', 'Demostración autenticada');
    $assert(Permisos::decidir($ruta, 'GET', ['sistema' => false, 'permisos' => []])['resultado'] === 'negar', 'Demostración exige módulo');
}
$consulta = ['sistema' => false, 'permisos' => ['menu' => 'ver']];
$assert(Permisos::decidir('/admin/panaderia/menu', 'GET', $consulta)['resultado'] === 'permitir', 'Consulta autorizada');
$assert(Permisos::decidir('/admin/panaderia/inventario', 'GET', $consulta)['resultado'] === 'negar', 'Cambiar contexto no concede inventario');
foreach (['production', '', 'staging', 'development', 'test'] as $entorno) {
    putenv('APP_ENV=' . $entorno);
    $_ENV['APP_ENV'] = $entorno;
    $assert(Contextos::demoHabilitada() === in_array($entorno, ['development', 'test'], true), 'Activación explícita: ' . $entorno);
}
$fixture = require dirname(__DIR__, 2) . '/database/fixtures/panaderia.php';
foreach ($fixture['productos'] as $id => $producto) {
    $assert(is_string($id) && $producto['precio'] > 0 && is_bool($producto['disponible']), 'Producto estructurado: ' . $id);
}
foreach ($fixture['recetas'] as $id => $receta) {
    $assert($receta['rendimiento']['cantidad'] > 0, 'Rendimiento: ' . $id);
    foreach ($receta['ingredientes'] as $ingrediente) {
        $articulo = $fixture['articulos'][$ingrediente['articulo']] ?? null;
        $assert($articulo !== null && $ingrediente['cantidad'] > 0 && $ingrediente['unidad'] === $articulo['unidad'], 'Referencia y unidad consistentes: ' . $id);
        if (isset($ingrediente['receta'])) {
            $assert(($fixture['recetas'][$ingrediente['receta']]['salida'] ?? null) === $ingrediente['articulo'], 'Preparación intermedia consistente');
        }
    }
}
echo "Contextos: {$total} comprobaciones OK\n";
