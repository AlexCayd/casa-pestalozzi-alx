<?php
/**
 * Barra superior compartida del panel de administracion.
 * Muestra el control del sidebar, el nombre actual y el usuario.
 */
$currentModuleTitle = $topbarTitle
    ?? $title
    ?? ($modules[$activeModule]['title'] ?? 'Panel');
?>
<header class="admin-topbar">
    <button
        class="admin-menu-toggle"
        type="button"
        aria-label="Abrir navegacion"
        aria-controls="admin-sidebar"
        aria-expanded="false"
        data-admin-sidebar-toggle
    >
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="admin-topbar__heading">
        <p class="admin-topbar__module">
            <?php echo htmlspecialchars($currentModuleTitle, ENT_QUOTES, 'UTF-8'); ?>
        </p>
    </div>

    <div class="admin-topbar__actions">
        <button
            class="admin-topbar__support"
            type="button"
            data-admin-modal-open="admin-problem-modal"
            aria-haspopup="dialog"
            aria-controls="admin-problem-modal"
        >
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="9"/>
                <path d="M9.5 9a2.6 2.6 0 0 1 5 1c0 2-2.5 2.2-2.5 4"/>
                <path d="M12 17h.01"/>
            </svg>
            <span>Reportar un problema</span>
        </button>

        <button
            class="admin-theme-toggle"
            type="button"
            aria-label="Cambiar tema"
            aria-pressed="false"
            data-admin-theme-toggle
            data-admin-magnetic
        >
            <svg class="admin-theme-toggle__moon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z" />
            </svg>
            <svg class="admin-theme-toggle__sun" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="4" />
                <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
            </svg>
        </button>

        <div class="admin-topbar__user" aria-label="Usuario actual">
            <span>Administrador</span>
            <strong>AD</strong>
        </div>
    </div>
</header>
