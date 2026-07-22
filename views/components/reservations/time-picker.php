<?php
/**
 * Shared reservation time picker markup.
 *
 * Expected variables:
 * - rootId, inputId, displayId, dropdownId, name, value, endpoint, disabled
 * - placeholder, staticStep, required, displayAriaDescribedby, displayAriaInvalid
 * - inputDataAttributes
 */

$rootId = (string)($rootId ?? 'hourPicker');
$inputId = (string)($inputId ?? 'horaHidden');
$displayId = (string)($displayId ?? 'hourDisplay');
$dropdownId = (string)($dropdownId ?? 'hourDropdown');
$name = (string)($name ?? 'hora');
$value = (string)($value ?? '');
$endpoint = (string)($endpoint ?? '/api/reservation-schedules');
$disabled = (bool)($disabled ?? false);
$placeholder = (string)($placeholder ?? 'Elige una hora');
$staticStep = max(0, (int)($staticStep ?? 0));
$required = (bool)($required ?? false);
$displayAriaDescribedby = trim((string)($displayAriaDescribedby ?? ''));
$displayAriaInvalid = (bool)($displayAriaInvalid ?? false);
$inputDataAttributes = is_array($inputDataAttributes ?? null) ? $inputDataAttributes : [];

$h = static function ($item): string {
    return htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');
};
?>
<div
    class="hour-picker-wrap"
    id="<?php echo $h($rootId); ?>"
    data-reservation-time-picker
    data-schedules-endpoint="<?php echo $h($endpoint); ?>"
    data-static-step="<?php echo $staticStep; ?>"
>
    <div class="hour-picker-control" data-time-control>
        <span class="hour-picker-control__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <circle cx="12" cy="12" r="8.5"/>
                <path d="M12 7.5V12l3 2"/>
            </svg>
        </span>
        <input
            type="text"
            class="hour-display"
            id="<?php echo $h($displayId); ?>"
            placeholder="<?php echo $h($placeholder); ?>"
            readonly
            aria-controls="<?php echo $h($dropdownId); ?>"
            aria-expanded="false"
            aria-haspopup="listbox"
            aria-invalid="<?php echo $displayAriaInvalid ? 'true' : 'false'; ?>"
            <?php echo $displayAriaDescribedby !== '' ? 'aria-describedby="' . $h($displayAriaDescribedby) . '"' : ''; ?>
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
        <?php echo $name !== '' ? 'name="' . $h($name) . '"' : ''; ?>
        id="<?php echo $h($inputId); ?>"
        value="<?php echo $h($value); ?>"
        data-time-input
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
    <div class="hour-dropdown" id="<?php echo $h($dropdownId); ?>" role="listbox" aria-label="Horarios disponibles" aria-hidden="true" data-time-dropdown>
        <div class="hour-dropdown__head" aria-hidden="true">
            <strong>Horarios disponibles</strong>
            <span>Selecciona una hora</span>
        </div>
        <div class="hour-dropdown__options" data-time-options></div>
    </div>
</div>
