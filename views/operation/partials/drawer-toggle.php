<?php
/** Boton compartido para abrir el drawer de reservaciones. */
$operationalDrawerId = (string)($operationalDrawerId ?? 'operational-reservations-drawer');
$operationalDrawerInitialCount = (string)($operationalDrawerInitialCount ?? '0');
$operationalDrawerToggleH = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
?>
<button
    type="button"
    class="operational-drawer-toggle"
    aria-controls="<?php echo $operationalDrawerToggleH($operationalDrawerId); ?>"
    aria-expanded="false"
    data-operational-drawer-toggle
>
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M5 6h14M5 12h14M5 18h14"></path>
    </svg>
    <span>Reservaciones</span>
    <span class="operational-drawer-toggle__count" data-operational-drawer-count><?php echo $operationalDrawerToggleH($operationalDrawerInitialCount); ?></span>
</button>
<?php unset($operationalDrawerInitialCount, $operationalDrawerToggleH); ?>
