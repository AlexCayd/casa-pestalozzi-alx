<?php

declare(strict_types=1);

/*
 * Contrato de la landing de la panadería (/panaderia).
 *
 * Lo que protege: que la temporada se calcule bien en los cruces de año, que
 * la vitrina lea el menú del negocio Panadería (y no una carta del
 * Restaurante), que la ruta, las subrutas del andamio, el sitemap, la paleta
 * editable y los datos de contacto estén donde deben, y que los encargos no se
 * queden sin frase. Sólo lee archivos y llama servicios puros; no toca la base.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Model\CategoriasMenu;
use Services\Panaderia\PanaderiaConfig;
use Services\Panaderia\Temporadas;

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
$dia = static fn (string $fecha): DateTimeImmutable => new DateTimeImmutable($fecha . ' 12:00', new DateTimeZone('America/Mexico_City'));

// 1 · Temporadas: la de ahora, la siguiente y el cruce de año.
$t = Temporadas::paraFecha($dia('2026-10-23'));
$assert(($t['ahora']['nombre'] ?? null) === 'Pan de muerto', 'El 23 de octubre es temporada de pan de muerto.');
$assert(($t['ahora']['hasta_texto'] ?? null) === '2 de noviembre', 'La de ahora dice hasta cuándo, en palabras.');
$assert(count(array_filter($t['calendario'], static fn ($f) => $f['ahora'])) === 1, 'Sólo una fila del calendario se marca como la de ahora.');
$assert($t['hoy'] > 80 && $t['hoy'] < 82, 'La marca de hoy cae en octubre en la regla del año.');

$t = Temporadas::paraFecha($dia('2027-01-03'));
$assert(($t['ahora']['nombre'] ?? null) === 'Rosca de Reyes', 'El 3 de enero sigue la rosca que empezó en diciembre.');
$t = Temporadas::paraFecha($dia('2026-12-27'));
$assert(($t['ahora']['nombre'] ?? null) === 'Rosca de Reyes', 'El 27 de diciembre ya es temporada de rosca.');
$t = Temporadas::paraFecha($dia('2026-12-25'));
$assert($t['ahora'] === null && ($t['siguiente']['nombre'] ?? null) === 'Rosca de Reyes', 'El 25 de diciembre no hay ninguna y la siguiente es la rosca.');
$t = Temporadas::paraFecha($dia('2026-07-01'));
$assert($t['ahora'] === null && ($t['siguiente']['nombre'] ?? null) === 'Pan de elote', 'En julio la siguiente es el pan de elote.');

$rosca = array_values(array_filter($t['calendario'], static fn ($f) => $f['nombre'] === 'Rosca de Reyes'))[0];
$assert(count($rosca['tramos']) === 2 && $rosca['tramos'][0]['inicio'] == 0, 'Una ventana que cruza el año se dibuja en dos tramos.');
$assert(Temporadas::fechasEnPalabras('03-12', '03-19') === 'Del 12 al 19 de marzo', 'Si es el mismo mes, el mes se dice una vez.');
$assert(count(Temporadas::PANES) >= 4 && count(Temporadas::PANES) <= 6, 'La temporada lleva entre cuatro y seis panes.');
foreach (Temporadas::PANES as $pan) {
    $assert(($pan['frase'] ?? '') !== '' && ($pan['descripcion'] ?? '') !== '', "{$pan['nombre']} tiene descripción y frase para WhatsApp.");
}

// 2 · La vitrina es el menú del negocio Panadería, no una carta del Restaurante.
$assert(!isset(CategoriasMenu::CARTAS['panaderia']), 'Las cartas del Restaurante no incluyen una de la panadería: la Panadería tiene su propio menú.');
$deploy = $leer('database/deploy.sql');
$assert(str_contains($deploy, "(3,'Panadería','panaderia','negocio')"), 'deploy.sql siembra el negocio Panadería.');
$assert(str_contains($deploy, "(3,'comida','Menú de Panadería'"), 'La Panadería tiene su carta de comida, que es la vitrina.');
$catalogo = $leer('services/Panaderia/PanaderiaCatalogo.php');
$assert(str_contains($catalogo, "CartaMultinegocio::publicaDe('panaderia', CategoriasMenu::CARTA_COMIDA)"), 'La vitrina lee la carta de comida de la Panadería.');
$assert(str_contains($leer('services/Menu/Carta.php'), 'public static function publicaDe(string $negocio'), 'La carta pública se puede pedir por negocio.');
$assert(str_contains($leer('database/development.sql'), "WHEN 1201 THEN 'build/images/panaderia/bolleria.webp'"), 'Las categorías de muestra de la Panadería traen su foto.');
foreach (['panes', 'bolleria', 'dulces'] as $foto) {
    $assert(is_file($root . "/assets/images/panaderia/{$foto}.webp"), "La sección {$foto} tiene su propia foto (no una compartida).");
}

// 3 · Contacto: la panadería tiene sus datos, y no son los del restaurante.
$assert(PanaderiaConfig::telefonoTel() === '+525512299648', 'El teléfono de la panadería sale en formato tel:.');
$assert(str_starts_with(PanaderiaConfig::whatsappUrl('Hola'), 'https://wa.me/525512299648?text='), 'Los encargos van al WhatsApp de la panadería.');
$assert(str_contains(PanaderiaConfig::direccion(), 'San Borja 1026'), 'La dirección es la de San Borja.');
$assert(PanaderiaConfig::whatsapp() !== \Services\Configuration\SitioConfig::whatsappEventos(), 'El WhatsApp de la panadería no es el de eventos del restaurante.');
foreach (PanaderiaConfig::ENCARGOS as $grupo => $casos) {
    foreach ($casos as $caso) {
        $assert(count($caso) === 3 && $caso[2] !== '' && $caso[1] !== '', "«{$caso[0]}» ({$grupo}) tiene anticipación y frase.");
    }
}
$assert(count(PanaderiaConfig::ENCARGOS) % 3 === 0, 'Los grupos de encargos llenan sus filas (3 × n).');
$assert(str_contains(PanaderiaConfig::lineaDeHoy($dia('2026-10-23')), 'Hoy abrimos de 7:30'), 'La línea de hoy se dice en palabras y sin el cero delante.');

// 4 · Ruta, sitemap, paleta y orden de las bandas.
$rutas = $leer('public/index.php');
$assert(str_contains($rutas, "\$router->get('/panaderia', [PanaderiaController::class, 'index']);"), 'La ruta /panaderia está registrada.');
$assert(str_contains($rutas, 'use Controllers\Panaderia\PanaderiaController;'), 'La ruta usa el controlador de la capa de la panadería.');
foreach (['carta' => 'vitrina', 'pedidos' => 'encargos', 'nosotros' => 'portada', 'contacto' => 'visitanos'] as $sub => $ancla) {
    $assert(str_contains($rutas, "'/panaderia/{$sub}'") && str_contains($rutas, "'/panaderia#{$ancla}'"), "/panaderia/{$sub} lleva a #{$ancla}.");
}
$assert(str_contains($leer('controllers/SeoController.php'), "\$base . '/panaderia'"), '/panaderia está en el sitemap.');
$vista = $leer('views/panaderia/index.php');
$css = strpos($vista, '/build/css/panaderia.css');
$paleta = strpos($vista, 'PaletaLanding::estiloEnLinea()');
$assert($css !== false && $paleta !== false && $paleta > $css, 'La paleta editable se emite DESPUÉS de panaderia.css.');
$assert(str_contains($vista, "'@type' => 'Bakery'") && str_contains($vista, "'parentOrganization'"), 'Los datos estructurados son de una Bakery enlazada al restaurante.');

$tonos = [];
foreach (['_portada', '_vitrina', '_temporada', '_encargos', '_restaurante', '_visitanos'] as $parcial) {
    $assert(preg_match('/<section[^>]*data-tono="([a-z]+)"/', $leer("views/panaderia/{$parcial}.php"), $m) === 1, "{$parcial} declara su tono.");
    $tonos[] = $m[1];
}
for ($i = 1; $i < count($tonos); $i++) {
    $assert($tonos[$i] !== $tonos[$i - 1], 'Dos bandas vecinas no comparten tono (' . $tonos[$i - 1] . ' / ' . $tonos[$i] . ').');
}

// 5 · La portada del restaurante lleva a la panadería sin abrir el mapa equivocado.
$adelanto = $leer('views/home/_adelanto-panaderia.php');
$assert(str_contains($adelanto, 'href="/panaderia"') && str_contains($adelanto, 'href="/panaderia#visitanos"'), 'El adelanto de la portada lleva a /panaderia y a su «Cómo llegar».');
$assert(!str_contains($adelanto, 'maps.app.goo.gl'), 'El adelanto ya no abre el mapa del restaurante como si fuera el de la panadería.');

fwrite(STDOUT, "Panadería: {$assertions} comprobaciones OK\n");
