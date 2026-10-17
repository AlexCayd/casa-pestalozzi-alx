<?php

declare(strict_types=1);

/*
 * Contrato de la paleta editable de la landing (PaletaLanding).
 *
 * Lo que protege: que el espejo de la capa 1 no se separe del SCSS, que las
 * mezclas en PHP sigan dando lo que computa el navegador (el PDF las usa tal
 * cual), que la marca pase sus propias comprobaciones de contraste y que nada
 * que no sea un hex llegue al <style> de las páginas públicas.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Configuration\PaletaLanding;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};

// 1 · MARCA es la capa 1 de _reset.scss, valor por valor.
$reset = (string) file_get_contents($root . '/src/scss/layout/_reset.scss');
foreach (PaletaLanding::MARCA as $clave => $hex) {
    $assert(
        preg_match('/--brand-' . $clave . ':\s*(#[0-9a-fA-F]{6});/', $reset, $m) === 1,
        "_reset.scss declara --brand-{$clave}."
    );
    $assert(strtolower($m[1]) === $hex, "PaletaLanding::MARCA['{$clave}'] coincide con _reset.scss ({$m[1]}).");
}

// 2 · Los porcentajes de las comprobaciones son los de _reset.scss.
foreach ([
    'color-mix(in srgb, var(--verde-deep) 69%, var(--bg))',
    'color-mix(in srgb, var(--verde-deep) 74%, var(--bg))',
    'color-mix(in srgb, var(--brand-crema) 65%, var(--bg))',
    'color-mix(in srgb, var(--brand-crema) 52%, var(--bg))',
    'color-mix(in srgb, var(--brand-verde) 72%, black)',
    'color-mix(in srgb, var(--brand-cafe) 74%, black)',
] as $mezcla) {
    $assert(str_contains($reset, $mezcla), "_reset.scss sigue usando {$mezcla}.");
}

// 3 · Las mezclas en PHP reproducen la tabla que el PDF tenía escrita a mano
//     (medida en el navegador sobre la paleta de marca).
$esperadoPdf = [
    'verde' => '#225036', 'cafe' => '#4a2f21', 'beige' => '#e3d5bb', 'crema' => '#f5f1e8',
    'bg' => '#f5f1e8', 'txt' => '#183a27', 'txt_strong' => '#372318', 'txt_mute' => '#455e4e',
    'txt_faint' => '#5d7263', 'accent' => '#4a2f21', 'line_soft' => '#c2b7ac', 'line_strong' => '#705a4d',
    'v_bg' => '#225036', 'v_txt' => '#f5f1e8', 'v_txt_mute' => '#c7cec1', 'v_accent' => '#e3d5bb',
];
$assert(PaletaLanding::paraPdf(PaletaLanding::MARCA) === $esperadoPdf, 'paraPdf() con la marca da la tabla histórica del PDF.');

// 4 · La marca pasa todas sus comprobaciones; una paleta pálida no.
foreach (PaletaLanding::contraste(PaletaLanding::MARCA) as $c) {
    $assert($c['ok'], "La marca cumple «{$c['rotulo']}» ({$c['ratio']}:1).");
}
$palida = ['verde' => '#9fb8a8'] + PaletaLanding::MARCA;
$fallos = array_filter(PaletaLanding::contraste($palida), static fn ($c) => !$c['ok']);
$assert(count($fallos) > 0, 'Un verde pálido deja pares por debajo de AA.');

// 5 · Normalización y validación.
$assert(PaletaLanding::normalizar('#ABC') === '#aabbcc', 'Forma corta en mayúsculas.');
$assert(PaletaLanding::normalizar(' 225036 ') === '#225036', 'Sin almohadilla y con espacios.');
$assert(PaletaLanding::normalizar('#12345') === null, 'Cinco cifras no es un color.');
$assert(PaletaLanding::normalizar('red') === null, 'Un nombre de color no se admite.');
$assert(PaletaLanding::normalizar('#000000;}</style><script>') === null, 'Nada que no sea un hex llega al <style>.');
$assert(PaletaLanding::normalizar(['#225036']) === null, 'Un array no es un color.');

$v = PaletaLanding::validar(['verde' => '#225036', 'cafe' => '#5A3A2A', 'beige' => 'e3d5bb', 'crema' => 'nope']);
$assert(count($v['errores']) === 1, 'Un error por el color inválido.');
$assert($v['guardar']['verde'] === null, 'Un color igual al de marca se guarda como NULL.');
$assert($v['guardar']['beige'] === null, 'El de marca sin almohadilla también es NULL.');
$assert($v['guardar']['cafe'] === '#5a3a2a', 'Un color propio se guarda normalizado.');
$assert($v['paleta']['crema'] === 'nope', 'El valor inválido vuelve al formulario tal cual para corregirlo.');

// 6 · Sin base de datos —o sin tabla— la landing se pinta con su CSS.
$assert(PaletaLanding::vigente() === PaletaLanding::MARCA, 'Sin base, la paleta vigente es la de marca.');
$assert(PaletaLanding::estiloEnLinea() === '', 'Sin cambios guardados no se emite <style>.');

// 7 · Cada página de marca emite la paleta DESPUÉS de app.css.
foreach ([
    'views/home/index.php',
    'views/errors/404.php',
    'views/reservaciones/gestionar.php',
] as $vista) {
    $contenido = (string) file_get_contents($root . '/' . $vista);
    $css = strpos($contenido, '/build/css/app.css');
    $paleta = strpos($contenido, 'PaletaLanding::estiloEnLinea()');
    $assert($css !== false && $paleta !== false, "{$vista} carga app.css y la paleta.");
    $assert($paleta > $css, "{$vista} emite la paleta después de app.css.");
}

fwrite(STDOUT, "Paleta de la landing: {$assertions} comprobaciones OK\n");
