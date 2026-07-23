<?php
/**
 * Shared reservation date picker markup.
 *
 * Expected variables:
 * - rootId, inputId, displayId, calendarId, name, value, min, today, disabled, enabledWeekdays
 * - allowPast, required, inputDataAttributes, rootClass, showIcon
 * - prevId, nextId, labelId, gridId
 * - displayAriaDescribedby, displayAriaInvalid
 */

$rootId = (string)($rootId ?? 'datePicker');
$inputId = (string)($inputId ?? 'fechaHidden');
$displayId = (string)($displayId ?? 'dateDisplay');
$calendarId = (string)($calendarId ?? 'cpCalendar');
$name = (string)($name ?? 'fecha');
$value = (string)($value ?? '');
$min = (string)($min ?? '');
$today = (string)($today ?? $min);
$disabled = (bool)($disabled ?? false);
$enabledWeekdays = $enabledWeekdays ?? [];
$displayAriaDescribedby = trim((string)($displayAriaDescribedby ?? ''));
$displayAriaInvalid = (bool)($displayAriaInvalid ?? false);
$allowPast = (bool)($allowPast ?? false);
$required = (bool)($required ?? false);
$inputDataAttributes = is_array($inputDataAttributes ?? null) ? $inputDataAttributes : [];
$rootClass = trim((string)($rootClass ?? ''));
$showIcon = (bool)($showIcon ?? false);
$prevId = trim((string)($prevId ?? ''));
$nextId = trim((string)($nextId ?? ''));
$labelId = trim((string)($labelId ?? ''));
$gridId = trim((string)($gridId ?? ''));

if (!is_array($enabledWeekdays)) {
    $enabledWeekdays = [];
}

$h = static function ($item): string {
    return htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');
};
?>
<div
    class="date-picker-wrap<?php echo $showIcon ? ' date-picker-wrap--with-icon' : ''; ?><?php echo $rootClass !== '' ? ' ' . $h($rootClass) : ''; ?>"
    id="<?php echo $h($rootId); ?>"
    data-reservation-date-picker
    data-min-date="<?php echo $h($min); ?>"
    data-today-date="<?php echo $h($today); ?>"
    data-enabled-weekdays="<?php echo $h(implode(',', array_map('intval', $enabledWeekdays))); ?>"
    data-allow-past="<?php echo $allowPast ? '1' : '0'; ?>"
>
    <?php if ($showIcon): ?>
        <span class="date-picker__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
                <rect x="3" y="4" width="18" height="17" rx="2"></rect>
                <path d="M8 2v4M16 2v4M3 9h18"></path>
            </svg>
        </span>
    <?php endif; ?>
    <input
        type="text"
        class="date-display"
        id="<?php echo $h($displayId); ?>"
        placeholder="dd / mm / aaaa"
        readonly
        aria-haspopup="dialog"
        aria-controls="<?php echo $h($calendarId); ?>"
        aria-expanded="false"
        aria-invalid="<?php echo $displayAriaInvalid ? 'true' : 'false'; ?>"
        <?php echo $displayAriaDescribedby !== '' ? 'aria-describedby="' . $h($displayAriaDescribedby) . '"' : ''; ?>
        data-date-display
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <input
        type="hidden"
        <?php echo $name !== '' ? 'name="' . $h($name) . '"' : ''; ?>
        id="<?php echo $h($inputId); ?>"
        value="<?php echo $h($value); ?>"
        data-date-input
        data-reservation-control
        <?php foreach ($inputDataAttributes as $attribute => $attributeValue) : ?>
            <?php
            $attribute = strtolower(trim((string)$attribute));
            if (!preg_match('/^data-[a-z0-9_-]+$/', $attribute)) {
                continue;
            }
            ?>
            <?php echo $h($attribute); ?><?php echo $attributeValue === true || $attributeValue === '' ? '' : '="' . $h($attributeValue) . '"'; ?>
        <?php endforeach; ?>
        <?php echo $required ? 'required' : ''; ?>
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <div class="cp-calendar" id="<?php echo $h($calendarId); ?>" role="dialog" aria-label="Seleccionar fecha" aria-hidden="true" data-date-calendar>
        <div class="cpc-head">
            <button class="cpc-nav cpc-prev"<?php echo $prevId !== '' ? ' id="' . $h($prevId) . '"' : ''; ?> type="button" aria-label="Mes anterior" data-date-prev>&lt;</button>
            <span class="cpc-label"<?php echo $labelId !== '' ? ' id="' . $h($labelId) . '"' : ''; ?> data-date-label></span>
            <button class="cpc-nav cpc-next"<?php echo $nextId !== '' ? ' id="' . $h($nextId) . '"' : ''; ?> type="button" aria-label="Mes siguiente" data-date-next>&gt;</button>
        </div>
        <div class="cpc-weekdays">
            <span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sa</span>
        </div>
        <div class="cpc-grid"<?php echo $gridId !== '' ? ' id="' . $h($gridId) . '"' : ''; ?> data-date-grid></div>
    </div>
</div>
