<?php
/**
 * Shared reservation time picker markup.
 *
 * Expected variables:
 * - rootId, inputId, displayId, dropdownId, name, value, endpoint, disabled
 * - placeholder, staticStep, required, displayAriaDescribedby, displayAriaInvalid
 * - inputDataAttributes
 * - inline: rejilla de horarios siempre visible en vez de desplegable. El
 *   control de solo lectura se sigue emitiendo oculto porque el JS lo exige.
 */

$rootId = (string)($rootId ?? 'hourPicker');
$inputId = (string)($inputId ?? 'horaHidden');
$displayId = (string)($displayId ?? 'hourDisplay');
$dropdownId = (string)($dropdownId ?? 'hourDropdown');
$name = (string)($name ?? 'hora');
$value = (string)($value ?? '');
$endpoint = (string)($endpoint ?? '/api/reservaciones/horarios');
$disabled = (bool)($disabled ?? false);
$placeholder = (string)($placeholder ?? 'Elige una hora');
$staticStep = max(0, (int)($staticStep ?? 0));
$required = (bool)($required ?? false);
$displayAriaDescribedby = trim((string)($displayAriaDescribedby ?? ''));
$displayAriaInvalid = (bool)($displayAriaInvalid ?? false);
$inputDataAttributes = is_array($inputDataAttributes ?? null) ? $inputDataAttributes : [];
$inline = (bool)($inline ?? false);

// Ver date-picker.php: el escaper lleva nombre propio para no pisar el `$h` de
// la vista que hace el include.
$cpPickerEscape = static function ($item): string {
    return htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');
};
?>
<div
    class="hour-picker-wrap<?php echo $inline ? ' hour-picker-wrap--inline' : ''; ?>"
    id="<?php echo $cpPickerEscape($rootId); ?>"
    data-reservation-time-picker
    <?php echo $inline ? 'data-inline="1"' : ''; ?>
    data-schedules-endpoint="<?php echo $cpPickerEscape($endpoint); ?>"
    data-static-step="<?php echo $staticStep; ?>"
>
    <div class="hour-picker-control" data-time-control <?php echo $inline ? 'hidden' : ''; ?>>
        <span class="hour-picker-control__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <circle cx="12" cy="12" r="8.5"/>
                <path d="M12 7.5V12l3 2"/>
            </svg>
        </span>
        <input
            type="text"
            class="hour-display"
            id="<?php echo $cpPickerEscape($displayId); ?>"
            placeholder="<?php echo $cpPickerEscape($placeholder); ?>"
            readonly
            aria-controls="<?php echo $cpPickerEscape($dropdownId); ?>"
            aria-expanded="false"
            aria-haspopup="listbox"
            aria-invalid="<?php echo $displayAriaInvalid ? 'true' : 'false'; ?>"
            <?php echo $displayAriaDescribedby !== '' ? 'aria-describedby="' . $cpPickerEscape($displayAriaDescribedby) . '"' : ''; ?>
            data-time-display
            <?php echo $disabled ? 'disabled' : ''; ?>
        >
        <span class="hour-picker-control__chevron" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <path d="m7 10 5 5 5-5"/>
            </svg>
        </span>
    </div>
    <input
        type="hidden"
        <?php echo $name !== '' ? 'name="' . $cpPickerEscape($name) . '"' : ''; ?>
        id="<?php echo $cpPickerEscape($inputId); ?>"
        value="<?php echo $cpPickerEscape($value); ?>"
        data-time-input
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
        class="hour-dropdown<?php echo $inline ? ' hour-dropdown--inline open' : ''; ?>"
        id="<?php echo $cpPickerEscape($dropdownId); ?>"
        role="listbox"
        aria-label="Horarios disponibles"
        <?php echo $inline ? '' : 'aria-hidden="true"'; ?>
        <?php echo $inline && $displayAriaDescribedby !== '' ? 'aria-describedby="' . $cpPickerEscape($displayAriaDescribedby) . '"' : ''; ?>
        data-time-dropdown
    >
        <div class="hour-dropdown__head" aria-hidden="true">
            <strong>Horarios disponibles</strong>
            <span>Selecciona una hora</span>
        </div>
        <div class="hour-dropdown__options" data-time-options></div>
    </div>
</div>
<?php
// Igual que en date-picker.php: sin esto el segundo include de la página
// hereda los parámetros del primero.
unset(
    $rootId, $inputId, $displayId, $dropdownId, $name, $value, $endpoint, $disabled,
    $placeholder, $staticStep, $required, $displayAriaDescribedby, $displayAriaInvalid,
    $inputDataAttributes, $inline, $cpPickerEscape
);
?>
