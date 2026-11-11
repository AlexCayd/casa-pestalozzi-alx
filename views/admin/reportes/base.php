<?php
/** Plantilla fija: recibe exclusivamente el documento ya validado. */
$esc = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$formato = static function ($valor, string $tipo): string {
    if ($valor === null) return 'No disponible';
    if ($tipo === 'moneda') return '$' . number_format($valor, 2, '.', ',') . ' MXN';
    if ($tipo === 'porcentaje') return number_format($valor, 1, '.', ',') . ' %';
    if ($tipo === 'cantidad') return number_format($valor, 3, '.', ',');
    return number_format($valor, $tipo === 'entero' ? 0 : 2, '.', ',');
};
?>
<!doctype html>
<html lang="es"><head><meta charset="UTF-8"><title><?= $esc($documento['titulo']) ?></title>
<style>
@font-face { font-family: Montserrat; src: url('<?= $esc($fontsDir) ?>/Montserrat-Regular.ttf') format('truetype'); }
@font-face { font-family: Montserrat; font-weight: bold; src: url('<?= $esc($fontsDir) ?>/Montserrat-Bold.ttf') format('truetype'); }
@font-face { font-family: Playfair; src: url('<?= $esc($fontsDir) ?>/PlayfairDisplay-Regular.ttf') format('truetype'); }
@page { size: A4 portrait; margin: 36px 42px 54px; }
body { font-family: Montserrat, sans-serif; font-size: 8.5pt; line-height: 1.45; color: #214F36; }
header { border-bottom: 2px solid #D1AB66; padding-bottom: 12px; margin-bottom: 15px; }
.marca { font-size: 8pt; letter-spacing: 1px; font-weight: bold; text-transform: uppercase; color: #465f50; }
h1 { font-family: Playfair, serif; font-size: 20pt; line-height: 1.12; margin: 5px 0 4px; font-weight: normal; color: #214F36; }
.meta { color: #465f50; font-size: 7.5pt; }
.meta-corte { margin-top: 2px; }
h2 { font-family: Playfair, serif; font-size: 13pt; margin: 17px 0 7px; font-weight: normal; color: #214F36; page-break-after: avoid; }
p { margin: 0 0 6px; }
table { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
thead { display: table-header-group; page-break-after: avoid; }
tr { page-break-inside: avoid; }
tbody tr:nth-child(even) { background: #F5F1E8; }
.kpis { table-layout: fixed; border-spacing: 5px 4px; border-collapse: separate; margin: 2px 0 4px; width: 100%; }
.kpis tr { page-break-inside: avoid; }
.kpis td { width: 33.33%; border: 1px solid #E3D5BB; border-top: 2px solid #D1AB66; background: #F5F1E8; padding: 8px; vertical-align: top; }
.valor { font-size: 12pt; line-height: 1.2; font-weight: bold; display: block; margin: 2px 0; color: #214F36; }
.etiqueta, .comparacion { font-size: 7pt; color: #465f50; }
.comparacion { display: block; }
.resumen { padding: 10px 12px; margin-bottom: 10px; background: #F5F1E8; border: 1px solid #E3D5BB; }
th { text-align: left; background: #214F36; color: #F5F1E8; font-size: 7pt; font-weight: bold; }
th, td { padding: 5px 6px; border-bottom: 1px solid #E3D5BB; vertical-align: top; overflow-wrap: break-word; word-wrap: break-word; }
td.numero { text-align: right; }
td.sin-base { white-space: normal; }
.seccion-corta { page-break-inside: avoid; }
.notas { font-size: 7pt; line-height: 1.3; padding-left: 16px; margin: 0; }
.notas li { margin-bottom: 3px; }
footer { position: fixed; bottom: -35px; left: 0; right: 0; border-top: 1px solid #D1AB66; padding-top: 5px; font-size: 6.5pt; color: #465f50; }
.serie { table-layout: fixed; }
.serie .tramo { width: 36%; }
.serie .dato { width: 24%; text-align: right; }
.serie .escala { width: 40%; }
.barra { height: 7px; background: #214F36; margin-top: 3px; }
.barra-negativa { background: #914B37; }
.lectura-serie { font-size: 7pt; color: #465f50; margin-bottom: 6px; }
</style></head><body>
<header><div class="marca">Casa Pestalozzi</div><h1><?= $esc($documento['titulo']) ?></h1>
<div class="meta"><?= $esc($documento['contexto']) ?> · Periodo: <?= $esc($documento['periodo']['inicio']) ?> a <?= $esc($documento['periodo']['fin']) ?></div>
<?php if (!empty($documento['periodo']['comparacion']['inicio']) && !empty($documento['periodo']['comparacion']['fin'])): ?><div class="meta">Comparación: <?= $esc($documento['periodo']['comparacion']['inicio']) ?> a <?= $esc($documento['periodo']['comparacion']['fin']) ?></div><?php endif; ?>
<div class="meta meta-corte">Corte de datos: <?= $esc($documento['corteDatos']) ?></div></header>
<footer>Documento informativo · Generado: <?= $esc($documento['generadoEn']) ?></footer>
<h2>Resumen ejecutivo</h2><p class="resumen"><?= $esc($documento['resumen']) ?></p>
<?php if ($documento['kpis']): ?><table class="kpis"><tbody>
<?php foreach (array_chunk($documento['kpis'], 3) as $grupo): ?><tr>
<?php foreach ($grupo as $kpi): ?><td><span class="etiqueta"><?= $esc($kpi['etiqueta']) ?></span><span class="valor"><?= $esc($formato($kpi['valor'], $kpi['formato'])) ?></span>
<?php if (array_key_exists('comparacion', $kpi)): ?><span class="comparacion"><?= $kpi['comparacion'] === null ? 'Sin base comparable' : $esc($formato($kpi['comparacion'], 'porcentaje')) ?> · periodo anterior</span><?php endif; ?></td><?php endforeach; ?>
</tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php foreach ($documento['secciones'] as $seccion): ?><?php $filas = $seccion['filas'] ?? []; ?><div<?= $seccion['tipo'] === 'texto' || count($filas) <= 5 ? ' class="seccion-corta"' : '' ?>><h2><?= $esc($seccion['titulo']) ?></h2>
<?php if ($seccion['tipo'] === 'texto'): ?><p><?= $esc($seccion['contenido']) ?></p>
<?php elseif ($seccion['tipo'] === 'serie' && count($seccion['columnas']) === 2 && isset($seccion['formatos']) && array_values($seccion['formatos'])[1] !== 'texto'): ?><?php require __DIR__ . '/_serie.php'; ?>
<?php else: ?><table><thead><tr><?php foreach ($seccion['columnas'] as $columna): ?><th><?= $esc($columna) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($filas as $fila): $valores = array_values($fila); $formatos = array_values($seccion['formatos'] ?? array_fill(0, count($valores), 'texto')); ?><tr><?php foreach ($valores as $indice => $celda): $tipoCelda = $formatos[$indice]; $claseCelda = $tipoCelda === 'texto' ? '' : ($tipoCelda === 'porcentaje' && $celda === null ? ' class="numero sin-base"' : ' class="numero"'); ?><td<?= $claseCelda ?>><?= $tipoCelda === 'texto' ? ($celda === null ? 'No disponible' : $esc($celda)) : ($tipoCelda === 'porcentaje' && $celda === null ? 'Sin base comparable' : $esc($formato($celda, $tipoCelda))) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table><?php if (!$filas): ?><p><?= $esc($seccion['vacio'] ?? 'Sin actividad en el periodo.') ?></p><?php endif; ?><?php endif; ?></div><?php endforeach; ?>
<?php if ($documento['notas']): ?><div<?= count($documento['notas']) <= 10 && array_sum(array_map('strlen', $documento['notas'])) <= 1200 ? ' class="seccion-corta"' : '' ?>><h2>Notas y alcance</h2><ul class="notas"><?php foreach ($documento['notas'] as $nota): ?><li><?= $esc($nota) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
</body></html>
