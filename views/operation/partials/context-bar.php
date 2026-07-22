<?php
/**
 * Barra contextual compartida. Los slots conservan los controles especificos
 * y sus contratos JavaScript sin duplicar la estructura operativa.
 */
$operationalContextView = (string)($operationalContextView ?? 'map');
$operationalContextControlsHtml = (string)($operationalContextControlsHtml ?? '');
$operationalContextActionsHtml = (string)($operationalContextActionsHtml ?? '');
?>
<div class="operational-toolbar operational-context-bar operational-context-bar--<?php echo $operationalContextView === 'reservations' ? 'reservations' : 'map'; ?>" data-operational-context-bar>
    <?php include __DIR__ . '/drawer-toggle.php'; ?>
    <div class="operational-context-bar__controls">
        <?php echo $operationalContextControlsHtml; ?>
    </div>
    <?php if ($operationalContextActionsHtml !== ''): ?>
        <div class="operational-context-bar__actions">
            <?php echo $operationalContextActionsHtml; ?>
        </div>
    <?php endif; ?>
</div>
<?php unset($operationalContextView, $operationalContextControlsHtml, $operationalContextActionsHtml); ?>
