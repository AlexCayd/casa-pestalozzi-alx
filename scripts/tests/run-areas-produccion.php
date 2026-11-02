<?php

declare(strict_types=1);

/*
 * Contrato de los colores de las áreas de producción.
 *
 * El color de cada estación se elige en Configuración → Colores de las áreas
 * (Services\Configuration\AreasProduccion) y sólo entre los diez de la paleta
 * funcional. Lo que protege: que el espejo en PHP de la paleta no se separe de
 * los tokens (--c-* y --admin-c-*), que la validación rechace colores fuera de
 * la paleta y repetidos, y que ningún tablero vuelva a llevar sus colores
 * escritos a mano (el del piso llevaba cuatro distintos a los de la base).
 * Sólo lee archivos y llama a validar(); no toca la base.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Configuration\AreasProduccion;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};
$leer = static fn (string $ruta): string => (string) file_get_contents($root . '/' . $ruta);

// 1 · El espejo de la paleta es la paleta funcional, en los dos sistemas.
$reset = $leer('src/scss/layout/_reset.scss');
$globals = $leer('src/scss/admin/shared/base/_globals.scss');
$assert(count(AreasProduccion::PALETA) === 10, 'La paleta de las áreas son los diez colores de la paleta funcional.');
foreach (AreasProduccion::PALETA as $clave => $color) {
    $assert(preg_match('/^#[0-9a-f]{6}$/', $color['hex']) === 1, "{$clave} es un hex de seis cifras en minúsculas.");
    $assert(preg_match('/--c-' . $clave . ':\s*' . preg_quote($color['hex'], '/') . ';/i', $reset) === 1, "--c-{$clave} vale {$color['hex']} en _reset.scss.");
    $assert(preg_match('/--admin-c-' . $clave . ':\s*' . preg_quote($color['hex'], '/') . ';/i', $globals) === 1, "--admin-c-{$clave} vale {$color['hex']} en _globals.scss.");
}
// La vista previa de la pantalla tiene su propia lista de claves en el SCSS
// (una regla :has() por color): si se separa de la paleta, un color nuevo se
// podría elegir pero la vista previa no cambiaría, sin que nada fallara.
$hoja = $leer('src/scss/admin/modules/configuration.scss');
$assert(preg_match('/@each \$clave in ([^{]+)\{/', $hoja, $each) === 1, 'configuration.scss genera la vista previa con un @each.');
$clavesHoja = array_map(static fn (string $c): string => trim($c, " \"'\n\r\t"), explode(',', $each[1]));
$assert($clavesHoja === array_keys(AreasProduccion::PALETA), 'El @each de configuration.scss lista las mismas claves que AreasProduccion::PALETA, en el mismo orden.');
foreach (AreasProduccion::DE_ESPERA as $clave) {
    $assert(isset(AreasProduccion::PALETA[$clave]), "El color de espera {$clave} es de la paleta.");
}

// 2 · Normalizar y reconocer.
$assert(AreasProduccion::normalizar('#E51022') === '#e51022', 'normalizar() pasa a minúsculas.');
$assert(AreasProduccion::normalizar('e51022') === '#e51022', 'normalizar() acepta el hex sin almohadilla.');
$assert(AreasProduccion::normalizar('rojo') === null && AreasProduccion::normalizar('#fff') === null, 'normalizar() rechaza lo que no es un hex de seis cifras.');
$assert(AreasProduccion::clave('#E51022') === 'rojo', 'clave() reconoce un color de la paleta.');
$assert(AreasProduccion::clave('#7b5e3a') === null, 'clave() devuelve null para un color fuera de la paleta.');

// 3 · Validar: todas con color de la paleta, ninguno repetido. Sin base,
// todas() cae al respaldo de las cuatro estaciones.
$valido = AreasProduccion::validar(['cafe' => 'turquesa', 'jugos' => 'ambar', 'cocina' => 'rojo', 'horno' => 'indigo']);
$assert($valido['errores'] === [], 'Cuatro colores distintos de la paleta se aceptan.');
$assert(($valido['colores']['cocina'] ?? null) === '#e51022', 'Lo que se guarda es el hex de la paleta, no la clave.');

$repetido = AreasProduccion::validar(['cafe' => 'rojo', 'jugos' => 'ambar', 'cocina' => 'rojo', 'horno' => 'indigo']);
$assert(count($repetido['errores']) === 1 && str_contains($repetido['errores'][0], 'mismo color'), 'Dos estaciones con el mismo color se rechazan.');
$assert(str_contains($repetido['errores'][0], 'Barra de Café y Cocina'), 'El aviso dice qué estaciones chocan.');

$fuera = AreasProduccion::validar(['cafe' => '#000000', 'jugos' => 'ambar', 'cocina' => 'rojo', 'horno' => 'indigo']);
$assert($fuera['errores'] !== [] && str_contains($fuera['errores'][0], 'Barra de Café'), 'Un color fuera de la paleta se rechaza.');

$falta = AreasProduccion::validar(['cafe' => 'turquesa', 'jugos' => 'ambar', 'cocina' => 'rojo']);
$assert($falta['errores'] !== [], 'Una estación sin color se rechaza.');

// 4 · Ningún tablero lleva sus colores a mano: leen el servicio.
foreach (['controllers/AreaController.php', 'controllers/AdminAreaController.php'] as $controlador) {
    $codigo = $leer($controlador);
    $assert(str_contains($codigo, 'AreasProduccion::'), "{$controlador} lee las estaciones de AreasProduccion.");
    $assert(preg_match("/'color'\s*=>\s*'#/", $codigo) === 0, "{$controlador} no tiene colores de estación escritos a mano.");
}

// 5 · La semilla usa colores de la paleta, distintos entre sí.
$deploy = $leer('database/deploy.sql');
$assert(preg_match('/INSERT INTO areas_produccion \(id, nombre, slug, color\) VALUES(.*?);/s', $deploy, $semilla) === 1, 'deploy.sql siembra las áreas.');
preg_match_all("/'(#[0-9a-fA-F]{6})'/", $semilla[1], $hexes);
$claves = array_map([AreasProduccion::class, 'clave'], $hexes[1]);
$assert(count($claves) === 4 && !in_array(null, $claves, true), 'Las cuatro áreas sembradas tienen un color de la paleta.');
$assert(count(array_unique($claves)) === 4, 'Las cuatro áreas sembradas tienen colores distintos.');

// 6 · La pantalla: ruta, sin hex en el marcado, tarjeta en Configuración.
$assert(str_contains($leer('public/index.php'), "\$router->post('/admin/configuracion/areas', [AdminConfigurationController::class, 'guardarAreas']);"), 'La ruta de guardado está registrada.');
$vista = $leer('views/admin/configuration/areas.php');
$assert(preg_match('/#[0-9a-fA-F]{6}\b/', $vista) === 0, 'La vista no tiene ningún hex: las muestras piden el token.');
$assert(str_contains($vista, 'var(--admin-c-'), 'Las muestras pintan con los tokens de la paleta del panel.');
$assert(str_contains($leer('controllers/AdminConfigurationController.php'), "'titulo' => 'Colores de las áreas'"), 'Configuración enlaza a la pantalla.');

fwrite(STDOUT, "Colores de las áreas: {$assertions} comprobaciones OK\n");
