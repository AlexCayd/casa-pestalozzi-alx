<?php

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    $assertions++;
};

$menuPdfPath = $root . '/services/Menu/MenuPdf.php';
$menuPdf = file_get_contents($menuPdfPath);
$menuRoot = realpath(dirname($root . '/services/Menu', 2));
$assert(is_string($menuPdf), 'MenuPdf.php se puede leer.');
$assert($menuRoot === realpath($root), 'La ruta de MenuPdf resuelve a la raíz del proyecto.');
$assert(
    str_contains($menuPdf, '$projectRoot = realpath(dirname(__DIR__, 2));'),
    'MenuPdf calcula la raíz dos niveles sobre services/Menu.'
);
$assert(
    str_contains($menuPdf, "include \$projectRoot . '/views/admin/menu/items-pdf.php';"),
    'MenuPdf reutiliza la raíz para incluir la plantilla.'
);
$assert(is_file($root . '/views/admin/menu/items-pdf.php'), 'Existe la plantilla PDF.');

$fontsDir = $root . '/public/build/fonts';
foreach ([
    'PlayfairDisplay-Regular.ttf',
    'KudosKapsOneNF.ttf',
    'Montserrat-Regular.ttf',
    'Montserrat-Light.ttf',
    'Montserrat-Bold.ttf',
    'Montserrat-Italic.ttf',
] as $font) {
    $assert(is_file($fontsDir . '/' . $font), "Existe la fuente PDF {$font}.");
}
$assert(is_file($root . '/public/build/images/logo.svg'), 'Existe el logotipo usado por el PDF.');

$areasPath = \Services\Analytics\AreasMejora::rutaArchivo();
$normalizedAreasPath = str_replace('\\', '/', $areasPath);
$normalizedExpectedAreasPath = str_replace('\\', '/', $root . '/storage/areas_de_mejora.json');
$assert($normalizedAreasPath === $normalizedExpectedAreasPath, 'AreasMejora apunta a storage en la raíz del proyecto.');

$clientSession = file_get_contents($root . '/services/Security/ReservationClientSession.php');
$assert(
    is_string($clientSession)
        && str_contains(
            $clientSession,
            "dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'sessions'"
        ),
    'La ruta local de sesiones apunta a storage/sessions en la raíz del proyecto.'
);
// Una instalación limpia no versiona sesiones: comprobar que el runtime crea su destino local.
$_ENV['APP_ENV']='production'; putenv('APP_ENV=production');
$_SERVER['REMOTE_ADDR']='127.0.0.1';
\Services\Security\ReservationClientSession::start();
$assert(is_dir($root . '/storage/sessions'), 'El runtime crea el directorio local de sesiones.');
$assert(realpath(session_save_path())===realpath($root.'/storage/sessions'), 'La sesión local utiliza el destino del proyecto.');
session_destroy();

fwrite(STDOUT, "PASS: rutas de Services y recursos de MenuPdf ({$assertions} comprobaciones).\n");
