<?php
/** Barras estáticas con origen cero; solo escala visual, sin cálculos de negocio. */
$maximo = 0.0;
foreach ($filas as $filaSerie) {
    $filaSerie = array_values($filaSerie);
    if ($filaSerie[1] !== null) $maximo = max($maximo, abs((float) $filaSerie[1]));
}
$tipoSerie = array_values($seccion['formatos'])[1];
$columnasSerie = array_values($seccion['columnas']);
?>
<p class="lectura-serie">Totales por tramo, no promedios diarios. Los extremos pueden cubrir menos días. Barras desde cero; escala común de magnitud. Los valores negativos se indican con signo.</p>
<table class="serie"><thead><tr><th class="tramo"><?= $esc($columnasSerie[0]) ?></th><th class="dato"><?= $esc($columnasSerie[1]) ?></th><th class="escala">Magnitud relativa</th></tr></thead><tbody>
<?php foreach ($filas as $filaSerie):
    $filaSerie = array_values($filaSerie);
    $valorSerie = $filaSerie[1];
    $ancho = $maximo > 0 && $valorSerie !== null ? min(100.0, max(0.0, abs((float) $valorSerie) / $maximo * 100)) : 0;
?>
<tr><td><?= $esc($filaSerie[0]) ?></td><td class="numero"><?= $esc($formato($valorSerie, $tipoSerie)) ?></td><td><?php if ($ancho > 0): ?><div class="barra<?= $valorSerie < 0 ? ' barra-negativa' : '' ?>" style="width: <?= number_format($ancho, 3, '.', '') ?>%"></div><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table>
<?php if (!$filas): ?><p><?= $esc($seccion['vacio'] ?? 'Sin actividad en el periodo.') ?></p><?php elseif ($maximo === 0.0): ?><p class="lectura-serie">Sin magnitudes distintas de cero disponibles para representar.</p><?php endif; ?>
