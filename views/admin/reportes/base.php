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
@page { margin: 38px 43px 58px; }
body { font-family: Montserrat, sans-serif; font-size: 9pt; line-height: 1.5; color: #253c30; }
header { border-bottom: 2px solid #b59b67; padding-bottom: 14px; margin-bottom: 20px; }
.marca { font-size: 9pt; letter-spacing: 1px; font-weight: bold; text-transform: uppercase; }
h1 { font-family: Playfair, serif; font-size: 23pt; line-height: 1.2; margin: 9px 0 5px; font-weight: normal; }
.meta { color: #465f50; font-size: 8pt; }
h2 { font-family: Playfair, serif; font-size: 15pt; margin: 22px 0 8px; font-weight: normal; page-break-after: avoid; }
p { margin: 0 0 8px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
thead { display: table-header-group; page-break-after: avoid; } tr { page-break-inside: avoid; }
.seccion-corta { page-break-inside: avoid; }
th { text-align: left; background: #e9eee9; font-size: 8pt; }
th, td { padding: 6px 7px; border-bottom: 1px solid #d8dfd8; vertical-align: top; overflow-wrap: break-word; }
.kpis td { width: 33%; border: 0; padding: 8px 10px 10px 0; }
.valor { font-size: 14pt; font-weight: bold; display: block; }
.etiqueta, .comparacion { font-size: 8pt; color: #465f50; }
.notas { font-size: 8pt; } .notas li { margin-bottom: 4px; }
footer { position: fixed; bottom: -38px; left: 0; right: 0; border-top: 1px solid #b59b67; padding-top: 6px; font-size: 7pt; color: #465f50; }
</style></head><body>
<header><div class="marca">Casa Pestalozzi</div><h1><?= $esc($documento['titulo']) ?></h1>
<div class="meta"><?= $esc($documento['contexto']) ?> · <?= $esc($documento['periodo']['inicio']) ?> a <?= $esc($documento['periodo']['fin']) ?></div>
<div class="meta">Corte de datos: <?= $esc($documento['corteDatos']) ?></div></header>
<footer>Documento informativo · Generado: <?= $esc($documento['generadoEn']) ?></footer>
<h2>Resumen ejecutivo</h2><p><?= $esc($documento['resumen']) ?></p>
<?php if ($documento['kpis']): ?><table class="kpis"><tbody>
<?php foreach (array_chunk($documento['kpis'], 3) as $grupo): ?><tr>
<?php foreach ($grupo as $kpi): ?><td><span class="etiqueta"><?= $esc($kpi['etiqueta']) ?></span><span class="valor"><?= $esc($formato($kpi['valor'], $kpi['formato'])) ?></span>
<?php if (array_key_exists('comparacion', $kpi)): ?><span class="comparacion"><?= $kpi['comparacion'] === null ? 'Sin base comparable' : $esc($formato($kpi['comparacion'], 'porcentaje')) ?> · periodo anterior</span><?php endif; ?></td><?php endforeach; ?>
</tr><?php endforeach; ?></tbody></table><?php endif; ?>
<?php foreach ($documento['secciones'] as $seccion): ?><div<?= $seccion['tipo'] === 'texto' || count($seccion['filas']) <= 10 ? ' class="seccion-corta"' : '' ?>><h2><?= $esc($seccion['titulo']) ?></h2>
<?php if ($seccion['tipo'] === 'texto'): ?><p><?= $esc($seccion['contenido']) ?></p>
<?php else: ?><table><thead><tr><?php foreach ($seccion['columnas'] as $columna): ?><th><?= $esc($columna) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($seccion['filas'] as $fila): ?><tr><?php foreach (array_values($fila) as $indice => $celda): $tipoCelda = array_values($seccion['formatos'] ?? array_fill(0, count($fila), 'texto'))[$indice]; ?><td><?= $tipoCelda === 'texto' ? ($celda === null ? 'No disponible' : $esc($celda)) : $esc($formato($celda, $tipoCelda)) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
</tbody></table><?php if (!$seccion['filas']): ?><p><?= $esc($seccion['vacio'] ?? 'Sin actividad en el periodo.') ?></p><?php endif; ?><?php endif; ?></div><?php endforeach; ?>
<?php if ($documento['notas']): ?><div<?= count($documento['notas']) <= 10 && array_sum(array_map('strlen', $documento['notas'])) <= 1200 ? ' class="seccion-corta"' : '' ?>><h2>Notas y alcance</h2><ul class="notas"><?php foreach ($documento['notas'] as $nota): ?><li><?= $esc($nota) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
</body></html>
