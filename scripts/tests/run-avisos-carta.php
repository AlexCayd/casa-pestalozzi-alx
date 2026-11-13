<?php

declare(strict_types=1);

/*
 * Contrato de los avisos de la carta (Configuración → Avisos de la carta).
 *
 * Lo que protege, sin base de datos:
 *   · el marcado ligero (## subtítulo, **negritas**, párrafos) y que TODO lo
 *     demás se escape: el PDF imprime el HTML que sale de aquí tal cual;
 *   · la validación por campo del formulario;
 *   · la plantilla del PDF: los avisos del inicio antes de la primera
 *     sección, los del final antes de la despedida, y nada del texto
 *     guardado llega sin escapar;
 *   · que la lectura del PDF sea tolerante (sin base, sin avisos y sin error);
 *   · DDL, migración, rutas y permiso (prefijo /admin/configuracion).
 * El espejo en JS del marcado lo vigila run-avisos-carta-ui.cjs con los
 * mismos casos.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Configuration\AvisosCarta;
use Services\Security\Permisos;

$root = dirname(__DIR__, 2);
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    $assertions++;
};
$igual = static function ($obtenido, $esperado, string $message) use ($assert): void {
    $assert($obtenido === $esperado, $message . ' — obtenido: ' . var_export($obtenido, true));
};

// 1 · Marcado → HTML. Los mismos casos están en run-avisos-carta-ui.cjs.
$casos = json_decode((string) file_get_contents($root . '/scripts/tests/fixtures/avisos-carta-marcado.json'), true);
$assert(is_array($casos) && count($casos) >= 10, 'Los casos de marcado compartidos con el JS existen.');
foreach ($casos as $caso) {
    $igual(AvisosCarta::html($caso['entrada'], $caso['clases'] ?? []), $caso['html'], 'Marcado: ' . $caso['nombre']);
}

// 2 · Extracto para la lista.
$igual(AvisosCarta::extracto("## Formas de pago\nEfectivo **y** tarjeta"), 'Formas de pago · Efectivo y tarjeta', 'El extracto quita las marcas y une los renglones.');
$largo = AvisosCarta::extracto(str_repeat('palabra ', 40), 30);
$assert(mb_strlen($largo) <= 30 && str_ends_with($largo, '…'), 'El extracto largo se corta con puntos suspensivos.');

// 3 · Validación por campo.
$vacio = AvisosCarta::validar([]);
foreach (['titulo', 'contenido', 'cartas'] as $campo) {
    $assert(isset($vacio['errores'][$campo]), "Un aviso vacío marca el campo {$campo}.");
}

$bueno = AvisosCarta::validar([
    'titulo' => "  Alergias   e\tintolerancias ",
    'contenido' => "Línea uno   \r\nLínea dos\r\n\r\n\r\n\r\nOtro párrafo\t con tab",
    'cartas' => ['comida', 'maridaje', 'panaderia'],
    'activo' => '1',
]);
$igual($bueno['errores'], [], 'Un aviso completo no tiene errores.');
$igual($bueno['aviso']['titulo'], 'Alergias e intolerancias', 'El título colapsa espacios y tabuladores.');
$igual($bueno['aviso']['contenido'], "Línea uno\nLínea dos\n\nOtro párrafo  con tab", 'El contenido normaliza saltos, espacios finales y renglones en blanco.');
$igual($bueno['aviso']['cartas'], ['comida' => true, 'maridaje' => true], 'Sólo las cartas conocidas; una ajena (panaderia) se ignora.');
$assert(!array_key_exists('posicion', $bueno['aviso']), 'El aviso no lleva lugar: todos van al final de la carta.');
$assert($bueno['aviso']['activo'] === true, 'activo=1 enciende el aviso.');
$assert(AvisosCarta::validar(['activo' => '0'] + $bueno['aviso'])['aviso']['activo'] === false, 'Sin activo=1 queda pausado.');

$con = static fn (array $cambio): array => AvisosCarta::validar($cambio + ['titulo' => 'T', 'contenido' => 'C', 'cartas' => ['comida']]);
$assert(isset($con(['titulo' => str_repeat('a', AvisosCarta::MAX_TITULO + 1)])['errores']['titulo']), 'Un título de más de 80 caracteres se rechaza.');
$assert(!isset($con(['titulo' => str_repeat('á', AvisosCarta::MAX_TITULO)])['errores']['titulo']), 'El límite del título cuenta caracteres, no bytes.');
$assert(isset($con(['contenido' => str_repeat('a', AvisosCarta::MAX_CONTENIDO + 1)])['errores']['contenido']), 'Un contenido de más de 1500 caracteres se rechaza.');
$assert(isset($con(['contenido' => implode("\n", array_fill(0, AvisosCarta::MAX_RENGLONES + 1, 'x'))])['errores']['contenido']), 'Más de 40 renglones se rechaza.');
$assert(isset($con(['contenido' => 'Hola <b>mundo</b>'])['errores']['contenido']), 'Las etiquetas HTML se rechazan con un mensaje que explica las marcas.');
$assert(!isset($con(['contenido' => 'Precios < $100 y > $50'])['errores']['contenido']), 'Un signo < suelto no es una etiqueta.');
$assert(isset($con(['contenido' => "##\n\n   "])['errores']['contenido']), 'Un contenido sin texto (sólo marcas) se rechaza.');
$assert(isset($con(['cartas' => ['panaderia']])['errores']['cartas']), 'Una carta ajena no cuenta como carta elegida.');
$assert(isset($con(['cartas' => 'comida'])['errores']['cartas']) === false, 'Una carta suelta (no lista) también se acepta.');
$manual = $con(['posicion' => 'inicio']);
$assert($manual['errores'] === [] && !array_key_exists('posicion', $manual['aviso']), 'Una posición enviada a mano se ignora: no hay inicio.');
$assert(isset($con(['titulo' => ['x']])['errores']['titulo']), 'Un título que no es texto cuenta como vacío.');

// 4 · Plantillas del editor.
$pagos = AvisosCarta::nuevo('pagos');
$igual($pagos['cartas'], ['comida' => true, 'maridaje' => true], 'La plantilla de pagos se imprime en las dos cartas.');
$igual(AvisosCarta::nuevo('inexistente')['titulo'], '', 'Una plantilla desconocida da un aviso en blanco.');
foreach (AvisosCarta::PLANTILLAS as $clave => $plantilla) {
    $assert(!array_key_exists('posicion', $plantilla), "La plantilla {$clave} no elige lugar.");
    $v = AvisosCarta::validar(['titulo' => $plantilla['titulo'], 'contenido' => $plantilla['contenido'], 'cartas' => $plantilla['cartas'], 'activo' => '1']);
    $igual($v['errores'], [], "La plantilla {$clave} es un aviso válido.");
}

// 5 · Lectura tolerante del PDF: sin base no hay avisos, ni error.
$igual(AvisosCarta::paraPdf('comida'), [], 'Sin base de datos, la carta sale sin avisos.');
$igual(AvisosCarta::paraPdf('panaderia'), [], 'Una carta que no es de Restaurante no tiene avisos.');

// 6 · La plantilla del PDF.
$clasesPdf = ['h2' => 'aviso__h2', 'p' => 'aviso__p', 'b' => 'aviso__b'];
$avisoPdf = static fn (string $titulo, string $contenido): array => ['titulo' => $titulo, 'html' => AvisosCarta::html($contenido, $clasesPdf)];
$pintar = static function (array $categorias, array $avisos) use ($root): string {
    $fontsDir = $root . '/public/build/fonts';
    $logoRuta = $root . '/public/build/images/logo.svg';
    $pdfAvisos = $avisos;
    ob_start();
    include $root . '/views/admin/menu/items-pdf.php';
    return (string) ob_get_clean();
};
$plato = static fn (string $n): object => (object) ['nombre' => $n, 'descripcion' => 'Desc', 'precio' => 100];
$categorias = [
    ['nombre' => 'Entradas', 'platillos' => [$plato('Uno'), $plato('Dos')]],
    ['nombre' => 'Postres', 'platillos' => [$plato('Tres')]],
];
$avisos = [
    $avisoPdf('<b>Temporada</b>', "Pregunta **hoy**\n<img src=x onerror=alert(1)>"),
    $avisoPdf('Pagos', "## Formas de pago\nEfectivo"),
];
$html = $pintar($categorias, $avisos);
$assert(str_contains($html, '&lt;b&gt;Temporada&lt;/b&gt;') && !str_contains($html, '<b>Temporada</b>'), 'El título del aviso se escapa en el PDF.');
$assert(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;') && !str_contains($html, '<img src=x'), 'El contenido guardado nunca llega como HTML al PDF.');
$assert(str_contains($html, '<strong class="aviso__b">hoy</strong>'), 'Las negritas llegan con la clase del PDF.');
$assert(str_contains($html, '<h2 class="aviso__h2">Formas de pago</h2>'), 'El subtítulo llega con la clase del PDF.');
$posPrimeraSeccion = strpos($html, '<tr class="cat-row');
$posUltimoPlato = strpos($html, '>Tres<');
$posCierre = strpos($html, '<tr class="cierre-row">');
$posTemporada = strpos($html, '&lt;b&gt;Temporada&lt;/b&gt;');
$posPagos = strpos($html, '<p class="aviso__titulo">Pagos</p>');
$posPrimerAviso = strpos($html, '<tr class="aviso-row');
$assert($posPrimeraSeccion !== false && $posPrimerAviso !== false && $posPrimerAviso > $posUltimoPlato, 'Ningún aviso va antes de la primera sección: todos van después del último platillo.');
$assert($posTemporada !== false && $posPagos !== false && $posUltimoPlato < $posTemporada && $posTemporada < $posPagos && $posPagos < $posCierre, 'Los avisos van al final, en su orden y antes de la despedida.');
$assert(str_contains($html, 'cat-row first'), 'La primera sección conserva su arranque pegado a la portada.');
$assert(!str_contains($html, 'aviso-row--'), 'No quedan variantes de lugar en las filas de aviso.');
$assert(!str_contains($html, 'col-der celda-cierre'), 'Con avisos al final, la despedida no se mete en la celda vacía (quedaría antes de ellos).');

// Dos columnas: dos avisos comparten fila, una celda por columna; uno que
// sobra va solo y a lo ancho. La última fila se ata a la despedida.
$filasAviso = static fn (string $h): int => substr_count($h, '<tr class="aviso-row');
$assert($filasAviso($html) === 1 && substr_count($html, '<td class="aviso-celda">') === 1 && substr_count($html, '<td class="aviso-celda aviso-celda--der">') === 1 && !str_contains($html, '<td class="aviso-solo"'), 'Dos avisos van en una sola fila, uno por columna.');
$assert(str_contains($html, '<tr class="aviso-row ultimo">'), 'La única fila de avisos es la última y se ata a la despedida.');

$tres = $pintar($categorias, [$avisoPdf('A1', 'uno'), $avisoPdf('A2', 'dos'), $avisoPdf('A3', 'tres')]);
$posPar = strpos($tres, '<tr class="aviso-row">');
$posSolo = strpos($tres, '<tr class="aviso-row ultimo"><td class="aviso-solo" colspan="2">');
$assert($filasAviso($tres) === 2 && $posPar !== false && $posSolo !== false && $posPar < $posSolo, 'Con tres avisos: un par y el tercero solo a lo ancho, que cierra antes de la despedida.');
$assert(strpos($tres, '>A1<') < strpos($tres, '>A2<') && strpos($tres, '>A2<') < $posSolo && strpos($tres, '>A3<') > $posSolo, 'Los avisos se leen en orden: izquierda, derecha y después el que sobra.');

$uno = $pintar($categorias, [$avisoPdf('Solo', 'uno')]);
$assert($filasAviso($uno) === 1 && str_contains($uno, '<tr class="aviso-row ultimo"><td class="aviso-solo" colspan="2">') && !str_contains($uno, '<td class="aviso-celda'), 'Un aviso único va a lo ancho, como antes de las dos columnas.');

$sinAvisos = $pintar($categorias, []);
$assert(!str_contains($sinAvisos, '<tr class="aviso-row') && str_contains($sinAvisos, 'cat-row first') && str_contains($sinAvisos, 'col-der celda-cierre'), 'Sin avisos, la carta queda exactamente como antes.');
$vacia = $pintar([], $avisos);
$assert(str_contains($vacia, 'class="empty"') && substr_count($vacia, '<div class="aviso">') === 2 && str_contains($vacia, 'aviso-celda--der'), 'Con la carta vacía, los avisos se imprimen bajo el mensaje, también en dos columnas.');

// 7 · DDL, rutas, permiso y consumidores. Sin migración aparte: database/
//     sólo lleva ddl.sql, deploy.sql y development.sql.
$ddl = (string) file_get_contents($root . '/database/ddl.sql');
$assert(preg_match('/^DROP TABLE IF EXISTS[^;]*\bconfiguracion_avisos\b/m', $ddl) === 1, 'ddl.sql borra configuracion_avisos antes de crearla.');
$assert(preg_match('/CREATE TABLE configuracion_avisos \((.*?)\) ENGINE/s', $ddl, $enDdl) === 1, 'ddl.sql crea configuracion_avisos.');
$assert(!is_file($root . '/database/actualizaciones/avisos-carta.sql'), 'La tabla vive sólo en ddl.sql, sin migración aparte.');
$assert(!str_contains($enDdl[1], 'posicion'), 'configuracion_avisos no guarda lugar: todos los avisos van al final.');

$rutas = (string) file_get_contents($root . '/public/index.php');
foreach ([
    "get('/admin/configuracion/avisos', [AdminConfigurationController::class, 'avisos'])",
    "post('/admin/configuracion/avisos', [AdminConfigurationController::class, 'guardarAviso'])",
    "post('/admin/configuracion/avisos/estado', [AdminConfigurationController::class, 'cambiarEstadoAviso'])",
    "post('/admin/configuracion/avisos/mover', [AdminConfigurationController::class, 'moverAviso'])",
    "post('/admin/configuracion/avisos/eliminar', [AdminConfigurationController::class, 'eliminarAviso'])",
] as $ruta) {
    $assert(str_contains($rutas, $ruta), "Ruta registrada: {$ruta}");
}
foreach (['/admin/configuracion/avisos', '/admin/configuracion/avisos/estado', '/admin/configuracion/avisos/mover', '/admin/configuracion/avisos/eliminar'] as $url) {
    $igual(Permisos::clasificar($url), ['tipo' => 'modulos', 'modulos' => ['configuration']], "{$url} exige el módulo configuration.");
    $igual(Permisos::clasificarDirecto($url), ['tipo' => 'modulos', 'modulos' => ['configuration']], "{$url} exige configuration también en multinegocio.");
}

$menuPdf = (string) file_get_contents($root . '/services/Menu/MenuPdf.php');
$assert(str_contains($menuPdf, "AvisosCarta::paraPdf(\$carta)"), 'La carta pública de Restaurante imprime sus avisos.');
$assert(str_contains($menuPdf, "\$datos['contexto']==='restaurante'?\\Services\\Configuration\\AvisosCarta::paraPdf"), 'El PDF administrativo sólo lleva avisos en Restaurante: la Panadería no.');

$servicio = (string) file_get_contents($root . '/services/Configuration/AvisosCarta.php');
$assert(substr_count($servicio, "ContextoNegocio::exigir('configuration', 'editar', 'restaurante', true)") === 1
    && str_contains($servicio, 'ContextoNegocio::transaccion('), 'Toda escritura relee configuration/editar de Restaurante dentro de la transacción.');

$vista = (string) file_get_contents($root . '/views/admin/configuration/avisos.php');
$assert(!str_contains($vista, 'name="posicion"') && !str_contains((string) file_get_contents($root . '/src/js/admin/configuration/avisos.js'), 'posicion'), 'El editor ya no ofrece elegir lugar.');
require_once $root . '/views/admin/partials/_icons.php';
preg_match_all("/admin_icon\\('([a-z-]+)'/", $vista, $iconos);
foreach (array_unique($iconos[1]) as $icono) {
    $assert(admin_icon($icono) !== '', "El icono «{$icono}» existe en el catálogo del panel.");
}
$assert(!preg_match('/\b(alert|confirm|prompt)\s*\(/', (string) file_get_contents($root . '/src/js/admin/configuration/avisos.js')), 'avisos.js no usa diálogos nativos.');
$scss = (string) file_get_contents($root . '/src/scss/admin/modules/_config-avisos.scss');
$assert(!preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(/', $scss), 'La hoja de avisos sólo usa tokens: ni hex ni rgba.');

echo "Avisos de la carta: {$assertions} comprobaciones OK\n";
