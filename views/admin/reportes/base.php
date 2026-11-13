<?php
/** Plantilla fija: recibe exclusivamente el documento ya validado. */
$corteDia = substr($documento['corteDatos'], 0, 10);
$estadoPeriodo = $documento['periodo']['inicio'] > $corteDia ? 'Futuro' : ($documento['periodo']['fin'] >= $corteDia ? 'Provisional' : 'Observado');
$informeId = $documento['id'] ?? '';
$esVentas = in_array($informeId, ['finanzas', 'analisis', 'tickets'], true);
$esc = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formato = static function ($valor, string $tipo, bool $conSigno = false): string {
    if ($valor === null) return 'No disponible';
    $signo = $valor < 0 ? '-' : ($conSigno && $valor > 0 ? '+' : '');
    if ($tipo === 'moneda') return $signo . '$' . number_format(abs($valor), 2, '.', ',') . ' MXN';
    if ($tipo === 'porcentaje') return $signo . number_format(abs($valor), 1, '.', ',') . ' %';
    if ($tipo === 'cantidad') return number_format($valor, 3, '.', ',');
    return number_format($valor, $tipo === 'entero' ? 0 : 2, '.', ',');
};
?>
<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><title><?= $esc($documento['titulo']) ?></title>
<style>
@font-face { font-family: Montserrat; src: url('<?= $esc($fontsDir) ?>/Montserrat-Regular.ttf') format('truetype'); }
@font-face { font-family: Montserrat; font-weight: bold; src: url('<?= $esc($fontsDir) ?>/Montserrat-Bold.ttf') format('truetype'); }
@page { size: A4 portrait; margin: 36px 42px 54px; }
body { font-family: Montserrat, sans-serif; }
p { margin: 0 0 6px; }
table { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
thead { display: table-header-group; page-break-after: avoid; }
tr { page-break-inside: avoid; }
.kpis { table-layout: fixed; width: 100%; }
.kpis td { width: 33.33%; vertical-align: top; }
.kpis-4 td { width: 25%; }
.valor { line-height: 1.2; font-weight: bold; display: block; }
.comparacion { display: block; }
h1 { line-height: 1.12; }
h2 { page-break-after: avoid; }
.marca { font-weight: bold;  }
th { text-align: left; font-weight: bold; }
th, td { vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
.numero { text-align: right; }
.tabla-datos { table-layout: fixed; }
.fila-resultado td { font-weight: bold; }
.grafica { margin: 8px 0 14px; page-break-inside: avoid; }
.grafica img { width: 100%; height: auto; }
.grafica-nota { font-size: 8.5pt; color: #62686e; margin-bottom: 5px; }
.seccion-corta { page-break-inside: avoid; }
.notas { padding-left: 16px; margin: 0; }
footer { position: fixed; bottom: -35px; left: 0; right: 0; padding-top: 5px; }
body { color: #25282b; font-size: 10pt; line-height: 1.4; }
header { border-bottom: 1px solid #b7bcc1; padding-bottom: 12px; margin-bottom: 14px; }
.marca { color: #555b61; font-size: 8.5pt; letter-spacing: .5px; }
h1, h2 { font-family: Montserrat, sans-serif; color: #25282b; font-weight: bold; }
h1 { font-size: 19pt; margin: 5px 0 9px; }
h2 { font-size: 11pt; margin: 16px 0 8px; }
.meta { color: #565c62; font-size: 8.5pt; }
.resumen { padding: 0; border: 0; background: white; margin-bottom: 12px; }
.kpis { border-collapse: collapse; margin: 12px 0 16px; }
.kpis td { border: 0; border-bottom: 1px solid #d7dadd; border-top: 0; background: white; padding: 8px 10px 10px 0; }
.valor { color: #25282b; font-size: 13pt; margin: 4px 0 2px; }
.etiqueta, .comparacion { color: #565c62; font-size: 8.5pt; }
.comparacion { margin-top: 3px; }
th { background: #f0f1f2; color: #34393e; font-size: 9pt; }
th, td { padding: 6px 8px; border-bottom: 1px solid #d7dadd; }
tbody tr:nth-child(even) { background: white; }
.fila-resultado td { border-top: 1px solid #8d949b; background: #f4f5f6; }
.comparativa { font-size: 9pt; }
.comparativa .secundario { display: block; font-size: 8.5pt; color: #62686e; margin-top: 1px; }
.alcance-periodo { border-left: 2px solid #737b83; padding-left: 9px; margin: 6px 0; color: #50575e; font-size: 9pt; }
.notas { font-size: 8.5pt; line-height: 1.4; }
.notas li { margin-bottom: 6px; }
footer { border-top: 1px solid #b7bcc1; color: #62686e; font-size: 8pt; }
</style></head><body>
<header><div class="marca">Casa Pestalozzi</div><h1><?= $esc($documento['titulo']) ?></h1>
<div class="meta"><?= $esc($documento['contexto']) ?> · Periodo: <?= $esc($documento['periodo']['inicio']) ?> a <?= $esc($documento['periodo']['fin']) ?></div>
<?php if (!empty($documento['periodo']['comparacion']['inicio']) && !empty($documento['periodo']['comparacion']['fin'])): ?><div class="meta">Comparación: <?= $esc($documento['periodo']['comparacion']['inicio']) ?> a <?= $esc($documento['periodo']['comparacion']['fin']) ?></div><?php endif; ?>
<?php if (!empty($documento['provisional'])): ?><div class="meta">Estado: Provisional</div><?php endif; ?>
<div class="meta meta-corte">Corte de datos: <?= $esc($documento['corteDatos']) ?></div></header>
<footer>Documento informativo · Generado: <?= $esc($documento['generadoEn']) ?></footer>
<p class="resumen"><?= $esc($documento['resumen']) ?></p>
<?php if ($esVentas && $estadoPeriodo !== 'Observado'): ?><p class="alcance-periodo"><strong><?= $esc($estadoPeriodo) ?>.</strong> Fechas futuras: actividad registrada, no observada. Las variaciones son provisionales, no la evolución de un periodo completo. <?= $informeId === 'finanzas' ? 'Los gastos cubren todo el rango, incluido futuro; no son egresos ya ocurridos.' : '' ?></p><?php endif; ?>
<?php $longitudKpi = 0; foreach ($documento['kpis'] as $indicador) $longitudKpi = max($longitudKpi, mb_strlen($formato($indicador['valor'], $indicador['formato'])));
$columnasKpi = count($documento['kpis']) === 7 && $longitudKpi <= 18 ? 4 : 3; ?>
<?php if ($documento['kpis']): ?><table class="kpis<?= $columnasKpi === 4 ? ' kpis-4' : '' ?>"><tbody>
<?php foreach (array_chunk($documento['kpis'], $columnasKpi) as $grupo): ?><tr>
<?php foreach ($grupo as $kpi): ?><td><span class="etiqueta"><?= $esc($kpi['etiqueta']) ?></span><span class="valor"><?= $esc($formato($kpi['valor'], $kpi['formato'])) ?></span>
<?php if (!$esVentas && array_key_exists('comparacion', $kpi)): ?><span class="comparacion"><?= $kpi['comparacion'] === null ? 'Sin base comparable' : $esc($formato($kpi['comparacion'], 'porcentaje', true)) ?> · periodo anterior</span><?php endif; ?></td><?php endforeach; ?>
</tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php
$seccionesVisibles = $documento['secciones'];
usort($seccionesVisibles, static fn ($a, $b) => ($a['orden'] ?? 0) <=> ($b['orden'] ?? 0));
$serieDemanda = null;
foreach ($seccionesVisibles as $candidata) {
    if (($candidata['id'] ?? '') === 'serie_demanda' && count($candidata['filas']) <= 7
        && count(array_filter($candidata['filas'], static fn ($fila) => preg_match('/\A\d{4}-\d{2}-\d{2}\z/', (string) $fila[0]))) === count($candidata['filas'])) $serieDemanda = array_column($candidata['filas'], 1, 0);
}
$bloques = [];
foreach ($seccionesVisibles as $seccion) {
    $id = $seccion['id'] ?? '';
    if ($id === 'demanda_top' && $serieDemanda !== null) {
        $duplicada = true;
        foreach ($seccion['filas'] as $fila) if (!array_key_exists($fila[0], $serieDemanda) || $serieDemanda[$fila[0]] !== $fila[1]) $duplicada = false;
        if ($duplicada) continue;
    }
    if ($id === 'tickets_estados') continue; // Cifras exactas ya presentes en los KPI.
    if ($id === 'serie_ventas') {
        $plan = \Services\Shared\GraficaPdf::alcanceTemporal($seccion, $corteDia);
        $grafica = isset($plan['observada']['grafica']) ? \Services\Shared\GraficaPdf::preparar($plan['observada'], $formato, $fuenteGrafica ?? 'Montserrat') : null;
        if ($grafica && !$grafica['tabla']) {
            $bloques[] = ['seccion'=>$plan['observada'], 'grafica'=>$grafica,
                'nota'=>'Solo tramos enteramente observados, terminados antes del ' . $corteDia . '. Corte de datos: ' . $documento['corteDatos'] . '.'];
            if ($plan['restante'] !== null) $bloques[] = ['seccion'=>$plan['restante'], 'grafica'=>null,
                'nota'=>'Totales registrados íntegros; no se divide ni recalcula la parte observada de un tramo parcial.'];
        } else {
            $observaciones = count(array_filter($plan['observada']['filas'], static fn ($fila) => $fila[1] !== null));
            if ($grafica && $observaciones < 2) $grafica['mensaje'] = 'No hay tramos completos observados suficientes para una gráfica.';
            $bloques[] = ['seccion'=>$plan['tabla'], 'grafica'=>$grafica,
                'nota'=>'Corte de datos: ' . $documento['corteDatos'] . '. La tabla conserva todos los tramos e importes registrados.'];
        }
        continue;
    }
    $grafica = isset($seccion['grafica']) ? \Services\Shared\GraficaPdf::preparar($seccion, $formato, $fuenteGrafica ?? 'Montserrat') : null;
    $tablaBreve = ($seccion['grafica']['tipo'] ?? '') === 'barras' && count($seccion['grafica']['series']) === 1 && count($seccion['filas']) <= 5;
    if ($tablaBreve) {
        if (count($seccion['columnas']) === 3) $seccion['anchos'] = [58, 16, 26];
        if ($grafica && !$grafica['tabla']) $grafica = ['imagenes'=>[], 'mensaje'=>'', 'tabla'=>true];
    }
    $bloques[] = ['seccion'=>$seccion, 'grafica'=>$grafica, 'nota'=>''];
}
$unirNotas = false;
foreach ($bloques as $bloqueIndice => $bloque):
    $seccion = $bloque['seccion']; $grafica = $bloque['grafica']; $filas = $seccion['filas'] ?? [];
    $esComparativa = ($seccion['id'] ?? '') === 'comparacion_financiera';
    $esGrafica = $grafica && !$grafica['tabla'];
    $unirNotas = $bloqueIndice === count($bloques) - 1 && ($esGrafica ? count($grafica['imagenes']) === 1 : count($filas) <= 7)
        && array_sum(array_map('strlen', $documento['notas'])) <= 1200;
?><?php if ($unirNotas): ?><div class="seccion-corta"><?php endif; ?><div<?= $seccion['tipo'] === 'texto' || (!$esGrafica && count($filas) <= 5) ? ' class="seccion-corta"' : '' ?>><?php if (!$esGrafica): ?><h2><?= $esc($seccion['titulo']) ?></h2><?php if ($bloque['nota'] !== ''): ?><p class="grafica-nota"><?= $esc($bloque['nota']) ?></p><?php endif; ?><?php endif; ?>
<?php if ($esComparativa): ?>
<table class="tabla-datos comparativa"><colgroup><col style="width:28%"><col style="width:24%"><col style="width:24%"><col style="width:24%"></colgroup><thead><tr><th>Indicador</th><th class="numero">Anterior</th><th class="numero">Actual</th><th class="numero">Diferencia</th></tr></thead><tbody>
<?php foreach ($filas as $filaIndice => $fila): ?><tr<?= in_array($filaIndice, $seccion['resaltadas'] ?? [], true) ? ' class="fila-resultado"' : '' ?>><td><?= $esc($fila[0]) ?></td><td class="numero"><?= $esc($formato($fila[1], 'moneda')) ?></td><td class="numero"><?= $esc($formato($fila[2], 'moneda')) ?></td><td class="numero"><?= $esc($formato($fila[3], 'moneda', true)) ?><?php if ($fila[4] !== null): ?><span class="secundario"><?= $esc($formato($fila[4], 'porcentaje', true)) ?></span><?php else: ?><span class="secundario">Sin base comparable</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php elseif ($seccion['tipo'] === 'texto'): ?><p><?= $esc($seccion['contenido']) ?></p>
<?php elseif ($grafica && !$grafica['tabla']): ?>
<?php foreach ($grafica['imagenes'] as $imagenIndice => $imagen): ?><div class="grafica"><?php if ($imagenIndice === 0): ?><h2><?= $esc($seccion['titulo']) ?></h2><?php if ($bloque['nota'] !== ''): ?><p class="grafica-nota"><?= $esc($bloque['nota']) ?></p><?php endif; ?><?php if (count($grafica['imagenes']) > 1): ?><p class="grafica-nota">Continuaciones con la misma escala; se conservan todas las categorías y todos los valores exactos.</p><?php endif; ?><?php endif; ?><img src="<?= $esc($imagen) ?>" alt="<?= $esc($seccion['titulo']) ?>"></div><?php endforeach; ?>
<?php else: ?><?php if ($grafica && $grafica['mensaje'] !== ''): ?><p class="grafica-nota"><?= $esc($grafica['mensaje']) ?></p><?php endif; ?>
<table class="tabla-datos"><?php if (isset($seccion['anchos'])): ?><colgroup><?php foreach ($seccion['anchos'] as $ancho): ?><col style="width: <?= $ancho ?>%;"><?php endforeach; ?></colgroup><?php endif; ?><thead><tr><?php foreach ($seccion['columnas'] as $i => $columna): ?><th<?= isset($seccion['anchos'][$i]) ? ' style="width: ' . $seccion['anchos'][$i] . '%;"' : '' ?><?= ($seccion['formatos'][$i] ?? 'texto') === 'texto' ? '' : ' class="numero"' ?>><?= $esc($columna) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($filas as $filaIndice => $fila): $valores = array_values($fila); $formatos = array_values($seccion['formatos'] ?? array_fill(0, count($valores), 'texto')); ?><tr<?= in_array($filaIndice, $seccion['resaltadas'] ?? [], true) ? ' class="fila-resultado"' : '' ?>><?php foreach ($valores as $indice => $celda): $tipoCelda = $formatos[$indice]; $claseCelda = $tipoCelda === 'texto' ? '' : ($tipoCelda === 'porcentaje' && $celda === null ? ' class="numero sin-base"' : ' class="numero"'); ?><td<?= isset($seccion['anchos'][$indice]) ? ' style="width: ' . $seccion['anchos'][$indice] . '%;"' : '' ?><?= $claseCelda ?>><?= $tipoCelda === 'texto' ? ($celda === null ? 'No disponible' : $esc($celda)) : ($tipoCelda === 'porcentaje' && $celda === null ? 'Sin base comparable' : $esc($formato($celda, $tipoCelda, in_array($indice, $seccion['conSigno'] ?? [], true)))) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table><?php if (!$filas): ?><p><?= $esc($seccion['vacio'] ?? 'Sin actividad en el periodo.') ?></p><?php endif; ?><?php endif; ?></div><?php endforeach; ?>
<?php $notasVisibles = $documento['notas']; ?>
<?php if ($notasVisibles): ?><div<?= count($notasVisibles) <= 10 && array_sum(array_map('strlen', $notasVisibles)) <= 1200 ? ' class="seccion-corta"' : '' ?>><h2>Notas y alcance</h2><ul class="notas"><?php foreach ($notasVisibles as $nota): ?><li><?= $esc($nota) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($unirNotas): ?></div><?php endif; ?>
</body></html>
