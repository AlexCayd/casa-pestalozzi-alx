<?php
/** Drawer compartido de reservaciones del dia. */
$operationalDrawerId = (string)($operationalDrawerId ?? 'operational-reservations-drawer');
$operationalDrawerTitleId = (string)($operationalDrawerTitleId ?? 'operational-reservations-title');
$operationalDrawerClass = trim((string)($operationalDrawerClass ?? ''));
$operationalDrawerAttributes = is_array($operationalDrawerAttributes ?? null) ? $operationalDrawerAttributes : [];
$operationalDrawerDateHtml = (string)($operationalDrawerDateHtml ?? '');
$operationalDrawerCountHtml = (string)($operationalDrawerCountHtml ?? '0');
$operationalDrawerSlotHtml = (string)($operationalDrawerSlotHtml ?? 'Reservaciones activas');
$operationalDrawerListHtml = (string)($operationalDrawerListHtml ?? '');
$operationalDrawerListId = trim((string)($operationalDrawerListId ?? ''));
$operationalDrawerListClass = trim((string)($operationalDrawerListClass ?? ''));
$operationalDrawerListAttributes = is_array($operationalDrawerListAttributes ?? null) ? $operationalDrawerListAttributes : [];
$operationalDrawerH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<aside
    class="operational-drawer<?php echo $operationalDrawerClass !== '' ? ' ' . $operationalDrawerH($operationalDrawerClass) : ''; ?>"
    id="<?php echo $operationalDrawerH($operationalDrawerId); ?>"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="<?php echo $operationalDrawerH($operationalDrawerTitleId); ?>"
    aria-live="polite"
    aria-busy="false"
    tabindex="-1"
    data-operational-drawer
    <?php foreach ($operationalDrawerAttributes as $attribute => $attributeValue) : ?>
        <?php
        $attribute = strtolower(trim((string)$attribute));
        if (!preg_match('/^data-[a-z0-9_-]+$/', $attribute)) {
            continue;
        }
        ?>
        <?php echo $operationalDrawerH($attribute); ?><?php echo $attributeValue === true || $attributeValue === '' ? '' : '="' . $operationalDrawerH($attributeValue) . '"'; ?>
    <?php endforeach; ?>
>
    <div class="operational-drawer__head">
        <h2 class="operational-drawer__title" id="<?php echo $operationalDrawerH($operationalDrawerTitleId); ?>">Reservaciones del día</h2>
        <div class="operational-drawer__meta">
            <?php echo $operationalDrawerDateHtml; ?>
            <?php echo $operationalDrawerCountHtml; ?>
        </div>
        <button type="button" class="operational-icon-button operational-drawer__close" aria-label="Cerrar reservaciones" data-operational-drawer-close>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>
        </button>
    </div>
    <div class="operational-drawer__content">
        <div class="reservation-operation__slot"><?php echo $operationalDrawerSlotHtml; ?></div>
        <div
            class="reservation-operation__reservation-list operational-reservation-list<?php echo $operationalDrawerListClass !== '' ? ' ' . $operationalDrawerH($operationalDrawerListClass) : ''; ?>"
            <?php echo $operationalDrawerListId !== '' ? 'id="' . $operationalDrawerH($operationalDrawerListId) . '"' : ''; ?>
            <?php foreach ($operationalDrawerListAttributes as $attribute => $attributeValue) : ?>
                <?php
                $attribute = strtolower(trim((string)$attribute));
                if (!preg_match('/^data-[a-z0-9_-]+$/', $attribute)) {
                    continue;
                }
                ?>
                <?php echo $operationalDrawerH($attribute); ?><?php echo $attributeValue === true || $attributeValue === '' ? '' : '="' . $operationalDrawerH($attributeValue) . '"'; ?>
            <?php endforeach; ?>
        >
            <?php echo $operationalDrawerListHtml; ?>
        </div>
    </div>
</aside>
<button type="button" class="operational-drawer-backdrop" aria-label="Cerrar reservaciones" data-operational-drawer-backdrop hidden></button>
<?php unset($operationalDrawerId, $operationalDrawerTitleId, $operationalDrawerClass, $operationalDrawerAttributes, $operationalDrawerDateHtml, $operationalDrawerCountHtml, $operationalDrawerSlotHtml, $operationalDrawerListHtml, $operationalDrawerListId, $operationalDrawerListClass, $operationalDrawerListAttributes, $operationalDrawerH); ?>
