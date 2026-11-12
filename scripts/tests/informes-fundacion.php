<?php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Services\Shared\GeneradorPdf;
use Services\Shared\RangoPeriodo;

function comprobar(bool $condicion, string $mensaje): void {
    if (!$condicion) throw new RuntimeException($mensaje);
}
function rechazar(callable $accion): void {
    try { $accion(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Se aceptó una entrada inválida.');
}
$rango = RangoPeriodo::resolverInforme(['desde' => '2024-01-01', 'hasta' => '2024-12-31'], '2026-11-06');
comprobar($rango['dias'] === 366 && $rango['prevEnd'] === '2023-12-31', 'Rango bisiesto incorrecto');
foreach ([7 => ['día', 7], 8 => ['semana', 2], 93 => ['semana', 14], 94 => ['mes', 4], 366 => ['mes', 12]] as $cantidad => [$modo, $filas]) {
    $dias = [];
    $inicio = new DateTimeImmutable('2024-01-01');
    for ($i = 0; $i < $cantidad; $i++) $dias[$inicio->modify('+' . $i . ' days')->format('Y-m-d')] = $i + 1;
    $agrupada = RangoPeriodo::agruparSerieInforme($dias);
    comprobar($agrupada['modo'] === $modo && count($agrupada['filas']) === $filas && array_sum(array_column($agrupada['filas'], 1)) === array_sum($dias), 'Agrupación o total incorrecto para ' . $cantidad . ' días');
}
comprobar(RangoPeriodo::resolverInforme([], '2026-11-06')['start'] === '2026-10-08', 'Predeterminado incorrecto');
foreach ([
    ['desde' => '2026-02-29', 'hasta' => '2026-03-01'],
    ['desde' => '2026-11-01\n', 'hasta' => '2026-11-02'],
    ['desde' => '2026-11-03', 'hasta' => '2026-11-02'],
    ['desde' => '2024-01-01', 'hasta' => '2025-01-01'],
    ['desde' => '2026-01-01'], ['rango' => "30\n"], ['rango' => []],
    ['desde' => '1000-01-01', 'hasta' => '1000-01-01'],
] as $invalido) {
    // La segunda cadena debe contener un salto real.
    $invalido = array_map(static fn ($v) => is_string($v) ? str_replace('\\n', "\n", $v) : $v, $invalido);
    rechazar(static fn () => RangoPeriodo::resolverInforme($invalido, '2026-11-06'));
}
$doc = [
    'titulo' => 'Informe <script>hostil</script> & café', 'contexto' => 'Contexto de prueba',
    'periodo' => ['inicio' => '2026-10-01', 'fin' => '2026-10-31'],
    'generadoEn' => '2026-11-06 12:00:00', 'corteDatos' => '2026-11-06 12:00:00',
    'resumen' => 'Datos sintéticos & seguros.',
    'kpis' => [['etiqueta' => 'Venta', 'valor' => 123.5, 'formato' => 'moneda', 'comparacion' => null]],
    'secciones' => [['titulo' => 'Prueba', 'tipo' => 'tabla', 'columnas' => ['Concepto', 'Importe'],
        'formatos' => ['texto', 'moneda'], 'filas' => array_fill(0, 100, ['<img src="https://example.com/x">', 42.0])]],
    'notas' => ['Documento de prueba.'],
];
$pdf = GeneradorPdf::documento($doc);
comprobar(str_starts_with($pdf->output(), '%PDF-'), 'No se generó PDF');
comprobar($pdf->getCanvas()->get_page_count() > 1, 'No se probó paginación');
comprobar($pdf->getOptions()->getIsRemoteEnabled() === false && $pdf->getOptions()->getIsPhpEnabled() === false && $pdf->getOptions()->getIsJavascriptEnabled() === false, 'Opciones inseguras');
rechazar(static fn () => GeneradorPdf::emitir($doc, '../informe.pdf'));
$serieDoc = $doc;
$serieDoc['secciones'] = [['titulo' => 'Serie segura', 'tipo' => 'serie',
    'grafica' => ['tipo' => 'barras', 'unidad' => 'MXN', 'series' => [1]],
    'columnas' => ['Tramo', 'Importe'], 'formatos' => ['texto', 'moneda'],
    'filas' => [['<img src="https://example.com/hostil">', -10.0], ['Cero', 0], ['Ausente', null], ['Positivo', 20.0]]]];
$htmlSerie = static function (array $documento): string {
    $fontsDir = str_replace('\\', '/', realpath(dirname(__DIR__, 2) . '/public/build/fonts'));
    ob_start();
    require dirname(__DIR__, 2) . '/views/admin/reportes/base.php';
    return ob_get_clean();
};
$html = $htmlSerie($serieDoc);
$formatear = static fn ($v, $t): string => $v === null ? 'No disponible' : ($v < 0 ? '-' : '') . '$' . number_format(abs($v), 2, '.', ',') . ' MXN';
$resultado = \Services\Shared\GraficaPdf::preparar($serieDoc['secciones'][0], $formatear);
$svg = base64_decode(substr($resultado['imagenes'][0], strpos($resultado['imagenes'][0], ',') + 1));
comprobar(!str_contains($svg, '<img') && str_contains($svg, '&lt;img') && !str_contains($svg, 'href='), 'Etiqueta de gráfica no escapada o recurso externo');
comprobar(str_contains($svg, '-$10.00 MXN') && str_contains($svg, 'No disponible'), 'Negativo o ausencia ocultados');
$xml = new DOMDocument(); comprobar($xml->loadXML($svg), 'SVG inválido');
$xp = new DOMXPath($xml); $xp->registerNamespace('s', 'http://www.w3.org/2000/svg');
$origen = (float) $xp->query('//s:line[@stroke-width="2"]')->item(0)->getAttribute('x1');
$barras = $xp->query('//s:rect[@height="9"]');
comprobar($barras->length === 2, 'Cero o ausencia generan barras artificiales');
comprobar((float)$barras->item(0)->getAttribute('x') < $origen && abs((float)$barras->item(1)->getAttribute('x') - $origen) < .01, 'El negativo no queda a la izquierda del cero común');
$serieDoc['secciones'][0]['filas'] = [['Extremo', 1.0e308], ['Mitad', -5.0e307]];
$html = $htmlSerie($serieDoc);
comprobar(!str_contains($html, 'data:image/svg') && str_contains($html, 'preservar la legibilidad'), 'Datos extremos deben conservar tabla sin geometría infinita');
$serieDoc['secciones'][0]['filas'] = [['Sin actividad', 0], ['Sin fuente', null]];
$html = $htmlSerie($serieDoc);
comprobar(!str_contains($html, 'data:image/svg') && str_contains($html, 'Sin actividad distinta de cero'), 'Gráfica artificial en serie vacía');
comprobar(str_starts_with(GeneradorPdf::documento($serieDoc)->output(), '%PDF-'), 'Serie sin actividad no renderizada');
$serieDoc['secciones'][0]['grafica']['tipo'] = 'lineas';
$serieDoc['secciones'][0]['filas'] = [['2026-01-01', 1234.56]];
comprobar(\Services\Shared\GraficaPdf::preparar($serieDoc['secciones'][0], $formatear)['tabla'], 'Un punto no permite inferir tendencia');
$serieDoc['secciones'][0]['filas'] = [];
for ($i = 1; $i <= 14; $i++) $serieDoc['secciones'][0]['filas'][] = ['2026-01-' . str_pad((string)$i,2,'0',STR_PAD_LEFT), $i * 1234.56];
$graficas = \Services\Shared\GraficaPdf::preparar($serieDoc['secciones'][0], $formatear);
$todos = implode('', array_map(static fn ($uri) => base64_decode(substr($uri, strpos($uri, ',') + 1)), $graficas['imagenes']));
comprobar(str_contains($todos, '1,234.56') && str_contains($todos, '17,283.84'), 'Las etiquetas pierden centavos o valores exactos');
comprobar(substr_count($todos, '<circle ') === 14 && count($graficas['imagenes']) > 1, 'Faltan puntos o no se separan rótulos extensos');
$serieConLoteCero = $serieDoc['secciones'][0];
for ($i = 7; $i < 14; $i++) $serieConLoteCero['filas'][$i][1] = 0;
$originalConLoteCero = $serieConLoteCero;
$sinPanelVacio = \Services\Shared\GraficaPdf::preparar($serieConLoteCero, $formatear);
comprobar($sinPanelVacio['tabla'] && !$sinPanelVacio['imagenes'] && $serieConLoteCero === $originalConLoteCero, 'Un lote temporal entero cero debe conservar la serie íntegra en tabla, sin mutar tramos/importes');
$serieTemporal = ['tipo'=>'serie', 'titulo'=>'Ventas', 'columnas'=>['Periodo','Ventas'], 'formatos'=>['texto','moneda'],
    'grafica'=>['tipo'=>'columnas','unidad'=>'MXN','series'=>[1]],
    'filas'=>[['2026-11-10', 1.25], ['2026-11-11 a 2026-11-17', 22.78], ['2026-11-18', 0], ['2026-11-19', -5.67], ['2026-11-20', null]]];
$originalTemporal = $serieTemporal;
$presentada = \Services\Shared\GraficaPdf::alcanceTemporal($serieTemporal, '2026-11-12');
comprobar($serieTemporal === $originalTemporal && !isset($presentada['grafica']), 'La presentación temporal muta fuente o grafica futuro como observado');
comprobar(array_column($presentada['filas'], 1) === array_column($serieTemporal['filas'], 1), 'La clasificación temporal cambia importes/null/ceros');
comprobar(array_column($presentada['filas'], 0) === array_column($serieTemporal['filas'], 0), 'La clasificación temporal parte o elimina tramos');
comprobar(array_column($presentada['filas'], 2) === ['Observado', 'Parcial al corte; total registrado del tramo', 'Sin actividad registrada; no observado', 'Registrado futuro; no observado', 'No disponible; no observado'], 'Alcances temporales incorrectos');
comprobar(\Services\Shared\GraficaPdf::alcanceTemporal($serieTemporal, '2026-11-21') === $serieTemporal, 'Se altera una serie enteramente observada');
$serieDoc['secciones'][0]['grafica']['series'] = [0];
rechazar(static fn () => GeneradorPdf::documento($serieDoc));
$doc['kpis'][0]['valor'] = '<b>1</b>';
rechazar(static fn () => GeneradorPdf::documento($doc));
echo "PASS fundación informes: periodos, validación, gráficas seguras/exactas y PDF multipágina seguro\n";
