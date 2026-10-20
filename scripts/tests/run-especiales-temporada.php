<?php

declare(strict_types=1);

/*
 * Contrato de los especiales de temporada de la landing.
 *
 * Los especiales son una carta más de `categorias.carta` ('temporada') y la
 * vitrina de views/home/_firma.php los pinta desde la base. Lo que protege:
 * que el ENUM del DDL y CategoriasMenu::CARTAS no se separen, que la
 * categoría del sistema siga sembrada con su foto de respaldo, que la vitrina
 * no vuelva a un arreglo fijo en el JS ni cambie el marcado de la tarjeta, y
 * que pedir la carta de temporada al PDF no lo rompa.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Model\CategoriasMenu;

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

// 1 · El ENUM del DDL es exactamente la lista de CategoriasMenu::CARTAS.
$ddl = $leer('database/ddl.sql');
$assert(
    preg_match('/CREATE TABLE IF NOT EXISTS categorias \((.*?)\);/s', $ddl, $tabla) === 1
        && preg_match("/carta\s+ENUM\(([^)]*)\)/", $tabla[1], $enum) === 1,
    'ddl.sql declara categorias.carta como ENUM.'
);
$valores = array_map(static fn ($v) => trim($v, " '"), explode(',', $enum[1]));
$assert($valores === array_keys(CategoriasMenu::CARTAS), 'El ENUM de categorias.carta coincide con CategoriasMenu::CARTAS.');
$assert(isset(CategoriasMenu::CARTAS[CategoriasMenu::CARTA_TEMPORADA]), 'Existe la carta de temporada.');
$assert(!in_array(CategoriasMenu::CARTA_TEMPORADA, CategoriasMenu::CARTAS_IMPRESAS, true), 'Los especiales no se imprimen.');
$assert(CategoriasMenu::normalizarCarta('temporada') === 'temporada', 'normalizarCarta() admite temporada.');

// 2 · La categoría del sistema se siembra en deploy.sql, con la foto que toma
//     un especial sin la suya: un archivo propio, que nadie más nombra.
$deploy = $leer('database/deploy.sql');
$assert(
    preg_match("/\(\s*\d+\s*,\s*'Especiales de temporada'\s*,\s*'temporada'\s*,\s*'build\/images\/especiales-temporada\.webp'\s*\)/", $deploy) === 1,
    'deploy.sql siembra «Especiales de temporada» con carta temporada y su foto.'
);
$assert(is_file($root . '/assets/images/especiales-temporada.webp'), 'Existe la foto de respaldo de los especiales.');
$assert(substr_count($deploy, 'especiales-temporada.webp') === 1, 'Ninguna otra categoría comparte la foto de respaldo.');

// 3 · El PDF no tiene pieza de temporada y no se rompe si se la piden.
$pdf = $leer('services/Menu/MenuPdf.php');
$assert(str_contains($pdf, 'if (!isset(self::PIEZAS[$carta]))'), 'MenuPdf cae a la carta de comida ante una carta sin papel.');

// 4 · La vitrina sale de la base, no de un arreglo en el JS.
$gallery = $leer('src/js/modules/gallery.js');
$assert(!preg_match('/var\s+GALLERY\s*=/', $gallery), 'gallery.js ya no trae la lista fija de especiales.');
$assert(!str_contains($gallery, 'createElement("div")'), 'gallery.js no construye tarjetas.');
$home = $leer('controllers/HomeController.php');
$assert(str_contains($home, 'Carta::especiales()'), 'HomeController carga los especiales.');
$carta = $leer('services/Menu/Carta.php');
$assert(str_contains($carta, 'self::publica(CategoriasMenu::CARTA_TEMPORADA)'), 'Carta::especiales() lee la carta de temporada.');
$assert(str_contains($carta, '\'cat\'    => $categoria[\'label\']'), 'Carta::especiales() entrega la categoría de cada platillo.');

// 5 · El parcial: el marcado de siempre para todas las tarjetas.
// Sin require de views/components/ornamento.php: la landing ya no lleva
// adornos después del hero y ese catálogo se retiró; _firma.php no lo llama.
$renderiza = static function (array $especiales) use ($root): string {
    $especialesTemporada = $especiales;
    ob_start();
    include $root . '/views/home/_firma.php';
    return (string) ob_get_clean();
};

$html = $renderiza([
    ['n' => 'Con <foto>', 'd' => 'Descripción', 'p' => 345.0, 'cat' => 'Especiales & temporada', 'img' => '/build/images/mejor-5.webp', 'propia' => true],
    ['n' => 'De categoría', 'd' => 'Otra', 'p' => 95.5, 'cat' => 'Pastas de otoño', 'img' => '/build/images/especiales-temporada.webp', 'propia' => false],
]);
$assert(substr_count($html, 'class="gcard" data-cursor="Ampliar" data-zoom') === 2, 'Las dos tarjetas son la de siempre, ampliables.');
$assert(substr_count($html, '<div class="gcard__cap"><div class="t">') === 2, 'La cartela conserva sus dos líneas.');
$assert(!preg_match('/gcard--|class="d"|data-tono="verde"/', $html), 'Sin variantes de tarjeta nuevas.');
$assert(str_contains($html, 'Con &lt;foto&gt;'), 'El nombre se escapa.');
$assert(str_contains($html, '<div class="t">Especiales &amp; temporada</div>') && str_contains($html, '<div class="t">Pastas de otoño</div>'), 'La línea chica de la cartela es la categoría del platillo, escapada.');
$assert(str_contains($html, 'data-zoom-cat="Pastas de otoño"'), 'El lightbox recibe la categoría.');
$assert(!str_contains($html, '$345'), 'El precio ya no ocupa la línea de la categoría.');
$assert(str_contains($html, 'Clic para ampliar'), 'Con especiales, la sección ofrece ampliar.');

$vacio = $renderiza([]);
$assert(str_contains($vacio, 'id="firma"') && str_contains($vacio, 'class="lead"'), 'Una semana sin especiales deja la sección con su línea de aviso.');
$assert(!str_contains($vacio, 'Clic para ampliar') && !str_contains($vacio, 'gcard'), 'Sin especiales no hay tarjetas ni promesa de ampliar.');

// La vitrina no estrena estilos: su hoja es la del repositorio.
$assert(!str_contains($leer('src/scss/components/_galeria.scss'), 'gcard--sin-foto'), '_galeria.scss no lleva variantes de especiales.');

// 6 · El formulario del platillo pide la foto de la landing en los especiales.
$form = $leer('views/admin/menu/form.php');
$assert(str_contains($form, 'data-carta='), 'Las categorías del formulario dicen a qué carta pertenecen.');
$assert(str_contains($form, 'data-foto-especial') && str_contains($form, 'Foto para la landing'), 'El campo de foto se presenta como la foto de la landing en los especiales.');
$assert(!str_contains($form, 'fotoDeCategoria'), 'Los especiales ofrecen la foto de su categoría como cualquier platillo.');
$js = $leer('src/js/admin/core/foto-platillo.js');
$assert(str_contains($js, "getAttribute('data-carta') === 'temporada'"), 'foto-platillo.js reconoce los especiales.');

fwrite(STDOUT, "Especiales de temporada: {$assertions} comprobaciones OK\n");
