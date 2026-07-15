<?php
/**
 * Shared reservation date picker markup.
 *
 * Expected variables:
 * - rootId, inputId, displayId, calendarId, name, value, min, disabled, enabledWeekdays
 */

$rootId = (string)($rootId ?? 'datePicker');
$inputId = (string)($inputId ?? 'fechaHidden');
$displayId = (string)($displayId ?? 'dateDisplay');
$calendarId = (string)($calendarId ?? 'cpCalendar');
$name = (string)($name ?? 'fecha');
$value = (string)($value ?? '');
$min = (string)($min ?? '');
$disabled = (bool)($disabled ?? false);
$enabledWeekdays = $enabledWeekdays ?? [];

if (!is_array($enabledWeekdays)) {
    $enabledWeekdays = [];
}

$h = static function ($item): string {
    return htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8');
};
?>
<div
    class="date-picker-wrap"
    id="<?php echo $h($rootId); ?>"
    data-reservation-date-picker
    data-min-date="<?php echo $h($min); ?>"
    data-enabled-weekdays="<?php echo $h(implode(',', array_map('intval', $enabledWeekdays))); ?>"
>
    <input
        type="text"
        class="date-display"
        id="<?php echo $h($displayId); ?>"
        placeholder="dd / mm / aaaa"
        readonly
        aria-controls="<?php echo $h($calendarId); ?>"
        aria-expanded="false"
        data-date-display
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <input
        type="hidden"
        name="<?php echo $h($name); ?>"
        id="<?php echo $h($inputId); ?>"
        value="<?php echo $h($value); ?>"
        data-date-input
        data-reservation-control
        <?php echo $disabled ? 'disabled' : ''; ?>
    >
    <div class="cp-calendar" id="<?php echo $h($calendarId); ?>" aria-hidden="true" data-date-calendar>
        <div class="cpc-head">
            <button class="cpc-nav cpc-prev" type="button" aria-label="Mes anterior" data-date-prev>&lt;</button>
            <span class="cpc-label" data-date-label></span>
            <button class="cpc-nav cpc-next" type="button" aria-label="Mes siguiente" data-date-next>&gt;</button>
        </div>
        <div class="cpc-weekdays">
            <span>do</span><span>lu</span><span>ma</span><span>mi</span><span>ju</span><span>vi</span><span>sa</span>
        </div>
        <div class="cpc-grid" data-date-grid></div>
    </div>
</div>
