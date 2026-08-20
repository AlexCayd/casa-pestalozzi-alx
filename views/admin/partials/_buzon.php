<?php
use Services\ReservacionConfig;

$buzonCantidad = (int)($buzonCantidadTotal ?? 0);
$buzonCantidadAccionable = (int)($buzonCantidadAccionable ?? 0);
$buzonCantidadSeguimiento = (int)($buzonCantidadSeguimiento ?? 0);
$buzonPrioridad = (string)($buzonPrioridadAccionable ?? '');
$buzonEstado = $buzonCantidad > 0 ? 'has-items' : 'is-empty';
if ($buzonCantidadSeguimiento > 0 && $buzonCantidadAccionable === 0) {
    $buzonEstado .= ' has-followup';
}
if ($buzonPrioridad === 'alta') {
    $buzonEstado .= ' has-high-priority';
}
?>
<div class="admin-inbox <?php echo htmlspecialchars($buzonEstado, ENT_QUOTES, 'UTF-8'); ?>" data-admin-inbox data-admin-csrf="<?php echo htmlspecialchars((string)\Services\AdminCsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>" data-inbox-refresh-seconds="<?php echo (int)ReservacionConfig::REFRESCO_ESTADOS_SEGUNDOS; ?>">
    <div class="admin-inbox__backdrop" data-inbox-close hidden></div>
    <aside
        class="admin-inbox__drawer"
        id="admin-inbox-drawer"
        role="dialog"
        aria-modal="true"
        aria-labelledby="admin-inbox-title"
        hidden
        data-inbox-drawer
    >
        <header class="admin-inbox__header">
            <button class="admin-inbox__back" type="button" data-inbox-back hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
                <span>Notificaciones</span>
            </button>
            <div>
                <p class="admin-inbox__eyebrow" data-inbox-eyebrow>Seguimiento administrativo</p>
                <h2 id="admin-inbox-title" data-inbox-title>Notificaciones</h2>
                <p data-inbox-summary><?php echo $buzonCantidadAccionable . ' requieren atención · ' . $buzonCantidadSeguimiento . ' en seguimiento'; ?></p>
            </div>
            <button class="admin-inbox__close" type="button" aria-label="Cerrar buzón" data-inbox-close>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </header>
        <div class="admin-inbox__filters" role="tablist" aria-label="Filtrar notificaciones" data-inbox-filters>
            <button type="button" class="is-active" data-inbox-filter="action" role="tab" aria-selected="true">Atención</button>
            <button type="button" data-inbox-filter="followup" role="tab" aria-selected="false">Seguimiento</button>
            <button type="button" data-inbox-filter="all" role="tab" aria-selected="false">Todas</button>
        </div>
        <div class="admin-inbox__body" data-inbox-list aria-live="polite">
            <p class="admin-inbox__loading" data-inbox-loading>Cargando acciones…</p>
        </div>
        <div class="admin-inbox__context" data-inbox-context role="region" aria-live="polite" hidden></div>
    </aside>
</div>
