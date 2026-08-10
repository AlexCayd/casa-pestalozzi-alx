<?php
/**
 * Tarjeta de métrica del panel.
 *
 * Unifica lo que Finanzas e Inventario duplicaban: la cifra, su contexto y el
 * comparativo contra el periodo anterior. El badge de delta salió de dentro de
 * la cifra —donde heredaba la caja serif de 30px y la descuadraba— a un pie
 * propio, junto al valor anterior: un "+2991%" sin la cifra de la que parte no
 * dice nada.
 *
 * Parámetros:
 * - $statLabel    (string)  rótulo en versalitas. Obligatorio.
 * - $statValue    (string)  cifra ya formateada. Obligatorio.
 * - $statSub      (string)  contexto bajo la cifra. Opcional.
 * - $statDelta    (?float)  variación en % contra el periodo anterior, o null.
 * - $statPrev     (string)  cifra del periodo anterior, ya formateada. Opcional.
 * - $statTone     (string)  '', 'alert', 'good', 'bad' o 'gold'.
 * - $statInverso  (bool)    true cuando subir es malo (merma, costos): invierte
 *                           el color del badge sin invertir la flecha, que
 *                           sigue describiendo la dirección real.
 * - $statLead     (bool)    tarjeta destacada, a doble ancho.
 *
 * Cierra con unset() de sus parámetros: una página lo incluye varias veces y el
 * segundo include heredaría lo que dejó el primero.
 */
$statLabel = (string) ($statLabel ?? '');
$statValue = (string) ($statValue ?? '—');
$statSub = (string) ($statSub ?? '');
$statDelta = isset($statDelta) && $statDelta !== null ? (float) $statDelta : null;
$statPrev = (string) ($statPrev ?? '');
$statTone = (string) ($statTone ?? '');
$statInverso = (bool) ($statInverso ?? false);
$statLead = (bool) ($statLead ?? false);

$statEscape = static fn ($valor): string => htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');

$statClases = ['admin-stat-card'];
if ($statTone !== '') {
    $statClases[] = 'admin-stat-card--' . $statTone;
}
if ($statLead) {
    $statClases[] = 'admin-stat-card--lead';
}

// Sube o baja lo dice la flecha; bueno o malo, el color. En una métrica
// invertida las dos lecturas dejan de coincidir y por eso se separan.
$statSube = $statDelta !== null && $statDelta >= 0;
$statBueno = $statInverso ? !$statSube : $statSube;
?>
<article class="<?php echo implode(' ', $statClases); ?>">
    <span class="admin-stat-card__label"><?php echo $statEscape($statLabel); ?></span>
    <span class="admin-stat-card__value"><?php echo $statEscape($statValue); ?></span>
    <?php if ($statSub !== '') : ?>
        <span class="admin-stat-card__sub"><?php echo $statSub; ?></span>
    <?php endif; ?>

    <?php if ($statDelta !== null || $statPrev !== '') : ?>
        <div class="admin-stat-card__foot">
            <?php if ($statDelta !== null) : ?>
                <span class="admin-delta <?php echo $statBueno ? 'is-up' : 'is-down'; ?>">
                    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                        <path d="<?php echo $statSube ? 'M7 14 12 9l5 5' : 'M7 10l5 5 5-5'; ?>"/>
                    </svg>
                    <?php echo ($statSube ? '+' : '') . number_format($statDelta, 1); ?>%
                </span>
            <?php endif; ?>
            <?php if ($statPrev !== '') : ?>
                <span class="admin-stat-card__prev"><?php echo $statEscape($statPrev); ?> en el periodo anterior</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</article>
<?php
unset(
    $statLabel, $statValue, $statSub, $statDelta, $statPrev, $statTone,
    $statInverso, $statLead, $statEscape, $statClases, $statSube, $statBueno
);
?>
