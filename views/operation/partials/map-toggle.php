<?php
$operationalMapToggleLabel = (string)($operationalMapToggleLabel ?? 'mapa');
$operationalMapToggleLabelAttribute = htmlspecialchars($operationalMapToggleLabel, ENT_QUOTES, 'UTF-8');
$operationalMapToggleIconOnly = (bool)($operationalMapToggleIconOnly ?? false);
$operationalMapToggleInitialLabel = $operationalMapToggleIconOnly ? 'Maximizar mapa' : 'Maximizar ' . $operationalMapToggleLabel;
?>
<button
    type="button"
    class="operational-map-toggle<?php echo $operationalMapToggleIconOnly ? ' operational-map-toggle--icon' : ''; ?>"
    data-operational-map-toggle
    data-operational-map-label="<?php echo $operationalMapToggleLabelAttribute; ?>"
    aria-pressed="false"
    aria-label="<?php echo htmlspecialchars($operationalMapToggleInitialLabel, ENT_QUOTES, 'UTF-8'); ?>"
    title="<?php echo htmlspecialchars($operationalMapToggleInitialLabel, ENT_QUOTES, 'UTF-8'); ?>">
    <svg class="operational-map-toggle__expand" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M8 3H3v5"/><path d="M21 8V3h-5"/><path d="M16 21h5v-5"/><path d="M3 16v5h5"/>
    </svg>
    <svg class="operational-map-toggle__collapse" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M8 3v5H3"/><path d="M21 8h-5V3"/><path d="M16 21v-5h5"/><path d="M3 16h5v5"/>
    </svg>
    <?php if (!$operationalMapToggleIconOnly): ?>
        <span class="operational-map-toggle__label">Maximizar <?php echo $operationalMapToggleLabelAttribute; ?></span>
    <?php endif; ?>
</button>
