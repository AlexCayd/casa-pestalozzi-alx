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
$doc['kpis'][0]['valor'] = '<b>1</b>';
rechazar(static fn () => GeneradorPdf::documento($doc));
echo "PASS fundación informes: periodos, validación y PDF multipágina seguro\n";
