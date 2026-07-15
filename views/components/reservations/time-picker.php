<?php
/**
 * Shared reservation time picker markup.
 *
 * Expected variables:
 * - rootId, inputId, displayId, dropdownId, name, value, endpoint, disabled
 */

$rootId = (string)($rootId ?? 'hourPicker');
$inputId = (string)($inputId ?? 'horaHidden');
$displayId = (string)($displayId ?? 'hourDisplay');
$dropdownId = (string)($dropdownId ?? 'hourDropdown');
$name = (string)($name ?? 'hora');
$value = (string)($value ?? '');
$endpoint = (string)($endpoint ?? '/api/reservation-schedules');
$disabled = (bool)($disabled ?? false);

$h = static function ($item): string {
    return htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');
};
?>
<div
    class="hour-picker-wrap"
    id="<?php echo $h($rootId); ?>"
    data-reservation-time-picker
    data-schedules-endpoint="<?php echo $h($endpoint); ?>"
>
    <input
        type="text"
        class="hour-display"
        id="<?php echo $h($displayId); ?>"
        placeholder="Elige una hora"
        readonly
        aria-controls="<?php echo $h($dropdownId); ?>"
        aria-expanded="false"
        aria-haspopup="listbox"
        data-time-display
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <input
        type="hidden"
        name="<?php echo $h($name); ?>"
        id="<?php echo $h($inputId); ?>"
        value="<?php echo $h($value); ?>"
        data-time-input
        data-reservation-control
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <div class="hour-dropdown" id="<?php echo $h($dropdownId); ?>" role="listbox" aria-hidden="true" data-time-dropdown></div>
</div>
