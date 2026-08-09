<?php
/**
 * Selector de periodo compartido por los tableros del admin.
 *
 * Espera $rango tal y como lo devuelve Services\RangoPeriodo::resolver().
 * Parámetros opcionales:
 * - $rangeCaption: rótulo a la izquierda del disparador.
 * - $rangeCompare: false para ocultar el interruptor de comparación en módulos
 *   que todavía no saben leer un periodo previo.
 *
 * Cierra con unset() de sus parámetros: puede incluirse más de una vez por
 * página y el segundo include heredaría lo que dejó el primero.
 */
$rango = is_array($rango ?? null) ? $rango : [];
$rangeCaption = (string) ($rangeCaption ?? 'Periodo');
$rangeCompare = (bool) ($rangeCompare ?? true);

$rangeEscape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$rangeStart = (string) ($rango['start'] ?? date('Y-m-d', strtotime('-29 days')));
$rangeEnd = (string) ($rango['end'] ?? date('Y-m-d'));
$rangePreset = (int) ($rango['preset'] ?? 30);
$rangeLabel = (string) ($rango['label'] ?? 'Últimos 30 días');
$rangeComparar = !empty($rango['comparar']);
$rangeEsCustom = $rangePreset === 0;

$rangeMesesCortos = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$rangeBonita = static function (string $iso) use ($rangeMesesCortos): string {
    $ts = strtotime($iso);
    return $ts ? ((int) date('j', $ts) . ' ' . $rangeMesesCortos[(int) date('n', $ts)]) : $iso;
};
$rangeResumen = $rangeBonita($rangeStart) . ' – ' . $rangeBonita($rangeEnd)
    . ' ' . date('Y', strtotime($rangeEnd));

$rangePresets = [];
foreach (\Services\RangoPeriodo::PRESETS as $dias) {
    $rangePresets[$dias] = $dias === 365 ? 'Último año' : ('Últimos ' . $dias . ' días');
}
?>
<div class="admin-range" data-analytics-range-picker
     data-start="<?php echo $rangeEscape($rangeStart); ?>"
     data-end="<?php echo $rangeEscape($rangeEnd); ?>"
     data-today="<?php echo $rangeEscape(date('Y-m-d')); ?>"
     data-compare="<?php echo $rangeComparar ? '1' : '0'; ?>"
     data-preset="<?php echo $rangePreset; ?>">

    <span class="admin-range__caption"><?php echo $rangeEscape($rangeCaption); ?></span>

    <button type="button" class="admin-range__trigger" data-range-trigger
            aria-expanded="false" aria-haspopup="dialog">
        <svg class="admin-range__icon" viewBox="0 0 24 24" aria-hidden="true">
            <rect x="3" y="4.5" width="18" height="17" rx="2.5"/><path d="M16 2.5v4M8 2.5v4M3 10h18"/>
        </svg>
        <span class="admin-range__trigger-text">
            <span class="admin-range__trigger-label" data-range-label><?php echo $rangeEscape($rangeLabel); ?></span>
            <span class="admin-range__trigger-dates" data-range-dates><?php echo $rangeEscape($rangeResumen); ?></span>
        </span>
        <svg class="admin-range__chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    <div class="admin-range__pop" data-range-pop hidden role="dialog" aria-label="Elegir periodo">
        <div class="admin-range__presets" role="group" aria-label="Periodos rápidos">
            <?php foreach ($rangePresets as $dias => $etiqueta) : ?>
                <button type="button" class="admin-range__preset <?php echo $rangePreset === $dias ? 'is-active' : ''; ?>"
                        data-range-preset="<?php echo (int) $dias; ?>"><?php echo $rangeEscape($etiqueta); ?></button>
            <?php endforeach; ?>
            <button type="button" class="admin-range__preset <?php echo $rangeEsCustom ? 'is-active' : ''; ?>"
                    data-range-preset="custom">Personalizado</button>
        </div>

        <div class="admin-range__cal">
            <div class="admin-range__nav">
                <button type="button" class="admin-range__nav-btn" data-range-prev aria-label="Mes anterior">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
                </button>
                <span class="admin-range__month-title" data-range-title-a></span>
                <span class="admin-range__month-title admin-range__month-title--b" data-range-title-b></span>
                <button type="button" class="admin-range__nav-btn" data-range-next aria-label="Mes siguiente">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                </button>
            </div>

            <div class="admin-range__months">
                <div class="admin-range__month">
                    <div class="admin-range__weekdays" aria-hidden="true"><span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sa</span></div>
                    <div class="admin-range__grid" data-range-grid-a></div>
                </div>
                <div class="admin-range__month admin-range__month--b">
                    <div class="admin-range__weekdays" aria-hidden="true"><span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sa</span></div>
                    <div class="admin-range__grid" data-range-grid-b></div>
                </div>
            </div>

            <?php if ($rangeCompare) : ?>
                <?php // Comparar contra el periodo inmediatamente anterior de la
                      // misma duración: es lo que separa "vendimos más" de
                      // "el periodo era más largo". ?>
                <label class="admin-range__compare admin-switch">
                    <input type="checkbox" data-range-compare <?php echo $rangeComparar ? 'checked' : ''; ?>>
                    <span class="admin-switch__track" aria-hidden="true">
                        <span class="admin-switch__thumb"></span>
                    </span>
                    <span class="admin-range__compare-text">
                        Comparar con el periodo anterior
                        <small><?php echo $rangeEscape((string) ($rango['prevLabel'] ?? '')); ?></small>
                    </span>
                </label>
            <?php endif; ?>

            <div class="admin-range__foot">
                <span class="admin-range__summary" data-range-summary aria-live="polite"></span>
                <div class="admin-range__foot-actions">
                    <button type="button" class="admin-btn admin-btn--ghost admin-btn--small" data-range-cancel>Cancelar</button>
                    <button type="button" class="admin-btn admin-btn--primary admin-btn--small" data-range-apply>Aplicar</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
unset(
    $rangeCaption, $rangeCompare, $rangeEscape, $rangeStart, $rangeEnd, $rangePreset,
    $rangeLabel, $rangeComparar, $rangeEsCustom, $rangeMesesCortos, $rangeBonita,
    $rangeResumen, $rangePresets
);
?>
