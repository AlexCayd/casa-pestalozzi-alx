<?php

declare(strict_types=1);

/*
 * La frontera entre las dos landings.
 *
 * El sitio público tiene dos casas: la portada del RESTAURANTE (/) y la de la
 * PANADERÍA (/panaderia), en calles distintas. Cada archivo pertenece a una de
 * tres capas y su ruta lo dice:
 *
 *   · panadería   — carpetas panaderia/ (controllers/Panaderia, services/Panaderia,
 *                   views/panaderia, src/scss/panaderia, src/js/panaderia).
 *   · restaurante — views/home, src/scss/components, src/js/modules (salvo lo
 *                   que el bundle de la panadería toma de marca).
 *   · marca       — views/components/marca, src/scss/shared, src/scss/layout:
 *                   lo que usan las dos.
 *
 * La regla: panadería y restaurante usan la marca, nunca la una a la otra. Lo
 * que protege esta prueba es que nadie la cruce sin darse cuenta: un include
 * de views/home desde la panadería, un @use de components/ en su hoja, un pie
 * que lea la dirección del restaurante o una portada que lea la panadería.
 * Sólo lee archivos; no toca la base.
 */

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

/**
 * El código de un archivo sin sus comentarios: un comentario que explica la
 * frontera («la panadería no lee SitioConfig») no es cruzarla.
 */
$sinComentarios = static function (string $ruta, string $contenido): string {
    if (str_ends_with($ruta, '.php')) {
        $codigo = '';
        foreach (token_get_all($contenido) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codigo .= is_array($token) ? $token[1] : $token;
        }
        return $codigo;
    }
    $sinBloques = (string) preg_replace('#/\*.*?\*/#s', '', $contenido);
    return (string) preg_replace('#(^|\s)//.*$#m', '$1', $sinBloques);
};

/** Todos los archivos de una carpeta, con su código (sin comentarios). */
$archivos = static function (string $carpeta) use ($root, $sinComentarios): array {
    $out = [];
    $dir = $root . '/' . $carpeta;
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile()) {
            $ruta = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            $out[$ruta] = $sinComentarios($ruta, (string) file_get_contents($f->getPathname()));
        }
    }
    return $out;
};

/** Ninguno de los archivos de $carpeta contiene ninguna de las $marcas. */
$prohibir = static function (string $carpeta, array $marcas, string $porque) use ($archivos, $assert): void {
    $lista = $archivos($carpeta);
    $assert($lista !== [], "{$carpeta} existe y tiene archivos.");
    foreach ($lista as $ruta => $contenido) {
        foreach ($marcas as $marca) {
            $assert(!str_contains($contenido, $marca), "{$ruta} no contiene «{$marca}»: {$porque}");
        }
    }
};

// 1 · La panadería no toma nada del restaurante.
$prohibir('views/panaderia', ['views/home/', "'/../home/", '_nav.php', '_footer.php'], 'la panadería usa los componentes de marca, no las vistas de la portada.');
$prohibir('views/panaderia', ['SitioConfig::direccion', 'SitioConfig::telefono', 'SitioConfig::mapsUrl', 'SitioConfig::correo', 'SitioConfig::whatsappEventos', 'SitioConfig::redes'],
    'la panadería está en otra calle; sus datos son de PanaderiaConfig.');
$prohibir('controllers/Panaderia', ['HomeController', 'SitioConfig::direccion', 'SitioConfig::telefono'], 'el controlador de la panadería no conoce la portada.');
$prohibir('services/Panaderia', ['SitioConfig::direccion', 'SitioConfig::telefono', 'SitioConfig::mapsUrl', 'ReservacionConfig'], 'la configuración de la panadería no lee la del restaurante.');
$prohibir('src/scss/panaderia', ["'../components/", '"../components/'], 'la hoja de la panadería toma la marca de shared/, nunca las secciones de la portada.');
$prohibir('src/js/panaderia', ['initMenu', 'initForm', 'initGallery', 'initReservationAccess', 'heroIntro'], 'el JS de la panadería no arranca módulos de la portada.');

// 2 · El restaurante no toma nada de la panadería.
$prohibir('views/home', ['../panaderia/', 'PanaderiaConfig::', 'Services\\Panaderia'],'la portada sólo enlaza a /panaderia; no lee sus datos ni incluye sus vistas.');
$prohibir('src/scss/components', ['panaderia/', 'pan-portada', 'pan-vitrina'], 'las secciones de la portada no dependen de la hoja de la panadería.');
$assert(!preg_match("#@use\s+'panaderia/#", $leer('src/scss/app.scss')), 'app.scss no incluye la hoja de la panadería.');

// 3 · La marca no lee la configuración de ninguna de las dos casas.
$prohibir('views/components/marca', ['SitioConfig::', 'PanaderiaConfig::', 'ReservacionConfig::', 'HorarioOperacionService::'],
    'un componente de marca recibe sus datos por parámetro; si los leyera, firmaría las dos páginas con la dirección de una.');
$prohibir('src/scss/shared/marca', ['pan-', '.hero__', '.menu__'], 'la hoja de marca no conoce las piezas de ninguna página.');

// 4 · Cada casa tiene su bundle, y el de la panadería es una lista explícita.
$gulp = $leer('gulpfile.js');
$assert(str_contains($gulp, '"!src/js/panaderia/**/*.js"'), 'El bundle de la portada excluye src/js/panaderia.');
$assert(preg_match('/panaderiaJs:\s*\[(.*?)\]/s', $gulp, $lista) === 1, 'gulpfile.js declara paths.panaderiaJs.');
$assert(!str_contains($lista[1], 'src/js/**'), 'El bundle de la panadería no usa el glob general: su lista es la declaración de lo que toma de la marca.');
foreach (['menu.js', 'form.js', 'gallery.js', 'hero.js', 'reservation-access.js', 'maridaje.js', 'punto-de-venta.js'] as $deLaPortada) {
    $assert(!str_contains($lista[1], $deLaPortada), "El bundle de la panadería no lleva {$deLaPortada}, que es de la portada.");
}
$assert(str_contains($gulp, 'panaderiaScss: "src/scss/panaderia/app-panaderia.scss"'), 'La panadería tiene su propia hoja.');

$vista = $leer('views/panaderia/index.php');
$assert(str_contains($vista, '/build/css/panaderia.css') && !str_contains($vista, '/build/css/app.css'), 'La panadería carga panaderia.css y no app.css.');
$assert(str_contains($vista, '/build/js/panaderia.min.js') && !str_contains($vista, 'bundle.min.js'), 'La panadería carga panaderia.min.js y no el bundle de la portada.');

// 5 · Lo que se mudó a la capa de marca no se quedó también en la portada.
foreach (['views/home/_cursor.php', 'views/home/_redes.php', 'views/home/_panaderia.php',
          'src/scss/components/_buttons.scss', 'src/scss/components/_footer.scss', 'src/scss/components/_lightbox.scss',
          'src/scss/components/_cursor.scss', 'src/scss/components/_italia.scss', 'src/scss/components/_ornamentos.scss',
          'src/scss/components/_ubicacion.scss', 'src/scss/components/_panaderia.scss'] as $viejo) {
    $assert(!is_file($root . '/' . $viejo), "{$viejo} ya no existe: se movió a la capa de marca o se renombró.");
}

fwrite(STDOUT, "Frontera panadería / restaurante: {$assertions} comprobaciones OK\n");
