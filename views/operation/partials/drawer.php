<?php
/** Drawer compartido de reservaciones del dia. */
$operationalDrawerId = (string)($operationalDrawerId ?? 'operational-reservations-drawer');
$operationalDrawerTitleId = (string)($operationalDrawerTitleId ?? 'operational-reservations-title');
$operationalDrawerClass = trim((string)($operationalDrawerClass ?? ''));
$operationalDrawerAttributes = is_array($operationalDrawerAttributes ?? null) ? $operationalDrawerAttributes : [];
$operationalDrawerDateHtml = (string)($operationalDrawerDateHtml ?? '');
$operationalDrawerCountHtml = (string)($operationalDrawerCountHtml ?? '0');
$operationalDrawerSlotHtml = (string)($operationalDrawerSlotHtml ?? 'Reservaciones activas');
$operationalDrawerFilterHtml = (string)($operationalDrawerFilterHtml ?? '');
$operationalDrawerListHtml = (string)($operationalDrawerListHtml ?? '');
$operationalDrawerListId = trim((string)($operationalDrawerListId ?? ''));
$operationalDrawerListClass = trim((string)($operationalDrawerListClass ?? ''));
$operationalDrawerListAttributes = is_array($operationalDrawerListAttributes ?? null) ? $operationalDrawerListAttributes : [];
$operationalActiveModule = (string)($operationalActiveModule ?? 'reservations');
$operationalMapHref = (string)($operationalMapHref ?? '/punto-de-venta');
// Un href vacío oculta el enlace: los destinos de /admin solo tienen sentido
// para quien puede entrar en ellos.
$operationalAdminHref = (string)($operationalAdminHref ?? '');
// El POS no emite la navegación de módulos: sin «Reservaciones» sólo quedaría
// «Mesas», que es la página actual, y le quitaba ~70 px a la lista.
$operationalDrawerModules = (bool)($operationalDrawerModules ?? true);
$operationalDrawerTitle = (string)($operationalDrawerTitle ?? 'Reservaciones del día');
// Pie fijo fuera del scroller: la única acción primaria del cajón.
$operationalDrawerFooterHtml = (string)($operationalDrawerFooterHtml ?? '');
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
        <h2 class="operational-drawer__title" id="<?php echo $operationalDrawerH($operationalDrawerTitleId); ?>"><?php echo $operationalDrawerH($operationalDrawerTitle); ?></h2>
        <div class="operational-drawer__meta">
            <?php if ($operationalDrawerDateHtml !== ''): ?>
                <?php echo $operationalDrawerDateHtml; ?>
            <?php endif; ?>
            <?php echo $operationalDrawerCountHtml; ?>
        </div>
        <button type="button" class="operational-icon-button operational-drawer__close" aria-label="Cerrar reservaciones" data-operational-drawer-close>
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"></path></svg>
        </button>
    </div>
    <?php if ($operationalDrawerModules): ?>
    <nav class="operational-drawer__modules" aria-label="Mapas operativos">
        <a
            class="operational-drawer__module-link<?php echo $operationalActiveModule === 'map' ? ' is-active' : ''; ?>"
            href="<?php echo $operationalDrawerH($operationalMapHref); ?>"
            data-operational-nav="map"
            <?php echo $operationalActiveModule === 'map' ? 'aria-current="page"' : ''; ?>
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="7" height="7" rx="1"></rect><rect x="14" y="4" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg>
            <span>Mesas</span>
        </a>
        <?php if ($operationalAdminHref !== ''): ?>
            <a
                class="operational-drawer__module-link"
                href="<?php echo $operationalDrawerH($operationalAdminHref); ?>"
                data-operational-nav="admin"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 19V9"></path><path d="M10 19V5"></path><path d="M16 19v-7"></path><path d="M21 19H3"></path></svg>
                <span>Panel de administración</span>
            </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
    <div class="operational-drawer__content">
        <?php if (trim($operationalDrawerFilterHtml) !== ''): ?>
            <div class="operational-drawer__filters">
                <?php echo $operationalDrawerFilterHtml; ?>
            </div>
        <?php endif; ?>
        <?php if (trim($operationalDrawerSlotHtml) !== ''): ?>
            <div class="reservation-operation__slot"><?php echo $operationalDrawerSlotHtml; ?></div>
        <?php endif; ?>
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
    <?php if (trim($operationalDrawerFooterHtml) !== ''): ?>
        <div class="operational-drawer__pie"><?php echo $operationalDrawerFooterHtml; ?></div>
    <?php endif; ?>
</aside>
<button type="button" class="operational-drawer-backdrop" aria-label="Cerrar reservaciones" data-operational-drawer-backdrop hidden></button>
<?php unset($operationalDrawerId, $operationalDrawerTitleId, $operationalDrawerClass, $operationalDrawerAttributes, $operationalDrawerDateHtml, $operationalDrawerCountHtml, $operationalDrawerSlotHtml, $operationalDrawerFilterHtml, $operationalDrawerListHtml, $operationalDrawerListId, $operationalDrawerListClass, $operationalDrawerListAttributes, $operationalActiveModule, $operationalMapHref, $operationalAdminHref, $operationalDrawerModules, $operationalDrawerTitle, $operationalDrawerFooterHtml, $operationalDrawerH); ?>
