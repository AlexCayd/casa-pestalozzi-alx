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
    'color-mix(in srgb, var(--verde-ink) 79%, var(--bg))',
    'color-mix(in srgb, var(--brand-verde) 72%, black)',
    'color-mix(in srgb, var(--brand-verde) 58%, black)',
    'color-mix(in srgb, var(--brand-oro) 70%, var(--brand-crema))',
] as $mezcla) {
    $assert(str_contains($reset, $mezcla), "_reset.scss sigue usando {$mezcla}.");
}

// 3 · Las mezclas en PHP siguen dando la tabla del PDF. La fórmula se validó
//     contra el navegador con la paleta anterior (la tabla escrita a mano que
//     tenía items-pdf.php salía idéntica); esto la fija con la del manual.
$esperadoPdf = [
    'verde' => '#214f36', 'oro' => '#d1ab66', 'beige' => '#e3d5bb', 'crema' => '#f5f1e8',
    'bg' => '#f5f1e8', 'txt' => '#183927', 'txt_strong' => '#132e1f', 'txt_mute' => '#445e4e',
    'txt_faint' => '#5c7263', 'accent' => '#214f36', 'line_soft' => '#dbbf8a', 'line_strong' => '#486151',
    'v_bg' => '#214f36', 'v_txt' => '#f5f1e8', 'v_txt_mute' => '#c6cdc1', 'v_accent' => '#dcc08d',
];
$assert(PaletaLanding::paraPdf(PaletaLanding::MARCA) === $esperadoPdf, 'paraPdf() con la marca da la tabla del PDF.');
$assert(
    PaletaLanding::paraPdf(['verde' => '#225036', 'oro' => '#d1ab66', 'beige' => '#e3d5bb', 'crema' => '#f5f1e8'])['txt'] === '#183a27',
    'La mezcla del texto reproduce el valor medido en el navegador (verde-deep de la paleta anterior).'
);

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

$v = PaletaLanding::validar(['verde' => '#214F36', 'oro' => '#C9A15A', 'beige' => 'e3d5bb', 'crema' => 'nope']);
$assert(count($v['errores']) === 1, 'Un error por el color inválido.');
$assert($v['guardar']['verde'] === null, 'Un color igual al de marca se guarda como NULL.');
$assert($v['guardar']['beige'] === null, 'El de marca sin almohadilla también es NULL.');
$assert($v['guardar']['oro'] === '#c9a15a', 'Un color propio se guarda normalizado.');
$assert($v['paleta']['crema'] === 'nope', 'El valor inválido vuelve al formulario tal cual para corregirlo.');

// 6 · Sin base de datos —o sin tabla— la landing se pinta con su CSS.
$assert(PaletaLanding::vigente() === PaletaLanding::MARCA, 'Sin base, la paleta vigente es la de marca.');
$assert(PaletaLanding::estiloEnLinea() === '', 'Sin cambios guardados no se emite <style>.');

// 7 · Cada página de marca emite la paleta DESPUÉS de app.css.
foreach ([
    'views/home/index.php',
    'views/feedback/index.php',
    'views/errors/404.php',
    'views/reservaciones/gestionar.php',
    'views/layout.php',
] as $vista) {
    $contenido = (string) file_get_contents($root . '/' . $vista);
    $css = strpos($contenido, '/build/css/app.css');
    $paleta = strpos($contenido, 'PaletaLanding::estiloEnLinea()');
    $assert($css !== false && $paleta !== false, "{$vista} carga app.css y la paleta.");
    $assert($paleta > $css, "{$vista} emite la paleta después de app.css.");
}

fwrite(STDOUT, "Paleta de la landing: {$assertions} comprobaciones OK\n");
