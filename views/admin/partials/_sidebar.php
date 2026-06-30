<?php
/**
 * Navegacion lateral compartida por los modulos de administracion.
 * Renderiza las rutas disponibles y senala el modulo activo.
 */
$sidebarIcons = [
    'analytics' => '<path d="M4 19V5"/><path d="M4 19h16"/><path d="m7 15 3-4 3 2 4-6"/>',
    'menu' => '<path d="M5 4.5h8a3 3 0 0 1 3 3v12a2 2 0 0 0-2-2H5z"/><path d="M16 7.5h3v12a2 2 0 0 0-2-2h-1"/><path d="M8 8h4"/><path d="M8 11h4"/><path d="M8 14h3"/>',
    'map' => '<path d="M9 18 3 21V6l6-3 6 3 6-3v15l-6 3-6-3Z"/><path d="M9 3v15"/><path d="M15 6v15"/>',
    'area' => '<path d="M4 7h16"/><path d="M7 7v10a3 3 0 0 0 3 3h4a3 3 0 0 0 3-3V7"/><path d="M9 3v4"/><path d="M15 3v4"/><path d="M9 12h6"/>',
    'reservations' => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4"/><path d="M16 3v4"/><path d="M4 10h16"/>',
    'tables' => '<rect x="5" y="5" width="14" height="10" rx="2"/><path d="M8 15v4"/><path d="M16 15v4"/><path d="M5 19h14"/>',
    'products' => '<path d="M6 3v8a4 4 0 0 0 8 0V3"/><path d="M10 3v18"/><path d="M18 3v18"/>',
    'categories' => '<path d="M20 12 12 20 4 12l8-8 8 8Z"/><path d="M12 8h.01"/>',
    'tickets' => '<path d="M6 3h12v18l-2-1-2 1-2-1-2 1-2-1-2 1V3Z"/><path d="M9 8h6"/><path d="M9 12h6"/><path d="M9 16h4"/>',
    'payments' => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M3 10h18"/><path d="M7 15h3"/>',
    'printers' => '<path d="M7 8V3h10v5"/><path d="M7 17H5a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2h-2"/><path d="M7 14h10v7H7z"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a3 3 0 0 0-2-2.8"/>',
];
?>
<aside class="admin-sidebar" id="admin-sidebar" aria-label="Navegacion de administracion" data-admin-sidebar>
    <div class="admin-sidebar__header">
        <a class="admin-sidebar__brand" href="/admin/analytics" title="Casa Pestalozzi">
            <span class="admin-sidebar__brand-mark" aria-hidden="true">CP</span>
            <span class="admin-sidebar__brand-text">
                CASA PESTALOZZI
            </span>
        </a>

        <button
            class="admin-sidebar__close"
            type="button"
            aria-label="Cerrar navegacion"
            data-admin-sidebar-close>x</button>
    </div>

    <nav class="admin-sidebar__nav">
        <?php foreach ($modules as $moduleKey => $module): ?>
            <a
                class="admin-sidebar__link <?php echo $activeModule === $moduleKey ? 'is-active' : ''; ?>"
                href="<?php echo $module['path']; ?>"
                title="<?php echo htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8'); ?>">
                <span class="admin-sidebar__link-mark" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <?php echo $sidebarIcons[$moduleKey] ?? $sidebarIcons['analytics']; ?>
                    </svg>
                </span>
                <span class="admin-sidebar__link-text">
                    <?php echo $module['title']; ?>
                </span>
            </a>
        <?php endforeach; ?>
    </nav>
</aside>
