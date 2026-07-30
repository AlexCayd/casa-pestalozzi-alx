<?php
/**
 * Barra contextual compartida. Los slots conservan los controles especificos
 * y sus contratos JavaScript sin duplicar la estructura operativa.
 */
$operationalContextView = (string)($operationalContextView ?? 'map');
$operationalContextControlsHtml = (string)($operationalContextControlsHtml ?? '');
$operationalContextActionsHtml = (string)($operationalContextActionsHtml ?? '');
$operationalContextSelectionHtml = (string)($operationalContextSelectionHtml ?? '');
$operationalContextIncludeDrawerToggle = (bool)($operationalContextIncludeDrawerToggle ?? true);
?>
<div class="operational-toolbar operational-context-bar operational-context-bar--<?php echo $operationalContextView === 'reservations' ? 'reservations' : 'map'; ?>" data-operational-context-bar>
    <?php if ($operationalContextIncludeDrawerToggle): ?>
        <?php include __DIR__ . '/drawer-toggle.php'; ?>
    <?php endif; ?>
    <div class="operational-context-bar__controls">
        <?php echo $operationalContextControlsHtml; ?>
    </div>
    <?php if ($operationalContextSelectionHtml !== ''): ?>
        <div class="operational-context-bar__selection">
            <?php echo $operationalContextSelectionHtml; ?>
        </div>
    <?php endif; ?>
    <?php if ($operationalContextActionsHtml !== ''): ?>
        <div class="operational-context-bar__actions">
            <?php echo $operationalContextActionsHtml; ?>
        </div>
    <?php endif; ?>
</div>
<?php unset($operationalContextView, $operationalContextControlsHtml, $operationalContextActionsHtml, $operationalContextSelectionHtml, $operationalContextIncludeDrawerToggle); ?>
