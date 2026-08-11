<?php
/**
 * Shared reservation date picker markup.
 *
 * Expected variables:
 * - rootId, inputId, displayId, calendarId, name, value, min, today, disabled, enabledWeekdays
 * - allowPast, required, inputDataAttributes, rootClass, showIcon
 * - prevId, nextId, labelId, gridId
 * - displayAriaDescribedby, displayAriaInvalid
 * - inline: calendario siempre visible en vez de popover. El input de display
 *   sigue emitiendose oculto porque el JS compartido lo exige para arrancar.
 */

$rootId = (string)($rootId ?? 'datePicker');
$inputId = (string)($inputId ?? 'fechaHidden');
$displayId = (string)($displayId ?? 'dateDisplay');
$calendarId = (string)($calendarId ?? 'cpCalendar');
$name = (string)($name ?? 'fecha');
$value = (string)($value ?? '');
$min = (string)($min ?? '');
$maxDate = (string)($maxDate ?? '');
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
$inline = (bool)($inline ?? false);

if (!is_array($enabledWeekdays)) {
    $enabledWeekdays = [];
}

// El icono es el afijo del campo de texto; sin campo visible no tiene dónde ir.
if ($inline) {
    $showIcon = false;
}

// Nombre propio y no `$h`: `include` comparte el scope del llamador, y varias
// vistas (configuración de horarios, anuncio, POS) tienen su propio escaper
// llamado `$h`. Pisárselo aquí lo dejaba nulo tras el unset del final.
$cpPickerEscape = static function ($item): string {
    return htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');
};
?>
<div
    class="date-picker-wrap<?php echo $showIcon ? ' date-picker-wrap--with-icon' : ''; ?><?php echo $inline ? ' date-picker-wrap--inline' : ''; ?><?php echo $rootClass !== '' ? ' ' . $cpPickerEscape($rootClass) : ''; ?>"
    id="<?php echo $cpPickerEscape($rootId); ?>"
    data-reservation-date-picker
    <?php echo $inline ? 'data-inline="1"' : ''; ?>
    data-min-date="<?php echo $cpPickerEscape($min); ?>"
    data-max-date="<?php echo $cpPickerEscape($maxDate); ?>"
    data-today-date="<?php echo $cpPickerEscape($today); ?>"
    data-enabled-weekdays="<?php echo $cpPickerEscape(implode(',', array_map('intval', $enabledWeekdays))); ?>"
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
        id="<?php echo $cpPickerEscape($displayId); ?>"
        placeholder="dd / mm / aaaa"
        readonly
        aria-haspopup="dialog"
        aria-controls="<?php echo $cpPickerEscape($calendarId); ?>"
        aria-expanded="false"
        aria-invalid="<?php echo $displayAriaInvalid ? 'true' : 'false'; ?>"
        <?php echo !$inline && $displayAriaDescribedby !== '' ? 'aria-describedby="' . $cpPickerEscape($displayAriaDescribedby) . '"' : ''; ?>
        data-date-display
        <?php echo $inline ? 'hidden tabindex="-1" aria-hidden="true"' : ''; ?>
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <input
        type="hidden"
        <?php echo $name !== '' ? 'name="' . $cpPickerEscape($name) . '"' : ''; ?>
        id="<?php echo $cpPickerEscape($inputId); ?>"
        value="<?php echo $cpPickerEscape($value); ?>"
        data-date-input
        data-reservation-control
        <?php foreach ($inputDataAttributes as $attribute => $attributeValue) : ?>
            <?php
            $attribute = strtolower(trim((string)$attribute));
            if (!preg_match('/^data-[a-z0-9_-]+$/', $attribute)) {
                continue;
            }
            ?>
            <?php echo $cpPickerEscape($attribute); ?><?php echo $attributeValue === true || $attributeValue === '' ? '' : '="' . $cpPickerEscape($attributeValue) . '"'; ?>
        <?php endforeach; ?>
        <?php echo $required ? 'required' : ''; ?>
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <div
        class="cp-calendar<?php echo $inline ? ' cp-calendar--inline open' : ''; ?>"
        id="<?php echo $cpPickerEscape($calendarId); ?>"
        <?php echo $inline ? 'role="group"' : 'role="dialog" aria-hidden="true"'; ?>
        aria-label="Seleccionar fecha"
        <?php echo $inline && $displayAriaDescribedby !== '' ? 'aria-describedby="' . $cpPickerEscape($displayAriaDescribedby) . '"' : ''; ?>
        <?php echo $inline ? 'aria-invalid="' . ($displayAriaInvalid ? 'true' : 'false') . '"' : ''; ?>
        data-date-calendar
    >
        <div class="cpc-head">
            <button class="cpc-nav cpc-prev"<?php echo $prevId !== '' ? ' id="' . $cpPickerEscape($prevId) . '"' : ''; ?> type="button" aria-label="Mes anterior" data-date-prev>&lt;</button>
            <span class="cpc-label"<?php echo $labelId !== '' ? ' id="' . $cpPickerEscape($labelId) . '"' : ''; ?> data-date-label></span>
            <button class="cpc-nav cpc-next"<?php echo $nextId !== '' ? ' id="' . $cpPickerEscape($nextId) . '"' : ''; ?> type="button" aria-label="Mes siguiente" data-date-next>&gt;</button>
        </div>
        <div class="cpc-weekdays">
            <span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sa</span>
        </div>
        <div class="cpc-grid"<?php echo $gridId !== '' ? ' id="' . $cpPickerEscape($gridId) . '"' : ''; ?> data-date-grid></div>
    </div>
</div>
<?php
// El parcial se incluye varias veces por página con juegos de parámetros
// distintos; sin esto el segundo include hereda lo que dejó el primero.
unset(
    $rootId, $inputId, $displayId, $calendarId, $name, $value, $min, $maxDate, $today,
    $disabled, $enabledWeekdays, $displayAriaDescribedby, $displayAriaInvalid, $allowPast,
    $required, $inputDataAttributes, $rootClass, $showIcon, $prevId, $nextId, $labelId,
    $gridId, $inline, $cpPickerEscape
);
?>
