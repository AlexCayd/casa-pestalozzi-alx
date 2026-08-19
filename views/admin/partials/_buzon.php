<?php
use Services\BuzonNotificacionesService;

$buzonResumen = ['cantidad' => 0, 'prioridad_maxima' => null];
try {
    $buzonResumen = BuzonNotificacionesService::resumen();
} catch (Throwable $e) {
    error_log('Buzón administrativo no disponible: ' . $e->getMessage());
}
$buzonCantidad = (int)($buzonResumen['cantidad'] ?? 0);
?>
<div class="admin-inbox" data-admin-inbox data-admin-csrf="<?php echo htmlspecialchars((string)\Services\AdminCsrfService::token(), ENT_QUOTES, 'UTF-8'); ?>">
    <button
        class="admin-inbox__trigger"
        type="button"
        aria-label="Abrir buzón de acciones pendientes"
        aria-controls="admin-inbox-drawer"
        aria-expanded="false"
        data-inbox-open
    >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/>
            <path d="M10 21h4"/>
        </svg>
        <span class="admin-inbox__badge" data-inbox-count<?php echo $buzonCantidad > 0 ? '' : ' hidden'; ?>><?php echo $buzonCantidad; ?></span>
    </button>

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
            <div>
                <p class="admin-inbox__eyebrow">Seguimiento administrativo</p>
                <h2 id="admin-inbox-title">Acciones pendientes</h2>
                <p data-inbox-summary>Consulta los casos que requieren atención.</p>
            </div>
            <button class="admin-inbox__close" type="button" aria-label="Cerrar buzón" data-inbox-close>
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg>
            </button>
        </header>
        <div class="admin-inbox__filters" role="tablist" aria-label="Filtrar acciones">
            <button type="button" class="is-active" data-inbox-filter="all" role="tab" aria-selected="true">Todas</button>
            <button type="button" data-inbox-filter="reservaciones" role="tab" aria-selected="false">Reservaciones</button>
        </div>
        <div class="admin-inbox__body" data-inbox-list aria-live="polite">
            <p class="admin-inbox__loading" data-inbox-loading>Cargando acciones…</p>
        </div>
        <div class="admin-inbox__context" data-inbox-context hidden></div>
    </aside>
</div>
