<?php
/**
 * Indicador compartido de ultima actualizacion para herramientas operativas.
 */
$operationalUpdateId = trim((string)($operationalUpdateId ?? ''));
$operationalUpdateTextId = trim((string)($operationalUpdateTextId ?? ''));
$operationalUpdateText = (string)($operationalUpdateText ?? 'Preparando operación');
$operationalUpdateTextAttributes = is_array($operationalUpdateTextAttributes ?? null)
    ? $operationalUpdateTextAttributes
    : [];
$operationalUpdateClass = trim((string)($operationalUpdateClass ?? ''));
$operationalUpdateH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<span
    class="operational-update<?php echo $operationalUpdateClass !== '' ? ' ' . $operationalUpdateH($operationalUpdateClass) : ''; ?>"
    <?php echo $operationalUpdateId !== '' ? 'id="' . $operationalUpdateH($operationalUpdateId) . '"' : ''; ?>
    role="status"
    aria-live="polite"
>
    <span class="operational-update__dot mapa-live-dot" aria-hidden="true"></span>
    <span
        class="operational-update__text"
        <?php echo $operationalUpdateTextId !== '' ? 'id="' . $operationalUpdateH($operationalUpdateTextId) . '"' : ''; ?>
        <?php foreach ($operationalUpdateTextAttributes as $attribute => $attributeValue) : ?>
            <?php
            $attribute = strtolower(trim((string)$attribute));
            if (!preg_match('/^data-[a-z0-9_-]+$/', $attribute)) {
                continue;
            }
            ?>
            <?php echo $operationalUpdateH($attribute); ?><?php echo $attributeValue === true || $attributeValue === '' ? '' : '="' . $operationalUpdateH($attributeValue) . '"'; ?>
        <?php endforeach; ?>
    ><?php echo $operationalUpdateH($operationalUpdateText); ?></span>
</span>
<?php unset($operationalUpdateId, $operationalUpdateTextId, $operationalUpdateText, $operationalUpdateTextAttributes, $operationalUpdateClass, $operationalUpdateH); ?>
